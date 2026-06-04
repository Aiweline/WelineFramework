<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Console\AiSite;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionArtifactService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeManifestPolicy;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

final class CompactSessions implements CommandInterface
{
    public const ALIASES = [
        'ai-site:sessions:compact',
    ];

    private const DEFAULT_BATCH_SIZE = 25;
    private const DEFAULT_MIN_BYTES = 1048576;
    private const MAX_SAMPLE_ROWS = 20;

    /**
     * @var array<string, true>
     */
    private const ACTIVE_STATUSES = [
        'generating' => true,
        'in_progress' => true,
        'pending' => true,
        'processing' => true,
        'queued' => true,
        'running' => true,
        'streaming' => true,
        'working' => true,
    ];

    public function execute(array $args = [], array $data = []): string
    {
        $apply = $this->boolOption($args, $data, ['apply'], false);
        $format = \strtolower((string)$this->option($args, $data, ['format'], 'text'));
        $batchSize = $this->intOption($args, $data, ['batch_size', 'batch-size', 'batch'], self::DEFAULT_BATCH_SIZE, 1, 200);
        $minBytes = $this->intOption($args, $data, ['min_bytes', 'min-bytes'], self::DEFAULT_MIN_BYTES, 0, \PHP_INT_MAX);
        $limit = $this->intOption($args, $data, ['limit'], 0, 0, \PHP_INT_MAX);
        $sessionId = $this->intOption($args, $data, ['session_id', 'session-id', 'id'], 0, 0, \PHP_INT_MAX);
        $adminId = $this->intOption($args, $data, ['admin_id', 'admin-id', 'admin'], 0, 0, \PHP_INT_MAX);
        $includeRunning = $this->boolOption($args, $data, ['include_running', 'include-running'], false);

        /** @var AiSiteAgentSession $sessionModel */
        $sessionModel = ObjectManager::getInstance(AiSiteAgentSession::class);
        /** @var AiSiteAgentSessionArtifactService $artifactService */
        $artifactService = ObjectManager::getInstance(AiSiteAgentSessionArtifactService::class);
        /** @var ConnectionFactory $connectionFactory */
        $connectionFactory = ObjectManager::getInstance(ConnectionFactory::class);
        $connector = $connectionFactory->getConnector();
        if (!$connector instanceof ConnectorInterface) {
            return $this->emitResult($format, [
                'success' => false,
                'message' => 'Database connector is not available.',
            ]);
        }

        $connection = $connector->getWrappedConnection();
        $pdo = $connection->getPdo();

        $table = $connector->quoteTable($sessionModel->getTable());
        $pk = $connector->quoteIdentifier(AiSiteAgentSession::schema_fields_ID);
        $admin = $connector->quoteIdentifier(AiSiteAgentSession::schema_fields_ADMIN_USER_ID);
        $public = $connector->quoteIdentifier(AiSiteAgentSession::schema_fields_PUBLIC_ID);
        $stage = $connector->quoteIdentifier(AiSiteAgentSession::schema_fields_STAGE);
        $scope = $connector->quoteIdentifier(AiSiteAgentSession::schema_fields_SCOPE_JSON);
        $lengthExpression = $this->lengthExpression($connection->getDriverType(), $scope);

        $summary = [
            'success' => true,
            'mode' => $apply ? 'apply' : 'dry-run',
            'batch_size' => $batchSize,
            'min_bytes' => $minBytes,
            'session_id' => $sessionId,
            'admin_id' => $adminId,
            'include_running' => $includeRunning,
            'scanned' => 0,
            'candidates' => 0,
            'applied' => 0,
            'saved_bytes' => 0,
            'before_bytes' => 0,
            'after_bytes' => 0,
            'skipped_active' => 0,
            'skipped_invalid_json' => 0,
            'skipped_no_gain' => 0,
            'write_conflicts' => 0,
            'errors' => 0,
            'samples' => [],
        ];

        $afterId = 0;
        while (true) {
            $rows = $this->fetchBatch(
                $pdo,
                $table,
                $pk,
                $admin,
                $public,
                $stage,
                $scope,
                $lengthExpression,
                $afterId,
                $batchSize,
                $minBytes,
                $sessionId,
                $adminId
            );
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $sessionRowId = (int)($row['session_id'] ?? 0);
                if ($sessionRowId > $afterId) {
                    $afterId = $sessionRowId;
                }
                if ($sessionRowId <= 0) {
                    continue;
                }
                if ($limit > 0 && $summary['scanned'] >= $limit) {
                    break 2;
                }

                ++$summary['scanned'];
                $rawScopeJson = (string)($row['scope_json'] ?? '');
                $originalBytes = \strlen($rawScopeJson);
                $reportedBytes = (int)($row['scope_json_bytes'] ?? $originalBytes);

                try {
                    $decoded = \json_decode($rawScopeJson, true, 512, \JSON_THROW_ON_ERROR);
                    if (!\is_array($decoded)) {
                        ++$summary['skipped_invalid_json'];
                        continue;
                    }
                } catch (\JsonException) {
                    ++$summary['skipped_invalid_json'];
                    continue;
                }

                /** @var AiSiteScopeManifestPolicy $manifestPolicy */
                $manifestPolicy = ObjectManager::getInstance(AiSiteScopeManifestPolicy::class);
                $decoded = $manifestPolicy->dehydrateScopePaths($decoded);

                if (!$includeRunning && $this->hasActiveOperation($decoded)) {
                    ++$summary['skipped_active'];
                    unset($decoded, $rawScopeJson);
                    continue;
                }

                try {
                    $prepared = $artifactService->prepareScopeForStorage($sessionRowId, $decoded);
                    $compacted = $sessionModel->compactScopeForStorage($prepared['scope']);
                    $nextScopeJson = $compacted === []
                        ? '{}'
                        : (string)\json_encode(
                            $compacted,
                            \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR
                        );
                } catch (\Throwable) {
                    ++$summary['errors'];
                    unset($decoded, $prepared, $compacted, $nextScopeJson, $rawScopeJson);
                    continue;
                }

                $nextBytes = \strlen($nextScopeJson);
                if ($nextScopeJson === $rawScopeJson || $nextBytes >= $originalBytes) {
                    ++$summary['skipped_no_gain'];
                    unset($decoded, $prepared, $compacted, $nextScopeJson, $rawScopeJson);
                    continue;
                }

                ++$summary['candidates'];
                $savedBytes = $originalBytes - $nextBytes;
                $summary['before_bytes'] += $reportedBytes > 0 ? $reportedBytes : $originalBytes;
                $summary['after_bytes'] += $nextBytes;
                $summary['saved_bytes'] += $savedBytes;

                $sample = [
                    'session_id' => $sessionRowId,
                    'admin_user_id' => (int)($row['admin_user_id'] ?? 0),
                    'public_id' => (string)($row['public_id'] ?? ''),
                    'stage' => (string)($row['stage'] ?? ''),
                    'before_bytes' => $originalBytes,
                    'after_bytes' => $nextBytes,
                    'saved_bytes' => $savedBytes,
                    'applied' => false,
                ];

                if ($apply) {
                    $artifactService->persistArtifacts($sessionRowId, $prepared['artifacts']);
                    $updated = $this->updateScopeJson($pdo, $table, $pk, $scope, $sessionRowId, $rawScopeJson, $nextScopeJson);
                    if ($updated) {
                        ++$summary['applied'];
                        $sample['applied'] = true;
                    } else {
                        ++$summary['write_conflicts'];
                    }
                }

                if (\count($summary['samples']) < self::MAX_SAMPLE_ROWS) {
                    $summary['samples'][] = $sample;
                }

                unset($decoded, $prepared, $compacted, $nextScopeJson, $rawScopeJson);
            }

            if ($sessionId > 0 || \count($rows) < $batchSize) {
                break;
            }
            \gc_collect_cycles();
        }

