<?php
// vim: set ai ts=4 sw=4 ft=php:
/**
 * Google Contact Sync — FreePBX module (UCP)
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

namespace UCP\Modules;

use \UCP\Modules as Modules;

#[\AllowDynamicProperties]
class Googlecontactsync extends Modules {

	protected $module = 'Googlecontactsync';
	private $user = null;
	private $userId = false;

	public function __construct($Modules) {
		$this->Modules = $Modules;
		$this->user    = $this->UCP->User->getUser();
		$this->userId  = $this->user ? $this->user['id'] : false;
	}

	/**
	 * Runs on every authenticated UCP page load (via generateMenu), before any
	 * output. We use it as the pre-render hook to catch the Google OAuth
	 * redirect — which lands on /ucp/index.php — and the Disconnect action.
	 */
	public function getMenuItems() {
		$this->handleIncomingRequest();
		return array(
			'rawname' => 'googlecontactsync',
			'name'    => _('Google Contact Sync'),
		);
	}

	/**
	 * Detect and route the OAuth callback / disconnect request. Always derives
	 * the uid from the authenticated session (never from client input).
	 *
	 * The Google OAuth callback lands on the bare redirect URI (/ucp/index.php)
	 * carrying only Google's own query parameters (state, code, scope, iss) — it
	 * does NOT include our `googlecontactsync` marker. We therefore recognise it
	 * by the presence of our signed `state`; handleOAuthCallback() verifies that
	 * state and rejects anything that is not ours. The disconnect action is our
	 * own link, so it keeps using the explicit `googlecontactsync=disconnect`.
	 */
	private function handleIncomingRequest() {
		$gcs = $this->UCP->FreePBX->Googlecontactsync;

		// --- Google OAuth callback (state always present; code on success, error on denial). ---
		if (isset($_GET['state']) && (isset($_GET['code']) || isset($_GET['error']))) {
			if ($this->userId === false) {
				return;
			}
			if (isset($_GET['error'])) {
				$this->redirectClean(array('googlecontactsyncmsg' => 'denied'));
			}
			try {
				$gcs->handleOAuthCallback((string) $_GET['code'], (string) $_GET['state'], $this->userId);
				$this->redirectClean(array('googlecontactsyncmsg' => 'connected'));
			} catch (\Exception $e) {
				$this->redirectClean(array('googlecontactsyncmsg' => 'error'));
			}
			return;
		}

		// --- Disconnect action (our own link). ---
		if ($this->userId !== false && isset($_REQUEST['googlecontactsync'])
			&& (string) $_REQUEST['googlecontactsync'] === 'disconnect' && isset($_GET['token'])) {
			if ($gcs->verifyDisconnectToken($this->userId, (string) $_GET['token'])) {
				$gcs->disconnect($this->userId);
				$this->redirectClean(array('googlecontactsyncmsg' => 'disconnected'));
			} else {
				$this->redirectClean(array('googlecontactsyncmsg' => 'error'));
			}
		}
	}

	/**
	 * Whitelist the AJAX commands this UCP module accepts (called by the UCP
	 * Ajax dispatcher before {@see ajaxHandler()}).
	 *
	 * @param string $command
	 * @param array  $settings
	 * @return bool
	 */
	public function ajaxRequest($command, $settings) {
		switch ($command) {
			case 'savesettings':
			case 'syncnow':
			case 'fullsync':
				return true;
			default:
				return false;
		}
	}

	/**
	 * Handle a UCP AJAX request. Returns a {status, message} array rendered as
	 * JSON. The uid is always taken from the authenticated session (no IDOR) and
	 * each action is additionally bound to a per-user CSRF token.
	 *
	 * @return array{status:bool,message:string}
	 */
	public function ajaxHandler() {
		if ($this->userId === false) {
			return array('status' => false, 'message' => _('You are not signed in.'));
		}
		$gcs     = $this->UCP->FreePBX->Googlecontactsync;
		$command = isset($_REQUEST['command']) ? (string) $_REQUEST['command'] : '';
		$token   = isset($_POST['token']) ? (string) $_POST['token'] : '';

		switch ($command) {
			case 'savesettings':
				if (!$gcs->verifyActionToken($this->userId, 'savesettings', $token)) {
					return array('status' => false, 'message' => _('Security token mismatch. Please reload the page and try again.'));
				}
				$group = isset($_POST['target_group']) ? (string) $_POST['target_group'] : '';
				if ($group === '__new__') {
					$gcs->createAndSetTargetGroup($this->userId);
				} elseif ($group !== '') {
					$gcs->setAccountTarget($this->userId, (int) $group, '');
				}
				$freq = isset($_POST['frequency']) ? (string) $_POST['frequency'] : 'default';
				$time = isset($_POST['freq_time']) ? (string) $_POST['freq_time'] : null;
				$dow  = isset($_POST['freq_dow']) ? (string) $_POST['freq_dow'] : null;
				$gcs->setAccountFrequency($this->userId, $freq, $time, $dow);
				return array('status' => true, 'message' => _('Your settings have been saved.'));

			case 'syncnow':
				if (!$gcs->verifyActionToken($this->userId, 'syncnow', $token)) {
					return array('status' => false, 'message' => _('Security token mismatch. Please reload the page and try again.'));
				}
				return $this->runSync($gcs, false);

			case 'fullsync':
				if (!$gcs->verifyActionToken($this->userId, 'fullsync', $token)) {
					return array('status' => false, 'message' => _('Security token mismatch. Please reload the page and try again.'));
				}
				return $this->runSync($gcs, true);
		}
		return array('status' => false, 'message' => _('Unknown request.'));
	}

	/**
	 * Run a sync for the session user and build the AJAX response (status, a
	 * user-facing message, and the refreshed last-sync / last-error fields).
	 *
	 * @param object $gcs  The main Googlecontactsync BMO.
	 * @param bool   $full When true, perform a clean full import; otherwise an
	 *        incremental sync.
	 * @return array<string,mixed>
	 */
	private function runSync($gcs, $full) {
		try {
			$gcs->syncUid($this->userId, $full);
			$st = $gcs->getConnectionStatus($this->userId);
			return array(
				'status'    => true,
				'message'   => $full ? _('Full sync completed.') : _('Sync completed.'),
				'lastSync'  => $this->formatLastSync($st),
				'lastError' => $this->extractLastError($st),
			);
		} catch (\Exception $e) {
			$st = $gcs->getConnectionStatus($this->userId);
			return array(
				'status'    => false,
				'message'   => _('The sync did not complete. Please try again later.'),
				'lastSync'  => $this->formatLastSync($st),
				'lastError' => $this->extractLastError($st),
			);
		}
	}

	/**
	 * The last recorded sync error for the widget, or '' when the last run was
	 * successful. Used to refresh the error box after an AJAX "Sync now".
	 *
	 * @param array<string,mixed> $status
	 * @return string
	 */
	private function extractLastError($status) {
		if (($status['last_status'] ?? '') === 'error' && !empty($status['last_message'])) {
			return (string) $status['last_message'];
		}
		return '';
	}

	/**
	 * Render the "Last sync" line shown in the widget from a connection-status
	 * array, so the AJAX "Sync now" response can refresh it in place.
	 *
	 * @param array<string,mixed> $status
	 * @return string Escaped, display-ready text.
	 */
	private function formatLastSync($status) {
		if (empty($status['last_sync'])) {
			return _('No sync has run yet.');
		}
		return _('Last sync:').' '
			.date('Y-m-d H:i', (int) $status['last_sync'])
			.' ('.(string) $status['last_status'].')';
	}

	/**
	 * Redirect back to a clean UCP URL (drops the OAuth query parameters so a
	 * reload cannot replay the code/state).
	 *
	 * A root-relative target is used deliberately: the browser resolves it
	 * against the current origin, so a forged `Host` header cannot turn this
	 * into an open redirect to an attacker-controlled domain.
	 *
	 * @param array<string,string> $params
	 */
	private function redirectClean($params) {
		$url = '/ucp/index.php';
		if (!empty($params)) {
			$url .= '?'.http_build_query($params);
		}
		header('Location: '.$url);
		exit;
	}

	public function getWidgetList() {
		$responseData = array(
			'rawname' => 'googlecontactsync',
			'display' => _('Google Contact Sync'),
			'icon'    => 'fa fa-google',
			'list'    => array(),
		);
		if ($this->userId === false) {
			return $responseData;
		}
		$responseData['list']['googlecontactsync'] = array(
			'display'     => _('Google Contact Sync'),
			'defaultsize' => array('height' => 4, 'width' => 3),
			'minsize'     => array('height' => 3, 'width' => 2),
			'description' => _('Connect your Google account to import contacts.'),
		);
		return $responseData;
	}

	public function getWidgetDisplay($id) {
		$gcs    = $this->UCP->FreePBX->Googlecontactsync;
		$status = $gcs->getConnectionStatus($this->userId);

		$authUrl   = '';
		$authError = '';
		// Build the consent URL whenever credentials are configured — it drives
		// both the initial "Connect" button and the "Reconnect" button shown to
		// an already-connected account whose Google access was revoked/expired.
		if ($status['credentialsConfigured']) {
			try {
				$authUrl = $gcs->buildAuthUrl($this->userId);
			} catch (\Exception $e) {
				$authError = $e->getMessage();
			}
		}

		$displayvars = array(
			'status'          => $status,
			'authUrl'         => $authUrl,
			'authError'       => $authError,
			'disconnectToken' => $status['connected'] ? $gcs->getDisconnectToken($this->userId) : '',
			'saveToken'       => $status['connected'] ? $gcs->getActionToken($this->userId, 'savesettings') : '',
			'syncToken'       => $status['connected'] ? $gcs->getActionToken($this->userId, 'syncnow') : '',
			'fullSyncToken'   => $status['connected'] ? $gcs->getActionToken($this->userId, 'fullsync') : '',
			'groups'          => $status['connected'] ? $gcs->getAvailableGroups($this->userId) : array(),
			'globalFrequency' => $gcs->getGlobalFrequency(),
			'frequencies'     => \FreePBX\modules\Googlecontactsync::FREQUENCIES,
			'daysOfWeek'      => $gcs->getDaysOfWeek(),
			'message'         => isset($_REQUEST['googlecontactsyncmsg']) ? (string) $_REQUEST['googlecontactsyncmsg'] : '',
		);

		return array(
			'title' => _('Google Contact Sync'),
			'html'  => $this->load_view(__DIR__.'/views/widget.php', $displayvars),
		);
	}
}
