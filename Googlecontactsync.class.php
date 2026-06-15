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

namespace FreePBX\modules;

if (file_exists(__DIR__.'/vendor/autoload.php')) {
	include __DIR__.'/vendor/autoload.php';
}

use BMO;
use FreePBX_Helpers;
use FreePBX\modules\Googlecontactsync\Lib\TokenStore;
use FreePBX\modules\Googlecontactsync\Lib\GoogleClientFactory;
use FreePBX\modules\Googlecontactsync\Lib\PeopleSync;
use FreePBX\modules\Googlecontactsync\Lib\Schedule;

class Googlecontactsync extends FreePBX_Helpers implements BMO {

	/** KV store keys for global settings. */
	const KEY_CLIENT_ID     = 'client_id';
	const KEY_CLIENT_SECRET = 'client_secret_enc';
	const KEY_REDIRECT_URI  = 'redirect_uri';
	const KEY_FREQUENCY     = 'global_frequency';
	const KEY_FREQ_TIME     = 'global_freq_time';
	const KEY_FREQ_DOW      = 'global_freq_dow';

	/** KV key for the HMAC key that signs OAuth `state` values. */
	const KEY_STATE_KEY     = 'state_sign_key';

	/** Lifetime (seconds) of a pending OAuth authorization request. */
	const STATE_TTL         = 600;

	/** Allowed scheduling frequencies. */
	const FREQUENCIES = array('hourly', 'daily', 'weekly');

	/** @var \PDO */
	private $db;
	/** @var \FreePBX */
	private $freepbx;

	/** @var TokenStore|null */
	private $tokenStore;

	private $message = '';

	public function __construct($freepbx = null) {
		if ($freepbx === null) {
			throw new \Exception('Not given a FreePBX Object');
		}
		$this->freepbx = $freepbx;
		$this->db = $freepbx->Database;
	}

	// ///////////////////////////////// //
	// Install / Uninstall (BMO)          //
	// ///////////////////////////////// //

	public function install() {
		$this->seedDefaultSettings();
		$this->addCronEntry();
	}

	public function uninstall() {
		$this->removeCronEntry();
		// Revoke every outstanding Google grant before the module loader drops our
		// tables, so no live tokens are left behind that we can no longer manage.
		foreach ($this->getAllAccounts() as $account) {
			$this->revokeAccountToken($account);
		}
		// The account/contact/log tables are dropped by the framework on uninstall,
		// but the KV settings (including the encrypted Client Secret and the OAuth
		// state-signing key) live in the shared kvstore and must be cleared here.
		$this->purgeSettings();
		// Finally, securely remove the at-rest encryption key file.
		$this->deleteKeyFile();
	}

	/**
	 * Remove all global KV settings owned by this module (Client ID, encrypted
	 * Client Secret, redirect URI, schedule, and the OAuth state-signing key).
	 */
	private function purgeSettings() {
		$keys = array(
			self::KEY_CLIENT_ID,
			self::KEY_CLIENT_SECRET,
			self::KEY_REDIRECT_URI,
			self::KEY_FREQUENCY,
			self::KEY_FREQ_TIME,
			self::KEY_FREQ_DOW,
			self::KEY_STATE_KEY,
		);
		foreach ($keys as $key) {
			// setConfig(..., false) deletes the stored row (delConfig alias).
			$this->setConfig($key, false);
		}
	}

	/**
	 * Securely delete the 256-bit encryption key file: overwrite its contents
	 * before unlinking so the key cannot be trivially recovered from freed disk
	 * blocks. No-op when the file is absent.
	 */
	private function deleteKeyFile() {
		$path = $this->getKeyFilePath();
		if (!is_file($path)) {
			return;
		}
		$size = @filesize($path);
		if ($size && $size > 0) {
			$fh = @fopen($path, 'r+');
			if ($fh !== false) {
				@fwrite($fh, str_repeat("\0", $size));
				@fflush($fh);
				@fclose($fh);
			}
		}
		@unlink($path);
	}

	/**
	 * Register the single recurring cron entry that drives scheduled syncs
	 * (every 15 minutes; spec §10.1). Any previous googlecontactsync entry is
	 * removed first so re-installs do not stack duplicate lines.
	 */
	private function addCronEntry() {
		$webuser = (string) $this->freepbx->Config->get('AMPASTERISKWEBUSER');
		$sbin    = rtrim((string) $this->freepbx->Config->get('AMPSBIN'), '/');
		$fwc     = $sbin.'/fwconsole';
		$cron    = $this->freepbx->Cron($webuser);
		$this->removeCronEntry();
		// A short random delay spreads load when many PBXes share a schedule;
		// the literal % must be escaped for crontab.
		$cron->addLine('*/15 * * * * [ -e '.$fwc.' ] && sleep $((RANDOM\%30)) && '.$fwc.' googlecontactsync --runsync -q');
	}

	/**
	 * Remove any googlecontactsync scheduled-sync cron lines for the web user.
	 */
	private function removeCronEntry() {
		$webuser = (string) $this->freepbx->Config->get('AMPASTERISKWEBUSER');
		$cron    = $this->freepbx->Cron($webuser);
		foreach ($cron->getAll() as $line) {
			if (preg_match('/fwconsole\s+googlecontactsync\s+--runsync/', (string) $line)) {
				$cron->remove($line);
			}
		}
	}

	/**
	 * Persist the global default schedule on first install so the admin UI and
	 * due-logic share an explicit, stored baseline (no-op if already set).
	 */
	private function seedDefaultSettings() {
		if ((string) $this->getConfig(self::KEY_FREQUENCY) === '') {
			$default = $this->getGlobalFrequency();
			$this->setGlobalFrequency($default['frequency'], $default['time'], $default['dow']);
		}
	}

	// ///////////////////////////////// //
	// Admin page (BMO UI)                //
	// ///////////////////////////////// //

	public function doConfigPageInit($page) {
		$request = freepbxGetSanitizedRequest();
		$action = isset($request['action']) ? $request['action'] : '';
		if ($action !== 'savesettings') {
			return;
		}

		$clientId     = isset($request['client_id']) ? trim((string) $request['client_id']) : '';
		$clientSecret = isset($request['client_secret']) ? (string) $request['client_secret'] : '';

		// Write-only secret: an empty field means "keep the stored value".
		$this->setCredentials($clientId, $clientSecret === '' ? null : $clientSecret);

		$redirectUri    = isset($request['redirect_uri']) ? trim((string) $request['redirect_uri']) : '';
		$redirectUriOk  = $this->setRedirectUri($redirectUri);

		$frequency = isset($request['frequency']) ? (string) $request['frequency'] : '';
		$freqTime  = isset($request['freq_time']) ? (string) $request['freq_time'] : '';
		$freqDow   = isset($request['freq_dow']) && $request['freq_dow'] !== '' ? (int) $request['freq_dow'] : null;
		$this->setGlobalFrequency($frequency, $freqTime, $freqDow);

		if (!$redirectUriOk) {
			$this->message = array(
				'message' => _('Settings saved, but the Redirect URI was ignored: it must be a valid HTTPS URL.'),
				'type'    => 'warning',
			);
		} else {
			$this->message = array(
				'message' => _('Settings saved.'),
				'type'    => 'success',
			);
		}
	}

