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

        $this->runCommand($broadcaster, $events);

        self::assertSame(['cache-clear-wait', 'storefront-warm'], $events);
    }

    public function testStorefrontWarmupIsSkippedWhenWorkerCacheClearDoesNotComplete(): void
    {
        $events = [];
        $broadcaster = new CacheClearRuntimeBroadcasterFake(
            $events,
            ['success' => true, 'completed' => false, 'message' => 'queued'],
        );

        $this->runCommand($broadcaster, $events);

        self::assertSame(['cache-clear-wait'], $events);
    }

    /** @param list<string> $events */
    private function runCommand(CacheClearRuntimeBroadcasterFake $broadcaster, array &$events): void
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
            \ob_end_clean();
        }
    }
}

final class CacheClearEmptyScanner extends Scanner
{
    public function __construct()
    {
    }

    public function getCaches(): array
    {
        return [];
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
