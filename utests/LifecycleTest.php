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
use FreePBX\modules\Googlecontactsync\Lib\TokenStore;

if (!function_exists('_')) {
	function _($s) { return $s; }
}
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

/** Cron BMO double: records lines so cron add/remove can be asserted. */
class GcsLifeFakeCron {
	public $lines = array();
	public function getAll() { return $this->lines; }
	public function addLine($line) { $this->lines[] = (string) $line; return true; }
	public function remove($line) {
		$this->lines = array_values(array_filter($this->lines, function ($l) use ($line) {
			return $l !== $line;
		}));
		return true;
	}
}

/** FreePBX Config double returning canned values. */
class GcsLifeFakeConfig {
	public $vals;
	public function __construct(array $vals) { $this->vals = $vals; }
	public function get($key) { return isset($this->vals[$key]) ? $this->vals[$key] : ''; }
}

/** Contact Manager double recording entry deletions and file regenerations. */
class GcsLifeFakeContactmanager {
	public $deleted     = array();
	public $regenerated = array();
	public function deleteEntryByID($id, $updateContactFile = true) {
		$this->deleted[] = (int) $id;
		return array('status' => true);
	}
	public function getGroupByID($id) {
		return array('id' => (int) $id, 'name' => 'Group '.(int) $id, 'type' => 'external', 'owner' => -1);
	}
	public function updateContactUpdatedDetails($owner, array $groupIds) {
		foreach ($groupIds as $g) { $this->regenerated[] = (int) $g; }
	}
}

/** FreePBX container double exposing the collaborators lifecycle code reaches for. */
#[\AllowDynamicProperties]
class GcsLifeFakeFreepbx {
	public $Userman;
	public $Contactmanager;
	public $Database;
	public $Config;
	public $cron;
	public function Cron($user) { return $this->cron; }
}

/**
 * Covers the M8 lifecycle behaviour: usermanDelUser/ucpDelUser purge + token
 * revoke wiring, imported-entry removal via the Contact Manager API, and
 * uninstall() cron removal + key-file deletion.
 *
 * @covers \FreePBX\modules\Googlecontactsync\Googlecontactsync
 */
class LifecycleTest extends TestCase {

	/** @var \PDO */
	private $db;
	/** @var \FreePBX\modules\Googlecontactsync */
	private $gcs;
	/** @var GcsLifeFakeContactmanager */
	private $cm;
	/** @var GcsLifeFakeCron */
	private $cron;
	/** @var string */
	private $etcDir;

	protected function setUp(): void {
		$this->db = new \PDO('sqlite::memory:');
		$this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$this->db->exec(
			'CREATE TABLE googlecontactsync_accounts ('
			.' id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, google_email TEXT,'
			.' access_token TEXT, refresh_token TEXT, enabled INTEGER DEFAULT 1)'
		);
		$this->db->exec(
			'CREATE TABLE googlecontactsync_contacts ('
			.' id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, resource_name TEXT,'
			.' entryid INTEGER, groupid INTEGER)'
		);
		$this->db->exec(
			'CREATE TABLE googlecontactsync_logs ('
			.' id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, uid INTEGER,'
			.' started INTEGER, finished INTEGER, status TEXT, message TEXT)'
		);

		$this->etcDir = sys_get_temp_dir().'/gcs_life_'.bin2hex(random_bytes(4));
		mkdir($this->etcDir, 0700, true);

		$this->cm   = new GcsLifeFakeContactmanager();
		$this->cron = new GcsLifeFakeCron();

		$freepbx                 = new GcsLifeFakeFreepbx();
		$freepbx->Contactmanager = $this->cm;
		$freepbx->Database       = $this->db;
		$freepbx->cron           = $this->cron;
		$freepbx->Config         = new GcsLifeFakeConfig(array(
			'AMPASTERISKWEBUSER' => 'asterisk',
			'AMPSBIN'            => '/usr/sbin',
			'ASTETCDIR'          => $this->etcDir,
		));

		$ref = new \ReflectionClass(\FreePBX\modules\Googlecontactsync::class);
		$this->gcs = $ref->newInstanceWithoutConstructor();
		$this->setPrivate('db', $this->db);
		$this->setPrivate('freepbx', $freepbx);
		// Inject a deterministic, file-less TokenStore so revoke logic never
		// touches the real key file during the delete/uninstall paths.
		$this->setPrivate('tokenStore', new TokenStore(null, str_repeat('k', 32)));
	}

	protected function tearDown(): void {
		$keyFile = $this->etcDir.'/googlecontactsync.key';
		if (is_file($keyFile)) { @unlink($keyFile); }
		if (is_dir($this->etcDir)) { @rmdir($this->etcDir); }
	}

	private function setPrivate(string $name, $value): void {
		$prop = new \ReflectionProperty(\FreePBX\modules\Googlecontactsync::class, $name);
		$prop->setAccessible(true);
		$prop->setValue($this->gcs, $value);
	}

