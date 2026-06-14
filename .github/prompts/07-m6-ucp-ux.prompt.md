---
description: "M6 — UCP UX for googlecontactsync: target group selector (private/external), frequency override, connection status, Sync now, Disconnect."
name: "GCS M6: UCP user experience"
agent: "agent"
model: "Claude Opus 4.8 (copilot)"
---
Implement **Milestone M6 (UCP UX)** from the build spec.
Read it first: [implementation spec](../../docs/googlecontactsync-implementation-spec.md)
(focus on §12 UCP module, §9 security/IDOR).

## Deliverables (extend `ucp/Googlecontactsync.class.php` + `ucp/assets/`)
- Connection status panel: connected email, last sync time, last result.
- **Target group selector**: the user's **private** groups + an option
  "Create new private group 'Google Contacts'", plus **external** groups the user is
  permitted to use. Persist via `setAccountTarget($uid,$groupid,$type)`.
- **Frequency override**: "Use system default (<value>)" or hourly/daily/weekly
  (+ time / day). Persist via `setAccountFrequency(...)`.
- **Sync now** button → backend runs `--uid=<self>`.
- Connect / Disconnect buttons wired to M2 flow.

## Security (must)
- Re-derive uid from the UCP session server-side; reject any client-supplied uid (IDOR).
- Validate the selected group is owned by the user (private) or in their allowed
  external groups **before** saving.

## Verification
- A user can pick an existing private group or auto-create one, pick an external
  group, set a frequency override, run Sync now, and disconnect — all scoped to self.
- Attempting to target a non-owned private group is rejected server-side.

Report the UX + verification results.
