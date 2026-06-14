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
- A Google Cloud project with the People API enabled and OAuth Web credentials.

## License

GPLv3+ — see [LICENSE](LICENSE).

## Author

Dr. Patrick Maier — Softwareentwicklung Patrick Maier
Web: <https://www.se-pm.de> · Email: <mail@se-pm.de>

© 2026 Dr. Patrick Maier
