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
use FreePBX\modules\Googlecontactsync\Lib\Schedule;

/**
 * Due-time scheduling rules for hourly/daily/weekly frequencies (spec §10.1).
 *
 * @covers \FreePBX\modules\Googlecontactsync\Lib\Schedule
 */
class ScheduleTest extends TestCase {

	/** @var string|false Saved default timezone, restored in tearDown. */
	private $tz;

	protected function setUp(): void {
		// Pin a timezone so mktime()/date() are deterministic across hosts.
		$this->tz = date_default_timezone_get();
		date_default_timezone_set('UTC');
	}

	protected function tearDown(): void {
		if (is_string($this->tz)) {
			date_default_timezone_set($this->tz);
		}
	}

	/** Unix timestamp for a UTC wall-clock moment. */
	private function at($str) {
		return (int) strtotime($str.' UTC');
	}

	// ----- hourly -------------------------------------------------------- //

	public function testHourlyDueWhenAnHourElapsed() {
		$now = $this->at('2026-06-15 10:00:00');
		$this->assertTrue(Schedule::isDue(array('frequency' => 'hourly'), $now - 3600, $now));
		$this->assertTrue(Schedule::isDue(array('frequency' => 'hourly'), $now - 7200, $now));
	}

	public function testHourlyNotDueWithinTheHour() {
		$now = $this->at('2026-06-15 10:00:00');
		$this->assertFalse(Schedule::isDue(array('frequency' => 'hourly'), $now - 3599, $now));
		$this->assertFalse(Schedule::isDue(array('frequency' => 'hourly'), $now - 60, $now));
	}

	public function testHourlyDueWhenNeverSynced() {
		$now = $this->at('2026-06-15 10:00:00');
		$this->assertTrue(Schedule::isDue(array('frequency' => 'hourly'), 0, $now));
	}

	// ----- daily --------------------------------------------------------- //

	public function testDailyDueAfterScheduledTimeNotYetSyncedToday() {
		$eff = array('frequency' => 'daily', 'time' => '03:00');
		$now = $this->at('2026-06-15 03:30:00');
		// Last synced yesterday → due now that today's 03:00 has passed.
		$this->assertTrue(Schedule::isDue($eff, $this->at('2026-06-14 03:05:00'), $now));
	}

	public function testDailyNotDueBeforeScheduledTime() {
		$eff = array('frequency' => 'daily', 'time' => '03:00');
		$now = $this->at('2026-06-15 02:59:00');
		$this->assertFalse(Schedule::isDue($eff, $this->at('2026-06-14 03:05:00'), $now));
	}

	public function testDailyNotDueWhenAlreadySyncedToday() {
		$eff = array('frequency' => 'daily', 'time' => '03:00');
		$now = $this->at('2026-06-15 09:00:00');
		// Already ran after today's scheduled time.
		$this->assertFalse(Schedule::isDue($eff, $this->at('2026-06-15 03:02:00'), $now));
	}

	public function testDailyDueWhenNeverSyncedAndPastTime() {
		$eff = array('frequency' => 'daily', 'time' => '03:00');
		$now = $this->at('2026-06-15 10:00:00');
		$this->assertTrue(Schedule::isDue($eff, 0, $now));
	}

	public function testDailyDefaultTimeWhenMissing() {
		// Default schedule time is 03:00.
		$eff = array('frequency' => 'daily');
		$this->assertFalse(Schedule::isDue($eff, 0, $this->at('2026-06-15 02:30:00')));
		$this->assertTrue(Schedule::isDue($eff, 0, $this->at('2026-06-15 03:30:00')));
	}

	// ----- weekly -------------------------------------------------------- //

	public function testWeeklyDueOnMatchingDayPastTime() {
		// 2026-06-15 is a Monday (dow 1).
		$eff = array('frequency' => 'weekly', 'time' => '03:00', 'dow' => 1);
		$now = $this->at('2026-06-15 03:30:00');
		$this->assertTrue(Schedule::isDue($eff, $this->at('2026-06-08 03:05:00'), $now));
	}

	public function testWeeklyNotDueOnNonMatchingDay() {
		// 2026-06-16 is a Tuesday (dow 2); schedule targets Monday.
		$eff = array('frequency' => 'weekly', 'time' => '03:00', 'dow' => 1);
		$now = $this->at('2026-06-16 03:30:00');
		$this->assertFalse(Schedule::isDue($eff, $this->at('2026-06-08 03:05:00'), $now));
	}

	public function testWeeklyNotDueBeforeScheduledTimeOnMatchingDay() {
		$eff = array('frequency' => 'weekly', 'time' => '03:00', 'dow' => 1);
		$now = $this->at('2026-06-15 02:00:00');
		$this->assertFalse(Schedule::isDue($eff, $this->at('2026-06-08 03:05:00'), $now));
	}

	public function testWeeklyNotDueWhenAlreadySyncedThisWeek() {
		$eff = array('frequency' => 'weekly', 'time' => '03:00', 'dow' => 1);
		$now = $this->at('2026-06-15 09:00:00');
		// Already synced after this Monday's scheduled time.
		$this->assertFalse(Schedule::isDue($eff, $this->at('2026-06-15 03:01:00'), $now));
	}

	public function testWeeklyDueWhenNeverSyncedOnMatchingDay() {
		$eff = array('frequency' => 'weekly', 'time' => '03:00', 'dow' => 1);
		$now = $this->at('2026-06-15 04:00:00');
		$this->assertTrue(Schedule::isDue($eff, 0, $now));
	}

	// ----- fallback ------------------------------------------------------ //

	public function testUnknownFrequencyTreatedAsDaily() {
		$eff = array('frequency' => 'monthly', 'time' => '03:00');
		$this->assertFalse(Schedule::isDue($eff, 0, $this->at('2026-06-15 02:00:00')));
		$this->assertTrue(Schedule::isDue($eff, 0, $this->at('2026-06-15 04:00:00')));
	}
}
