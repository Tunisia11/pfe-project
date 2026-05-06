CREATE TABLE IF NOT EXISTS admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    name TEXT NULL,
    role TEXT NOT NULL DEFAULT 'admin',
    is_active INTEGER NOT NULL DEFAULT 1,
    last_login_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS admin_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_token_hash TEXT NOT NULL UNIQUE,
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    revoked_at TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES admin_users(id)
);

CREATE TABLE IF NOT EXISTS extracted_contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    domain TEXT NULL,
    category TEXT NULL,
    source_count INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    notes TEXT NULL,
    first_seen_at TEXT NULL,
    last_seen_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS contact_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id INTEGER NOT NULL,
    email_archive_id INTEGER NOT NULL,
    source_field TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (contact_id) REFERENCES extracted_contacts(id)
);

CREATE TABLE IF NOT EXISTS sync_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    status TEXT NOT NULL,
    emails_processed INTEGER NOT NULL DEFAULT 0,
    total_extracted_addresses INTEGER NOT NULL DEFAULT 0,
    valid_contacts INTEGER NOT NULL DEFAULT 0,
    duplicates_removed INTEGER NOT NULL DEFAULT 0,
    ignored_invalid_or_system_addresses INTEGER NOT NULL DEFAULT 0,
    unique_contacts INTEGER NOT NULL DEFAULT 0,
    message TEXT NULL,
    payload_json TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS app_settings (
    "key" TEXT PRIMARY KEY,
    value TEXT NULL,
    type TEXT NOT NULL DEFAULT 'string',
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_user_id INTEGER NULL,
    action TEXT NOT NULL,
    entity_type TEXT NULL,
    entity_id TEXT NULL,
    metadata_json TEXT NULL,
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_extracted_contacts_email ON extracted_contacts(email);
CREATE INDEX IF NOT EXISTS idx_extracted_contacts_domain ON extracted_contacts(domain);
CREATE INDEX IF NOT EXISTS idx_extracted_contacts_status ON extracted_contacts(status);
CREATE INDEX IF NOT EXISTS idx_contact_sources_contact_id ON contact_sources(contact_id);
CREATE INDEX IF NOT EXISTS idx_contact_sources_email_archive_id ON contact_sources(email_archive_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_contact_sources_unique ON contact_sources(contact_id, email_archive_id, source_field);
CREATE INDEX IF NOT EXISTS idx_sync_runs_created_at ON sync_runs(created_at);
CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at ON audit_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_audit_logs_admin_user_id ON audit_logs(admin_user_id);
