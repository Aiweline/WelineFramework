<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Sse;

/**
 * In-memory SSE collector for bin-query resource polling (no browser EventSource).
 */
final class CollectingSseWriter
{
    /** @var list<array{event:string,data:mixed,id:?int}> */
    private array $events = [];
    private bool $started = false;
    private bool $closed = false;
    private mixed $completePayload = null;

    public function setRetryInterval(int $milliseconds): self
    {
        return $this;
    }

    public function setHeartbeatInterval(int $seconds): self
    {
        return $this;
    }

    public function setCooperativeYield(bool $enabled): self
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

    public function sendData(mixed $data = null, ?int $id = null): self
    {
        return $this->sendEvent('message', $data, $id);
    }

    public function sendHeartbeat(): self
    {
        return $this;
    }

    public function maybeHeartbeat(): self
    {
        return $this;
    }

    public function sendError(string $message, int $code = 500): self
    {
        return $this->sendEvent('error', [
            'message' => $message,
            'code' => $code,
            'http_code' => $code,
        ]);
    }

    public function complete(mixed $data = null): void
    {
        $this->completePayload = $data;
        $this->sendEvent('done', is_array($data) ? $data : ['data' => $data]);
        $this->closed = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function isAlive(): bool
    {
        return $this->started && !$this->closed;
    }

    /**
     * @return list<array{event:string,data:mixed,id:?int}>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function completePayload(): mixed
    {
        return $this->completePayload;
    }

    /**
     * @return array{success:bool,events:list<array{event:string,data:mixed,id:?int}>,complete:mixed}
     */
    public function toArray(): array
    {
        $complete = $this->completePayload;
        $success = true;
        if (is_array($complete) && array_key_exists('success', $complete)) {
            $success = (bool)$complete['success'];
        }

        return [
            'success' => $success,
            'events' => $this->events,
            'complete' => $complete,
        ];
    }
}
