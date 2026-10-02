<?php

declare(strict_types=1);

namespace Weline\Framework\Router;

/**
 * 全页缓存（FPC）决策诊断（NDJSON → var/log/fpc-diag.log）。
 *
 * 生产上出现过「payload 文件持续增长、但同一 URL 连请求多次始终 MISS」的情况：
 * 缓存写入确实发生，读路径却永远落空，于是每个请求都付一次整页 SSR。
 * 由于 MISS 会同时来自多个门槛（是否允许构建、响应是否可共享、策略 TTL 是否为 0、
 * 是否有渲染器调用过 SharedResponseCachePolicy::forbid()），只看响应头无法区分。
 *
 * 启用方式（与 MemDiag 一致）：
 *   - 查询串或 Cookie `__fpcdiag=1`，或
 *   - 触碰 `var/fpc-diag.on`
 * 关闭：删掉该文件并去掉参数。未启用时所有方法立即返回，热路径零开销。
 */
final class FpcDiag
{
    private const FLAG_FILE = 'fpc-diag.on';
    private const LOG_FILE = 'fpc-diag.log';
    private const QUERY_KEY = '__fpcdiag';

    private static ?bool $armed = null;
    private static ?string $logPathOverride = null;
    private static int $seq = 0;

    public static function armFromRequest(?string $requestUri = null): void
    {
        $uri = $requestUri ?? (string)($_SERVER['REQUEST_URI'] ?? '');
        $on = false;
        if (isset($_GET[self::QUERY_KEY]) && (string)$_GET[self::QUERY_KEY] !== '0') {
            $on = true;
        } elseif (isset($_COOKIE[self::QUERY_KEY]) && (string)$_COOKIE[self::QUERY_KEY] !== '0') {
            $on = true;
        } elseif ($uri !== '' && \str_contains($uri, self::QUERY_KEY . '=')) {
            $on = !\str_contains($uri, self::QUERY_KEY . '=0');
        } elseif (\is_file(self::flagPath())) {
            $on = true;
        }
        self::$armed = $on;
    }

    public static function isArmed(): bool
    {
        if (self::$armed === null) {
            self::$armed = \is_file(self::flagPath());
        }

        return self::$armed;
    }

    /**
     * 记录一条决策事件。未启用时立即返回；任何写入失败都不得影响请求。
     *
     * @param array<string, mixed> $data
     */
    public static function event(string $name, array $data = []): void
    {
        if (!self::isArmed()) {
            return;
        }

        $payload = [
            'ts' => \gmdate('Y-m-d\TH:i:s.v\Z'),
            'seq' => ++self::$seq,
            'event' => $name,
            'pid' => \function_exists('getmypid') ? (int)\getmypid() : 0,
            'worker_id' => (string)($_SERVER['WLS_WORKER_ID'] ?? ''),
            'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'data' => $data,
        ];
        $line = \json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return;
        }

        $path = self::logPath();
        $dir = \dirname($path);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }
        @\file_put_contents($path, $line . "\n", \FILE_APPEND | \LOCK_EX);
    }

    /** 供单测使用：把日志写到临时路径；传 null 恢复默认。 */
    public static function useLogPath(?string $path): void
    {
        self::$logPathOverride = $path;
        self::$armed = null;
    }

    /** 供单测使用：重置启用状态与序号。 */
    public static function reset(): void
    {
        self::$armed = null;
        self::$seq = 0;
    }

    private static function flagPath(): string
    {
        return (\defined('BP') ? BP : (\dirname(__DIR__, 5) . \DIRECTORY_SEPARATOR))
            . 'var' . \DIRECTORY_SEPARATOR . self::FLAG_FILE;
    }

    private static function logPath(): string
    {
        if (self::$logPathOverride !== null) {
            return self::$logPathOverride;
        }

        return (\defined('BP') ? BP : (\dirname(__DIR__, 5) . \DIRECTORY_SEPARATOR))
            . 'var' . \DIRECTORY_SEPARATOR . 'log' . \DIRECTORY_SEPARATOR . self::LOG_FILE;
    }
}
