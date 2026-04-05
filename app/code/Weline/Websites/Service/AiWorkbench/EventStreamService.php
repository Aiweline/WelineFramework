<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\AiSiteBuilderEvent;

class EventStreamService
{
    public function __construct(
        private readonly AiSiteBuilderEvent $eventModel,
        private readonly SessionService $sessionService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function appendEvent(
        int $sessionId,
        int $adminUserId,
        string $stageCode,
        string $eventType,
        array $payload = [],
        string $level = AiSiteBuilderEvent::LEVEL_INFO
    ): int {
        if ($this->sessionService->loadById($sessionId, $adminUserId) === null) {
            return 0;
        }

        $event = clone $this->eventModel;
        $event->clearData()->clearQuery();
        $event->setData(AiSiteBuilderEvent::schema_fields_SESSION_ID, $sessionId);
        $event->setData(AiSiteBuilderEvent::schema_fields_STAGE_CODE, \trim($stageCode));
        $event->setData(AiSiteBuilderEvent::schema_fields_EVENT_TYPE, \trim($eventType));
        $event->setData(AiSiteBuilderEvent::schema_fields_LEVEL, \trim($level) ?: AiSiteBuilderEvent::LEVEL_INFO);
        $event->setPayloadArray($payload);
        try {
            $event->save();
        } catch (\Throwable $e) {
            if (!$this->isPgsqlAiSiteBuilderEventPrimaryKeyBroken($e)) {
                throw $e;
            }

            $this->repairPgsqlAiSiteBuilderEventPrimaryKey();

            $event = clone $this->eventModel;
            $event->clearData()->clearQuery();
            $event->setData(AiSiteBuilderEvent::schema_fields_ID, $this->allocateNextEventId());
            $event->setData(AiSiteBuilderEvent::schema_fields_SESSION_ID, $sessionId);
            $event->setData(AiSiteBuilderEvent::schema_fields_STAGE_CODE, \trim($stageCode));
            $event->setData(AiSiteBuilderEvent::schema_fields_EVENT_TYPE, \trim($eventType));
            $event->setData(AiSiteBuilderEvent::schema_fields_LEVEL, \trim($level) ?: AiSiteBuilderEvent::LEVEL_INFO);
            $event->setPayloadArray($payload);
            $event->save();
        }

        return $event->getId();
    }

    public function getLatestEventId(int $sessionId, int $adminUserId): int
    {
        if ($this->sessionService->loadById($sessionId, $adminUserId) === null) {
            return 0;
        }

        $event = clone $this->eventModel;
        $event->clearData()->clearQuery()
            ->where(AiSiteBuilderEvent::schema_fields_SESSION_ID, $sessionId)
            ->order(AiSiteBuilderEvent::schema_fields_ID, 'DESC')
            ->limit(1)
            ->find()
            ->fetch();

        return $event->getId();
    }

    /**
     * @return list<array{
     *   event_id:int,
     *   stage_code:string,
     *   event_type:string,
     *   level:string,
     *   payload:array<string, mixed>,
     *   create_time:string
     * }>
     */
    public function listEventsAfterId(int $sessionId, int $adminUserId, int $afterEventId, int $limit = 100, bool $skipSessionValidation = false): array
    {
        // 跳过会话验证以减少数据库查询（SSE 长连接场景下，首次验证后无需重复验证）
        if (!$skipSessionValidation && $this->sessionService->loadById($sessionId, $adminUserId) === null) {
            return [];
        }

        $limit = \min(200, \max(1, $limit));
        $event = clone $this->eventModel;
        $rows = $event->clearData()->clearQuery()
            ->where(AiSiteBuilderEvent::schema_fields_SESSION_ID, $sessionId)
            ->where(AiSiteBuilderEvent::schema_fields_ID, $afterEventId, '>')
            ->order(AiSiteBuilderEvent::schema_fields_ID, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        return $this->hydrateEvents($rows);
    }

    /**
     * @return list<array{
     *   event_id:int,
     *   stage_code:string,
     *   event_type:string,
     *   level:string,
     *   payload:array<string, mixed>,
     *   create_time:string
     * }>
     */
    public function listRecentEvents(int $sessionId, int $adminUserId, int $limit = 200): array
    {
        if ($this->sessionService->loadById($sessionId, $adminUserId) === null) {
            return [];
        }

        $limit = \min(500, \max(1, $limit));
        $event = clone $this->eventModel;
        $rows = $event->clearData()->clearQuery()
            ->where(AiSiteBuilderEvent::schema_fields_SESSION_ID, $sessionId)
            ->order(AiSiteBuilderEvent::schema_fields_ID, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        if (!\is_array($rows)) {
            return [];
        }

        return $this->hydrateEvents(\array_reverse($rows));
    }

    /**
     * @param mixed $rows
     * @return list<array{
     *   event_id:int,
     *   stage_code:string,
     *   event_type:string,
     *   level:string,
     *   payload:array<string, mixed>,
     *   create_time:string
     * }>
     */
    private function hydrateEvents(mixed $rows): array
    {
        if (!\is_array($rows)) {
            return [];
        }

        $events = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $item = clone $this->eventModel;
            $item->setData($row);
            $events[] = [
                'event_id' => $item->getId(),
                'stage_code' => $item->getStageCode(),
                'event_type' => $item->getEventType(),
                'level' => $item->getLevel(),
                'payload' => $item->getPayloadArray(),
                'create_time' => (string)($row[AiSiteBuilderEvent::schema_fields_CREATE_TIME] ?? ''),
            ];
        }

        return $events;
    }

    private function isPgsqlAiSiteBuilderEventPrimaryKeyBroken(\Throwable $e): bool
    {
        $chain = $e;
        while ($chain !== null) {
            $msg = $chain->getMessage();
            if ((\str_contains($msg, '23502') || \str_contains($msg, '23505'))
                && \str_contains($msg, AiSiteBuilderEvent::schema_fields_ID)
                && (\str_contains($msg, 'weline_websites_ai_site_builder_event')
                    || \str_contains($msg, 'm_weline_websites_ai_site_builder_event'))) {
                return true;
            }
            $chain = $chain->getPrevious();
        }

        return false;
    }

    private function repairPgsqlAiSiteBuilderEventPrimaryKey(): void
    {
        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        if (!$connector instanceof PgsqlConnector || !\method_exists($connector, 'getWrappedConnection')) {
            return;
        }

        $pk = AiSiteBuilderEvent::schema_fields_ID;
        $declared = [
            'name' => $pk,
            'type' => 'int',
            'length' => null,
            'nullable' => false,
            'primaryKey' => true,
            'autoIncrement' => true,
            'default' => null,
            'comment' => '',
            'unique' => false,
        ];
        $existingCol = null;
        foreach ($connector->getTableColumns($this->eventModel->getTable()) as $row) {
            if (!\is_array($row) || (string)($row['name'] ?? '') !== $pk) {
                continue;
            }
            $existingCol = [
                'name' => (string)($row['name'] ?? ''),
                'type' => (string)($row['type'] ?? ''),
                'length' => \array_key_exists('length', $row) ? $row['length'] : null,
                'nullable' => (bool)($row['nullable'] ?? true),
                'primaryKey' => (bool)($row['primary_key'] ?? false),
                'autoIncrement' => (bool)($row['auto_increment'] ?? false),
                'default' => $row['default'] ?? null,
                'comment' => (string)($row['comment'] ?? ''),
                'unique' => (bool)($row['unique'] ?? false),
            ];
            break;
        }

        $quotedTable = $connector->quoteTable($this->eventModel->getTable());
        $ddl = $connector->buildAlterModifyColumnSql($quotedTable, $declared, $existingCol);
        foreach (\preg_split('/;\s*\R/m', \trim($ddl)) ?: [] as $piece) {
            $sql = \trim((string)$piece);
            if ($sql === '') {
                continue;
            }
            if (!\str_ends_with($sql, ';')) {
                $sql .= ';';
            }
            $connector->query($sql)->fetch();
        }

        $pdo = $connector->getWrappedConnection()->getPdo();
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            return;
        }
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (\Throwable) {
            }
        }

        $tableSql = $this->eventModel->getTable();
        $stmt = $pdo->query('SELECT COALESCE(MAX("' . $pk . '"), 0) AS mx FROM ' . $tableSql);
        if ($stmt === false) {
            return;
        }
        $maxId = (int)($stmt->fetch(\PDO::FETCH_ASSOC)['mx'] ?? 0);

        $sequences = [];
        $defaultStmt = $pdo->query(
            "SELECT column_default FROM information_schema.columns
             WHERE table_schema = 'weline'
               AND table_name = 'm_weline_websites_ai_site_builder_event'
               AND column_name = " . $pdo->quote($pk)
        );
        if ($defaultStmt !== false) {
            $defaultExpr = (string)($defaultStmt->fetchColumn() ?: '');
            if (\preg_match("/nextval\\('([^']+)'/i", $defaultExpr, $matches)) {
                $sequence = (string)$matches[1];
                $sequences[] = \str_contains($sequence, '.') ? $sequence : ('weline.' . $sequence);
            }
        }

        $tableForSeq = \str_replace('"', '', $tableSql);
        $seqStmt = $pdo->query(
            'SELECT pg_get_serial_sequence(' . $pdo->quote($tableForSeq) . ", '" . $pk . "')"
        );
        if ($seqStmt !== false) {
            $sequence = $seqStmt->fetchColumn();
            if (\is_string($sequence) && $sequence !== '') {
                $sequences[] = $sequence;
            }
        }

        if ($sequences === []) {
            $base = \preg_replace('/^.*\./', '', \str_replace('"', '', $tableSql));
            $sequences[] = 'weline.' . $base . '_' . $pk . '_seq';
        }

        foreach (\array_values(\array_unique($sequences)) as $sequence) {
            $pdo->exec('SELECT setval(' . $pdo->quote($sequence) . ', ' . \max(0, $maxId) . ', true)');
        }
    }

    private function allocateNextEventId(): int
    {
        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        if (!$connector instanceof PgsqlConnector || !\method_exists($connector, 'getWrappedConnection')) {
            return 0;
        }

        $pdo = $connector->getWrappedConnection()->getPdo();
        $tableSql = $this->eventModel->getTable();
        $pk = AiSiteBuilderEvent::schema_fields_ID;
        $stmt = $pdo->query('SELECT COALESCE(MAX("' . $pk . '"), 0) AS mx FROM ' . $tableSql);
        if ($stmt === false) {
            return 0;
        }

        return ((int)($stmt->fetch(\PDO::FETCH_ASSOC)['mx'] ?? 0)) + 1;
    }
}
