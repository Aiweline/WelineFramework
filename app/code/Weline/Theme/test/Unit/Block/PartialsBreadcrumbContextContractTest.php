<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Block;

use PHPUnit\Framework\TestCase;

/** breadcrumb partial must inherit page trail from Template assigns / seo bag. */
final class PartialsBreadcrumbContextContractTest extends TestCase
{
    public function testRenderPartialsInjectsBreadcrumbTrailFromTemplate(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Block/Partials.php');
        self::assertStringContainsString("strtolower(\$type) === 'breadcrumb'", $src);
        self::assertStringContainsString("storefront_product_breadcrumbs", $src);
        self::assertStringContainsString("storefront_category_breadcrumbs", $src);
        self::assertStringContainsString("\$seoBag['breadcrumbs']", $src);
        self::assertStringContainsString("\$data['breadcrumbs'] = \$seoBag['breadcrumbs']", $src);
    }
}