	public function getActionBar($request) {
		$buttons = array();
		if (($request['display'] ?? '') === 'googlecontactsync') {
			$buttons['reset'] = array(
				'name'  => 'reset',
				'id'    => 'reset',
				'value' => _('Reset'),
			);
			$buttons['submit'] = array(
				'name'  => 'submit',
				'id'    => 'submit',
				'value' => _('Submit'),
			);
		}
		return $buttons;
	}

	public function ajaxRequest($req, &$setting) {
		switch ($req) {
			case 'syncnow':
			case 'disconnect':
			case 'clearlogs':
				// Admin-authenticated commands; keep the default authenticate=true
				// so the framework gates them behind admin login + referer check.
				return true;
			default:
				return false;
		}
	}

	/**
	 * Handle admin AJAX commands for the Users and Logs tabs. Returns an
	 * associative array the framework serialises to JSON. All commands are
	 * gated behind admin authentication by the Ajax dispatcher.
	 *
	 * @return array<string,mixed>
	 */
	public function ajaxHandler() {
		$command = isset($_REQUEST['command']) ? (string) $_REQUEST['command'] : '';
		switch ($command) {
			case 'syncnow':
				$uid = isset($_POST['uid']) ? (int) $_POST['uid'] : 0;
				if ($uid <= 0 || !$this->getAccountByUid($uid)) {
					return array('status' => false, 'message' => _('Unknown account.'));
				}
				try {
					$res = $this->syncUid($uid, false);
					$ok  = !empty($res['status']);
					return array(
						'status'  => $ok,
						'message' => $ok ? _('Sync completed.') : _('The sync did not complete. See the Logs tab for details.'),
					);
				} catch (\Exception $e) {
					return array('status' => false, 'message' => _('The sync did not complete. See the Logs tab for details.'));
				}

			case 'disconnect':
				$uid = isset($_POST['uid']) ? (int) $_POST['uid'] : 0;
				if ($uid <= 0) {
					return array('status' => false, 'message' => _('Unknown account.'));
				}
				$this->disconnect($uid);
				return array('status' => true, 'message' => _('Account disconnected.'));

			case 'clearlogs':
				$days = isset($_POST['days']) ? (int) $_POST['days'] : 30;
				$removed = $this->clearOldLogs($days);
				return array(
					'status'  => true,
					'message' => sprintf(_('Removed %d log entr%s.'), $removed, $removed === 1 ? 'y' : 'ies'),
					'removed' => $removed,
				);
		}
		return array('status' => false, 'message' => _('Unknown request.'));
	}

	/**
	 * Render the admin page (Settings, Users, and Logs tabs).
	 */
	public function showPage() {
		$request     = $_REQUEST;
		$allowedTabs = array('settings', 'users', 'logs');
		$activeTab   = (isset($request['tab']) && in_array($request['tab'], $allowedTabs, true))
			? (string) $request['tab'] : 'settings';

		$settings = load_view(__DIR__.'/views/settings.php', array(
			'clientId'           => $this->getClientId(),
			'hasClientSecret'    => $this->hasClientSecret(),
			'redirectUri'        => trim((string) $this->getConfig(self::KEY_REDIRECT_URI)),
			'defaultRedirectUri' => $this->getDefaultRedirectUri(),
			'frequency'          => $this->getGlobalFrequency(),
			'daysOfWeek'         => $this->getDaysOfWeek(),
		));

		$users = load_view(__DIR__.'/views/users.php', array(
			'rows' => $this->getUsersTabData(),
		));

		$logUid    = (isset($request['logs_uid']) && $request['logs_uid'] !== '') ? (int) $request['logs_uid'] : null;
		$logStatus = (isset($request['logs_status']) && in_array($request['logs_status'], array('ok', 'error'), true))
			? (string) $request['logs_status'] : null;
		$perPage    = 25;
		$total      = $this->countLogs($logUid, $logStatus);
		$totalPages = max(1, (int) ceil($total / $perPage));
		$page       = isset($request['logs_page']) ? max(1, (int) $request['logs_page']) : 1;
		if ($page > $totalPages) {
			$page = $totalPages;
		}
		$offset = ($page - 1) * $perPage;

		$logs = load_view(__DIR__.'/views/logs.php', array(
			'rows'         => $this->getLogsTabData($logUid, $logStatus, $perPage, $offset),
			'userOptions'  => $this->getLogUserFilterOptions(),
			'filterUid'    => $logUid,
			'filterStatus' => $logStatus,
			'page'         => $page,
			'totalPages'   => $totalPages,
			'total'        => $total,
		));

		return load_view(__DIR__.'/views/main.php', array(
			'message'   => $this->message,
			'activeTab' => $activeTab,
			'settings'  => $settings,
			'users'     => $users,
			'logs'      => $logs,
		));
	}

	// ///////////////////////////////// //
	// Admin Users + Logs tabs (M7)       //
	// ///////////////////////////////// //

	/**
	 * Build the per-user rows for the admin Users tab from the stored accounts.
	 * No secrets are included — only display-safe status fields.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getUsersTabData() {
		$rows = array();
		foreach ($this->getAllAccounts() as $account) {
			$uid = (int) $account['uid'];
			$eff = $this->getEffectiveFrequency($account);
			$rows[] = array(
				'uid'               => $uid,
				'user'              => $this->getUserDisplay($uid),
				'email'             => (string) ($account['google_email'] ?? ''),
				'group'             => $this->getGroupLabel($account),
				'frequency'         => $this->describeFrequency($eff),
				'frequencyOverride' => in_array((string) ($account['frequency'] ?? ''), self::FREQUENCIES, true),
				'enabled'           => !empty($account['enabled']),
				'lastSync'          => (!empty($account['last_sync'])) ? (int) $account['last_sync'] : null,
				'status'            => (string) ($account['last_status'] ?? ''),
				'message'           => (string) ($account['last_message'] ?? ''),
			);
		}
		return $rows;
	}

	/**
	 * Friendly display name for a userman uid (display name, else username, else
	 * a synthetic label). Never throws when Userman cannot resolve the id.
	 *
	 * @param int $uid
	 * @return string
	 */
	public function getUserDisplay($uid) {
		$uid = (int) $uid;
		try {
			$user = $this->freepbx->Userman->getUserByID($uid);
		} catch (\Exception $e) {
			$user = null;
		}
		if (is_array($user)) {
			if (!empty($user['displayname'])) {
				return (string) $user['displayname'];
			}
			if (!empty($user['username'])) {
				return (string) $user['username'];
			}
		}
		return sprintf(_('User #%d'), $uid);
	}

