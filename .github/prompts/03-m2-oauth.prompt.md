---
description: "M2 — OAuth 2.0 authorization-code flow for googlecontactsync: UCP Connect button, signed state, callback handler, token exchange, encrypted persistence, id_token verification."
name: "GCS M2: OAuth connect flow"
agent: "agent"
model: "Claude Opus 4.8 (copilot)"
---
Implement **Milestone M2 (OAuth)** from the build spec.
Read it first: [implementation spec](../../docs/googlecontactsync-implementation-spec.md)
(focus on §7 OAuth flow + diagram, §9 security, §12 UCP module, §13 OAuth methods).

## Deliverables
- `Lib/GoogleClientFactory.php` — builds a configured `Google\Client` from the
  stored Client ID/Secret (decrypted), scope `contacts.readonly`,
  `access_type=offline`, `prompt=consent`, redirect URI from settings.
- Main class: `buildAuthUrl($uid)` (returns consent URL + signed single-use `state`
  bound to the UCP session uid), `handleOAuthCallback($code,$state)` (validate
  state/CSRF, exchange code, decode + **verify** `id_token` audience == client_id to
  get `google_sub`/email, encrypt + store tokens in `googlecontactsync_accounts`),
  `saveAccountTokens`, `disconnect($uid)` (revoke via Google revoke endpoint + purge).
- UCP module `ucp/Googlecontactsync.class.php`: "Connect Google Account" /
  "Disconnect" buttons; detect the OAuth callback on the UCP redirect URL
  (`?googlecontactsync=oauth&code=...&state=...`) and route it before normal UCP
  rendering. Register via the `<ucp>` hook (`ucpConfigPage`).

## Security (must)
- `state` = HMAC-signed, single-use, expiry-bound, tied to logged-in uid; reject reuse/mismatch.
- Re-derive uid from the UCP session server-side; ignore any client-supplied uid.
- Require HTTPS redirect URI; never log code/tokens.

## Verification
- A test Google account can connect from UCP; account row stores **encrypted**
  tokens + `google_sub` + email; UCP shows "Connected as <email>".
- Disconnect revokes the token and clears the row.
- Replaying an old `state` is rejected.

Do not implement contact sync yet. Report the flow + verification results.
