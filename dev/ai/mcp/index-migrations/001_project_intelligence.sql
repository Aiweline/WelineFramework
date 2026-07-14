CREATE TABLE IF NOT EXISTS metadata (
    metadata_key TEXT PRIMARY KEY,
    value_json TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS indexed_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    path TEXT NOT NULL UNIQUE,
    kind TEXT NOT NULL,
    language TEXT NOT NULL DEFAULT '',
    module_vendor TEXT NOT NULL DEFAULT '',
    module_name TEXT NOT NULL DEFAULT '',
    size_bytes INTEGER NOT NULL,
    mtime INTEGER NOT NULL,
    content_hash TEXT NOT NULL,
    git_blob TEXT NOT NULL DEFAULT '',
    revision INTEGER NOT NULL,
    indexed_at TEXT NOT NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}'
);

CREATE INDEX IF NOT EXISTS indexed_files_kind_idx ON indexed_files(kind);
CREATE INDEX IF NOT EXISTS indexed_files_module_idx ON indexed_files(module_vendor, module_name);
CREATE INDEX IF NOT EXISTS indexed_files_revision_idx ON indexed_files(revision);

CREATE TABLE IF NOT EXISTS chunks (
    chunk_id TEXT PRIMARY KEY,
    file_id INTEGER NOT NULL REFERENCES indexed_files(id) ON DELETE CASCADE,
    kind TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    symbol_uid TEXT,
    start_line INTEGER NOT NULL,
    end_line INTEGER NOT NULL,
    start_byte INTEGER NOT NULL DEFAULT 0,
    end_byte INTEGER NOT NULL DEFAULT 0,
    content TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    token_estimate INTEGER NOT NULL DEFAULT 0,
    revision INTEGER NOT NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}'
);

CREATE INDEX IF NOT EXISTS chunks_file_idx ON chunks(file_id, start_line);
CREATE INDEX IF NOT EXISTS chunks_symbol_idx ON chunks(symbol_uid);
CREATE INDEX IF NOT EXISTS chunks_revision_idx ON chunks(revision);

CREATE VIRTUAL TABLE IF NOT EXISTS chunk_fts USING fts5(
    content,
    path,
    title,
    symbol_name,
    module,
    tokenize = 'unicode61 remove_diacritics 2'
);

-- ProjectIndex upgrades this compatibility table to an FTS5 trigram virtual
-- table when the linked SQLite build supports the trigram tokenizer. Keeping
-- the same columns lets triggers and retrieval degrade safely to LIKE search.
CREATE TABLE IF NOT EXISTS chunk_trigram (
    rowid INTEGER PRIMARY KEY,
    content TEXT NOT NULL,
    path TEXT NOT NULL,
    title TEXT NOT NULL,
    symbol_name TEXT NOT NULL,
    module TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS chunk_vector_terms (
    chunk_id TEXT NOT NULL REFERENCES chunks(chunk_id) ON DELETE CASCADE,
    term_hash INTEGER NOT NULL,
    weight REAL NOT NULL,
    PRIMARY KEY (chunk_id, term_hash)
) WITHOUT ROWID;

CREATE INDEX IF NOT EXISTS chunk_vector_terms_hash_idx
    ON chunk_vector_terms(term_hash, chunk_id);

CREATE TABLE IF NOT EXISTS symbols (
    symbol_uid TEXT PRIMARY KEY,
    file_id INTEGER NOT NULL REFERENCES indexed_files(id) ON DELETE CASCADE,
    chunk_id TEXT REFERENCES chunks(chunk_id) ON DELETE SET NULL,
    name TEXT NOT NULL,
    fq_name TEXT NOT NULL,
    kind TEXT NOT NULL,
    namespace TEXT NOT NULL DEFAULT '',
    signature TEXT NOT NULL DEFAULT '',
    parent_uid TEXT,
    start_line INTEGER NOT NULL,
    end_line INTEGER NOT NULL,
    start_byte INTEGER NOT NULL DEFAULT 0,
    end_byte INTEGER NOT NULL DEFAULT 0,
    body_hash TEXT NOT NULL,
    revision INTEGER NOT NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}'
);