	/**
	 * Resolve the Contact Manager group name for an account's import target.
	 *
	 * @param array<string,mixed> $account
	 * @return string Empty string when no target is set.
	 */
	private function getGroupLabel($account) {
		$gid = isset($account['target_groupid']) ? (int) $account['target_groupid'] : 0;
		if ($gid <= 0) {
			return '';
		}
		try {
			$group = $this->freepbx->Contactmanager->getGroupByID($gid);
		} catch (\Exception $e) {
			$group = null;
		}
		if (!empty($group['name'])) {
			return (string) $group['name'];
		}
		return '#'.$gid;
	}

	/**
	 * Human-readable description of an effective schedule.
	 *
	 * @param array{frequency:string,time:string,dow:int} $eff
	 * @return string
	 */
	private function describeFrequency($eff) {
		switch ($eff['frequency']) {
			case 'hourly':
				return _('Hourly');
			case 'weekly':
				$days = $this->getDaysOfWeek();
				$day  = isset($days[$eff['dow']]) ? $days[$eff['dow']] : (string) $eff['dow'];
				return sprintf(_('Weekly · %s · %s'), $day, $eff['time']);
			case 'daily':
			default:
				return sprintf(_('Daily · %s'), $eff['time']);
		}
	}

	/**
	 * Distinct userman uids that appear in the sync logs, mapped to their display
	 * labels (used to populate the Logs tab user filter).
	 *
	 * @return array<int,string>
	 */
	public function getLogUserFilterOptions() {
		$sth = $this->db->prepare('SELECT DISTINCT uid FROM googlecontactsync_logs WHERE uid IS NOT NULL ORDER BY uid');
		$sth->execute();
		$out = array();
		foreach ($sth->fetchAll(\PDO::FETCH_COLUMN) as $uid) {
			$out[(int) $uid] = $this->getUserDisplay((int) $uid);
		}
		return $out;
	}

	/**
	 * Build the WHERE clause and bound parameters for log filtering.
	 *
	 * @param int|null    $uid
	 * @param string|null $status 'ok' or 'error'.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function logFilterClause($uid, $status) {
		$conds  = array();
		$params = array();
		if ($uid !== null) {
			$conds[]  = 'uid = ?';
			$params[] = (int) $uid;
		}
		if ($status !== null && in_array($status, array('ok', 'error'), true)) {
			$conds[]  = 'status = ?';
			$params[] = $status;
		}
		$where = $conds ? ('WHERE '.implode(' AND ', $conds)) : '';
		return array($where, $params);
	}

	/**
	 * Count log rows matching the given filters.
	 *
	 * @param int|null    $uid
	 * @param string|null $status
	 * @return int
	 */
	public function countLogs($uid = null, $status = null) {
		list($where, $params) = $this->logFilterClause($uid, $status);
		$sth = $this->db->prepare('SELECT COUNT(*) FROM googlecontactsync_logs '.$where);
		$sth->execute($params);
		return (int) $sth->fetchColumn();
	}

