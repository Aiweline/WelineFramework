<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class PromotionThemeProductBindingTest extends TestCase
{
    public function testProductBindingModelStoresWebsiteId(): void
    {
        $model = dirname(__DIR__, 3) . '/Model/PromotionActivityThemeProduct.php';
        $service = dirname(__DIR__, 3) . '/Service/PromotionThemeProductService.php';
        $picker = dirname(__DIR__, 3) . '/../Product/view/statics/js/backend/product-admin-picker.js';

        self::assertStringContainsString('schema_fields_WEBSITE_ID', (string)file_get_contents($model));
        self::assertStringContainsString('normalizeProductBindings', (string)file_get_contents($service));
        self::assertStringContainsString('一站一活动', (string)file_get_contents($service));
        self::assertStringContainsString('product_website_ids[]', (string)file_get_contents($picker));
    }
}
