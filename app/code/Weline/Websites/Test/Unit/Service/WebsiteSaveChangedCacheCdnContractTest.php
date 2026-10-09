<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Website 保存走 w_changed：本地 namespace bump + WebsiteChangedType recipe CDN purge。
 */
final class WebsiteSaveChangedCacheCdnContractTest extends TestCase
{
    public function testWebsiteSavePublishesResourceChangeAndFlushDeferred(): void
    {
        $controller = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Admin/Website.php'
        );
        self::assertStringContainsString('w_changed($change)', $controller);
        self::assertStringContainsString('flushDeferred(', $controller);
        self::assertStringContainsString('publishWebsiteChange', $controller);
        self::assertStringContainsString("resourceType: 'website'", $controller);
    }

    public function testWebsiteChangedTypeRegisteredWithCdnPurgeRecipe(): void
    {
        $path = dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Changed/Type/WebsiteChangedType.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("return 'website'", $src);
        self::assertStringContainsString('CODE_BUMP_NAMESPACES', $src);
        self::assertStringContainsString('CODE_CDN_PURGE', $src);
        self::assertStringContainsString('allow_empty', $src);
        self::assertStringContainsString('CODE_PURGE_FPC_URLS', $src);
    }

    public function testCdnCapabilityRoutesWebsiteThroughPurgeWebsiteScope(): void
    {
        $cdnCapability = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Cdn/extends/module/Weline_Framework/Changed/Capability/CdnCapability.php'
        );
        self::assertStringContainsString("resourceType() === 'website'", $cdnCapability);
        self::assertStringContainsString('purgeWebsiteScope', $cdnCapability);

        $purger = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Cdn/Service/CachePurger.php'
        );
        self::assertStringContainsString('function purgeWebsiteScope', $purger);
        self::assertStringContainsString("'triggered' => true", $purger);
    }

    public function testWebsiteEditFormWarnsCacheAndCdnOnSave(): void
    {
        $form = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/Admin/Website/form.phtml'
        );
        self::assertStringContainsString('data-testid="website-save-cache-cdn-warning"', $form);
        self::assertStringContainsString('data-testid="website-save-cache-cdn-warning-near-save"', $form);
        self::assertStringContainsString('保存网站会清理本站本地缓存与 CDN', $form);
        self::assertStringContainsString('若未调整关键信息，不建议保存', $form);
        self::assertStringContainsString('保存会清理本站缓存与 CDN', $form);
        self::assertStringContainsString('data-website-save="1"', $form);
    }

    public function testWebsiteImpactCarriesNamespacesAndUrlsForCdn(): void
    {
        $factory = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/WebsiteChangeSnapshotFactory.php'
        );
        self::assertStringContainsString("'namespaces'", $factory);
        self::assertStringContainsString("'urls'", $factory);
        self::assertStringContainsString('websites-registry', $factory);
    }
}
