<?php

declare(strict_types=1);

namespace LearningMcp;

use PDO;
use RuntimeException;
use Throwable;

final class ProjectIndex
{
    private const SCHEMA_VERSION = 2;

    private string $root;
    private string $projectId;
    private string $databasePath;
    private ?PDO $database = null;
    private int $transactionDepth = 0;

    /** @param array<string, mixed> $resolved */
    public function __construct(private readonly Config $config, array $resolved)
    {
        $repository = trim((string) ($resolved['repository'] ?? ''));
        if ($repository === '') {
            throw new RuntimeException('Resolved project is missing repository');
        }
        $root = realpath(Config::expandPath($repository));
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Project repository does not exist: ' . $repository);
        }
        $this->root = rtrim($root, DIRECTORY_SEPARATOR);
        $project = is_array($resolved['project'] ?? null) ? $resolved['project'] : [];
        $this->projectId = trim((string) ($project['id'] ?? ''));
        if ($this->projectId === '') {
            $this->projectId = 'repo:sha256:' . hash('sha256', $this->root);
        }

        $indexRoot = rtrim($config->dataDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'indexes';
        $safeProjectHash = hash('sha256', $this->projectId . "\0" . $this->root);
        $projectDirectory = $indexRoot . DIRECTORY_SEPARATOR . $safeProjectHash;
        $this->ensurePrivateDirectory($indexRoot);
        $this->ensurePrivateDirectory($projectDirectory);
        $this->databasePath = $projectDirectory . DIRECTORY_SEPARATOR . 'project.sqlite';

        $this->database = new PDO('sqlite:' . $this->databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->database->exec('PRAGMA foreign_keys = ON');
        $this->database->exec('PRAGMA busy_timeout = 5000');
        $this->database->exec('PRAGMA journal_mode = WAL');
        $this->database->exec('PRAGMA synchronous = NORMAL');
        $this->database->exec('PRAGMA temp_store = MEMORY');
        // Large FTS/vector batches otherwise checkpoint every few megabytes and
        // turn an incremental refresh into repeated full-database fsync work.
        $this->database->exec('PRAGMA wal_autocheckpoint = 32768');
        $this->enforcePrivateFiles();
        $this->migrate();
        $this->configureTrigramIndex();
        $this->initializeMetadata();
        $this->enforcePrivateFiles();
    }

    public function root(): string
    {
        return $this->root;
    }

    public function projectId(): string
    {
        return $this->projectId;
    }

    public function path(): string
    {
        return $this->databasePath;
    }

    public function pdo(): PDO
    {
        if (!$this->database instanceof PDO) {
            throw new RuntimeException('Project index is closed');
        }

        return $this->database;
    }

    public function revision(): int
    {
        return max(0, (int) $this->metadata('revision', 0));
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        $state = $this->metadata('state', []);
        if (!is_array($state)) {
            $state = [];
        }

        return array_replace($this->defaultState(), $state, ['revision' => $this->revision()]);
    }

    /** @param array<string, mixed> $state */
    public function setState(array $state): void
    {
        $state = array_replace($this->state(), $state, [
            'revision' => $this->revision(),
            'updated_at' => Clock::now(),
        ]);
        $this->putMetadata('state', $state);
    }

    public function nextRevision(): int
    {
        return $this->transaction(function (PDO $database): int {
            $revision = $this->revision() + 1;
            $this->putMetadata('revision', $revision, $database);

            return $revision;
        });
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $database = $this->pdo();
        $counts = [];
        foreach (['indexed_files', 'indexed_file_contents', 'chunks', 'symbols', 'relations', 'skills'] as $table) {
            $counts[$table] = (int) $database->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }
        $state = $this->state();

        return [
            'project_id' => $this->projectId,
            'root' => $this->root,
            'index_db' => $this->databasePath,
            'revision' => $this->revision(),
            'freshness' => (string) ($state['freshness'] ?? 'unknown'),
            'state' => $state,
            'counts' => $counts,
            'database_bytes' => is_file($this->databasePath) ? (int) filesize($this->databasePath) : 0,
            'features' => [
                'fts5_unicode61' => true,
                'fts5_trigram' => (bool) ($state['trigram_available'] ?? false),
                'sparse_feature_hash' => true,
                'compressed_file_content_store' => true,
                'batch_indexed_file_read' => true,
                'neural_embeddings' => false,
                'graph_engine' => 'sqlite_overlay',
                'external_graph_available' => (bool) ($state['external_graph_available'] ?? false),
            ],
        ];
    }

    public function missingFileContentCount(): int
    {
        return (int) $this->pdo()->query(
            'SELECT COUNT(*)
               FROM indexed_files AS f
          LEFT JOIN indexed_file_contents AS c ON c.file_id = f.id
              WHERE c.file_id IS NULL'
        )->fetchColumn();
    }

    public function normalizeRelativePath(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new RuntimeException('Path contains a NUL byte');
        }
        $path = str_replace('\\', '/', $path);
        if ($path === '' || $path === '.') {
            return '';
        }
        if (str_starts_with($path, '/')) {
            $absolute = $this->normalizeSegments($path, true);
            $root = str_replace('\\', '/', $this->root);
            if (!$this->isContained($absolute, $root)) {
                throw new RuntimeException('Absolute path escapes project root: ' . $path);
            }
            $path = ltrim(substr($absolute, strlen($root)), '/');
        }

        return $this->normalizeSegments($path, false);
    }

