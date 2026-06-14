---
description: "Context primer for any Google Contact Sync build session. Run first in a fresh chat to load the spec and conventions before a milestone prompt."
name: "GCS: Session Primer"
argument-hint: "Optionally name the milestone you are about to work on"
agent: "agent"
model: "Claude Opus 4.8 (copilot)"
---
You are building the FreePBX module **`googlecontactsync`** (Google People API →
Contact Manager importer). Before doing anything, load and internalize the
authoritative build spec and the conventions it references.

## Required reading (read these in full now)
- The build spec: [implementation spec](../../docs/googlecontactsync-implementation-spec.md)
- The reference module conventions: `/var/www/html/admin/modules/contactmanager`
  (class `Contactmanager.class.php`, its `module.xml`, `composer.json`, `ucp/`).

## Ground rules for every session
- Target **FreePBX 17 / PHP 8.2**, BMO pattern (`FreePBX\modules`,
  `FreePBX_Helpers implements BMO`).
- **Never** write SQL directly into `contactmanager_*` tables — use the public
  Contact Manager methods listed in spec §3.1 via `$this->FreePBX->Contactmanager`.
- All DB access via **PDO prepared statements**; escape all view output.
- Apply the **security requirements in spec §9** (encrypted tokens/secret, CSRF
  state, least-privilege scope, no secret leakage, IDOR protection).
- Follow `contactmanager` code style (tabs, namespaces, method naming).
- Do only what the current milestone asks. Do not scaffold future milestones.

Confirm you have read the spec, then summarize in 3–5 bullets the architecture and
the milestone you are about to implement. Then stop and wait, OR proceed if a
milestone task was included with this prompt.
