---
description: "M0 — Scaffold the googlecontactsync module: module.xml, main BMO class skeleton, composer.json, install.php, LICENSE, README. Module installs and creates DB tables."
name: "GCS M0: Scaffold module"
agent: "agent"
model: "Claude Opus 4.8 (copilot)"
---
Implement **Milestone M0 (Scaffold)** from the build spec.
Read it first: [implementation spec](../../docs/googlecontactsync-implementation-spec.md)
(focus on §4 file layout, §5 module.xml, §6 composer.json, §13 class skeleton).

## Deliverables
- `module.xml` exactly as specified in §5 (rawname, depends on `contactmanager`
  and `userman`, the three `<database>` tables, `<console>`, UCP/userman hooks).
- `Googlecontactsync.class.php` — `FreePBX\modules\Googlecontactsync extends
  \FreePBX_Helpers implements \BMO`, with the method stubs from §13 (empty bodies
  where logic is not yet implemented; `install()` may be empty for now).
- `composer.json` per §6 (`google/apiclient ^2.15`, php 8.2 platform). Run
  `composer install` in the module dir and commit `vendor/`.
- `install.php` (minimal/bootstrap only — prefer BMO `install()`), `LICENSE`
  (GPLv3+), `README.md` stub. Use the publisher **Dr. Patrick Maier
  (Softwareentwicklung Patrick Maier, https://www.se-pm.de, mail@se-pm.de)** and add
  the GPLv3+ copyright header from spec §2 to the top of every PHP file.
- Reuse the structural patterns of `/var/www/html/admin/modules/contactmanager`.

## Verification
- `fwconsole ma install googlecontactsync` succeeds.
- The three tables `googlecontactsync_accounts`, `googlecontactsync_contacts`,
  `googlecontactsync_logs` exist with the columns from §5.
- The module appears in the admin menu (page can be a placeholder for now).
- No PHP fatal/lint errors.

Do **not** implement OAuth, sync, UI logic, or scheduling yet — those are later
milestones. Report what you created and the verification results.
