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

namespace {

use PHPUnit\Framework\TestCase;

// Minimal gettext shim so the module file can load under CLI without gettext.
if (!function_exists('_')) {
	function _($s) { return $s; }
}

// Stand-ins for the FreePBX base types the main class extends/implements.
if (!interface_exists('BMO')) {
	interface BMO {}
}
if (!class_exists('FreePBX_Helpers')) {
	#[\AllowDynamicProperties]
	class FreePBX_Helpers {
		/** @var array<string,mixed> In-memory KV store so tests exercise real persistence. */
		private $kvStore = array();
		private function kvKey($key, $id) { return ($id === null ? '' : ((string) $id).'|').(string) $key; }
		public function getConfig($key, $id = null) {
			$k = $this->kvKey($key, $id);
			return array_key_exists($k, $this->kvStore) ? $this->kvStore[$k] : '';
		}
		public function setConfig($key, $value = false, $id = null) {
			$k = $this->kvKey($key, $id);
			if ($value === false) { unset($this->kvStore[$k]); } else { $this->kvStore[$k] = $value; }
			return true;
		}
		public function delConfig($key, $id = null) { return $this->setConfig($key, false, $id); }
	}
}

require_once __DIR__.'/../Googlecontactsync.class.php';

/** Userman double that resolves a predictable display name. */
class GcsSecFakeUserman {
	public function getUserByID($id, $extraInfo = true) {
		return array('id' => (int) $id, 'username' => 'user'.(int) $id, 'displayname' => 'User '.(int) $id);
	}
}

/**
 * Contact Manager double whose group ownership is configurable, so the
 * server-side import-target allow-list (IDOR protection) can be exercised.
 */
#[\AllowDynamicProperties]
class GcsSecFakeContactmanager {
	/** @var array<int,array<string,mixed>> Groups returned by getGroupsbyOwner(). */
	public $ownedGroups = array();

	public function getGroupsbyOwner($owner) {
		return $this->ownedGroups;
	}

	public function getGroupByID($id) {
		foreach ($this->ownedGroups as $g) {
			if ((int) $g['id'] === (int) $id) {
				return $g;
			}
		}
		return array();
	}
}

/** Config double returning a per-test temp directory for the key file. */
#[\AllowDynamicProperties]
class GcsSecFakeConfig {
	/** @var array<string,string> */
	public $values = array();
	public function get($key) {
		return isset($this->values[$key]) ? $this->values[$key] : '';
	}
}

/** FreePBX container holding the collaborators the main class reaches for. */
#[\AllowDynamicProperties]
class GcsSecFakeFreepbx {
	public $Userman;
	public $Contactmanager;
	public $Config;
	public $Database;
}

/**
 * Security hardening (spec §9 / §15) for the OAuth state machine and the
 * server-side authorization checks of the main BMO class:
 *  - signed, single-use, time-bound `state` (CSRF + replay protection),
 *  - id-token/session uid binding in the callback (IDOR protection),
 *  - import-target group ownership validation (IDOR protection),
 *  - HTTPS and credential preconditions before starting OAuth.
 *
 * @covers \FreePBX\modules\Googlecontactsync\Googlecontactsync
 */
class OAuthSecurityTest extends TestCase {

	/** @var \PDO */
	private $db;

	/** @var \FreePBX\modules\Googlecontactsync */
	private $gcs;

	/** @var GcsSecFakeContactmanager */
	private $cm;

	/** @var string Temp dir holding the encryption key file for this test. */
	private $etcDir;

	protected function setUp(): void {
		// Ensure isHttpsRequest() sees a non-HTTPS request unless a test opts in.
		unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_X_FORWARDED_SSL']);
		$_SERVER['SERVER_PORT'] = '80';

		$this->db = new \PDO('sqlite::memory:');
		$this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$this->db->exec(
			'CREATE TABLE googlecontactsync_accounts ('
			.' id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, target_groupid INTEGER,'
			.' target_group_type TEXT, updated INTEGER)'
		);

		$this->etcDir = sys_get_temp_dir().'/gcs_oauthsec_'.bin2hex(random_bytes(6));
		@mkdir($this->etcDir, 0700, true);

		$config = new GcsSecFakeConfig();
		$config->values['ASTETCDIR'] = $this->etcDir;

		$this->cm = new GcsSecFakeContactmanager();

		$freepbx = new GcsSecFakeFreepbx();
		$freepbx->Userman        = new GcsSecFakeUserman();
		$freepbx->Contactmanager = $this->cm;
		$freepbx->Config         = $config;
		$freepbx->Database       = $this->db;

		$ref = new \ReflectionClass(\FreePBX\modules\Googlecontactsync::class);
		$this->gcs = $ref->newInstanceWithoutConstructor();
		$this->setPrivate('db', $this->db);
		$this->setPrivate('freepbx', $freepbx);
	}

