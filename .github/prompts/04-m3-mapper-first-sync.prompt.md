---
description: "M3 — ContactMapper + first full sync for googlecontactsync: map Google Person to a Contact Manager entry and import into a user-selected/auto-created private group."
name: "GCS M3: Mapper + first sync"
agent: "agent"
model: "Claude Opus 4.8 (copilot)"
---
Implement **Milestone M3 (Mapper + first sync)** from the build spec.
Read it first: [implementation spec](../../docs/googlecontactsync-implementation-spec.md)
(focus on §3 Contact Manager API, §8.1/§8.3 People API + mapping, §8.4 step 1–4).

## Deliverables
- `Lib/ContactMapper.php` — maps a Google `Person` to the Contact Manager `$entry`
  array per the §8.3 table (display/first/last name, **title**, company, address,
  phone numbers with §8.3 type mapping, emails, websites, photo→`image`). Apply the
  skip rule (no usable name/phone/email → skip).
- `Lib/PeopleSync.php` — full sync path:
  - `people.connections.list` with `resourceName=people/me`, the `personFields`
    from §8.1, `requestSyncToken=true`, paginate via `pageToken`.
  - Resolve the target group (§8.4 step 2): use account target if valid, else
    `addGroup("Google Contacts", 'private', uid)`.
  - For each Person: map → `addEntryByGroupID(...)` via `$this->FreePBX->Contactmanager`;
    record mapping rows in `googlecontactsync_contacts` (resource_name, etag, entryid).
  - Persist `nextSyncToken`, `last_sync`, `last_status='ok'`, and a row in
    `googlecontactsync_logs` with added/updated/deleted counts.
- Wire `syncUid($uid)` on the main class to call `PeopleSync`.
- Pass `$updateContactFile=false` per entry; regenerate contact files once at end (§8.5).

## Verification
- A connected test account imports its Google contacts into the chosen/created
  private group; entries appear in Contact Manager with names, numbers, emails.
- `googlecontactsync_contacts` mapping rows + a `_logs` row are written.

Do **not** implement incremental/deletion handling or scheduling yet (M4/M5).
Add PHPUnit fixtures for `ContactMapper`. Report counts + verification results.
