-- ============================================================
-- GREENPRENEUR — Peer Unique ID System Schema & Backfill SQL
-- Date: 2026-07-22
-- Run manually on Local & Production PostgreSQL Databases.
-- DO NOT use Laravel migrations.
-- ============================================================

-- 1. Create Sequence for Peer ID (Starts at 1)
CREATE SEQUENCE IF NOT EXISTS users_peer_id_seq START WITH 1 INCREMENT BY 1 MINVALUE 1;

-- 2. Add peer_id column to users table if missing
ALTER TABLE users ADD COLUMN IF NOT EXISTS peer_id VARCHAR(50);

-- Set column default for peer_id to consume sequence automatically on direct raw SQL INSERTs
ALTER TABLE users ALTER COLUMN peer_id SET DEFAULT ('PG3182736' || nextval('users_peer_id_seq')::text);

-- 3. Idempotently populate existing users having NULL peer_id
-- Order deterministically by created_at ASC NULLS LAST, id ASC
DO $$
DECLARE
    max_existing_seq BIGINT := 0;
BEGIN
    -- Determine current highest sequence number from existing valid peer_id values
    SELECT COALESCE(MAX(CAST(SUBSTRING(peer_id FROM 10) AS BIGINT)), 0)
    INTO max_existing_seq
    FROM users
    WHERE peer_id IS NOT NULL AND peer_id ~ '^PG3182736[0-9]+$';

    -- Assign peer_id to existing users who do not have one
    WITH unassigned AS (
        SELECT id, ROW_NUMBER() OVER (ORDER BY created_at ASC NULLS LAST, id ASC) AS row_num
        FROM users
        WHERE peer_id IS NULL
    )
    UPDATE users
    SET peer_id = 'PG3182736' || (max_existing_seq + unassigned.row_num)::text
    FROM unassigned
    WHERE users.id = unassigned.id;

    -- Update sequence setval so nextval continues sequentially from max assigned ID
    SELECT COALESCE(MAX(CAST(SUBSTRING(peer_id FROM 10) AS BIGINT)), 0)
    INTO max_existing_seq
    FROM users
    WHERE peer_id IS NOT NULL AND peer_id ~ '^PG3182736[0-9]+$';

    IF max_existing_seq > 0 THEN
        PERFORM setval('users_peer_id_seq', max_existing_seq);
    END IF;
END $$;

-- 4. Enforce NOT NULL constraint on peer_id
ALTER TABLE users ALTER COLUMN peer_id SET NOT NULL;

-- 5. Create UNIQUE constraint on peer_id idempotently (PostgreSQL automatically creates unique index for constraint)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'users_peer_id_unique'
    ) THEN
        ALTER TABLE users ADD CONSTRAINT users_peer_id_unique UNIQUE (peer_id);
    END IF;
END $$;

-- ============================================================
-- VERIFICATION QUERY
-- ============================================================
SELECT id, email, peer_id, created_at
FROM users
ORDER BY created_at ASC NULLS LAST, id ASC
LIMIT 10;

