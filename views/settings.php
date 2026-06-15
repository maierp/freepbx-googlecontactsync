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
<div class="fpbx-container">
	<div class="display full-border">
		<form name="gcssettings" class="fpbx-submit" method="post" action="config.php?display=googlecontactsync" autocomplete="off">
			<input type="hidden" name="action" value="savesettings">

			<!--Client ID-->
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
					</div>
				</div>
				<div class="row">
					<div class="col-md-12">
						<span id="client_id-help" class="help-block fpbx-help-block"><?php echo _('The OAuth 2.0 Web application Client ID from your Google Cloud project.'); ?></span>
					</div>
				</div>
			</div>
			<!--END Client ID-->

			<!--Client Secret-->
			<div class="element-container">
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
					</div>
				</div>
				<div class="row">
					<div class="col-md-12">
						<span id="client_secret-help" class="help-block fpbx-help-block"><?php echo _('Stored encrypted. Leave blank to keep the existing secret; clearing it is not possible from here once set unless you enter a new value.'); ?></span>
					</div>
				</div>
			</div>
			<!--END Client Secret-->

			<!--Redirect URI-->
			<div class="element-container">
				<div class="row">
					<div class="col-md-12">
						<div class="row">
							<div class="form-group">
								<div class="col-md-3">
									<label class="control-label" for="redirect_uri"><?php echo _('Authorized Redirect URI'); ?></label>
									<i class="fa fa-question-circle fpbx-help-icon" data-for="redirect_uri"></i>
								</div>
								<div class="col-md-9">
									<div class="input-group">
										<input type="text" class="form-control" id="redirect_uri" name="redirect_uri" autocomplete="off" placeholder="<?php echo htmlspecialchars($defaultRedirectUri); ?>" value="<?php echo htmlspecialchars($redirectUri); ?>">
										<span class="input-group-btn">
											<button type="button" class="btn btn-default" id="redirect_uri_default" title="<?php echo htmlspecialchars(_('Use auto-detected URL'), ENT_QUOTES); ?>" data-default="<?php echo htmlspecialchars($defaultRedirectUri); ?>"><?php echo _('Use default'); ?></button>
										</span>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div class="row">
					<div class="col-md-12">
						<span id="redirect_uri-help" class="help-block fpbx-help-block"><?php echo _('Add this exact value to the Authorized redirect URIs of your Google OAuth client. Must be a public HTTPS URL ending in /ucp/index.php. Leave blank to use the auto-detected default shown as placeholder.'); ?></span>
					</div>
				</div>
			</div>
			<!--END Redirect URI-->

			<!--Default Sync Frequency-->
			<div class="element-container">
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
					</div>
				</div>
				<div class="row">
					<div class="col-md-12">
						<span id="frequency-help" class="help-block fpbx-help-block"><?php echo _('System-wide default schedule. Users may override this in UCP.'); ?></span>
					</div>
				</div>
			</div>
			<!--END Default Sync Frequency-->

			<!--Time of Day-->
			<div class="element-container gcs-freq-time"<?php echo $freq === 'hourly' ? ' style="display:none;"' : ''; ?>>
				<div class="row">
					<div class="col-md-12">
						<div class="row">
							<div class="form-group">
								<div class="col-md-3">
									<label class="control-label" for="freq_time"><?php echo _('Time of Day'); ?></label>
									<i class="fa fa-question-circle fpbx-help-icon" data-for="freq_time"></i>
								</div>
								<div class="col-md-9">
									<input type="time" class="form-control" id="freq_time" name="freq_time" value="<?php echo htmlspecialchars($frequency['time']); ?>">
								</div>
							</div>
						</div>
					</div>
				</div>
				<div class="row">
					<div class="col-md-12">
						<span id="freq_time-help" class="help-block fpbx-help-block"><?php echo _('Time of day to run daily and weekly syncs.'); ?></span>
					</div>
				</div>
			</div>
			<!--END Time of Day-->

			<!--Day of Week-->
			<div class="element-container gcs-freq-dow"<?php echo $freq === 'weekly' ? '' : ' style="display:none;"'; ?>>
				<div class="row">
					<div class="col-md-12">
						<div class="row">
							<div class="form-group">
								<div class="col-md-3">
									<label class="control-label" for="freq_dow"><?php echo _('Day of Week'); ?></label>
									<i class="fa fa-question-circle fpbx-help-icon" data-for="freq_dow"></i>
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
				<div class="row">
					<div class="col-md-12">
						<span id="freq_dow-help" class="help-block fpbx-help-block"><?php echo _('Day of week to run weekly syncs.'); ?></span>
					</div>
				</div>
			</div>
			<!--END Day of Week-->
		</form>
	</div>
</div>

<script type="text/javascript">
	(function() {
		function toggleFreqFields() {
			var sel = document.getElementById('frequency');
			if (!sel) {
				return;
			}
			var v = sel.value;
			document.querySelector('.gcs-freq-time').style.display = (v === 'hourly') ? 'none' : '';
			document.querySelector('.gcs-freq-dow').style.display = (v === 'weekly') ? '' : 'none';
		}
		var sel = document.getElementById('frequency');
		if (sel) {
			sel.addEventListener('change', toggleFreqFields);
		}

		var defBtn = document.getElementById('redirect_uri_default');
		if (defBtn) {
			defBtn.addEventListener('click', function() {
				var input = document.getElementById('redirect_uri');
				if (input) {
					input.value = defBtn.getAttribute('data-default') || '';
				}
			});
		}
	})();
</script>
