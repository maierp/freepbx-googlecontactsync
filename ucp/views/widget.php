<?php
// vim: set ai ts=4 sw=4 ft=php:
/**
 * Google Contact Sync — FreePBX module (UCP widget view)
 *
 * Copyright (C) 2026 Dr. Patrick Maier, Softwareentwicklung Patrick Maier
 * https://www.se-pm.de — mail@se-pm.de
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later version.
 *
 * @var array  $status          Connection status (connected, email, last_sync, target_groupid, frequency, ...).
 * @var string $authUrl         Google consent URL (when not connected).
 * @var string $authError       Reason the connect action is unavailable.
 * @var string $disconnectToken CSRF token for the disconnect link.
 * @var string $saveToken       CSRF token for the settings form.
 * @var string $syncToken       CSRF token for the "Sync now" link.
 * @var array  $groups          Groups the user may import into (id, name, type).
 * @var array  $globalFrequency Admin default schedule (frequency, time, dow).
 * @var array  $frequencies     Allowed frequency keys (hourly|daily|weekly).
 * @var array  $daysOfWeek      Day-of-week labels keyed 0..6.
 * @var string $message         One-shot status message key from the last action.
 */
$messages = array(
	'connected'    => array('success', _('Your Google account is now connected.')),
	'disconnected' => array('info',    _('Your Google account has been disconnected.')),
	'denied'       => array('warning', _('Google access was not granted.')),
	'error'        => array('danger',  _('Something went wrong. Please try again.')),
);

$freqLabels = array(
	'hourly' => _('Hourly'),
	'daily'  => _('Daily'),
	'weekly' => _('Weekly'),
);

/** Human-readable description of the admin default schedule. */
$globalDesc = isset($freqLabels[$globalFrequency['frequency']])
	? $freqLabels[$globalFrequency['frequency']]
	: $globalFrequency['frequency'];
if ($globalFrequency['frequency'] === 'daily') {
	$globalDesc .= ' '._('at').' '.$globalFrequency['time'];
} elseif ($globalFrequency['frequency'] === 'weekly') {
	$dayLabel = isset($daysOfWeek[$globalFrequency['dow']]) ? $daysOfWeek[$globalFrequency['dow']] : '';
	$globalDesc .= ' '._('on').' '.$dayLabel.' '._('at').' '.$globalFrequency['time'];
}

$currentFreq = isset($status['frequency']) && in_array($status['frequency'], $frequencies, true)
	? $status['frequency'] : 'default';
