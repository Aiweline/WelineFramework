<?php
declare(strict_types=1);

namespace Weline\Server\EventLoop;

final class EventExtLoop implements EventLoopInterface
{
    private \EventBase $base;

    public function __construct()
    {
        if (!\extension_loaded('event') || !\class_exists(\EventBase::class) || !\class_exists(\Event::class)) {
            throw new \RuntimeException('event extension is not loaded');
        }
        $this->base = new \EventBase();
    }

    public function wait(
        array &$read,
        array &$write,
        array &$except,
        int $timeoutSec,
        int $timeoutUsec
    ): int|false {
        $base = $this->base;
        $readyRead = [];
        $readyWrite = [];
        $watchers = [];
        $timeout = $this->normalizeTimeout($timeoutSec, $timeoutUsec);

        foreach ($read as $resource) {
            if (!\is_resource($resource)) {
                continue;
            }
            $rid = \get_resource_id($resource);
            $event = new \Event(
                $base,
                $resource,
                \Event::READ,
                static function () use (&$readyRead, $rid, $resource): void {
                    $readyRead[$rid] = $resource;
                }
            );
            if ($event->add($timeout)) {
                $watchers[] = $event;
            }
        }

        foreach ($write as $resource) {
            if (!\is_resource($resource)) {
                continue;
            }
            $rid = \get_resource_id($resource);
            $event = new \Event(
                $base,
                $resource,
                \Event::WRITE,
                static function () use (&$readyWrite, $rid, $resource): void {
                    $readyWrite[$rid] = $resource;
                }
            );
            if ($event->add($timeout)) {
                $watchers[] = $event;
            }
        }

        // 无 watcher 时，退化为定时等待，保持 wait 语义一致。
        if ($watchers === []) {
            if ($timeout > 0.0) {
                \usleep((int) \round($timeout * 1_000_000));
            }
            $read = [];
            $write = [];
            $except = [];
            return 0;
        }

        $loopResult = $base->loop(\EventBase::LOOP_ONCE);
        foreach ($watchers as $watcher) {
            $watcher->del();
            $watcher->free();
        }

        if ($loopResult === false || $loopResult < 0) {
            $read = [];
            $write = [];
            $except = [];
            return false;
        }

        $read = \array_values($readyRead);
        $write = \array_values($readyWrite);
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
}

