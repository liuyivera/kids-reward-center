# Family Points

A PHP + MySQL family rewards app for tracking children’s points, managing rewards, and supporting family-scoped access.

## Current user flow

See the project flow document here:

- `docs/current-user-flow.md`

## Core concept

This app is designed around a family-first model:

- Parents log in to manage their household
- Children access their own page through a unique access code/link
- Family data is isolated by `family_id`
- The creator can use developer-only family switching for testing and debugging

## Key pages

- `landing.php` – public landing page, no household data shown
- `parent-login.php` – parent login
- `parent-dashboard.php` – family management dashboard
- `index.php` – family view for logged-in parents
- `shop.php` – child-specific rewards page, protected by valid access code or parent login
- `family-settings.php` – family settings page
- `creator-admin.php` – creator-only maintenance tools

## Security model

- Family data is protected by `family_id`
- Child access uses a per-child access code/link rather than per-child login accounts
- Parent pages require valid parent session
- Creator-only tools remain separate from normal family access

## Local setup

1. Start MAMP / Apache / MySQL.
2. Import the database schema if needed.
3. Confirm the database configuration in:
   - `includes/config.php`
   - `includes/config.local.php`
4. Open the project in the browser using the local MAMP URL.

## Development notes

- The app is PHP procedural code with PDO and MySQL.
- The current architecture favors family boundaries before deeper multi-parent role complexity.
- Public pages should not expose any family-specific data.

## Related docs

- `docs/current-user-flow.md`
- `docs/multi-family-migration-plan.md`
- `docs/creator-permissions.md`
- `docs/creator-admin-ops.md`