$currentTime = !empty($status['freq_time']) ? $status['freq_time'] : $globalFrequency['time'];
$currentDow  = ($status['freq_dow'] !== null) ? (int) $status['freq_dow'] : (int) $globalFrequency['dow'];
?>
<div class="googlecontactsync-widget">
	<?php if (!empty($message) && isset($messages[$message])): ?>
		<div class="alert alert-<?php echo $messages[$message][0]; ?>">
			<?php echo htmlspecialchars($messages[$message][1]); ?>
		</div>
	<?php endif; ?>

	<?php if (!empty($status['connected'])): ?>
		<p>
			<i class="fa fa-check-circle text-success"></i>
			<?php echo _('Connected as'); ?>
			<strong><?php echo htmlspecialchars($status['email']); ?></strong>
		</p>
		<?php if (!empty($status['last_sync'])): ?>
			<p class="text-muted gcs-last-sync">
				<?php echo _('Last sync:'); ?>
				<?php echo htmlspecialchars(date('Y-m-d H:i', (int) $status['last_sync'])); ?>
				(<?php echo htmlspecialchars($status['last_status']); ?>)
			</p>
		<?php else: ?>
			<p class="text-muted gcs-last-sync"><?php echo _('No sync has run yet.'); ?></p>
		<?php endif; ?>

		<div class="gcs-last-error">
			<?php if (($status['last_status'] ?? '') === 'error' && !empty($status['last_message'])): ?>
				<div class="alert alert-danger">
					<strong><?php echo _('Last sync error:'); ?></strong>
					<?php echo htmlspecialchars((string) $status['last_message']); ?>
				</div>
			<?php endif; ?>
		</div>

		<form method="post" action="index.php" class="googlecontactsync-settings gcs-settings-form">
			<input type="hidden" name="googlecontactsync" value="savesettings">
			<input type="hidden" name="token" value="<?php echo htmlspecialchars($saveToken, ENT_QUOTES); ?>">

			<div class="form-group">
				<label for="gcs-target-group"><?php echo _('Import into group'); ?></label>
				<select class="form-control" id="gcs-target-group" name="target_group">
					<?php foreach ($groups as $g): ?>
						<option value="<?php echo (int) $g['id']; ?>"
							<?php echo ((int) $status['target_groupid'] === (int) $g['id']) ? 'selected' : ''; ?>>
							<?php echo htmlspecialchars($g['name']); ?>
							(<?php echo htmlspecialchars($g['type'] === 'external' ? _('shared') : _('private')); ?>)
						</option>
					<?php endforeach; ?>
					<option value="__new__" <?php echo empty($status['target_groupid']) ? 'selected' : ''; ?>>
						<?php echo _('Create new private group "Google Contacts"'); ?>
					</option>
				</select>
			</div>

			<div class="form-group">
				<label for="gcs-frequency"><?php echo _('Sync frequency'); ?></label>
				<select class="form-control gcs-frequency" id="gcs-frequency" name="frequency">
					<option value="default" <?php echo ($currentFreq === 'default') ? 'selected' : ''; ?>>
						<?php echo _('Use system default'); ?> (<?php echo htmlspecialchars($globalDesc); ?>)
					</option>
					<?php foreach ($frequencies as $f): ?>
						<option value="<?php echo htmlspecialchars($f, ENT_QUOTES); ?>"
							<?php echo ($currentFreq === $f) ? 'selected' : ''; ?>>
							<?php echo htmlspecialchars(isset($freqLabels[$f]) ? $freqLabels[$f] : $f); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="form-group gcs-field-time">
				<label for="gcs-freq-time"><?php echo _('Time of day (daily/weekly)'); ?></label>
				<input type="time" class="form-control" id="gcs-freq-time" name="freq_time"
					value="<?php echo htmlspecialchars($currentTime, ENT_QUOTES); ?>">
			</div>

			<div class="form-group gcs-field-dow">
				<label for="gcs-freq-dow"><?php echo _('Day of week (weekly)'); ?></label>
				<select class="form-control" id="gcs-freq-dow" name="freq_dow">
					<?php foreach ($daysOfWeek as $num => $label): ?>
						<option value="<?php echo (int) $num; ?>"
							<?php echo ($currentDow === (int) $num) ? 'selected' : ''; ?>>
							<?php echo htmlspecialchars($label); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<button type="submit" class="btn btn-primary">
				<i class="fa fa-save"></i> <?php echo _('Save settings'); ?>
			</button>
		</form>

		<p class="googlecontactsync-actions" style="margin-top:10px;">
			<button type="button" class="btn btn-default gcs-syncnow"
			   data-token="<?php echo htmlspecialchars($syncToken, ENT_QUOTES); ?>">
				<i class="fa fa-refresh"></i> <?php echo _('Sync now'); ?>
			</button>
			<a class="btn btn-default"
			   href="index.php?googlecontactsync=disconnect&amp;token=<?php echo urlencode($disconnectToken); ?>"
			   onclick="return confirm('<?php echo htmlspecialchars(_('Disconnect this Google account?'), ENT_QUOTES); ?>');">
				<i class="fa fa-unlink"></i> <?php echo _('Disconnect'); ?>
			</a>
		</p>
	<?php elseif (!empty($authUrl)): ?>
		<p><?php echo _('Import your Google Contacts into your PBX contacts.'); ?></p>
		<a class="btn btn-primary" href="<?php echo htmlspecialchars($authUrl); ?>">
			<i class="fa fa-google"></i> <?php echo _('Connect Google Account'); ?>
		</a>
	<?php elseif (empty($status['credentialsConfigured'])): ?>
		<p class="text-muted">
			<?php echo _('Google Contact Sync has not been configured by your administrator yet.'); ?>
		</p>
	<?php else: ?>
		<p class="text-muted"><?php echo htmlspecialchars($authError); ?></p>
	<?php endif; ?>
</div>
