<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * AI 建站工作台会话：创建、读写 scope、事件流、站点/主题/发布状态
 */

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSessionEvent;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Database\Connection\Adapter\Pgsql\SchemaConfig;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

class AiSiteAgentSessionService
{
    public function __construct(
        private readonly AiSiteAgentSession $sessionModel,
        private readonly AiSiteAgentSessionEvent $eventModel,
    ) {
    }

    public function generatePublicId(): string
    {
        return \bin2hex(\random_bytes(16));
    }

    /**
     * @param array<string, mixed> $initialScope
     */
    public function createSession(int $adminUserId, array $initialScope = []): AiSiteAgentSession
    {
        $session = clone $this->sessionModel;
        $session->clearData()->clearQuery();
        $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $this->generatePublicId());
        $session->setData(AiSiteAgentSession::schema_fields_ADMIN_USER_ID, $adminUserId);
        $session->setData(AiSiteAgentSession::schema_fields_WEBSITE_ID, 0);
        $session->setData(AiSiteAgentSession::schema_fields_VIRTUAL_THEME_ID, 0);
        $session->setData(AiSiteAgentSession::schema_fields_STAGE, AiSiteAgentSession::STAGE_BRIEF);
        $session->setData(AiSiteAgentSession::schema_fields_PUBLISH_STATUS, AiSiteAgentSession::PUBLISH_STATUS_DRAFT);
        $session->setScopeArray($initialScope);
        try {
            $session->save();
        } catch (\Throwable $e) {
            if (!$this->isPgsqlAiSessionPrimaryKeySerialMissing($e)) {
                throw $e;
            }
            echo "[DEBUG] 检测到序列问题，开始修复...\n";
            $this->repairPgsqlAiSessionPrimaryKeySerial();
            echo "[DEBUG] 序列修复完成，重新创建会话对象并重试保存...\n";

            // 重新创建一个全新的会话对象
            $session = clone $this->sessionModel;
            $session->clearData()->clearQuery();
            $session->setData(AiSiteAgentSession::schema_fields_PUBLIC_ID, $this->generatePublicId());
            $session->setData(AiSiteAgentSession::schema_fields_ADMIN_USER_ID, $adminUserId);
            $session->setData(AiSiteAgentSession::schema_fields_WEBSITE_ID, 0);
            $session->setData(AiSiteAgentSession::schema_fields_VIRTUAL_THEME_ID, 0);
            $session->setData(AiSiteAgentSession::schema_fields_STAGE, AiSiteAgentSession::STAGE_BRIEF);
            $session->setData(AiSiteAgentSession::schema_fields_PUBLISH_STATUS, AiSiteAgentSession::PUBLISH_STATUS_DRAFT);
            $session->setScopeArray($initialScope);
            $session->save();
        }
        return $session;
    }

    public function loadByPublicId(string $publicId, int $forAdminUserId): ?AiSiteAgentSession
    {
        $publicId = \trim($publicId);
        if ($publicId === '' || $forAdminUserId <= 0) {
            return null;
        }
        $session = clone $this->sessionModel;
        $session->clearData()->clearQuery()
            ->where(AiSiteAgentSession::schema_fields_PUBLIC_ID, $publicId)
            ->where(AiSiteAgentSession::schema_fields_ADMIN_USER_ID, $forAdminUserId)
            ->find()
            ->fetch();
        return $session->getId() > 0 ? $session : null;
    }

    public function loadById(int $sessionId, int $forAdminUserId): ?AiSiteAgentSession
    {
        if ($sessionId <= 0 || $forAdminUserId <= 0) {
            return null;
        }
        $session = clone $this->sessionModel;
        $session->clearData()->clearQuery()
            ->where(AiSiteAgentSession::schema_fields_ID, $sessionId)
            ->where(AiSiteAgentSession::schema_fields_ADMIN_USER_ID, $forAdminUserId)
            ->find()
            ->fetch();

        return $session->getId() > 0 ? $session : null;
    }

    public function deleteSession(int $sessionId, int $forAdminUserId): bool
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return false;
        }

        $eventModel = clone $this->eventModel;
        $eventModel->clearData()->clearQuery()
            ->where(AiSiteAgentSessionEvent::schema_fields_AGENT_SESSION_ID, $sessionId)
            ->delete()
            ->fetch();

        // AbstractModel::delete() 内部已执行 fetch()；切勿再链式 ->fetch()，否则返回的是模型对象，(bool)$model 恒为 true。
        // QueryAst 对 DELETE 的 fetch 结果为 rowCount>0 时的 boolean，应以此为准。
        $session->delete();
        if ($session->getQueryData() === true) {
            return true;
        }

        return $this->loadById($sessionId, $forAdminUserId) === null;
    }

    /**
     * @param array<string, mixed> $patch 顶层键与 scope 合并（array_replace）
     */
    public function mergeScope(int $sessionId, int $forAdminUserId, array $patch): bool
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return false;
        }
        if (\array_key_exists('target_domain', $patch)) {
            $td = \trim((string)$patch['target_domain']);
            $patch['target_domain'] = $td === '' ? '' : \strtolower($td);
        }
        $scope = $session->getScopeArray();
        $merged = \array_replace($scope, $patch);
        $session->setScopeArray($merged);
        $this->touchUpdateTime($session);
        $session->save();
        return true;
    }

    /**
     * 整体替换 scope（仍须为对象 JSON 对应的关联数组）
     *
     * @param array<string, mixed> $scope
     */
    public function replaceScope(int $sessionId, int $forAdminUserId, array $scope): bool
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return false;
        }
        if (\array_key_exists('target_domain', $scope)) {
            $td = \trim((string)$scope['target_domain']);
            $scope['target_domain'] = $td === '' ? '' : \strtolower($td);
        }
        $session->setScopeArray($scope);
        $this->touchUpdateTime($session);
        $session->save();
        return true;
    }

    public function setStage(int $sessionId, int $forAdminUserId, string $stage): bool
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return false;
        }
        $session->setData(AiSiteAgentSession::schema_fields_STAGE, $stage);
        $this->touchUpdateTime($session);
        $session->save();
        return true;
    }

    public function bindWebsite(int $sessionId, int $forAdminUserId, int $websiteId): bool
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return false;
        }
        $session->setData(AiSiteAgentSession::schema_fields_WEBSITE_ID, \max(0, $websiteId));
        $this->touchUpdateTime($session);
        $session->save();
        return true;
    }

    public function bindVirtualTheme(int $sessionId, int $forAdminUserId, int $virtualThemeId): bool
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return false;
        }
        $session->setData(AiSiteAgentSession::schema_fields_VIRTUAL_THEME_ID, \max(0, $virtualThemeId));
        $this->touchUpdateTime($session);
        $session->save();
        return true;
    }

    public function setPublishStatus(int $sessionId, int $forAdminUserId, string $publishStatus): bool
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return false;
        }
        $session->setData(AiSiteAgentSession::schema_fields_PUBLISH_STATUS, $publishStatus);
        $this->touchUpdateTime($session);
        $session->save();
        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function appendEvent(
        int $sessionId,
        int $forAdminUserId,
        string $eventType,
        array $payload = [],
        string $stageCode = '',
        string $level = AiSiteAgentSessionEvent::LEVEL_INFO
    ): bool
    {
        if ($this->loadById($sessionId, $forAdminUserId) === null) {
            return false;
        }
        $event = clone $this->eventModel;
        $event->clearData()->clearQuery();
        $event->setData(AiSiteAgentSessionEvent::schema_fields_AGENT_SESSION_ID, $sessionId);
        $event->setData(AiSiteAgentSessionEvent::schema_fields_STAGE_CODE, \trim($stageCode));
        $event->setData(AiSiteAgentSessionEvent::schema_fields_EVENT_TYPE, $eventType);
        $event->setData(
            AiSiteAgentSessionEvent::schema_fields_LEVEL,
            \trim($level) !== '' ? \trim($level) : AiSiteAgentSessionEvent::LEVEL_INFO
        );
        $event->setPayloadArray($payload);
        try {
            $event->save();
        } catch (\Throwable $e) {
            if (!$this->isPgsqlAiSessionEventPrimaryKeyBroken($e)) {
                throw $e;
            }
            $event = clone $this->eventModel;
            $event->clearData()->clearQuery();
            $event->setData(AiSiteAgentSessionEvent::schema_fields_ID, $this->allocateNextAiSessionEventId());
            $event->setData(AiSiteAgentSessionEvent::schema_fields_AGENT_SESSION_ID, $sessionId);
            $event->setData(AiSiteAgentSessionEvent::schema_fields_STAGE_CODE, \trim($stageCode));
            $event->setData(AiSiteAgentSessionEvent::schema_fields_EVENT_TYPE, $eventType);
            $event->setData(
                AiSiteAgentSessionEvent::schema_fields_LEVEL,
                \trim($level) !== '' ? \trim($level) : AiSiteAgentSessionEvent::LEVEL_INFO
            );
            $event->setPayloadArray($payload);
            $event->save();
        }
        return true;
    }

    /**
     * 按事件主键游标拉取新事件（供 SSE 增量推送）
     *
     * @return list<array{
     *   event_id: int,
     *   stage_code: string,
     *   event_type: string,
     *   level: string,
     *   payload: array<string, mixed>,
     *   create_time: string
     * }>
     */
    public function getLatestEventId(int $sessionId, int $forAdminUserId): int
    {
        if ($this->loadById($sessionId, $forAdminUserId) === null) {
            return 0;
        }
        $event = clone $this->eventModel;
        $event->clearData()->clearQuery()
            ->where(AiSiteAgentSessionEvent::schema_fields_AGENT_SESSION_ID, $sessionId)
            ->order(AiSiteAgentSessionEvent::schema_fields_ID, 'DESC')
            ->limit(1)
            ->find()
            ->fetch();

        return $event->getId() > 0 ? $event->getId() : 0;
    }

    public function listEventsAfterId(int $sessionId, int $forAdminUserId, int $afterEventId, int $limit = 100): array
    {
        if ($this->loadById($sessionId, $forAdminUserId) === null) {
            return [];
        }
        $limit = \min(200, \max(1, $limit));
        $event = clone $this->eventModel;
        $rows = $event->clearData()->clearQuery()
            ->where(AiSiteAgentSessionEvent::schema_fields_AGENT_SESSION_ID, $sessionId)
            ->where(AiSiteAgentSessionEvent::schema_fields_ID, $afterEventId, '>')
            ->order(AiSiteAgentSessionEvent::schema_fields_ID, 'ASC')
            ->limit($limit)
            ->select()
            ->fetchArray();
        if (!\is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $m = clone $this->eventModel;
            $m->setData($row);
            $out[] = [
                'event_id' => $m->getId(),
                'stage_code' => $m->getStageCode(),
                'event_type' => $m->getEventType(),
                'level' => $m->getLevel(),
                'payload' => $m->getPayloadArray(),
                'create_time' => (string) ($row[AiSiteAgentSessionEvent::schema_fields_CREATE_TIME] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @return list<array{
     *   event_id: int,
     *   stage_code: string,
     *   event_type: string,
     *   level: string,
     *   payload: array<string, mixed>,
     *   create_time: string
     * }>
     */
    public function listRecentEvents(int $sessionId, int $forAdminUserId, int $limit = 200): array
    {
        if ($this->loadById($sessionId, $forAdminUserId) === null) {
            return [];
        }
        $limit = \min(500, \max(1, $limit));
        $event = clone $this->eventModel;
        $rows = $event->clearData()->clearQuery()
            ->where(AiSiteAgentSessionEvent::schema_fields_AGENT_SESSION_ID, $sessionId)
            ->order(AiSiteAgentSessionEvent::schema_fields_CREATE_TIME, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();
        if (!\is_array($rows)) {
            return [];
        }
        $rows = \array_reverse($rows);
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $m = clone $this->eventModel;
            $m->setData($row);
            $out[] = [
                'event_id' => $m->getId(),
                'stage_code' => $m->getStageCode(),
                'event_type' => $m->getEventType(),
                'level' => $m->getLevel(),
                'payload' => $m->getPayloadArray(),
                'create_time' => (string) ($row[AiSiteAgentSessionEvent::schema_fields_CREATE_TIME] ?? ''),
            ];
        }
        return $out;
    }

    private function touchUpdateTime(AiSiteAgentSession $session): void
    {
        $session->setData(
            AiSiteAgentSession::schema_fields_UPDATE_TIME,
            \date('Y-m-d H:i:s')
        );
    }

    /**
     * @return list<array{public_id: string, stage: string, publish_status: string, website_id: int, virtual_theme_id: int, update_time: string}>
     */
    public function listRecentSessionsForAdmin(int $adminUserId, int $limit = 20): array
    {
        if ($adminUserId <= 0) {
            return [];
        }
        $limit = \min(50, \max(1, $limit));
        $session = clone $this->sessionModel;
        $rows = $session->clearData()->clearQuery()
            ->where(AiSiteAgentSession::schema_fields_ADMIN_USER_ID, $adminUserId)
            ->order(AiSiteAgentSession::schema_fields_UPDATE_TIME, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();
        if (!\is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $m = clone $this->sessionModel;
            $m->setData($row);
            if ($m->getId() <= 0) {
                continue;
            }
            $out[] = [
                'public_id' => $m->getPublicId(),
                'stage' => $m->getStage(),
                'publish_status' => $m->getPublishStatus(),
                'website_id' => $m->getWebsiteId(),
                'virtual_theme_id' => $m->getVirtualThemeId(),
                'update_time' => (string) ($row[AiSiteAgentSession::schema_fields_UPDATE_TIME] ?? ''),
            ];
        }
        return $out;
    }

    private function isPgsqlAiSessionPrimaryKeySerialMissing(\Throwable $e): bool
    {
        $chain = $e;
        while ($chain !== null) {
            $msg = $chain->getMessage();
            // 检查 NOT NULL 错误（23502）或主键冲突错误（23505）
            if ((\str_contains($msg, '23502') || \str_contains($msg, '23505'))
                && \str_contains($msg, 'ai_site_agent_session_id')
                && (\str_contains($msg, 'guolairen_page_builder_ai_site_agent_session')
                    || \str_contains($msg, 'm_guolairen_page_builder_ai_site_agent_session'))) {
                return true;
            }
            $chain = $chain->getPrevious();
        }
        return false;
    }

    private function isPgsqlAiSessionEventPrimaryKeyBroken(\Throwable $e): bool
    {
        $chain = $e;
        while ($chain !== null) {
            $msg = $chain->getMessage();
            if ((\str_contains($msg, '23502') || \str_contains($msg, '23505'))
                && \str_contains($msg, AiSiteAgentSessionEvent::schema_fields_ID)
                && (\str_contains($msg, 'guolairen_page_builder_ai_site_agent_event')
                    || \str_contains($msg, 'm_guolairen_page_builder_ai_site_agent_event'))) {
                return true;
            }
            $chain = $chain->getPrevious();
        }

        return false;
    }

    private function allocateNextAiSessionEventId(): int
    {
        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        if (!$connector instanceof PgsqlConnector || !\method_exists($connector, 'getWrappedConnection')) {
            return 0;
        }

        $pdo = $connector->getWrappedConnection()->getPdo();
        $tableSql = $this->eventModel->getTable();
        $pk = AiSiteAgentSessionEvent::schema_fields_ID;
        $stmt = $pdo->query('SELECT COALESCE(MAX("' . $pk . '"), 0) AS mx FROM ' . $tableSql);
        if ($stmt === false) {
            return 0;
        }

        return ((int)($stmt->fetch(\PDO::FETCH_ASSOC)['mx'] ?? 0)) + 1;
    }

    /**
     * 历史库表在 PG 上曾建成无序列的 INTEGER 主键，INSERT 省略主键时会违反 NOT NULL。
     * 与 SchemaDiff 的 MODIFY 逻辑一致：CREATE SEQUENCE + SET DEFAULT nextval。
     * 同时修复序列值，确保序列值大于当前最大 ID。
     */
    private function repairPgsqlAiSessionPrimaryKeySerial(): void
    {
        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        if (!$connector instanceof PgsqlConnector) {
            return;
        }
        $pk = AiSiteAgentSession::schema_fields_ID;
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
        $columns = $connector->getTableColumns($this->sessionModel->getTable());
        foreach ($columns as $row) {
            if (!\is_array($row) || (string) ($row['name'] ?? '') !== $pk) {
                continue;
            }
            $existingCol = [
                'name' => (string) ($row['name'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'length' => \array_key_exists('length', $row) ? $row['length'] : null,
                'nullable' => (bool) ($row['nullable'] ?? true),
                'primaryKey' => (bool) ($row['primary_key'] ?? false),
                'autoIncrement' => (bool) ($row['auto_increment'] ?? false),
                'default' => $row['default'] ?? null,
                'comment' => (string) ($row['comment'] ?? ''),
                'unique' => (bool) ($row['unique'] ?? false),
            ];
            break;
        }
        $quotedTable = $connector->quoteTable($this->sessionModel->getTable());
        $ddl = $connector->buildAlterModifyColumnSql($quotedTable, $declared, $existingCol);
        foreach (\preg_split('/;\s*\R/m', \trim($ddl)) ?: [] as $piece) {
            $sql = \trim((string) $piece);
            if ($sql === '') {
                continue;
            }
            if (!\str_ends_with($sql, ';')) {
                $sql .= ';';
            }
            $connector->query($sql)->fetch();
        }

        // 修复序列值：确保序列值大于当前最大 ID
        try {
            // 从列信息中提取序列名
            $columnDefault = $existingCol['default'] ?? null;
            if (!$columnDefault || !\preg_match("/nextval\('([^']+)'/", $columnDefault, $matches)) {
                return;
            }

            $sequenceName = $matches[1];

            // 获取当前最大 ID（使用 ORM）
            $session = clone $this->sessionModel;
            $maxIdRow = $session->clearData()->clearQuery()
                ->select("MAX({$pk}) as max_id")
                ->fetch();
            $maxId = (int) ($maxIdRow['max_id'] ?? 0);
            $nextId = $maxId + 1;

            // 重置序列
            $resetSeqSql = "SELECT setval('{$sequenceName}', {$nextId}, false)";
            $connector->query($resetSeqSql)->fetch();
        } catch (\Throwable $e) {
            // 序列修复失败，静默处理
        }
    }
}
