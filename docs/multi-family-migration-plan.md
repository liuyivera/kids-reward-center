# Multi-Child / Multi-Family Migration Plan

## Context: local auth.php vs production auth.php

There are two different purposes for the `auth.php` file depending on environment:

- Local MAMP development: the version in the project root is intended for the local database and service setup on the developer machine.
- Production HostGator deployment: the backup copy of `auth.php` is intentionally configured for the live host environment, with production-safe settings and runtime assumptions.

These are not meant to be treated as the same file with the same credentials or runtime paths. The safe pattern is to keep environment-specific configuration in a shared loader (for example `includes/config.php`) and keep local-only secrets outside the Git repo.

Example rule:

- `includes/config.php` loads values from `includes/config.local.php` or environment variables when present.
- Local secrets remain local-only and are not committed.
- Production credentials live on the live environment and are not shared in source control.

This distinction matters for migration work because the codebase should be environment-agnostic, while the auth settings remain environment-specific.

---

## Architecture audit summary

### What is already strong

- The app is mostly data-driven for child records, rewards, and redemption flows.
- `children`, `rewards`, and `redemptions` are already structured around `child_id`, which is a good base for supporting more children.
- Parent auth is enforced via server-side checks (`includes/auth.php` and route guards), which is the right foundation for adding family-level ownership checks.
- The existing reward flow already treats the child as part of the reward request and the approval path, which supports multi-child logic if routed correctly.

### What is currently blocking full multi-child / multi-family support

1. Hard-coded per-child shop pages
   - `shop-minecraft.php` and `shop-music.php` each assume a fixed child id (`1` and `2`).
   - This is the clearest sign that the app currently expects a small, fixed set of children and does not yet support dynamic routing.

2. Missing family ownership boundary
   - Parents and children need an explicit family relationship so a parent from Family A cannot manage a child in Family B.
   - At present, the code has session auth, but not a clearly centralized family-scope check for all write operations.

3. Theme/route assumptions
   - Some flows still assume a per-theme file or a fixed theme route rather than a generic child-based route.
   - This should be replaced by dynamic page loading based on child id or slug.

4. No explicit multi-family model yet
   - There is no `families` table or `family_id` relationship layer to separate records cleanly by household.

---

## Recommended migration strategy

### Phase 0 — Preparation and safe git setup

Before major function changes, set up a clean Git workflow:

- Initialize or confirm a Git repo.
- Add `.gitignore` entries for local config files and other secret or generated files.
- Add `includes/config.example.php` as the committed template.
- Create a branch strategy:
  - `main` for production-safe/stable code
  - `staging` for validation
  - feature branches for each major change set
- Keep environment secrets out of Git completely.

This is the safest starting point because it makes the app changes reviewable and reversible.

### Phase 1 — Add family model in the database

Add a family layer before reworking the app logic:

- `families` table
  - `id`
  - `name`
  - `created_at`
- Add `family_id` to `parents`
- Add `family_id` to `children`
- Backfill existing records into a default family or the active household grouping
- Add indexes on `children.family_id` and `parents.family_id`

Optional but recommended:

- Add `parent_role` or `family_role` later if different guardian roles are needed
- Add a per-family settings table if the app grows beyond a basic household model

### Phase 2 — Replace hard-coded child pages with dynamic routing

Replace the fixed shop pages with a single dynamic route, for example:

- `shop.php?child_id=123`
- or `shop.php?child_slug=alex`

Implementation goals:

- Resolve the child from database
- Load that child’s theme (`minecraft`, `music`, etc.)
- Load its rewards and redemption status
- Keep the route generic instead of hard-coded to one child or one theme

If the current site needs a simple interim step, a compatibility layer can redirect older routes to the correct child id while the app moves to a single dynamic flow.

### Phase 3 — Centralize ownership and parent access checks

Add a shared function such as:

- `verify_parent_child(parent_id, child_id)`
- `verify_parent_family(parent_id, family_id)`

Use this in every write endpoint:

- reward request
- reward approval
- point recording
- child management actions
- parent dashboard filters

This prevents one family from acting on another family’s child records.

### Phase 4 — Validate UI and reporting at scale

After the logic is dynamic and safe, update UI flows:

- child list on the home page is already data-driven and should work naturally
- reward pages should not assume a single static theme page
- parent dashboard should show per-family and per-child information clearly
- reports should aggregate by child and by family as needed

### Phase 5 — Production rollout and monitoring

Roll out only after:

- staging validation
- DB migration testing
- backup/recovery validation
- smoke tests on the live environment using non-production data or a staging snapshot

Then deploy the app update and monitor:

- parent access errors
- child reward flows
- duplicate reward submissions
- auth/session issues

---

## Recommended sequencing: app changes vs GitHub setup

The safest order is:

1. Set up Git repo and branch workflow first
   - repo structure
   - `.gitignore`
   - `config.example.php`
   - safe backup of local env files

2. Create a staging branch and a migration branch for the major changes
   - develop/staging branch for pre-production validation
   - feature branch for the multi-family work

3. Test the DB schema changes in staging or local copy first
   - no production writes until validated

4. Make the application code changes in the feature branch
   - dynamic child routing
   - family ownership checks
   - UI updates

5. Validate with a smoke test suite and manual checks
   - parent/child isolation
   - shop rendering by child
   - approval flows
   - permissions and redirects

6. Merge into staging, validate, then merge to a production release branch

7. Deploy to HostGator only after final checks

This order keeps the codebase reviewable and reduces the risk that large logic changes and environment setup get tangled together.

### Why this order is better

- It keeps credentials and environment config out of source control.
- It allows the team to test the migration on a safe branch before touching production.
- It prevents large app rewrites from being interwoven with repo setup problems.
- It gives a clean audit trail if something breaks during the migration.

In short: do the Git hygiene and branch structure first, then do the major app migration on a dedicated change branch, and only deploy after validating the schema and behavior on a staging copy.

---

## Suggested practical workflow for this project

For this codebase, I would use this sequence:

1. Confirm git repo status and create `.gitignore` / environment-template files
2. Create a dedicated `feature/multi-family` branch
3. Add the database migration scripts for `families` and `family_id`
4. Run the migration in a local or staging database
5. Update the app to be child-driven and family-aware
6. Validate the reward and approval flows
7. Merge to staging
8. Test with a nearly production-like dataset
9. Deploy to live only after the release check passes

This gives a clean path from the current app to the future multi-family architecture without losing the existing app behavior during the transition.

---

## Short recommendation

If the team wants a safe, low-drama migration, I recommend:

- keep the environment-specific `auth.php` files separate by deployment target
- keep production credentials out of Git
- use a small, explicit migration plan like the one above
- do the Git setup and branch discipline before the major app refactor

That approach gives both operational safety and a future-proof path to multi-child and multi-family support.
