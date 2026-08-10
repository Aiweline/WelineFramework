<?php

declare(strict_types=1);

namespace Weline\Framework\Session;

use Weline\Framework\Runtime\Runtime;

/**
 * Request-local mutable state owned by Session itself.
 *
 * SessionFactory owns its object scopes independently. This state only holds
 * the shutdown queue and hot-path counters that used to be process-global.
 *
 * @internal
 */
final class SessionRequestState
{
    /** @var \WeakMap<\Fiber, self>|null */
    private static ?\WeakMap $fiberStates = null;

    private static ?self $mainState = null;

    /** @var array<int|string, Session> */
    public array $instancesForShutdown = [];

    public int $hotPathLogCount = 0;

    public int $hotPathLogSuppressedCount = 0;

    public bool $hotPathLogSuppressionAnnounced = false;

    public static function current(): self
    {
        $fiber = self::currentRequestFiber();
        if ($fiber === null) {
            return self::$mainState ??= new self();
        }

        self::$fiberStates ??= new \WeakMap();
        if (!isset(self::$fiberStates[$fiber])) {
            self::$fiberStates[$fiber] = new self();
        }

        return self::$fiberStates[$fiber];
    }

    public static function peekCurrent(): ?self
    {
        $fiber = self::currentRequestFiber();
        if ($fiber === null) {
            return self::$mainState;
        }

        return self::forFiber($fiber);
    }

    public static function forFiber(\Fiber $fiber): ?self
    {
        if (self::$fiberStates === null || !isset(self::$fiberStates[$fiber])) {
            return null;
        }

        return self::$fiberStates[$fiber];
    }

    public static function resetCurrent(): void
    {
        $fiber = self::currentRequestFiber();
        if ($fiber === null) {
            self::$mainState = null;
            return;
        }

        self::resetForFiber($fiber);
    }

    public static function resetForFiber(\Fiber $fiber): void
    {
        if (self::$fiberStates !== null && isset(self::$fiberStates[$fiber])) {
            unset(self::$fiberStates[$fiber]);
        }
    }

    /**
     * @return list<self>
     */
    public static function allStates(): array
    {
        $states = [];
        if (self::$mainState !== null) {
            $states[] = self::$mainState;
        }
        if (self::$fiberStates !== null) {
            foreach (self::$fiberStates as $state) {
                $states[] = $state;
            }
        }

        return $states;
    }

    public static function resetAll(): void
    {
        self::$mainState = null;
        self::$fiberStates = null;
    }

    public static function fiberStateCount(): int
    {
        return self::$fiberStates === null ? 0 : \count(self::$fiberStates);
    }

    private static function currentRequestFiber(): ?\Fiber
    {
        if (!Runtime::isPersistent()) {
            return null;
        }

        return \Fiber::getCurrent();
    }
}
