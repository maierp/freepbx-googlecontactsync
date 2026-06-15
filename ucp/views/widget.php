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
 * @var array  $status          Connection status (connected, email, last_sync, ...).
 * @var string $authUrl         Google consent URL (when not connected).
 * @var string $authError       Reason the connect action is unavailable.
 * @var string $disconnectToken CSRF token for the disconnect link.
 * @var string $message         One-shot status message key from the last action.
 */
$messages = array(
	'connected'    => array('success', _('Your Google account is now connected.')),
	'disconnected' => array('info',    _('Your Google account has been disconnected.')),
	'denied'       => array('warning', _('Google access was not granted.')),
	'error'        => array('danger',  _('Something went wrong. Please try again.')),
);
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
			<p class="text-muted">
				<?php echo _('Last sync:'); ?>
				<?php echo htmlspecialchars(date('Y-m-d H:i', (int) $status['last_sync'])); ?>
				(<?php echo htmlspecialchars($status['last_status']); ?>)
			</p>
		<?php else: ?>
			<p class="text-muted"><?php echo _('No sync has run yet.'); ?></p>
		<?php endif; ?>
		<a class="btn btn-default"
		   href="index.php?googlecontactsync=disconnect&amp;token=<?php echo urlencode($disconnectToken); ?>"
		   onclick="return confirm('<?php echo htmlspecialchars(_('Disconnect this Google account?'), ENT_QUOTES); ?>');">
			<i class="fa fa-unlink"></i> <?php echo _('Disconnect'); ?>
		</a>
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
