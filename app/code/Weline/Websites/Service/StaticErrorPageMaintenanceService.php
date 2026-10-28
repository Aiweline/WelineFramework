<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

/**
 * 404 与维护静态页的增量发布（由 {@see \Weline\Websites\Cron\WebsitesOperationsMaintenance} 调用）。
 *
 * 一次发布是 website×locale 全量重写，成本不低，因此只在「距上次成功发布 ≥ 刷新周期」时放行，
 * 默认 7 天，可用 `websites.static_error_pages_refresh_days` 覆盖。状态落 var/state。
 */
final class StaticErrorPageMaintenanceService
{
    private const STATE_FILENAME = 'static_error_pages.json';
    private const DEFAULT_REFRESH_DAYS = 7;

    /**
     * kind => 生成器类（模块缺失时按 missing 跳过，不影响其它块）。
     *
     * @var array<string, class-string>
     */
    private const GENERATORS = [
        'not_found' => \Weline\Theme\Service\StorefrontNotFoundStaticGenerator::class,
        'maintenance' => \Weline\Maintenance\Service\MaintenanceStaticGenerator::class,
    ];

    public function refreshDays(): int
    {
        try {
            $days = (int)Env::getInstance()->getConfig('websites.static_error_pages_refresh_days', self::DEFAULT_REFRESH_DAYS);
        } catch (\Throwable) {
            return self::DEFAULT_REFRESH_DAYS;
        }

        return $days > 0 ? $days : self::DEFAULT_REFRESH_DAYS;
    }

    public function statePath(): string
    {
        return \rtrim(VAR_DIR, '/\\') . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR . self::STATE_FILENAME;
    }

    /**
     * 是否到了刷新时间（只读状态文件，不做任何渲染）。
     */
    public function isDue(?int $now = null): bool
    {
        $now ??= \time();
        $state = $this->readState();
        if ($state === null) {
            return true;
        }
        $lastAt = (int)($state['at'] ?? 0);
        if ($lastAt <= 0) {
            return true;
        }

        return ($now - $lastAt) >= $this->refreshDays() * 86400;
    }

    /**
     * 发布 404 与维护静态页；全部成功才推进刷新时间（失败下一轮继续重试）。
     *
     * @return array{not_found: int, maintenance: int, written: int, failed: int, messages: list<string>}
     */
    public function publish(?int $now = null): array
    {
        $now ??= \time();
        $report = [
            'not_found' => 0,
            'maintenance' => 0,
            'written' => 0,
            'failed' => 0,
            'messages' => [],
        ];

        foreach (self::GENERATORS as $kind => $class) {
            if (!\class_exists($class)) {
                $report['messages'][] = $kind . ':module_missing';
                continue;
            }
            try {
                /** @var object{publishAll: callable} $generator */
                $generator = ObjectManager::getInstance($class);
                // 两个生成器的 publishAll() 返回的是「已发布标签列表」(site/lang)，不是
                // StaticErrorPagePublisher 的 written/failed 结构；按元素个数计成功数。
                // 失败明细由生成器自身日志承担，这里只统计产出量，避免误报 failed=0 之外的信息。
                $labels = $generator->publishAll();
                $published = \is_array($labels) ? \count($labels) : 0;
                $report[$kind] = $published;
                $report['written'] += $published;
            } catch (\Throwable $throwable) {
                $report['messages'][] = $kind . ':' . $throwable->getMessage();
            }
        }

        if ($report['failed'] === 0 && $report['messages'] === []) {
            $this->writeState($now, $report);
        }

        return $report;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readState(): ?array
    {
        $path = $this->statePath();
        if (!\is_file($path)) {
            return null;
        }
        $raw = @\file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = \json_decode($raw, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array{written: int, failed: int} $report
     */
    private function writeState(int $now, array $report): void
    {
        $path = $this->statePath();
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return;
        }
        @\file_put_contents($path, (string)\json_encode([
            'at' => $now,
            'written' => (int)$report['written'],
            'failed' => (int)$report['failed'],
            'refresh_days' => $this->refreshDays(),
        ], JSON_UNESCAPED_UNICODE));
    }
}
