<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * AI 建站智能体会话：创建、读写 scope、事件流、站点/主题/发布状态
 */

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSessionEvent;

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
        $session->setData(AiSiteAgentSession::schema_fields_WELINE_THEME_ID, 0);
        $session->setData(AiSiteAgentSession::schema_fields_STAGE, AiSiteAgentSession::STAGE_BRIEF);
        $session->setData(AiSiteAgentSession::schema_fields_PUBLISH_STATUS, AiSiteAgentSession::PUBLISH_STATUS_DRAFT);
        $session->setScopeArray($initialScope);
        $session->save();
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

    /**
     * @param array<string, mixed> $patch 顶层键与 scope 合并（array_replace）
     */
    public function mergeScope(int $sessionId, int $forAdminUserId, array $patch): bool
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return false;
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

    public function bindWelineTheme(int $sessionId, int $forAdminUserId, int $welineThemeId): bool
    {
        $session = $this->loadById($sessionId, $forAdminUserId);
        if ($session === null) {
            return false;
        }
        $session->setData(AiSiteAgentSession::schema_fields_WELINE_THEME_ID, \max(0, $welineThemeId));
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
    public function appendEvent(int $sessionId, int $forAdminUserId, string $eventType, array $payload = []): bool
    {
        if ($this->loadById($sessionId, $forAdminUserId) === null) {
            return false;
        }
        $event = clone $this->eventModel;
        $event->clearData()->clearQuery();
        $event->setData(AiSiteAgentSessionEvent::schema_fields_AGENT_SESSION_ID, $sessionId);
        $event->setData(AiSiteAgentSessionEvent::schema_fields_EVENT_TYPE, $eventType);
        $event->setPayloadArray($payload);
        $event->save();
        return true;
    }

    /**
     * 按事件主键游标拉取新事件（供 SSE 增量推送）
     *
     * @return list<array{event_id: int, event_type: string, payload: array<string, mixed>, create_time: string}>
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
                'event_type' => $m->getEventType(),
                'payload' => $m->getPayloadArray(),
                'create_time' => (string) ($row[AiSiteAgentSessionEvent::schema_fields_CREATE_TIME] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @return list<array{event_id: int, event_type: string, payload: array<string, mixed>, create_time: string}>
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
                'event_type' => $m->getEventType(),
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
     * @return list<array{public_id: string, stage: string, publish_status: string, website_id: int, weline_theme_id: int, update_time: string}>
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
                'weline_theme_id' => $m->getWelineThemeId(),
                'update_time' => (string) ($row[AiSiteAgentSession::schema_fields_UPDATE_TIME] ?? ''),
            ];
        }
        return $out;
    }
}
