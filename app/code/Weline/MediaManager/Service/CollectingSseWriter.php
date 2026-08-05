<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

/**
 * In-memory SSE collector for bin-query (no browser EventSource).
 */
final class CollectingSseWriter
{
    /** @var list<array{event:string,data:mixed}> */
    private array $events = [];
    private bool $started = false;

    public function setHeartbeatInterval(int $seconds): self
    {
        return $this;
    }

    public function start(): self
    {
        $this->started = true;
        return $this;
    }

    public function sendEvent(string $event, mixed $data = null, ?int $id = null): self
    {
        $this->started = true;
        $this->events[] = [
            'event' => $event,
            'data' => $data,
            'id' => $id,
        ];
        return $this;
    }

    public function sendEventAndYield(string $event, mixed $data = null, ?int $id = null): self
    {
        return $this->sendEvent($event, $data, $id);
    }

    public function sendHeartbeat(): self
    {
        return $this;
    }

    public function close(): void
    {
        $this->started = true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * @return list<array{event:string,data:mixed,id:?int}>
     */
    public function events(): array
    {
        return $this->events;
    }
}
