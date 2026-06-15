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

if (!defined('FREEPBX_IS_AUTH')) {
	die('No direct script access allowed');
}

/**
 * @var array<int,array<string,mixed>> $rows
 */
$statusClass = function ($status, $enabled) {
	if (!$enabled) {
		return 'default';
	}
	switch ($status) {
		case 'ok':
			return 'success';
		case 'error':
			return 'danger';
		case 'running':
			return 'warning';
		default:
			return 'default';
	}
};
?>
<div class="fpbx-container">
	<div class="display full-border">
		<p class="help-block"><?php echo _('Users who have connected a Google account from UCP. Use Sync now to run an immediate sync, or Disconnect to revoke access and remove the connection.'); ?></p>
		<table class="table table-striped" id="gcs-users-table">
			<thead>
				<tr>
					<th><?php echo _('User'); ?></th>
					<th><?php echo _('Google Account'); ?></th>
					<th><?php echo _('Target Group'); ?></th>
					<th><?php echo _('Frequency'); ?></th>
					<th><?php echo _('Last Sync'); ?></th>
					<th><?php echo _('Status'); ?></th>
					<th class="text-right"><?php echo _('Actions'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if (empty($rows)) { ?>
					<tr>
						<td colspan="7" class="text-center text-muted"><?php echo _('No users have connected a Google account yet.'); ?></td>
					</tr>
				<?php } else { ?>
					<?php foreach ($rows as $row) { ?>
						<tr data-uid="<?php echo (int) $row['uid']; ?>">
							<td><?php echo htmlspecialchars($row['user']); ?></td>
							<td><?php echo $row['email'] !== '' ? htmlspecialchars($row['email']) : '<span class="text-muted">&mdash;</span>'; ?></td>
							<td><?php echo $row['group'] !== '' ? htmlspecialchars($row['group']) : '<span class="text-muted">'._('Not set').'</span>'; ?></td>
							<td>
								<?php echo htmlspecialchars($row['frequency']); ?>
								<?php if ($row['frequencyOverride']) { ?>
									<span class="label label-info"><?php echo _('override'); ?></span>
								<?php } else { ?>
									<span class="label label-default"><?php echo _('default'); ?></span>
								<?php } ?>
							</td>
							<td>
								<?php if ($row['lastSync']) { ?>
									<?php echo htmlspecialchars(date('Y-m-d H:i', (int) $row['lastSync'])); ?>
								<?php } else { ?>
									<span class="text-muted"><?php echo _('Never'); ?></span>
								<?php } ?>
							</td>
							<td>
								<span class="label label-<?php echo $statusClass($row['status'], $row['enabled']); ?>">
									<?php
									if (!$row['enabled']) {
										echo _('Disabled');
									} else {
										echo htmlspecialchars($row['status'] !== '' ? ucfirst($row['status']) : _('Pending'));
									}
									?>
								</span>
								<?php if ($row['status'] === 'error' && $row['message'] !== '') { ?>
									<i class="fa fa-exclamation-triangle text-danger" title="<?php echo htmlspecialchars($row['message'], ENT_QUOTES); ?>"></i>
								<?php } ?>
							</td>
							<td class="text-right">
								<button type="button" class="btn btn-default btn-xs gcs-syncnow" data-uid="<?php echo (int) $row['uid']; ?>"><i class="fa fa-refresh"></i> <?php echo _('Sync now'); ?></button>
								<button type="button" class="btn btn-danger btn-xs gcs-disconnect" data-uid="<?php echo (int) $row['uid']; ?>" data-user="<?php echo htmlspecialchars($row['user'], ENT_QUOTES); ?>"><i class="fa fa-unlink"></i> <?php echo _('Disconnect'); ?></button>
							</td>
						</tr>
					<?php } ?>
				<?php } ?>
			</tbody>
		</table>
	</div>
</div>

<script type="text/javascript">
	(function($) {
		var ajaxUrl = 'ajax.php?module=googlecontactsync';

		function reloadUsers() {
			window.location.href = 'config.php?display=googlecontactsync&tab=users';
		}

		function setBusy($btn, busy) {
			$btn.prop('disabled', busy);
			$btn.closest('tr').find('button').prop('disabled', busy);
		}

		$(document).on('click', '.gcs-syncnow', function() {
			var $btn = $(this);
			var uid = $btn.data('uid');
			setBusy($btn, true);
			$.post(ajaxUrl + '&command=syncnow', { uid: uid }, function(resp) {
				if (resp && resp.message) {
					alert(resp.message);
				}
				reloadUsers();
			}, 'json').fail(function() {
				alert(<?php echo json_encode(_('The request failed. Please try again.')); ?>);
				setBusy($btn, false);
			});
		});

		$(document).on('click', '.gcs-disconnect', function() {
			var $btn = $(this);
			var uid = $btn.data('uid');
			var user = $btn.data('user');
			if (!window.confirm(<?php echo json_encode(_('Disconnect the Google account for')); ?> + ' ' + user + '?')) {
				return;
			}
			setBusy($btn, true);
			$.post(ajaxUrl + '&command=disconnect', { uid: uid }, function(resp) {
				if (resp && resp.message) {
					alert(resp.message);
				}
				reloadUsers();
			}, 'json').fail(function() {
				alert(<?php echo json_encode(_('The request failed. Please try again.')); ?>);
				setBusy($btn, false);
			});
		});
	})(jQuery);
</script>
