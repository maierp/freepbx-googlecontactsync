---
description: "M1 — Admin Settings tab + encrypted secret storage (TokenStore) for googlecontactsync. Store Client ID/Secret, show redirect URI, set global frequency."
name: "GCS M1: Admin settings + TokenStore"
agent: "agent"
model: "Claude Opus 4.8 (copilot)"
---
Implement **Milestone M1 (Admin settings + encryption)** from the build spec.
Read it first: [implementation spec](../../docs/googlecontactsync-implementation-spec.md)
(focus on §9 security, §11 admin page Settings tab, §13 settings methods).

## Deliverables
- `Lib/TokenStore.php` — `sodium_crypto_secretbox` encrypt/decrypt with a 256-bit
  key stored in a `0600` key file created on install (key sourcing per §9). Methods
  to encrypt/decrypt strings; tamper detection on decrypt.
- Settings persistence on the main class (§13): `getClientId`, `setCredentials`
  (encrypts the secret via TokenStore, stores via `setConfig`), `getRedirectUri`
  (`https://<FQDN>/ucp/index.php`), `getGlobalFrequency` / `setGlobalFrequency`.
- Admin page Settings tab: `page.googlecontactsync.php` + `views/main.php` +
  `views/settings.php` using the BMO `showPage`/`getActionBar`/`ajaxHandler`
  pattern from `contactmanager`. Fields: Client ID, Client Secret (write-only,
  shows "•••• set"), read-only Redirect URI to copy, global frequency
  (hourly/daily/weekly + time-of-day + day-of-week), "Test credentials" button.
- CSRF-protect all POSTs with FreePBX's built-in token; escape all output.

## Verification
- Saving credentials persists; the secret is stored **encrypted** (verify it is not
  plaintext in the DB/KV store) and never echoed back.
- Redirect URI renders with the server FQDN over HTTPS.
- Add a PHPUnit test in `utests/` for `TokenStore` encrypt/decrypt round-trip +
  tamper rejection.

Do not implement OAuth flow or sync yet. Report results + the encryption approach.
