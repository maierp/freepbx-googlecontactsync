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
		// M5 will register the recurring cron entry and seed default settings.
	}

	public function uninstall() {
		// M5/M8 will remove the cron entry and (optionally) imported data.
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
		return false;
	}

	public function ajaxHandler() {
		return false;
	}

	/**
	 * Render the admin page (Settings tab).
	 */
	public function showPage() {
		$settings = load_view(__DIR__.'/views/settings.php', array(
			'clientId'           => $this->getClientId(),
			'hasClientSecret'    => $this->hasClientSecret(),
			'redirectUri'        => trim((string) $this->getConfig(self::KEY_REDIRECT_URI)),
			'defaultRedirectUri' => $this->getDefaultRedirectUri(),
			'frequency'          => $this->getGlobalFrequency(),
			'daysOfWeek'         => $this->getDaysOfWeek(),
		));
		return load_view(__DIR__.'/views/main.php', array(
			'message'  => $this->message,
			'settings' => $settings,
		));
	}

	/**
	 * Day-of-week labels keyed 0 (Sunday) .. 6 (Saturday).
	 *
	 * @return array<int,string>
	 */
	private function getDaysOfWeek() {
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

	public function getAllAccounts() {
		// Implemented in M7 (admin Users tab).
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
		}
		return true;
	}

	public function setAccountTarget($uid, $groupid, $type) {
		// Implemented in M6 (UCP group selector).
	}

	public function setAccountFrequency($uid, $freq, $time = null, $dow = null) {
		// Implemented in M6 (UCP frequency override).
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
			// Revocation is best-effort; always purge locally regardless.
		}

		$sth = $this->db->prepare('DELETE FROM googlecontactsync_contacts WHERE account_id = ?');
		$sth->execute(array((int) $account['id']));
		$sth = $this->db->prepare('DELETE FROM googlecontactsync_accounts WHERE uid = ?');
		$sth->execute(array($uid));
		return true;
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
		if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
			throw new \Exception(_('A secure (HTTPS) connection is required to connect a Google account.'));
		}

		$client = $this->getGoogleClient();
		$client->setState($this->signState($uid));
		return $client->createAuthUrl();
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
	 * CSRF token for the UCP "Disconnect" action, bound to the user and the
	 * per-install signing key.
	 *
	 * @param int $uid
	 * @return string
	 */
	public function getDisconnectToken($uid) {
		return hash_hmac('sha256', 'disconnect|'.(int) $uid, $this->getStateKey());
	}

	/**
	 * @param int    $uid
	 * @param string $token
	 * @return bool
	 */
	public function verifyDisconnectToken($uid, $token) {
		return hash_equals($this->getDisconnectToken($uid), (string) $token);
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

	public function syncUid($uid) {
	}

	public function runDueSyncs() {
	}

	// ///////////////////////////////// //
	// Hooks                              //
	// ///////////////////////////////// //

	public function ucpConfigPage($mods, $uid) {
	}

	public function ucpDelUser($id, $display, $data) {
	}

	public function usermanDelUser($id, $display, $data) {
	}
}