	/**
	 * Fetch a page of log rows (newest first) for the admin Logs tab, resolving
	 * each row's user label. Messages are already redacted at write time.
	 *
	 * @param int|null    $uid
	 * @param string|null $status
	 * @param int         $limit
	 * @param int         $offset
	 * @return array<int,array<string,mixed>>
	 */
	public function getLogsTabData($uid = null, $status = null, $limit = 25, $offset = 0) {
		list($where, $params) = $this->logFilterClause($uid, $status);
		$sql = 'SELECT * FROM googlecontactsync_logs '.$where.' ORDER BY id DESC LIMIT '.(int) $limit.' OFFSET '.(int) $offset;
		$sth = $this->db->prepare($sql);
		$sth->execute($params);
		$rows = array();
		foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $r) {
			$logUid = (isset($r['uid']) && $r['uid'] !== null) ? (int) $r['uid'] : null;
			$rows[] = array(
				'id'       => (int) $r['id'],
				'uid'      => $logUid,
				'user'     => $logUid !== null ? $this->getUserDisplay($logUid) : '',
				'started'  => isset($r['started']) ? (int) $r['started'] : 0,
				'finished' => isset($r['finished']) ? (int) $r['finished'] : 0,
				'status'   => (string) ($r['status'] ?? ''),
				'added'    => (int) ($r['added'] ?? 0),
				'updated'  => (int) ($r['updated'] ?? 0),
				'deleted'  => (int) ($r['deleted'] ?? 0),
				'message'  => (string) ($r['message'] ?? ''),
			);
		}
		return $rows;
	}

	/**
	 * Delete old log rows. A positive $days removes entries finished more than
	 * that many days ago; a non-positive value clears every log row.
	 *
	 * @param int $days
	 * @return int Number of rows removed.
	 */
	public function clearOldLogs($days = 30) {
		$days = (int) $days;
		if ($days <= 0) {
			$sth = $this->db->prepare('DELETE FROM googlecontactsync_logs');
			$sth->execute();
		} else {
			$cutoff = time() - ($days * 86400);
			$sth = $this->db->prepare('DELETE FROM googlecontactsync_logs WHERE finished IS NOT NULL AND finished < ?');
			$sth->execute(array($cutoff));
		}
		return (int) $sth->rowCount();
	}

	/**
	 * Day-of-week labels keyed 0 (Sunday) .. 6 (Saturday).
	 *
	 * @return array<int,string>
	 */
	public function getDaysOfWeek() {
		return array(
			0 => _('Sunday'),
			1 => _('Monday'),
			2 => _('Tuesday'),
			3 => _('Wednesday'),
			4 => _('Thursday'),
			5 => _('Friday'),
			6 => _('Saturday'),
		);
	}

	/**
	 * Lazily build the encryption helper backed by the on-disk key file.
	 *
	 * @return TokenStore
	 */
	private function getTokenStore() {
		if ($this->tokenStore === null) {
			$this->tokenStore = new TokenStore($this->getKeyFilePath());
		}
		return $this->tokenStore;
	}

	/**
	 * Absolute path to the 256-bit key file, outside the web root.
	 *
	 * @return string
	 */
	private function getKeyFilePath() {
		$dir = $this->freepbx->Config->get('ASTETCDIR');
		if (empty($dir)) {
			$dir = '/etc/asterisk';
		}
		return rtrim($dir, '/').'/googlecontactsync.key';
	}

	// ///////////////////////////////// //
	// Settings (global OAuth + default)  //
	// ///////////////////////////////// //

	/**
	 * @return string The configured Google OAuth Client ID (empty when unset).
	 */
	public function getClientId() {
		return (string) $this->getConfig(self::KEY_CLIENT_ID);
	}

	/**
	 * Persist the OAuth credentials. The Client Secret is encrypted at rest.
	 *
	 * @param string      $clientId
	 * @param string|null $clientSecret Pass null to leave the stored secret untouched.
	 */
	public function setCredentials($clientId, $clientSecret) {
		$clientId = trim((string) $clientId);
		$this->setConfig(self::KEY_CLIENT_ID, $clientId !== '' ? $clientId : false);

		if ($clientSecret !== null) {
			$clientSecret = (string) $clientSecret;
			if ($clientSecret === '') {
				$this->setConfig(self::KEY_CLIENT_SECRET, false);
			} else {
				$this->setConfig(self::KEY_CLIENT_SECRET, $this->getTokenStore()->encrypt($clientSecret));
			}
		}
		return true;
	}

	/**
	 * Decrypt and return the stored Client Secret. For server-side OAuth use only;
	 * never surface this in the UI or logs.
	 *
	 * @return string Empty string when not set or undecryptable.
	 */
	public function getClientSecret() {
		$enc = $this->getConfig(self::KEY_CLIENT_SECRET);
		if (empty($enc)) {
			return '';
		}
		$plain = $this->getTokenStore()->decrypt($enc);
		return $plain === null ? '' : $plain;
	}

	/**
	 * @return bool Whether a Client Secret is currently stored.
	 */
	public function hasClientSecret() {
		return !empty($this->getConfig(self::KEY_CLIENT_SECRET));
	}

	/**
	 * The OAuth redirect URI the admin must register in Google Cloud. Returns the
	 * admin-configured override when set, otherwise an auto-detected default.
	 *
	 * @return string e.g. https://pbx.example.com/ucp/index.php
	 */
	public function getRedirectUri() {
		$stored = trim((string) $this->getConfig(self::KEY_REDIRECT_URI));
		if ($stored !== '') {
			return $stored;
		}
		return $this->getDefaultRedirectUri();
	}

	/**
	 * Auto-detected redirect URI from the current request host (the suggested
	 * default shown when no override is configured).
	 *
	 * @return string
	 */
	public function getDefaultRedirectUri() {
		$host = '';
		if (!empty($_SERVER['HTTP_HOST'])) {
			$host = $_SERVER['HTTP_HOST'];
		} elseif (!empty($_SERVER['SERVER_NAME'])) {
			$host = $_SERVER['SERVER_NAME'];
		} else {
			$host = gethostname();
		}
		return 'https://'.$host.'/ucp/index.php';
	}

	/**
	 * Persist an admin-configured redirect URI override. An empty value clears the
	 * override so the auto-detected default is used again. Only well-formed HTTPS
	 * URLs are accepted (Google rejects non-HTTPS redirect URIs).
	 *
	 * @param string $uri
	 * @return bool True when stored/cleared; false when the value was rejected.
	 */
	public function setRedirectUri($uri) {
		$uri = trim((string) $uri);
		if ($uri === '') {
			$this->setConfig(self::KEY_REDIRECT_URI, false);
			return true;
		}
		if (!filter_var($uri, FILTER_VALIDATE_URL) || stripos($uri, 'https://') !== 0) {
			return false;
		}
		$this->setConfig(self::KEY_REDIRECT_URI, $uri);
		return true;
	}

	/**
	 * The global default sync schedule.
	 *
	 * @return array{frequency:string,time:string,dow:int}
	 */
	public function getGlobalFrequency() {
		$frequency = (string) $this->getConfig(self::KEY_FREQUENCY);
		if (!in_array($frequency, self::FREQUENCIES, true)) {
			$frequency = 'daily';
		}
		$time = (string) $this->getConfig(self::KEY_FREQ_TIME);
		if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
			$time = '03:00';
		}
		$dow = $this->getConfig(self::KEY_FREQ_DOW);
		$dow = ($dow === false || $dow === '') ? 1 : (int) $dow;
		if ($dow < 0 || $dow > 6) {
			$dow = 1;
		}
		return array(
			'frequency' => $frequency,
			'time'      => $time,
			'dow'       => $dow,
		);
	}

	/**
	 * Persist the global default sync schedule, validating all inputs.
	 *
	 * @param string      $frequency One of self::FREQUENCIES.
	 * @param string|null $time      HH:MM (used for daily/weekly).
	 * @param int|null    $dow       0-6, Sunday..Saturday (used for weekly).
	 */
	public function setGlobalFrequency($frequency, $time = null, $dow = null) {
		$frequency = (string) $frequency;
		if (!in_array($frequency, self::FREQUENCIES, true)) {
			$frequency = 'daily';
		}
		$this->setConfig(self::KEY_FREQUENCY, $frequency);

		if ($time !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $time)) {
			$this->setConfig(self::KEY_FREQ_TIME, (string) $time);
		}

		if ($dow !== null) {
			$dow = (int) $dow;
			if ($dow >= 0 && $dow <= 6) {
				$this->setConfig(self::KEY_FREQ_DOW, $dow);
			}
		}
		return true;
	}

	// ///////////////////////////////// //
	// Accounts                           //
	// ///////////////////////////////// //

	/**
	 * Fetch the stored account row for a userman uid (raw columns; token columns
	 * remain encrypted). Returns null when the user has no connected account.
	 *
	 * @param int $uid
	 * @return array<string,mixed>|null
	 */
	public function getAccountByUid($uid) {
		$sth = $this->db->prepare('SELECT * FROM googlecontactsync_accounts WHERE uid = ? LIMIT 1');
		$sth->execute(array((int) $uid));
		$row = $sth->fetch(\PDO::FETCH_ASSOC);
		return $row !== false ? $row : null;
	}

	/**
	 * Fetch all stored account rows (raw columns; token columns remain
	 * encrypted), ordered by uid. Used by the admin Users tab and the console
	 * `--list` command.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getAllAccounts() {
		$sth = $this->db->prepare('SELECT * FROM googlecontactsync_accounts ORDER BY uid');
		$sth->execute();
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
		return $rows !== false ? $rows : array();
	}

	/**
	 * Fetch all enabled account rows (sync candidates), ordered by uid.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getEnabledAccounts() {
		$sth = $this->db->prepare('SELECT * FROM googlecontactsync_accounts WHERE enabled = 1 ORDER BY uid');
		$sth->execute();
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
		return $rows !== false ? $rows : array();
	}

	/**
	 * Resolve the schedule that actually governs an account: the per-user
	 * override when one is set to a valid frequency, otherwise the admin global
	 * default. Missing/invalid override sub-fields fall back to the global value.
	 *
	 * @param array<string,mixed> $account
	 * @return array{frequency:string,time:string,dow:int}
	 */
	public function getEffectiveFrequency($account) {
		$global = $this->getGlobalFrequency();
		$freq   = isset($account['frequency']) ? (string) $account['frequency'] : '';
		if (!in_array($freq, self::FREQUENCIES, true)) {
			return $global;
		}

		$time = $global['time'];
		if (isset($account['freq_time']) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $account['freq_time'])) {
			$time = (string) $account['freq_time'];
		}

		$dow = $global['dow'];
		if (isset($account['freq_dow']) && $account['freq_dow'] !== '' && $account['freq_dow'] !== null) {
			$candidate = (int) $account['freq_dow'];
			if ($candidate >= 0 && $candidate <= 6) {
				$dow = $candidate;
			}
		}

		return array('frequency' => $freq, 'time' => $time, 'dow' => $dow);
	}

	/**
	 * Persist freshly-issued OAuth tokens for a user, encrypting them at rest.
	 * Upserts on the unique `uid`. The refresh token is preserved when Google
	 * does not return a new one (it only re-issues it on first consent).
	 *
	 * @param int                  $uid
	 * @param array<string,mixed>  $tokenArray    Token response from Google.
	 * @param array<string,mixed>  $idTokenClaims Verified id_token payload.
	 */
	public function saveAccountTokens($uid, $tokenArray, $idTokenClaims) {
		$uid   = (int) $uid;
		$store = $this->getTokenStore();

		$refresh    = isset($tokenArray['refresh_token']) ? (string) $tokenArray['refresh_token'] : '';
		$expiresIn  = isset($tokenArray['expires_in']) ? (int) $tokenArray['expires_in'] : 3600;
		$created    = isset($tokenArray['created']) ? (int) $tokenArray['created'] : time();
		$tokenExp   = $created + $expiresIn;
		$sub        = isset($idTokenClaims['sub']) ? (string) $idTokenClaims['sub'] : '';
		$email      = isset($idTokenClaims['email']) ? (string) $idTokenClaims['email'] : '';
		$accessEnc  = $store->encrypt(json_encode($tokenArray));

		$existing = $this->getAccountByUid($uid);
		if ($refresh === '' && !empty($existing['refresh_token'])) {
			// Keep the previously stored (already-encrypted) refresh token.
			$refreshEnc = $existing['refresh_token'];
		} else {
			$refreshEnc = $refresh !== '' ? $store->encrypt($refresh) : null;
		}

		$now = time();
		if ($existing) {
			$sth = $this->db->prepare(
				'UPDATE googlecontactsync_accounts'
				.' SET google_sub = ?, google_email = ?, access_token = ?, refresh_token = ?,'
				.' token_expires = ?, enabled = 1, last_status = ?, updated = ?'
				.' WHERE uid = ?'
			);
			$sth->execute(array($sub, $email, $accessEnc, $refreshEnc, $tokenExp, 'ok', $now, $uid));
		} else {
			$sth = $this->db->prepare(
				'INSERT INTO googlecontactsync_accounts'
				.' (uid, google_sub, google_email, access_token, refresh_token, token_expires,'
				.' enabled, last_status, created, updated)'
				.' VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)'
			);
			$sth->execute(array($uid, $sub, $email, $accessEnc, $refreshEnc, $tokenExp, 'ok', $now, $now));

			// A brand-new account id can re-use the auto-increment value of a
			// previously disconnected account (e.g. after a disconnect/reconnect
			// cycle). Purge any mapping/log rows still keyed to that id so the
			// fresh connection starts from a guaranteed clean slate and its first
			// sync cannot collide with stale `googlecontactsync_contacts` rows.
			$newId = (int) $this->db->lastInsertId();
			if ($newId > 0) {
				$sth = $this->db->prepare('DELETE FROM googlecontactsync_contacts WHERE account_id = ?');
				$sth->execute(array($newId));
				$sth = $this->db->prepare('DELETE FROM googlecontactsync_logs WHERE account_id = ?');
				$sth->execute(array($newId));
			}
		}
		return true;
	}

	/**
	 * Contact Manager groups a user may import into: their own private groups
	 * plus any external groups they are permitted to use. Internal groups (auto
	 * from extensions) are never valid import targets and are excluded.
	 *
	 * This set is the authoritative allow-list used to validate a user's group
	 * selection server-side (IDOR protection).
	 *
	 * @param int $uid userman user id.
	 * @return array<int,array{id:int,name:string,type:string,owner:int}>
	 */
	public function getAvailableGroups($uid) {
		$uid    = (int) $uid;
		$groups = $this->freepbx->Contactmanager->getGroupsbyOwner($uid);
		$out    = array();
		foreach ((array) $groups as $g) {
			$type = isset($g['type']) ? (string) $g['type'] : '';
			if ($type !== 'private' && $type !== 'external') {
				continue;
			}
			$out[] = array(
				'id'    => (int) $g['id'],
				'name'  => (string) $g['name'],
				'type'  => $type,
				'owner' => isset($g['owner']) ? (int) $g['owner'] : -1,
			);
		}
		return $out;
	}

	/**
	 * Persist the Contact Manager group a user's contacts import into. The group
	 * id is validated against {@see getAvailableGroups()} so a user can only
	 * target a private group they own or an external group they may access; its
	 * real type is taken from Contact Manager (never trusted from the client).
	 *
	 * @param int    $uid
	 * @param int    $groupid
	 * @param string $type Ignored; the authoritative type is resolved server-side.
	 * @return bool True when stored; false when the user/group is invalid.
	 */
	public function setAccountTarget($uid, $groupid, $type = '') {
		$uid = (int) $uid;
		if (!$this->getAccountByUid($uid)) {
			return false;
		}
		$groupid = (int) $groupid;
		$group   = null;
		foreach ($this->getAvailableGroups($uid) as $g) {
			if ($g['id'] === $groupid) {
				$group = $g;
				break;
			}
		}
		if ($group === null) {
			return false;
		}
		$sth = $this->db->prepare(
			'UPDATE googlecontactsync_accounts SET target_groupid = ?, target_group_type = ?, updated = ? WHERE uid = ?'
		);
		$sth->execute(array($groupid, $group['type'], time(), $uid));
		return true;
	}

	/**
	 * Create a fresh private "Google Contacts" group owned by the user and set it
	 * as their import target. Used by the UCP "Create new private group" option.
	 *
	 * @param int $uid
	 * @return bool True when created and stored; false otherwise.
	 */
	public function createAndSetTargetGroup($uid) {
		$uid = (int) $uid;
		if (!$this->getAccountByUid($uid)) {
			return false;
		}
		$res = $this->freepbx->Contactmanager->addGroup(_('Google Contacts'), 'private', $uid, false);
		if (empty($res['status']) || empty($res['id'])) {
			return false;
		}
		$sth = $this->db->prepare(
			'UPDATE googlecontactsync_accounts SET target_groupid = ?, target_group_type = ?, updated = ? WHERE uid = ?'
		);
		$sth->execute(array((int) $res['id'], 'private', time(), $uid));
		return true;
	}

	/**
	 * Persist a user's per-account schedule override. A frequency that is not one
	 * of self::FREQUENCIES (e.g. the UCP "Use system default" choice) clears the
	 * override so the account follows the admin global default again.
	 *
	 * @param int         $uid
	 * @param string      $freq One of self::FREQUENCIES, or any other value to clear.
	 * @param string|null $time HH:MM (used for daily/weekly).
	 * @param int|null    $dow  0-6, Sunday..Saturday (used for weekly).
	 * @return bool True when stored/cleared; false when the user has no account.
	 */
	public function setAccountFrequency($uid, $freq, $time = null, $dow = null) {
		$uid = (int) $uid;
		if (!$this->getAccountByUid($uid)) {
			return false;
		}
		$freq = (string) $freq;
		if (!in_array($freq, self::FREQUENCIES, true)) {
			$sth = $this->db->prepare(
				'UPDATE googlecontactsync_accounts'
				.' SET frequency = NULL, freq_time = NULL, freq_dow = NULL, updated = ? WHERE uid = ?'
			);
			$sth->execute(array(time(), $uid));
			return true;
		}

		$timeVal = null;
		if ($time !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $time)) {
			$timeVal = (string) $time;
		}
		$dowVal = null;
		if ($dow !== null && $dow !== '') {
			$d = (int) $dow;
			if ($d >= 0 && $d <= 6) {
				$dowVal = $d;
			}
		}
		$sth = $this->db->prepare(
			'UPDATE googlecontactsync_accounts'
			.' SET frequency = ?, freq_time = ?, freq_dow = ?, updated = ? WHERE uid = ?'
		);
		$sth->execute(array($freq, $timeVal, $dowVal, time(), $uid));
		return true;
	}

	/**
	 * Disconnect a user's Google account: best-effort token revocation at Google,
	 * then purge the local account row and its contact mappings.
	 *
	 * @param int $uid
	 * @return bool
	 */
	public function disconnect($uid) {
		$uid     = (int) $uid;
		$account = $this->getAccountByUid($uid);
		if (!$account) {
			return true;
		}

		$this->revokeAccountToken($account);

		$sth = $this->db->prepare('DELETE FROM googlecontactsync_contacts WHERE account_id = ?');
		$sth->execute(array((int) $account['id']));
		$sth = $this->db->prepare('DELETE FROM googlecontactsync_accounts WHERE uid = ?');
		$sth->execute(array($uid));
		return true;
	}

	/**
	 * Best-effort revocation of an account's Google grant at Google's revoke
	 * endpoint. The refresh token is preferred (revoking it invalidates the whole
	 * grant); the access token is the fallback. Never throws: a failed revoke
	 * (network error, already-expired token, or missing credentials) must not
	 * block local cleanup, and no token value is ever surfaced to callers or logs.
	 *
	 * @param array<string,mixed> $account
	 */
	private function revokeAccountToken(array $account) {
		try {
			$store  = $this->getTokenStore();
			$revoke = '';
			if (!empty($account['refresh_token'])) {
				$revoke = (string) $store->decrypt($account['refresh_token']);
			}
			if ($revoke === '' && !empty($account['access_token'])) {
				$tok = json_decode((string) $store->decrypt($account['access_token']), true);
				if (is_array($tok) && !empty($tok['access_token'])) {
					$revoke = (string) $tok['access_token'];
				}
			}
			if ($revoke !== '' && $this->getClientId() !== '' && $this->hasClientSecret()) {
				$this->getGoogleClient()->revokeToken($revoke);
			}
		} catch (\Exception $e) {
			// Revocation is best-effort; callers always purge locally regardless.
		}
	}

	/**
	 * Summarize a user's connection state for UCP/admin display (no secrets).
	 *
	 * @param int $uid
	 * @return array<string,mixed>
	 */
	public function getConnectionStatus($uid) {
		$account = $this->getAccountByUid($uid);
		return array(
			'connected'             => !empty($account),
			'email'                 => $account['google_email'] ?? '',
			'last_sync'             => isset($account['last_sync']) ? (int) $account['last_sync'] : null,
			'last_status'           => $account['last_status'] ?? '',
			'last_message'          => $account['last_message'] ?? '',
			'enabled'               => isset($account['enabled']) ? (bool) $account['enabled'] : false,
			'target_groupid'        => isset($account['target_groupid']) ? (int) $account['target_groupid'] : 0,
			'target_group_type'     => $account['target_group_type'] ?? '',
			'frequency'             => $account['frequency'] ?? '',
			'freq_time'             => $account['freq_time'] ?? '',
			'freq_dow'              => isset($account['freq_dow']) && $account['freq_dow'] !== null ? (int) $account['freq_dow'] : null,
			'credentialsConfigured' => ($this->getClientId() !== '' && $this->hasClientSecret()),
		);
	}

	// ///////////////////////////////// //
	// OAuth                              //
	// ///////////////////////////////// //

	/**
	 * Build the Google consent URL for a user, embedding a signed, single-use
	 * `state` value. Requires configured credentials and an HTTPS request.
	 *
	 * @param int $uid
	 * @return string
	 * @throws \Exception When credentials are missing or the request is not HTTPS.
	 */
	public function buildAuthUrl($uid) {
		$uid = (int) $uid;
		if ($uid <= 0) {
			throw new \Exception(_('Invalid user.'));
		}
		if ($this->getClientId() === '' || !$this->hasClientSecret()) {
			throw new \Exception(_('Google OAuth credentials are not configured. Please ask your administrator to set them up.'));
		}
		if (!$this->isHttpsRequest()) {
			throw new \Exception(_('A secure (HTTPS) connection is required to connect a Google account.'));
		}

		$client = $this->getGoogleClient();
		$client->setState($this->signState($uid));
		return $client->createAuthUrl();
	}

	/**
	 * Whether the current request reached the client over HTTPS, accounting for
	 * a TLS-terminating reverse proxy (e.g. Nginx Proxy Manager) where the PHP
	 * backend itself is served over plain HTTP. Trusts the standard forwarded
	 * headers the proxy injects.
	 *
	 * @return bool
	 */
	private function isHttpsRequest() {
		if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
			return true;
		}
		if (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
			// May be a comma-separated list when chained; the first hop is the client.
			$proto = strtolower(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
			if ($proto === 'https') {
				return true;
			}
		}
		if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
			return true;
		}
		if (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
			return true;
		}
		return false;
	}

	/**
	 * Handle the OAuth redirect: validate `state`, exchange the code for tokens,
	 * verify the id_token, and persist the encrypted tokens.
	 *
	 * @param string   $code       Authorization code from Google.
	 * @param string   $state      Signed state value returned by Google.
	 * @param int|null $sessionUid  Logged-in UCP uid for IDOR/CSRF binding.
	 * @return array<string,mixed>
	 * @throws \Exception On any validation or exchange failure.
	 */
	public function handleOAuthCallback($code, $state, $sessionUid = null) {
		$uid = $this->verifyState($state);
		if ($sessionUid !== null && (int) $sessionUid !== $uid) {
			throw new \Exception(_('OAuth session mismatch.'));
		}
		if (!is_string($code) || $code === '') {
			throw new \Exception(_('Missing authorization code.'));
		}

		$client = $this->getGoogleClient();
		$token  = $client->fetchAccessTokenWithAuthCode($code);
		if (!is_array($token) || isset($token['error']) || empty($token['access_token'])) {
			throw new \Exception(_('Failed to obtain a Google access token.'));
		}

		$claims = $client->verifyIdToken();
		if (!is_array($claims) || empty($claims['sub'])) {
			throw new \Exception(_('Could not verify the Google account identity.'));
		}

		$this->saveAccountTokens($uid, $token, $claims);
		return array(
			'status' => true,
			'uid'    => $uid,
			'email'  => isset($claims['email']) ? (string) $claims['email'] : '',
		);
	}

	/**
	 * Build a configured Google API client from the stored credentials.
	 *
	 * @return \Google\Client
	 */
	private function getGoogleClient() {
		$factory = new GoogleClientFactory(
			$this->getClientId(),
			$this->getClientSecret(),
			$this->getRedirectUri()
		);
		return $factory->createClient();
	}

	/**
	 * Create a signed, single-use `state` value bound to the user. The random
	 * nonce is stored server-side (consumed on callback) to defeat replay/CSRF.
	 *
	 * @param int $uid
	 * @return string `base64url(payload).hex(hmac)`
	 */
	private function signState($uid) {
		$nonce = bin2hex(random_bytes(16));
		$exp   = time() + self::STATE_TTL;
		$data  = $this->base64UrlEncode((string) json_encode(array('u' => (int) $uid, 'n' => $nonce, 'e' => $exp)));
		$sig   = hash_hmac('sha256', $data, $this->getStateKey());
		$this->setConfig('state_nonce', $nonce.'|'.$exp, 'oauth|'.(int) $uid);
		return $data.'.'.$sig;
	}

	/**
	 * Validate a `state` value: signature, expiry, and single-use nonce. The
	 * nonce is consumed (deleted) here regardless of outcome.
	 *
	 * @param string $state
	 * @return int The uid encoded in the state.
	 * @throws \Exception When the state is invalid, expired, or already used.
	 */
	private function verifyState($state) {
		if (!is_string($state) || strpos($state, '.') === false) {
			throw new \Exception(_('Invalid OAuth state.'));
		}
		list($data, $sig) = explode('.', $state, 2);
		$expected = hash_hmac('sha256', $data, $this->getStateKey());
		if (!hash_equals($expected, (string) $sig)) {
			throw new \Exception(_('OAuth state signature mismatch.'));
		}
		$json = json_decode($this->base64UrlDecode($data), true);
		if (!is_array($json) || !isset($json['u'], $json['n'], $json['e'])) {
			throw new \Exception(_('Malformed OAuth state.'));
		}
		if (time() > (int) $json['e']) {
			throw new \Exception(_('The OAuth request expired. Please try again.'));
		}
		$uid    = (int) $json['u'];
		$stored = (string) $this->getConfig('state_nonce', 'oauth|'.$uid);
		$this->setConfig('state_nonce', false, 'oauth|'.$uid); // single-use: consume now
		$parts = explode('|', $stored, 2);
		if ($stored === '' || !hash_equals($parts[0], (string) $json['n'])) {
			throw new \Exception(_('The OAuth state is invalid or has already been used.'));
		}
		return $uid;
	}

	/**
	 * Persistent HMAC key used to sign OAuth `state` values (generated on first use).
	 *
	 * @return string
	 */
	private function getStateKey() {
		$key = (string) $this->getConfig(self::KEY_STATE_KEY);
		if ($key === '') {
			$key = bin2hex(random_bytes(32));
			$this->setConfig(self::KEY_STATE_KEY, $key);
		}
		return $key;
	}

	/**
	 * CSRF token for a per-user UCP action (e.g. disconnect, savesettings,
	 * syncnow), bound to the user, the action name, and the per-install signing
	 * key. Lets the no-JS server-rendered widget protect its links/forms.
	 *
	 * @param int    $uid
	 * @param string $action
	 * @return string
	 */
	public function getActionToken($uid, $action) {
		return hash_hmac('sha256', (string) $action.'|'.(int) $uid, $this->getStateKey());
	}

	/**
	 * @param int    $uid
	 * @param string $action
	 * @param string $token
	 * @return bool
	 */
	public function verifyActionToken($uid, $action, $token) {
		return hash_equals($this->getActionToken($uid, $action), (string) $token);
	}

	/**
	 * CSRF token for the UCP "Disconnect" action.
	 *
	 * @param int $uid
	 * @return string
	 */
	public function getDisconnectToken($uid) {
		return $this->getActionToken($uid, 'disconnect');
	}

	/**
	 * @param int    $uid
	 * @param string $token
	 * @return bool
	 */
	public function verifyDisconnectToken($uid, $token) {
		return $this->verifyActionToken($uid, 'disconnect', (string) $token);
	}

	private function base64UrlEncode($data) {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	private function base64UrlDecode($data) {
		return (string) base64_decode(strtr($data, '-_', '+/'));
	}

	// ///////////////////////////////// //
	// Sync (delegates to Lib\PeopleSync) //
	// ///////////////////////////////// //

	/**
	 * Run a one-way sync (Google → Contact Manager) for a single user.
	 *
	 * @param int  $uid
	 * @param bool $full When true, perform a clean full import: every previously
	 *        imported contact is deleted and re-imported afresh. Otherwise the
	 *        run is incremental when a sync token is available.
	 * @return array<string,mixed> Result summary (status, counts, message).
	 * @throws \Exception When the user has no connected Google account.
	 */
	public function syncUid($uid, $full = false) {
		$uid     = (int) $uid;
		$account = $this->getAccountByUid($uid);
		if (!$account) {
			throw new \Exception(_('No connected Google account for this user.'));
		}
		return $this->getPeopleSync()->syncAccount($account, (bool) $full);
	}

	/**
	 * Run every enabled account that is currently due, based on its effective
	 * schedule and last-sync time (spec §10.1). One failing account never blocks
	 * the others. Invoked by the recurring cron entry via the console command.
	 *
	 * @return array<int,array<string,mixed>> Per-account result summaries.
	 */
	public function runDueSyncs() {
		$now     = time();
		$results = array();
		foreach ($this->getEnabledAccounts() as $account) {
			$eff      = $this->getEffectiveFrequency($account);
			$lastSync = isset($account['last_sync']) ? (int) $account['last_sync'] : 0;
			if (!Schedule::isDue($eff, $lastSync, $now)) {
				continue;
			}
			$results[] = $this->runAccountSync($account);
		}
		return $results;
	}

	/**
	 * Sync a single account row, catching failures so a batch can continue.
	 *
	 * @param array<string,mixed> $account
	 * @return array<string,mixed> Result summary including `uid` and `status`.
	 */
	private function runAccountSync($account) {
		$uid = (int) $account['uid'];
		try {
			$res = $this->getPeopleSync()->syncAccount($account);
		} catch (\Throwable $e) {
			$res = array('status' => false, 'message' => $e->getMessage());
		}
		$res['uid'] = $uid;
		return $res;
	}

	/**
	 * Build the sync engine with its collaborators (Contact Manager BMO, the
	 * module's PDO handle, the encryption helper, and the CM spool tmp dir used
	 * for staging contact photos).
	 *
	 * @return PeopleSync
	 */
	private function getPeopleSync() {
		$spool  = (string) $this->freepbx->Config->get('ASTSPOOLDIR');
		$tmpDir = ($spool !== '') ? rtrim($spool, '/').'/tmp' : '';
		return new PeopleSync($this, $this->freepbx->Contactmanager, $this->db, $this->getTokenStore(), $tmpDir);
	}

	// ///////////////////////////////// //
	// Hooks                              //
	// ///////////////////////////////// //

	public function ucpConfigPage($mods, $uid) {
	}

	/**
	 * UCP user-deletion hook: purge the deleted user's Google Contact Sync data.
	 * @param int    $id      userman user id (server-trusted)
	 * @param string $display
	 * @param mixed  $data
	 */
	public function ucpDelUser($id, $display, $data) {
		$this->deleteUserData($id);
	}

	/**
	 * Userman user-deletion hook: purge the deleted user's Google Contact Sync
	 * data. Registered alongside the UCP hook so deletion from either surface
	 * leaves no orphaned mappings, logs, or live Google tokens.
	 * @param int    $id      userman user id (server-trusted)
	 * @param string $display
	 * @param mixed  $data
	 */
	public function usermanDelUser($id, $display, $data) {
		$this->deleteUserData($id);
	}

	/**
	 * Full account cleanup for a deleted userman/UCP user: revoke the Google
	 * grant, remove the Contact Manager entries this account imported, then delete
	 * the mapping, log, and account rows. Idempotent and safe to call when the
	 * user has no connected account.
	 *
	 * @param int $id userman user id (server-trusted; never client-supplied)
	 */
	private function deleteUserData($id) {
		$uid = (int) $id;
		if ($uid <= 0) {
			return;
		}
		$account = $this->getAccountByUid($uid);
		if (!$account) {
			return;
		}
		$accountId = (int) $account['id'];

		$this->revokeAccountToken($account);
		$this->deleteImportedEntries($accountId);

		$sth = $this->db->prepare('DELETE FROM googlecontactsync_contacts WHERE account_id = ?');
		$sth->execute(array($accountId));
		$sth = $this->db->prepare('DELETE FROM googlecontactsync_logs WHERE account_id = ?');
		$sth->execute(array($accountId));
		$sth = $this->db->prepare('DELETE FROM googlecontactsync_accounts WHERE id = ?');
		$sth->execute(array($accountId));
	}

	/**
	 * Remove the Contact Manager entries this account imported, via the public CM
	 * API (never direct SQL). Entries whose private group was already removed by
	 * Contact Manager's own user-delete hook are skipped silently; entries in
	 * shared external groups are removed so none are left orphaned. Affected
	 * groups have their contact files regenerated once after the batch.
	 *
	 * @param int $accountId
	 */
	private function deleteImportedEntries($accountId) {
		$accountId = (int) $accountId;
		$sth = $this->db->prepare(
			'SELECT entryid, groupid FROM googlecontactsync_contacts WHERE account_id = ? AND entryid IS NOT NULL'
		);
		$sth->execute(array($accountId));
		$rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
		if (!$rows) {
			return;
		}

		$cm     = $this->freepbx->Contactmanager;
		$groups = array();
		foreach ($rows as $row) {
			$entryid = (int) $row['entryid'];
			if ($entryid <= 0) {
				continue;
			}
			try {
				$cm->deleteEntryByID($entryid, false);
			} catch (\Exception $e) {
				// Entry already gone (e.g. its private group was deleted by
				// Contact Manager's own user-delete hook); nothing to do.
			}
			if (!empty($row['groupid'])) {
				$groups[(int) $row['groupid']] = true;
			}
		}

		foreach (array_keys($groups) as $groupId) {
			try {
				$group = $cm->getGroupByID($groupId);
				if (!empty($group)) {
					$cm->updateContactUpdatedDetails($group['owner'], array($groupId));
				}
			} catch (\Exception $e) {
				// Group already removed; contact-file regeneration is moot.
			}
		}
	}
}
