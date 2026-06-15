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
		return;
	}
});
