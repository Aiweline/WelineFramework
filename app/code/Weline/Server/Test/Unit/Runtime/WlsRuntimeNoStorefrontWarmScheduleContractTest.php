<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * Negative contract: WLS must not schedule storefront HTML FPC warm
 * (orchestration lives in Framework Cache Cron).
 */
final class WlsRuntimeNoStorefrontWarmScheduleContractTest extends TestCase
{
    public function testNeedsDeferredStorefrontWarmupIsHardDisabled(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Runtime/WlsRuntime.php'
        );
        self::assertMatchesRegularExpression(
            '/function needsDeferredStorefrontWarmup\(\): bool\s*\{[^}]*return false;/s',
            $source,
        );
        self::assertStringNotContainsString(
            'homepageNeedsDeferredPrime',
            $source,
        );
    }

    public function testHomepageKeepWarmIsHardDisabled(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Runtime/WlsRuntime.php'
        );
        self::assertMatchesRegularExpression(
            '/function homepageKeepWarmEnabled\(\): bool\s*\{[^}]*return false;/s',
            $source,
        );
    }

    public function testBuildAheadProcessPullAndDynamicDeferredAreHardDisabled(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Runtime/WlsRuntime.php'
        );
        foreach ([
            'shouldRunDeferredFpcBuildAheadWarmup',
            'shouldRunDeferredFpcProcessPullWarmup',
            'shouldRunDeferredDynamicFirstRenderWarmup',
        ] as $method) {
            self::assertMatchesRegularExpression(
                '/function ' . $method . '\(\): bool\s*\{[^}]*return false;/s',
                $source,
                $method,
            );
        }
    }

    public function testServerHasNoParallelStorefrontWarmupOrchestrator(): void
    {
        $cronDir = \dirname(__DIR__, 3) . '/Cron';
        $files = \is_dir($cronDir) ? \glob($cronDir . '/*.php') : [];
        self::assertIsArray($files);
        foreach ($files as $file) {
            $base = \basename((string)$file);
            self::assertStringNotContainsString('StorefrontWarmup', $base);
            $src = (string)\file_get_contents((string)$file);
            self::assertStringNotContainsString('StorefrontWarmupOrchestrator', $src);
        }
    }
}
