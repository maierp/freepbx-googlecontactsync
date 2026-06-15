<?php
// vim: set ai ts=4 sw=4 ft=php:
/**
 * Google Contact Sync — FreePBX module
 *
 * Copyright (C) 2026 Dr. Patrick Maier, Softwareentwicklung Patrick Maier
 * https://www.se-pm.de — mail@se-pm.de
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace FreePBX\modules\Googlecontactsync\Lib;

use Google\Service\PeopleService;
use Google\Service\PeopleService\Person;

/**
 * One-way sync engine: Google People API connections → Contact Manager.
 *
 * This milestone (M3) performs a **full** import — adding new contacts and
 * updating changed ones (etag-gated) into a user-selected or auto-created
 * private group, populating the `googlecontactsync_contacts` mapping table.
 * Incremental `syncToken` handling and deletion reconciliation arrive in M4.
 *
 * Contact Manager is only ever mutated through its public BMO methods so its
 * caches, contact-file regeneration, and hooks stay consistent. The module's
 * own tables (`googlecontactsync_*`) are accessed via PDO prepared statements.
 */
class PeopleSync {

	/** People fields requested from the API (spec §8.1). */
	const PERSON_FIELDS = 'names,emailAddresses,phoneNumbers,organizations,addresses,urls,photos,metadata';

	/** Page size for `connections.list` pagination. */
	const PAGE_SIZE = 200;

	/** Name of the private group auto-created when the user has none. */
	const TARGET_GROUP_NAME = 'Google Contacts';

	/** @var \FreePBX\modules\Googlecontactsync */
	private $module;

	/** @var object Contact Manager BMO object. */
	private $contactmanager;

	/** @var \PDO */
	private $db;

	/** @var TokenStore */
	private $store;

	/** @var ContactMapper */
	private $mapper;

	/** @var string Absolute path to the Contact Manager spool tmp directory. */
	private $tmpDir;

	/** @var PeopleService|null Injected service (tests); built on demand otherwise. */
	private $peopleService;

	/**
	 * @param \FreePBX\modules\Googlecontactsync $module
	 * @param object     $contactmanager Contact Manager BMO object.
	 * @param \PDO       $db
	 * @param TokenStore $store
	 * @param string     $tmpDir Contact Manager spool tmp dir (ASTSPOOLDIR/tmp).
	 */
	public function __construct($module, $contactmanager, \PDO $db, TokenStore $store, $tmpDir = '') {
		$this->module         = $module;
		$this->contactmanager = $contactmanager;
		$this->db             = $db;
		$this->store          = $store;
		$this->tmpDir         = (string) $tmpDir;
		$this->mapper         = new ContactMapper();
	}

	/**
	 * Inject a pre-built People service (used by tests with a mock transport).
	 *
	 * @param PeopleService $service
	 * @return $this
	 */
	public function setPeopleService(PeopleService $service) {
		$this->peopleService = $service;
		return $this;
	}

	/**
	 * Run a full sync for a single account row. Failures are caught, recorded,
	 * and returned (never thrown) so a batch run can continue with other users.
	 *
	 * @param array<string,mixed> $account Row from googlecontactsync_accounts.
	 * @return array<string,mixed> Result summary including a `status` flag.
	 */
	public function syncAccount(array $account) {
		$uid       = (int) $account['uid'];
		$accountId = (int) $account['id'];
		$started   = time();
		$this->markRunning($accountId);

		try {
			$service  = $this->getPeopleService($account);
			$groupId  = $this->resolveTargetGroup($account);
			$persons  = $this->fetchAllConnections($service);
			$counts   = $this->reconcile($accountId, $groupId, $persons);
			$this->regenerateContactFiles($groupId);

			$finished = time();
			$message  = sprintf(
				_('Imported %d, updated %d (%d unchanged).'),
				$counts['added'], $counts['updated'], $counts['skipped']
			);
			$this->markStatus($accountId, 'ok', $finished, $message);
			$this->writeLog($accountId, $uid, $started, $finished, 'ok', $counts, $message);

			return array(
				'status'  => true,
				'added'   => $counts['added'],
				'updated' => $counts['updated'],
				'skipped' => $counts['skipped'],
				'message' => $message,
			);
		} catch (\Throwable $e) {
			$finished = time();
			$message  = $this->redact($e->getMessage());
			$this->markStatus($accountId, 'error', $finished, $message);
			$this->writeLog(
				$accountId, $uid, $started, $finished, 'error',
				array('added' => 0, 'updated' => 0, 'deleted' => 0), $message
			);

			return array('status' => false, 'message' => $message);
		}
	}

