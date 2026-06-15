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

namespace FreePBX\Console\Command;

use FreePBX;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\HelpCommand;
use Symfony\Component\Console\Helper\Table;

class Googlecontactsync extends Command {

	protected function configure() {
		$this->setName('googlecontactsync')
			->setDescription(_('Google Contact Sync'))
			->setDefinition(array(
				new InputOption('runsync', null, InputOption::VALUE_NONE, _('Run all accounts that are due (used by cron)')),
				new InputOption('uid', null, InputOption::VALUE_REQUIRED, _('Force-sync one userman user now')),
				new InputOption('all', null, InputOption::VALUE_NONE, _('Force-sync every enabled account regardless of schedule')),
				new InputOption('full', null, InputOption::VALUE_NONE, _('With --uid/--all: clean full import (delete imported contacts and re-import all)')),
				new InputOption('list', null, InputOption::VALUE_NONE, _('Print account status table')),
			));
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$gcs = FreePBX::create()->Googlecontactsync;

		if ($input->getOption('list')) {
			$this->renderList($gcs, $output);
			return 0;
		}

		if ($input->getOption('uid') !== null) {
			return $this->syncOne($gcs, (int) $input->getOption('uid'), (bool) $input->getOption('full'), $output);
		}

		if ($input->getOption('all')) {
			return $this->syncAll($gcs, (bool) $input->getOption('full'), $output);
		}

		if ($input->getOption('runsync')) {
			return $this->runDue($gcs, $output);
		}

		$this->outputHelp($input, $output);
		return 4;
	}

	/**
	 * Print the account status table (`--list`).
	 */
	private function renderList($gcs, OutputInterface $output) {
		$accounts = $gcs->getAllAccounts();
		if (empty($accounts)) {
			$output->writeln(_('No connected Google accounts.'));
			return;
		}

		$table = new Table($output);
		$table->setHeaders(array(
			_('UID'), _('Google Email'), _('Target Group'),
			_('Frequency'), _('Last Sync'), _('Status'),
		));
		foreach ($accounts as $account) {
			$eff = $gcs->getEffectiveFrequency($account);
			$table->addRow(array(
				(int) $account['uid'],
				(string) ($account['google_email'] ?? ''),
				$this->formatGroup($account),
				$this->formatFrequency($eff) . (empty($account['enabled']) ? ' '._('(disabled)') : ''),
				$this->formatTime($account['last_sync'] ?? null),
				(string) ($account['last_status'] ?? ''),
			));
		}
		$table->render();
	}

	/**
	 * Force-sync a single user (`--uid=<id>`). Non-zero exit on failure.
	 */
	private function syncOne($gcs, $uid, $full, OutputInterface $output) {
		if ($uid <= 0) {
			$output->writeln('<error>'._('A valid --uid is required.').'</error>');
			return 1;
		}
		try {
			$res = $gcs->syncUid($uid, $full);
		} catch (\Throwable $e) {
			$output->writeln('<error>'.sprintf(_('uid %d: %s'), $uid, $e->getMessage()).'</error>');
			return 1;
		}
		$this->writeResult($output, $uid, $res);
		return empty($res['status']) ? 1 : 0;
	}

	/**
	 * Force-sync every enabled account regardless of schedule (`--all`).
	 */
	private function syncAll($gcs, $full, OutputInterface $output) {
		$accounts = $gcs->getEnabledAccounts();
		if (empty($accounts)) {
			$output->writeln(_('No enabled Google accounts to sync.'));
			return 0;
		}
		$failures = 0;
		foreach ($accounts as $account) {
			$uid = (int) $account['uid'];
			try {
				$res = $gcs->syncUid($uid, $full);
			} catch (\Throwable $e) {
				$res = array('status' => false, 'message' => $e->getMessage());
			}
			$this->writeResult($output, $uid, $res);
			if (empty($res['status'])) {
				$failures++;
			}
		}
		return $failures > 0 ? 1 : 0;
	}

	/**
	 * Run only the accounts that are currently due (`--runsync`, used by cron).
	 */
	private function runDue($gcs, OutputInterface $output) {
		$results = $gcs->runDueSyncs();
		if (empty($results)) {
			$output->writeln(_('No accounts are due to sync.'));
			return 0;
		}
		$failures = 0;
		foreach ($results as $res) {
			$this->writeResult($output, (int) ($res['uid'] ?? 0), $res);
			if (empty($res['status'])) {
				$failures++;
			}
		}
		return $failures > 0 ? 1 : 0;
	}

	/**
	 * Print a one-line per-account result summary.
	 *
	 * @param array<string,mixed> $res
	 */
	private function writeResult(OutputInterface $output, $uid, array $res) {
		if (!empty($res['status'])) {
			$output->writeln(sprintf(
				_('uid %d: OK — added %d, updated %d, deleted %d.'),
				(int) $uid,
				(int) ($res['added'] ?? 0),
				(int) ($res['updated'] ?? 0),
				(int) ($res['deleted'] ?? 0)
			));
		} else {
			$output->writeln('<error>'.sprintf(
				_('uid %d: ERROR — %s'),
				(int) $uid,
				(string) ($res['message'] ?? _('unknown error'))
			).'</error>');
		}
	}

	/**
	 * Human-readable effective schedule, e.g. "hourly", "daily 03:00",
	 * "weekly Mon 03:00".
	 *
	 * @param array{frequency:string,time:string,dow:int} $eff
	 * @return string
	 */
	private function formatFrequency(array $eff) {
		switch ($eff['frequency']) {
			case 'hourly':
				return 'hourly';
			case 'weekly':
				$days = array('Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat');
				$dow  = isset($days[(int) $eff['dow']]) ? $days[(int) $eff['dow']] : (string) $eff['dow'];
				return 'weekly '.$dow.' '.$eff['time'];
			case 'daily':
			default:
				return 'daily '.$eff['time'];
		}
	}

	/**
	 * @param array<string,mixed> $account
	 * @return string
	 */
	private function formatGroup($account) {
		$id   = isset($account['target_groupid']) ? (int) $account['target_groupid'] : 0;
		$type = (string) ($account['target_group_type'] ?? '');
		if ($id <= 0) {
			return _('(not set)');
		}
		return ($type !== '' ? $type.' ' : '').'#'.$id;
	}

	/**
	 * @param int|null $ts
	 * @return string
	 */
	private function formatTime($ts) {
		$ts = (int) $ts;
		return $ts > 0 ? date('Y-m-d H:i', $ts) : _('never');
	}

	protected function outputHelp(InputInterface $input, OutputInterface $output) {
		$help = new HelpCommand();
		$help->setCommand($this);
		return $help->run($input, $output);
	}
}
