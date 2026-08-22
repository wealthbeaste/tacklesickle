-- Screening Requests table
-- Public appointment requests for sickle cell screening

CREATE TABLE IF NOT EXISTS screening_requests (
    id SERIAL PRIMARY KEY,
    full_name VARCHAR(200) NOT NULL,
    phone VARCHAR(50),
    email VARCHAR(254),
    gender VARCHAR(20) CHECK (gender IN ('male', 'female', 'other')),
    district VARCHAR(100),
    preferred_date DATE,
    preferred_location VARCHAR(200),
    notes TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'confirmed', 'completed', 'cancelled')),
    participant_id INTEGER REFERENCES participants(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_screening_requests_status ON screening_requests(status);
CREATE INDEX IF NOT EXISTS idx_screening_requests_created_at ON screening_requests(created_at DESC);
