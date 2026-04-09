<?php

declare(strict_types=1);

namespace Weline\Server\Service;

/**
 * WLS Worker 进程级全局状态封装类
 *
 * 封装以下全局状态为静态属性：
 * - SSL 证书映射缓存 ($_domainPolicies)
 * - 证书恢复状态 ($_wlsRestoreAttempted)
 * - 静态文件缓存配置 ($WLS_STATIC_CACHE_*)
 * - CLI 参数 ($argv)
 * - 标准流 ($STDIN, $STDOUT, $STDERR)
 *
 * 这些是进程级状态，无法通过请求上下文隔离，
 * 使用 static 属性替代 global 变量，提供统一的访问接口。
 */
class WlsWorkerGlobals
{
    // ========== SSL 证书映射相关 ==========

    /**
     * 域名策略映射
     * 格式：[domain => ['force_https' => int, 'force_root_to_www' => int]]
     *
     * @var array<string, array{force_https: int, force_root_to_www: int}>
     */
    private static array $domainPolicies = [];

    /**
     * 证书恢复尝试记录（防止重复恢复）
     * 格式：[domain => true]
     *
     * @var array<string, bool>
     */
    private static array $restoreAttempted = [];

    // ========== 静态文件缓存配置 ==========

    /**
     * 静态文件缓存总量上限（字节）
     */
    private static int $staticCacheMaxTotal = 100 * 1024 * 1024; // 100MB

    /**
     * 单个静态文件缓存上限（字节）
     */
    private static int $staticCacheMaxSize = 1024 * 1024; // 1MB

    /**
     * 缓存淘汰阈值（字节）
     */
    private static int $cacheEvictionThreshold = 5 * 1024 * 1024; // 5MB

    /**
     * 上一次静态文件缓存命中信息
     */
    private static ?array $lastStaticCache = null;

    // ========== CLI 参数 ==========

    /**
     * 命令行参数
     *
     * @var array<string>
     */
    private static array $argv = [];

    // ========== 标准流 ==========

    /** @var resource */
    private static $stdin = null;

    /** @var resource */
    private static $stdout = null;

    /** @var resource */
    private static $stderr = null;

    // ==================== 访问器方法 ====================

    // ---------- Domain Policies ----------

    /**
     * 获取域名策略映射
     *
     * @return array<string, array{force_https: int, force_root_to_www: int}>
     */
    public static function getDomainPolicies(): array
    {
        return self::$domainPolicies;
    }

    /**
     * 设置域名策略映射
     *
     * @param array<string, array{force_https: int, force_root_to_www: int}> $policies
     */
    public static function setDomainPolicies(array $policies): void
    {
        self::$domainPolicies = $policies;
    }

    /**
     * 获取指定域名的策略
     *
     * @param string $domain
     * @return array{force_https: int, force_root_to_www: int}
     */
    public static function getDomainPolicy(string $domain): array
    {
        return self::$domainPolicies[$domain] ?? ['force_https' => 1, 'force_root_to_www' => 0];
    }

    // ---------- Restore Attempted ----------

    /**
     * 记录证书恢复尝试
     */
    public static function setRestoreAttempted(string $domain): void
    {
        self::$restoreAttempted[$domain] = true;
    }

    /**
     * 检查是否已尝试恢复过指定域名的证书
     */
    public static function isRestoreAttempted(string $domain): bool
    {
        return isset(self::$restoreAttempted[$domain]);
    }

    /**
     * 清除证书恢复尝试记录
     *
     * @param string[]|null $domains 空数组或 null 表示清除全部；指定域名数组则只清除指定域名
     */
    public static function clearRestoreAttempted(?array $domains = null): void
    {
        if ($domains === null || $domains === []) {
            self::$restoreAttempted = [];
        } else {
            foreach ($domains as $domain) {
                unset(self::$restoreAttempted[$domain]);
            }
        }
    }

    // ---------- Static Cache Config ----------

    public static function getStaticCacheMaxTotal(): int
    {
        return self::$staticCacheMaxTotal;
    }

    public static function setStaticCacheMaxTotal(int $maxTotal): void
    {
        self::$staticCacheMaxTotal = $maxTotal;
    }

    public static function getStaticCacheMaxSize(): int
    {
        return self::$staticCacheMaxSize;
    }

    public static function setStaticCacheMaxSize(int $maxSize): void
    {
        self::$staticCacheMaxSize = $maxSize;
    }

    public static function getCacheEvictionThreshold(): int
    {
        return self::$cacheEvictionThreshold;
    }

    public static function setCacheEvictionThreshold(int $threshold): void
    {
        self::$cacheEvictionThreshold = $threshold;
    }

    public static function getLastStaticCache(): ?array
    {
        return self::$lastStaticCache;
    }

