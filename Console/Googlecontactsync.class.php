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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\HelpCommand;

class Googlecontactsync extends Command {

	protected function configure() {
		$this->setName('googlecontactsync')
			->setDescription(_('Google Contact Sync'))
			->setDefinition(array(
				new InputOption('runsync', null, InputOption::VALUE_NONE, _('Run all accounts that are due (used by cron)')),
				new InputOption('uid', null, InputOption::VALUE_REQUIRED, _('Force-sync one userman user now')),
				new InputOption('all', null, InputOption::VALUE_NONE, _('Force-sync every enabled account regardless of schedule')),
				new InputOption('list', null, InputOption::VALUE_NONE, _('Print account status table')),
			));
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		// Sync/scheduling logic is implemented in M5. Placeholder for now.
		return $this->outputHelp($input, $output);
	}

	protected function outputHelp(InputInterface $input, OutputInterface $output) {
		$help = new HelpCommand();
		$help->setCommand($this);
		return $help->run($input, $output);
	}
}
