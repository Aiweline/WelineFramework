<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Console\Cache\Clear;
use Weline\Framework\Cache\Scanner;
use Weline\Framework\Cache\Service\CacheWarmerRegistry;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;

/**
 * Mode switches must invalidate only: storefront warm belongs to the cron
 * warmer that reacts to build/generation changes.
 */
final class ClearSkipWarmTest extends TestCase
{
    public function testSkipWarmFlagInvalidatesWithoutStorefrontWarmup(): void
    {
        $command = new Clear(
            new ClearSkipWarmScanner(),
            new Printing(),
            new ClearSkipWarmBroadcaster(),
            new CacheWarmerRegistry([]),
        );

        \ob_start();
        try {
            $code = $command->execute([], ['skip_view_compile' => true, 'skip_warm' => true]);
        } finally {
            \ob_end_clean();
        }

        self::assertSame(0, $code);
    }

    public function testNoWarmCliFlagInvalidatesWithoutStorefrontWarmup(): void
    {
        $command = new Clear(
            new ClearSkipWarmScanner(),
            new Printing(),
            new ClearSkipWarmBroadcaster(),
            new CacheWarmerRegistry([]),
        );

        \ob_start();
        try {
            $code = $command->execute(['--no-warm', '--pools-only']);
        } finally {
            \ob_end_clean();
        }

        self::assertSame(0, $code);
    }
}

final class ClearSkipWarmScanner extends Scanner
{
    public function __construct()
    {
    }

    public function getCaches(): array
    {
        return ['pools' => [
            ['identity' => 'test_pool', 'permanent' => false],
        ]];
    }

    public function clearPool(string $identity): bool
    {
        return true;
    }
}

final class ClearSkipWarmBroadcaster implements RuntimeControlBroadcasterInterface
{
    public function cacheClear(?string $instanceName = null): array
    {
        return ['success' => true];
    }

    public function cacheClearAndWait(?string $instanceName = null, float $timeout = 5.0): array
    {
        return ['success' => true, 'completed' => true, 'message' => 'cleared'];
    }

    public function reloadAsync(?string $instanceName = null, string $reloadType = 'code', float $timeout = 5.0): array
    {
        return ['success' => true];
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
