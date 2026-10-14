<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * Dev-only origin watch: Cron skip:non_dev; widget cascade via ledger then layout <w:widget> embed scan.
 */
final class ThemeLayoutOriginWatchContractTest extends TestCase
{
    public function testCronFastSkipsNonDevAndRegistersExecuteName(): void
    {
        $path = \dirname(__DIR__, 3) . '/Cron/LayoutOriginWatch.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString("return 'theme_layout_origin_watch';", $src);
        self::assertStringContainsString("return 'skip:non_dev';", $src);
        self::assertStringContainsString("Env::system('deploy')", $src);
        self::assertStringContainsString("!== 'dev'", $src);
        self::assertStringContainsString('ThemeLayoutOriginWatchService', $src);
        self::assertStringContainsString('unlock_timeout', $src);
        self::assertStringContainsString('max(30', $src);
        self::assertStringContainsString('Cron 进程在跑', $src);
        $executeStart = \strpos($src, 'function execute(): string');
        self::assertNotFalse($executeStart);
        $executeBody = \substr($src, (int)$executeStart, 800);
        $skipPos = \strpos($executeBody, "skip:non_dev");
        $runPos = \strpos($executeBody, '$this->watch->run()');
        self::assertNotFalse($skipPos);
        self::assertNotFalse($runPos);
        self::assertLessThan($runPos, $skipPos);
    }

    public function testWatchServiceCascadesWidgetsViaLedgerNotLayoutTextScan(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutOriginWatchService.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('ownSolidifyTargetsForTheme', $src);
        self::assertStringContainsString('ensureCurrent', $src);
        self::assertStringContainsString('rematerializeChromeIfStale', $src);
        self::assertStringContainsString('solidifyTheme', $src);
        self::assertStringContainsString('layout_type', $src);
        self::assertStringContainsString('layout_option', $src);
        self::assertStringContainsString('.origin-watch.json', $src);
        self::assertStringContainsString('watchedManifestFiles', $src);
        self::assertStringContainsString('Theme-internal .phtml + .css/.js', $src);
        self::assertStringContainsString('publishStaticAssets', $src);
        self::assertStringContainsString("'kind' => 'assets'", $src);
        self::assertStringContainsString('theme_layout_origin_watch_assets', $src);
        self::assertStringContainsString('warmAreaPacks', $src);
        self::assertStringContainsString('layoutTypesFromWidgetLedger', $src);
        self::assertStringContainsString('layoutTypesFromWidgetEmbeds', $src);
        self::assertStringContainsString('layoutHtmlEmbedsWidget', $src);
        self::assertStringContainsString('appendExternalWidgetSources', $src);
        self::assertStringContainsString('__ext__/', $src);
        self::assertStringContainsString('resolveWidgetStaticAbsolutes', $src);
        self::assertStringContainsString('/asset/', $src);
        self::assertStringContainsString("\$class['asset']", $src);
        self::assertStringContainsString('refreshLiveStaticMirrorsForChangedPaths', $src);
        self::assertStringContainsString('FpcStaticDiskStore::republishFromFileOnAllHosts', $src);
        self::assertStringContainsString('assets:live_static_mirror:', $src);
        self::assertStringContainsString('uriFromAppCodeAbsolute', $src);
        self::assertStringNotContainsString('purgeAllLive(', $src);
        self::assertStringContainsString('collectExternalWidgetRefs', $src);
        self::assertStringContainsString('resolveSolidifyCascade', $src);
        self::assertStringContainsString("kind' => 'widget'", $src);
        self::assertStringContainsString("kind' => 'template'", $src);
        self::assertStringContainsString('WidgetRegistryEntry', $src);
        self::assertStringContainsString('cascade:full_theme', $src);
        self::assertStringContainsString('embed_scan', $src);
        self::assertStringContainsString('force_pages', $src);
        self::assertStringContainsString("classified['widgets']", $src);
        self::assertStringContainsString('ThemeStorefrontSlugFpcPublisher', $src);
        self::assertStringContainsString('purgeLayoutsForThemeTargets', $src);
        self::assertStringContainsString('theme_layout_origin_watch', $src);
        self::assertStringContainsString('clearStorefrontCachesAfterBake', $src);
        self::assertStringContainsString('cache_cleared', $src);
        self::assertStringContainsString('allFrontendLayoutsForTheme', $src);
        // Layout-scoped slug purge only — never site-wide html-disk staging call.
        self::assertStringNotContainsString('clearAllThemeRelatedCaches(', $src);
        self::assertStringNotContainsString('ThemeRuntimeCacheCleaner', $src);
        // Order: solidify/heal before cache clear (string positions).
        $solidifyPos = \strpos($src, '$this->solidify->solidifyTheme');
        $clearPos = \strpos($src, 'clearStorefrontCachesAfterBake');
        self::assertNotFalse($solidifyPos);
        self::assertNotFalse($clearPos);
        self::assertLessThan($clearPos, $solidifyPos);
        // Embed scan matches real <w:widget> tags; must not treat slot accept= as embeds.
        self::assertStringContainsString('<w:widget\\b', $src);
        self::assertStringContainsString('accept= lists are not embeds', $src);
        self::assertStringNotContainsString('ManagedChildAdmission', $src);
        self::assertStringContainsString('never unfiltered full-theme bake', $src);
        self::assertStringContainsString("'kind' => 'chrome'", $src);
        self::assertStringContainsString("'kind' => 'layout'", $src);
    }

    public function testBakeCoordinatorHonorsForcePagesPastDigestSkip(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php';
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString("\$forcePages = !empty(\$options['force_pages'])", $src);
        self::assertStringContainsString('!$forcePages', $src);
        self::assertStringContainsString('generatedPageMatchesInputs', $src);
        $upgrade = \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityUpgradeSolidifyService.php';
        $upSrc = (string)\file_get_contents($upgrade);
        self::assertStringContainsString("\$out['force_pages'] = true", $upSrc);
    }

    public function testUsageServiceExposesOwnSolidifyTargetsFromReferenceOwnerScope(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/ThemeApplicationUsageService.php';
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('function ownSolidifyTargetsForTheme(int $themeId): array', $src);
        self::assertStringContainsString('version_owner_scope', $src);
        self::assertStringContainsString('listOwnApplicationDetailRows', $src);
        self::assertStringNotContainsString('__store__.__channel__', $src);
    }
}
