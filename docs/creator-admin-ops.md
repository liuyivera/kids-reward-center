# Creator Admin Operations Manual

## Scope

This document defines the operational rules for the application creator account.

The application is intentionally designed so that:

- all normal family accounts are members
- the app owner is the creator / system maintainer
- family-level permissions do not include a separate admin role
- creator-only actions are enforced by backend checks, not by UI visibility alone

## Principle

The creator account is not a family administrator. It is a system-level maintenance account.

Any action that affects more than one family, more than one account, or the app runtime itself belongs in the creator/admin layer.

## What remains member-only

These actions are part of the normal family workflow and are not creator-only:

- parent login
- child add / edit / delete within a family
- family name updates
- parent family account management within the same family
- reward record and approval flows inside the same family
- child score tracking and reward shop access for the current family

## What is creator-only

These actions belong to the developer/operator layer:

- viewing global app statistics
- listing all creator accounts and app owners
- resetting creator state and ensuring one creator remains active
- performing cross-family diagnostics
- platform maintenance and support work
- app recovery or remediation during incidents

## Required enforcement

All creator-only actions must:

1. require a valid login session
2. call `requireAppCreator()` before doing any work
3. validate CSRF on POST requests
4. use family scoping for family-level operations
5. never rely on a hidden UI button as the only protection

## Creator admin page

The creator admin page is the system maintenance surface for the owner.

It should include:

- app overview counts
- creator account listing
- global health summary
- safe maintenance actions
- no family-management operations that are not explicitly allowed by the member flow

## Safe operational rules

### 1. Keep one creator only

The creator state should be intentionally restricted to the application owner account.

When resetting creator state:

- clear other creator flags
- leave the current maintainer account as the only creator
- record the action in logs if logs are added later

### 2. Do not treat creator as a household admin

The creator is not empowered to act as a family admin by default.

Even if the creator belongs to a family, the creator must still use the system-level rules for platform management.

### 3. Use family checks for family actions

Family-only operations must still use a family boundary check, even for creator actions.

This is important because a creator may be a system maintainer, but the application still needs to protect household boundaries.

### 4. Never hide sensitive actions behind client-side logic alone

Frontend controls are only UI hints.

The real enforcement is all in PHP server-side checks.

## Recommended action checklist

Use this when performing creator-level maintenance:

- confirm the current account is the creator
- confirm the target action is truly creator-level
- confirm CSRF token is present
- confirm the action is necessary and reversible
- record what was changed and why
- validate the result immediately after the action

## Example safe creator actions

- reset creator flags
- inspect global family counts
- review creator account mapping
- verify app-wide system health
- perform a maintenance reset after a data issue

## Example actions that must not be done via creator access

- changing a family member's data without family scope
- modifying a child outside the current family boundary
- acting as a household admin without family ownership validation
- bypassing the app's member-level security model for normal family operations

## Final rule

The creator account exists to operate the app, not to override the family model itself.

The family model remains protected by family-scoped checks, while the creator role remains reserved for app-level maintenance and platform integrity.