	/**
	 * Build (or return the injected) People service for an account, refreshing
	 * the access token first when needed.
	 *
	 * @param array<string,mixed> $account
	 * @return PeopleService
	 */
	protected function getPeopleService(array $account) {
		if ($this->peopleService !== null) {
			return $this->peopleService;
		}
		return new PeopleService($this->buildAuthorizedClient($account));
	}

	/**
	 * Construct a Google client authorised with the account's stored tokens,
	 * refreshing and persisting the access token when it has expired.
	 *
	 * @param array<string,mixed> $account
	 * @return \Google\Client
	 * @throws \RuntimeException When access is expired and cannot be refreshed.
	 */
	protected function buildAuthorizedClient(array $account) {
		$factory = new GoogleClientFactory(
			$this->module->getClientId(),
			$this->module->getClientSecret(),
			$this->module->getRedirectUri()
		);
		$client = $factory->createClient();

		$accessJson = $this->store->decrypt((string) $account['access_token']);
		$token      = ($accessJson !== null) ? json_decode($accessJson, true) : null;
		if (is_array($token) && !empty($token)) {
			$client->setAccessToken($token);
		}

		if ($client->isAccessTokenExpired()) {
			$refresh = '';
			if (!empty($account['refresh_token'])) {
				$refresh = (string) $this->store->decrypt((string) $account['refresh_token']);
			}
			if ($refresh === '') {
				throw new \RuntimeException(_('Google access expired and no refresh token is available. Please reconnect your account.'));
			}
			$new = $client->fetchAccessTokenWithRefreshToken($refresh);
			if (!is_array($new) || isset($new['error']) || empty($new['access_token'])) {
				throw new \RuntimeException(_('Google access was revoked. Please reconnect your account.'));
			}
			$this->persistAccessToken((int) $account['uid'], $client->getAccessToken());
		}

		return $client;
	}

	/**
	 * Resolve the import target group: reuse the configured one when it still
	 * exists and the user owns/has access to it, otherwise auto-create a private
	 * "Google Contacts" group and persist it on the account.
	 *
	 * @param array<string,mixed> $account
	 * @return int Contact Manager group id.
	 * @throws \RuntimeException When the group cannot be created.
	 */
	protected function resolveTargetGroup(array $account) {
		$uid     = (int) $account['uid'];
		$groupId = isset($account['target_groupid']) ? (int) $account['target_groupid'] : 0;

		if ($groupId > 0) {
			$group = $this->contactmanager->getGroupByID($groupId);
			if (!empty($group)) {
				$type = isset($group['type']) ? $group['type'] : '';
				if ($type === 'external') {
					return $groupId;
				}
				if ($type === 'private' && (int) $group['owner'] === $uid) {
					return $groupId;
				}
				// internal or a group the user no longer owns → fall through.
			}
		}

		$res = $this->contactmanager->addGroup(self::TARGET_GROUP_NAME, 'private', $uid, false);
		if (empty($res['status']) || empty($res['id'])) {
			throw new \RuntimeException(_('Could not create a Contact Manager group for the import.'));
		}
		$newId = (int) $res['id'];
		$this->persistTargetGroup($uid, $newId, 'private');
		return $newId;
	}

	/**
	 * Fetch every connection (full, paginated) for the authenticated user.
	 *
	 * @param PeopleService $service
	 * @return array<int,Person>
	 */
	protected function fetchAllConnections(PeopleService $service) {
		$persons   = array();
		$pageToken = null;
		do {
			$opt = array(
				'personFields' => self::PERSON_FIELDS,
				'pageSize'     => self::PAGE_SIZE,
			);
			if ($pageToken !== null) {
				$opt['pageToken'] = $pageToken;
			}
			$response = $service->people_connections->listPeopleConnections('people/me', $opt);
			$conns    = $response->getConnections();
			if (is_array($conns)) {
				foreach ($conns as $person) {
					$persons[] = $person;
				}
			}
			$pageToken = $response->getNextPageToken();
		} while (!empty($pageToken));

		return $persons;
	}

