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

use PHPUnit\Framework\TestCase;
use FreePBX\modules\Googlecontactsync\Lib\PeopleSync;
use FreePBX\modules\Googlecontactsync\Lib\TokenStore;
use Google\Client;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\PeopleService;
use Google\Service\PeopleService\ListConnectionsResponse;
use Google\Service\PeopleService\Person;

/**
 * M4 sync-engine behaviour: incremental syncs, tombstone deletions,
 * EXPIRED_SYNC_TOKEN recovery, and full-sync deletion reconciliation.
 *
 * @covers \FreePBX\modules\Googlecontactsync\Lib\PeopleSync
 */
class PeopleSyncTest extends TestCase {

	/** @var \PDO */
	private $db;

	/** @var GcsFakeContactmanager */
	private $cm;

	/** @var TokenStore */
	private $store;

	/** @var string[] Temp files removed in tearDown. */
	private $tmpFiles = array();

	/** Group used as the import target. */
	private const GROUP = array('id' => 5, 'owner' => 42, 'type' => 'private', 'name' => 'Google Contacts');

	protected function setUp(): void {
		$this->db = new \PDO('sqlite::memory:');
		$this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$this->db->exec(
			'CREATE TABLE googlecontactsync_accounts ('
			.' id INTEGER PRIMARY KEY AUTOINCREMENT, uid INTEGER, sync_token TEXT,'
			.' target_groupid INTEGER, target_group_type TEXT, access_token TEXT,'
			.' refresh_token TEXT, token_expires INTEGER, last_sync INTEGER,'
			.' last_status TEXT, last_message TEXT, updated INTEGER)'
		);
		$this->db->exec(
			'CREATE TABLE googlecontactsync_contacts ('
			.' id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER,'
			.' resource_name TEXT, etag TEXT, entryid INTEGER, groupid INTEGER,'
			.' last_synced INTEGER)'
		);
		$this->db->exec(
			'CREATE TABLE googlecontactsync_logs ('
			.' id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER, uid INTEGER,'
			.' started INTEGER, finished INTEGER, status TEXT, added INTEGER,'
			.' updated INTEGER, deleted INTEGER, message TEXT)'
		);

		$this->cm    = new GcsFakeContactmanager(self::GROUP);
		$this->store = new TokenStore($this->tmpKeyPath());
	}

	protected function tearDown(): void {
		foreach ($this->tmpFiles as $f) {
			if (is_file($f)) {
				@unlink($f);
			}
		}
		$this->tmpFiles = array();
	}

	private function tmpKeyPath(): string {
		$path = sys_get_temp_dir().'/gcs_peoplesync_'.bin2hex(random_bytes(8)).'.key';
		$this->tmpFiles[] = $path;
		return $path;
	}

	/** Build a sync engine wired to the in-memory DB and the canned service. */
	private function engine(FakePeopleConnections $conns): PeopleSync {
		$service = new PeopleService(new Client());
		$service->people_connections = $conns;

		$sync = new PeopleSync(new \stdClass(), $this->cm, $this->db, $this->store, '');
		$sync->setPeopleService($service);
		return $sync;
	}

	/** Insert an account row and return its id. */
	private function seedAccount(string $syncToken = ''): int {
		$sth = $this->db->prepare(
			'INSERT INTO googlecontactsync_accounts (uid, sync_token, target_groupid, target_group_type)'
			.' VALUES (?, ?, ?, ?)'
		);
		$sth->execute(array(self::GROUP['owner'], $syncToken, self::GROUP['id'], 'private'));
		return (int) $this->db->lastInsertId();
	}

	/** Insert an account whose target group id points at a (since-deleted) group. */
	private function seedAccountWithTargetGroup(string $syncToken, int $targetGroupId): int {
		$sth = $this->db->prepare(
			'INSERT INTO googlecontactsync_accounts (uid, sync_token, target_groupid, target_group_type)'
			.' VALUES (?, ?, ?, ?)'
		);
		$sth->execute(array(self::GROUP['owner'], $syncToken, $targetGroupId, 'private'));
		return (int) $this->db->lastInsertId();
	}

	/** Insert a contact mapping row. */
	private function seedMapping(int $accountId, string $resourceName, string $etag, int $entryid): void {
		$sth = $this->db->prepare(
			'INSERT INTO googlecontactsync_contacts (account_id, resource_name, etag, entryid, groupid, last_synced)'
			.' VALUES (?, ?, ?, ?, ?, ?)'
		);
		$sth->execute(array($accountId, $resourceName, $etag, $entryid, self::GROUP['id'], time()));
	}

