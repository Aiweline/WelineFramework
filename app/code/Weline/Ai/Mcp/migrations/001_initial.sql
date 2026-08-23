PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS projects (
    id                  TEXT PRIMARY KEY,
    name                TEXT NOT NULL,
    root_fingerprint    TEXT NOT NULL,
    remote_fingerprint  TEXT,
    default_branch      TEXT,
    config_json         TEXT NOT NULL DEFAULT '{}',
    created_at          TEXT NOT NULL,
    updated_at          TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS sessions (
    id                  TEXT PRIMARY KEY,
    project_id          TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    agent               TEXT NOT NULL,
    cwd                 TEXT NOT NULL,
    branch              TEXT,
    worktree            TEXT,
    base_commit         TEXT,
    head_commit         TEXT,
    dirty_at_start      INTEGER NOT NULL DEFAULT 0,
    dirty_at_end        INTEGER NOT NULL DEFAULT 0,
    status              TEXT NOT NULL,
    outcome             TEXT,
    consent_json        TEXT NOT NULL DEFAULT '{}',
    started_at          TEXT NOT NULL,
    last_activity_at    TEXT NOT NULL,
    closed_at           TEXT
);

CREATE INDEX IF NOT EXISTS idx_sessions_project_activity
    ON sessions(project_id, last_activity_at);

CREATE TABLE IF NOT EXISTS events (
    id                  TEXT PRIMARY KEY,
    schema_version      TEXT NOT NULL,
    project_id          TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    session_id          TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    turn_id             TEXT,
    episode_id          TEXT,
    event_type          TEXT NOT NULL,
    source              TEXT NOT NULL,
    role                TEXT,
    content_redacted    TEXT,
    content_hash        TEXT NOT NULL,
    dedup_key           TEXT NOT NULL UNIQUE,
    raw_ref             TEXT,
    trust_class         TEXT NOT NULL,
    trust_score         REAL NOT NULL,
    context_json        TEXT NOT NULL DEFAULT '{}',
    metadata_json       TEXT NOT NULL DEFAULT '{}',
    observed_at         TEXT NOT NULL,
    ingested_at         TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_events_session_time
    ON events(session_id, observed_at);
CREATE INDEX IF NOT EXISTS idx_events_project_type
    ON events(project_id, event_type);

CREATE TABLE IF NOT EXISTS artifacts (
    id                  TEXT PRIMARY KEY,
    project_id          TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    session_id          TEXT REFERENCES sessions(id) ON DELETE CASCADE,
    kind                TEXT NOT NULL,
    uri                 TEXT,
    sha256              TEXT NOT NULL,
    mime_type           TEXT,
    metadata_json       TEXT NOT NULL DEFAULT '{}',
    created_at          TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS evidence (
    id                  TEXT PRIMARY KEY,
    project_id          TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    session_id          TEXT REFERENCES sessions(id) ON DELETE CASCADE,
    evidence_type       TEXT NOT NULL,
    source_event_id     TEXT REFERENCES events(id) ON DELETE SET NULL,
    artifact_id         TEXT REFERENCES artifacts(id) ON DELETE SET NULL,
    claim               TEXT NOT NULL,
    polarity            TEXT NOT NULL,
    strength            REAL NOT NULL,
    locator_json        TEXT NOT NULL,
    verified            INTEGER NOT NULL DEFAULT 0,
    created_at          TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_evidence_project_session
    ON evidence(project_id, session_id);

CREATE TABLE IF NOT EXISTS experiences (
    id                   TEXT PRIMARY KEY,
    project_id           TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    schema_version       TEXT NOT NULL,
    version              INTEGER NOT NULL DEFAULT 1,
    fingerprint          TEXT NOT NULL,
    title                TEXT NOT NULL,
    category             TEXT NOT NULL,
    problem_pattern      TEXT NOT NULL,
    trigger_text         TEXT,
    root_cause           TEXT,
    correct_approach     TEXT NOT NULL,
    reusable_rule        TEXT NOT NULL,
    wrong_paths_json     TEXT NOT NULL DEFAULT '[]',
    corrections_json     TEXT NOT NULL DEFAULT '[]',
    verification_json    TEXT NOT NULL DEFAULT '[]',
    scope_json           TEXT NOT NULL DEFAULT '{}',
    exceptions_json      TEXT NOT NULL DEFAULT '[]',
    confidence           REAL NOT NULL,
    confidence_json      TEXT NOT NULL DEFAULT '{}',
    status               TEXT NOT NULL,
    source_session_count INTEGER NOT NULL DEFAULT 1,
    first_seen_at        TEXT NOT NULL,
    last_seen_at         TEXT NOT NULL,
    valid_until          TEXT,
    supersedes_id        TEXT REFERENCES experiences(id) ON DELETE SET NULL,
    metadata_json        TEXT NOT NULL DEFAULT '{}',
    created_at           TEXT NOT NULL,
    updated_at           TEXT NOT NULL,
    UNIQUE(project_id, fingerprint)
);

CREATE INDEX IF NOT EXISTS idx_experiences_project_status
    ON experiences(project_id, status, confidence DESC);

CREATE TABLE IF NOT EXISTS experience_versions (
    experience_id          TEXT NOT NULL REFERENCES experiences(id) ON DELETE CASCADE,
    version                INTEGER NOT NULL,
    snapshot_json          TEXT NOT NULL,
    change_reason          TEXT NOT NULL,
    created_at             TEXT NOT NULL,
    PRIMARY KEY (experience_id, version)
);

CREATE TABLE IF NOT EXISTS experience_sources (
    experience_id       TEXT NOT NULL REFERENCES experiences(id) ON DELETE CASCADE,
    session_id          TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    PRIMARY KEY (experience_id, session_id)
);

CREATE TABLE IF NOT EXISTS experience_evidence (
    experience_id       TEXT NOT NULL REFERENCES experiences(id) ON DELETE CASCADE,
    evidence_id         TEXT NOT NULL REFERENCES evidence(id) ON DELETE CASCADE,
    relation            TEXT NOT NULL,
    PRIMARY KEY (experience_id, evidence_id, relation)
);

CREATE TABLE IF NOT EXISTS contradictions (
    id                  TEXT PRIMARY KEY,
    project_id          TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    left_experience_id  TEXT NOT NULL REFERENCES experiences(id) ON DELETE CASCADE,
    right_experience_id TEXT NOT NULL REFERENCES experiences(id) ON DELETE CASCADE,
    status              TEXT NOT NULL,
    resolution_json     TEXT,
    created_at          TEXT NOT NULL,
    resolved_at         TEXT
);

CREATE TABLE IF NOT EXISTS feedback (
    id                  TEXT PRIMARY KEY,
    project_id          TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    session_id          TEXT REFERENCES sessions(id) ON DELETE SET NULL,
    experience_id       TEXT REFERENCES experiences(id) ON DELETE SET NULL,
    rule_id             TEXT,
    actor               TEXT NOT NULL,
    result              TEXT NOT NULL,
    applied             INTEGER NOT NULL DEFAULT 0,
    comment             TEXT,
    evidence_ids_json   TEXT NOT NULL DEFAULT '[]',
    user_confirmed      INTEGER NOT NULL DEFAULT 0,
    idempotency_key     TEXT NOT NULL UNIQUE,
    created_at          TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS proposals (
    id                          TEXT PRIMARY KEY,
    project_id                  TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    schema_version              TEXT NOT NULL,
    source_experience_ids_json  TEXT NOT NULL,
    target                      TEXT NOT NULL,
    scope_json                  TEXT NOT NULL DEFAULT '{}',
    proposed_rule               TEXT NOT NULL,
    rationale                   TEXT NOT NULL,
    exceptions_json             TEXT NOT NULL DEFAULT '[]',
    validation_plan_json        TEXT NOT NULL DEFAULT '[]',
    rollback                    TEXT NOT NULL,
    status                      TEXT NOT NULL,
    suggestion                  TEXT,
    metadata_json               TEXT NOT NULL DEFAULT '{}',
    content_hash                TEXT NOT NULL,
    created_at                  TEXT NOT NULL,
    updated_at                  TEXT NOT NULL,
    UNIQUE(project_id, content_hash)
);

CREATE TABLE IF NOT EXISTS analysis_jobs (
    id                  TEXT PRIMARY KEY,
    job_type            TEXT NOT NULL,
    project_id          TEXT REFERENCES projects(id) ON DELETE CASCADE,
    session_id          TEXT REFERENCES sessions(id) ON DELETE CASCADE,
    idempotency_key     TEXT NOT NULL UNIQUE,
    status              TEXT NOT NULL,
    attempt             INTEGER NOT NULL DEFAULT 0,
    available_at        TEXT NOT NULL,
    leased_until        TEXT,
    payload_json        TEXT NOT NULL DEFAULT '{}',
    error_json          TEXT,
    created_at          TEXT NOT NULL,
    updated_at          TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_jobs_claim
    ON analysis_jobs(status, available_at, leased_until);

CREATE TABLE IF NOT EXISTS audit_log (
    id                  TEXT PRIMARY KEY,
    actor               TEXT NOT NULL,
    action              TEXT NOT NULL,
    entity_type         TEXT NOT NULL,
    entity_id           TEXT NOT NULL,
    before_hash         TEXT,
    after_hash          TEXT,
    details_json        TEXT NOT NULL DEFAULT '{}',
    created_at          TEXT NOT NULL
);

CREATE VIRTUAL TABLE IF NOT EXISTS experiences_fts USING fts5(
    title,
    problem_pattern,
    correct_approach,
    reusable_rule,
    content='experiences',
    content_rowid='rowid',
    tokenize='unicode61'
);

CREATE TRIGGER IF NOT EXISTS experiences_ai AFTER INSERT ON experiences BEGIN
    INSERT INTO experiences_fts(rowid, title, problem_pattern, correct_approach, reusable_rule)
    VALUES (new.rowid, new.title, new.problem_pattern, new.correct_approach, new.reusable_rule);
END;

CREATE TRIGGER IF NOT EXISTS experiences_ad AFTER DELETE ON experiences BEGIN
    INSERT INTO experiences_fts(experiences_fts, rowid, title, problem_pattern, correct_approach, reusable_rule)
    VALUES ('delete', old.rowid, old.title, old.problem_pattern, old.correct_approach, old.reusable_rule);
END;

CREATE TRIGGER IF NOT EXISTS experiences_au AFTER UPDATE ON experiences BEGIN
    INSERT INTO experiences_fts(experiences_fts, rowid, title, problem_pattern, correct_approach, reusable_rule)
    VALUES ('delete', old.rowid, old.title, old.problem_pattern, old.correct_approach, old.reusable_rule);
    INSERT INTO experiences_fts(rowid, title, problem_pattern, correct_approach, reusable_rule)
    VALUES (new.rowid, new.title, new.problem_pattern, new.correct_approach, new.reusable_rule);
END;

INSERT INTO experiences_fts(experiences_fts) VALUES ('rebuild');
