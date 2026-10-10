<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * Hard gate: sibling themes A/B inheriting Default must not clobber each other's
 * theme-head / widget-assets / com_* via unpinned ThemeData or cross-theme skip reuse.
 */
final class ThemeLayoutNamespaceIsolationContractTest extends TestCase
{
    public function testFormalCompilePinsThemeDataBeforeWarmAndCompile(): void
    {
        $service = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityFormalLocaleCompileService.php'
        );
        self::assertStringContainsString('ThemeData::setCurrentTheme($theme)', $service);
        self::assertStringContainsString('ThemeData::setCurrentArea($identity->area)', $service);
        $pinPos = strpos($service, 'ThemeData::setCurrentTheme($theme)');
        $warmPos = strpos($service, 'warmPublishedResourcePacks(');
        $restorePos = strpos($service, 'ThemeData::setCurrentTheme($previousTheme)');
        self::assertNotFalse($pinPos);
        self::assertNotFalse($warmPos);
        self::assertNotFalse($restorePos);
        // PHPUnit assertLessThan($expected, $actual) ⇒ $actual < $expected
        self::assertLessThan($warmPos, $pinPos, 'ThemeData pin must precede warmPublishedResourcePacks call');
        self::assertLessThan($restorePos, $warmPos, 'warm call must precede ThemeData restore');
        self::assertStringContainsString('WelineTheme $theme', $service);
        self::assertStringContainsString('theme_layout_formal_warm_theme_missing', $service);
    }

    public function testGatewayArtifactsRequireExplicitThemeNamespace(): void
    {
        $gateway = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ThemeResourceGateway.php'
        );
        self::assertStringContainsString('buildHeadChromeArtifact', $gateway);
        self::assertStringContainsString('buildWidgetAssetArtifact', $gateway);
        self::assertStringContainsString('?WelineTheme $theme = null', $gateway);
        self::assertStringContainsString('Fail-closed: never write a bare /Weline/Theme/widget-assets pack', $gateway);
        self::assertStringContainsString('/Weline/Theme/theme-head/', $gateway);
    }

    public function testHeadPacksResolveThemeFromThemeDataCurrent(): void
    {
        $css = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ThemeHeadChromeCssPack.php'
        );
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ThemeHeadChromeJsPack.php'
        );
        self::assertStringContainsString('ThemeData::getCurrentTheme', $css);
        self::assertStringContainsString('ThemeData::getCurrentTheme', $js);
        self::assertStringContainsString('buildHeadChromeArtifact', $css);
        self::assertStringContainsString('buildHeadChromeArtifact', $js);
    }

    public function testUpgradeCollectSkipsInheritWithoutCrossThemeReuse(): void
    {
        $coord = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityBakeCoordinator.php'
        );
        self::assertStringContainsString('layoutNeedsOwnSolidify', $coord);
        self::assertStringContainsString('skipped_inherit', $coord);
        self::assertStringContainsString('ThemeLayoutDesignOverride::provides', $coord);
        $override = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutDesignOverride.php'
        );
        self::assertStringContainsString('class ThemeLayoutDesignOverride', $override);
        self::assertStringContainsString('layer_key', $override);
    }
}