	/**
	 * Add new / update changed contacts and maintain the mapping table.
	 * Deletions are out of scope for this milestone (M4).
	 *
	 * @param int               $accountId
	 * @param int               $groupId
	 * @param array<int,Person> $persons
	 * @return array{added:int,updated:int,deleted:int,skipped:int}
	 */
	protected function reconcile($accountId, $groupId, array $persons) {
		$added = 0;
		$updated = 0;
		$skipped = 0;

		foreach ($persons as $person) {
			$resourceName = trim((string) $person->getResourceName());
			if ($resourceName === '') {
				$skipped++;
				continue;
			}
			$meta = $person->getMetadata();
			if ($meta && $meta->getDeleted()) {
				$skipped++; // tombstone; deletion handling is M4.
				continue;
			}

			$entry = $this->mapper->map($person);
			if ($entry === null) {
				$skipped++;
				continue;
			}
			$etag    = (string) $person->getEtag();
			$mapping = $this->getMapping($accountId, $resourceName);

			if ($mapping) {
				if ($etag !== '' && (string) $mapping['etag'] === $etag) {
					$skipped++;
					continue;
				}
				$entry['groupid'] = (int) $mapping['groupid'];
				$this->applyPhoto($person, $entry);
				$this->contactmanager->updateEntry((int) $mapping['entryid'], $entry, false);
				$this->updateMappingEtag((int) $mapping['id'], $etag);
				$updated++;
			} else {
				$this->applyPhoto($person, $entry);
				$res = $this->contactmanager->addEntryByGroupID($groupId, $entry, false);
				if (empty($res['status']) || empty($res['id'])) {
					$skipped++;
					continue;
				}
				$this->insertMapping($accountId, $resourceName, $etag, (int) $res['id'], $groupId);
				$added++;
			}
		}

		return array('added' => $added, 'updated' => $updated, 'deleted' => 0, 'skipped' => $skipped);
	}

	/**
	 * Best-effort: download the contact photo and stage it as a PNG in the
	 * Contact Manager tmp dir so its public API can ingest it. Any failure is
	 * swallowed — a missing photo must never fail the contact import.
	 *
	 * @param Person               $person
	 * @param array<string,mixed> &$entry
	 */
	private function applyPhoto(Person $person, array &$entry) {
		try {
			$url = $this->mapper->getPhotoUrl($person);
			if ($url === null) {
				return;
			}
			$file = $this->stagePhoto($url);
			if ($file !== null) {
				$entry['image'] = $file;
			}
		} catch (\Throwable $e) {
			// Ignore: photo import is non-essential.
		}
	}

	/**
	 * Download an image and write it as a PNG into the CM tmp dir.
	 *
	 * @param string $url
	 * @return string|null Absolute path to the staged PNG, or null on failure.
	 */
	private function stagePhoto($url) {
		if (!function_exists('imagecreatefromstring') || $this->tmpDir === '') {
			return null;
		}
		if (!is_dir($this->tmpDir) || !is_writable($this->tmpDir)) {
			return null;
		}
		$data = $this->httpGet($url);
		if ($data === null || $data === '') {
			return null;
		}
		$img = @imagecreatefromstring($data);
		if ($img === false) {
			return null;
		}
		$path = rtrim($this->tmpDir, '/').'/gcs_'.bin2hex(random_bytes(8)).'.png';
		$ok   = @imagepng($img, $path);
		imagedestroy($img);
		return $ok ? $path : null;
	}

