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
?>
<?php if (!empty($message) && is_array($message)) { ?>
	<div class="alert alert-<?php echo htmlspecialchars($message['type']); ?>"><?php echo htmlspecialchars($message['message']); ?></div>
<?php } ?>
<div class="container-fluid">
	<h1><?php echo _('Google Contact Sync'); ?></h1>
	<div class="row">
		<div class="col-sm-12">
			<ul class="nav nav-tabs" role="tablist">
				<li class="active" role="presentation"><a href="#settings" aria-controls="settings" role="tab" data-toggle="tab"><?php echo _('Settings'); ?></a></li>
			</ul>
			<div class="tab-content">
				<div id="settings" class="tab-pane active" role="tabpanel">
					<?php echo $settings; ?>
				</div>
			</div>
		</div>
	</div>
</div>
