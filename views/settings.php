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

$freq = $frequency['frequency'];
?>
<form name="gcssettings" class="fpbx-submit" method="post" action="config.php?display=googlecontactsync" autocomplete="off">
<input type="hidden" name="action" value="savesettings">

<div class="element-container">
	<div class="row">
		<div class="col-md-12">
			<div class="row">
				<div class="form-group">
					<div class="col-md-3">
						<label class="control-label" for="client_id"><?php echo _('Google OAuth Client ID'); ?></label>
						<i class="fa fa-question-circle fpbx-help-icon" data-for="client_id"></i>
					</div>
					<div class="col-md-9">
						<input type="text" class="form-control" id="client_id" name="client_id" autocomplete="off" value="<?php echo htmlspecialchars($clientId); ?>">
					</div>
				</div>
			</div>
			<div class="row"><div class="col-md-12"><span id="client_id-help" class="help-block fpbx-help-block"><?php echo _('The OAuth 2.0 Web application Client ID from your Google Cloud project.'); ?></span></div></div>
		</div>
	</div>

	<div class="row">
		<div class="col-md-12">
			<div class="row">
				<div class="form-group">
					<div class="col-md-3">
						<label class="control-label" for="client_secret"><?php echo _('Google OAuth Client Secret'); ?></label>
						<i class="fa fa-question-circle fpbx-help-icon" data-for="client_secret"></i>
					</div>
					<div class="col-md-9">
						<input type="password" class="form-control" id="client_secret" name="client_secret" autocomplete="new-password" value="" placeholder="<?php echo $hasClientSecret ? htmlspecialchars(_('•••••••• (stored — leave blank to keep)')) : htmlspecialchars(_('Not set')); ?>">
					</div>
				</div>
			</div>
			<div class="row"><div class="col-md-12"><span id="client_secret-help" class="help-block fpbx-help-block"><?php echo _('Stored encrypted. Leave blank to keep the existing secret; clearing it is not possible from here once set unless you enter a new value.'); ?></span></div></div>
		</div>
	</div>

	<div class="row">
		<div class="col-md-12">
			<div class="row">
				<div class="form-group">
					<div class="col-md-3">
						<label class="control-label" for="redirect_uri"><?php echo _('Authorized Redirect URI'); ?></label>
						<i class="fa fa-question-circle fpbx-help-icon" data-for="redirect_uri"></i>
					</div>
					<div class="col-md-9">
						<input type="text" class="form-control" id="redirect_uri" readonly onclick="this.select();" value="<?php echo htmlspecialchars($redirectUri); ?>">
					</div>
				</div>
			</div>
			<div class="row"><div class="col-md-12"><span id="redirect_uri-help" class="help-block fpbx-help-block"><?php echo _('Add this exact value to the Authorized redirect URIs of your Google OAuth client. Requires a public HTTPS FQDN.'); ?></span></div></div>
		</div>
	</div>

	<hr>

	<div class="row">
		<div class="col-md-12">
			<div class="row">
				<div class="form-group">
					<div class="col-md-3">
						<label class="control-label" for="frequency"><?php echo _('Default Sync Frequency'); ?></label>
						<i class="fa fa-question-circle fpbx-help-icon" data-for="frequency"></i>
					</div>
					<div class="col-md-9">
						<select class="form-control" id="frequency" name="frequency">
							<option value="hourly"<?php echo $freq === 'hourly' ? ' selected' : ''; ?>><?php echo _('Hourly'); ?></option>
							<option value="daily"<?php echo $freq === 'daily' ? ' selected' : ''; ?>><?php echo _('Daily'); ?></option>
							<option value="weekly"<?php echo $freq === 'weekly' ? ' selected' : ''; ?>><?php echo _('Weekly'); ?></option>
						</select>
					</div>
				</div>
			</div>
			<div class="row"><div class="col-md-12"><span id="frequency-help" class="help-block fpbx-help-block"><?php echo _('System-wide default schedule. Users may override this in UCP.'); ?></span></div></div>
		</div>
	</div>

	<div class="row gcs-freq-time"<?php echo $freq === 'hourly' ? ' style="display:none;"' : ''; ?>>
		<div class="col-md-12">
			<div class="row">
				<div class="form-group">
					<div class="col-md-3">
						<label class="control-label" for="freq_time"><?php echo _('Time of Day'); ?></label>
					</div>
					<div class="col-md-9">
						<input type="time" class="form-control" id="freq_time" name="freq_time" value="<?php echo htmlspecialchars($frequency['time']); ?>">
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="row gcs-freq-dow"<?php echo $freq === 'weekly' ? '' : ' style="display:none;"'; ?>>
		<div class="col-md-12">
			<div class="row">
				<div class="form-group">
					<div class="col-md-3">
						<label class="control-label" for="freq_dow"><?php echo _('Day of Week'); ?></label>
					</div>
					<div class="col-md-9">
						<select class="form-control" id="freq_dow" name="freq_dow">
							<?php foreach ($daysOfWeek as $dow => $label) { ?>
								<option value="<?php echo (int) $dow; ?>"<?php echo (int) $frequency['dow'] === (int) $dow ? ' selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
							<?php } ?>
						</select>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
</form>

<script type="text/javascript">
	(function() {
		function toggleFreqFields() {
			var v = document.getElementById('frequency').value;
			document.querySelector('.gcs-freq-time').style.display = (v === 'hourly') ? 'none' : '';
			document.querySelector('.gcs-freq-dow').style.display = (v === 'weekly') ? '' : 'none';
		}
		var sel = document.getElementById('frequency');
		if (sel) {
			sel.addEventListener('change', toggleFreqFields);
		}
	})();
</script>
