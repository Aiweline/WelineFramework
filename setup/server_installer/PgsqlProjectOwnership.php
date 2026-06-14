<?php

declare(strict_types=1);

final class PgsqlProjectOwnership
{
    private const MARKER_VERSION = 1;
    private const MARKER_TABLE = 'weline_project_owner';

    private string $projectRoot;
    private string $envPhpPath;
    private string $pgsqlDir;
    private string $dataDir;
    private string $markerPath;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $this->envPhpPath = $this->projectRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'env.php';
        $this->pgsqlDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'extend' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'pgsql';
        $this->dataDir = $this->pgsqlDir . DIRECTORY_SEPARATOR . 'data';
        $this->markerPath = $this->pgsqlDir . DIRECTORY_SEPARATOR . 'project-owner.json';
    }

    public function dataDir(): string
    {
        return $this->dataDir;
    }

    public function markerPath(): string
    {
        return $this->markerPath;
    }

    public function hasProjectData(): bool
    {
        return is_file($this->dataDir . DIRECTORY_SEPARATOR . 'PG_VERSION');
    }

    public function envPhpHasDb(): bool
    {
        $config = $this->readEnvPhpConfig();
        return isset($config['db']) && is_array($config['db']) && $config['db'] !== [];
    }

    public function readEnvPhpConfig(): array
    {
        if (!is_file($this->envPhpPath) || filesize($this->envPhpPath) < 10) {
            return [];
        }
        $config = @include $this->envPhpPath;
        return is_array($config) ? $config : [];
    }

    public function targetFromEnvPhp(): ?array
    {
        $config = $this->readEnvPhpConfig();
        $master = $config['db']['master'] ?? null;
        if (!is_array($master) || $master === []) {
            return null;
        }

        $host = trim((string)($master['hostname'] ?? $master['host'] ?? ''));
        $port = trim((string)($master['hostport'] ?? $master['port'] ?? ''));
        $type = strtolower(trim((string)($master['type'] ?? 'pgsql')));

        return [
            'source' => 'app/etc/env.php',
            'type' => $type !== '' ? $type : 'pgsql',
            'host' => $host !== '' ? $host : '127.0.0.1',
            'port' => $port !== '' ? $port : '5432',
            'database' => trim((string)($master['database'] ?? '')),
            'username' => trim((string)($master['username'] ?? '')),
            'password' => (string)($master['password'] ?? ''),
            'prefix' => (string)($master['prefix'] ?? 'm_'),
            'charset' => (string)($master['charset'] ?? 'UTF8'),
        ];
    }

    public function printTargetSummary(?array $target, string $label = 'database target'): void
    {
        if ($target === null) {
            echo "Database target ({$label}): not configured in app/etc/env.php.\n";
            return;
        }

        $host = $target['host'] ?? '';
        $port = $target['port'] ?? '';
        $database = $target['database'] ?? '';
        $username = $target['username'] ?? '';
        $type = $target['type'] ?? '';
        echo "Database target ({$label}): type={$type}, host={$host}, port={$port}, database={$database}, username={$username}, password=***\n";
    }

    public function classifyEnvTarget(?array $target): array
    {
        if ($target === null) {
            return [
                'mode' => 'first-install',
                'manageable' => true,
                'skip_pgsql' => false,
                'error' => false,
                'message' => 'No db.master in env.php; installer may create this project PostgreSQL.',
            ];
        }

        if (($target['type'] ?? 'pgsql') !== 'pgsql') {
            return [
                'mode' => 'non-pgsql',
                'manageable' => false,
                'skip_pgsql' => true,
                'error' => false,
                'message' => 'env.php points to a non-pgsql database; local PostgreSQL management is skipped.',
            ];
        }

        if (!$this->isLocalHost((string)($target['host'] ?? ''))) {
            return [
                'mode' => 'external-pgsql',
                'manageable' => false,
                'skip_pgsql' => true,
                'error' => false,
                'message' => 'env.php points to an external PostgreSQL host; local PostgreSQL management is skipped.',
            ];
        }

        $marker = $this->readFileMarker();
        if ($marker === null) {
            if (!$this->hasProjectData()) {
                if ($this->looksLikeGeneratedProjectDatabase($target)) {
                    return [
                        'mode' => 'project-data-missing',
                        'manageable' => false,
                        'skip_pgsql' => false,
                        'error' => true,
                        'message' => 'env.php looks like this project local database, but extend/server/pgsql/data is missing. Restore data or delete env.php for a fresh install.',
                    ];
                }
                return [
                    'mode' => 'local-unowned',
                    'manageable' => false,
                    'skip_pgsql' => true,
                    'error' => false,
                    'message' => 'env.php points to a local PostgreSQL database without this project marker; installer will not take ownership.',
                ];
            }

            return [
                'mode' => 'local-data-unowned',
                'manageable' => false,
                'skip_pgsql' => true,
                'error' => false,
                'message' => 'Project PostgreSQL data exists but has no ownership marker; installer will not manage it automatically.',
            ];
        }

        if (!$this->markerMatchesTarget($marker, $target)) {
            return [
                'mode' => 'local-other-db',
                'manageable' => false,
                'skip_pgsql' => true,
                'error' => false,
                'message' => 'env.php points to a local PostgreSQL database that is not owned by this project marker; local PostgreSQL management is skipped.',
            ];
        }

        if (!$this->hasProjectData()) {
            return [
                'mode' => 'project-data-missing',
                'manageable' => false,
                'skip_pgsql' => false,
                'error' => true,
                'message' => 'This project owns the env.php database, but extend/server/pgsql/data is missing. Restore data or delete env.php for a fresh install.',
            ];
        }

        return [
            'mode' => 'project-local',
            'manageable' => true,
            'skip_pgsql' => false,
            'error' => false,
            'message' => 'env.php points to this project local PostgreSQL; ownership marker allows installer self-healing.',
        ];
    }

    public function verifyTargetConnection(array $target): bool
    {
        if (($target['type'] ?? 'pgsql') !== 'pgsql') {
            echo "Database connection check: skipped by PostgreSQL installer for non-pgsql target.\n";
            return true;
        }
        if (!extension_loaded('pdo_pgsql')) {
            echo "Database connection check failed: PHP extension pdo_pgsql is not loaded.\n";
            return false;
        }
        $pdo = $this->connectTarget($target);
        if ($pdo === null) {
            return false;
        }
        echo "Database connection check OK (env.php).\n";
        return true;
    }

    public function ensureOwnershipMarkers(array $target): bool
    {
        if (($target['type'] ?? 'pgsql') !== 'pgsql' || !$this->isLocalHost((string)($target['host'] ?? ''))) {
            echo "Ownership marker skipped: env.php target is not project-local PostgreSQL.\n";
            return true;
        }
        if (!$this->hasProjectData()) {
            echo "Ownership marker failed: PostgreSQL data directory is missing.\n";
            return false;
        }

        $pdo = $this->connectTarget($target);
        if ($pdo === null) {
            return false;
        }

        $marker = $this->readFileMarker();
        if ($marker === null || !$this->markerMatchesTarget($marker, $target)) {
            $marker = $this->buildMarker($target);
        } else {
            $marker['updated_at'] = gmdate('c');
            $marker['data_pg_major'] = $this->readDataMajorVersion() ?? ($marker['data_pg_major'] ?? '');
        }

        if (!$this->writeDatabaseMarker($pdo, $marker)) {
            return false;
        }
        if (!$this->writeFileMarkerAtomic($marker)) {
            echo "Ownership marker failed: cannot write {$this->markerPath}.\n";
            return false;
        }

        echo "PostgreSQL ownership marker OK: install_id={$marker['install_id']}.\n";
        return true;
    }

    public function validateOwnershipMarkers(array $target): bool
    {
        $fileMarker = $this->readFileMarker();
        if ($fileMarker === null) {
            echo "PostgreSQL ownership check failed: file marker is missing ({$this->markerPath}).\n";
            return false;
        }
        if (!$this->markerMatchesTarget($fileMarker, $target)) {
            echo "PostgreSQL ownership check failed: env.php database/user does not match file marker.\n";
            return false;
        }

        $pdo = $this->connectTarget($target);
        if ($pdo === null) {
            return false;
        }
        $dbMarker = $this->readDatabaseMarker($pdo);
        if ($dbMarker === null) {
            echo "PostgreSQL ownership check failed: database marker is missing.\n";
            return false;
        }
        if (($dbMarker['install_id'] ?? '') !== ($fileMarker['install_id'] ?? '')) {
            echo "PostgreSQL ownership check failed: file marker and database marker install_id mismatch.\n";
            return false;
        }
        echo "PostgreSQL ownership check OK: install_id={$fileMarker['install_id']}.\n";
        return true;
    }

    public function updateEnvPhpDbPort(int $port): bool
    {
        if ($port <= 0 || $port > 65535) {
            return false;
        }
        $config = $this->readEnvPhpConfig();
        if (!isset($config['db']['master']) || !is_array($config['db']['master'])) {
            return false;
        }
        $current = (int)($config['db']['master']['hostport'] ?? $config['db']['master']['port'] ?? 0);
        if ($current === $port) {
            return true;
        }
        $config['db']['master']['hostport'] = (string)$port;
        if (isset($config['sandbox_db']['master']) && is_array($config['sandbox_db']['master'])) {
            $config['sandbox_db']['master']['hostport'] = (string)$port;
        }
        if (!$this->writePhpArrayFileAtomic($this->envPhpPath, $config)) {
            echo "Failed to update env.php PostgreSQL port.\n";
            return false;
        }
        echo "Updated app/etc/env.php PostgreSQL port to {$port}.\n";
        return true;
    }

    public function withInstallLock(callable $callback): mixed
    {
        $dir = $this->projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'process';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $dir . DIRECTORY_SEPARATOR . 'pgsql_install.lock';
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            echo "ERROR: cannot open PostgreSQL install lock: {$path}\n";
            return false;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            echo "ERROR: cannot acquire PostgreSQL install lock: {$path}\n";
            return false;
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function buildProjectDatabaseName(): string
    {
        $base = basename($this->projectRoot) ?: 'project';
        $slug = strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '_', $base));
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = 'project';
        }
        if (preg_match('/^[0-9]/', $slug) === 1) {
            $slug = 'p_' . $slug;
        }

        $hash = substr(sha1(strtolower(str_replace('\\', '/', $this->projectRoot))), 0, 8);
        $prefix = 'weline_';
        $maxSlugLength = 63 - strlen($prefix) - 1 - strlen($hash);
        if (strlen($slug) > $maxSlugLength) {
            $slug = substr($slug, 0, $maxSlugLength);
            $slug = rtrim($slug, '_');
        }
        return $prefix . $slug . '_' . $hash;
    }

    public function readFileMarker(): ?array
    {
        if (!is_file($this->markerPath)) {
            return null;
        }
        $json = @file_get_contents($this->markerPath);
        if (!is_string($json) || trim($json) === '') {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    public function readDataMajorVersion(): ?string
    {
        $path = $this->dataDir . DIRECTORY_SEPARATOR . 'PG_VERSION';
        if (!is_file($path)) {
            return null;
        }
        $version = trim((string)@file_get_contents($path));
        if ($version === '') {
            return null;
        }
        if (preg_match('/^([0-9]+)/', $version, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    public function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host));
        return $host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
    }

    private function markerMatchesTarget(array $marker, array $target): bool
    {
        $database = (string)($target['database'] ?? '');
        $username = (string)($target['username'] ?? '');
        if ($database === '' || $username === '') {
            return false;
        }
        return ($marker['database'] ?? '') === $database
            && ($marker['username'] ?? '') === $username
            && ($marker['project_root_hash'] ?? '') === $this->projectRootHash();
    }

    private function looksLikeGeneratedProjectDatabase(array $target): bool
    {
        $database = (string)($target['database'] ?? '');
        return $database !== '' && $database === $this->buildProjectDatabaseName();
    }

    private function buildMarker(array $target): array
    {
        return [
            'marker_version' => self::MARKER_VERSION,
            'install_id' => $this->generateInstallId(),
            'project_root_hash' => $this->projectRootHash(),
            'database' => (string)($target['database'] ?? ''),
            'username' => (string)($target['username'] ?? ''),
            'data_dir' => str_replace('\\', '/', $this->dataDir),
            'data_pg_major' => $this->readDataMajorVersion() ?? '',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];
    }

    private function generateInstallId(): string
    {
        try {
            $hex = bin2hex(random_bytes(16));
        } catch (Throwable) {
            $hex = substr(hash('sha256', $this->projectRoot . microtime(true)), 0, 32);
        }
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private function projectRootHash(): string
    {
        return hash('sha256', strtolower(str_replace('\\', '/', $this->projectRoot)));
    }

    private function connectTarget(array $target): ?PDO
    {
        try {
            $host = (string)($target['host'] ?? '127.0.0.1');
            $port = (string)($target['port'] ?? '5432');
            $database = (string)($target['database'] ?? '');
            $username = (string)($target['username'] ?? '');
            $password = (string)($target['password'] ?? '');
            $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
            return new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            echo "Database connection error: " . $e->getMessage() . "\n";
            return null;
        }
    }

    private function writeDatabaseMarker(PDO $pdo, array $marker): bool
    {
        try {
            $table = self::MARKER_TABLE;
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS {$table} (" .
                "id VARCHAR(32) PRIMARY KEY, " .
                "install_id VARCHAR(64) NOT NULL, " .
                "project_root_hash VARCHAR(64) NOT NULL, " .
                "database_name VARCHAR(128) NOT NULL, " .
                "username VARCHAR(128) NOT NULL, " .
                "marker_json TEXT NOT NULL, " .
                "created_at VARCHAR(40) NOT NULL, " .
                "updated_at VARCHAR(40) NOT NULL)"
            );
            $stmt = $pdo->prepare(
                "INSERT INTO {$table} (id, install_id, project_root_hash, database_name, username, marker_json, created_at, updated_at) " .
                "VALUES ('project', :install_id, :project_root_hash, :database_name, :username, :marker_json, :created_at, :updated_at) " .
                "ON CONFLICT (id) DO UPDATE SET " .
                "install_id = EXCLUDED.install_id, " .
                "project_root_hash = EXCLUDED.project_root_hash, " .
                "database_name = EXCLUDED.database_name, " .
                "username = EXCLUDED.username, " .
                "marker_json = EXCLUDED.marker_json, " .
                "updated_at = EXCLUDED.updated_at"
            );
            $stmt->execute([
                ':install_id' => (string)$marker['install_id'],
                ':project_root_hash' => (string)$marker['project_root_hash'],
                ':database_name' => (string)$marker['database'],
                ':username' => (string)$marker['username'],
                ':marker_json' => json_encode($marker, JSON_UNESCAPED_SLASHES),
                ':created_at' => (string)$marker['created_at'],
                ':updated_at' => (string)$marker['updated_at'],
            ]);
            return true;
        } catch (Throwable $e) {
            echo "Ownership marker database write failed: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function readDatabaseMarker(PDO $pdo): ?array
    {
        try {
            $table = self::MARKER_TABLE;
            $stmt = $pdo->query("SELECT install_id, project_root_hash, database_name, username, marker_json FROM {$table} WHERE id = 'project'");
            if ($stmt === false) {
                return null;
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }
            $json = json_decode((string)($row['marker_json'] ?? ''), true);
            if (is_array($json)) {
                return $json;
            }
            return [
                'install_id' => (string)($row['install_id'] ?? ''),
                'project_root_hash' => (string)($row['project_root_hash'] ?? ''),
                'database' => (string)($row['database_name'] ?? ''),
                'username' => (string)($row['username'] ?? ''),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function writeFileMarkerAtomic(array $marker): bool
    {
        $dir = dirname($this->markerPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($marker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }
        return $this->writeAtomic($this->markerPath, $json . PHP_EOL);
    }

    private function writePhpArrayFileAtomic(string $path, array $config): bool
    {
        $php = '<?php return ' . var_export($config, true) . ';';
        return $this->writeAtomic($path, $php);
    }

    private function writeAtomic(string $path, string $content): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        try {
            $suffix = bin2hex(random_bytes(4));
        } catch (Throwable) {
            $suffix = (string)getmypid();
        }
        $tmp = $path . '.tmp.' . $suffix;
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            return false;
        }
        if (is_file($path)) {
            $perms = @fileperms($path);
            if ($perms !== false) {
                @chmod($tmp, $perms & 0777);
            }
        }
        if (@rename($tmp, $path)) {
            return true;
        }
        @unlink($tmp);
        return false;
    }
}
