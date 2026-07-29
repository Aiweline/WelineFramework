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
     * Dispatcher keeps one client socket and one upstream socket per proxied
     * connection. A common Linux soft limit of 1024 therefore becomes
     * exhausted at roughly 500 concurrent requests even when the hard limit is
     * much higher, producing false "all workers unavailable" responses.
     */
    private const RECOMMENDED_OPEN_FILE_SOFT_LIMIT = 65536;

    /**
     * Unix WLS processes explicitly enable CLI OPcache when the extension is
     * available so every Master/Worker generation gets the production bytecode
     * fast path even when the interactive CLI default is disabled.
     *
     * PHP tracing JIT is process-shared on Windows when CLI OPcache is enabled.
     * Long-running WLS processes start and reload concurrently, and PHP 8.4 can
     * crash in ntdll with 0xC0000005 while publishing/reusing that shared JIT
     * buffer. Respect the installed bytecode OPcache policy there, but disable
     * the JIT buffer at process creation time; runtime ini_set() is too late.
     *
     * @return list<string>
     */
    public static function startupCliArguments(): array
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            if (\extension_loaded('Zend OPcache') || \function_exists('opcache_get_status')) {
                return [
                    '-d',
                    'opcache.enable_cli=1',
                ];
            }

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
        $this->raiseOpenFileSoftLimit();
        $this->setIniValue('max_execution_time', '0');
        $this->setTimeLimit(0);
        $this->setIgnoreUserAbort(true);
    }

    protected function raiseOpenFileSoftLimit(): void
    {
        if (!$this->supportsPosixOpenFileLimits()) {
            return;
        }

        $limits = $this->readPosixResourceLimits();
        if (!\is_array($limits)) {
            $this->reportOpenFileLimitWarning('Unable to read the process open-file limit.');
            return;
        }

        $softRaw = $limits['soft openfiles'] ?? null;
        $hardRaw = $limits['hard openfiles'] ?? null;
        if ($this->isUnlimitedResourceLimit($softRaw)) {
            return;
        }

        $soft = $this->normalizeFiniteResourceLimit($softRaw);
        if ($soft === null) {
            $this->reportOpenFileLimitWarning('The process soft open-file limit is not numeric.');
            return;
        }
        if ($soft >= self::RECOMMENDED_OPEN_FILE_SOFT_LIMIT) {
            return;
        }

        $hardUnlimited = $this->isUnlimitedResourceLimit($hardRaw);
        $hard = $hardUnlimited ? null : $this->normalizeFiniteResourceLimit($hardRaw);
        if (!$hardUnlimited && $hard === null) {
            $this->reportOpenFileLimitWarning('The process hard open-file limit is not numeric.');
            return;
        }

        $target = $hardUnlimited
            ? self::RECOMMENDED_OPEN_FILE_SOFT_LIMIT
            : \min(self::RECOMMENDED_OPEN_FILE_SOFT_LIMIT, (int)$hard);
        if ($target <= $soft) {
            $this->reportOpenFileLimitWarning(
                "Open-file hard limit {$hard} cannot satisfy the recommended "
                . self::RECOMMENDED_OPEN_FILE_SOFT_LIMIT . '.'
            );
            return;
        }

        $hardForSet = $hardUnlimited ? $this->posixUnlimitedResourceLimit() : (int)$hard;
        if (!$this->setPosixOpenFileLimit($target, $hardForSet)) {
            $this->reportOpenFileLimitWarning(
                "Unable to raise the process open-file soft limit from {$soft} to {$target}."
            );
            return;
        }

        if ($target < self::RECOMMENDED_OPEN_FILE_SOFT_LIMIT) {
            $this->reportOpenFileLimitWarning(
                "Open-file soft limit was raised to {$target}, below the recommended "
                . self::RECOMMENDED_OPEN_FILE_SOFT_LIMIT . ' because of the host hard limit.'
            );
        }
    }

    protected function supportsPosixOpenFileLimits(): bool
    {
        return !$this->isWindows()
            && \function_exists('posix_getrlimit')
            && \function_exists('posix_setrlimit')
            && \defined('POSIX_RLIMIT_NOFILE');
    }

    protected function readPosixResourceLimits(): array|false
    {
        return @\posix_getrlimit();
    }

    protected function setPosixOpenFileLimit(int $softLimit, int $hardLimit): bool
    {
        return @\posix_setrlimit(\POSIX_RLIMIT_NOFILE, $softLimit, $hardLimit);
    }

    protected function posixUnlimitedResourceLimit(): int
    {
        return \defined('POSIX_RLIMIT_INFINITY') ? (int)\constant('POSIX_RLIMIT_INFINITY') : -1;
    }

    protected function reportOpenFileLimitWarning(string $message): void
    {
        WlsLogger::warning_('[WLS Runtime] ' . $message);
    }

    private function isUnlimitedResourceLimit(mixed $value): bool
    {
        return \is_string($value) && \strtolower(\trim($value)) === 'unlimited';
    }

    private function normalizeFiniteResourceLimit(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (\is_string($value) && \preg_match('/^\d+$/D', $value) === 1) {
            return (int)$value;
        }

        return null;
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
            'session_server.php',
            'worker.php',
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
