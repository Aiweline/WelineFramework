<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Integration;

use Closure;
use Weline\Framework\Http\Sse\SseWriter;

final class DuplicateObserverHeartbeatWriter extends SseWriter
{
    private bool $heartbeatTriggered = false;

    /**
     * @var list<array{event:string,data:mixed}>
     */
    private array $events = [];

    public function __construct(
        private readonly Closure $onFirstHeartbeat,
    ) {
    }

    public function start(): static
    {
        return $this;
    }

    public function maybeHeartbeat(): self
    {
        if (!$this->heartbeatTriggered) {
            $this->heartbeatTriggered = true;
            ($this->onFirstHeartbeat)();
        }

        return $this;
    }

    public function sendEvent(string $event, mixed $data = null, ?int $id = null): static
    {
        $this->events[] = ['event' => $event, 'data' => $data];

        return $this;
    }

    public function sendError(string $message, int $code = 500): static
    {
        $this->events[] = ['event' => 'error', 'data' => ['message' => $message, 'code' => $code]];

        return $this;
    }

    public function complete(mixed $data = null): void
    {
        $this->events[] = ['event' => 'done', 'data' => $data];
    }

    public function isAlive(): bool
    {
        return true;
    }

    /**
     * @return list<array{event:string,data:mixed}>
     */
    public function eventsByName(string $eventName): array
    {
        return \array_values(\array_filter(
            $this->events,
            static fn(array $event): bool => $event['event'] === $eventName
        ));
    }

    public function countEvents(string $eventName): int
    {
        return \count($this->eventsByName($eventName));
    }
}
