---
description: "M5 — Scheduling for googlecontactsync: fwconsole command (--runsync/--uid/--all/--list), cron registration in install(), per-user override + admin default due-logic."
name: "GCS M5: Scheduling + console"
agent: "agent"
model: "Claude Opus 4.8 (copilot)"
---
Implement **Milestone M5 (Scheduling)** from the build spec.
Read it first: [implementation spec](../../docs/googlecontactsync-implementation-spec.md)
(focus on §10 scheduling, §13 `runDueSyncs`).

## Deliverables
- `Console/Googlecontactsync.php` — Symfony Console command (FreePBX pattern) with
  options `--runsync` (all due accounts), `--uid=<id>` (force one), `--all` (force
  all enabled), `--list` (status table). Non-zero exit on fatal errors; print a
  per-account summary (added/updated/deleted).
- `runDueSyncs()` on the main class: iterate enabled accounts, compute the effective
  frequency (per-user override else admin global default) and run only those **due**
  based on `last_sync`:
  - hourly: `now - last_sync >= 3600`
  - daily: new day past `freq_time`, not yet synced today
  - weekly: `dow == freq_dow` past `freq_time`, not synced this week
- Register a single recurring cron entry in BMO `install()` via
  `$this->FreePBX->Cron(...)` running `fwconsole googlecontactsync --runsync` every
  **15 minutes**; remove it in `uninstall()`.

## Verification
- `fwconsole googlecontactsync --list` prints accounts + status.
- `fwconsole googlecontactsync --uid=<id>` force-syncs one user.
- `fwconsole googlecontactsync --runsync` syncs only due accounts.
- The cron entry is created on install and removed on uninstall.
- Add PHPUnit tests for the hourly/daily/weekly due-time logic.

Report the command behavior + verification results.
