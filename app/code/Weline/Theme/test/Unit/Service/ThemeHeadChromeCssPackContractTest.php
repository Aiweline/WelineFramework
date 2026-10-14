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

    public function testStorefrontPackJoinsTokensUiThemeWithoutToastStyleShell(): void
    {
        $src = $this->read('Service/ThemeHeadChromeCssPack.php');
        self::assertStringContainsString('PACK_STOREFRONT', $src);
        self::assertStringContainsString('PACK_BACKEND', $src);
        self::assertStringContainsString('colors/_light.css', $src);
        self::assertStringContainsString('colors/_ink.css', $src);
        self::assertStringContainsString('variables/_spacing.css', $src);
        self::assertStringContainsString('variables/_auto-literals.css', $src);
        self::assertStringContainsString('weline-foundation.css', $src);
        self::assertStringContainsString('assets/css/theme.css', $src);
        // Style leaves stay out of layout pack (backend style selection generates CSS).
        self::assertStringContainsString('Style leaves', $src);
        self::assertStringContainsString('never hard-@static', $src);
        self::assertDoesNotMatchRegularExpression(
            "/PACK_STOREFRONT\\s*=>\\s*\\[[^\\]]*storefront-shopper-toast-amazon\\.css/s",
            $src,
        );
        self::assertMatchesRegularExpression(
            "/PACK_BACKEND\\s*=>\\s*\\[[\\s\\S]*?assets\\/css\\/theme\\.css/s",
            $src,
        );
        self::assertStringContainsString('theme_css_merge', $src);
        self::assertStringContainsString('warmAreaPacks', $src);
        self::assertStringContainsString('theme-head-chrome-v7', $src);
        self::assertStringContainsString('PACK_OVERLAY', $src);
        self::assertStringContainsString('themeOverlayAbsorbed', $src);
        self::assertStringContainsString('OVERLAY_LIST_RELATIVE', $src);
        self::assertStringContainsString('collectThemeOverlaySources', $src);
        // Theme pack leaf order: foundation/theme before variables before colors.
        $sf = strpos($src, "self::PACK_STOREFRONT => [");
        self::assertNotFalse($sf);
        $sfBlock = substr($src, $sf, 900);
        $foundation = strpos($sfBlock, 'weline-foundation.css');
        $spacing = strpos($sfBlock, 'variables/_spacing.css');
        $light = strpos($sfBlock, 'colors/_light.css');
        self::assertNotFalse($foundation);
        self::assertNotFalse($spacing);
        self::assertNotFalse($light);
        self::assertLessThan($spacing, $foundation, 'layout/shell CSS before variables');
        self::assertLessThan($light, $spacing, 'variables before colors');
        self::assertDoesNotMatchRegularExpression(
            '/\$attrs\s*\.=\\s*[\'"] data-weline-layout-css/',
            $this->extractTryLinkAttrsSnippet($src),
            'theme pack link must not set data-weline-layout-css'
        );
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
        self::assertMatchesRegularExpression(
            '/function buildWidgetAssetArtifact\s*\([^)]*\?WelineTheme\s+\$theme\s*=\s*null/s',
            $src
        );
        self::assertStringContainsString('/Weline/Theme/widget-assets/', $src);
    }

    public function testStorefrontPackEmitsBeforeStylesAfterHook(): void
    {
        // 2026-10-14：critical → theme pack → disk → overlay → early JS.
        // Big widget layout pack is placed after theme chrome by WidgetAssetHtmlPlacement.
        $prefix = $this->read('view/theme/frontend/partials/head/assets-prefix.phtml');
        $emitPack = strpos($prefix, '<?= $storefrontPackHtml');
        $critical = strpos($prefix, 'theme:layout-critical');
        $diskEcho = strpos($prefix, 'echo $diskOverrideHtml');
        $overlayEmit = strpos($prefix, '<?= $overlayPackHtml');
        $earlyJs = strpos($prefix, '<?= $earlyJsHtml');
        self::assertNotFalse($emitPack);
        self::assertNotFalse($critical);
        self::assertNotFalse($earlyJs);
        self::assertNotFalse($diskEcho);
        self::assertLessThan($emitPack, $critical, 'theme pack must come after critical CSS');
        self::assertLessThan($diskEcho, $emitPack, 'disk override after theme pack');
        self::assertNotFalse($overlayEmit);
        self::assertLessThan($overlayEmit, $diskEcho, 'overlay after disk');
        self::assertLessThan($earlyJs, $overlayEmit, 'early JS after overlay');
        self::assertStringContainsString('PACK_STOREFRONT', $prefix);
        self::assertStringContainsString('PACK_OVERLAY', $prefix);
        self::assertStringContainsString("'include_disk' => false", $prefix);
        self::assertStringNotContainsString('storefront-shopper-toast-amazon.css', $prefix);

        $suffix = $this->read('view/theme/frontend/partials/head/assets-suffix.phtml');
        self::assertStringContainsString('Weline_Theme::frontend::partials::head::styles-after', $suffix);
        self::assertStringNotContainsString(
            '<?= $storefrontPackHtml',
            $suffix,
            'theme pack must not be emitted twice from suffix when pack path succeeded',
        );
        self::assertStringContainsString('if ($storefrontPackHtml === null)', $suffix);
        self::assertStringNotContainsString('storefront-shopper-toast-amazon.css', $suffix);
    }

    private function extractTryLinkAttrsSnippet(string $src): string
    {
        $fn = strpos($src, 'function tryLinkHtml');
        self::assertNotFalse($fn);

        return substr($src, $fn, 1200);
    }

    public function testPackClassConstantsMatchHeadWiring(): void
    {
        self::assertSame('storefront', ThemeHeadChromeCssPack::PACK_STOREFRONT);
        self::assertSame('backend', ThemeHeadChromeCssPack::PACK_BACKEND);
        self::assertSame('tokens-pre', ThemeHeadChromeCssPack::PACK_TOKENS_PRE);
        self::assertSame('tokens-post', ThemeHeadChromeCssPack::PACK_TOKENS_POST);
        self::assertSame('ui', ThemeHeadChromeCssPack::PACK_UI);
        self::assertSame('overlay', ThemeHeadChromeCssPack::PACK_OVERLAY);
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

    /**
     * Shared extras-tab geometry/active fill belongs to Theme mini-cart-drawer.css.
     * Design overlay leaves + design statics/css must not reintroduce pill chrome.
     */
    public function testHanfuDesignCssMustNotRestyleSharedExtrasTabChrome(): void
    {
        $hanfuRoot = dirname(__DIR__, 6) . '/design/Weline/hanfu';
        $overlayPath = $hanfuRoot . '/frontend/partials/head/theme-head-css-overlay.php';
        self::assertFileExists($overlayPath);
        /** @var list<string> $leaves */
        $leaves = include $overlayPath;
        self::assertIsArray($leaves);

        $cssPaths = [];
        foreach ($leaves as $leaf) {
            $rel = (string)$leaf;
            // Overlay entries are theme-relative like theme/frontend/assets/css/hanfu-checkout.css
            $candidate = $hanfuRoot . '/frontend/' . preg_replace('#^theme/frontend/#', '', $rel);
            if (is_file($candidate) && str_ends_with($candidate, '.css')) {
                $cssPaths[] = $candidate;
            }
        }
        $staticsCss = $hanfuRoot . '/frontend/statics/css';
        if (is_dir($staticsCss)) {
            foreach (glob($staticsCss . '/*.css') ?: [] as $path) {
                $cssPaths[] = $path;
            }
        }
        self::assertFileDoesNotExist($hanfuRoot . '/frontend/statics/css/atelier-shell.css');

        $forbidden = '/mini-cart-drawer__extras-tab\.is-active[^{]*\{[^}]*(?:background\s*:|border-radius\s*:)/s';
        foreach ($cssPaths as $path) {
            $src = (string)file_get_contents($path);
            self::assertDoesNotMatchRegularExpression(
                $forbidden,
                $src,
                basename($path) . ' must not set background/border-radius on extras-tab.is-active'
            );
        }
    }
}
