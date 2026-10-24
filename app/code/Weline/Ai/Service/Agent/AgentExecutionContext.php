<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Agent;

/**
 * Request-scoped agent code for runtime tool governance.
 */
final class AgentExecutionContext
{
    private static ?\WeakMap $fiberStacks = null;

    /** @var list<string> */
    private static array $mainStack = [];

    public static function enter(string $agentCode): void
    {
        $code = trim($agentCode);
        if ($code === '') {
            throw new \InvalidArgumentException('Agent code is required.');
        }
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            self::$mainStack[] = $code;

            return;
        }
        self::$fiberStacks ??= new \WeakMap();
        /** @var list<string> $stack */
        $stack = self::$fiberStacks[$fiber] ?? [];
        $stack[] = $code;
        self::$fiberStacks[$fiber] = $stack;
    }

    public static function leave(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            if (self::$mainStack !== []) {
                array_pop(self::$mainStack);
            }

            return;
        }
        self::$fiberStacks ??= new \WeakMap();
        /** @var list<string> $stack */
        $stack = self::$fiberStacks[$fiber] ?? [];
        if ($stack !== []) {
            array_pop($stack);
        }
        self::$fiberStacks[$fiber] = $stack;
    }

    public static function currentAgentCode(): ?string
    {
        $fiber = \Fiber::getCurrent();
        $stack = $fiber === null ? self::$mainStack : (self::$fiberStacks[$fiber] ?? []);

        return $stack === [] ? null : $stack[array_key_last($stack)];
    }

    public static function reset(): void
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            self::$mainStack = [];

            return;
        }
        self::$fiberStacks ??= new \WeakMap();
        self::$fiberStacks[$fiber] = [];
    }
}
