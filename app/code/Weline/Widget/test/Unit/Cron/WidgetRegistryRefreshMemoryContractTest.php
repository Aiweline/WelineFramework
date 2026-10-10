<?php

declare(strict_types=1);

namespace Weline\Widget\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;

final class WidgetRegistryRefreshMemoryContractTest extends TestCase
{
    public function testCronRaisesMemoryCeilingForSolidifyCompile(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Cron/WidgetRegistryRefresh.php',
        );

        self::assertStringContainsString("ini_set('memory_limit', '512M')", $source);
        self::assertStringContainsString('finally', $source);
    }
}
