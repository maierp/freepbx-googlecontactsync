# Google Contact Sync (FreePBX module)

A FreePBX 17 module that imports each user's Google Contacts into the FreePBX
**Contact Manager** via the **Google People API**. Users authorize their own Google
account from UCP (OAuth 2.0), choose a target contact group, and contacts are
synchronized automatically on a configurable schedule (hourly / daily / weekly) with
incremental updates and deletion mirroring.

> **Status:** Specification & build-plan stage. The implementation is built milestone
> by milestone following the spec in [`docs/`](docs/googlecontactsync-implementation-spec.md).

## Features (planned)

- Per-user OAuth 2.0 (authorization-code flow) from UCP — one Google account per user.
- User selects the target Contact Manager group (private or external).
- Incremental sync via People API `syncToken`; mirrors updates **and** deletions.
- Imports names (incl. honorific), phone numbers, emails, company/job title,
  addresses, websites and photo/avatar.
- Admin page for Google OAuth credentials, default frequency, per-user status and logs.
- Scheduled sync via `fwconsole` + cron; per-user frequency override.

## Documentation

- [Implementation specification](docs/googlecontactsync-implementation-spec.md) —
  authoritative architecture, data model, OAuth flow, sync engine and build plan.
- Build prompts for GitHub Copilot live in [`.github/prompts/`](.github/prompts).

## Requirements

- FreePBX 17 / PHP 8.2
- Modules: `contactmanager`, `userman`
- A **public HTTPS FQDN** for your PBX (Google will not redirect to a bare IP
  address or to a plain `http://` URL).
- A Google Cloud project with the People API enabled and OAuth Web credentials —
  see [Setting up the Google OAuth app](#setting-up-the-google-oauth-app) below.

## Setting up the Google OAuth app

The module does **not** ship with any Google credentials. As the FreePBX
administrator you create your **own** Google Cloud OAuth application once, and your
users then authorize their individual Google accounts against it from UCP.

You will move two values — the **Client ID** and **Client Secret** — from Google
Cloud into the module's **Settings** tab, and one value — the **Redirect URI** —
from the module into Google Cloud.

### 1. Create (or select) a Google Cloud project

1. Go to <https://console.cloud.google.com>.
2. In the project picker (top bar), click **New Project** (or select an existing
   one). Give it a name such as `FreePBX Contact Sync` and click **Create**.

### 2. Enable the People API

1. Navigate to **APIs & Services → Library**.
2. Search for **Google People API**, open it and click **Enable**.

### 3. Configure the OAuth consent screen

1. Go to **APIs & Services → OAuth consent screen**.
2. Choose **User type → External** and click **Create**.
3. Fill in the required fields:
   - **App name** (e.g. `FreePBX Contact Sync`)
   - **User support email**
   - **Developer contact email**
4. On the **Scopes** step, click **Add or remove scopes** and add:
   ```
   https://www.googleapis.com/auth/contacts.readonly
   ```
   This read-only scope is the least privilege required to import contacts.
5. On the **Test users** step, add the Google accounts that are allowed to connect
   while the app is in *Testing* mode (add each user who will use the feature). If
   you instead **Publish** the app, any Google account may connect, but Google may
   require app verification for sensitive scopes.

### 4. Create OAuth client credentials

1. Go to **APIs & Services → Credentials**.
2. Click **Create credentials → OAuth client ID**.
3. **Application type → Web application**. Give it a name, e.g. `FreePBX UCP`.
4. Under **Authorized redirect URIs**, click **Add URI** and paste the exact value
   shown on the module's **Settings** tab — it looks like:
   ```
   https://<your-pbx-fqdn>/ucp/index.php
   ```
   > Copy it from the Settings tab rather than typing it by hand; it must match
   > character-for-character (including `https://` and the trailing `/ucp/index.php`).
5. Click **Create**. Google shows your **Client ID** and **Client Secret** — keep
   this dialog open for the next step.

### 5. Copy the credentials into FreePBX

1. In the FreePBX admin GUI open **Admin → Google Contact Sync → Settings**.
2. Paste the **Client ID** from Google into the **Client ID** field.
3. Paste the **Client Secret** from Google into the **Client Secret** field. The
   secret is stored encrypted and is never displayed again — the field simply shows
   that a secret is set.
4. Confirm the **Redirect URI** shown on this tab matches the one you registered in
   step 4 (copy it back into Google Cloud if you changed your FQDN).
5. Optionally set the **default sync frequency**.
6. Click **Submit**.

### 6. Users connect their accounts

Each user opens **UCP → Google Contact Sync**, clicks **Connect Google Account**,
signs in to Google and grants access. After consent they are returned to UCP showing
**Connected as &lt;their email&gt;**. Their contacts are then imported on the schedule
you configured (per-user overrides are available in UCP).

> **Troubleshooting**
> - *redirect_uri_mismatch*: the URI in Google Cloud does not exactly match the one
>   on the Settings tab. Re-copy it from the Settings tab.
> - *Access blocked / app not verified*: the connecting Google account is not listed
>   as a test user (Testing mode), or the app needs verification (Published mode).
> - *Connect button disabled / HTTPS error*: the module refuses to start OAuth unless
>   the request is over HTTPS. Ensure your PBX is reachable via `https://<FQDN>`.

## License

GPLv3+ — see [LICENSE](LICENSE).

## Author

Dr. Patrick Maier — Softwareentwicklung Patrick Maier
Web: <https://www.se-pm.de> · Email: <mail@se-pm.de>

© 2026 Dr. Patrick Maier
