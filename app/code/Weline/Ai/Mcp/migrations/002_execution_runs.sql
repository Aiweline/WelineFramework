PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS execution_runs (
    run_id                  TEXT PRIMARY KEY,
    task_id                 TEXT NOT NULL,
    trace_id                TEXT NOT NULL UNIQUE,
    project_id              TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    schema_version          TEXT NOT NULL DEFAULT 'execution-run.v1',
    supersedes_run_id       TEXT REFERENCES execution_runs(run_id) ON DELETE SET NULL,
    superseded_by_run_id    TEXT REFERENCES execution_runs(run_id) ON DELETE SET NULL,
    task_digest             TEXT NOT NULL,
    task_original_redacted  TEXT NOT NULL,
    task_contract_json      TEXT NOT NULL DEFAULT '{}',
    current_phase           TEXT NOT NULL,
    status                  TEXT NOT NULL,
    workflow_state          TEXT NOT NULL DEFAULT 'NORMAL',
    bundle_id               TEXT,
    edit_id                 TEXT,
    index_revision          INTEGER NOT NULL DEFAULT 0,
    budgets_json            TEXT NOT NULL DEFAULT '{}',
    counters_json           TEXT NOT NULL DEFAULT '{}',
    recursion_json          TEXT NOT NULL DEFAULT '{}',
    result_summary_json     TEXT NOT NULL DEFAULT '{}',
    error_json              TEXT,
    started_at              TEXT NOT NULL,
    updated_at              TEXT NOT NULL,
    completed_at            TEXT
);

CREATE INDEX IF NOT EXISTS idx_execution_runs_project_started
    ON execution_runs(project_id, started_at DESC);
CREATE INDEX IF NOT EXISTS idx_execution_runs_status_updated
    ON execution_runs(status, updated_at);
CREATE INDEX IF NOT EXISTS idx_execution_runs_task
    ON execution_runs(project_id, task_id, started_at DESC);

CREATE TABLE IF NOT EXISTS execution_run_events (
    sequence             INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id             TEXT NOT NULL UNIQUE,
    run_id               TEXT NOT NULL REFERENCES execution_runs(run_id) ON DELETE CASCADE,
    parent_event_id      TEXT,
    event_type           TEXT NOT NULL,
    phase                TEXT NOT NULL,
    status               TEXT NOT NULL,
    reason_code          TEXT,
    reason_text          TEXT,
    tool_name            TEXT,
    operation_name       TEXT,
    input_summary_json   TEXT NOT NULL DEFAULT '{}',
    output_summary_json  TEXT NOT NULL DEFAULT '{}',
    targets_json         TEXT NOT NULL DEFAULT '[]',
    metrics_json         TEXT NOT NULL DEFAULT '{}',
    retry_json           TEXT NOT NULL DEFAULT '{}',
    error_json           TEXT,
    started_at           TEXT NOT NULL,
    completed_at         TEXT,
    duration_ms          INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_execution_run_events_run_sequence
    ON execution_run_events(run_id, sequence);
CREATE INDEX IF NOT EXISTS idx_execution_run_events_reason
    ON execution_run_events(run_id, reason_code, sequence);

CREATE TABLE IF NOT EXISTS execution_run_files (
    run_id                TEXT NOT NULL REFERENCES execution_runs(run_id) ON DELETE CASCADE,
    path                  TEXT NOT NULL,
    candidate             INTEGER NOT NULL DEFAULT 0,
    selected              INTEGER NOT NULL DEFAULT 0,
    materialized          INTEGER NOT NULL DEFAULT 0,
    excluded              INTEGER NOT NULL DEFAULT 0,
    modified              INTEGER NOT NULL DEFAULT 0,
    candidate_reason      TEXT,
    excluded_reason       TEXT,
    regions_json          TEXT NOT NULL DEFAULT '[]',
    operation_count       INTEGER NOT NULL DEFAULT 0,
    pre_sha256            TEXT,
    post_sha256           TEXT,
    impact_json           TEXT NOT NULL DEFAULT '{}',
    validation_json       TEXT NOT NULL DEFAULT '{}',
    diff_redacted         TEXT,
    diff_truncated        INTEGER NOT NULL DEFAULT 0,
    updated_at            TEXT NOT NULL,
    PRIMARY KEY (run_id, path)
);

CREATE INDEX IF NOT EXISTS idx_execution_run_files_modified
    ON execution_run_files(run_id, modified, path);
