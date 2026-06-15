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

namespace UCP {
	// Minimal stand-in for the UCP module base class so the UCP module file can
	// be loaded without the full UCP framework present.
	if (!class_exists('UCP\\Modules')) {
		#[\AllowDynamicProperties]
		class Modules {
		}
	}
}

namespace {

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../ucp/Googlecontactsync.class.php';

/**
 * Test double for the main BMO that the UCP handler delegates to. Records the
 * delegated calls and lets each test pick which action tokens are valid and
 * whether syncUid() throws.
 */
class GcsFakeBmo {
	/** @var array<int,array<int,mixed>> Recorded delegated calls. */
	public $calls = array();
	/** @var array<string,string> Valid token per action. */
	public $tokens = array('savesettings' => 'save-ok', 'syncnow' => 'sync-ok', 'fullsync' => 'full-ok');
	/** @var bool When true, syncUid() throws to simulate a failed run. */
	public $syncThrows = false;

	/** @var array<string,mixed> Status returned by getConnectionStatus(). */
	public $status = array('last_sync' => 1700000000, 'last_status' => 'ok');

	public function verifyActionToken($uid, $action, $token) {
		return isset($this->tokens[$action]) && hash_equals($this->tokens[$action], (string) $token);
	}

	public function setAccountTarget($uid, $groupid, $type = '') {
		$this->calls[] = array('setAccountTarget', (int) $uid, (int) $groupid);
		return true;
	}

	public function createAndSetTargetGroup($uid) {
		$this->calls[] = array('createAndSetTargetGroup', (int) $uid);
		return true;
	}

	public function setAccountFrequency($uid, $freq, $time = null, $dow = null) {
		$this->calls[] = array('setAccountFrequency', (int) $uid, $freq, $time, $dow);
		return true;
	}

	public function syncUid($uid, $full = false) {
		$this->calls[] = array('syncUid', (int) $uid, (bool) $full);
		if ($this->syncThrows) {
			throw new \Exception('boom');
		}
		return array('status' => true);
	}

	public function getConnectionStatus($uid) {
		return $this->status;
	}
}

/**
 * Covers the UCP AJAX handler: session gating, per-action CSRF token rejection,
 * and the save / sync-now delegation paths.
 *
 * @covers \UCP\Modules\Googlecontactsync
 */
class UcpAjaxHandlerTest extends TestCase {

	private const UID = 42;

	/** @var GcsFakeBmo */
	private $bmo;

	/** @var \UCP\Modules\Googlecontactsync */
	private $ucp;

	protected function setUp(): void {
		$_REQUEST = array();
		$_POST    = array();

		$this->bmo = new GcsFakeBmo();
		$this->ucp = $this->makeHandler(self::UID);
	}

	protected function tearDown(): void {
		$_REQUEST = array();
		$_POST    = array();
	}

	/**
	 * Build a UCP module instance without running its framework-bound
	 * constructor, then inject a fake session uid and a fake BMO delegate.
	 *
	 * @param int|false $userId
	 */
	private function makeHandler($userId): \UCP\Modules\Googlecontactsync {
		$ref = new \ReflectionClass(\UCP\Modules\Googlecontactsync::class);
		$obj = $ref->newInstanceWithoutConstructor();

		$prop = $ref->getProperty('userId');
		$prop->setAccessible(true);
		$prop->setValue($obj, $userId);

		$ucp = new \stdClass();
		$ucp->FreePBX = new \stdClass();
		$ucp->FreePBX->Googlecontactsync = $this->bmo;
		$obj->UCP = $ucp;

		return $obj;
	}

	public function testNotSignedInIsRejected(): void {
		$ucp = $this->makeHandler(false);
		$_REQUEST['command'] = 'savesettings';

		$res = $ucp->ajaxHandler();

		$this->assertFalse($res['status']);
		$this->assertSame(array(), $this->bmo->calls);
	}

