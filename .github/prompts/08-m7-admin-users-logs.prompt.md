---
description: "M7 — Admin Users + Logs tabs for googlecontactsync: per-user connection status table with Sync now/Disconnect, and a paginated sync log viewer with filters."
name: "GCS M7: Admin Users + Logs tabs"
agent: "agent"
model: "Claude Opus 4.8 (copilot)"
---
Implement **Milestone M7 (Admin Users + Logs tabs)** from the build spec.
Read it first: [implementation spec](../../docs/googlecontactsync-implementation-spec.md)
(focus on §11 admin page tabs Users + Logs).

## Deliverables
- `views/users.php` — table of `googlecontactsync_accounts`: user, Google email,
  target group, effective frequency, last sync, status. Row actions: **Sync now**
  (runs `--uid`), **Disconnect** (revoke + clear, reuses M2 `disconnect`).
- `views/logs.php` — paginated `googlecontactsync_logs` (status, added/updated/
  deleted, message); filter by user and status; "Clear old logs" action.
- Wire both tabs into `views/main.php` + `ajaxHandler` on the main class.
- Escape all output; CSRF-protect actions with the FreePBX token; gate behind admin auth.

## Verification
- Users tab lists connected accounts with accurate last-sync/status.
- Sync now and Disconnect work from the admin page.
- Logs tab paginates and filters; never displays tokens/secrets (redaction holds).

Report the tabs + verification results.
