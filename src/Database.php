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
        if ($version > 4) throw new \RuntimeException('Datenbank ist neuer als die Anwendung.');
        if ($version === 4) return;
        $this->transaction(function (): void {
            $this->pdo->exec((string)file_get_contents(__DIR__ . '/schema.sql'));
            foreach (['services' => ['goae_code' => "TEXT NOT NULL DEFAULT ''", 'factor' => "TEXT NOT NULL DEFAULT ''", 'fee_type' => "TEXT NOT NULL DEFAULT 'standard'", 'on_request' => 'INTEGER NOT NULL DEFAULT 0'],
                'visits' => ['patient_address' => "TEXT NOT NULL DEFAULT '[]'"], 'payments' => ['invoice_json' => 'TEXT']] as $table => $columns) {
                $existing = array_column($this->pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
                foreach ($columns as $name => $type) if (!in_array($name, $existing, true)) $this->pdo->exec("ALTER TABLE $table ADD COLUMN $name $type");
            }
            $this->pdo->exec('PRAGMA user_version=4');
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