    public function absolutePath(string $path, bool $mustExist = false): string
    {
        $relative = $this->normalizeRelativePath($path);
        $candidate = $relative === '' ? $this->root : $this->root . DIRECTORY_SEPARATOR . $relative;
        $root = str_replace('\\', '/', $this->root);
        $cursor = $this->root;

        foreach ($relative === '' ? [] : explode('/', $relative) as $segment) {
            $cursor .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($cursor)) {
                $resolved = realpath($cursor);
                if ($resolved === false || !$this->isContained(str_replace('\\', '/', $resolved), $root)) {
                    throw new RuntimeException('Symlink escapes project root: ' . $relative);
                }
                $cursor = $resolved;
                continue;
            }
            if (file_exists($cursor)) {
                $resolved = realpath($cursor);
                if ($resolved === false || !$this->isContained(str_replace('\\', '/', $resolved), $root)) {
                    throw new RuntimeException('Resolved path escapes project root: ' . $relative);
                }
                $cursor = $resolved;
            }
        }

        if ($mustExist && !file_exists($candidate) && !is_link($candidate)) {
            throw new RuntimeException('Project path does not exist: ' . $relative);
        }
        if (is_link($candidate) && realpath($candidate) === false) {
            throw new RuntimeException('Broken symlink is not allowed: ' . $relative);
        }
        $resolved = realpath($candidate);
        if ($resolved !== false) {
            if (!$this->isContained(str_replace('\\', '/', $resolved), $root)) {
                throw new RuntimeException('Resolved path escapes project root: ' . $relative);
            }

            return $resolved;
        }

