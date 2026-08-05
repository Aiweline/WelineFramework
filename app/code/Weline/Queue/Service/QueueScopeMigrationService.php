<?php

declare(strict_types=1);

namespace Weline\Queue\Service;

use Weline\Queue\Model\Queue;
use Weline\Queue\Model\Queue\Type;

/**
 * P1b / P1B-002 工具面 + TASK-MIG-P1A 信封 apply。
 *
 * - help / preflight / quarantine：P1B-002
 * - apply：仅由 MIG-P1A 编排在隔离库调用；按冻结契约回填 Envelope，禁止猜零号站
 */
class QueueScopeMigrationService
{
    public function __construct(
        private readonly Queue $queue,
        private readonly Type $typeModel,
        private readonly QueueScopeProducerMapping $mapping,
    ) {
    }

    /**
     * @return array{
     *   mode:string,
     *   contracts:list<array<string,mixed>>,
     *   notes:list<string>
     * }
     */
    public function help(): array
    {
        return [
            'mode' => 'help',
            'contracts' => $this->mapping->frozenContracts(),
            'notes' => [
                'P1B-002 提供 help/preflight/quarantine；envelope apply 由 scope:migrate-p1a 在隔离 clone 编排。',
                '歧义行 quarantine，禁止映成 website_id=0 / default channel。',
                '新写仍强制 scope_envelope（见 QueueQueryProvider / save_before capture）。',
                'CLI：php bin/w queue:scope:migrate help|preflight|quarantine|verify',
            ],
        ];
    }

    /**
     * @return array{
     *   legacy_rows:int,
     *   total_rows:int,
     *   mappable:int,
     *   cancelled:int,
     *   quarantine:int,
     *   conservation_ok:bool,
     *   samples:array{
     *     mappable:list<array<string,mixed>>,
     *     quarantine:list<array<string,mixed>>,
     *     cancelled:list<array<string,mixed>>
     *   }
     * }
     */
    public function preflight(): array
    {
        $classified = $this->classifyLegacyRows();
        $legacy = \count($classified);
        $mappable = 0;
        $cancelled = 0;
        $quarantine = 0;
        $samples = [
            'mappable' => [],
            'quarantine' => [],
            'cancelled' => [],
        ];

        foreach ($classified as $item) {
            $decision = $item['decision'];
            if ($decision === QueueScopeProducerMapping::DECISION_MAP) {
                ++$mappable;
                if (\count($samples['mappable']) < 5) {
                    $samples['mappable'][] = $this->sample($item);
                }
            } elseif ($decision === QueueScopeProducerMapping::DECISION_CANCELLED) {
                ++$cancelled;
                if (\count($samples['cancelled']) < 5) {
                    $samples['cancelled'][] = $this->sample($item);
                }
            } else {
                ++$quarantine;
                if (\count($samples['quarantine']) < 5) {
                    $samples['quarantine'][] = $this->sample($item);
                }
            }
        }

        return [
            'legacy_rows' => $legacy,
            'total_rows' => $this->countAllRows(),
            'mappable' => $mappable,
            'cancelled' => $cancelled,
            'quarantine' => $quarantine,
            'conservation_ok' => ($mappable + $cancelled + $quarantine) === $legacy,
            'samples' => $samples,
        ];
    }

    /**
     * 对歧义 unfinished 遗留行写入 quarantine 标记，使其不可领取。
     * 不写 scope_* Envelope 列，不执行 cutover。
     *
     * @return array{examined:int,quarantined:int,skipped:int,samples:list<array<string,mixed>>}
     */
    public function quarantine(): array
    {
        $classified = $this->classifyLegacyRows();
        $examined = 0;
        $quarantined = 0;
        $skipped = 0;
        $samples = [];

        foreach ($classified as $item) {
            if ($item['decision'] !== QueueScopeProducerMapping::DECISION_QUARANTINE) {
                continue;
            }
            ++$examined;
            $row = $item['row'];
            if ($this->mapping->isAlreadyQuarantined($row)) {
                ++$skipped;
                continue;
            }
            if ($this->mapping->isTerminalNonConsumable($row)) {
                ++$skipped;
                continue;
            }

            $queueId = (int)($row['queue_id'] ?? 0);
            if ($queueId <= 0) {
                ++$skipped;
                continue;
            }

            $message = $this->mapping->quarantineMessage((string)$item['reason']);
            $updated = $this->markQuarantined($queueId, $message);
            if ($updated) {
                ++$quarantined;
                if (\count($samples) < 10) {
                    $samples[] = [
                        'queue_id' => $queueId,
                        'reason' => (string)$item['reason'],
                    ];
                }
            } else {
                ++$skipped;
            }
        }

        return [
            'examined' => $examined,
            'quarantined' => $quarantined,
            'skipped' => $skipped,
            'samples' => $samples,
        ];
    }

