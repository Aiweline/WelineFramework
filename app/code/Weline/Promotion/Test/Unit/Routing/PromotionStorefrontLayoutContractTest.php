<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Routing;

use PHPUnit\Framework\TestCase;

final class PromotionStorefrontLayoutContractTest extends TestCase
{
    public function testPromotionControllerUsesItsDedicatedThemeLayout(): void
    {
        $path = \dirname(__DIR__, 3) . '/Controller/Index.php';
        self::assertFileExists($path);
        $source = (string)\file_get_contents($path);

        self::assertStringContainsString("protected ?string \$layoutType = 'promotion.default';", $source);
        self::assertStringNotContainsString("protected ?string \$layoutType = 'default.default';", $source);
        self::assertStringContainsString("setGet('theme_public_route', 'promotion')", $source);
    }
}
