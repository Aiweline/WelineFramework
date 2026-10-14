<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * Dev-only origin watch: Cron skip:non_dev, chrome vs layout split, app-reference ensure.
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
        // Non-dev path must return before watch->run().
        $executeStart = \strpos($src, 'function execute(): string');
        self::assertNotFalse($executeStart);
        $executeBody = \substr($src, (int)$executeStart, 800);
        $skipPos = \strpos($executeBody, "skip:non_dev");
        $runPos = \strpos($executeBody, '$this->watch->run()');
        self::assertNotFalse($skipPos);
        self::assertNotFalse($runPos);
        self::assertLessThan($runPos, $skipPos);
    }

    public function testWatchServiceSplitsChromeHealFromLayoutCascade(): void
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
        self::assertStringContainsString('publishStaticAssets', $src);
        self::assertStringContainsString('.origin-watch.json', $src);
        // Chrome-only must not call unfiltered solidifyTheme (no empty options array as sole bake).
        self::assertStringContainsString('never unfiltered full-theme bake', $src);
        self::assertStringContainsString("'kind' => 'chrome'", $src);
        self::assertStringContainsString("'kind' => 'layout'", $src);
        self::assertStringContainsString("'kind' => 'assets'", $src);
    }

    public function testUpgradeExposesPublishStaticAssetsForWatch(): void
    {
        $path = \dirname(__DIR__, 3) . '/Console/Theme/Upgrade.php';
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('public function publishStaticAssets(WelineTheme $theme, array $modules = []): void', $src);
        self::assertStringContainsString('$this->upgradeOneTheme($theme, $modules);', $src);
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
