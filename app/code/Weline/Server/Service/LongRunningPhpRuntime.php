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
     * opcache.interned_strings_buffer sizing, in MB (user directive 2026-10-30).
     * The host php.ini default is 8MB and this key was absent from the startup
     * args, so Workers ran with a permanently saturated interned buffer; once
     * full, OPcache stops sharing strings and rewrites them per-script, which
     * measured ~4.4x opcode inflation on the same locale bag. See the sizing
     * note at the argument injection site for the cold-vs-warm measurements.
     * 40MB sits above the measured 32MB elbow to leave head-room for 38 locales.
     */
    private const OPCACHE_INTERNED_STRINGS_BUFFER_MB = 40;

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

                // Interned strings buffer (user directive 2026-10-30 → 40MB).
                // Why this matters here: the host php.ini default is 8MB, and this
                // key is NOT in the six startup args, so every Worker inherited the
                // 8MB default and sat permanently in the "buffer full" state. Once
                // interned storage is full, OPcache stops sharing strings and
                // rewrites them into each script's own opcode block instead, which
                // measured 4.4x inflation on the same locale bag in the same process:
                //   zh_Hans_CN.shared.php, interned free=5.52MB → shared opcode=1.110MB
                //   zh_Hans_CN.shared.php, interned free=0.00MB → shared opcode=4.908MB
                // Sizing note: measured cold-start of 80 bags showed isb=8 → 143.45MB
                // and isb=16 → 145.24MB (no gain, +1.79MB), while a warm layout
                // (layout + 320 templates + 80 bags) showed isb=8 → 255.94MB,
                // isb=16 → 218.63MB, isb=32 → 197.72MB (58.22MB saved at 8→32).
                // So the benefit only materialises once the working set is warm,
                // which is the normal steady state of these long-running Workers.
                // 40MB is chosen above the measured 32MB point for head-room across
                // 38 locales; cost is a one-off per-process segment allocation.
                $arguments[] = '-d';
                $arguments[] = 'opcache.interned_strings_buffer='
                    . self::OPCACHE_INTERNED_STRINGS_BUFFER_MB;

                // Shared on-disk bytecode cache: survives process exits, so a
                // recycled Worker reloads opcode instead of re-parsing ~13k
                // scripts.
                //
                // 盘缓存必须配合时间戳校验：file_cache_consistency_checks 只校验
                // 缓存文件自身完整性，不比对源文件 mtime。宿主机 php.ini 把
                // validate_timestamps 设为 Off 时，实测会让 Worker 长期执行旧字节码
                // （ConnectionPool.php 的 .bin 曾落后源文件 17 小时，热重载后仍缺新方法）。
                $fileCache = self::ensureFileCacheDirectory();
                if ($fileCache !== '') {
                    $arguments[] = '-d';
                    $arguments[] = 'opcache.file_cache=' . $fileCache;
                    $arguments[] = '-d';
                    $arguments[] = 'opcache.file_cache_only=0';
                    $arguments[] = '-d';
                    $arguments[] = 'opcache.validate_timestamps=1';
                    $arguments[] = '-d';
                    $arguments[] = 'opcache.revalidate_freq=0';
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

    /**
     * 启动期一次的陈旧 .bin 清理（由 `server:start` / `server:reload` 的 CLI 调用）。
     *
     * 不放在 ensureFileCacheDirectory()：那里每个 Worker 启动都会经过，会把盘缓存
     * 打成"每个进程都扫一遍"的重活；JIT=on 又不会回填，反复扫描没有收益。
     * 用 flock + stamp 记录本次计数，使"第二次启动 removed=0"可被外部核对。
     *
     * @return array{scanned:int, removed:int, kept:int, orphan:int, dir:string}
     */
    public static function pruneStaleFileCacheOnStartup(): array
    {
        $dir = self::ensureFileCacheDirectory();
        if ($dir === '') {
            return ['scanned' => 0, 'removed' => 0, 'kept' => 0, 'orphan' => 0, 'dir' => ''];
        }

        $stampPath = \rtrim($dir, '/\\') . \DIRECTORY_SEPARATOR . '.prune.stamp';
        $lock = @\fopen($stampPath . '.lock', 'c');
        if (\is_resource($lock)) {
            @\flock($lock, LOCK_EX);
        }

        try {
            $result = self::pruneStaleFileCache($dir);
            $payload = \json_encode([
                'at' => \date('c'),
                'scanned' => $result['scanned'],
                'removed' => $result['removed'],
                'kept' => $result['kept'],
                'orphan' => $result['orphan'] ?? 0,
            ], JSON_UNESCAPED_SLASHES);
            @\file_put_contents($stampPath, (string) $payload);
            // 无论 removed 是否为 0 都留一行：否则"跳过 / 0 删除 / 未执行"三种情况无法区分。
            @\error_log('[opcache] stale bytecode prune ' . (string) $payload);
        } finally {
            if (\is_resource($lock)) {
                @\flock($lock, LOCK_UN);
                @\fclose($lock);
            }
        }

        return $result;
    }

    /**
     * 清理陈旧字节码缓存：源文件不存在，或源文件 mtime 比 .bin 新，则删除该 .bin。
     *
     * 为什么必须有这一步：`opcache.file_cache_consistency_checks` 只校验缓存文件自身完整性，
     * 不比对源文件时间戳；宿主机 `validate_timestamps=Off` 时，进程会长期执行旧字节码
     * （实测 ConnectionPool 的 .bin 落后源文件 17 小时，热重载后仍缺新方法）。
     * unlink 对正在读取该 .bin 的进程是安全的（POSIX：已打开的 fd 继续有效）。
     *
     * @param string|null $dir 测试用覆盖目录；null 用生产缓存目录
     * @return array{scanned:int, removed:int, kept:int, dir:string}
     */
    public static function pruneStaleFileCache(?string $dir = null): array
    {
        $dir = $dir ?? self::ensureFileCacheDirectory();
        $result = ['scanned' => 0, 'removed' => 0, 'kept' => 0, 'orphan' => 0, 'dir' => $dir];
        if ($dir === '' || !\is_dir($dir)) {
            return $result;
        }

        $prefixLength = \strlen(\rtrim($dir, '/\\') . \DIRECTORY_SEPARATOR);
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch (\Throwable) {
            return $result;
        }

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (!\str_ends_with($path, '.bin')) {
                continue;
            }
            $result['scanned']++;

            // 缓存键 = {system_id}/{源文件绝对路径}.bin：去掉首层 system_id 与末尾 .bin
            // 即源文件绝对路径（与 cache:complicategc --bin-gc 的判定一致）。
            $relative = \substr($path, $prefixLength);
            $segments = \explode(\DIRECTORY_SEPARATOR, $relative);
            if (\count($segments) < 2) {
                $result['kept']++;
                continue;
            }
            \array_shift($segments);
            $source = \implode(\DIRECTORY_SEPARATOR, $segments);
            $source = \substr($source, 0, -4);
            // 缓存键去掉了绝对路径的前导分隔符（/Users/... → Users/...），这里补回，
            // 否则所有 .bin 都会被判成「源文件不存在」而误删整库缓存。
            // Windows 盘符（C:/...）本身就是绝对路径，不再补前导分隔符。
            if (!\preg_match('#\A[A-Za-z]:[\\\\/]#', $source)) {
                $source = \DIRECTORY_SEPARATOR . $source;
            }

            // 只允许处理本项目根下的缓存：换软链 / 迁移 / BP 变化时还原出的路径可能指向
            // 别的项目，对它们一律只统计不删除，避免把整库有效字节码删光。
            $projectRoot = \rtrim((string) (\defined('BP') ? \BP : ''), '/\\');
            if ($projectRoot === '' || !\str_starts_with($source, $projectRoot . \DIRECTORY_SEPARATOR)) {
                $result['orphan']++;
                $result['kept']++;
                continue;
            }

            $stale = !\is_file($source);
            if (!$stale) {
                $sourceMtime = @\filemtime($source);
                $stale = $sourceMtime !== false && $sourceMtime > (int) $file->getMTime();
            }

            if ($stale) {
                if (@\unlink($path)) {
                    $result['removed']++;
                    continue;
                }
                $result['kept']++;
                continue;
            }

            $result['kept']++;
        }

        return $result;
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
