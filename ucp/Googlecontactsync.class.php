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
	 * Redirect back to a clean UCP URL (drops the OAuth query parameters so a
	 * reload cannot replay the code/state).
	 *
	 * @param array<string,string> $params
	 */
	private function redirectClean($params) {
		$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$host  = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
		$url   = $proto.'://'.$host.'/ucp/index.php';
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
		if (!$status['connected'] && $status['credentialsConfigured']) {
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
			'message'         => isset($_REQUEST['googlecontactsyncmsg']) ? (string) $_REQUEST['googlecontactsyncmsg'] : '',
		);

		return array(
			'title' => _('Google Contact Sync'),
			'html'  => $this->load_view(__DIR__.'/views/widget.php', $displayvars),
		);
	}
}
