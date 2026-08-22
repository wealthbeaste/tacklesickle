-- Auth & RBAC Migration
-- Adds: last_login_at, sessions table, review fields, role normalization

-- 1. Drop old CHECK constraint on role, then update values
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tsca_users_role_check') THEN
        ALTER TABLE tsca_users DROP CONSTRAINT tsca_users_role_check;
    END IF;
END $$;

-- 2. Add last_login_at to tsca_users
ALTER TABLE tsca_users ADD COLUMN IF NOT EXISTS last_login_at TIMESTAMPTZ;

-- 3. Normalize roles: admin -> ADMINISTRATOR, data_entry -> DATA_ENTRY, supervisor -> SUPERVISOR
UPDATE tsca_users SET role = 'ADMINISTRATOR' WHERE role IN ('admin', 'ADMINISTRATOR');
ALTER TABLE tsca_users ALTER COLUMN role TYPE VARCHAR(30);
ALTER TABLE tsca_users ADD CONSTRAINT tsca_users_role_check CHECK (role IN ('DATA_ENTRY', 'SUPERVISOR', 'ADMINISTRATOR'));

-- 4. Add status column (ACTIVE/DISABLED) - is_active already exists, adding explicit status
ALTER TABLE tsca_users ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'DISABLED'));
UPDATE tsca_users SET status = 'ACTIVE' WHERE is_active = true;
UPDATE tsca_users SET status = 'DISABLED' WHERE is_active = false;

-- 4. Sessions/tokens table
CREATE TABLE IF NOT EXISTS user_sessions (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES tsca_users(id) ON DELETE CASCADE,
    token VARCHAR(128) NOT NULL UNIQUE,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_token ON user_sessions(token);
CREATE INDEX IF NOT EXISTS idx_sessions_user ON user_sessions(user_id);

-- 5. Add review fields to screenings
ALTER TABLE screenings ADD COLUMN IF NOT EXISTS review_status VARCHAR(20) NOT NULL DEFAULT 'PENDING' CHECK (review_status IN ('PENDING', 'REVIEWED', 'VALIDATED', 'REJECTED'));
ALTER TABLE screenings ADD COLUMN IF NOT EXISTS reviewed_by INTEGER REFERENCES tsca_users(id);
ALTER TABLE screenings ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMPTZ;
CREATE INDEX IF NOT EXISTS idx_screenings_review ON screenings(review_status);

-- 6. Add review fields to follow_ups
ALTER TABLE follow_ups ADD COLUMN IF NOT EXISTS review_status VARCHAR(20) NOT NULL DEFAULT 'PENDING' CHECK (review_status IN ('PENDING', 'REVIEWED', 'VALIDATED', 'REJECTED'));
ALTER TABLE follow_ups ADD COLUMN IF NOT EXISTS reviewed_by INTEGER REFERENCES tsca_users(id);
ALTER TABLE follow_ups ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMPTZ;

-- 7. Update existing admin user password hash to bcrypt for 'admin123'
-- This is the default bootstrap password
UPDATE tsca_users SET password_hash = '$2y$12$t/whrHsbBMMrwET/Ix0XHejrLHfiLIcr3KjUVhApYxkJoy18mu8C6', role = 'ADMINISTRATOR', status = 'ACTIVE' WHERE username = 'admin';
