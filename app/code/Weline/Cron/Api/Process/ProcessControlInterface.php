<?php

declare(strict_types=1);

namespace Weline\Cron\Api\Process;

/**
 * Platform-compatible process operations published by Weline_Cron.
 */
interface ProcessControlInterface
{
    public function normalizeTaskName(string $name): string;

    public function isRunning(int $pid): bool;

    public function terminate(int $pid, string $processName): bool;

    public function removeLog(string $processName): bool;
}
