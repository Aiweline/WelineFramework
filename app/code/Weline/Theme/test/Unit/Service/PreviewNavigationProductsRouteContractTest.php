<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: theme editor must preserve clicked storefront path; only add editor params.
 * Hard-fails if path is rewritten via layout_path / product_list / theme-preview/content.
 */
final class PreviewNavigationProductsRouteContractTest extends TestCase
{
    public function testResolverDoesNotRewriteClickedPathFromLayoutPath(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/PreviewNavigationResolver.php'
        );

        self::assertStringContainsString('preserve_path', $source);
        self::assertStringContainsString("\$response['public_route']", $source);
        // Must NOT rebuild public_route from layout_path + entity_slug.
        self::assertStringNotContainsString(
            '$publicRoute = $layoutPath . \'/\' . $entitySlug',
            $source
        );
        self::assertStringNotContainsString(
            '$publicRoute = $layoutPath;',
            $source
        );
    }

    public function testPreviewRouteTablesAreDeleted(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ThemePageTypeResolver.php'
        );

        self::assertStringNotContainsString('PREVIEW_ROUTE_BY_PAGE_TYPE', $source);
        self::assertStringNotContainsString('PREVIEW_ROUTE_BY_LAYOUT_TYPE', $source);
        self::assertStringNotContainsString('theme/frontend/theme-preview/content', $source);
        self::assertStringContainsString('Path ↔ layout 1:1', $source);
    }

    public function testFrontendEditorJsHasNoContentShellHardcode(): void
    {
        $js = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor.js'
        );
        $visual = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/visual-editor.js'
        );
        $template = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/backend/ThemeEditor/index.phtml'
        );

        self::assertStringNotContainsString(
            "apiFrontendLayoutPreview || '/theme/frontend/theme-preview/content'",
            $js
        );
        self::assertStringContainsString('apiThemePreviewGateway', $js);
        self::assertStringNotContainsString('/product/view/id/1.html', $visual);
        self::assertStringNotContainsString('/checkout/cart', $visual);
        self::assertStringContainsString('path=layout 1:1', $visual);
        self::assertStringContainsString('no Magento-style alias table', $visual);
        self::assertStringContainsString('theme-preview/gateway', $template);
        self::assertStringNotContainsString(
            "data-api-frontend-layout-preview=",
            $template
        );
    }

    public function testEditorJsPreservesClickedPathAndOnlyAddsEditorParams(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor.js'
        );

        self::assertStringContainsString('buildCanvasUrlPreservingStorefrontPath', $source);
        self::assertStringContainsString('applyResolvedEditorCanvasNavigation', $source);
        self::assertStringContainsString('Preserve-path canvas navigation', $source);
        self::assertStringContainsString('Preserve clicked/navigation path exactly', $source);
        self::assertStringContainsString('Wait for preview-sample', $source);
        self::assertStringContainsString('isHomepageLayoutType', $source);
        // Must not invent path from layout type when previewEntityRoute is empty.
        self::assertStringNotContainsString(
            '// Layout-dropdown fallback only: path equals layout type (no alias table).',
            $source
        );
        // Must not hard-remap product_list → products inside resolveCanvasStorefrontPath.
        self::assertDoesNotMatchRegularExpression(
            '/function resolveCanvasStorefrontPath[\s\S]*?product_list[\s\S]*?return \'products\'/',
            $source
        );
        self::assertStringNotContainsString("pageType = 'products'", $source);
        self::assertStringNotContainsString("product_list' ? 'products'", $source);
        self::assertStringContainsString("url.searchParams.delete('page_type')", $source);
        self::assertStringContainsString("url.searchParams.delete('layout_type')", $source);
    }
}
