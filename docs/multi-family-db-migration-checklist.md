# Multi-Family DB Migration Checklist

## Goal

Prepare the database for supporting multiple children per family and multiple families in the same system, while preserving compatibility with the current app behavior.

## Current assumptions to remove

- Some pages and routes are still implicitly tied to a fixed or small set of child records.
- There is no explicit family-level boundary between parent accounts and children.
- Some app logic assumes a single household context instead of a family-scoped model.

## Target data model

### Families table

Create a `families` table with the minimum useful fields:

```sql
CREATE TABLE families (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Parent accounts

Add a `family_id` column to the parent-user table (or whichever table stores parent accounts). Example:

```sql
ALTER TABLE parent_users
    ADD COLUMN family_id INT UNSIGNED NULL AFTER id;
```

If the table uses a different name than `parent_users`, replace it with the real table name in the live schema.

### Children

Add a `family_id` column to the children table:

```sql
ALTER TABLE children
    ADD COLUMN family_id INT UNSIGNED NULL AFTER id;
```

### Indexes

```sql
ALTER TABLE parent_users
    ADD INDEX idx_parent_users_family_id (family_id);

ALTER TABLE children
    ADD INDEX idx_children_family_id (family_id);
```

---

## Backfill plan

Before enabling strict rules, backfill all existing rows into a default family or into logical household groups.

### Option A: single default family for legacy records

```sql
INSERT INTO families (name, created_at)
VALUES ('Default Family', NOW());

UPDATE parent_users
SET family_id = 1
WHERE family_id IS NULL;

UPDATE children
SET family_id = 1
WHERE family_id IS NULL;
```

This is the simplest migration if the current app only has one logical family in production.

### Option B: create one family per current parent household

This is more work but better if the app already has a natural grouping of parent/children records in the database.

Example logic:

- For each parent, create a family record
- Assign that family to the parent
- Assign the same family to that parent’s children

This requires a production-safe script and verification before rollout.

---

## Optional foreign keys

If the database can support it and the data is known-good, add foreign keys after backfill:

```sql
ALTER TABLE parent_users
    ADD CONSTRAINT fk_parent_users_family
    FOREIGN KEY (family_id) REFERENCES families(id)
    ON DELETE RESTRICT;

ALTER TABLE children
    ADD CONSTRAINT fk_children_family
    FOREIGN KEY (family_id) REFERENCES families(id)
    ON DELETE RESTRICT;
```

If the project is still in a mixed legacy state, it is safer to add constraints after verifying every row is mapped correctly.

---

## App behavior changes to accompany the schema

### 1. Dynamic child routing

Replace fixed front-end routes like:

- `shop-minecraft.php`
- `shop-music.php`

with a generic route:

- `shop.php?child_id=...`

The page should load the child record by id and apply its theme or settings without hard-coded assumptions.

### 2. Family ownership checks

Use a shared function pattern:

```php
function verifyParentChild(int $parentId, int $childId): bool {
    // ensure parent exists and belongs to same family as child
}
```

And then call it for:

- reward requests
- reward approvals
- point recording
- modification of child records
- parent dashboard filtering

### 3. Family-scoped dashboard queries

Any query used by parent pages should filter by `family_id` as the primary data boundary when listing children, rewards, redemptions, and reports.

---

## Suggested migration phases

### Phase 1: Schema addition

- add `families` table
- add `family_id` columns
- add indexes
- backfill legacy rows

### Phase 2: App compatibility

- deploy app code that is safe with `family_id = NULL` and legacy rows
- keep existing UI flow working while new family logic is introduced

### Phase 3: Family enforcement

- add ownership checks and family-scoped queries
- reject actions crossing family boundaries
- log violations for review

### Phase 4: Multi-child UI cleanup

- remove hard-coded shop page assumptions
- unify theme routing
- add child listing/filtering improvements

---

## Safe rollout order

1. Back up production DB
2. Run migration in staging or local copy
3. Verify all `family_id` rows are populated
4. Deploy code that accepts both legacy and family-aware data
5. Run smoke tests for parent access and reward flows
6. Deploy to production
7. Monitor logs and access errors for the first 24–48 hours

---

## Validation checklist

Before declaring the migration complete, confirm:

- every parent has a valid `family_id`
- every child has a valid `family_id`
- no orphaned rows exist after backfill
- a parent from Family A cannot access Family B child pages
- reward requests and approvals still work for existing children
- the dynamic shop route loads the correct child and theme

---

## Notes

This plan intentionally keeps the first migration minimal and backward-compatible. The key idea is to add a family boundary without breaking the current site behavior. Once the model is stable, the app can move from fixed-child assumptions to a fully dynamic multi-child / multi-family structure.
