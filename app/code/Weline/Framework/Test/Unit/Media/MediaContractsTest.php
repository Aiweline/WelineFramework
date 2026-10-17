<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Media;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllMediaContractsPass().
 */
final class MediaContractsTest extends TestCase
{
    public function testAllMediaContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'MediaReferenceIdentityBuilder::testWScopeResolvesFrameworkBuilderInterface' => function () {
            $this->assert_MediaReferenceIdentityBuilder_testWScopeResolvesFrameworkBuilderInterface();
        },
            'MediaReferenceIdentityBuilder::testTemplatePublishesFrameworkSeoBag' => function () {
            $this->assert_MediaReferenceIdentityBuilder_testTemplatePublishesFrameworkSeoBag();
        },
            'MediaReferenceIdentityBuilder::testMemDiagUsesProcessCacheResetterRegistry' => function () {
            $this->assert_MediaReferenceIdentityBuilder_testMemDiagUsesProcessCacheResetterRegistry();
        },
            'MediaReferenceIdentityBuilder::testUrlMatrixExpanderUsesWebsitesQueryOnly' => function () {
            $this->assert_MediaReferenceIdentityBuilder_testUrlMatrixExpanderUsesWebsitesQueryOnly();
        },
            'MediaReferenceIdentityBuilder::testResourceChangeFactoryResolvesMediaScopeInterface' => function () {
            $this->assert_MediaReferenceIdentityBuilder_testResourceChangeFactoryResolvesMediaScopeInterface();
        },
            'MediaReferenceIdentityBuilder::testHtmlCacheAdmissionHasNoProductSoftPull' => function () {
            $this->assert_MediaReferenceIdentityBuilder_testHtmlCacheAdmissionHasNoProductSoftPull();
        },
            'MediaReferenceIdentityBuilder::testErrorPageRendererHasNoWidgetI18nSoftPull' => function () {
            $this->assert_MediaReferenceIdentityBuilder_testErrorPageRendererHasNoWidgetI18nSoftPull();
        },
            'MediaReferenceIdentityBuilder::testHotCachePagePrefetchHasNoWebsitesOrThemeFqcnSoftPull' => function () {
            $this->assert_MediaReferenceIdentityBuilder_testHotCachePagePrefetchHasNoWebsitesOrThemeFqcnSoftPull();
        },
            'MediaReferenceIdentityBuilder::testDevToolMemoryLimitUsesDeveloperAccessProvider' => function () {
            $this->assert_MediaReferenceIdentityBuilder_testDevToolMemoryLimitUsesDeveloperAccessProvider();
        },
        ];
    }

private function assert_MediaReferenceIdentityBuilder_testWScopeResolvesFrameworkBuilderInterface(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Common/functions.php');
    self::assertStringContainsString('MediaReferenceIdentityBuilderInterface', $src);
    self::assertStringNotContainsString('FileManager\Service\MediaReference\MediaReferenceIdentityBuilder::class', $src);
}

private function assert_MediaReferenceIdentityBuilder_testTemplatePublishesFrameworkSeoBag(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/Template.php');
    self::assertStringContainsString('Framework\Http\Seo\SeoPageProfileBag', $src);
    self::assertStringNotContainsString('Seo\Service\Head\SeoPageProfileBag', $src);
}

private function assert_MediaReferenceIdentityBuilder_testMemDiagUsesProcessCacheResetterRegistry(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Runtime/MemDiag.php');
    self::assertStringContainsString('ModuleProcessCacheResetterRegistry', $src);
    self::assertStringContainsString('diagCounts', $src);
    self::assertStringNotContainsString('Theme\Api\Runtime\ProcessCacheResetter', $src);
    self::assertStringNotContainsString('Product\Service\ProductSearchProjectionService', $src);
    self::assertStringNotContainsString('Product\Service\StorefrontProductDetailProjector', $src);
    self::assertStringNotContainsString('Product\Service\StorefrontProductMediaUrlResolver', $src);
    self::assertStringNotContainsString('Product\Service\StorefrontEavLabelResolver', $src);
    self::assertStringNotContainsString('Theme\Block\Partials', $src);
    self::assertStringNotContainsString('Theme\Service\RuntimeTemplateMaterializer', $src);
    self::assertStringNotContainsString('Theme\Helper\ThemeData', $src);
}

private function assert_MediaReferenceIdentityBuilder_testUrlMatrixExpanderUsesWebsitesQueryOnly(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Event/Changed/UrlMatrixExpander.php');
    self::assertStringContainsString("w_query('websites', 'getWebsiteLanguageCodes'", $src);
    self::assertStringContainsString("w_query('websites', 'getWebsiteById'", $src);
    self::assertStringNotContainsString('Websites\Model\WebsiteLanguage', $src);
    self::assertStringNotContainsString('_wl=', $src);
    self::assertStringContainsString('withPathVariant', $src);
}

private function assert_MediaReferenceIdentityBuilder_testResourceChangeFactoryResolvesMediaScopeInterface(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Event/ResourceChange/ResourceChangeFactory.php');
    self::assertStringContainsString('MediaReferenceScopeResolverInterface', $src);
    self::assertStringNotContainsString('FileManager\Service\MediaReference\MediaReferenceScopeResolver::class', $src);
}

private function assert_MediaReferenceIdentityBuilder_testHtmlCacheAdmissionHasNoProductSoftPull(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/Helper/HtmlCacheAdmission.php');
    self::assertStringContainsString('data-weline-product-card-css', $src);
    self::assertStringNotContainsString('Product\Service\ProductCardRenderer', $src);
}

private function assert_MediaReferenceIdentityBuilder_testErrorPageRendererHasNoWidgetI18nSoftPull(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Http/ErrorPageRenderer.php');
    self::assertStringContainsString('function_exists(\'__\')', $src);
    self::assertStringNotContainsString('Theme\Helper\WidgetI18n', $src);
}

private function assert_MediaReferenceIdentityBuilder_testHotCachePagePrefetchHasNoWebsitesOrThemeFqcnSoftPull(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Cache/Service/StorefrontHotCachePagePrefetch.php');
    self::assertStringContainsString('websites.sales_channel_catalog', $src);
    self::assertStringContainsString('StorefrontPagePrefetchContributionRegistry', $src);
    self::assertStringNotContainsString('Websites\Service\StorefrontScopeCatalogCacheCoordinator', $src);
    self::assertStringNotContainsString('Theme\Service\Storefront\ThemePathResolvePagePrefetch', $src);
}

private function assert_MediaReferenceIdentityBuilder_testDevToolMemoryLimitUsesDeveloperAccessProvider(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Runtime/DevToolMemoryLimitBootstrap.php');
    self::assertStringContainsString('DeveloperAccessProviderInterface', $src);
    self::assertStringContainsString('hasActivePanelSession', $src);
    self::assertStringNotContainsString('DeveloperWorkspace\Service\PanelAccessService', $src);
}
}
