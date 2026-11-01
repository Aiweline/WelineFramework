<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Console\Cache;

if (!\function_exists(__NAMESPACE__ . '\\__')) {
    function __(string $text, array $params = []): string
    {
        foreach ($params as $index => $value) {
            $text = \str_replace('%{' . ($index + 1) . '}', (string)$value, $text);
        }

        return $text;
    }
}

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Console\Cache\Clear;
use Weline\Framework\Cache\Contract\CacheWarmerInterface;
use Weline\Framework\Cache\Scanner;
use Weline\Framework\Cache\Service\CacheWarmerRegistry;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;

final class ConsoleCacheClearRuntimeSyncTest extends TestCase
{
    public function testStorefrontWarmupRunsOnlyAfterWorkersConfirmCacheClear(): void
    {
        $events = [];
        $broadcaster = new CacheClearRuntimeBroadcasterFake(
            $events,
            ['success' => true, 'completed' => true, 'message' => 'cleared'],
        );

        $output = $this->runCommand($broadcaster, $events);

        // 确认清理完成 → 允许进入预热；未完成 → 打印跳过提示（见下一个用例）。
        // FPC 预热编排已迁到 StorefrontWarmupOrchestrator，不再经 CacheWarmerRegistry 的
        // 'storefront' 池，因此这里用命令自身的成功标记断言，而不是 warmer fake 的事件。
        self::assertSame(['cache-clear-wait'], $events);
        self::assertStringContainsString('WLS 缓存清理已完成', $output);
        self::assertStringNotContainsString('WLS 缓存清理未完成', $output);
    }

    public function testStorefrontWarmupIsSkippedWhenWorkerCacheClearDoesNotComplete(): void
    {
        $events = [];
        $broadcaster = new CacheClearRuntimeBroadcasterFake(
            $events,
            ['success' => true, 'completed' => false, 'message' => 'queued'],
        );

        $output = $this->runCommand($broadcaster, $events);

        self::assertSame(['cache-clear-wait'], $events);
        self::assertStringContainsString('WLS 缓存清理未完成', $output);
        self::assertStringNotContainsString('WLS 缓存清理已完成', $output);
    }

    /** @param list<string> $events */
    private function runCommand(CacheClearRuntimeBroadcasterFake $broadcaster, array &$events): string
    {
        $registry = new CacheWarmerRegistry([
            new CacheClearStorefrontWarmerFake($events),
        ]);

        $command = new Clear(
            new CacheClearEmptyScanner(),
            new Printing(),
            $broadcaster,
            $registry,
        );

        \ob_start();
        try {
            $command->execute();
        } finally {
            $output = (string)\ob_get_clean();
        }

        return $output;
    }
}

final class CacheClearEmptyScanner extends Scanner
{
    public function __construct()
    {
    }

    public function getCaches(): array
    {
        // 必须至少有一个池：Clear::execute() 在 totalPools === 0 时会直接早返回，
        // 根本走不到「向 WLS 确认清理」这一步。
        return ['pools' => [['identity' => 'test.pool', 'permanent' => false]]];
    }

    public function clearPool(string $identity): bool
    {
        return true;
    }
}

final class CacheClearRuntimeBroadcasterFake implements RuntimeControlBroadcasterInterface
{
    /**
     * @param list<string> $events
     * @param array<string, mixed> $waitResult
     */
    public function __construct(
        private array &$events,
        private readonly array $waitResult,
    ) {
    }

    public function cacheClear(?string $instanceName = null): array
    {
        $this->events[] = 'cache-clear-async';

        return ['success' => true, 'message' => 'queued'];
    }

    public function cacheClearAndWait(?string $instanceName = null, float $timeout = 5.0): array
    {
        $this->events[] = 'cache-clear-wait';

        return $this->waitResult;
    }

    public function reloadAsync(?string $instanceName = null, string $reloadType = 'code', float $timeout = 5.0): array
    {
        $this->events[] = 'reload-async';

        return ['success' => true, 'message' => 'queued'];
    }

    public function maintenanceMode(): ?bool
    {
        return false;
    }

    public function setMaintenanceMode(bool $enabled): array
    {
        return ['success' => true];
    }
}

final class CacheClearStorefrontWarmerFake implements CacheWarmerInterface
{
    /** @param list<string> $events */
    public function __construct(private array &$events)
    {
    }

    public function getName(): string
    {
        return 'theme.storefront_fpc';
    }

    public function getTargetPool(): string
    {
        return 'fpc';
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function canWarm(): bool
    {
        return true;
    }

    public function warm(): array
    {
        $this->events[] = 'storefront-warm';

        return ['warmed' => 1, 'skipped' => 0];
    }
}
