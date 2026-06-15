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

/**
 * Pure, side-effect-free scheduling logic (spec §10.1).
 *
 * Decides whether an account is due for a sync given its effective frequency
 * (per-user override resolved against the admin default) and the timestamp of
 * its last successful run. Kept free of FreePBX/DB dependencies so the
 * hourly/daily/weekly due-time rules are unit-testable in isolation.
 */
class Schedule {

	/** Default time-of-day used when an effective schedule omits/garbles it. */
	const DEFAULT_TIME = '03:00';

	/** Default day-of-week (Monday) used when an effective schedule omits it. */
	const DEFAULT_DOW = 1;

	/**
	 * Whether an account is due to sync now.
	 *
	 * @param array{frequency:string,time?:string,dow?:int} $eff Effective schedule.
	 * @param int|null $lastSync Unix timestamp of the last sync (0/null = never).
	 * @param int      $now      Current Unix timestamp.
	 * @return bool
	 */
	public static function isDue(array $eff, $lastSync, $now) {
		$frequency = isset($eff['frequency']) ? (string) $eff['frequency'] : 'daily';
		$lastSync  = (int) $lastSync;
		$now       = (int) $now;

		switch ($frequency) {
			case 'hourly':
				return ($now - $lastSync) >= 3600;

			case 'weekly':
				$dow = isset($eff['dow']) ? (int) $eff['dow'] : self::DEFAULT_DOW;
				if ((int) date('w', $now) !== $dow) {
					return false;
				}
				return self::dueAtTimeToday($eff, $lastSync, $now);

			case 'daily':
			default:
				return self::dueAtTimeToday($eff, $lastSync, $now);
		}
	}

	/**
	 * True when `$now` is at/after today's scheduled time-of-day and the account
	 * has not already synced since that scheduled moment. This expresses both
	 * "past freq_time" and "not yet synced today/this week" from the spec.
	 *
	 * @param array{time?:string} $eff
	 * @param int $lastSync
	 * @param int $now
	 * @return bool
	 */
	private static function dueAtTimeToday(array $eff, $lastSync, $now) {
		$time = isset($eff['time']) ? (string) $eff['time'] : self::DEFAULT_TIME;
		if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
			$time = self::DEFAULT_TIME;
			preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m);
		}
		$scheduled = mktime((int) $m[1], (int) $m[2], 0, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));
		if ($now < $scheduled) {
			return false;
		}
		return $lastSync < $scheduled;
	}
}
