<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Agent;

/**
 * Request-scoped agent code for runtime tool governance.
 */
final class AgentExecutionContext
{
    /** @var list<string> */
    private static array $stack = [];

    public static function enter(string $agentCode): void
    {
        $code = trim($agentCode);
        if ($code === '') {
            throw new \InvalidArgumentException('Agent code is required.');
        }
        self::$stack[] = $code;
    }

    public static function leave(): void
    {
        if (self::$stack === []) {
            return;
        }
        array_pop(self::$stack);
    }

    public static function currentAgentCode(): ?string
    {
        if (self::$stack === []) {
            return null;
        }

        return self::$stack[array_key_last(self::$stack)];
    }

    public static function reset(): void
    {
        self::$stack = [];
    }
}
