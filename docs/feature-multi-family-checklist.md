# Feature: Multi-Family / Multi-Child Migration

## Current status

- Repo initialized on `feature/multi-family`
- Documentation saved under `docs/`
- Migration SQL template created at `database/multi-family-migration.sql`
- No app logic changes made yet

## Safe workflow

1. Create or confirm a local/staging database copy
2. Run the migration script against the staging copy only
3. Verify all rows have valid `family_id` values
4. Keep legacy data working while writing compatibility code
5. Update dynamic child routing and ownership checks
6. Validate reward request/approval flows
7. Merge to staging, then deploy to production

## Immediate tasks

- Confirm actual DB table names used by the project
- Confirm whether `parent_users` is the correct parent table name
- Confirm whether children are already grouped by household or need a default family assignment
- Add basic migration validation queries

## Read-only audit notes

- `index.php` is mostly data-driven for children
- `shop-minecraft.php` and `shop-music.php` contain hard-coded child assumptions
- `request-reward.php` and `approve-reward.php` are the critical logic endpoints to protect with family-scoped checks
- `includes/auth.php` is the right place to centralize parent access checks

## Next major milestones

- [ ] Verify schema names and real DB layout
- [ ] Validate migration on a staging/local copy
- [ ] Add `families` table and `family_id` columns
- [ ] Backfill legacy records
- [ ] Add family-aware checks to parent actions
- [ ] Replace fixed shop routes with dynamic child flow
- [ ] Run smoke tests and deploy
