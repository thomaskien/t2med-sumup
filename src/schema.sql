CREATE TABLE IF NOT EXISTS services (
    id TEXT PRIMARY KEY, label TEXT NOT NULL, price_cents INTEGER NOT NULL CHECK(price_cents > 0),
    active INTEGER NOT NULL DEFAULT 1, updated_at INTEGER NOT NULL,
    goae_code TEXT NOT NULL DEFAULT '', factor TEXT NOT NULL DEFAULT '',
    fee_type TEXT NOT NULL DEFAULT 'standard', on_request INTEGER NOT NULL DEFAULT 0,
    vat INTEGER NOT NULL DEFAULT 0, base_cents INTEGER NOT NULL DEFAULT 0, default_reason TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS service_groups (
    id TEXT PRIMARY KEY, label TEXT NOT NULL, service_ids_json TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1, updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS visits (
    id TEXT PRIMARY KEY, context_id TEXT NOT NULL, patient_id TEXT NOT NULL,
    patient_name TEXT NOT NULL, patient_birthdate TEXT NOT NULL DEFAULT '', patient_address TEXT NOT NULL DEFAULT '[]',
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
    fhir_resource_id TEXT, fhir_written_at INTEGER, error_message TEXT NOT NULL DEFAULT '',
    invoice_json TEXT, inbox_id TEXT, t2med_invoice TEXT,
    export_status TEXT NOT NULL DEFAULT '', export_error TEXT NOT NULL DEFAULT '', export_path TEXT NOT NULL DEFAULT '',
    auto_print INTEGER NOT NULL DEFAULT 0, print_status TEXT NOT NULL DEFAULT '', print_error TEXT NOT NULL DEFAULT '', print_info TEXT NOT NULL DEFAULT '',
    t2med_done_at INTEGER, payment_method TEXT NOT NULL DEFAULT 'card',
    corrected_cents INTEGER, voided_at INTEGER, correction_note TEXT NOT NULL DEFAULT ''
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
CREATE TABLE IF NOT EXISTS invoice_inbox (
    id TEXT PRIMARY KEY, invoice_number TEXT NOT NULL, invoice_date TEXT NOT NULL,
    amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
    patient_name TEXT NOT NULL, patient_birthdate TEXT NOT NULL,
    received_at INTEGER NOT NULL, superseded_at INTEGER,
    pdf BLOB, export_status TEXT NOT NULL DEFAULT '', export_error TEXT NOT NULL DEFAULT '', export_path TEXT NOT NULL DEFAULT '',
    printed_at INTEGER, lxp_status TEXT NOT NULL DEFAULT '', lxp_job_id TEXT NOT NULL DEFAULT '', lxp_sent_at INTEGER, lxp_mode TEXT NOT NULL DEFAULT '', lxp_error TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS invoice_inbox_number ON invoice_inbox(invoice_number, superseded_at);
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS admin_sessions (
    token_hash TEXT PRIMARY KEY, csrf TEXT NOT NULL, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, role TEXT NOT NULL DEFAULT 'admin',
    remember INTEGER NOT NULL DEFAULT 0, net TEXT NOT NULL DEFAULT '', label TEXT NOT NULL DEFAULT '', last_used INTEGER NOT NULL DEFAULT 0
);
