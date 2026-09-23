<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Api\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * wave8-8c: Theme registers compliant HotCache bag warmup for deferred Runtime.
 */
final class StorefrontHotCacheBagWarmupProviderContractTest extends TestCase
{
    public function testModuleProvidesBagWarmupCapability(): void
    {
        $module = require BP . 'app/code/Weline/Theme/etc/module.php';
        self::assertIsArray($module);
        $provides = $module['provides'] ?? [];
        self::assertIsArray($provides);
        self::assertSame(
            \Weline\Theme\Api\Runtime\StorefrontHotCacheBagWarmupProvider::class,
            $provides['storefront_hot_cache_bag_warmup.Weline_Theme'] ?? null,
        );
    }

    public function testProviderImplementsFrameworkInterface(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Api/Runtime/StorefrontHotCacheBagWarmupProvider.php'
        );
        self::assertStringContainsString(
            'StorefrontHotCacheBagWarmupProviderInterface',
            $source,
        );
        self::assertStringContainsString('StorefrontHotCacheBagSeeder', $source);
        self::assertStringContainsString('primeCriticalBags', $source);
    }

    public function testChromeEagerSeedAndSlotProjectionPrimeExist(): void
    {
        $chrome = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/LayoutEntity/ThemeLayoutEntityChrome.php'
        );
        self::assertStringContainsString('seedPublishedHotCacheEager', $chrome);
        self::assertStringContainsString('rememberPolicy(', $chrome);

        $filler = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php'
        );
        self::assertStringContainsString('primePublishedChromeSlotProjectionHotCache', $filler);
        self::assertStringContainsString('chrome.slot.projection.v3|', $filler);
        self::assertStringContainsString('static fn(): array => []', $filler);

        $seeder = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/StorefrontHotCacheBagSeeder.php'
        );
        self::assertStringContainsString('HeaderCommerceData::resolveCategoryNavItems', $seeder);
        self::assertStringContainsString('seedPublishedHotCacheEager', $seeder);
        self::assertStringContainsString('renderPartials', $seeder);
        self::assertStringContainsString('theme.partials.fetch.header', $seeder);
        self::assertStringContainsString('getActiveTheme', $seeder);
        self::assertStringContainsString('ensureFrontendThemeAssignedToTemplate', $seeder);
        self::assertStringContainsString('theme.layout_entity.chrome_slot_projection', $seeder);
        self::assertTrue(
            \strpos($seeder, 'theme.partials.fetch.header') < \strpos($seeder, "bags[] = 'theme.layout_entity.chrome_slot_projection'"),
            'chrome_slot_projection must be primed after header partials (MRU)',
        );
        self::assertStringContainsString('scope_identity_missing', $seeder);
        self::assertStringContainsString('ensureStorefrontScopeIdentityForBagPrime', $seeder);
        self::assertStringContainsString('ScopeIdentity::channel(0, \'default\', \'default\', \'default\'', $seeder);
        self::assertStringContainsString('RequestContext::isInitialized()', $seeder);
        self::assertStringContainsString('default.__store__.__channel__', $seeder);
        // chrome_rendered miss short-path: honest [] only (禁空烧重投影); never empty HTML HIT.
        self::assertStringContainsString('chrome_rendered:miss scope=', $seeder);
        self::assertStringContainsString('rememberHonestEmptyChromeSlotProjection', $seeder);
        self::assertStringContainsString('storefrontBagPrimeScopes', $seeder);
        self::assertStringNotContainsString(
            'primePublishedChromeSlotProjectionHotCache($themeId, $storageScope)',
            $seeder,
            'miss short-path must not call heavy primePublishedChromeSlotProjectionHotCache on live scope alone',
        );
        self::assertStringNotContainsString('rememberPolicy($policy, $logicalKey, static fn(): string => \'\')', $seeder);
        self::assertStringNotContainsString("fn(): string => ''", $seeder);

        // Chrome eager seed: scope fallback + disk first; stage-gated durable bake (P8-O2).
        self::assertStringContainsString('publishedChromeSeedScopes', $chrome);
        self::assertMatchesRegularExpression(
            '/function seedPublishedHotCacheEager[\s\S]*?readRenderedCache\(\$path\)/',
            $chrome,
            'bag-prime seed must prefer durable disk snapshot',
        );
        self::assertStringContainsString('bagPrimeAllowsDurableChromeBake', $chrome);
        self::assertMatchesRegularExpression(
            '/function seedPublishedHotCacheEager[\s\S]*?loadOrRenderPublished\(\$path\)/',
            $chrome,
            'P8-O2: heavy/peer stages may one-shot bake chrome.rendered when disk wiped',
        );
        self::assertStringContainsString('post_critical_heavy', $chrome);
        self::assertMatchesRegularExpression(
            '/function bagPrimeAllowsDurableChromeBake[\s\S]*?post_critical_heavy[\s\S]*?peer_hydrate[\s\S]*?post_locale/',
            $chrome,
            'durable bake allow-list must be heavy/peer/post_locale only',
        );
        self::assertDoesNotMatchRegularExpression(
            '/function bagPrimeAllowsDurableChromeBake[\s\S]*?\'pre_critical\'/',
            $chrome,
            'pre_critical must not be in durable-bake allow-list',
        );
        self::assertDoesNotMatchRegularExpression(
            '/function bagPrimeAllowsDurableChromeBake[\s\S]*?\'critical\'/',
            $chrome,
            'critical must not be in durable-bake allow-list',
        );
        self::assertStringContainsString('ensureFrontendThemeAssignedToTemplate', $seeder);
        // Theme assign must precede chrome seed (durable bake needs Template theme).
        $chromeSeedPos = \strpos($seeder, 'seedPublishedHotCacheEager');
        $themeAssignPos = \strpos($seeder, 'ensureFrontendThemeAssignedToTemplate');
        self::assertNotFalse($chromeSeedPos);
        self::assertNotFalse($themeAssignPos);
        self::assertTrue(
            $themeAssignPos < $chromeSeedPos,
            'ensureFrontendThemeAssignedToTemplate must run before chrome seed',
        );

        self::assertStringContainsString('rememberHonestEmptyChromeSlotProjection', $filler);
        self::assertStringContainsString('static fn(): array => []', $filler);
    }
}