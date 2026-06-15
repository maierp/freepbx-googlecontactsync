/*
 * Google Contact Sync — FreePBX module (UCP)
 *
 * Copyright (C) 2026 Dr. Patrick Maier, Softwareentwicklung Patrick Maier
 * https://www.se-pm.de — mail@se-pm.de
 *
 * Licensed under the GNU General Public License v3 or later.
 */
var GooglecontactsyncC = UCPMC.extend({
	init: function (UCP) {
		this.UCP = UCP;
	},
	poll: function (id, data) {
		return;
	},
	displayWidget: function (widget_id, dashboard_id) {
		this.bindFrequencyToggle();
		this.bindSettingsForm();
		return;
	},
	// Show only the schedule fields that apply to the chosen frequency:
	//   default/hourly → neither, daily → time, weekly → time + day-of-week.
	bindFrequencyToggle: function () {
		var $widget = $('.googlecontactsync-widget');
		if (!$widget.length) {
			return;
		}
		var toggle = function () {
			var freq = $widget.find('.gcs-frequency').val();
			$widget.find('.gcs-field-time').toggle(freq === 'daily' || freq === 'weekly');
			$widget.find('.gcs-field-dow').toggle(freq === 'weekly');
		};
		$widget.off('change.gcsfreq').on('change.gcsfreq', '.gcs-frequency', toggle);
		toggle();
	},
	// Save settings and "Sync now" via AJAX (no full page reload), reporting the
	// outcome through the standard UCP alert banner.
	bindSettingsForm: function () {
		var self = this;
		var $widget = $('.googlecontactsync-widget');
		if (!$widget.length) {
			return;
		}

		$widget.find('.gcs-settings-form').off('submit.gcs').on('submit.gcs', function (e) {
			e.preventDefault();
			var $form = $(this);
			var $btn = $form.find('button[type=submit]');
			$btn.prop('disabled', true);
			self.post({
				module: 'googlecontactsync',
				command: 'savesettings',
				token: $form.find('input[name=token]').val(),
				target_group: $form.find('[name=target_group]').val(),
				frequency: $form.find('[name=frequency]').val(),
				freq_time: $form.find('[name=freq_time]').val(),
				freq_dow: $form.find('[name=freq_dow]').val()
			}, function () {
				$btn.prop('disabled', false);
			});
		});

		$widget.find('.gcs-syncnow').off('click.gcs').on('click.gcs', function (e) {
			e.preventDefault();
			self.runSync($widget, $(this), 'syncnow');
		});

		$widget.find('.gcs-fullsync').off('click.gcs').on('click.gcs', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var confirmMsg = $btn.data('confirm');
			if (confirmMsg && !window.confirm(confirmMsg)) {
				return;
			}
			self.runSync($widget, $btn, 'fullsync');
		});
	},
	// Post a sync command and refresh the last-sync / last-error display.
	runSync: function ($widget, $btn, command) {
		var self = this;
		$btn.prop('disabled', true);
		self.post({
			module: 'googlecontactsync',
			command: command,
			token: $btn.data('token')
		}, function () {
			$btn.prop('disabled', false);
		}, function (resp) {
			if (resp && typeof resp.lastSync !== 'undefined') {
				$widget.find('.gcs-last-sync').text(resp.lastSync);
			}
			if (resp && typeof resp.lastError !== 'undefined') {
				self.renderLastError($widget, resp.lastError);
			}
		});
	},
	// Replace the persistent "last sync error" box. Empty string clears it.
	renderLastError: function ($widget, message) {
		var $box = $widget.find('.gcs-last-error');
		if (!message) {
			$box.empty();
			return;
		}
		var $alert = $('<div class="alert alert-danger"></div>');
		$('<strong></strong>').text(_('Last sync error:')).appendTo($alert);
		$alert.append(document.createTextNode(' ' + message));
		$box.empty().append($alert);
	},
	post: function (data, always, done) {
		$.post(UCP.ajaxUrl, data, function (resp) {
			if (resp && resp.status) {
				UCP.showAlert(resp.message, 'success');
			} else {
				UCP.showAlert((resp && resp.message) ? resp.message : _('Something went wrong. Please try again.'), 'danger');
			}
			if (typeof done === 'function') {
				done(resp);
			}
		}).fail(function () {
			UCP.showAlert(_('An unknown error occurred.'), 'danger');
		}).always(function () {
			if (typeof always === 'function') {
				always();
			}
		});
	}
});
