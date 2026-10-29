<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Framework\Console\ConsoleEncoding;
use Weline\Server\Log\WlsLogger;

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
     * Upper bound when honouring a host/php.ini opcache.memory_consumption.
     * CLI OPcache is per-process (Master + every Worker + Dispatcher each hold
     * their own segment), so an unbounded value multiplies across the fleet.
     */
    private const OPCACHE_MEMORY_CEILING_MB = 256;

    /**
     * Disk bytecode cache, relative to the framework base path. Shared across
     * process generations, unlike the per-process CLI OPcache segment.
     */
    private const RELATIVE_FILE_CACHE_DIR = 'generated/framework/opcache_file_cache';

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
                // CLI OPcache is per-process (not SHM-shared across Workers).
                // 88MB was sized from a "1533 scripts" warm-path MemDiag sample.
                // Measured warm footprint is 30.2KB/PHP script and 45.8KB/compiled
                // template, so 88MB only holds ≈2473 scripts while the real working
                // set is ≥13852 (12063 app PHP + 1789 templates written in 1h).
                // 192MB holds ≈5395 scripts; the disk bytecode cache below backfills
                // the evicted remainder across process generations.
                $opcacheMb = 192;
                $configured = \ini_get('opcache.memory_consumption');
                if (\is_string($configured) && \ctype_digit($configured)) {
                    $configuredMb = (int)$configured;
                    // Honour a larger host/php.ini value, but never above the
                    // per-process ceiling: Master + every Worker + Dispatcher
                    // each own an independent segment.
                    if ($configuredMb > $opcacheMb) {
                        $opcacheMb = \min($configuredMb, self::OPCACHE_MEMORY_CEILING_MB);
                    }
                }

                $arguments = [
                    '-d',
                    'opcache.enable_cli=1',
                    '-d',
                    'opcache.memory_consumption=' . $opcacheMb,
                ];

                // Shared on-disk bytecode cache: survives process exits, so a
                // recycled Worker reloads opcode instead of re-parsing ~13k
                // scripts. consistency_checks is intentionally left at its
                // default (On) so deploy-time edits never serve stale bytecode.
                $fileCache = self::ensureFileCacheDirectory();
                if ($fileCache !== '') {
                    $arguments[] = '-d';
                    $arguments[] = 'opcache.file_cache=' . $fileCache;
                    $arguments[] = '-d';
                    $arguments[] = 'opcache.file_cache_only=0';
                }

                // Tracing JIT is ON by default (user directive 2026-10-29).
                // Interaction with file_cache, measured 2026-10-29 (4-way matrix,
                // fresh cache dir per run):
                //   jit=on  + file_cache → cached=320 .bin=0     (does NOT write)
                //   jit=on  + no cache   → cached=320 .bin=2
                //   jit=off + file_cache → cached=320 .bin=321   (writes)
                //   jit=off + no cache   → cached=320 .bin=3
                // So JIT suppresses *writes* to the disk cache, but it still READS
                // a cache populated by another process: with a pre-warmed dir a
                // fresh jit=on process cold-starts in 0.18-0.20s vs 0.38-0.42s
                // without one, and opcache_get_status reports hits=339 misses=0
                // with jit.on=1. Keeping file_cache alongside JIT is therefore
                // still a net win for warm starts; the cache is filled by CLI
                // commands (compile/warm) which do not enable JIT.
                $arguments[] = '-d';
                $arguments[] = 'opcache.jit=tracing';
                $arguments[] = '-d';
                $arguments[] = 'opcache.jit_buffer_size=64M';

                return $arguments;
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

    /**
     * Absolute path of the shared disk bytecode cache, created on first use.
     * Returns '' when the directory cannot be created or written, so callers
     * keep the memory-only fast path instead of failing process start.
     */
    public static function ensureFileCacheDirectory(): string
    {
        $base = \defined('BP') ? (string)\BP : '';
        if ($base === '') {
            return '';
        }

        $path = \rtrim($base, '/\\') . \DIRECTORY_SEPARATOR
            . \str_replace('/', \DIRECTORY_SEPARATOR, self::RELATIVE_FILE_CACHE_DIR);

        if (!\is_dir($path)) {
            // Concurrent Worker starts can race here; a lost race is not an error.
            @\mkdir($path, 0775, true);
        }

        if (!\is_dir($path) || !\is_writable($path)) {
            return '';
        }

        return $path;
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
