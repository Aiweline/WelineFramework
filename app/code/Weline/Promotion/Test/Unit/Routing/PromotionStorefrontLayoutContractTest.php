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
        self::assertStringContainsString("theme_public_route", $source);
        self::assertStringContainsString("'promotion/'", $source);
        $layout = \dirname(__DIR__, 3) . '/view/theme/frontend/layouts/promotion/default.phtml';
        self::assertFileExists($layout);
        self::assertFileDoesNotExist(
            \dirname(__DIR__, 4) . '/Theme/view/theme/frontend/layouts/promotion/default.phtml'
        );
        $source = (string)\file_get_contents($layout);
        self::assertStringContainsString('<w:slot id="content"', $source);
        self::assertStringContainsString('<w:slot id="promotion-bottom"', $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::promotion::content-after', $source);
    }
}
