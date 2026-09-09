<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeProductQueryYieldContractTest extends TestCase
{
    public function testBareProductWithIdQueryIsTreatedAsModuleOwned(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Controller/Router.php',
        );

        self::assertStringContainsString('productQueryOwnsDetail', $source);
        self::assertStringContainsString("\$normalizedPath === 'product' && self::productQueryOwnsDetail()", $source);
        self::assertStringContainsString("Weline\\Product\\Controller\\Router::process", $source);
    }
}
