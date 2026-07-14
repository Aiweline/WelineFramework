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
    /**
     * PHP tracing JIT is process-shared on Windows when CLI OPcache is enabled.
     * Long-running WLS processes start and reload concurrently, and PHP 8.4 can
     * crash in ntdll with 0xC0000005 while publishing/reusing that shared JIT
     * buffer. Keep bytecode OPcache enabled, but disable only the JIT buffer at
     * process creation time; runtime ini_set() is too late for this directive.
     *
     * @return list<string>
     */
    public static function startupCliArguments(): array
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        return [
            '-d',
            'opcache.jit=0',
            '-d',
            'opcache.jit_buffer_size=0',
        ];
    }

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
            'protocol_edge.php',
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
