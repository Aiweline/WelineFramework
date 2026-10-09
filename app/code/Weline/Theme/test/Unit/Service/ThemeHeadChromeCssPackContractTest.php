<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeHeadChromeCssPack;
use Weline\Theme\Service\ThemeResourceGateway;

/**
 * Head chrome CSS pack: single STOREFRONT/BACKEND shells; theme overlay belongs to active theme.
 */
final class ThemeHeadChromeCssPackContractTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . ltrim($relative, '/');
        self::assertFileExists($path);
        $content = file_get_contents($path);
        self::assertIsString($content);

        return $content;
    }

    public function testStorefrontPackJoinsTokensUiThemeAndToastAtRuntime(): void
    {
        $src = $this->read('Service/ThemeHeadChromeCssPack.php');
        self::assertStringContainsString('PACK_STOREFRONT', $src);
        self::assertStringContainsString('PACK_BACKEND', $src);
        self::assertStringContainsString('colors/_light.css', $src);
        self::assertStringContainsString('colors/_ink.css', $src);
        self::assertStringContainsString('variables/_spacing.css', $src);
        self::assertStringContainsString('weline-foundation.css', $src);
        self::assertStringContainsString('assets/css/theme.css', $src);
        self::assertStringContainsString('storefront-shopper-toast-amazon.css', $src);
        self::assertStringContainsString('theme_css_merge', $src);
        self::assertStringContainsString('warmAreaPacks', $src);
        self::assertStringContainsString('theme-head-chrome-v4', $src);
        self::assertStringContainsString('themeOverlayAbsorbed', $src);
        self::assertStringContainsString('OVERLAY_LIST_RELATIVE', $src);
        self::assertStringContainsString('collectThemeOverlaySources', $src);
        self::assertStringContainsString('resolvePackScopeFingerprint', $src);
        self::assertStringContainsString('artifactPathBelongsToTheme', $src);
        self::assertStringContainsString('ThemeApplicationContext', $src);
        self::assertStringNotContainsString("['css_merge']", $src);
    }

    public function testGatewayPublishesHeadChromeUnderActiveThemeNamespace(): void
    {
        $src = $this->read('Service/ThemeResourceGateway.php');
        self::assertStringContainsString('function buildHeadChromeArtifact', $src);
        self::assertStringContainsString('resolvePublicThemePath', $src);
        self::assertStringContainsString('/Weline/Theme/theme-head/', $src);
        self::assertStringContainsString('ThemeData::getCurrentTheme', $src);
        self::assertStringContainsString('?WelineTheme $theme = null', $src);
    }

    public function testStorefrontPackEmitsBeforeStylesAfterHook(): void
    {
        $head = $this->read('view/theme/frontend/partials/head/assets-suffix.phtml');
        $emitPack = strpos($head, '<?= $storefrontPackHtml');
        $hook = strpos($head, 'Weline_Theme::frontend::partials::head::styles-after');
        self::assertNotFalse($emitPack);
        self::assertNotFalse($hook);
        self::assertLessThan($hook, $emitPack, 'storefront pack emit must precede styles-after hook');
        self::assertStringContainsString('PACK_STOREFRONT', $head);
    }

    public function testPackClassConstantsMatchHeadWiring(): void
    {
        self::assertSame('storefront', ThemeHeadChromeCssPack::PACK_STOREFRONT);
        self::assertSame('backend', ThemeHeadChromeCssPack::PACK_BACKEND);
        self::assertSame('tokens-pre', ThemeHeadChromeCssPack::PACK_TOKENS_PRE);
        self::assertSame('tokens-post', ThemeHeadChromeCssPack::PACK_TOKENS_POST);
        self::assertSame('ui', ThemeHeadChromeCssPack::PACK_UI);
        self::assertSame('partials/head/theme-head-css-overlay.php', ThemeHeadChromeCssPack::OVERLAY_LIST_RELATIVE);
        self::assertTrue(method_exists(ThemeResourceGateway::class, 'buildHeadChromeArtifact'));
        self::assertTrue(method_exists(ThemeHeadChromeCssPack::class, 'warmAreaPacks'));
        self::assertTrue(method_exists(ThemeHeadChromeCssPack::class, 'themeOverlayAbsorbed'));
    }

    public function testDesignThemeOverlayListsMatchSkinCssFallback(): void
    {
        $designRoot = dirname(__DIR__, 6) . '/design/Weline';
        foreach (['hanfu' => 'hanfu-skin.phtml', 'daocharms' => 'daocharms-skin.phtml'] as $theme => $skin) {
            $overlayPath = $designRoot . '/' . $theme . '/frontend/partials/head/theme-head-css-overlay.php';
            $skinPath = $designRoot . '/' . $theme . '/frontend/partials/head/' . $skin;
            self::assertFileExists($overlayPath, $theme . ' overlay list missing');
            self::assertFileExists($skinPath, $theme . ' skin missing');
            /** @var list<string> $leaves */
            $leaves = include $overlayPath;
            self::assertIsArray($leaves);
            self::assertNotEmpty($leaves, $theme . ' overlay must list brand CSS');
            $skinSrc = (string)file_get_contents($skinPath);
            self::assertStringContainsString('themeOverlayAbsorbed', $skinSrc);
            foreach ($leaves as $leaf) {
                $basename = basename((string)$leaf);
                self::assertStringContainsString(
                    $basename,
                    $skinSrc,
                    $theme . ' skin fallback must keep ' . $basename . ' when merge off',
                );
            }
        }
    }
}
