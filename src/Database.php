<?php
declare(strict_types=1);
namespace KienzleSumup;

final class Database {
    public readonly \PDO $pdo;
    public function __construct(public readonly string $directory) {
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Datenverzeichnis nicht verfügbar.');
        }
        $this->pdo = new \PDO('sqlite:' . $directory . '/payments.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL;');
    }
    public function migrate(): void {
        $version = (int)$this->pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version > 18) throw new \RuntimeException('Datenbank ist neuer als die Anwendung.');
        if ($version === 18) return;
        $this->transaction(function (): void {
            $this->pdo->exec((string)file_get_contents(__DIR__ . '/schema.sql'));
            foreach (['services' => ['goae_code' => "TEXT NOT NULL DEFAULT ''", 'factor' => "TEXT NOT NULL DEFAULT ''", 'fee_type' => "TEXT NOT NULL DEFAULT 'standard'", 'on_request' => 'INTEGER NOT NULL DEFAULT 0', 'vat' => 'INTEGER NOT NULL DEFAULT 0', 'base_cents' => 'INTEGER NOT NULL DEFAULT 0', 'default_reason' => "TEXT NOT NULL DEFAULT ''"],
                'visits' => ['patient_address' => "TEXT NOT NULL DEFAULT '[]'"], 'payments' => ['invoice_json' => 'TEXT', 'inbox_id' => 'TEXT', 't2med_invoice' => 'TEXT',
                    'export_status' => "TEXT NOT NULL DEFAULT ''", 'export_error' => "TEXT NOT NULL DEFAULT ''", 'export_path' => "TEXT NOT NULL DEFAULT ''",
                    'auto_print' => 'INTEGER NOT NULL DEFAULT 0', 'print_status' => "TEXT NOT NULL DEFAULT ''", 'print_error' => "TEXT NOT NULL DEFAULT ''", 'print_info' => "TEXT NOT NULL DEFAULT ''", 't2med_done_at' => 'INTEGER', 'payment_method' => "TEXT NOT NULL DEFAULT 'card'", 'corrected_cents' => 'INTEGER', 'voided_at' => 'INTEGER', 'correction_note' => "TEXT NOT NULL DEFAULT ''"],
                'admin_sessions' => ['role' => "TEXT NOT NULL DEFAULT 'admin'", 'remember' => 'INTEGER NOT NULL DEFAULT 0', 'net' => "TEXT NOT NULL DEFAULT ''", 'label' => "TEXT NOT NULL DEFAULT ''", 'last_used' => 'INTEGER NOT NULL DEFAULT 0'],
                'invoice_inbox' => ['printed_at' => 'INTEGER', 'lxp_status' => "TEXT NOT NULL DEFAULT ''", 'lxp_job_id' => "TEXT NOT NULL DEFAULT ''", 'lxp_sent_at' => 'INTEGER', 'lxp_mode' => "TEXT NOT NULL DEFAULT ''", 'lxp_error' => "TEXT NOT NULL DEFAULT ''", 'pdf' => 'BLOB', 'export_status' => "TEXT NOT NULL DEFAULT ''", 'export_error' => "TEXT NOT NULL DEFAULT ''", 'export_path' => "TEXT NOT NULL DEFAULT ''"]] as $table => $columns) {
                $existing = array_column($this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
                foreach ($columns as $name => $type) if (!in_array($name, $existing, true)) $this->pdo->exec("ALTER TABLE $table ADD COLUMN $name $type");
            }
            // Erst nach dem Ergänzen der Spalten: Index für bereits kassierte T2med-Rechnungen.
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS payment_t2med_invoice ON payments(t2med_invoice)');
            $this->pdo->exec('PRAGMA user_version=18');
        });
    }
    public function query(string $sql, array $params = []): \PDOStatement {
        $stmt = $this->pdo->prepare($sql); $stmt->execute($params); return $stmt;
    }
    public function one(string $sql, array $params = []): ?array { return $this->query($sql, $params)->fetch() ?: null; }
    public function transaction(callable $action): mixed {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try { $result = $action(); $this->pdo->exec('COMMIT'); return $result; }
        catch (\Throwable $e) { $this->pdo->exec('ROLLBACK'); throw $e; }
    }
    public function locked(string $name, callable $action): mixed {
        $handle = fopen($this->directory . '/lock-' . hash('sha256', $name), 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle) fclose($handle);
            throw new Problem('Der Vorgang wird gerade verarbeitet. Bitte kurz warten.', 409);
        }
        try { return $action(); }
        finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