    /**
     * TASK-MIG-P1A：对可判定遗留行回填 Envelope；歧义 unfinished 写入 quarantine。
     *
     * 守恒：mapped + cancelled + quarantined + skipped = legacy_before。
     *
     * @return array{
     *   legacy_before:int,
     *   mapped:int,
     *   cancelled:int,
     *   quarantined:int,
     *   skipped:int,
     *   conservation_ok:bool,
     *   samples:list<array<string,mixed>>
     * }
     */
    public function apply(): array
    {
        $this->assertCurrentDatabaseIsolated();
        $before = $this->classifyLegacyRows();
        $legacyBefore = \count($before);
        $mapped = 0;
        $cancelled = 0;
        $quarantined = 0;
        $skipped = 0;
        $samples = [];

        foreach ($before as $item) {
            $decision = $item['decision'];
            $row = $item['row'];
            $queueId = (int)($row['queue_id'] ?? 0);

            if ($decision === QueueScopeProducerMapping::DECISION_CANCELLED) {
                ++$cancelled;
                continue;
            }

            if ($decision === QueueScopeProducerMapping::DECISION_QUARANTINE) {
                if ($queueId <= 0) {
                    ++$skipped;
                    continue;
                }
                if ($this->mapping->isAlreadyQuarantined($row) || $this->mapping->isTerminalNonConsumable($row)) {
                    ++$quarantined;
                    continue;
                }
                $message = $this->mapping->quarantineMessage((string)$item['reason']);
                if ($this->markQuarantined($queueId, $message)) {
                    ++$quarantined;
                } else {
                    ++$skipped;
                }
                continue;
            }

            if ($decision !== QueueScopeProducerMapping::DECISION_MAP) {
                ++$skipped;
                continue;
            }

            $envelopeData = $item['envelope'] ?? null;
            if (!\is_array($envelopeData) || $queueId <= 0) {
                ++$skipped;
                continue;
            }
            if (!$this->backfillEnvelope($queueId, $envelopeData)) {
                ++$skipped;
                continue;
            }
            ++$mapped;
            if (\count($samples) < 10) {
                $samples[] = [
                    'queue_id' => $queueId,
                    'kind' => $item['kind'],
                    'producer_key' => $item['producer_key'],
                ];
            }
        }

        return [
            'legacy_before' => $legacyBefore,
            'mapped' => $mapped,
            'cancelled' => $cancelled,
            'quarantined' => $quarantined,
            'skipped' => $skipped,
            'conservation_ok' => ($mapped + $cancelled + $quarantined + $skipped) === $legacyBefore,
            'samples' => $samples,
        ];
    }

    /**
     * @param array<string, mixed> $envelopeData
     */
    private function backfillEnvelope(int $queueId, array $envelopeData): bool
    {
        $queue = clone $this->queue;
        $queue->clearData()->reset()
            ->where(Queue::schema_fields_ID, $queueId)
            ->find()
            ->fetch();
        if ((int)$queue->getId() !== $queueId) {
            return false;
        }
        $kind = $queue->getData(Queue::schema_fields_SCOPE_KIND);
        if ($kind !== null && $kind !== '') {
            return false;
        }

        try {
            $envelope = \Weline\Framework\Runtime\ScopeEnvelope::fromArray($envelopeData);
        } catch (\Throwable) {
            return false;
        }
        $queue->setScopeEnvelope($envelope)->save(true);

        return true;
    }

    private function assertCurrentDatabaseIsolated(): void
    {
        $connector = $this->queue->getConnection()->getConnector();
        $cfg = $connector->getConfigProvider();
        (new \Weline\Framework\Database\Migration\Service\DatabaseFingerprintGuard())->assertIsolatedDatabase([
            'type' => (string)$cfg->getDbType(),
            'hostname' => (string)$cfg->getHostName(),
            'hostport' => (string)$cfg->getHostPort(),
            'database' => \strtolower((string)$cfg->getDatabase()),
            'username' => (string)$cfg->getUsername(),
        ]);
    }