        return $candidate;
    }

    /** @return array{vendor:string,module:string,code:string,root:string}|null */
    public function moduleForPath(string $path): ?array
    {
        $relative = $this->normalizeRelativePath($path);
        if (preg_match('~^app/code/([^/]+)/([^/]+)(?:/|$)~', $relative, $matches) !== 1) {
            return null;
        }

        return [
            'vendor' => $matches[1],
            'module' => $matches[2],
            'code' => $matches[1] . '_' . $matches[2],
            'root' => 'app/code/' . $matches[1] . '/' . $matches[2],
        ];
    }

    public function transaction(callable $callback): mixed
    {
        $database = $this->pdo();
        $savepoint = 'learning_mcp_' . $this->transactionDepth;
        if ($this->transactionDepth === 0) {
            $database->beginTransaction();
        } else {
            $database->exec('SAVEPOINT ' . $savepoint);
        }
        ++$this->transactionDepth;
        try {
            $result = $callback($database);
            --$this->transactionDepth;
            if ($this->transactionDepth === 0) {
                $database->commit();
            } else {
                $database->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            $this->enforcePrivateFiles();

            return $result;
        } catch (Throwable $exception) {
            --$this->transactionDepth;
            if ($this->transactionDepth === 0 && $database->inTransaction()) {
                $database->rollBack();
            } elseif ($this->transactionDepth > 0) {
                $database->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $database->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }

    public function close(): void
    {
        if ($this->database instanceof PDO) {
            try {
                $this->database->exec('PRAGMA wal_checkpoint(PASSIVE)');
            } catch (Throwable) {
                // Active read cursors may temporarily lock the checkpoint. Closing
                // the connection still safely leaves WAL recovery to SQLite.
            }
        }
        $this->database = null;
        $this->enforcePrivateFiles();
    }

    private function migrate(): void
    {
        $storedVersion = 0;
        $metadataExists = (int) $this->pdo()->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'metadata'"
        )->fetchColumn() === 1;
        if ($metadataExists) {
            $statement = $this->pdo()->prepare(
                'SELECT value_json FROM metadata WHERE metadata_key = :key LIMIT 1'
            );
            $statement->execute(['key' => 'schema_version']);
            $stored = $statement->fetchColumn();
            if (is_string($stored)) {
                $storedVersion = max(0, (int) Json::decode($stored, 0));
            }
        }
        if ($storedVersion >= self::SCHEMA_VERSION) {
            return;
        }

        $migrations = [];
        for ($version = $storedVersion + 1; $version <= self::SCHEMA_VERSION; ++$version) {
            $matches = glob(sprintf('%s/index-migrations/%03d_*.sql', dirname(__DIR__), $version));
            if (!is_array($matches) || count($matches) !== 1) {
                throw new RuntimeException(sprintf('Expected exactly one project index migration for version %d', $version));
            }
            $sql = file_get_contents($matches[0]);
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException('Unable to read project index migration: ' . $matches[0]);
            }
            $migrations[] = $sql;
        }
        $this->transaction(static function (PDO $database) use ($migrations): void {
            foreach ($migrations as $sql) {
                $database->exec($sql);
            }
        });
    }

    private function configureTrigramIndex(): void
    {
        $database = $this->pdo();
        $definition = (string) $database->query(
            "SELECT sql FROM sqlite_master WHERE name = 'chunk_trigram' LIMIT 1"
        )->fetchColumn();
        if (stripos($definition, 'VIRTUAL TABLE') !== false) {
            return;
        }
        $available = false;
        try {
            $database->exec("CREATE VIRTUAL TABLE temp.__learning_mcp_trigram_probe USING fts5(value, tokenize='trigram')");
            $database->exec('DROP TABLE temp.__learning_mcp_trigram_probe');
            $available = true;
        } catch (Throwable) {
            try {
                $database->exec('DROP TABLE IF EXISTS temp.__learning_mcp_trigram_probe');
            } catch (Throwable) {
                // The regular compatibility table remains usable through LIKE.
            }
        }
        if (!$available) {
            return;
        }
        $this->transaction(static function (PDO $database): void {
            $database->exec('DROP TABLE chunk_trigram');
            $database->exec(
                "CREATE VIRTUAL TABLE chunk_trigram USING fts5(content, path, title, symbol_name, module, tokenize='trigram')"
            );
            $database->exec(
                "INSERT INTO chunk_trigram(rowid, content, path, title, symbol_name, module)
                 SELECT c.rowid, c.content, f.path, c.title, COALESCE(c.symbol_uid, ''),
                        trim(f.module_vendor || '/' || f.module_name, '/')
                   FROM chunks AS c JOIN indexed_files AS f ON f.id = c.file_id
                  WHERE f.kind IN ('doc', 'rule', 'skill')"
            );
        });
    }

    private function initializeMetadata(): void
    {
        $existingState = $this->metadata('state', null);
        $definition = (string) $this->pdo()->query(
            "SELECT sql FROM sqlite_master WHERE name = 'chunk_trigram' LIMIT 1"
        )->fetchColumn();
        $trigramAvailable = stripos($definition, 'VIRTUAL TABLE') !== false;
        if ((int) $this->metadata('schema_version', 0) >= self::SCHEMA_VERSION
            && $this->metadata('project_id', '') === $this->projectId
            && $this->metadata('root', '') === $this->root
            && $this->metadata('revision', null) !== null
            && is_array($existingState)
            && (bool) ($existingState['trigram_available'] ?? false) === $trigramAvailable) {
            return;
        }
        $this->transaction(function (PDO $database) use ($existingState): void {
            $this->putMetadata('schema_version', self::SCHEMA_VERSION, $database);
            $this->putMetadata('project_id', $this->projectId, $database);
            $this->putMetadata('root', $this->root, $database);
            if ($this->metadata('revision', null) === null) {
                $this->putMetadata('revision', 0, $database);
            }
            if (!is_array($existingState)) {
                $this->putMetadata('state', $this->defaultState(), $database);
            } else {
                $definition = (string) $database->query(
                    "SELECT sql FROM sqlite_master WHERE name = 'chunk_trigram' LIMIT 1"
                )->fetchColumn();
                $existingState['trigram_available'] = stripos($definition, 'VIRTUAL TABLE') !== false;
                $this->putMetadata('state', array_replace($this->defaultState(), $existingState), $database);
            }
        });
    }

    /** @return array<string, mixed> */
    private function defaultState(): array
    {
        $definition = '';
        if ($this->database instanceof PDO) {
            $definition = (string) $this->database->query(
                "SELECT sql FROM sqlite_master WHERE name = 'chunk_trigram' LIMIT 1"
            )->fetchColumn();
        }

        return [
            'phase' => 'idle',
            'freshness' => 'unknown',
            'revision' => 0,
            'last_started_at' => null,
            'last_completed_at' => null,
            'last_error' => null,
            'graph_engine' => 'sqlite_overlay',
            'external_graph_available' => false,
            'external_graph_hint' => 'An external graph may enrich results, but retrieval never invokes it per query.',
            'trigram_available' => stripos($definition, 'VIRTUAL TABLE') !== false,
            'vector_engine' => 'deterministic_sparse_feature_hash',
            'file_content_store' => 'gzip_blob_same_project_index',
            'batch_indexed_file_read' => true,
            'neural_embeddings' => false,
            'updated_at' => Clock::now(),
        ];
    }

    private function metadata(string $key, mixed $fallback): mixed
    {
        $statement = $this->pdo()->prepare('SELECT value_json FROM metadata WHERE metadata_key = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();
        if (!is_string($value)) {
            return $fallback;
        }

        return Json::decode($value, $fallback);
    }

    private function putMetadata(string $key, mixed $value, ?PDO $database = null): void
    {
        $statement = ($database ?? $this->pdo())->prepare(
            'INSERT INTO metadata(metadata_key, value_json, updated_at)
             VALUES(:key, :value, :updated_at)
             ON CONFLICT(metadata_key) DO UPDATE SET value_json = excluded.value_json, updated_at = excluded.updated_at'
        );
        $statement->execute([
            'key' => $key,
            'value' => Json::encode($value),
            'updated_at' => Clock::now(),
        ]);
    }

    private function normalizeSegments(string $path, bool $absolute): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    throw new RuntimeException('Path escapes project root: ' . $path);
                }
                array_pop($segments);
                continue;
            }
            if (str_contains($segment, "\0")) {
                throw new RuntimeException('Path contains a NUL byte');
            }
            $segments[] = $segment;
        }

        return ($absolute ? '/' : '') . implode('/', $segments);
    }

    private function isContained(string $path, string $root): bool
    {
        $path = rtrim($path, '/');
        $root = rtrim($root, '/');

        return $path === $root || str_starts_with($path, $root . '/');
    }

    private function ensurePrivateDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create private index directory: ' . $directory);
        }
        if (!chmod($directory, 0700)) {
            throw new RuntimeException('Unable to protect index directory: ' . $directory);
        }
    }

    private function enforcePrivateFiles(): void
    {
        foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm'] as $file) {
            if (is_file($file) && !chmod($file, 0600)) {
                throw new RuntimeException('Unable to protect index file: ' . $file);
            }
        }
    }
}
