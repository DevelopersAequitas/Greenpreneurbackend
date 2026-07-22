-- ============================================================
-- GREENPRENEUR — Account Deletion Requests Schema SQL Script
-- Date: 2026-07-21
-- Run manually on Local & Production PostgreSQL Databases.
-- DO NOT use Laravel migrations.
-- ============================================================

CREATE TABLE IF NOT EXISTS account_deletion_requests (
    id UUID PRIMARY KEY,
    user_id UUID NULL,
    email VARCHAR(255) NULL,
    reason TEXT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Foreign Key Constraint (to users.id)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fk_account_deletion_requests_user'
    ) THEN
        ALTER TABLE account_deletion_requests
        ADD CONSTRAINT fk_account_deletion_requests_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
    END IF;
END $$;

-- Indexes for status, user_id, and email queries
CREATE INDEX IF NOT EXISTS idx_account_deletion_requests_user_id ON account_deletion_requests(user_id);
CREATE INDEX IF NOT EXISTS idx_account_deletion_requests_status ON account_deletion_requests(status);
CREATE INDEX IF NOT EXISTS idx_account_deletion_requests_email ON account_deletion_requests(email);

-- ============================================================
-- VERIFICATION QUERY
-- ============================================================
SELECT column_name, data_type, is_nullable
FROM information_schema.columns
WHERE table_name = 'account_deletion_requests'
ORDER BY ordinal_position;