        return $this->emitResult($format, $summary);
    }

    public function tip(): string
    {
        return 'Compact historical PageBuilder AI site session scope_json snapshots.';
    }

    public function help(): array|string
    {
        return [
            'Usage:',
            '  php bin/w aisite:compact-sessions [--apply] [--min-bytes=1048576] [--batch-size=25] [--limit=0]',
            '  php bin/w ai-site:sessions:compact --dry-run --format=json',
            '  php bin/w aisite:compact-sessions --apply --session-id=32',
            '',
            'Options:',
            '  --apply             Write compacted scope_json back to the database. Omit for dry-run.',
            '  --min-bytes=N       Only scan rows whose scope_json is at least N bytes. Use 0 for all rows.',
            '  --batch-size=N      Number of rows to load per batch. Default 25, max 200.',
            '  --limit=N           Stop after scanning N matched rows. Default 0 means no limit.',
            '  --session-id=N      Compact one session only.',
            '  --admin-id=N        Restrict rows to one admin user.',
            '  --include-running   Include sessions that appear to have active work in progress.',
            '  --format=text|json  Output format.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchBatch(
        \PDO $pdo,
        string $table,
        string $pk,
        string $admin,
        string $public,
        string $stage,
        string $scope,
        string $lengthExpression,
        int $afterId,
        int $batchSize,
        int $minBytes,
        int $sessionId,
        int $adminId
    ): array {
        $where = [
            "{$pk} > :after_id",
            "COALESCE({$scope}, '') <> ''",
        ];
        $params = ['after_id' => $afterId];
        if ($minBytes > 0) {
            $where[] = "{$lengthExpression} >= :min_bytes";
            $params['min_bytes'] = $minBytes;
        }
        if ($sessionId > 0) {
            $where[] = "{$pk} = :session_id";
            $params['session_id'] = $sessionId;
        }
        if ($adminId > 0) {
            $where[] = "{$admin} = :admin_id";
            $params['admin_id'] = $adminId;
        }

        $sql = "SELECT {$pk} AS session_id,"
            . " {$admin} AS admin_user_id,"
            . " {$public} AS public_id,"
            . " {$stage} AS stage,"
            . " {$scope} AS scope_json,"
            . " {$lengthExpression} AS scope_json_bytes"
            . " FROM {$table}"
            . ' WHERE ' . \implode(' AND ', $where)
            . " ORDER BY {$pk} ASC"
            . " LIMIT {$batchSize}";

        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            return [];
        }
        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, $value, \PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return \is_array($rows) ? $rows : [];
    }

    private function updateScopeJson(
        \PDO $pdo,
        string $table,
        string $pk,
        string $scope,
        int $sessionId,
        string $oldScopeJson,
        string $nextScopeJson
    ): bool {
        $sql = "UPDATE {$table}"
            . " SET {$scope} = :next_scope_json"
            . " WHERE {$pk} = :session_id"
            . " AND {$scope} = :old_scope_json";
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bindValue(':next_scope_json', $nextScopeJson, \PDO::PARAM_STR);
        $stmt->bindValue(':session_id', $sessionId, \PDO::PARAM_INT);
        $stmt->bindValue(':old_scope_json', $oldScopeJson, \PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    private function lengthExpression(string $driverType, string $field): string
    {
        return $driverType === 'pgsql'
            ? "OCTET_LENGTH(COALESCE({$field}, ''))"
            : "LENGTH(COALESCE({$field}, ''))";
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function hasActiveOperation(array $scope): bool
    {
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $operationStatus = \strtolower(\trim((string)($activeOperation['queue_status'] ?? '')));
        if ($operationStatus !== '' && isset(self::ACTIVE_STATUSES[$operationStatus])) {
            return true;
        }

        $workspaceStatus = \strtolower(\trim((string)($scope['workspace_status'] ?? '')));

        return $workspaceStatus !== '' && isset(self::ACTIVE_STATUSES[$workspaceStatus]);
    }

    /**
     * @param list<string> $names
     */
    private function option(array $args, array $data, array $names, mixed $default = null): mixed
    {
        foreach ($names as $name) {
            if (\array_key_exists($name, $args)) {
                return $args[$name];
            }
            if (\array_key_exists($name, $data)) {
                return $data[$name];
            }
        }

        return $default;
    }

    /**
     * @param list<string> $names
     */
    private function boolOption(array $args, array $data, array $names, bool $default): bool
    {
        foreach ($names as $name) {
            if (\array_key_exists($name, $args)) {
                return $this->toBool($args[$name], true);
            }
            if (\array_key_exists($name, $data)) {
                return $this->toBool($data[$name], true);
            }
        }

        return $default;
    }

    private function toBool(mixed $value, bool $emptyValue): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return $value !== 0;
        }

        $value = \strtolower(\trim((string)$value));
        if ($value === '') {
            return $emptyValue;
        }

        return !\in_array($value, ['0', 'false', 'no', 'off', 'n'], true);
    }

    /**
     * @param list<string> $names
     */
    private function intOption(array $args, array $data, array $names, int $default, int $min, int $max): int
    {
        $value = $this->option($args, $data, $names, $default);
        $value = \is_numeric($value) ? (int)$value : $default;

        return \max($min, \min($max, $value));
    }

    /**
     * @param array<string, mixed> $result
     */
    private function emitResult(string $format, array $result): string
    {
        if ($format === 'json') {
            $text = \json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT) . "\n";
            echo $text;

            return $text;
        }

        $lines = [];
        if (($result['success'] ?? false) !== true) {
            $lines[] = '[aisite:compact-sessions] error: ' . (string)($result['message'] ?? 'unknown error');
        } else {
            $lines[] = \sprintf(
                '[aisite:compact-sessions] mode=%s scanned=%d candidates=%d applied=%d saved_bytes=%d',
                (string)($result['mode'] ?? ''),
                (int)($result['scanned'] ?? 0),
                (int)($result['candidates'] ?? 0),
                (int)($result['applied'] ?? 0),
                (int)($result['saved_bytes'] ?? 0)
            );
            $lines[] = \sprintf(
                '[aisite:compact-sessions] skipped active=%d invalid_json=%d no_gain=%d write_conflicts=%d errors=%d',
                (int)($result['skipped_active'] ?? 0),
                (int)($result['skipped_invalid_json'] ?? 0),
                (int)($result['skipped_no_gain'] ?? 0),
                (int)($result['write_conflicts'] ?? 0),
                (int)($result['errors'] ?? 0)
            );
            foreach (($result['samples'] ?? []) as $sample) {
                if (!\is_array($sample)) {
                    continue;
                }
                $lines[] = \sprintf(
                    '  #%d admin=%d stage=%s bytes=%d->%d saved=%d applied=%s public_id=%s',
                    (int)($sample['session_id'] ?? 0),
                    (int)($sample['admin_user_id'] ?? 0),
                    (string)($sample['stage'] ?? ''),
                    (int)($sample['before_bytes'] ?? 0),
                    (int)($sample['after_bytes'] ?? 0),
                    (int)($sample['saved_bytes'] ?? 0),
                    !empty($sample['applied']) ? 'yes' : 'no',
                    (string)($sample['public_id'] ?? '')
                );
            }
        }

        $text = \implode("\n", $lines) . "\n";
        echo $text;

        return $text;
    }
}