	/**
	 * Minimal HTTPS GET for contact photos (URL supplied by the Google API).
	 *
	 * @param string $url
	 * @return string|null
	 */
	private function httpGet($url) {
		if (stripos($url, 'https://') !== 0) {
			return null;
		}
		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			curl_setopt_array($ch, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_TIMEOUT        => 15,
				CURLOPT_MAXREDIRS      => 3,
			));
			$data = curl_exec($ch);
			$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			return ($data === false || $code >= 400) ? null : (string) $data;
		}
		$ctx  = stream_context_create(array('http' => array('timeout' => 15)));
		$data = @file_get_contents($url, false, $ctx);
		return ($data === false) ? null : $data;
	}

	/**
	 * Trigger Contact Manager's one-shot contact-file regeneration for the group
	 * (per-entry regeneration was suppressed during the batch).
	 *
	 * @param int $groupId
	 */
	private function regenerateContactFiles($groupId) {
		$group = $this->contactmanager->getGroupByID($groupId);
		if (!empty($group)) {
			$this->contactmanager->updateContactUpdatedDetails($group['owner'], array((int) $groupId));
		}
	}

	// ///////////////////////////////// //
	// Mapping table (own DB, PDO)        //
	// ///////////////////////////////// //

	/**
	 * @param int    $accountId
	 * @param string $resourceName
	 * @return array<string,mixed>|null
	 */
	private function getMapping($accountId, $resourceName) {
		$sth = $this->db->prepare(
			'SELECT * FROM googlecontactsync_contacts WHERE account_id = ? AND resource_name = ? LIMIT 1'
		);
		$sth->execute(array((int) $accountId, (string) $resourceName));
		$row = $sth->fetch(\PDO::FETCH_ASSOC);
		return $row !== false ? $row : null;
	}

	private function insertMapping($accountId, $resourceName, $etag, $entryId, $groupId) {
		$sth = $this->db->prepare(
			'INSERT INTO googlecontactsync_contacts'
			.' (account_id, resource_name, etag, entryid, groupid, last_synced)'
			.' VALUES (?, ?, ?, ?, ?, ?)'
		);
		$sth->execute(array((int) $accountId, (string) $resourceName, (string) $etag, (int) $entryId, (int) $groupId, time()));
	}

	private function updateMappingEtag($id, $etag) {
		$sth = $this->db->prepare('UPDATE googlecontactsync_contacts SET etag = ?, last_synced = ? WHERE id = ?');
		$sth->execute(array((string) $etag, time(), (int) $id));
	}

	// ///////////////////////////////// //
	// Account persistence (own DB, PDO)  //
	// ///////////////////////////////// //

	/**
	 * Persist a refreshed access token (and a rotated refresh token when Google
	 * returns one). Never logs the token material.
	 *
	 * @param int                 $uid
	 * @param array<string,mixed> $tokenArray
	 */
	private function persistAccessToken($uid, $tokenArray) {
		if (!is_array($tokenArray)) {
			return;
		}
		$created = isset($tokenArray['created']) ? (int) $tokenArray['created'] : time();
		$expires = $created + (isset($tokenArray['expires_in']) ? (int) $tokenArray['expires_in'] : 3600);

		$sth = $this->db->prepare(
			'UPDATE googlecontactsync_accounts SET access_token = ?, token_expires = ?, updated = ? WHERE uid = ?'
		);
		$sth->execute(array($this->store->encrypt(json_encode($tokenArray)), $expires, time(), (int) $uid));

		if (!empty($tokenArray['refresh_token'])) {
			$sth = $this->db->prepare('UPDATE googlecontactsync_accounts SET refresh_token = ? WHERE uid = ?');
			$sth->execute(array($this->store->encrypt((string) $tokenArray['refresh_token']), (int) $uid));
		}
	}

	private function persistTargetGroup($uid, $groupId, $type) {
		$sth = $this->db->prepare(
			'UPDATE googlecontactsync_accounts SET target_groupid = ?, target_group_type = ?, updated = ? WHERE uid = ?'
		);
		$sth->execute(array((int) $groupId, (string) $type, time(), (int) $uid));
	}

	private function markRunning($accountId) {
		$sth = $this->db->prepare('UPDATE googlecontactsync_accounts SET last_status = ?, updated = ? WHERE id = ?');
		$sth->execute(array('running', time(), (int) $accountId));
	}

	private function markStatus($accountId, $status, $finished, $message) {
		$sth = $this->db->prepare(
			'UPDATE googlecontactsync_accounts SET last_status = ?, last_message = ?, last_sync = ?, updated = ? WHERE id = ?'
		);
		$sth->execute(array((string) $status, (string) $message, (int) $finished, time(), (int) $accountId));
	}

	/**
	 * @param int                                          $accountId
	 * @param int                                          $uid
	 * @param int                                          $started
	 * @param int                                          $finished
	 * @param string                                       $status
	 * @param array{added:int,updated:int,deleted:int}     $counts
	 * @param string                                       $message
	 */
	private function writeLog($accountId, $uid, $started, $finished, $status, array $counts, $message) {
		$sth = $this->db->prepare(
			'INSERT INTO googlecontactsync_logs'
			.' (account_id, uid, started, finished, status, added, updated, deleted, message)'
			.' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
		);
		$sth->execute(array(
			(int) $accountId, (int) $uid, (int) $started, (int) $finished, (string) $status,
			(int) $counts['added'], (int) $counts['updated'], (int) $counts['deleted'], (string) $message,
		));
	}

	/**
	 * Strip anything resembling an OAuth token from a message before it is
	 * stored or surfaced, so secrets never leak into logs or the UI.
	 *
	 * @param string $message
	 * @return string
	 */
	private function redact($message) {
		$message = (string) $message;
		$message = preg_replace('/(access_token|refresh_token|id_token|client_secret)["\']?\s*[:=]\s*\S+/i', '$1=[redacted]', $message);
		$message = preg_replace('/\bya29\.\S+/', '[redacted]', $message);
		return $message;
	}
}
