-- Multi-family migration template
-- IMPORTANT:
-- 1) Review the actual table names in your DB before running this script.
-- 2) Run this in a staging/local copy first.
-- 3) Backup the production DB before any schema change.

-- -----------------------------------------------------------------------------
-- 1) Create family table
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS families (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_families_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- 2) Add family_id to parent and child records
-- -----------------------------------------------------------------------------
ALTER TABLE parent_users
    ADD COLUMN family_id INT UNSIGNED NULL AFTER id;

ALTER TABLE children
    ADD COLUMN family_id INT UNSIGNED NULL AFTER id;

-- -----------------------------------------------------------------------------
-- 3) Add indexes
-- -----------------------------------------------------------------------------
ALTER TABLE parent_users
    ADD INDEX idx_parent_users_family_id (family_id);

ALTER TABLE children
    ADD INDEX idx_children_family_id (family_id);

-- -----------------------------------------------------------------------------
-- 4) Backfill legacy data into a default family
--    If you already have a real household grouping in your data, replace this with
--    a more precise family assignment process.
-- -----------------------------------------------------------------------------
INSERT INTO families (name)
SELECT 'Default Family'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM families WHERE name = 'Default Family'
);

UPDATE parent_users
SET family_id = (
    SELECT id
    FROM families
    WHERE name = 'Default Family'
)
WHERE family_id IS NULL;

UPDATE children
SET family_id = (
    SELECT id
    FROM families
    WHERE name = 'Default Family'
)
WHERE family_id IS NULL;

-- -----------------------------------------------------------------------------
-- 5) Optional: add foreign keys after verifying all rows are mapped properly.
-- -----------------------------------------------------------------------------
-- ALTER TABLE parent_users
--     ADD CONSTRAINT fk_parent_users_family
--     FOREIGN KEY (family_id) REFERENCES families(id)
--     ON DELETE RESTRICT;
--
-- ALTER TABLE children
--     ADD CONSTRAINT fk_children_family
--     FOREIGN KEY (family_id) REFERENCES families(id)
--     ON DELETE RESTRICT;

-- -----------------------------------------------------------------------------
-- 6) Optional validation queries
-- -----------------------------------------------------------------------------
-- SELECT * FROM parent_users WHERE family_id IS NULL;
-- SELECT * FROM children WHERE family_id IS NULL;
-- SELECT COUNT(*) FROM families;
