CREATE TABLE IF NOT EXISTS services (
    id TEXT PRIMARY KEY, label TEXT NOT NULL, price_cents INTEGER NOT NULL CHECK(price_cents > 0),
    active INTEGER NOT NULL DEFAULT 1, updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS visits (
    id TEXT PRIMARY KEY, context_id TEXT NOT NULL, patient_id TEXT NOT NULL,
    patient_name TEXT NOT NULL, patient_birthdate TEXT NOT NULL DEFAULT '',
    oauth_cipher TEXT NOT NULL, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL,
    completed_at INTEGER, mock_document_fail INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS visits_context ON visits(context_id, patient_id);
CREATE TABLE IF NOT EXISTS launch_tickets (
    hash TEXT PRIMARY KEY, visit_id TEXT NOT NULL REFERENCES visits(id), expires_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS browsers (
    hash TEXT PRIMARY KEY, csrf TEXT NOT NULL, expires_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS visit_access (
    browser_hash TEXT NOT NULL REFERENCES browsers(hash) ON DELETE CASCADE,
    visit_id TEXT NOT NULL REFERENCES visits(id), PRIMARY KEY(browser_hash, visit_id)
);
CREATE TABLE IF NOT EXISTS payments (
    id TEXT PRIMARY KEY, visit_id TEXT NOT NULL REFERENCES visits(id),
    request_id TEXT NOT NULL UNIQUE, request_hash TEXT NOT NULL,
    reference TEXT NOT NULL UNIQUE, reader_id TEXT NOT NULL,
    amount_cents INTEGER NOT NULL CHECK(amount_cents > 0), currency TEXT NOT NULL DEFAULT 'EUR',
    services_json TEXT NOT NULL,
    payment_status TEXT NOT NULL, doc_status TEXT NOT NULL DEFAULT 'pending',
    checkout_id TEXT, client_transaction_id TEXT, transaction_id TEXT,
    created_at INTEGER NOT NULL, paid_at INTEGER, checked_at INTEGER NOT NULL DEFAULT 0,
    fhir_resource_id TEXT, fhir_written_at INTEGER, error_message TEXT NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX IF NOT EXISTS reader_busy ON payments(reader_id)
    WHERE payment_status IN ('starting','pending','unknown','cancel_requested');
CREATE UNIQUE INDEX IF NOT EXISTS visit_unfinished_payment ON payments(visit_id)
    WHERE payment_status IN ('starting','pending','unknown','cancel_requested','successful');
CREATE INDEX IF NOT EXISTS payment_visit ON payments(visit_id, created_at);
CREATE TABLE IF NOT EXISTS mock_records (
    payment_id TEXT PRIMARY KEY REFERENCES payments(id), resource_json TEXT NOT NULL, created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS receipt_emails (
    request_id TEXT PRIMARY KEY, payment_id TEXT NOT NULL REFERENCES payments(id),
    recipient TEXT NOT NULL, status TEXT NOT NULL, created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS receipt_prints (
    request_id TEXT PRIMARY KEY, payment_id TEXT NOT NULL REFERENCES payments(id),
    status TEXT NOT NULL, created_at INTEGER NOT NULL
);
