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

// Minimal gettext shim so the module file can load under CLI without the
// gettext extension.
if (!function_exists('_')) {
	function _($s) { return $s; }
}

// Stand-ins for the FreePBX base types the main class extends/implements so the
// class file can be loaded without the full framework present.
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

/** Fake Userman that resolves a predictable display name. */
class GcsAdminFakeUserman {
	public function getUserByID($id, $extraInfo = true) {
		return array('id' => (int) $id, 'username' => 'user'.(int) $id, 'displayname' => 'User '.(int) $id);
	}
}

/** Fake Contact Manager exposing only the group lookup the tab needs. */
class GcsAdminFakeContactmanager {
	public function getGroupByID($id) {
		return array('id' => (int) $id, 'name' => 'Group '.(int) $id, 'type' => 'private', 'owner' => 42);
	}
}

/** Fake FreePBX container holding the collaborators the main class reaches for. */
#[\AllowDynamicProperties]
class GcsAdminFakeFreepbx {
	public $Userman;
	public $Contactmanager;
	public $Database;
}

/**
 * Covers the M7 admin Users + Logs data helpers and the admin AJAX handler:
 * log filtering/pagination, old-log purging, the per-user status rows, and the
 * syncnow/disconnect/clearlogs command routing.
 *
 * @covers \FreePBX\modules\Googlecontactsync\Googlecontactsync
 */
class AdminUsersLogsTest extends TestCase {

	/** @var \PDO */
	private $db;

	/** @var \FreePBX\modules\Googlecontactsync */
	private $gcs;

	protected function setUp(): void {
		$_REQUEST = array();
		$_POST    = array();

		$this->db = new \PDO('sqlite::memory:');
		$this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$this->db->exec(
			'CREATE TABLE googlecontactsync_accounts ('
			.' id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, google_email TEXT,'
			.' target_groupid INTEGER, target_group_type TEXT, frequency TEXT,'
			.' freq_time TEXT, freq_dow INTEGER, enabled INTEGER DEFAULT 1,'
			.' last_sync INTEGER, last_status TEXT, last_message TEXT,'
			.' access_token TEXT, refresh_token TEXT)'
		);
		$this->db->exec(
			'CREATE TABLE googlecontactsync_contacts ('
			.' id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, resource_name TEXT)'
		);
		$this->db->exec(
			'CREATE TABLE googlecontactsync_logs ('
			.' id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, uid INTEGER,'
			.' started INTEGER, finished INTEGER, status TEXT, added INTEGER,'
			.' updated INTEGER, deleted INTEGER, message TEXT)'
		);

		$freepbx = new GcsAdminFakeFreepbx();
		$freepbx->Userman        = new GcsAdminFakeUserman();
		$freepbx->Contactmanager = new GcsAdminFakeContactmanager();
		$freepbx->Database       = $this->db;

		// Build without the framework-bound constructor, then inject the DB and
		// fake FreePBX container into the private properties.
		$ref = new \ReflectionClass(\FreePBX\modules\Googlecontactsync::class);
		$this->gcs = $ref->newInstanceWithoutConstructor();
		$this->setPrivate('db', $this->db);
		$this->setPrivate('freepbx', $freepbx);
	}

	protected function tearDown(): void {
		$_REQUEST = array();
		$_POST    = array();
	}

	private function setPrivate(string $name, $value): void {
		$prop = new \ReflectionProperty(\FreePBX\modules\Googlecontactsync::class, $name);
		$prop->setAccessible(true);
		$prop->setValue($this->gcs, $value);
	}

	private function seedAccount(int $uid, array $overrides = array()): void {
		$row = array_merge(array(
			'google_email'   => 'u'.$uid.'@example.com',
			'target_groupid' => 7,
			'frequency'      => null,
			'freq_time'      => null,
			'freq_dow'       => null,
			'enabled'        => 1,
			'last_sync'      => 1700000000,
			'last_status'    => 'ok',
			'last_message'   => '',
		), $overrides);
		$sth = $this->db->prepare(
			'INSERT INTO googlecontactsync_accounts'
			.' (uid, google_email, target_groupid, frequency, freq_time, freq_dow, enabled, last_sync, last_status, last_message)'
			.' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
		);
		$sth->execute(array(
			$uid, $row['google_email'], $row['target_groupid'], $row['frequency'], $row['freq_time'],
			$row['freq_dow'], $row['enabled'], $row['last_sync'], $row['last_status'], $row['last_message'],
		));
	}

	private function seedLog(int $uid, string $status, int $finished, array $counts = array(), string $message = ''): void {
		$c = array_merge(array('added' => 0, 'updated' => 0, 'deleted' => 0), $counts);
		$sth = $this->db->prepare(
			'INSERT INTO googlecontactsync_logs (account_id, uid, started, finished, status, added, updated, deleted, message)'
			.' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
		);
		$sth->execute(array(1, $uid, $finished - 5, $finished, $status, $c['added'], $c['updated'], $c['deleted'], $message));
	}

