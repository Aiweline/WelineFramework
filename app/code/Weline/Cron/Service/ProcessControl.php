<?php

declare(strict_types=1);

namespace Weline\Cron\Service;

use Weline\Cron\Api\Process\ProcessControlInterface;
use Weline\Cron\Helper\Process;

final class ProcessControl implements ProcessControlInterface
{
    public function normalizeTaskName(string $name): string
    {
        return Process::initTaskName($name);
    }

    public function isRunning(int $pid): bool
    {
        return $pid > 0 && Process::isProcessRunning($pid);
    }

    public function terminate(int $pid, string $processName): bool
    {
        return $pid > 0 && Process::killPid($pid, $processName);
    }

    public function removeLog(string $processName): bool
    {
        return Process::unsetLogProcessFilePath($processName);
    }
}
