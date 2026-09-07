<?php

declare(strict_types=1);

namespace Weline\WarmCache\Test\Unit\Console;

use PHPUnit\Framework\TestCase;

final class CacheWarmCommandRegistryContractTest extends TestCase
{
    private const COMMAND = __DIR__ . '/../../../Console/Cache/Warm.php';

    public function testPoolAndListOptionsUseTheCentralWarmerRegistry(): void
    {
        $source = file_get_contents(self::COMMAND);

        self::assertIsString($source);
        self::assertStringContainsString('CacheWarmerRegistry', $source);
        self::assertStringContainsString('warmUp($pool', $source);
        self::assertStringContainsString("isset(\$data['pool'])", $source);
        self::assertStringContainsString("\$args['pool']", $source);
        self::assertStringContainsString("isset(\$data['list'])", $source);
    }

    public function testStorefrontFpcWarmerIsRegisteredForCentralWarmup(): void
    {
        $source = file_get_contents(self::COMMAND);

        self::assertIsString($source);
        self::assertStringContainsString('theme.storefront_fpc', $source);
        self::assertStringContainsString('StorefrontFpcWarmer', $source);
    }
}