	protected function tearDown(): void {
		$key = $this->etcDir.'/googlecontactsync.key';
		if (is_file($key)) {
			@unlink($key);
		}
		if (is_dir($this->etcDir)) {
			@rmdir($this->etcDir);
		}
		unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_X_FORWARDED_SSL'], $_SERVER['SERVER_PORT']);
	}

	private function setPrivate(string $name, $value): void {
		$prop = new \ReflectionProperty(\FreePBX\modules\Googlecontactsync::class, $name);
		$prop->setAccessible(true);
		$prop->setValue($this->gcs, $value);
	}

	/** Invoke a private/protected method on the BMO under test. */
	private function callPrivate(string $method, array $args = []) {
		$m = new \ReflectionMethod(\FreePBX\modules\Googlecontactsync::class, $method);
		$m->setAccessible(true);
		return $m->invokeArgs($this->gcs, $args);
	}

	private function seedAccount(int $uid): void {
		$sth = $this->db->prepare('INSERT INTO googlecontactsync_accounts (uid, updated) VALUES (?, ?)');
		$sth->execute(array($uid, time()));
	}

	// ---- OAuth state: signing, verification, replay/CSRF -------------------- //

	public function testSignedStateRoundTripYieldsUid(): void {
		$state = $this->callPrivate('signState', array(7));
		$this->assertIsString($state);
		$this->assertStringContainsString('.', $state);
		$this->assertSame(7, $this->callPrivate('verifyState', array($state)));
	}

	public function testTamperedStateSignatureIsRejected(): void {
		$state = $this->callPrivate('signState', array(7));
		// Flip the final signature character so the HMAC no longer matches.
		$last    = substr($state, -1);
		$swapped = $last === 'a' ? 'b' : 'a';
		$tampered = substr($state, 0, -1).$swapped;

		$this->expectException(\Exception::class);
		$this->callPrivate('verifyState', array($tampered));
	}

	public function testTamperedStatePayloadIsRejected(): void {
		// A forged payload (different uid) signed with the wrong key must fail.
		$payload = json_encode(array('u' => 999, 'n' => bin2hex(random_bytes(16)), 'e' => time() + 600));
		$data    = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
		$forged  = $data.'.'.hash_hmac('sha256', $data, 'not-the-real-key');

		$this->expectException(\Exception::class);
		$this->callPrivate('verifyState', array($forged));
	}

	public function testExpiredStateIsRejected(): void {
		$key   = (string) $this->callPrivate('getStateKey', array());
		$uid   = 7;
		$nonce = bin2hex(random_bytes(16));
		// Validly-signed but already-expired state (expiry checked before nonce).
		$payload = json_encode(array('u' => $uid, 'n' => $nonce, 'e' => time() - 10));
		$data    = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
		$state   = $data.'.'.hash_hmac('sha256', $data, $key);

		$this->expectException(\Exception::class);
		$this->callPrivate('verifyState', array($state));
	}

	public function testStateNonceIsSingleUse(): void {
		$state = $this->callPrivate('signState', array(7));
		$this->assertSame(7, $this->callPrivate('verifyState', array($state)));

		// Replay of the same state must be rejected (nonce consumed).
		$this->expectException(\Exception::class);
		$this->callPrivate('verifyState', array($state));
	}

	// ---- OAuth callback: session/uid binding (IDOR) ------------------------ //

