<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\Console\ConsoleEncoding;

/**
 * Long-running daemon processes must explicitly disable PHP execution limits.
 * Otherwise Master / Worker / Dispatcher can be killed by max_execution_time
 * and the control plane will degrade into orphan cleanup paths.
 */
class LongRunningPhpRuntime
{
    public function apply(): void
    {
        $this->initConsoleEncoding();
        $this->setIniValue('max_execution_time', '0');
        $this->setTimeLimit(0);
        $this->setIgnoreUserAbort(true);
    }

    protected function initConsoleEncoding(): void
    {
        if (\PHP_SAPI !== 'cli') {
            return;
        }
        if ($this->isWindows() && $this->isWlsDaemonProcess()) {
            return;
        }
        $this->initializeConsoleEncoding();
    }

    protected function initializeConsoleEncoding(): void
    {
        ConsoleEncoding::initForCli();
    }

    protected function isWindows(): bool
    {
        return \PHP_OS_FAMILY === 'Windows';
    }

    protected function isWlsDaemonProcess(): bool
    {
        $roleValue = $_SERVER['WLS_PROCESS_ROLE']
            ?? $_ENV['WLS_PROCESS_ROLE']
            ?? \getenv('WLS_PROCESS_ROLE');
        $role = \is_string($roleValue) ? $roleValue : '';
        if ($role !== '') {
            return true;
        }

        $script = \basename((string)($_SERVER['argv'][0] ?? $_SERVER['SCRIPT_FILENAME'] ?? ''));
        return \in_array($script, [
            'dispatcher.php',
            'http_redirect_worker.php',
            'session_server.php',
            'worker.php',
            'worker_ssl.php',
            'worker_ssl_event.php',
        ], true);
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
