-- TSCA Sickle Cell Screening Registry
-- Database Schema Migration

-- Users table for role-based access
CREATE TABLE IF NOT EXISTS tsca_users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(160) NOT NULL,
    role VARCHAR(20) NOT NULL CHECK (role IN ('data_entry', 'supervisor', 'admin')),
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Participants table
CREATE TABLE IF NOT EXISTS participants (
    id SERIAL PRIMARY KEY,
    tsca_id VARCHAR(32) NOT NULL UNIQUE,
    first_name VARCHAR(80) NOT NULL,
    last_name VARCHAR(80) NOT NULL,
    age INTEGER CHECK (age >= 0 AND age <= 150),
    date_of_birth DATE,
    gender VARCHAR(20) NOT NULL CHECK (gender IN ('male', 'female', 'other')),
    phone VARCHAR(40),
    national_id VARCHAR(80),
    is_minor BOOLEAN NOT NULL DEFAULT false,
    guardian_name VARCHAR(160),
    guardian_phone VARCHAR(40),
    guardian_relationship VARCHAR(80),
    district VARCHAR(100),
    sub_county VARCHAR(100),
    village VARCHAR(100),
    notes TEXT,
    created_by INTEGER REFERENCES tsca_users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Outreach events table
CREATE TABLE IF NOT EXISTS outreach_events (
    id SERIAL PRIMARY KEY,
    event_name VARCHAR(200) NOT NULL,
    district VARCHAR(100) NOT NULL,
    location VARCHAR(200),
    event_date DATE NOT NULL,
    team_lead VARCHAR(160),
    partners TEXT,
    description TEXT,
    created_by INTEGER REFERENCES tsca_users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Screening encounters table
CREATE TABLE IF NOT EXISTS screenings (
    id SERIAL PRIMARY KEY,
    participant_id INTEGER NOT NULL REFERENCES participants(id) ON DELETE CASCADE,
    event_id INTEGER REFERENCES outreach_events(id),
    screening_date DATE NOT NULL,
    screening_site VARCHAR(200),
    test_type VARCHAR(50) NOT NULL CHECK (test_type IN ('rapid_test', 'hemoglobin_electrophoresis', 'hplc', 'other')),
    result VARCHAR(20) NOT NULL CHECK (result IN ('reactive', 'non_reactive', 'AA', 'AS', 'SS', 'SC', 'unknown')),
    health_worker_name VARCHAR(160),
    health_worker_id VARCHAR(80),
    counselor_notes TEXT,
    created_by INTEGER REFERENCES tsca_users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Follow-ups and referrals table
CREATE TABLE IF NOT EXISTS follow_ups (
    id SERIAL PRIMARY KEY,
    participant_id INTEGER NOT NULL REFERENCES participants(id) ON DELETE CASCADE,
    screening_id INTEGER REFERENCES screenings(id),
    follow_up_date DATE NOT NULL,
    referral_needed BOOLEAN NOT NULL DEFAULT false,
    referral_facility VARCHAR(200),
    referral_reason TEXT,
    follow_up_status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (follow_up_status IN ('pending', 'completed', 'cancelled', 'lost_to_follow_up')),
    follow_up_outcome TEXT,
    counseling_notes TEXT,
    next_follow_up_date DATE,
    created_by INTEGER REFERENCES tsca_users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Indexes
CREATE INDEX IF NOT EXISTS idx_participants_tsca_id ON participants(tsca_id);
CREATE INDEX IF NOT EXISTS idx_participants_name ON participants(last_name, first_name);
CREATE INDEX IF NOT EXISTS idx_participants_district ON participants(district);
CREATE INDEX IF NOT EXISTS idx_screenings_participant ON screenings(participant_id);
CREATE INDEX IF NOT EXISTS idx_screenings_event ON screenings(event_id);
CREATE INDEX IF NOT EXISTS idx_screenings_date ON screenings(screening_date DESC);
CREATE INDEX IF NOT EXISTS idx_screenings_result ON screenings(result);
CREATE INDEX IF NOT EXISTS idx_follow_ups_participant ON follow_ups(participant_id);
CREATE INDEX IF NOT EXISTS idx_follow_ups_status ON follow_ups(follow_up_status);
CREATE INDEX IF NOT EXISTS idx_outreach_events_date ON outreach_events(event_date DESC);
CREATE INDEX IF NOT EXISTS idx_outreach_events_district ON outreach_events(district);

-- Seed default admin user (password: admin123 - should be changed)
-- bcrypt hash for 'admin123'
INSERT INTO tsca_users (username, password_hash, full_name, role)
VALUES ('admin', '$2y$12$t/whrHsbBMMrwET/Ix0XHejrLHfiLIcr3KjUVhApYxkJoy18mu8C6', 'System Administrator', 'admin')
ON CONFLICT (username) DO NOTHING;
