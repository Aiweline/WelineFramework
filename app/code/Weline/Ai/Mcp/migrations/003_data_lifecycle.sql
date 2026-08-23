PRAGMA foreign_keys = ON;

ALTER TABLE sessions ADD COLUMN lifecycle_state TEXT NOT NULL DEFAULT 'active';
ALTER TABLE sessions ADD COLUMN lifecycle_generation INTEGER NOT NULL DEFAULT 1;
ALTER TABLE sessions ADD COLUMN raw_expires_at TEXT;
ALTER TABLE sessions ADD COLUMN archiving_at TEXT;
ALTER TABLE sessions ADD COLUMN archive_reason TEXT;
ALTER TABLE sessions ADD COLUMN archive_cutoff_event_id TEXT;

UPDATE sessions
SET raw_expires_at = MIN(
    COALESCE(
        strftime('%Y-%m-%dT%H:%M:%fZ', started_at, '+14 days'),
        strftime('%Y-%m-%dT%H:%M:%fZ', 'now', '+14 days')
    ),
    strftime('%Y-%m-%dT%H:%M:%fZ', 'now', '+14 days')
)
WHERE raw_expires_at IS NULL OR raw_expires_at = '';

ALTER TABLE analysis_jobs ADD COLUMN session_generation INTEGER;
ALTER TABLE analysis_jobs ADD COLUMN cancel_reason TEXT;

CREATE TABLE IF NOT EXISTS project_locations (
    project_id          TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    canonical_path      TEXT NOT NULL,
    worktree_path       TEXT,
    git_identity        TEXT NOT NULL DEFAULT '',
    is_primary          INTEGER NOT NULL DEFAULT 0 CHECK(is_primary IN (0, 1)),
    last_confirmed_at   TEXT NOT NULL,
    PRIMARY KEY(project_id, canonical_path)
);

CREATE TABLE IF NOT EXISTS session_tombstones (
    session_hash             TEXT PRIMARY KEY,
    project_id               TEXT REFERENCES projects(id) ON DELETE SET NULL,
    archive_kind             TEXT NOT NULL,
    archive_status           TEXT NOT NULL,
    final_learning_status    TEXT NOT NULL,
    archived_at              TEXT NOT NULL,
    expires_at               TEXT,
    cleared_counts_json      TEXT NOT NULL DEFAULT '{}',
    artifact_cleanup_status  TEXT NOT NULL DEFAULT 'not_required'
);

CREATE TABLE IF NOT EXISTS experience_provenance (
    experience_id       TEXT NOT NULL REFERENCES experiences(id) ON DELETE CASCADE,
    experience_version  INTEGER NOT NULL,
    project_id          TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    source_hash         TEXT NOT NULL,
    source_kind         TEXT NOT NULL,
    observation_count   INTEGER NOT NULL DEFAULT 1,
    first_seen_at       TEXT NOT NULL,
    last_seen_at        TEXT NOT NULL,
    quality_at_archive  REAL NOT NULL DEFAULT 0,
    PRIMARY KEY(experience_id, experience_version, source_hash)
);

CREATE TABLE IF NOT EXISTS experience_feedback_rollups (
    experience_id   TEXT PRIMARY KEY REFERENCES experiences(id) ON DELETE CASCADE,
    accepted_count  INTEGER NOT NULL DEFAULT 0,
    rejected_count  INTEGER NOT NULL DEFAULT 0,
    hit_count       INTEGER NOT NULL DEFAULT 0,
    stale_count     INTEGER NOT NULL DEFAULT 0,
    last_feedback_at TEXT
);

CREATE TABLE IF NOT EXISTS maintenance_state (
    task_name          TEXT PRIMARY KEY,
    cursor_json        TEXT NOT NULL DEFAULT '{}',
    lease_owner        TEXT,
    leased_until       TEXT,
    last_started_at    TEXT,
    last_completed_at  TEXT,
    last_error_code    TEXT,
    metrics_json       TEXT NOT NULL DEFAULT '{}'
);

CREATE INDEX IF NOT EXISTS idx_sessions_lifecycle_expiry
    ON sessions(lifecycle_state, raw_expires_at, id);
CREATE INDEX IF NOT EXISTS idx_analysis_jobs_session_generation
    ON analysis_jobs(session_id, session_generation, status);
CREATE INDEX IF NOT EXISTS idx_session_tombstones_expiry
    ON session_tombstones(expires_at);
CREATE INDEX IF NOT EXISTS idx_experience_provenance_experience
    ON experience_provenance(experience_id, experience_version);
CREATE INDEX IF NOT EXISTS idx_experience_provenance_source_hash
    ON experience_provenance(source_hash, experience_id);
CREATE INDEX IF NOT EXISTS idx_project_locations_preferred
    ON project_locations(project_id, is_primary, last_confirmed_at);
CREATE INDEX IF NOT EXISTS idx_audit_log_created_at
    ON audit_log(created_at);
CREATE INDEX IF NOT EXISTS idx_feedback_session
    ON feedback(session_id);
