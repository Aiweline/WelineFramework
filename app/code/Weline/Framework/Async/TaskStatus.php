<?php

declare(strict_types=1);

namespace Weline\Framework\Async;

/**
 * Runtime-neutral asynchronous task lifecycle values.
 */
final class TaskStatus
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const STOP = 'stop';
    public const ERROR = 'error';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::PENDING,
            self::RUNNING,
            self::DONE,
            self::STOP,
            self::ERROR,
        ];
    }

    public static function isValid(string $status): bool
    {
        return \in_array($status, self::values(), true);
    }
}