	private function seedAccount(int $uid): int {
		$sth = $this->db->prepare(
			'INSERT INTO googlecontactsync_accounts (uid, google_email, enabled) VALUES (?, ?, 1)'
		);
		$sth->execute(array($uid, 'u'.$uid.'@example.com'));
		return (int) $this->db->lastInsertId();
	}

	private function seedContact(int $accountId, string $resource, int $entryid, int $groupid): void {
		$sth = $this->db->prepare(
			'INSERT INTO googlecontactsync_contacts (account_id, resource_name, entryid, groupid)'
			.' VALUES (?, ?, ?, ?)'
		);
		$sth->execute(array($accountId, $resource, $entryid, $groupid));
	}

	private function seedLog(int $accountId, int $uid): void {
		$sth = $this->db->prepare(
			'INSERT INTO googlecontactsync_logs (account_id, uid, started, finished, status, message)'
			.' VALUES (?, ?, 1, 2, ?, ?)'
		);
		$sth->execute(array($accountId, $uid, 'ok', ''));
	}

	private function rowCount(string $table, string $where, array $params): int {
		$sth = $this->db->prepare('SELECT COUNT(*) FROM '.$table.' WHERE '.$where);
		$sth->execute($params);
		return (int) $sth->fetchColumn();
	}

	public function testUsermanDelUserPurgesAccountMappingsAndLogs(): void {
		$accountId = $this->seedAccount(10);
		$this->seedContact($accountId, 'people/c1', 101, 7);
		$this->seedContact($accountId, 'people/c2', 102, 7);
		$this->seedLog($accountId, 10);
		// A second user's data must be left untouched.
		$other = $this->seedAccount(20);
		$this->seedContact($other, 'people/c9', 201, 8);
		$this->seedLog($other, 20);

		$this->gcs->usermanDelUser(10, 'User 10', array());

		$this->assertSame(0, $this->rowCount('googlecontactsync_accounts', 'uid = ?', array(10)));
		$this->assertSame(0, $this->rowCount('googlecontactsync_contacts', 'account_id = ?', array($accountId)));
		$this->assertSame(0, $this->rowCount('googlecontactsync_logs', 'account_id = ?', array($accountId)));
		// Imported entries removed via the CM public API.
		$this->assertEqualsCanonicalizing(array(101, 102), $this->cm->deleted);
		$this->assertSame(array(7), $this->cm->regenerated);

		// Untouched user survives.
		$this->assertSame(1, $this->rowCount('googlecontactsync_accounts', 'uid = ?', array(20)));
		$this->assertSame(1, $this->rowCount('googlecontactsync_contacts', 'account_id = ?', array($other)));
	}

	public function testUcpDelUserPurgesSameAsUserman(): void {
		$accountId = $this->seedAccount(10);
		$this->seedContact($accountId, 'people/c1', 111, 5);

		$this->gcs->ucpDelUser(10, 'User 10', array());

		$this->assertSame(0, $this->rowCount('googlecontactsync_accounts', 'uid = ?', array(10)));
		$this->assertSame(array(111), $this->cm->deleted);
		$this->assertSame(array(5), $this->cm->regenerated);
	}

	public function testDelUserForUnknownUserIsNoop(): void {
		$this->gcs->usermanDelUser(999, 'Ghost', array());
		$this->assertSame(array(), $this->cm->deleted);
		$this->assertSame(0, $this->rowCount('googlecontactsync_accounts', '1 = ?', array(1)));
	}

	public function testDelUserSkipsMappingsWithoutEntryId(): void {
		$accountId = $this->seedAccount(10);
		// A mapping that never produced a CM entry (entryid NULL) must be ignored
		// by the importer-entry removal but still deleted from the mapping table.
		$sth = $this->db->prepare(
			'INSERT INTO googlecontactsync_contacts (account_id, resource_name, entryid, groupid)'
			.' VALUES (?, ?, NULL, NULL)'
		);
		$sth->execute(array($accountId, 'people/c0'));

		$this->gcs->usermanDelUser(10, 'User 10', array());

		$this->assertSame(array(), $this->cm->deleted);
		$this->assertSame(0, $this->rowCount('googlecontactsync_contacts', 'account_id = ?', array($accountId)));
	}

	public function testUninstallRemovesCronAndDeletesKeyFile(): void {
		// A stale cron line plus an unrelated one: only ours must be removed.
		$this->cron->lines = array(
			'*/15 * * * * [ -e /usr/sbin/fwconsole ] && '
				.'sleep $((RANDOM\%30)) && /usr/sbin/fwconsole googlecontactsync --runsync -q',
			'0 1 * * * /usr/sbin/fwconsole somethingelse',
		);
		$keyFile = $this->etcDir.'/googlecontactsync.key';
		file_put_contents($keyFile, str_repeat('s', 32));
		chmod($keyFile, 0600);
		$this->seedAccount(10);

		$this->gcs->uninstall();

		$this->assertCount(1, $this->cron->lines);
		$this->assertStringContainsString('somethingelse', $this->cron->lines[0]);
		$this->assertFileDoesNotExist($keyFile);
	}
}

}
