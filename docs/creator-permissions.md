# Creator-only Permissions Model

## Purpose

This application treats every family parent as a normal member of the household. There is no family-level admin/member role distinction for the end-user experience.

Instead, the application distinguishes between:

- member-level access: normal family parent account
- creator-level access: application owner / system maintainer

This model is intentional. It keeps family members simple while reserving high-risk operations for the creator/maintainer who controls the app itself.

## Core rule

The creator is not a special family administrator. The creator is a system-level superuser.

This means:

- ordinary parents can manage their own family and children within the family boundary
- the creator can perform system-level maintenance that regular members should never access
- the permission check is enforced on the server side, not in the browser UI

## Why this design

A family-level admin/member model is useful for multi-parent households, but it is not the right fit when the goal is: "I, as the app owner, am the only person who can perform platform-level operations."

In this project, the creator is conceptually similar to:

- app owner
- system maintainer
- developer / operator

They are not a household manager in the same way as a normal parent user.

## Security model

### Member permissions

A normal parent user may:

- log in to the parent dashboard
- record points for children in their own family
- manage their household children
- update household settings for their family
- add or remove family parent accounts as allowed by the app flow

A normal member may not:

- access creator-only system pages
- change creator flags
- access global app statistics
- perform maintenance actions across families
- bypass family-level boundary checks

### Creator permissions

A creator may perform:

- system overview checks
- global platform maintenance
- creator-only admin operations
- account repair and cross-family instrumentation
- app-level resets that are unsafe for ordinary family members

The creator permission is enforced by backend checks such as:

- `isAppCreator()`
- `requireAppCreator()`

These functions are the final authority and must not be replaced by UI-only logic.

## Implementation details

The current implementation uses a database flag on the parent account:

- `parent_users.is_creator`

The app owner account is assigned this flag to identify the creator.

In addition, the configuration layer may also define:

- `APP_CREATOR_PARENT_ID`
- `APP_CREATOR_PARENT_IDS`

These are optional safety hooks for code-level configuration and deployment-specific overrides.

The current project enforces the rule in the backend with a function like:

- `requireAppCreator()`

This performs the final access check and exits with an HTTP 403 if the current user is not the creator.

## Frontend vs backend

The frontend may hide or show creator-only controls, but that is only a UX convenience.

The real boundary is backend enforcement.

That means:

- a member user should not see the creator admin page
- even if they manually access the URL, the server must deny access
- any creator-only action must validate permission before performing the database change

## Expected behavior

A normal member flow should look like this:

- login as family parent
- access own family dashboard
- manage own household
- no creator-only features visible

A creator flow should look like this:

- login as creator account
- access creator-only tools
- review app-wide stats
- perform maintenance safely

## Operational guidance

The creator account should remain:

- minimal in number
- intentionally controlled
- protected by backend checks
- not exposed as a family admin role

This keeps the app simple:

- the household model remains family-scoped
- the creator model remains app-scoped

## Summary

The app currently uses a clean separation:

- family members: normal household users
- creator: system owner / runtime maintainer

This is the correct fit for the project because the creator is a developer/operator concern, not a family-management role.
