<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeHeadChromeCssPack;
use Weline\Theme\Service\ThemeResourceGateway;

/**
 * Head chrome CSS: tokens → storefront base → layout-scoped overlay (Amazon-aligned).
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

    /** @return list<string> */
    private function flattenOverlayManifest(mixed $list): array
    {
        self::assertIsArray($list);
        if (array_key_exists('common', $list) || array_key_exists('layouts', $list)) {
            $out = [];
            foreach ((array)($list['common'] ?? []) as $item) {
                if (is_string($item) && $item !== '') {
                    $out[] = $item;
                }
            }
            foreach ((array)($list['layouts'] ?? []) as $items) {
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $item) {
                    if (is_string($item) && $item !== '') {
                        $out[] = $item;
                    }
                }
            }

            return array_values(array_unique($out));
        }
        $out = [];
        foreach ($list as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    public function testStorefrontPackIsBaseOnlyAndTokensHoldVariables(): void
    {
        $src = $this->read('Service/ThemeHeadChromeCssPack.php');
        self::assertStringContainsString('PACK_TOKENS', $src);
        self::assertStringContainsString('PACK_STOREFRONT', $src);
        self::assertStringContainsString('PACK_BACKEND', $src);
        self::assertStringContainsString('theme-head-chrome-v8', $src);
        self::assertStringContainsString('normalizeOverlayManifest', $src);
        self::assertStringContainsString('resolveOverlayLeavesForLayout', $src);
        self::assertStringContainsString('selectOverlayLeaves', $src);
        self::assertStringContainsString('ThemePageTypeResolver', $src);
        self::assertStringContainsString('resolveLayoutType', $src);
        self::assertStringContainsString('resolveLayoutTypeFromUri', $src);
        self::assertStringContainsString('Weline\\Framework\\Http\\Request', $src);
        self::assertStringContainsString('Style leaves', $src);
        self::assertStringContainsString('never hard-@static', $src);
        self::assertDoesNotMatchRegularExpression(
            "/PACK_STOREFRONT\\s*=>\\s*\\[[^\\]]*storefront-shopper-toast-amazon\\.css/s",
            $src,
        );

        $tokens = strpos($src, "self::PACK_TOKENS => [");
        $sf = strpos($src, "self::PACK_STOREFRONT => [");
        self::assertNotFalse($tokens);
        self::assertNotFalse($sf);
        self::assertGreaterThan($tokens, $sf);
        $tokensBlock = substr($src, $tokens, $sf - $tokens);
        self::assertStringContainsString('variables/_spacing.css', $tokensBlock);
        self::assertStringContainsString('colors/_light.css', $tokensBlock);
        self::assertStringContainsString('colors/_ink.css', $tokensBlock);
        self::assertStringNotContainsString('weline-foundation.css', $tokensBlock);
        self::assertStringNotContainsString('assets/css/theme.css', $tokensBlock);
        self::assertStringNotContainsString('colors/_dark.css', $tokensBlock);

        $sfEnd = strpos($src, 'self::PACK_BACKEND => [', $sf);
        self::assertNotFalse($sfEnd);
        $sfBlock = substr($src, $sf, $sfEnd - $sf);
        self::assertStringContainsString('weline-foundation.css', $sfBlock);
        self::assertStringContainsString('assets/css/theme.css', $sfBlock);
        self::assertStringContainsString('colors/_dark.css', $sfBlock);
        self::assertStringNotContainsString('variables/_spacing.css', $sfBlock);
        self::assertStringNotContainsString('colors/_light.css', $sfBlock);

        $tryLinkAttrs = $this->extractTryLinkAttrsSnippet($src);
        self::assertDoesNotMatchRegularExpression(
            '/\$attrs\s*\.=\\s*[\'"] data-weline-layout-css/',
            $tryLinkAttrs,
            'theme pack link must not set data-weline-layout-css'
        );
        // Pin attribute assignment only — comments may mention media="print" as forbidden.
        self::assertDoesNotMatchRegularExpression(
            '/\$attrs\s*(?:\.=|=)\s*[\'"][^\'"]*\bmedia\s*=/',
            $tryLinkAttrs,
            'amazon-progressive: chrome packs must not set media= (print-defer reserved for widget late source)'
        );
        self::assertDoesNotMatchRegularExpression(
            '/return\s+[\'"]<link[^\'"]*\bmedia\s*=/',
            $tryLinkAttrs,
            'amazon-progressive: tryLinkHtml return must stay blocking stylesheet (no media=)'
        );
        self::assertStringContainsString('amazon-progressive', $src);
        self::assertStringContainsString('WidgetAssetOptimizer', $src);
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
        $prefix = $this->read('view/theme/frontend/partials/head/assets-prefix.phtml');
        $tokens = strpos($prefix, '<?= $tokensPackHtml');
        $emitPack = strpos($prefix, '<?= $storefrontPackHtml');
        $critical = strpos($prefix, 'theme:layout-critical');
        $diskEcho = strpos($prefix, 'echo $diskOverrideHtml');
        $overlayEmit = strpos($prefix, '<?= $overlayPackHtml');
        self::assertNotFalse($tokens);
        self::assertNotFalse($emitPack);
        self::assertNotFalse($critical);
        self::assertNotFalse($diskEcho);
        self::assertLessThan($tokens, $critical, 'tokens after critical');
        self::assertLessThan($emitPack, $tokens, 'storefront base after tokens');
        self::assertLessThan($diskEcho, $emitPack, 'disk after storefront base');
        self::assertNotFalse($overlayEmit);
        self::assertLessThan($overlayEmit, $diskEcho, 'overlay after disk');
        // Amazon-style default Theme: no Theme JS / early pack in head prefix.
        self::assertStringNotContainsString('earlyJsHtml', $prefix);
        self::assertStringNotContainsString('ThemeHeadChromeJsPack', $prefix);
        self::assertStringNotContainsString('<script', $prefix);
        self::assertStringContainsString('PACK_TOKENS', $prefix);
        self::assertStringContainsString('PACK_STOREFRONT', $prefix);
        self::assertStringContainsString('PACK_OVERLAY', $prefix);
        // P2-revised: tokens gun before storefront gun (blocking shell, no print-defer).
        // File convention: assertLessThan($laterPos, $earlierPos) ⇒ earlier < later.
        $tokensConst = strpos($prefix, 'ThemeHeadChromeCssPack::PACK_TOKENS');
        $storefrontConst = strpos($prefix, 'ThemeHeadChromeCssPack::PACK_STOREFRONT');
        self::assertNotFalse($tokensConst);
        self::assertNotFalse($storefrontConst);
        self::assertLessThan($storefrontConst, $tokensConst, 'PACK_TOKENS before PACK_STOREFRONT');
        self::assertDoesNotMatchRegularExpression(
            '/<(?:link|style)[^>]*\bmedia\s*=\s*[\'"]print[\'"]/i',
            $prefix,
            'prefix must not emit print-deferred chrome CSS tags'
        );
        self::assertMatchesRegularExpression(
            "/PACK_STOREFRONT[\s\S]{0,240}'include_disk'\\s*=>\\s*true/",
            $prefix,
            'storefront pack must absorb disk when merge on'
        );
        self::assertStringContainsString('diskIncludedInPack', $prefix);
        self::assertStringNotContainsString('storefront-shopper-toast-amazon.css', $prefix);

        $suffix = $this->read('view/theme/frontend/partials/head/assets-suffix.phtml');
        self::assertStringContainsString('Weline_Theme::frontend::partials::head::styles-after', $suffix);
        self::assertStringContainsString('PACK_TOKENS', $suffix);
        self::assertStringContainsString('if ($tokensPackHtml === null && $storefrontPackHtml === null)', $suffix);
        self::assertStringNotContainsString('storefront-shopper-toast-amazon.css', $suffix);
    }

    private function extractTryLinkAttrsSnippet(string $src): string
    {
        $fn = strpos($src, 'function tryLinkHtml');
        self::assertNotFalse($fn);

        return substr($src, $fn, 1400);
    }

    public function testDiskOverrideMeaningfulCssGate(): void
    {
        self::assertFalse(\Weline\Theme\Service\Disk\ThemeDiskHeadService::cssContentIsMeaningful(''));
        self::assertFalse(\Weline\Theme\Service\Disk\ThemeDiskHeadService::cssContentIsMeaningful('/* only comment */'));
        self::assertTrue(\Weline\Theme\Service\Disk\ThemeDiskHeadService::cssContentIsMeaningful(
            ":root { --test-color: #ff0000; }"
        ));
        $src = $this->read('Service/ThemeHeadChromeCssPack.php');
        self::assertStringContainsString('overrideBundleHasMeaningfulCss', $src);
        self::assertStringContainsString('PACK_STOREFRONT', $src);
        self::assertMatchesRegularExpression(
            "/include_disk[\\s\\S]{0,120}PACK_STOREFRONT/",
            $src
        );
    }

    public function testPackClassConstantsMatchHeadWiring(): void
    {
        self::assertSame('tokens', ThemeHeadChromeCssPack::PACK_TOKENS);
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
            $leaves = $this->flattenOverlayManifest(include $overlayPath);
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
            self::assertMatchesRegularExpression(
                '/if\s*\(\s*!?\s*\$absorbOverlayCss\s*\)\s*:\s*\?>[\s\S]*endif/i',
                $skinSrc,
                $theme . ' skin must gate overlay CSS behind absorbOverlayCss',
            );
            $afterAbsorb = preg_split('/endif\s*;?\s*\?>/i', $skinSrc, 2)[1] ?? '';
            foreach ($leaves as $leaf) {
                $basename = basename((string)$leaf);
                self::assertStringNotContainsString(
                    $basename,
                    $afterAbsorb,
                    $theme . ' must not re-link ' . $basename . ' after overlay absorb (duplicate)',
                );
            }
        }
    }

    public function testHanfuOverlayScopesHomepageCheckoutAccount(): void
    {
        $path = dirname(__DIR__, 6) . '/design/Weline/hanfu/frontend/partials/head/theme-head-css-overlay.php';
        self::assertFileExists($path);
        /** @var array{common?:list<string>,layouts?:array<string,list<string>>} $manifest */
        $manifest = include $path;
        self::assertArrayHasKey('common', $manifest);
        self::assertArrayHasKey('layouts', $manifest);
        $common = implode("\n", $manifest['common']);
        self::assertStringContainsString('hanfu-ui-sot.css', $common);
        self::assertStringNotContainsString('hanfu-homepage.css', $common);
        self::assertStringNotContainsString('hanfu-checkout.css', $common);
        self::assertStringNotContainsString('hanfu-account.css', $common);
        self::assertStringContainsString('hanfu-homepage.css', implode("\n", $manifest['layouts']['homepage'] ?? []));
        self::assertStringContainsString('hanfu-checkout.css', implode("\n", $manifest['layouts']['checkout'] ?? []));
        self::assertStringContainsString('hanfu-account.css', implode("\n", $manifest['layouts']['account'] ?? []));

        $homeLeaves = implode("\n", ThemeHeadChromeCssPack::selectOverlayLeaves($manifest, 'homepage'));
        self::assertStringContainsString('hanfu-ui-sot.css', $homeLeaves);
        self::assertStringContainsString('hanfu-homepage.css', $homeLeaves);
        self::assertStringNotContainsString('hanfu-checkout.css', $homeLeaves);
        self::assertStringNotContainsString('hanfu-account.css', $homeLeaves);

        $checkoutLeaves = implode("\n", ThemeHeadChromeCssPack::selectOverlayLeaves($manifest, 'checkout'));
        self::assertStringContainsString('hanfu-checkout.css', $checkoutLeaves);
        self::assertStringNotContainsString('hanfu-homepage.css', $checkoutLeaves);

        $accountLogin = implode("\n", ThemeHeadChromeCssPack::selectOverlayLeaves($manifest, 'account/login'));
        self::assertStringContainsString('hanfu-account.css', $accountLogin);
        self::assertStringNotContainsString('hanfu-checkout.css', $accountLogin);

        $commonOnly = implode("\n", ThemeHeadChromeCssPack::selectOverlayLeaves($manifest, ''));
        self::assertStringContainsString('hanfu-ui-sot.css', $commonOnly);
        self::assertStringNotContainsString('hanfu-homepage.css', $commonOnly);
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
        $leaves = $this->flattenOverlayManifest(include $overlayPath);

        $cssPaths = [];
        foreach ($leaves as $leaf) {
            $rel = (string)$leaf;
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