	public function testOAuthCallbackRejectsSessionUidMismatch(): void {
		$state = $this->callPrivate('signState', array(5));

		// The state is for uid 5 but the authenticated UCP session is uid 9:
		// the callback must refuse before exchanging the code with Google.
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('OAuth session mismatch');
		$this->gcs->handleOAuthCallback('an-auth-code', $state, 9);
	}

	// ---- Import-target group ownership (IDOR) ------------------------------ //

	public function testSetAccountTargetRejectsGroupUserDoesNotOwn(): void {
		$uid = 7;
		$this->seedAccount($uid);
		// The user only owns group 11; group 99 belongs to someone else.
		$this->cm->ownedGroups = array(
			array('id' => 11, 'name' => 'Mine', 'type' => 'private', 'owner' => $uid),
		);

		$this->assertFalse($this->gcs->setAccountTarget($uid, 99, 'private'));

		$sth = $this->db->prepare('SELECT target_groupid FROM googlecontactsync_accounts WHERE uid = ?');
		$sth->execute(array($uid));
		$this->assertNull($sth->fetchColumn() ?: null, 'A rejected target must not be persisted.');
	}

	public function testSetAccountTargetAcceptsOwnedGroupAndIgnoresClientType(): void {
		$uid = 7;
		$this->seedAccount($uid);
		$this->cm->ownedGroups = array(
			array('id' => 11, 'name' => 'Mine', 'type' => 'private', 'owner' => $uid),
		);

		// Even if the client claims 'external', the authoritative type is taken
		// from Contact Manager server-side.
		$this->assertTrue($this->gcs->setAccountTarget($uid, 11, 'external'));

		$sth = $this->db->prepare('SELECT target_groupid, target_group_type FROM googlecontactsync_accounts WHERE uid = ?');
		$sth->execute(array($uid));
		$row = $sth->fetch(\PDO::FETCH_ASSOC);
		$this->assertSame(11, (int) $row['target_groupid']);
		$this->assertSame('private', $row['target_group_type']);
	}

	public function testSetAccountTargetRejectsUnknownUser(): void {
		// No account row for uid 7 → nothing to target.
		$this->cm->ownedGroups = array(
			array('id' => 11, 'name' => 'Mine', 'type' => 'private', 'owner' => 7),
		);
		$this->assertFalse($this->gcs->setAccountTarget(7, 11, 'private'));
	}

	public function testAvailableGroupsExcludeInternalGroups(): void {
		$this->cm->ownedGroups = array(
			array('id' => 1, 'name' => 'Extensions', 'type' => 'internal', 'owner' => -2),
			array('id' => 11, 'name' => 'Mine', 'type' => 'private', 'owner' => 7),
			array('id' => 12, 'name' => 'Shared', 'type' => 'external', 'owner' => -1),
		);
		$ids = array_map(static function ($g) { return $g['id']; }, $this->gcs->getAvailableGroups(7));
		$this->assertContains(11, $ids);
		$this->assertContains(12, $ids);
		$this->assertNotContains(1, $ids, 'Internal groups are never valid import targets.');
	}

	// ---- OAuth preconditions: credentials + HTTPS -------------------------- //

	public function testBuildAuthUrlFailsWithoutCredentials(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('not configured');
		$this->gcs->buildAuthUrl(7);
	}

	public function testBuildAuthUrlRequiresHttpsWhenCredentialsConfigured(): void {
		$this->gcs->setCredentials('client-id.apps.googleusercontent.com', 'a-secret');
		$this->assertTrue($this->gcs->hasClientSecret());

		// Request is plain HTTP (set up in setUp) → OAuth must refuse to start.
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('HTTPS');
		$this->gcs->buildAuthUrl(7);
	}

	public function testClientSecretIsStoredEncryptedNotPlaintext(): void {
		$this->gcs->setCredentials('client-id', 'super-secret-value');

		// The encrypted-at-rest value must never be the plaintext secret.
		$stored = $this->callPrivate('getConfig', array(\FreePBX\modules\Googlecontactsync::KEY_CLIENT_SECRET));
		$this->assertNotSame('super-secret-value', $stored);
		$this->assertStringNotContainsString('super-secret-value', (string) $stored);
		// …but it round-trips back to the plaintext for server-side OAuth use.
		$this->assertSame('super-secret-value', $this->gcs->getClientSecret());
	}
}

} // namespace
