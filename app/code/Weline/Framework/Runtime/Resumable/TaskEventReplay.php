<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable;

/**
 * A durable event page returned to an SSE transport.
 *
 * When a client cursor falls behind a compaction boundary, the runtime returns
 * a persisted `runtime_snapshot` event with its real sequence.  The transport
 * sends a cursor-free reset control frame first, then that event, then the
 * later incremental events.  No connection-local sequence is ever invented.
 */
final readonly class TaskEventReplay
{
    /**
     * @param list<TaskEvent> $events Events strictly after the effective
     *        cursor (the snapshot sequence when reset is required).
     */
    public function __construct(
        public TaskSnapshot $task,
        public int $requestedAfterSequence,
        public array $events,
        public bool $resetRequired = false,
        public int $compactedBeforeSequence = 0,
        public ?TaskEvent $snapshotEvent = null,
    ) {
        if ($this->requestedAfterSequence < 0 || $this->compactedBeforeSequence < 0) {
            throw new \InvalidArgumentException('Task event replay cursors cannot be negative.');
        }
        if ($this->resetRequired) {
            if ($this->compactedBeforeSequence <= $this->requestedAfterSequence) {
                throw new \InvalidArgumentException('A reset replay requires a later compaction boundary.');
            }
            if ($this->snapshotEvent === null || $this->snapshotEvent->event !== 'runtime_snapshot') {
                throw new \InvalidArgumentException('A reset replay requires a persisted runtime snapshot event.');
            }
        } elseif ($this->snapshotEvent !== null) {
            throw new \InvalidArgumentException('A snapshot event is only valid for a reset replay.');
        }

        $effectiveCursor = $this->snapshotEvent?->sequence ?? $this->requestedAfterSequence;
        if ($this->snapshotEvent !== null) {
            if ($this->snapshotEvent->taskId !== $this->task->taskId
                || $this->snapshotEvent->sequence <= $this->requestedAfterSequence
                || $this->snapshotEvent->sequence < $this->compactedBeforeSequence) {
                throw new \InvalidArgumentException('Task replay snapshot does not match its cursor or task.');
            }
        }

        $previous = $effectiveCursor;
        foreach ($this->events as $event) {
            if (!$event instanceof TaskEvent
                || $event->taskId !== $this->task->taskId
                || $event->sequence !== $previous + 1) {
                // A snapshot covers everything up to its own persisted
                // sequence.  Every later sequence must therefore be present
                // in a valid page.  Treat a gap as a durable-read/compaction
                // race, never as an excuse to make a client silently skip
                // state during recovery.
                throw new \InvalidArgumentException('Task replay events must be contiguous durable events for one task.');
            }
            $previous = $event->sequence;
        }
    }

    public function isTerminal(): bool
    {
        return $this->task->isTerminal();
    }
}