    /**
     * @return array{legacy_rows:int,unfinished_unmarked:int,ok:bool,unmapped_mappable:int}
     */
    public function verify(): array
    {
        $classified = $this->classifyLegacyRows();
        $legacy = \count($classified);
        $unfinishedUnmarked = 0;
        $unmappedMappable = 0;
        foreach ($classified as $item) {
            if ($item['decision'] === QueueScopeProducerMapping::DECISION_MAP) {
                ++$unmappedMappable;
                continue;
            }
            if ($item['decision'] !== QueueScopeProducerMapping::DECISION_QUARANTINE) {
                continue;
            }
            $row = $item['row'];
            if ($this->mapping->isTerminalNonConsumable($row)) {
                continue;
            }
            if (!$this->mapping->isAlreadyQuarantined($row)) {
                ++$unfinishedUnmarked;
            }
        }

        return [
            'legacy_rows' => $legacy,
            'unfinished_unmarked' => $unfinishedUnmarked,
            'unmapped_mappable' => $unmappedMappable,
            'ok' => $unfinishedUnmarked === 0 && $unmappedMappable === 0,
        ];
    }

    /**
     * @return list<array{
     *   decision:string,
     *   producer_key:?string,
     *   kind:?string,
     *   envelope:?array<string,mixed>,
     *   reason:string,
     *   row:array<string,mixed>,
     *   type_class:?string
     * }>
     */
    private function classifyLegacyRows(): array
    {
        $rows = $this->fetchLegacyRows();
        $typeClasses = $this->loadTypeClassMap();
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $typeId = (int)($row['type_id'] ?? 0);
            $typeClass = $typeClasses[$typeId] ?? null;
            $decision = $this->mapping->classify($row, $typeClass);
            $out[] = $decision + [
                'row' => $row,
                'type_class' => $typeClass,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchLegacyRows(): array
    {
        $connector = $this->queue->getConnection()->getConnector();
        $table = $connector->getTable($this->queue->getOriginTableName());
        $sql = "SELECT queue_id, type_id, name, module, status, finished, auto, result, content, biz_key,"
            . ' scope_kind, scope_website_id, scope_website_code, scope_store_code,'
            . ' scope_channel_code, scope_store_mode, scope_envelope_version'
            . " FROM {$table}"
            . " WHERE scope_kind IS NULL OR scope_kind = ''";
        $stmt = $connector->getLink()->query($sql);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return \is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int, string>
     */
    private function loadTypeClassMap(): array
    {
        $type = clone $this->typeModel;
        $rows = $type->clearData()->reset()
            ->select()
            ->fetchArray();
        $map = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $typeId = (int)($row[Type::schema_fields_ID] ?? 0);
            $class = \trim((string)($row[Type::schema_fields_class] ?? ''));
            if ($typeId > 0 && $class !== '') {
                $map[$typeId] = $class;
            }
        }

        return $map;
    }

    private function markQuarantined(int $queueId, string $message): bool
    {
        $queue = clone $this->queue;
        $queue->clearData()->reset()
            ->where(Queue::schema_fields_ID, $queueId)
            ->find()
            ->fetch();
        if ((int)$queue->getId() !== $queueId) {
            return false;
        }
        $kind = $queue->getData(Queue::schema_fields_SCOPE_KIND);
        if ($kind !== null && $kind !== '') {
            return false;
        }
        if ($this->mapping->isAlreadyQuarantined($queue->getData())) {
            return false;
        }

        $queue->setAuto(false)
            ->setStatus(Queue::status_stop)
            ->setFinished(true)
            ->setResult($message)
            ->setPid(0)
            ->setDispatchToken(null)
            ->setDispatchUntil(null);
        $queue->save(true);

        return true;
    }

    /**
     * @param array{
     *   decision:string,
     *   producer_key:?string,
     *   kind:?string,
     *   reason:string,
     *   row:array<string,mixed>,
     *   type_class:?string
     * } $item
     * @return array<string,mixed>
     */
    private function sample(array $item): array
    {
        $row = $item['row'];

        return [
            'queue_id' => (int)($row['queue_id'] ?? 0),
            'type_class' => $item['type_class'],
            'module' => (string)($row['module'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'decision' => $item['decision'],
            'producer_key' => $item['producer_key'],
            'kind' => $item['kind'],
            'reason' => $item['reason'],
        ];
    }

    private function countAllRows(): int
    {
        $connector = $this->queue->getConnection()->getConnector();
        $table = $connector->getTable($this->queue->getOriginTableName());

        return (int)$connector->getLink()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}
