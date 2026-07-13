<?php

declare(strict_types=1);

namespace Weline\Queue\Api;

use Weline\Framework\Async\TaskStatus;

/**
 * Stable queue lifecycle values shared by producers and consumers.
 */
final class QueueStatus
{
    public const PENDING = TaskStatus::PENDING;
    public const RUNNING = TaskStatus::RUNNING;
    public const DONE = TaskStatus::DONE;
    public const STOP = TaskStatus::STOP;
    public const ERROR = TaskStatus::ERROR;

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return TaskStatus::values();
    }

    public static function isValid(string $status): bool
    {
        return TaskStatus::isValid($status);
    }
}
