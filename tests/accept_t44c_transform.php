<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('DS', DIRECTORY_SEPARATOR);
defined('DEV') || define('DEV', false);
defined('PROD') || define('PROD', true);

require BP . 'app/autoload.php';
require BP . 'app/code/Weline/Framework/Common/functions.php';

use Weline\Database\Model\Release\DataTransformProgress;
use Weline\Database\Model\Release\SchemaOperationEvent;
use Weline\Database\Service\Release\DataTransformExecutor;
use Weline\Database\Service\Release\ReleaseTableInstaller;
use Weline\Database\Service\Release\SchemaJournal;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Release\Api\DataTransformInterface;
use Weline\Framework\Database\Release\Api\SchemaScopeInterface;
use Weline\Framework\Database\Schema\SchemaParser;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$scope = new class implements SchemaScopeInterface {
    public function connectionName(): string
    {
        return 'default';
    }

    public function databaseName(): string
    {
        return '';
    }

    public function schemaName(): string
    {
        return 'main';
    }

    public function shardKey(): string
    {
        return '';
    }

    public function tenantKey(): string
    {
        return '';
    }

    public function key(): string
    {
        return 'scope-t44c';
    }
};

$tmp = sys_get_temp_dir() . DS . 'weline_t44c_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '.sqlite';
$factory = null;
try {
    $config = new ConfigProvider([
        'type' => 'sqlite',
        'database' => '',
        'path' => $tmp,
        'persistent' => false,
    ]);
    $factory = ConnectionFactory::getInstance($config);
    (new ReleaseTableInstaller(new SchemaParser(), $factory))->install();
    $link = $factory->getConnector()->getLink();

    $link->exec("CREATE TABLE transform_unit (id INTEGER PRIMARY KEY, name VARCHAR(64) NOT NULL DEFAULT '', processed INTEGER NOT NULL DEFAULT 0)");
    foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
        $link->prepare('INSERT INTO transform_unit (name, processed) VALUES (:name, 0)')->execute([':name' => $name]);
    }
    $link->exec("CREATE TABLE transform_retry (id INTEGER PRIMARY KEY, name VARCHAR(64) NOT NULL DEFAULT '')");
    foreach (['w', 'x', 'y', 'z'] as $name) {
        $link->prepare('INSERT INTO transform_retry (name) VALUES (:name)')->execute([':name' => $name]);
    }

    $transform = new class ($link, $scope) implements DataTransformInterface {
        public function __construct(
            private readonly PDO $link,
            private readonly SchemaScopeInterface $scope,
        ) {
        }

        public function id(): string
        {
            return 't44c-transform-unit';
        }

        public function scope(): SchemaScopeInterface
        {
            return $this->scope;
        }

        public function fromVersion(): string
        {
            return '1.0.0';
        }

        public function toVersion(): string
        {
            return '1.1.0';
        }

        public function table(): string
        {
            return 'transform_unit';
        }

        public function estimateRows(): int
        {
            return 5;
        }

        public function plan(): array
        {
            return ['columns' => ['name', 'processed']];
        }

        public function applyBatch(array $cursor, int $limit): array
        {
            $lastId = (int) ($cursor['id'] ?? 0);
            $select = $this->link->prepare('SELECT id FROM transform_unit WHERE id > :id ORDER BY id ASC LIMIT :limit');
            $select->bindValue(':id', $lastId, PDO::PARAM_INT);
            $select->bindValue(':limit', $limit, PDO::PARAM_INT);
            $select->execute();
            $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));

            $update = $this->link->prepare("UPDATE transform_unit SET name = name || '_x', processed = 1 WHERE id = :id");
            foreach ($ids as $id) {
                $update->execute([':id' => $id]);
            }

            $nextId = $ids === [] ? $lastId : (int) end($ids);

            return [
                'done' => count($ids) < $limit,
                'cursor' => ['id' => $nextId],
                'processed' => count($ids),
                'errors' => [],
            ];
        }

        public function verify(array $cursor): array
        {
            $processed = (int) $this->link->query('SELECT COUNT(*) FROM transform_unit WHERE processed = 1')->fetchColumn();

            return ['ok' => $processed === 5, 'processed' => $processed];
        }

        public function inverse(): ?DataTransformInterface
        {
            return null;
        }

        public function requiresSnapshot(): bool
        {
            return false;
        }

        public function batchSize(): int
        {
            return 2;
        }

        public function throttleMs(): int
        {
            return 0;
        }
    };

    $journal = new SchemaJournal($factory, new SchemaOperationEvent());
    $executor = new DataTransformExecutor($factory, new DataTransformProgress(), $journal);

    $result = $executor->run($transform, 'rel-t44c');
    $check(($result['status'] ?? null) === 'done', 'run must finish with status done');
    $check(($result['rows'] ?? null) === 5, 'run must process 5 rows, got ' . (int) ($result['rows'] ?? 0));
    $check(($result['batches'] ?? null) === 3, 'run must use 3 batches, got ' . (int) ($result['batches'] ?? 0));

    $progressRows = $link
        ->query("SELECT status, rows_done, cursor_json FROM weline_schema_data_transform_progress WHERE transform_id = 't44c-transform-unit'")
        ->fetchAll(PDO::FETCH_ASSOC);
    $check(count($progressRows) === 1, 'progress must keep exactly one row per release/transform/scope');
    $check(($progressRows[0]['status'] ?? null) === DataTransformProgress::STATUS_DONE, 'progress status must be done');
    $check((int) ($progressRows[0]['rows_done'] ?? 0) === 5, 'progress rows_done must be 5');
    $check(json_decode((string) ($progressRows[0]['cursor_json'] ?? ''), true) === ['id' => 5], 'progress cursor must persist the last id');

    $events = $journal->events('rel-t44c');
    $states = array_map(static fn(array $row): string => (string) $row['state'], $events);
    $check(count(array_filter($states, static fn(string $state): bool => $state === 'transformed')) === 3, 'journal must record one transformed event per batch');
    $check(end($states) === 'verified', 'journal must record a verified event last');
    $check($journal->latestState('scope-t44c', 'transform:t44c-transform-unit') === 'verified', 'latestState must project verified');

    $again = $executor->run($transform, 'rel-t44c');
    $check(($again['status'] ?? null) === 'already_done', 'second run must return already_done');
    $check(($again['rows'] ?? null) === 5, 'already_done must report the persisted row count');
    $check(($again['batches'] ?? null) === 0, 'already_done must not run batches');
    $check(count($journal->events('rel-t44c')) === count($events), 'already_done must not append journal events');

    $names = $link->query('SELECT name FROM transform_unit ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $check($names === ['a_x', 'b_x', 'c_x', 'd_x', 'e_x'], 'second run must not reprocess rows');

    $failing = new class ($link, $scope) implements DataTransformInterface {
        public bool $failSecondBatch = true;

        public int $calls = 0;

        public function __construct(
            private readonly PDO $link,
            private readonly SchemaScopeInterface $scope,
        ) {
        }

        public function id(): string
        {
            return 't44c-retry';
        }

        public function scope(): SchemaScopeInterface
        {
            return $this->scope;
        }

        public function fromVersion(): string
        {
            return '1.0.0';
        }

        public function toVersion(): string
        {
            return '1.1.0';
        }

        public function table(): string
        {
            return 'transform_retry';
        }

        public function estimateRows(): int
        {
            return 4;
        }

        public function plan(): array
        {
            return ['columns' => ['name']];
        }

        public function applyBatch(array $cursor, int $limit): array
        {
            ++$this->calls;
            if ($this->failSecondBatch && $this->calls === 2) {
                return [
                    'done' => false,
                    'cursor' => $cursor,
                    'processed' => 0,
                    'errors' => ['simulated failure'],
                ];
            }

            $lastId = (int) ($cursor['id'] ?? 0);
            $select = $this->link->prepare('SELECT id FROM transform_retry WHERE id > :id ORDER BY id ASC LIMIT :limit');
            $select->bindValue(':id', $lastId, PDO::PARAM_INT);
            $select->bindValue(':limit', $limit, PDO::PARAM_INT);
            $select->execute();
            $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));

            $update = $this->link->prepare("UPDATE transform_retry SET name = name || '_r' WHERE id = :id");
            foreach ($ids as $id) {
                $update->execute([':id' => $id]);
            }

            $nextId = $ids === [] ? $lastId : (int) end($ids);

            return [
                'done' => count($ids) < $limit,
                'cursor' => ['id' => $nextId],
                'processed' => count($ids),
                'errors' => [],
            ];
        }

        public function verify(array $cursor): array
        {
            $processed = (int) $this->link->query("SELECT COUNT(*) FROM transform_retry WHERE name LIKE '%\\_r' ESCAPE '\\'")->fetchColumn();

            return ['ok' => $processed === 4, 'processed' => $processed];
        }

        public function inverse(): ?DataTransformInterface
        {
            return null;
        }

        public function requiresSnapshot(): bool
        {
            return false;
        }

        public function batchSize(): int
        {
            return 3;
        }

        public function throttleMs(): int
        {
            return 0;
        }
    };

    $failed = false;
    try {
        $executor->run($failing, 'rel-t44c');
    } catch (RuntimeException) {
        $failed = true;
    }
    $check($failed, 'a transform reporting errors must fail the run');

    $failedProgress = $link
        ->query("SELECT status, rows_done, cursor_json FROM weline_schema_data_transform_progress WHERE transform_id = 't44c-retry'")
        ->fetchAll(PDO::FETCH_ASSOC);
    $check(count($failedProgress) === 1, 'failed transform must persist one progress row');
    $check(($failedProgress[0]['status'] ?? null) === DataTransformProgress::STATUS_FAILED, 'failed transform progress status must be failed');
    $check((int) ($failedProgress[0]['rows_done'] ?? 0) === 3, 'failed transform must keep completed rows');
    $check(json_decode((string) ($failedProgress[0]['cursor_json'] ?? ''), true) === ['id' => 3], 'failed transform must keep its cursor for retry');

    $failing->failSecondBatch = false;
    $failing->calls = 0;
    $retry = $executor->run($failing, 'rel-t44c');
    $check(($retry['status'] ?? null) === 'done', 'retry must finish with status done');
    $check(($retry['rows'] ?? null) === 4, 'retry must accumulate 4 rows, got ' . (int) ($retry['rows'] ?? 0));
    $check(($retry['batches'] ?? null) === 1, 'retry must resume from the stored cursor in 1 batch');

    $retryProgress = $link
        ->query("SELECT status, rows_done FROM weline_schema_data_transform_progress WHERE transform_id = 't44c-retry'")
        ->fetchAll(PDO::FETCH_ASSOC);
    $check(($retryProgress[0]['status'] ?? null) === DataTransformProgress::STATUS_DONE, 'retried progress status must be done');
    $check((int) ($retryProgress[0]['rows_done'] ?? 0) === 4, 'retried progress rows_done must be 4');

    $retryNames = $link->query('SELECT name FROM transform_retry ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $check($retryNames === ['w_r', 'x_r', 'y_r', 'z_r'], 'retry must not reprocess the first batch');
} finally {
    if ($factory instanceof ConnectionFactory) {
        try {
            $factory->close();
        } catch (Throwable) {
            // ignore close failure during cleanup
        }
    }
    foreach ([$tmp, $tmp . '-wal', $tmp . '-shm', $tmp . '-journal'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'T44C TRANSFORM PASS', PHP_EOL;
