<?php

declare(strict_types=1);

/**
 * Weline 面板授权会话下将 memory_limit 设为「进程启动基线 × 倍数」，
 * 便于保留完整 SQL 等 trace meta。基线仅在每个 Worker 进程首次请求时从 ini 读取一次，避免跨请求重复翻倍。
 */

namespace Weline\Framework\Runtime;

use Weline\DeveloperWorkspace\Service\PanelAccessService;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

final class DevToolMemoryLimitBootstrap
{
    /** @var int|null 进程级基线（字节），首次 capture 后固定 */
    private static ?int $baselineBytes = null;

    /**
     * 在 App 每次 bootstrap 最前调用：在任意 ini_set 之前记录 PHP 配置的内存上限。
     */
    public static function captureProcessMemoryBaselineIfUnset(): void
    {
        if (self::$baselineBytes !== null) {
            return;
        }
        $raw = \ini_get('memory_limit');
        if ($raw === false || $raw === '') {
            self::$baselineBytes = 0;

            return;
        }
        $parsed = self::parseIniMemoryToBytes((string)$raw);
        self::$baselineBytes = $parsed < 0 ? 0 : $parsed;
    }

    public static function applyIfDevToolSessionActive(): void
    {
        if (!\class_exists(Env::class, false)) {
            return;
        }
        if (!self::isLikelyDevToolSession()) {
            return;
        }

        $multiplier = (float)Env::get('dev_tool.memory_limit_multiplier', 2.0);
        if ($multiplier <= 1.0) {
            return;
        }

        self::multiplyFromBaseline($multiplier);
    }

    private static function isLikelyDevToolSession(): bool
    {
        try {
            return ObjectManager::getInstance(PanelAccessService::class)->canAccessPanel();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function multiplyFromBaseline(float $multiplier): void
    {
        if (self::$baselineBytes === null) {
            self::captureProcessMemoryBaselineIfUnset();
        }
        $base = self::$baselineBytes ?? 0;
        if ($base <= 0) {
            return;
        }

        $newBytes = (int)\min((float)$base * $multiplier, (float)\PHP_INT_MAX);
        if ($newBytes <= $base) {
            return;
        }

        $formatted = self::formatBytesAsIniMemory($newBytes);
        if ($formatted !== '') {
            \ini_set('memory_limit', $formatted);
        }
    }

    /**
     * @internal 供单测解析 ini memory_limit 字符串
     */
    public static function parseIniMemoryToBytes(string $value): int
    {
        $value = \trim($value);
        if ($value === '' || \strcasecmp($value, '0') === 0) {
            return 0;
        }
        if ($value === '-1') {
            return -1;
        }

        if (!\preg_match('/^(?<num>\d+)(?<unit>[KMG])?$/i', $value, $m)) {
            return (int)$value;
        }

        $n = (int)$m['num'];
        $u = \strtoupper((string)($m['unit'] ?? ''));

        return match ($u) {
            'G' => $n * 1024 * 1024 * 1024,
            'M' => $n * 1024 * 1024,
            'K' => $n * 1024,
            default => $n,
        };
    }

    /**
     * @internal 供单测
     */
    public static function formatBytesAsIniMemory(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }

        if ($bytes % (1024 * 1024 * 1024) === 0) {
            return (string)($bytes / (1024 * 1024 * 1024)) . 'G';
        }
        if ($bytes % (1024 * 1024) === 0) {
            return (string)($bytes / (1024 * 1024)) . 'M';
        }
        if ($bytes % 1024 === 0) {
            return (string)($bytes / 1024) . 'K';
        }

        return (string)$bytes;
    }
}