	public function testUnknownCommandIsRejected(): void {
		$_REQUEST['command'] = 'bogus';

		$res = $this->ucp->ajaxHandler();

		$this->assertFalse($res['status']);
		$this->assertSame(array(), $this->bmo->calls);
	}

	public function testSaveSettingsRejectsBadToken(): void {
		$_REQUEST['command'] = 'savesettings';
		$_POST['token']      = 'wrong';
		$_POST['frequency']  = 'weekly';

		$res = $this->ucp->ajaxHandler();

		$this->assertFalse($res['status']);
		$this->assertStringContainsString('token', strtolower($res['message']));
		$this->assertSame(array(), $this->bmo->calls, 'No save must happen on token mismatch.');
	}

	public function testSaveSettingsWithExistingGroupDelegates(): void {
		$_REQUEST['command'] = 'savesettings';
		$_POST['token']        = 'save-ok';
		$_POST['target_group'] = '7';
		$_POST['frequency']    = 'weekly';
		$_POST['freq_time']    = '04:30';
		$_POST['freq_dow']     = '3';

		$res = $this->ucp->ajaxHandler();

		$this->assertTrue($res['status']);
		$this->assertContains(array('setAccountTarget', self::UID, 7), $this->bmo->calls);
		$this->assertContains(array('setAccountFrequency', self::UID, 'weekly', '04:30', '3'), $this->bmo->calls);
	}

	public function testSaveSettingsWithNewGroupCreatesGroup(): void {
		$_REQUEST['command'] = 'savesettings';
		$_POST['token']        = 'save-ok';
		$_POST['target_group'] = '__new__';
		$_POST['frequency']    = 'default';

		$res = $this->ucp->ajaxHandler();

		$this->assertTrue($res['status']);
		$this->assertContains(array('createAndSetTargetGroup', self::UID), $this->bmo->calls);
		// Clearing the override is the "default" save path.
		$this->assertContains(array('setAccountFrequency', self::UID, 'default', null, null), $this->bmo->calls);
		foreach ($this->bmo->calls as $call) {
			$this->assertNotSame('setAccountTarget', $call[0], 'A new group must not also call setAccountTarget.');
		}
	}

	public function testSyncNowRejectsBadToken(): void {
		$_REQUEST['command'] = 'syncnow';
		$_POST['token']      = 'nope';

		$res = $this->ucp->ajaxHandler();

		$this->assertFalse($res['status']);
		$this->assertSame(array(), $this->bmo->calls);
	}

	public function testSyncNowRunsForSessionUid(): void {
		$_REQUEST['command'] = 'syncnow';
		$_POST['token']      = 'sync-ok';

		$res = $this->ucp->ajaxHandler();

		$this->assertTrue($res['status']);
		$this->assertSame(array(array('syncUid', self::UID, false)), $this->bmo->calls);
		$this->assertArrayHasKey('lastSync', $res);
		$this->assertStringContainsString('ok', $res['lastSync']);
	}

	public function testFullSyncRunsCleanImportForSessionUid(): void {
		$_REQUEST['command'] = 'fullsync';
		$_POST['token']      = 'full-ok';

		$res = $this->ucp->ajaxHandler();

		$this->assertTrue($res['status']);
		$this->assertSame(array(array('syncUid', self::UID, true)), $this->bmo->calls);
	}

	public function testFullSyncRejectsBadToken(): void {
		$_REQUEST['command'] = 'fullsync';
		$_POST['token']      = 'nope';

		$res = $this->ucp->ajaxHandler();

		$this->assertFalse($res['status']);
		$this->assertSame(array(), $this->bmo->calls);
	}

	public function testSyncNowReportsFailureGracefully(): void {
		$this->bmo->syncThrows = true;
		$_REQUEST['command']   = 'syncnow';
		$_POST['token']        = 'sync-ok';

		$res = $this->ucp->ajaxHandler();

		$this->assertFalse($res['status']);
		$this->assertSame(array(array('syncUid', self::UID, false)), $this->bmo->calls);
	}
}

}
