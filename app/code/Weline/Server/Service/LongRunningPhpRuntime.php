<?php
declare(strict_types=1);

namespace Weline\Server\Service;

/**
 * Long-running daemon processes must explicitly disable PHP execution limits.
 * Otherwise Master / Worker / Dispatcher can be killed by max_execution_time
 * and the control plane will degrade into orphan cleanup paths.
 */
class LongRunningPhpRuntime
{
    public function apply(): void
    {
        $this->setIniValue('max_execution_time', '0');
        $this->setTimeLimit(0);
        $this->setIgnoreUserAbort(true);
    }

    protected function setIniValue(string $key, string $value): void
    {
        if (\function_exists('ini_set')) {
            @\ini_set($key, $value);
        }
    }

    protected function setTimeLimit(int $seconds): void
    {
        if (\function_exists('set_time_limit')) {
            @\set_time_limit($seconds);
        }
    }

    protected function setIgnoreUserAbort(bool $enabled): void
    {
        if (\function_exists('ignore_user_abort')) {
            @\ignore_user_abort($enabled);
        }
    }
}
