<?php

declare(strict_types=1);

namespace Weline\Database\Service\Release;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Weline\Database\Model\Release\DataTransformProgress;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Release\Api\DataTransformInterface;

/**
 * Runs a DataTransform in bounded, resumable batches and records progress + journal events.
 *
 * Progress is persisted after every batch so a failed run resumes from its cursor.
 * No DDL and no legacy migration domain is used.
 */
final class DataTransformExecutor
{
    public const DEFAULT_MAX_BATCHES = 10000;

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly DataTransformProgress $progressModel,
        private readonly SchemaJournal $journal,
    ) {
        $this->progressModel->setConnection($this->connectionFactory);
    }

    /**
     * @param array<string, mixed> $options batch_size, max_batches, operation_id, module_name, actor, source
     * @return array{status: string, rows: int, batches: int, cursor: array<string, mixed>}
     */
    public function run(DataTransformInterface $transform, string $releaseId, array $options = []): array
    {
        $transformId = trim($transform->id());
        $scopeKey = trim($transform->scope()->key());
        $table = trim($transform->table());
        if ($releaseId === '' || $transformId === '' || $scopeKey === '' || $table === '') {
            throw new InvalidArgumentException('Data transform requires non-empty release id, transform id, scope and table');
        }

        $progress = $this->findProgress($releaseId, $transformId, $scopeKey);
        if ($progress !== null && (string) $progress[DataTransformProgress::schema_fields_STATUS] === DataTransformProgress::STATUS_DONE) {
            return [
                'status' => 'already_done',
                'rows' => (int) $progress[DataTransformProgress::schema_fields_ROWS_DONE],
                'batches' => 0,
                'cursor' => $this->decodeCursor($progress[DataTransformProgress::schema_fields_CURSOR_JSON] ?? null),
            ];
        }

        $progressId = $progress !== null ? (int) $progress[DataTransformProgress::schema_fields_ID] : null;
        $cursor = $this->decodeCursor($progress[DataTransformProgress::schema_fields_CURSOR_JSON] ?? null);
        $rowsDone = $progress !== null ? (int) $progress[DataTransformProgress::schema_fields_ROWS_DONE] : 0;

        $batchSize = max(1, (int) ($options['batch_size'] ?? $transform->batchSize()));
        $maxBatches = max(1, (int) ($options['max_batches'] ?? self::DEFAULT_MAX_BATCHES));
        $operationId = (string) ($options['operation_id'] ?? $transformId);
        $moduleName = (string) ($options['module_name'] ?? '');
        $actor = (string) ($options['actor'] ?? 'cli');
        $source = (string) ($options['source'] ?? 'cli');

        $batches = 0;
        $completed = false;
        while ($batches < $maxBatches) {
            try {
                $result = $transform->applyBatch($cursor, $batchSize);
            } catch (Throwable $exception) {
                $this->persistProgress(
                    $progressId,
                    $releaseId,
                    $transformId,
                    $scopeKey,
                    $table,
                    $cursor,
                    $rowsDone,
                    DataTransformProgress::STATUS_FAILED
                );
                throw $exception;
            }

            if (!$this->isValidBatchResult($result)) {
                $this->persistProgress(
                    $progressId,
                    $releaseId,
                    $transformId,
                    $scopeKey,
                    $table,
                    $cursor,
                    $rowsDone,
                    DataTransformProgress::STATUS_FAILED
                );
                throw new RuntimeException('applyBatch must return done(bool), cursor(array) and processed(int)');
            }

            $errors = $result['errors'] ?? [];
            if (!is_array($errors)) {
                $errors = [(string) $errors];
            }
            if ($errors !== []) {
                $this->persistProgress(
                    $progressId,
                    $releaseId,
                    $transformId,
                    $scopeKey,
                    $table,
                    $cursor,
                    $rowsDone,
                    DataTransformProgress::STATUS_FAILED
                );
                throw new RuntimeException(
                    'Data transform ' . $transformId . ' failed: ' . implode('; ', $errors)
                );
            }

            $processed = (int) $result['processed'];
            $rowsDone += $processed;
            $cursor = $result['cursor'];
            ++$batches;

            $progressId = $this->persistProgress(
                $progressId,
                $releaseId,
                $transformId,
                $scopeKey,
                $table,
                $cursor,
                $rowsDone,
                DataTransformProgress::STATUS_RUNNING
            );

            $this->journal->append([
                'release_id' => $releaseId,
                'operation_id' => $operationId,
                'scope_key' => $scopeKey,
                'node_id' => 'transform:' . $transformId,
                'state' => 'transformed',
                'module_name' => $moduleName,
                'table_name' => $table,
                'payload_hash' => $this->cursorHash($cursor),
                'actor' => $actor,
                'source' => $source,
                'detail_json' => (string) json_encode([
                    'batch' => $batches,
                    'processed' => $processed,
                    'rows_done' => $rowsDone,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            if ($result['done'] === true) {
                $completed = true;
                break;
            }
        }

        if (!$completed) {
            $this->persistProgress(
                $progressId,
                $releaseId,
                $transformId,
                $scopeKey,
                $table,
                $cursor,
                $rowsDone,
                DataTransformProgress::STATUS_FAILED
            );
            throw new RuntimeException(
                'Data transform ' . $transformId . ' exceeded max_batches=' . $maxBatches
            );
        }

        try {
            $verification = $transform->verify($cursor);
        } catch (Throwable $exception) {
            $this->persistProgress(
                $progressId,
                $releaseId,
                $transformId,
                $scopeKey,
                $table,
                $cursor,
                $rowsDone,
                DataTransformProgress::STATUS_FAILED
            );
            throw $exception;
        }

        if (!is_array($verification) || ($verification['ok'] ?? null) !== true) {
            $this->persistProgress(
                $progressId,
                $releaseId,
                $transformId,
                $scopeKey,
                $table,
                $cursor,
                $rowsDone,
                DataTransformProgress::STATUS_FAILED
            );
            throw new RuntimeException('Data transform ' . $transformId . ' verification failed');
        }

        $this->persistProgress(
            $progressId,
            $releaseId,
            $transformId,
            $scopeKey,
            $table,
            $cursor,
            $rowsDone,
            DataTransformProgress::STATUS_DONE
        );

        $this->journal->append([
            'release_id' => $releaseId,
            'operation_id' => $operationId,
            'scope_key' => $scopeKey,
            'node_id' => 'transform:' . $transformId,
            'state' => 'verified',
            'module_name' => $moduleName,
            'table_name' => $table,
            'payload_hash' => $this->cursorHash($cursor),
            'actor' => $actor,
            'source' => $source,
            'detail_json' => (string) json_encode([
                'batches' => $batches,
                'rows' => $rowsDone,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return [
            'status' => 'done',
            'rows' => $rowsDone,
            'batches' => $batches,
            'cursor' => $cursor,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findProgress(string $releaseId, string $transformId, string $scopeKey): ?array
    {
        $rows = $this->newProgressModel()
            ->where(DataTransformProgress::schema_fields_RELEASE_ID, $releaseId)
            ->where(DataTransformProgress::schema_fields_TRANSFORM_ID, $transformId)
            ->where(DataTransformProgress::schema_fields_SCOPE_KEY, $scopeKey)
            ->limit(1)
            ->select()
            ->fetchArray();

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $cursor
     */
    private function persistProgress(
        ?int $progressId,
        string $releaseId,
        string $transformId,
        string $scopeKey,
        string $table,
        array $cursor,
        int $rowsDone,
        string $status,
    ): int {
        $data = [
            DataTransformProgress::schema_fields_RELEASE_ID => $releaseId,
            DataTransformProgress::schema_fields_TRANSFORM_ID => $transformId,
            DataTransformProgress::schema_fields_SCOPE_KEY => $scopeKey,
            DataTransformProgress::schema_fields_TABLE_NAME => $table,
            DataTransformProgress::schema_fields_CURSOR_JSON => (string) json_encode($cursor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            DataTransformProgress::schema_fields_ROWS_DONE => $rowsDone,
            DataTransformProgress::schema_fields_CHECKSUM => $this->cursorHash($cursor),
            DataTransformProgress::schema_fields_STATUS => $status,
            DataTransformProgress::schema_fields_UPDATED_AT => date('Y-m-d H:i:s'),
        ];

        $model = $this->newProgressModel();

        if ($progressId !== null) {
            $model
                ->where($this->primaryKey(), $progressId)
                ->update($data)
                ->fetch();

            return $progressId;
        }

        $model->setData($data);
        $result = $model->save();
        $insertedId = is_int($result) ? $result : (int) $model->getId();
        if ($insertedId <= 0) {
            throw new RuntimeException('Data transform progress could not be persisted for ' . $transformId);
        }

        return $insertedId;
    }

    private function primaryKey(): string
    {
        $primaryKey = $this->progressModel->getIdField();

        return $primaryKey !== '' ? $primaryKey : DataTransformProgress::schema_primary_key;
    }

    /**
     * @param mixed $result
     */
    private function isValidBatchResult(mixed $result): bool
    {
        return is_array($result)
            && array_key_exists('done', $result)
            && is_bool($result['done'])
            && array_key_exists('cursor', $result)
            && is_array($result['cursor'])
            && array_key_exists('processed', $result)
            && is_int($result['processed']);
    }

    private function newProgressModel(): DataTransformProgress
    {
        $model = clone $this->progressModel;
        $model->setConnection($this->connectionFactory);
        $model->clearDataObject();
        $model->clearQuery();

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeCursor(mixed $cursorJson): array
    {
        if (!is_string($cursorJson) || $cursorJson === '') {
            return [];
        }
        $decoded = json_decode($cursorJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $cursor
     */
    private function cursorHash(array $cursor): string
    {
        return hash(
            'sha256',
            (string) json_encode($cursor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
