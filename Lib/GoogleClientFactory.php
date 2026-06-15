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

use Google\Client;

/**
 * Builds a configured {@see \Google\Client} for the OAuth authorization-code
 * flow used by Google Contact Sync.
 *
 * The client requests the least-privilege read-only Contacts scope and the
 * `offline` access type with a forced consent prompt so Google issues a
 * refresh token for unattended, scheduled syncs.
 */
class GoogleClientFactory {

	/** Least-privilege People API scope (read-only contacts). */
	const SCOPE = 'https://www.googleapis.com/auth/contacts.readonly';

	/**
	 * Identity scopes. Required so Google issues an `id_token` (carrying the
	 * stable account `sub` and `email`) alongside the access token, which we
	 * verify to identify the connected Google account.
	 */
	const SCOPES_IDENTITY = array('openid', 'email');

	/** @var string */
	private $clientId;

	/** @var string */
	private $clientSecret;

	/** @var string */
	private $redirectUri;

	/**
	 * @param string $clientId
	 * @param string $clientSecret
	 * @param string $redirectUri
	 */
	public function __construct($clientId, $clientSecret, $redirectUri) {
		$this->clientId     = (string) $clientId;
		$this->clientSecret = (string) $clientSecret;
		$this->redirectUri  = (string) $redirectUri;
	}

	/**
	 * @return Client A client configured for the authorization-code flow.
	 */
	public function createClient() {
		$client = new Client();
		$client->setApplicationName('FreePBX Google Contact Sync');
		$client->setClientId($this->clientId);
		$client->setClientSecret($this->clientSecret);
		$client->setRedirectUri($this->redirectUri);
		$client->setScopes(array_merge(array(self::SCOPE), self::SCOPES_IDENTITY));
		$client->setAccessType('offline');
		$client->setPrompt('consent');
		$client->setIncludeGrantedScopes(true);
		return $client;
	}
}