	private function account(int $id): array {
		$sth = $this->db->prepare('SELECT * FROM googlecontactsync_accounts WHERE id = ?');
		$sth->execute(array($id));
		return $sth->fetch(\PDO::FETCH_ASSOC);
	}

	private function mappingCount(int $accountId): int {
		$sth = $this->db->prepare('SELECT COUNT(*) FROM googlecontactsync_contacts WHERE account_id = ?');
		$sth->execute(array($accountId));
		return (int) $sth->fetchColumn();
	}

	private function mappingEtag(int $accountId, string $resourceName): ?string {
		$sth = $this->db->prepare('SELECT etag FROM googlecontactsync_contacts WHERE account_id = ? AND resource_name = ?');
		$sth->execute(array($accountId, $resourceName));
		$val = $sth->fetchColumn();
		return $val === false ? null : (string) $val;
	}

	private function person(string $resourceName, string $etag, string $name): Person {
		return new Person(array(
			'resourceName' => $resourceName,
			'etag'         => $etag,
			'names'        => array(array('displayName' => $name, 'givenName' => $name)),
		));
	}

	private function tombstone(string $resourceName): Person {
		return new Person(array(
			'resourceName' => $resourceName,
			'metadata'     => array('deleted' => true),
		));
	}

	private function response(array $persons, string $nextSync = '', string $nextPage = ''): ListConnectionsResponse {
		$r = new ListConnectionsResponse();
		$r->setConnections($persons);
		if ($nextSync !== '') {
			$r->setNextSyncToken($nextSync);
		}
		if ($nextPage !== '') {
			$r->setNextPageToken($nextPage);
		}
		return $r;
	}

	public function testFullInitialSyncAddsContactsAndStoresSyncToken(): void {
		$accountId = $this->seedAccount('');
		$conns = new FakePeopleConnections(array(
			$this->response(array(
				$this->person('people/c1', 'e1', 'Alice'),
				$this->person('people/c2', 'e2', 'Bob'),
			), 'TOKEN-1'),
		));

		$result = $this->engine($conns)->syncAccount($this->account($accountId));

		$this->assertTrue($result['status']);
		$this->assertSame(2, $result['added']);
		$this->assertSame(0, $result['deleted']);
		$this->assertSame(2, $this->mappingCount($accountId));
		$this->assertSame('TOKEN-1', $this->account($accountId)['sync_token']);
		// requestSyncToken must be set on the API call.
		$this->assertTrue($conns->calls[0]['requestSyncToken']);
		$this->assertArrayNotHasKey('syncToken', $conns->calls[0]);
	}

	public function testIncrementalSyncMirrorsTombstoneAndUpdate(): void {
		$accountId = $this->seedAccount('TOKEN-1');
		$this->seedMapping($accountId, 'people/c1', 'e1', 201);
		$this->seedMapping($accountId, 'people/c2', 'e2', 202);

		$conns = new FakePeopleConnections(array(
			$this->response(array(
				$this->tombstone('people/c1'),
				$this->person('people/c2', 'e2-new', 'Bob Updated'),
			), 'TOKEN-2'),
		));

		$result = $this->engine($conns)->syncAccount($this->account($accountId));

		$this->assertTrue($result['status']);
		$this->assertSame(1, $result['deleted']);
		$this->assertSame(1, $result['updated']);
		$this->assertSame(0, $result['added']);
		$this->assertContains(201, $this->cm->deleted, 'Tombstoned contact entry must be deleted');
		$this->assertArrayHasKey(202, $this->cm->updated, 'Changed contact entry must be updated');
		$this->assertSame(1, $this->mappingCount($accountId), 'Deleted mapping must be removed');
		$this->assertSame('e2-new', $this->mappingEtag($accountId, 'people/c2'));
		$this->assertSame('TOKEN-2', $this->account($accountId)['sync_token']);
		// Incremental call must carry the stored sync token.
		$this->assertSame('TOKEN-1', $conns->calls[0]['syncToken']);
	}

	public function testExpiredSyncTokenTriggersFullResyncAndReconcilesDeletions(): void {
		$accountId = $this->seedAccount('OLD-TOKEN');
		$this->seedMapping($accountId, 'people/c1', 'e1', 201);
		$this->seedMapping($accountId, 'people/c2', 'e2', 202);

		$expired = new GoogleServiceException(
			'Sync token is expired. EXPIRED_SYNC_TOKEN',
			400,
			null,
			array(array('reason' => 'EXPIRED_SYNC_TOKEN', 'message' => 'expired'))
		);
		// First (incremental) call throws; the full resync returns only c1.
		$conns = new FakePeopleConnections(array(
			$expired,
			$this->response(array($this->person('people/c1', 'e1', 'Alice')), 'TOKEN-3'),
		));

		$result = $this->engine($conns)->syncAccount($this->account($accountId));

		$this->assertTrue($result['status']);
		$this->assertSame(1, $result['deleted'], 'c2 missing from full set must be deleted');
		$this->assertContains(202, $this->cm->deleted);
		$this->assertNotContains(201, $this->cm->deleted, 'Still-present contact must survive');
		$this->assertSame(1, $this->mappingCount($accountId));
		$this->assertSame('TOKEN-3', $this->account($accountId)['sync_token']);
		// Second call is the full resync (no sync token).
		$this->assertArrayNotHasKey('syncToken', $conns->calls[1]);
	}

