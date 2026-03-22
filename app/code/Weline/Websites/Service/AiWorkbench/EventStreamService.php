<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

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
        $event->save();

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
    public function listEventsAfterId(int $sessionId, int $adminUserId, int $afterEventId, int $limit = 100): array
    {
        if ($this->sessionService->loadById($sessionId, $adminUserId) === null) {
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
}