	public function testCountLogsRespectsFilters(): void {
		$this->seedLog(10, 'ok', 1000);
		$this->seedLog(10, 'error', 1100);
		$this->seedLog(20, 'ok', 1200);

		$this->assertSame(3, $this->gcs->countLogs());
		$this->assertSame(2, $this->gcs->countLogs(10));
		$this->assertSame(2, $this->gcs->countLogs(null, 'ok'));
		$this->assertSame(1, $this->gcs->countLogs(10, 'error'));
	}

	public function testGetLogsTabDataOrdersNewestFirstAndPaginates(): void {
		for ($i = 1; $i <= 5; $i++) {
			$this->seedLog(10, 'ok', 1000 + $i);
		}

		$firstPage = $this->gcs->getLogsTabData(null, null, 2, 0);
		$this->assertCount(2, $firstPage);
		// Newest (highest finished) first.
		$this->assertSame(1005, $firstPage[0]['finished']);
		$this->assertSame(1004, $firstPage[1]['finished']);
		$this->assertSame('User 10', $firstPage[0]['user']);

		$secondPage = $this->gcs->getLogsTabData(null, null, 2, 2);
		$this->assertCount(2, $secondPage);
		$this->assertSame(1003, $secondPage[0]['finished']);
	}

	public function testGetLogsTabDataFiltersByUserAndStatus(): void {
		$this->seedLog(10, 'ok', 1000, array('added' => 3));
		$this->seedLog(10, 'error', 1100);
		$this->seedLog(20, 'ok', 1200);

		$rows = $this->gcs->getLogsTabData(10, 'ok', 25, 0);
		$this->assertCount(1, $rows);
		$this->assertSame(10, $rows[0]['uid']);
		$this->assertSame('ok', $rows[0]['status']);
		$this->assertSame(3, $rows[0]['added']);
	}

	public function testClearOldLogsRemovesOnlyOldEntries(): void {
		$now = time();
		$this->seedLog(10, 'ok', $now - (40 * 86400)); // old
		$this->seedLog(10, 'ok', $now - (10 * 86400)); // recent

		$removed = $this->gcs->clearOldLogs(30);
		$this->assertSame(1, $removed);
		$this->assertSame(1, $this->gcs->countLogs());
	}

	public function testClearOldLogsWithZeroClearsEverything(): void {
		$this->seedLog(10, 'ok', time());
		$this->seedLog(20, 'error', time());

		$removed = $this->gcs->clearOldLogs(0);
		$this->assertSame(2, $removed);
		$this->assertSame(0, $this->gcs->countLogs());
	}

	public function testGetUsersTabDataBuildsRows(): void {
		$this->seedAccount(10); // no override → default frequency
		$this->seedAccount(20, array('frequency' => 'hourly', 'last_status' => 'error', 'last_message' => 'boom'));

		$rows = $this->gcs->getUsersTabData();
		$this->assertCount(2, $rows);

		$this->assertSame('User 10', $rows[0]['user']);
		$this->assertSame('Group 7', $rows[0]['group']);
		$this->assertFalse($rows[0]['frequencyOverride']);

		$this->assertTrue($rows[1]['frequencyOverride']);
		$this->assertSame('Hourly', $rows[1]['frequency']);
		$this->assertSame('error', $rows[1]['status']);
	}

	public function testLogUserFilterOptionsAreDistinct(): void {
		$this->seedLog(10, 'ok', 1000);
		$this->seedLog(10, 'ok', 1100);
		$this->seedLog(20, 'error', 1200);

		$options = $this->gcs->getLogUserFilterOptions();
		$this->assertSame(array(10 => 'User 10', 20 => 'User 20'), $options);
	}

	public function testAjaxHandlerClearLogs(): void {
		$this->seedLog(10, 'ok', time());
		$this->seedLog(20, 'ok', time());

		$_REQUEST['command'] = 'clearlogs';
		$_POST['days']       = 0;

		$res = $this->gcs->ajaxHandler();
		$this->assertTrue($res['status']);
		$this->assertSame(2, $res['removed']);
		$this->assertSame(0, $this->gcs->countLogs());
	}

	public function testAjaxHandlerSyncNowRejectsUnknownAccount(): void {
		$_REQUEST['command'] = 'syncnow';
		$_POST['uid']        = 999;

		$res = $this->gcs->ajaxHandler();
		$this->assertFalse($res['status']);
	}

	public function testAjaxHandlerUnknownCommand(): void {
		$_REQUEST['command'] = 'bogus';
		$res = $this->gcs->ajaxHandler();
		$this->assertFalse($res['status']);
	}
}

}
