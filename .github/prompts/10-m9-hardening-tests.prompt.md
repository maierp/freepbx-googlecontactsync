---
description: "M9 — Hardening, i18n, tests, and signing for googlecontactsync: security review, gettext strings, PHPUnit/integration suite, and module signature prep."
name: "GCS M9: Hardening + tests + signing"
agent: "agent"
model: "Claude Opus 4.8 (copilot)"
---
Implement **Milestone M9 (Hardening + tests)** from the build spec.
Read it first: [implementation spec](../../docs/googlecontactsync-implementation-spec.md)
(focus on §9 security, §15 testing, §18 conventions).

## Deliverables
- **Security review against §9 + OWASP Top 10**: confirm encrypted tokens/secret,
  single-use signed `state`, least-privilege scope, id_token audience check, PDO
  everywhere, output escaping, IDOR protection, and **no secret/token leakage** in
  `freepbx.log`, sync logs, or UI exceptions. Fix any gaps found.
- **i18n**: wrap user-facing strings in `_()`; generate `i18n/` `.pot`.
- **Tests** (`utests/`): complete the PHPUnit suite per §15 — `ContactMapper`,
  `TokenStore`, due-time logic, and integration tests with a mocked `PeopleService`
  (add/update/delete counts, `EXPIRED_SYNC_TOKEN` recovery).
- **Signing**: ensure the module passes FreePBX signing checks; generate `module.sig`
  via the FreePBX tooling (do not hand-author it). Update `README.md` with the admin
  Google Cloud setup steps from §16.

## Verification
- `phpunit` (or `fwconsole ma utests googlecontactsync`) passes.
- A manual security pass confirms no plaintext secrets/tokens anywhere.
- The module installs/uninstalls cleanly and signs successfully.

Report the test results, the security review findings, and anything deferred to §17.
