---
description: "M8 — Lifecycle cleanup for googlecontactsync: usermanDelUser/ucpDelUser purge + Google token revoke, and uninstall() removing cron and optionally imported data."
name: "GCS M8: Lifecycle & cleanup"
agent: "agent"
model: "Claude Opus 4.8 (copilot)"
---
Implement **Milestone M8 (Lifecycle)** from the build spec.
Read it first: [implementation spec](../../docs/googlecontactsync-implementation-spec.md)
(focus on §9 account deletion, §13 hooks, §10 cron removal).

## Deliverables
- `usermanDelUser($id,$display,$data)` and `ucpDelUser($id,$display,$data)` hooks:
  delete the account row + its `googlecontactsync_contacts` mappings, optionally
  remove imported entries via Contact Manager, and **revoke** the Google token
  (`https://oauth2.googleapis.com/revoke`). Register both via `module.xml` hooks.
- `uninstall()`: remove the recurring cron entry, drop module settings/keys, and
  (optionally, with care) clean up imported data. Securely delete the encryption
  key file if appropriate.
- Ensure no orphaned mappings or tokens remain after a user is deleted.

## Verification
- Deleting a userman user revokes their Google token and purges their account +
  mappings (verify token is revoked against Google).
- `fwconsole ma uninstall googlecontactsync` removes the cron entry and settings.

Report the cleanup behavior + verification results.
