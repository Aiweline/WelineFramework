<?php

declare(strict_types=1);

namespace Weline\Faq\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class FaqPdpWidgetSeoContractTest extends TestCase
{
    public function testWidgetUsesResolverNotListForEntityProduct(): void
    {
        $widget = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/frontend/widgets/product-faq.phtml'
        );
        self::assertStringContainsString('FaqPdpResolveService', $widget);
        self::assertStringContainsString('resolveForPdp', $widget);
        self::assertStringNotContainsString("listForEntity('product'", $widget);
        self::assertStringContainsString('weline-product-faq__source', $widget);
        self::assertStringContainsString('wc-theme_widget_product_faq', $widget);
        self::assertStringContainsString('--weline-theme-', $widget);
        self::assertStringContainsString('--color-text', $widget);
    }

    public function testSeoProviderUsesSeoFaqsForPdp(): void
    {
        $seo = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Seo/SeoProfileProvider/ProductFaqSeoProfileProvider.php'
        );
        self::assertStringContainsString('seoFaqsForPdp', $seo);
        self::assertStringNotContainsString("seoFaqsForEntity('product'", $seo);

        $api = (string)file_get_contents(dirname(__DIR__, 3) . '/Api/FaqSeoFactsInterface.php');
        self::assertStringContainsString('seoFaqsForPdp', $api);
    }

    public function testBackendItemEditHasScopeAndFaqKey(): void
    {
        $edit = (string)file_get_contents(dirname(__DIR__, 3) . '/view/templates/Backend/Item/edit.phtml');
        self::assertStringContainsString('store_code', $edit);
        self::assertStringContainsString('channel_code', $edit);
        self::assertStringContainsString('faq_key', $edit);
        self::assertStringContainsString('抑制', $edit);

        $item = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/FaqItem.php');
        self::assertStringContainsString('idx_faq_item_scope_key_unique', $item);
        self::assertStringContainsString('UNIQUE', $item);
        self::assertStringContainsString('schema_fields_FAQ_KEY', $item);
    }
}
