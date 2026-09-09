<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Product pages emit ProductGroup + hasVariant; the panel contract must not
 * require a bare Product + group-level offers only.
 */
final class InspectorProductGroupJsonLdContractTest extends TestCase
{
    public function testPanelRulesAcceptProductGroupAndOfferOrVariant(): void
    {
        $inspector = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/seo-inspector/inspector.js'
        );

        self::assertStringContainsString('Product: ["Product", "ProductGroup"]', $inspector);
        self::assertStringContainsString('requiredFields: ["name", "image", "offers|hasVariant"]', $inspector);
        self::assertStringContainsString('"offers.price|offers.lowPrice"', $inspector);
        self::assertStringContainsString('if (node.review) pushNode(node.review)', $inspector);
        self::assertStringContainsString('已对接评论评分', $inspector);
        self::assertStringContainsString('optional: ["Review"]', $inspector);
        self::assertStringContainsString('toolUrlPageKey', $inspector);
        self::assertStringContainsString('currentToolUrlPageKey', $inspector);
        self::assertStringContainsString('weline-seo-panel__url-mismatch', $inspector);
        self::assertStringContainsString('检测 URL 与地址栏当前页不一致', $inspector);
        self::assertStringNotContainsString(
            "requiredTypes: [\"Product\", \"BreadcrumbList\"]",
            $inspector
        );
    }
}
