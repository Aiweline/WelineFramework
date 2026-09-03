<?php

declare(strict_types=1);

namespace Weline\Filters\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CategoryFiltersWidgetContractTest extends TestCase
{
    public function testWidgetDeclaresDefaultInjectionsIntoCategoryFiltersSlot(): void
    {
        $widget = require dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Filters/widget.php';
        $cfg = $widget['category-filters'] ?? [];

        self::assertSame('category-filters', $cfg['code'] ?? null);
        self::assertSame('Weline_Filters::templates/frontend/widgets/category-filters.phtml', $cfg['template'] ?? null);
        $injections = $cfg['default_injections'] ?? [];
        self::assertNotEmpty($injections);
        $slots = array_column($injections, 'slot');
        self::assertContains('category-filters', $slots);
        self::assertTrue((bool)($injections[0]['required'] ?? false));
    }

    public function testWidgetTemplateExposesAttributeAndPriceContracts(): void
    {
        $template = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/category-filters.phtml',
        );

        self::assertStringContainsString('storefront-filters-panel', $template);
        self::assertStringContainsString('storefront-filters-price', $template);
        self::assertStringContainsString('storefront-filters-attribute', $template);
        self::assertStringContainsString('StorefrontFilterPanelService', $template);
        self::assertStringContainsString('resolveListingOffers', $template);
        self::assertStringContainsString('wc-theme_widget_category_filters', $template);
        self::assertStringContainsString('@widget.default_injections', $template);
        self::assertStringContainsString('storefront-filters-dept-current', $template);
        self::assertStringContainsString('data-depth', $template);
        self::assertStringContainsString('w-filters__dept', $template);
    }

    public function testPanelServiceExpandsDepartmentNavAlongActivePath(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontFilterPanelService.php',
        );
        self::assertStringContainsString('function buildDepartmentNav(', $src);
        self::assertStringContainsString('activePathIds', $src);
        self::assertStringContainsString('storefront-filters-dept-current', (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/category-filters.phtml',
        ));
    }
}
