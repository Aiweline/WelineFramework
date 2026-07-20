<?php
declare(strict_types=1);

namespace Weline\Server\EventLoop;

final class EventExtLoop implements EventLoopInterface
{
    private \EventBase $base;

    /** @var array<int, \Event> */
    private array $readWatchers = [];

    /** @var array<int, \Event> */
    private array $writeWatchers = [];

    /** @var array<int, resource> */
    private array $readResources = [];

    /** @var array<int, resource> */
    private array $writeResources = [];

    /** @var array<int, resource> */
    private array $readyRead = [];

    /** @var array<int, resource> */
    private array $readyWrite = [];

    private \Event $timeoutEvent;

    public function __construct()
    {
        if (!\extension_loaded('event') || !\class_exists(\EventBase::class) || !\class_exists(\Event::class)) {
            throw new \RuntimeException('event extension is not loaded');
        }
        $this->base = new \EventBase();
        $this->timeoutEvent = new \Event(
            $this->base,
            -1,
            \Event::TIMEOUT,
            static function (): void {
            }
        );
    }

    public function __destruct()
    {
        foreach (\array_keys($this->readWatchers) as $rid) {
            $this->removeWatcher((int)$rid, true);
        }
        foreach (\array_keys($this->writeWatchers) as $rid) {
            $this->removeWatcher((int)$rid, false);
        }
        $this->timeoutEvent->del();
        $this->timeoutEvent->free();
    }

    public function wait(
        array &$read,
        array &$write,
        array &$except,
        int $timeoutSec,
        int $timeoutUsec
    ): int|false {
        $timeout = $this->normalizeTimeout($timeoutSec, $timeoutUsec);
        $this->readyRead = [];
        $this->readyWrite = [];

        $this->syncWatchers($read, true);
        $this->syncWatchers($write, false);

        if ($this->readWatchers === [] && $this->writeWatchers === []) {
            if ($timeout > 0.0) {
                \usleep((int)\round($timeout * 1_000_000));
            }
            $read = [];
            $write = [];
            $except = [];
            return 0;
        }

        if ($timeout > 0.0) {
            // Re-arm one process-lifetime timer instead of allocating/freeing an
            // Event object on every hot-loop iteration.
            $this->timeoutEvent->del();
            $this->timeoutEvent->add($timeout);
        }

        $flags = \EventBase::LOOP_ONCE;
        if ($timeout <= 0.0) {
            $flags |= \EventBase::LOOP_NONBLOCK;
        }

        $loopResult = $this->base->loop($flags);
        if ($timeout > 0.0) {
            $this->timeoutEvent->del();
        }

        if ($loopResult === false || $loopResult < 0) {
            $read = [];
            $write = [];
            $except = [];
            return false;
        }

        $read = \array_values(\array_intersect_key($this->readyRead, $this->readWatchers));
        $write = \array_values(\array_intersect_key($this->readyWrite, $this->writeWatchers));
        $except = [];

        return \count($read) + \count($write);
    }

    public function backend(): string
    {
        return 'event';
    }

    private function normalizeTimeout(int $timeoutSec, int $timeoutUsec): float
    {
        if ($timeoutSec < 0) {
            return 0.0;
        }
        if ($timeoutUsec < 0) {
            $timeoutUsec = 0;
        }

        return ((float) $timeoutSec) + (((float) $timeoutUsec) / 1_000_000.0);
    }

    private function syncWatchers(array $resources, bool $read): void
    {
        $requested = [];
        foreach ($resources as $resource) {
            if (!\is_resource($resource)) {
                continue;
            }
            $rid = \get_resource_id($resource);
            $requested[$rid] = $resource;
            $stored = $read ? ($this->readResources[$rid] ?? null) : ($this->writeResources[$rid] ?? null);
            if (\is_resource($stored) && $stored === $resource) {
                continue;
            }

            $this->removeWatcher($rid, $read);
            $this->addWatcher($rid, $resource, $read);
        }

        $watchers = $read ? $this->readWatchers : $this->writeWatchers;
        foreach (\array_keys($watchers) as $rid) {
            if (!isset($requested[$rid])) {
                $this->removeWatcher((int) $rid, $read);
            }
        }
    }

    private function addWatcher(int $rid, mixed $resource, bool $read): void
    {
        if (!\is_resource($resource)) {
            return;
        }

        $event = new \Event(
            $this->base,
            $resource,
            ($read ? \Event::READ : \Event::WRITE) | \Event::PERSIST,
            function (mixed $fd, int $what) use ($rid, $resource, $read): void {
                $expected = $read ? \Event::READ : \Event::WRITE;
                if (($what & $expected) === 0 || !\is_resource($resource)) {
                    return;
                }
                if ($read) {
                    $this->readyRead[$rid] = $resource;
                    return;
                }
                $this->readyWrite[$rid] = $resource;
            }
        );

        if (!$event->add()) {
            $event->free();
            return;
        }

        if ($read) {
            $this->readWatchers[$rid] = $event;
            $this->readResources[$rid] = $resource;
            return;
        }

        $this->writeWatchers[$rid] = $event;
        $this->writeResources[$rid] = $resource;
    }

    private function removeWatcher(int $rid, bool $read): void
    {
        $watchers = $read ? $this->readWatchers : $this->writeWatchers;
        if (!isset($watchers[$rid])) {
            return;
        }

        $watchers[$rid]->del();
        $watchers[$rid]->free();
        if ($read) {
            unset($this->readWatchers[$rid], $this->readResources[$rid], $this->readyRead[$rid]);
            return;
        }

        unset($this->writeWatchers[$rid], $this->writeResources[$rid], $this->readyWrite[$rid]);
    }
}