CREATE INDEX IF NOT EXISTS symbols_name_idx ON symbols(name COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS symbols_fq_name_idx ON symbols(fq_name COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS symbols_file_idx ON symbols(file_id, start_line);
CREATE INDEX IF NOT EXISTS symbols_parent_idx ON symbols(parent_uid);

CREATE TABLE IF NOT EXISTS relations (
    relation_id INTEGER PRIMARY KEY AUTOINCREMENT,
    file_id INTEGER NOT NULL REFERENCES indexed_files(id) ON DELETE CASCADE,
    source_symbol_uid TEXT REFERENCES symbols(symbol_uid) ON DELETE CASCADE,
    target_name TEXT NOT NULL,
    target_symbol_uid TEXT REFERENCES symbols(symbol_uid) ON DELETE SET NULL,
    relation_kind TEXT NOT NULL,
    line INTEGER NOT NULL,
    confidence REAL NOT NULL DEFAULT 0.75,
    revision INTEGER NOT NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}'
);

CREATE INDEX IF NOT EXISTS relations_source_idx ON relations(source_symbol_uid, relation_kind);
CREATE INDEX IF NOT EXISTS relations_target_idx ON relations(target_name COLLATE NOCASE, relation_kind);
CREATE INDEX IF NOT EXISTS relations_target_uid_idx ON relations(target_symbol_uid, relation_kind);
CREATE INDEX IF NOT EXISTS relations_file_idx ON relations(file_id, line);

CREATE TABLE IF NOT EXISTS skills (
    skill_id TEXT PRIMARY KEY,
    path TEXT NOT NULL UNIQUE,
    file_id INTEGER NOT NULL REFERENCES indexed_files(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    module_vendor TEXT NOT NULL DEFAULT '',
    module_name TEXT NOT NULL DEFAULT '',
    triggers_json TEXT NOT NULL DEFAULT '[]',
    status TEXT NOT NULL DEFAULT 'indexed',
    source_hash TEXT NOT NULL,
    revision INTEGER NOT NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}'
);

CREATE INDEX IF NOT EXISTS skills_name_idx ON skills(name COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS skills_module_idx ON skills(module_vendor, module_name);

CREATE TABLE IF NOT EXISTS knowledge_state (
    state_key TEXT PRIMARY KEY,
    value_json TEXT NOT NULL,
    revision INTEGER NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS query_log (
    query_id TEXT PRIMARY KEY,
    query_text TEXT NOT NULL,
    options_json TEXT NOT NULL DEFAULT '{}',
    revision INTEGER NOT NULL,
    status TEXT NOT NULL,
    timings_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    completed_at TEXT
);

CREATE INDEX IF NOT EXISTS query_log_created_idx ON query_log(created_at);

CREATE TABLE IF NOT EXISTS query_results (
    query_id TEXT NOT NULL REFERENCES query_log(query_id) ON DELETE CASCADE,
    result_rank INTEGER NOT NULL,
    chunk_id TEXT NOT NULL REFERENCES chunks(chunk_id) ON DELETE CASCADE,
    score REAL NOT NULL,
    components_json TEXT NOT NULL DEFAULT '{}',
    PRIMARY KEY (query_id, result_rank)
);

CREATE INDEX IF NOT EXISTS query_results_chunk_idx ON query_results(chunk_id, query_id);

CREATE TABLE IF NOT EXISTS query_feedback (
    feedback_id TEXT PRIMARY KEY,
    query_id TEXT NOT NULL REFERENCES query_log(query_id) ON DELETE CASCADE,
    chunk_id TEXT,
    outcome TEXT NOT NULL,
    comment TEXT NOT NULL DEFAULT '',
    actor TEXT NOT NULL DEFAULT 'user',
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS query_feedback_query_idx ON query_feedback(query_id, created_at);
CREATE INDEX IF NOT EXISTS query_feedback_chunk_idx ON query_feedback(chunk_id, outcome);

CREATE TABLE IF NOT EXISTS edit_transactions (
    transaction_id TEXT PRIMARY KEY,
    base_revision INTEGER NOT NULL,
    status TEXT NOT NULL,
    token_hash TEXT,
    base_commit TEXT NOT NULL DEFAULT '',
    plan_digest TEXT NOT NULL DEFAULT '',
    request_json TEXT NOT NULL,
    plan_json TEXT NOT NULL DEFAULT '{}',
    snapshots_json TEXT NOT NULL DEFAULT '[]',
    result_json TEXT NOT NULL DEFAULT '{}',
    error_json TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    expires_at TEXT,
    applied_at TEXT
);

CREATE INDEX IF NOT EXISTS edit_transactions_status_idx ON edit_transactions(status, created_at);
CREATE UNIQUE INDEX IF NOT EXISTS edit_transactions_token_hash_unique
    ON edit_transactions(token_hash) WHERE token_hash IS NOT NULL;

CREATE TABLE IF NOT EXISTS validation_runs (
    validation_id TEXT PRIMARY KEY,
    transaction_id TEXT REFERENCES edit_transactions(transaction_id) ON DELETE SET NULL,
    revision INTEGER NOT NULL,
    profile TEXT NOT NULL,
    status TEXT NOT NULL,
    command_json TEXT NOT NULL DEFAULT '[]',
    result_json TEXT NOT NULL DEFAULT '{}',
    started_at TEXT NOT NULL,
    completed_at TEXT
);

CREATE INDEX IF NOT EXISTS validation_runs_transaction_idx
    ON validation_runs(transaction_id, started_at);

CREATE TRIGGER IF NOT EXISTS chunks_after_insert
AFTER INSERT ON chunks
BEGIN
    INSERT INTO chunk_fts(rowid, content, path, title, symbol_name, module)
    SELECT NEW.rowid, NEW.content, f.path, NEW.title, COALESCE(NEW.symbol_uid, ''),
           trim(f.module_vendor || '/' || f.module_name, '/')
      FROM indexed_files AS f WHERE f.id = NEW.file_id;
    INSERT INTO chunk_trigram(rowid, content, path, title, symbol_name, module)
    SELECT NEW.rowid, NEW.content, f.path, NEW.title, COALESCE(NEW.symbol_uid, ''),
           trim(f.module_vendor || '/' || f.module_name, '/')
      FROM indexed_files AS f
     WHERE f.id = NEW.file_id AND f.kind IN ('doc', 'rule', 'skill');
END;

CREATE TRIGGER IF NOT EXISTS chunks_before_delete
BEFORE DELETE ON chunks
BEGIN
    DELETE FROM chunk_fts WHERE rowid = OLD.rowid;
    DELETE FROM chunk_trigram WHERE rowid = OLD.rowid;
END;

CREATE TRIGGER IF NOT EXISTS chunks_after_update
AFTER UPDATE OF content, title, symbol_uid, file_id ON chunks
BEGIN
    DELETE FROM chunk_fts WHERE rowid = OLD.rowid;
    DELETE FROM chunk_trigram WHERE rowid = OLD.rowid;
    INSERT INTO chunk_fts(rowid, content, path, title, symbol_name, module)
    SELECT NEW.rowid, NEW.content, f.path, NEW.title, COALESCE(NEW.symbol_uid, ''),
           trim(f.module_vendor || '/' || f.module_name, '/')
      FROM indexed_files AS f WHERE f.id = NEW.file_id;
    INSERT INTO chunk_trigram(rowid, content, path, title, symbol_name, module)
    SELECT NEW.rowid, NEW.content, f.path, NEW.title, COALESCE(NEW.symbol_uid, ''),
           trim(f.module_vendor || '/' || f.module_name, '/')
      FROM indexed_files AS f
     WHERE f.id = NEW.file_id AND f.kind IN ('doc', 'rule', 'skill');
END;