    public static function setLastStaticCache(?array $cache): void
    {
        self::$lastStaticCache = $cache;
    }

    /**
     * 批量设置静态缓存配置
     *
     * @param int|null $maxTotal
     * @param int|null $maxSize
     * @param int|null $evictionThreshold
     */
    public static function configureStaticCache(
        ?int $maxTotal = null,
        ?int $maxSize = null,
        ?int $evictionThreshold = null
    ): void {
        if ($maxTotal !== null) {
            self::$staticCacheMaxTotal = $maxTotal;
        }
        if ($maxSize !== null) {
            self::$staticCacheMaxSize = $maxSize;
        }
        if ($evictionThreshold !== null) {
            self::$cacheEvictionThreshold = $evictionThreshold;
        }
    }

    // ---------- CLI Args ----------

    /**
     * 获取命令行参数
     *
     * @return array<string>
     */
    public static function getArgv(): array
    {
        return self::$argv;
    }

    /**
     * 设置命令行参数
     *
     * @param array<string> $argv
     */
    public static function setArgv(array $argv): void
    {
        self::$argv = $argv;
    }

    /**
     * 获取指定位置的命令行参数
     *
     * @param int $index
     * @param string|null $default
     * @return string|null
     */
    public static function getArgvAt(int $index, ?string $default = null): ?string
    {
        return self::$argv[$index] ?? $default;
    }

    // ---------- Standard Streams ----------

    /**
     * 获取标准输入
     *
     * @return resource
     */
    public static function getStdin()
    {
        if (self::$stdin === null) {
            self::$stdin = \defined('STDIN') ? STDIN : fopen('php://stdin', 'r');
        }
        return self::$stdin;
    }

    /**
     * 获取标准输出
     *
     * @return resource
     */
    public static function getStdout()
    {
        if (self::$stdout === null) {
            self::$stdout = \defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w');
        }
        return self::$stdout;
    }

    /**
     * 获取标准错误
     *
     * @return resource
     */
    public static function getStderr()
    {
        if (self::$stderr === null) {
            self::$stderr = \defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
        }
        return self::$stderr;
    }

    /**
     * 设置标准输入
     *
     * @param resource $stdin
     */
    public static function setStdin($stdin): void
    {
        self::$stdin = $stdin;
    }

    /**
     * 设置标准输出
     *
     * @param resource $stdout
     */
    public static function setStdout($stdout): void
    {
        self::$stdout = $stdout;
    }

    /**
     * 设置标准错误
     *
     * @param resource $stderr
     */
    public static function setStderr($stderr): void
    {
        self::$stderr = $stderr;
    }

    /**
     * 重置标准流到 /dev/null
     *
     * @param string|null $stdoutFile 日志文件路径，如果为 '/dev/null' 则重定向到 null 设备
     */
    public static function resetStd(?string $stdoutFile = null): void
    {
        // Windows 使用 NUL，Unix 使用 /dev/null
        $nullDevice = DIRECTORY_SEPARATOR === '/' ? '/dev/null' : 'NUL';
        if ($stdoutFile === null || $stdoutFile === '/dev/null' || $stdoutFile === 'NUL') {
            self::$stdin = fopen($nullDevice, 'r');
            self::$stdout = fopen($nullDevice, 'a');
            self::$stderr = fopen($nullDevice, 'a');
        } else {
            self::$stdin = fopen($nullDevice, 'r');
            self::$stdout = fopen($stdoutFile, 'a');
            self::$stderr = fopen($stdoutFile, 'a');
        }
    }

    // ---------- 兼容性别名方法（向后兼容） ----------

    /**
     * @deprecated 使用 getDomainPolicies() 替代
     */
    public static function &getDomainPoliciesRef(): array
    {
        return self::$domainPolicies;
    }

    /**
     * @deprecated 使用 getRestoreAttempted() 替代
     */
    public static function &getRestoreAttemptedRef(): array
    {
        return self::$restoreAttempted;
    }

    /**
     * 初始化 CLI 参数（从全局 $argv 导入）
     *
     * @param array<string>|null $argv
     */
    public static function initFromArgv(?array $argv = null): void
    {
        if ($argv !== null) {
            self::$argv = $argv;
        } elseif (\defined('argv')) {
            self::$argv = \argv();
        }
    }

    /**
     * 重置所有状态（主要用于测试）
     */
    public static function reset(): void
    {
        self::$domainPolicies = [];
        self::$restoreAttempted = [];
        self::$staticCacheMaxTotal = 100 * 1024 * 1024;
        self::$staticCacheMaxSize = 1024 * 1024;
        self::$cacheEvictionThreshold = 5 * 1024 * 1024;
        self::$lastStaticCache = null;
        self::$argv = [];
        self::$stdin = null;
        self::$stdout = null;
        self::$stderr = null;
    }
}
