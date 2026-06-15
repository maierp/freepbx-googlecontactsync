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
 * @var array<int,string>              $userOptions
 * @var int|null                       $filterUid
 * @var string|null                    $filterStatus
 * @var int                            $page
 * @var int                            $totalPages
 * @var int                            $total
 */
$pageUrl = function ($targetPage) use ($filterUid, $filterStatus) {
	$params = array('display' => 'googlecontactsync', 'tab' => 'logs', 'logs_page' => (int) $targetPage);
	if ($filterUid !== null) {
		$params['logs_uid'] = (int) $filterUid;
	}
	if ($filterStatus !== null) {
		$params['logs_status'] = $filterStatus;
	}
	return 'config.php?'.http_build_query($params);
};
?>
<div class="fpbx-container">
	<div class="display full-border">
		<form method="get" action="config.php" class="form-inline gcs-logs-filter" style="margin-bottom:15px;">
			<input type="hidden" name="display" value="googlecontactsync">
			<input type="hidden" name="tab" value="logs">
			<div class="form-group">
				<label for="logs_uid"><?php echo _('User'); ?></label>
				<select class="form-control" id="logs_uid" name="logs_uid">
					<option value=""><?php echo _('All users'); ?></option>
					<?php foreach ($userOptions as $uid => $label) { ?>
						<option value="<?php echo (int) $uid; ?>"<?php echo ($filterUid !== null && (int) $filterUid === (int) $uid) ? ' selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
					<?php } ?>
				</select>
			</div>
			<div class="form-group">
				<label for="logs_status"><?php echo _('Status'); ?></label>
				<select class="form-control" id="logs_status" name="logs_status">
					<option value=""><?php echo _('All'); ?></option>
					<option value="ok"<?php echo $filterStatus === 'ok' ? ' selected' : ''; ?>><?php echo _('OK'); ?></option>
					<option value="error"<?php echo $filterStatus === 'error' ? ' selected' : ''; ?>><?php echo _('Error'); ?></option>
				</select>
			</div>
			<button type="submit" class="btn btn-default"><i class="fa fa-filter"></i> <?php echo _('Filter'); ?></button>
			<a href="config.php?display=googlecontactsync&amp;tab=logs" class="btn btn-link"><?php echo _('Reset'); ?></a>

			<div class="pull-right form-inline">
				<label for="gcs_clear_days"><?php echo _('Clear logs older than'); ?></label>
				<div class="input-group">
					<input type="number" class="form-control" id="gcs_clear_days" min="0" step="1" value="30" style="width:90px;">
					<span class="input-group-addon"><?php echo _('days'); ?></span>
				</div>
				<button type="button" class="btn btn-warning" id="gcs-clear-logs"><i class="fa fa-trash"></i> <?php echo _('Clear old logs'); ?></button>
			</div>
		</form>

		<table class="table table-striped" id="gcs-logs-table">
			<thead>
				<tr>
					<th><?php echo _('When'); ?></th>
					<th><?php echo _('User'); ?></th>
					<th><?php echo _('Status'); ?></th>
					<th class="text-right"><?php echo _('Added'); ?></th>
					<th class="text-right"><?php echo _('Updated'); ?></th>
					<th class="text-right"><?php echo _('Deleted'); ?></th>
					<th><?php echo _('Message'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if (empty($rows)) { ?>
					<tr>
						<td colspan="7" class="text-center text-muted"><?php echo _('No log entries match the current filter.'); ?></td>
					</tr>
				<?php } else { ?>
					<?php foreach ($rows as $row) {
						$when = $row['finished'] > 0 ? $row['finished'] : $row['started'];
					?>
						<tr>
							<td><?php echo $when > 0 ? htmlspecialchars(date('Y-m-d H:i:s', (int) $when)) : '<span class="text-muted">&mdash;</span>'; ?></td>
							<td><?php echo $row['user'] !== '' ? htmlspecialchars($row['user']) : '<span class="text-muted">&mdash;</span>'; ?></td>
							<td>
								<?php if ($row['status'] === 'error') { ?>
									<span class="label label-danger"><?php echo _('Error'); ?></span>
								<?php } elseif ($row['status'] === 'ok') { ?>
									<span class="label label-success"><?php echo _('OK'); ?></span>
								<?php } else { ?>
									<span class="label label-default"><?php echo htmlspecialchars($row['status']); ?></span>
								<?php } ?>
							</td>
							<td class="text-right"><?php echo (int) $row['added']; ?></td>
							<td class="text-right"><?php echo (int) $row['updated']; ?></td>
							<td class="text-right"><?php echo (int) $row['deleted']; ?></td>
							<td><?php echo $row['message'] !== '' ? htmlspecialchars($row['message']) : ''; ?></td>
						</tr>
					<?php } ?>
				<?php } ?>
			</tbody>
		</table>

		<?php if ($total > 0) { ?>
			<div class="row">
				<div class="col-md-6">
					<p class="text-muted"><?php echo sprintf(_('Showing page %d of %d (%d entries).'), (int) $page, (int) $totalPages, (int) $total); ?></p>
				</div>
				<div class="col-md-6 text-right">
					<nav>
						<ul class="pagination" style="margin:0;">
							<li class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">
								<?php if ($page <= 1) { ?>
									<span>&laquo;</span>
								<?php } else { ?>
									<a href="<?php echo htmlspecialchars($pageUrl($page - 1)); ?>">&laquo;</a>
								<?php } ?>
							</li>
							<li class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
								<?php if ($page >= $totalPages) { ?>
									<span>&raquo;</span>
								<?php } else { ?>
									<a href="<?php echo htmlspecialchars($pageUrl($page + 1)); ?>">&raquo;</a>
								<?php } ?>
							</li>
						</ul>
					</nav>
				</div>
			</div>
		<?php } ?>
	</div>
</div>

<script type="text/javascript">
	(function($) {
		$(document).on('click', '#gcs-clear-logs', function() {
			var $btn = $(this);
			var days = parseInt($('#gcs_clear_days').val(), 10);
			if (isNaN(days) || days < 0) {
				days = 0;
			}
			var prompt = days > 0
				? <?php echo json_encode(_('Delete log entries older than the selected number of days?')); ?>
				: <?php echo json_encode(_('Delete ALL log entries? This cannot be undone.')); ?>;
			if (!window.confirm(prompt)) {
				return;
			}
			$btn.prop('disabled', true);
			$.post('ajax.php?module=googlecontactsync&command=clearlogs', { days: days }, function(resp) {
				if (resp && resp.message) {
					alert(resp.message);
				}
				window.location.href = 'config.php?display=googlecontactsync&tab=logs';
			}, 'json').fail(function() {
				alert(<?php echo json_encode(_('The request failed. Please try again.')); ?>);
				$btn.prop('disabled', false);
			});
		});
	})(jQuery);
</script>
