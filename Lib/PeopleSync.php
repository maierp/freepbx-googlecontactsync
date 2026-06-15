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

use Google\Service\Exception as GoogleServiceException;
use Google\Service\PeopleService;
use Google\Service\PeopleService\Person;

/**
 * One-way sync engine: Google People API connections → Contact Manager.
 *
 * Adds new contacts, updates changed ones (etag-gated), and mirrors deletions
 * into a user-selected or auto-created private group, maintaining the
 * `googlecontactsync_contacts` mapping table.
 *
 * Syncs are **incremental** when a People API `syncToken` is stored: only
 * changed/added/deleted contacts are returned, with deletions arriving as
 * tombstones (`metadata.deleted == true`). The first run (no token) and any run
 * whose token has expired (`EXPIRED_SYNC_TOKEN`) fall back to a **full** resync,
 * after which deletions are reconciled by diffing the mapping table against the
 * set of contacts Google returned. A fresh `nextSyncToken` is persisted after
 * every successful run; on failure no partial token is stored so the next run
 * can recover.
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
	 * @param array<string,mixed> $account   Row from googlecontactsync_accounts.
	 * @param bool                $forceFull When true, perform a clean full
	 *        import: delete every contact this account previously imported,
	 *        discard the stored sync token, and re-import all contacts afresh.
	 * @return array<string,mixed> Result summary including a `status` flag.
	 */
	public function syncAccount(array $account, $forceFull = false) {
		$uid       = (int) $account['uid'];
		$accountId = (int) $account['id'];
		$started   = time();
		$this->markRunning($accountId);

		try {
			$service   = $this->getPeopleService($account);
			$originalGroupId = isset($account['target_groupid']) ? (int) $account['target_groupid'] : 0;
			$groupId   = $this->resolveTargetGroup($account);
			$syncToken = isset($account['sync_token']) ? (string) $account['sync_token'] : '';

			// The configured target group was deleted/replaced, so a new one was
			// just created. Its contacts (and the entries the old mappings point
			// to) are gone, so force a full resync and drop the stale mappings —
			// otherwise an incremental run imports nothing and unchanged etags
			// would skip re-adding every contact into the new group.
			if ($originalGroupId > 0 && $groupId !== $originalGroupId) {
				$this->clearMappings($accountId);
				$syncToken = '';
			}

			// Explicit clean full import: remove the contacts previously imported
			// for this account and start from a blank slate so every Google
			// contact is re-created fresh (no stale entries, no unchanged-etag
			// skips).
			if ($forceFull) {
				$this->purgeImportedContacts($accountId);
				$syncToken = '';
			}

			$fetch     = $this->fetchConnections($service, $syncToken);
			$counts    = $this->reconcile($accountId, $groupId, $fetch['persons'], $fetch['incremental']);
			$this->regenerateContactFiles($groupId);
			$this->persistSyncToken($accountId, $fetch['nextSyncToken']);

			$finished = time();
			$message  = sprintf(
				_('Imported %d, updated %d, deleted %d (%d unchanged).'),
				$counts['added'], $counts['updated'], $counts['deleted'], $counts['skipped']
			);
			$this->markStatus($accountId, 'ok', $finished, $message);
			$this->writeLog($accountId, $uid, $started, $finished, 'ok', $counts, $message);

			return array(
				'status'  => true,
				'added'   => $counts['added'],
				'updated' => $counts['updated'],
				'deleted' => $counts['deleted'],
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
	 * Fetch connections, preferring an incremental sync when a `syncToken` is
	 * stored. If the token has expired (`EXPIRED_SYNC_TOKEN`) it is discarded and
	 * a full resync is performed instead (which also drives deletion
	 * reconciliation, see {@see reconcile()}).
	 *
	 * @param PeopleService $service
	 * @param string        $syncToken Stored token from a previous run ('' = none).
	 * @return array{persons:array<int,Person>,nextSyncToken:string,incremental:bool}
	 */
	protected function fetchConnections(PeopleService $service, $syncToken) {
		$syncToken = (string) $syncToken;
		if ($syncToken !== '') {
			try {
				return $this->listConnections($service, $syncToken, true);
			} catch (GoogleServiceException $e) {
				if (!$this->isExpiredSyncToken($e)) {
					throw $e;
				}
				// Expired token → fall through to a full resync.
			}
		}
		return $this->listConnections($service, '', false);
	}

	/**
	 * Page through `people.connections.list`, collecting every returned Person
	 * and capturing the `nextSyncToken` emitted on the final page.
	 *
	 * @param PeopleService $service
	 * @param string        $syncToken   Incremental token, or '' for a full list.
	 * @param bool          $incremental Whether this is an incremental run.
	 * @return array{persons:array<int,Person>,nextSyncToken:string,incremental:bool}
	 */
	private function listConnections(PeopleService $service, $syncToken, $incremental) {
		$persons   = array();
		$pageToken = null;
		$nextSync  = '';
		do {
			$opt = array(
				'personFields'     => self::PERSON_FIELDS,
				'pageSize'         => self::PAGE_SIZE,
				'requestSyncToken' => true,
			);
			if ($syncToken !== '') {
				$opt['syncToken'] = $syncToken;
			}
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
			$token = (string) $response->getNextSyncToken();
			if ($token !== '') {
				$nextSync = $token;
			}
			$pageToken = $response->getNextPageToken();
		} while (!empty($pageToken));

		return array(
			'persons'       => $persons,
			'nextSyncToken' => $nextSync,
			'incremental'   => (bool) $incremental,
		);
	}

	/**
	 * Whether a People API error is a 400 `EXPIRED_SYNC_TOKEN` (the signal to
	 * discard the stored token and perform a full resync).
	 *
	 * @param GoogleServiceException $e
	 * @return bool
	 */
	private function isExpiredSyncToken(GoogleServiceException $e) {
		if ((int) $e->getCode() !== 400) {
			return false;
		}
		if (stripos((string) $e->getMessage(), 'EXPIRED_SYNC_TOKEN') !== false) {
			return true;
		}
		foreach ((array) $e->getErrors() as $err) {
			$reason = is_array($err) && isset($err['reason']) ? (string) $err['reason'] : '';
			if (stripos($reason, 'EXPIRED_SYNC_TOKEN') !== false) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Add new / update changed / delete removed contacts and maintain the
	 * mapping table.
	 *
	 * Incremental runs receive deletions as tombstones
	 * (`metadata.deleted == true`). Full runs receive only live contacts, so
	 * deletions are reconciled afterwards by diffing the mapping table against
	 * the set of resource names returned this run.
	 *
	 * @param int               $accountId
	 * @param int               $groupId
	 * @param array<int,Person> $persons
	 * @param bool              $incremental Whether a valid sync token was used.
	 * @return array{added:int,updated:int,deleted:int,skipped:int}
	 */
	protected function reconcile($accountId, $groupId, array $persons, $incremental) {
		$added   = 0;
		$updated = 0;
		$deleted = 0;
		$skipped = 0;
		$seen    = array();

		foreach ($persons as $person) {
			$resourceName = trim((string) $person->getResourceName());
			if ($resourceName === '') {
				$skipped++;
				continue;
			}
			$seen[$resourceName] = true;

			$meta = $person->getMetadata();
			if ($meta && $meta->getDeleted()) {
				// Incremental tombstone → mirror the deletion locally.
				$mapping = $this->getMapping($accountId, $resourceName);
				if ($mapping) {
					$this->contactmanager->deleteEntryByID((int) $mapping['entryid']);
					$this->deleteMapping((int) $mapping['id']);
					$deleted++;
				} else {
					$skipped++; // never imported (or already removed).
				}
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

		// Full-sync deletion reconciliation: contacts we previously imported but
		// that Google did not return this run have been deleted upstream.
		if (!$incremental) {
			$deleted += $this->reconcileFullSyncDeletions($accountId, $seen);
		}

		return array('added' => $added, 'updated' => $updated, 'deleted' => $deleted, 'skipped' => $skipped);
	}

	/**
	 * Delete locally any contacts this account previously imported whose resource
	 * name was absent from a full sync (i.e. removed in Google). Scoped to rows
	 * this account created, so manually added contacts in a shared group are
	 * never touched.
	 *
	 * @param int                    $accountId
	 * @param array<string,bool>     $seen Resource names returned this run.
	 * @return int Number of contacts deleted.
	 */
	private function reconcileFullSyncDeletions($accountId, array $seen) {
		$deleted = 0;
		$sth = $this->db->prepare(
			'SELECT id, entryid, resource_name FROM googlecontactsync_contacts WHERE account_id = ?'
		);
		$sth->execute(array((int) $accountId));
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
		foreach ($rows as $row) {
			if (isset($seen[(string) $row['resource_name']])) {
				continue;
			}
			$this->contactmanager->deleteEntryByID((int) $row['entryid']);
			$this->deleteMapping((int) $row['id']);
			$deleted++;
		}
		return $deleted;
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

	/**
	 * Persist a contact mapping idempotently. Any pre-existing row for the same
	 * (account_id, resource_name) is removed first, so a reused account id (after
	 * a disconnect/reconnect), an interrupted prior run, or an overlapping sync
	 * can never trip the `acct_resource` unique constraint and abort the run.
	 * Implemented with DELETE-then-INSERT to stay portable across MySQL and the
	 * SQLite used by the test suite.
	 *
	 * @param int    $accountId
	 * @param string $resourceName
	 * @param string $etag
	 * @param int    $entryId
	 * @param int    $groupId
	 */
	private function insertMapping($accountId, $resourceName, $etag, $entryId, $groupId) {
		$del = $this->db->prepare(
			'DELETE FROM googlecontactsync_contacts WHERE account_id = ? AND resource_name = ?'
		);
		$del->execute(array((int) $accountId, (string) $resourceName));

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

	private function deleteMapping($id) {
		$sth = $this->db->prepare('DELETE FROM googlecontactsync_contacts WHERE id = ?');
		$sth->execute(array((int) $id));
	}

	/**
	 * Remove all contact mappings for an account. Used when the target group was
	 * recreated so the next full resync re-imports every contact afresh.
	 *
	 * @param int $accountId
	 */
	private function clearMappings($accountId) {
		$sth = $this->db->prepare('DELETE FROM googlecontactsync_contacts WHERE account_id = ?');
		$sth->execute(array((int) $accountId));
	}

	/**
	 * Delete every Contact Manager entry this account previously imported and
	 * clear its mappings, leaving a blank slate for a clean full import. Scoped
	 * to rows this account created, so contacts added by other means survive.
	 *
	 * @param int $accountId
	 */
	private function purgeImportedContacts($accountId) {
		$sth = $this->db->prepare('SELECT entryid FROM googlecontactsync_contacts WHERE account_id = ?');
		$sth->execute(array((int) $accountId));
		foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$this->contactmanager->deleteEntryByID((int) $row['entryid']);
		}
		$this->clearMappings($accountId);
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

	/**
	 * Persist the People API `nextSyncToken` so the next run can be incremental.
	 * Only stored when non-empty so a successful run never clears a usable token.
	 *
	 * @param int    $accountId
	 * @param string $token
	 */
	private function persistSyncToken($accountId, $token) {
		$token = (string) $token;
		if ($token === '') {
			return;
		}
		$sth = $this->db->prepare('UPDATE googlecontactsync_accounts SET sync_token = ?, updated = ? WHERE id = ?');
		$sth->execute(array($token, time(), (int) $accountId));
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
