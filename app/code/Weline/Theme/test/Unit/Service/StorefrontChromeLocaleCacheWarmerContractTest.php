<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\StorefrontChromeLocaleCacheWarmer;
use Weline\Theme\Service\StorefrontThemeCacheCoordinator;

/** Guard: Clear warmUp chrome pool + Theme provides locale chrome warmer (L2-honest). */
final class StorefrontChromeLocaleCacheWarmerContractTest extends TestCase
{
    public function testModuleProvidesChromeLocaleWarmer(): void
    {
        $module = require dirname(__DIR__, 3) . '/etc/module.php';
        $provides = $module['provides'] ?? [];
        self::assertSame(
            StorefrontChromeLocaleCacheWarmer::class,
            $provides['cache.warmer.theme.storefront_chrome_locales'] ?? null,
        );
        self::assertSame('2.2.974', (string)($module['version'] ?? ''));
    }

    public function testWarmerTargetsStorefrontChromePool(): void
    {
        $warmer = new StorefrontChromeLocaleCacheWarmer();
        self::assertSame(
            StorefrontThemeCacheCoordinator::STOREFRONT_CHROME_POOL,
            $warmer->getTargetPool(),
        );
        self::assertSame('theme.storefront_chrome_locales', $warmer->getName());
    }

    public function testClearWarmsChromePoolWithoutThemeFqcn(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Framework/Cache/Console/Cache/Clear.php'
        );
        // Subprocess reseed: in-process warmUp shared Clear RequestContext and
        // produced sticky false HIT; Clear must shell cache:warm --pool=…
        self::assertStringContainsString(
            'cache:warm --pool=weline_theme_storefront_chrome',
            $src,
        );
        self::assertStringNotContainsString(
            "warmUp('weline_theme_storefront_chrome')",
            $src,
        );
        self::assertStringNotContainsString('StorefrontChromeLocaleCacheWarmer', $src);
    }

    public function testWarmerUsesWebsiteEnabledLocalesNotHardcodedCatalog(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontChromeLocaleCacheWarmer.php'
        );
        self::assertStringContainsString('getWebsiteLanguageCodes', $src);
        self::assertStringContainsString('renderPartials', $src);
        self::assertStringContainsString("'header'", $src);
        self::assertStringContainsString("'footer'", $src);
        self::assertStringContainsString("'head'", $src);
        self::assertStringContainsString('BUDGET_MS', $src);
        // Must not invent tip-query or tip-locale whitelists as the fix.
        self::assertStringNotContainsString('hanfu', $src);
        self::assertStringNotContainsString('HEADER_TIP_QUERIES', $src);
        self::assertStringNotContainsString("['ar_SA', 'de_DE', 'fr_FR']", $src);
        self::assertStringNotContainsString("'fr_FR', 'ar_SA'", $src);
        // Must not hardcode acceptance sibling Host slug.
        self::assertStringNotContainsString('p05113ef3', $src);
        self::assertStringNotContainsString('daocharms', $src);
        self::assertStringNotContainsString('changanhanfu', $src);
    }

    public function testWarmerRaisesMemoryFloorForMultiLocaleHydrate(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontChromeLocaleCacheWarmer.php'
        );
        self::assertStringContainsString("ini_set('memory_limit', self::MEMORY_FLOOR)", $src);
        self::assertStringContainsString("MEMORY_FLOOR = '512M'", $src);
        self::assertStringNotContainsString('Parser::clearWorkerCaches', $src);
    }

    public function testWarmerPinsPublicHostAndReportsL2Fields(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/StorefrontChromeLocaleCacheWarmer.php'
        );
        self::assertStringContainsString('installAcceptancePublicHost', $src);
        self::assertStringContainsString('WLS_PUBLIC_ORIGIN', $src);
        self::assertStringContainsString('public_origin', $src);
        self::assertStringContainsString('public_host', $src);
        self::assertStringContainsString('warmed_l2_hit', $src);
        self::assertStringContainsString('warmed_render', $src);
        self::assertStringContainsString('verifyStorefrontSharedDurableL2', $src);
        self::assertStringContainsString('verifyStorefrontHeadAssetsDurableL2', $src);
        self::assertStringContainsString('installHomeRequestUri', $src);
        self::assertStringContainsString('GATE_LOCALES', $src);
        self::assertStringContainsString("'fr_FR'", $src);
        self::assertStringContainsString('BUDGET_MS = 180000', $src);
        self::assertStringContainsString('warm_partial=1', $src);
        self::assertStringContainsString('gate_ok:', $src);
        // website_url must match request Cookie shape (no trailing slash).
        self::assertStringContainsString('NO trailing slash', $src);
        self::assertStringContainsString("\$scheme . '://' . \$host;", $src);
        self::assertStringNotContainsString("\$scheme . '://' . \$host . '/';", $src);
        // Prefer bare public_host; strip WLS listen / 9xxx ports from origin.
        self::assertStringContainsString('isPrivateListenPort', $src);
        self::assertStringContainsString('Prefer bare public_host BEFORE public_origin', $src);
        // Home Index installs ThemeLayoutSourceSnapshot — warmer captures chrome bytes.
        self::assertStringContainsString('installHomepageLayoutSourceSnapshot', $src);
        self::assertStringContainsString('SolidifiedControllerTemplateResolver', $src);
        self::assertStringContainsString('snapshot_fp=', $src);
        self::assertStringContainsString('ThemeLayoutSourceSnapshot::REQUEST_KEY', $src);
        // xscope A: path-local digests (not full layout fp) for header/footer keys.
        self::assertStringContainsString('path-local digests', $src);
        self::assertStringContainsString('chrome-partial-v22', $src);
        // Published site theme via ThemeApplicationContext (not module default id=1).
        self::assertStringContainsString('ThemeApplicationContextProducer', $src);
        self::assertStringContainsString('layoutType', $src);
        self::assertStringContainsString("'homepage'", $src);
        // gate_ok requires header+footer L2 (chrome_n=2), not head-only.
        self::assertStringContainsString('$chromeL2 = $headerL2 && $footerL2', $src);
        // Ban shell-strip optimization language in warmer.
        self::assertStringNotContainsString('stripChrome', $src);
        self::assertStringNotContainsString('remove header', $src);
    }

    public function testPartialsExposesDurableL2VerifyHelpers(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Block/Partials.php'
        );
        self::assertStringContainsString('function verifyStorefrontSharedDurableL2', $src);
        self::assertStringContainsString('function verifyStorefrontHeadAssetsDurableL2', $src);
        self::assertStringContainsString('purgeProcessCacheForPolicy', $src);
        self::assertStringContainsString('peekPolicy', $src);
    }
}
