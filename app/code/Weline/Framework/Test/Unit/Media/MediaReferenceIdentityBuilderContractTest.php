<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Media;

use PHPUnit\Framework\TestCase;

final class MediaReferenceIdentityBuilderContractTest extends TestCase
{
    public function testWScopeResolvesFrameworkBuilderInterface(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Common/functions.php'
        );
        self::assertStringContainsString('MediaReferenceIdentityBuilderInterface', $src);
        self::assertStringNotContainsString(
            'FileManager\\Service\\MediaReference\\MediaReferenceIdentityBuilder::class',
            $src
        );
    }

    public function testTemplatePublishesFrameworkSeoBag(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/View/Template.php'
        );
        self::assertStringContainsString('Framework\\Http\\Seo\\SeoPageProfileBag', $src);
        self::assertStringNotContainsString('Seo\\Service\\Head\\SeoPageProfileBag', $src);
    }

    public function testMemDiagUsesProcessCacheResetterRegistry(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Runtime/MemDiag.php'
        );
        self::assertStringContainsString('ModuleProcessCacheResetterRegistry', $src);
        self::assertStringContainsString('diagCounts', $src);
        self::assertStringNotContainsString('Theme\\Api\\Runtime\\ProcessCacheResetter', $src);
        self::assertStringNotContainsString('Product\\Service\\ProductSearchProjectionService', $src);
        self::assertStringNotContainsString('Product\\Service\\StorefrontProductDetailProjector', $src);
        self::assertStringNotContainsString('Product\\Service\\StorefrontProductMediaUrlResolver', $src);
        self::assertStringNotContainsString('Product\\Service\\StorefrontEavLabelResolver', $src);
        self::assertStringNotContainsString('Theme\\Block\\Partials', $src);
        self::assertStringNotContainsString('Theme\\Service\\RuntimeTemplateMaterializer', $src);
        self::assertStringNotContainsString('Theme\\Helper\\ThemeData', $src);
    }

    public function testUrlMatrixExpanderUsesWebsitesQueryOnly(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Event/Changed/UrlMatrixExpander.php'
        );
        self::assertStringContainsString("w_query('websites', 'getWebsiteLanguageCodes'", $src);
        self::assertStringNotContainsString('Websites\\Model\\WebsiteLanguage', $src);
    }

    public function testResourceChangeFactoryResolvesMediaScopeInterface(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Event/ResourceChange/ResourceChangeFactory.php'
        );
        self::assertStringContainsString('MediaReferenceScopeResolverInterface', $src);
        self::assertStringNotContainsString(
            'FileManager\\Service\\MediaReference\\MediaReferenceScopeResolver::class',
            $src
        );
    }

    public function testHtmlCacheAdmissionHasNoProductSoftPull(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/View/Helper/HtmlCacheAdmission.php'
        );
        self::assertStringContainsString('data-weline-product-card-css', $src);
        self::assertStringNotContainsString('Product\\Service\\ProductCardRenderer', $src);
    }

    public function testErrorPageRendererHasNoWidgetI18nSoftPull(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Http/ErrorPageRenderer.php'
        );
        self::assertStringContainsString('function_exists(\'__\')', $src);
        self::assertStringNotContainsString('Theme\\Helper\\WidgetI18n', $src);
    }

    public function testHotCachePagePrefetchHasNoWebsitesOrThemeFqcnSoftPull(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Cache/Service/StorefrontHotCachePagePrefetch.php'
        );
        self::assertStringContainsString('websites.sales_channel_catalog', $src);
        self::assertStringContainsString('StorefrontPagePrefetchContributionRegistry', $src);
        self::assertStringNotContainsString('Websites\\Service\\StorefrontScopeCatalogCacheCoordinator', $src);
        self::assertStringNotContainsString('Theme\\Service\\Storefront\\ThemePathResolvePagePrefetch', $src);
    }

    public function testDevToolMemoryLimitUsesDeveloperAccessProvider(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Runtime/DevToolMemoryLimitBootstrap.php'
        );
        self::assertStringContainsString('DeveloperAccessProviderInterface', $src);
        self::assertStringContainsString('hasActivePanelSession', $src);
        self::assertStringNotContainsString('DeveloperWorkspace\\Service\\PanelAccessService', $src);
    }
}
