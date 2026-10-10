<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class YouMayLikeWidgetContractTest extends TestCase
{
    public function testRegistrationPinsProductOwnedDefaultInjectionAndListingCard(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Product/widget.php';
        $tpl = 'Weline_Product::templates/frontend/widgets/you-may-like.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/you-may-like.phtml');
        self::assertStringContainsString('@widget.code {you-may-like}', $src);
        self::assertStringContainsString('@widget.placement {layout}', $src);
        self::assertStringContainsString('"slot":"design-product-you-may-like"', $src);
        self::assertStringContainsString('"placement":"injection"', $src);
        self::assertStringContainsString('"required":true', $src);
    }
}