	public function testNonExpiredApiErrorIsNotSwallowed(): void {
		$accountId = $this->seedAccount('TOKEN-1');
		$serverError = new GoogleServiceException('Backend error', 500, null, array());
		$conns = new FakePeopleConnections(array($serverError));

		$result = $this->engine($conns)->syncAccount($this->account($accountId));

		$this->assertFalse($result['status']);
		$this->assertSame('error', $this->account($accountId)['last_status']);
		// A non-expired failure must not clobber the stored sync token.
		$this->assertSame('TOKEN-1', $this->account($accountId)['sync_token']);
	}

	public function testDeletedTargetGroupForcesFullResyncAndReimportsContacts(): void {
		// The user deleted the import group; its id is stored but no longer
		// resolvable, and a stored sync token would otherwise drive an
		// incremental (empty) run. The sync must recreate the group, drop the
		// stale mappings, and full-resync so every contact is re-imported.
		$accountId = $this->seedAccountWithTargetGroup('TOKEN-OLD', 999);
		$this->seedMapping($accountId, 'people/c1', 'e1', 201);
		$this->seedMapping($accountId, 'people/c2', 'e2', 202);

		$conns = new FakePeopleConnections(array(
			$this->response(array(
				$this->person('people/c1', 'e1', 'Alice'),
				$this->person('people/c2', 'e2', 'Bob'),
			), 'TOKEN-NEW'),
		));

		$result = $this->engine($conns)->syncAccount($this->account($accountId));

		$this->assertTrue($result['status']);
		$this->assertSame(2, $result['added'], 'Every contact must be re-imported into the new group');
		$this->assertSame(0, $result['updated']);
		$this->assertSame(0, $result['skipped'], 'Stale mappings must not cause unchanged-etag skips');
		$this->assertSame(2, $this->mappingCount($accountId), 'Mappings rebuilt against the new group');
		$this->assertSame('TOKEN-NEW', $this->account($accountId)['sync_token']);
		// The run must be a full sync (no stored sync token sent to the API).
		$this->assertArrayNotHasKey('syncToken', $conns->calls[0]);
	}
}

/**
 * Minimal Contact Manager double recording the mutating calls the sync makes.
 */
class GcsFakeContactmanager {
	public array $entries = array();
	public array $updated = array();
	public array $deleted = array();
	public int $nextId = 100;
	private array $group;

	public function __construct(array $group) {
		$this->group = $group;
	}

	public function getGroupByID($id) {
		return ((int) $id === (int) $this->group['id']) ? $this->group : array();
	}

	public function addGroup($name, $type = 'internal', $owner = -1, $updateContactFile = true) {
		return array('status' => true, 'id' => $this->group['id']);
	}

	public function addEntryByGroupID($groupid, $entry, $updateContactFile = true) {
		$id = $this->nextId++;
		$this->entries[$id] = $entry;
		return array('status' => true, 'id' => $id);
	}

	public function updateEntry($id, $entry, $updateContactFile = true) {
		$this->updated[(int) $id] = $entry;
		$this->entries[(int) $id] = $entry;
		return array('status' => true);
	}

	public function deleteEntryByID($id) {
		$this->deleted[] = (int) $id;
		unset($this->entries[(int) $id]);
		return array('status' => true);
	}

	public function updateContactUpdatedDetails($owner, $groups) {
		return true;
	}
}

/**
 * Stand-in for PeopleService::$people_connections returning queued responses
 * (or throwing queued exceptions) in order, recording the options of each call.
 */
class FakePeopleConnections {
	/** @var array<int,mixed> Queue of ListConnectionsResponse|\Throwable. */
	private array $queue;

	/** @var array<int,array> Options passed to each call. */
	public array $calls = array();

	public function __construct(array $queue) {
		$this->queue = $queue;
	}

	public function listPeopleConnections($resourceName, $optParams = array()) {
		$this->calls[] = $optParams;
		$next = array_shift($this->queue);
		if ($next instanceof \Throwable) {
			throw $next;
		}
		return $next;
	}
}
