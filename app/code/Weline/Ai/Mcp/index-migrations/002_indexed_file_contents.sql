CREATE TABLE IF NOT EXISTS indexed_file_contents (
    file_id INTEGER PRIMARY KEY REFERENCES indexed_files(id) ON DELETE CASCADE,
    content_blob BLOB NOT NULL,
    encoding TEXT NOT NULL DEFAULT 'gzip',
    content_hash TEXT NOT NULL,
    original_bytes INTEGER NOT NULL,
    stored_bytes INTEGER NOT NULL,
    revision INTEGER NOT NULL,
    indexed_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS indexed_file_contents_revision_idx
    ON indexed_file_contents(revision);
