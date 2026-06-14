---
description: "M4 — Incremental sync + deletions for googlecontactsync: People API syncToken persistence, deleted-contact mirroring, EXPIRED_SYNC_TOKEN recovery, full-sync deletion reconciliation."
name: "GCS M4: Incremental + deletions"
agent: "agent"
model: "Claude Opus 4.8 (copilot)"
---
Implement **Milestone M4 (Incremental sync + deletions)** from the build spec.
Read it first: [implementation spec](../../docs/googlecontactsync-implementation-spec.md)
(focus on §8.1 sync token, §8.4 full reconciliation algorithm steps 3–7, §8.5).

## Deliverables (extend `Lib/PeopleSync.php`)
- Use the stored `sync_token` when present (`syncToken=...`, `requestSyncToken=true`).
- Handle updates via `etag`: unchanged etag → skip; changed → `updateEntry(...)`.
- Handle deletions:
  - Incremental: `metadata.deleted == true` → `deleteEntryByID(entryid)` for the
    mapped resource, remove the mapping row (§8.4 step 4b).
  - Full sync: reconcile by removing mapping rows whose `resource_name` was not
    returned this run, deleting those entries (§8.4 step 5) — scoped only to rows
    this account owns (external-group safety, §8.4 note).
- On HTTP 400 `EXPIRED_SYNC_TOKEN`: clear stored token, perform a full resync +
  deletion reconciliation.
- On exception: set `last_status='error'`, write a log row, and **do not** persist a
  partial `sync_token` (§8.4 step 7).

## Verification (use a mock People API / canned responses — §15 integration)
- Edit a contact in Google → re-sync → entry updated (count `updated`).
- Delete a contact in Google → re-sync → entry removed (count `deleted`).
- Simulate `EXPIRED_SYNC_TOKEN` → full resync recovers correctly.
- Manually added contacts (not in mapping) in an external group are **not** deleted.

Add integration tests with injected fake `PeopleService` responses. Report results.
