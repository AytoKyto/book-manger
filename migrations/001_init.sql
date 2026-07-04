CREATE TABLE IF NOT EXISTS books (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    path TEXT NOT NULL,
    genre TEXT DEFAULT '',
    status TEXT NOT NULL DEFAULT 'draft',
    word_target INTEGER DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS chapters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
    title TEXT NOT NULL,
    filename TEXT NOT NULL,
    order_index INTEGER NOT NULL DEFAULT 0,
    word_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(book_id, filename)
);

CREATE TABLE IF NOT EXISTS agent_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    book_id INTEGER NOT NULL REFERENCES books(id) ON DELETE CASCADE,
    chapter_id INTEGER REFERENCES chapters(id) ON DELETE CASCADE,
    agent_name TEXT NOT NULL,
    instruction TEXT DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending', -- pending|running|awaiting_review|applied|rejected|error
    error_message TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    started_at TEXT,
    completed_at TEXT
);

CREATE TABLE IF NOT EXISTS run_diffs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    run_id INTEGER NOT NULL REFERENCES agent_runs(id) ON DELETE CASCADE,
    file_path TEXT NOT NULL,
    diff_text TEXT NOT NULL,
    decision TEXT NOT NULL DEFAULT 'pending', -- pending|accepted|rejected
    applied_at TEXT
);

CREATE TABLE IF NOT EXISTS snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chapter_id INTEGER NOT NULL REFERENCES chapters(id) ON DELETE CASCADE,
    content TEXT NOT NULL,
    reason TEXT NOT NULL DEFAULT 'manual', -- manual|before_agent_apply
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_chapters_book ON chapters(book_id);
CREATE INDEX IF NOT EXISTS idx_runs_book ON agent_runs(book_id);
CREATE INDEX IF NOT EXISTS idx_runs_status ON agent_runs(status);
CREATE INDEX IF NOT EXISTS idx_diffs_run ON run_diffs(run_id);
CREATE INDEX IF NOT EXISTS idx_snapshots_chapter ON snapshots(chapter_id);
