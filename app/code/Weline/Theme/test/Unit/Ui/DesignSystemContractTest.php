<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\Compiler\Tag\BuiltinRegistry;
use Weline\Framework\View\Compiler\Tag\TagDescriptor;

require_once dirname(__DIR__, 7) . '/app/bootstrap.php';

/**
 * Weline UI 设计系统契约（Apple 风格组件层）。
 *
 * 锁住：
 * - Token 层 / 组件层文件存在，且按 foundation → tokens → components 顺序进入 foundation bundle；
 * - 组件 CSS 只消费语义 Token（无颜色字面量、无 !important）；
 * - 色盘映射存在（--color-* / --backend-color-* → --weline-theme-*）；
 * - 编译产物包含设计系统 Token；
 * - 标签静态/动态阶段（compile/runtime/auto）可用且内置标签均声明合法阶段。
 *
 * 标准正文：doc/设计系统/组件与Token规范.md
 */
final class DesignSystemContractTest extends TestCase
{
    private static function uiCss(): string
    {
        return BP . 'app/code/Weline/Theme/view/ui/css';
    }

    public function testTokenAndComponentSourcesExistAndAreBundledInOrder(): void
    {
        $expected = [
            'css/foundation.css',
            'css/tokens/apple.css',
            'css/components/button.css',
            'css/components/field.css',
            'css/components/selection.css',
            'css/components/navigation.css',
            'css/components/surface.css',
            'css/components/feedback.css',
        ];
        $componentBundles = [
            'ui-tokens' => 'css/tokens/apple.css',
            'ui-critical' => 'css/critical-utilities.css',
            'ui-button' => 'css/components/button.css',
            'ui-field' => 'css/components/field.css',
            'ui-selection' => 'css/components/selection.css',
            'ui-navigation' => 'css/components/navigation.css',
            'ui-surface' => 'css/components/surface.css',
            'ui-feedback' => 'css/components/feedback.css',
        ];
        foreach ($expected as $relative) {
            self::assertFileExists(self::uiCss() . '/' . substr($relative, 4));
        }

        $manifest = json_decode(
            (string)file_get_contents(BP . 'app/code/Weline/Theme/etc/weline-ui-assets.json'),
            true
        );
        self::assertIsArray($manifest, 'weline-ui-assets.json must be valid JSON.');
        $sources = $manifest['bundles']['foundation']['sources'] ?? [];
        self::assertSame($expected, $sources, 'foundation bundle order: foundation → tokens → components (single critical pack).');
        foreach ($componentBundles as $bundle => $source) {
            self::assertFileExists(self::uiCss() . '/' . substr($source, 4));
            self::assertSame([$source], $manifest['bundles'][$bundle]['sources'] ?? [], 'Component bundle source mismatch: ' . $bundle);
            $output = (string)($manifest['bundles'][$bundle]['output'] ?? '');
            self::assertNotSame('', $output, 'Component bundle output missing: ' . $bundle);
            self::assertFileExists(BP . 'app/code/Weline/Theme/view/statics/ui/' . $output, 'Compiled component bundle missing: ' . $output);
        }
    }

    public function testComponentCssIsTokenOnly(): void
    {
        $manifest = json_decode(
            (string)file_get_contents(BP . 'app/code/Weline/Theme/etc/weline-ui-assets.json'),
            true
        );
        $files = [];
        foreach (($manifest['bundles'] ?? []) as $bundleName => $bundle) {
            if (!is_string($bundleName) || !str_starts_with($bundleName, 'ui-')) {
                continue;
            }
            foreach ((array)($bundle['sources'] ?? []) as $relative) {
                if (is_string($relative) && str_starts_with($relative, 'css/components/')) {
                    $files[] = self::uiCss() . '/' . substr($relative, 4);
                }
            }
        }
        $files = array_values(array_unique($files));
        self::assertNotEmpty($files, 'Component layer is empty.');

        $offenders = [];
        foreach ($files as $file) {
            $css = (string)preg_replace('/\/\*.*?\*\//s', '', (string)file_get_contents($file));
            if (str_contains($css, '!important')) {
                $offenders[] = basename($file) . ' -> !important';
            }
            if (preg_match('/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(/', $css) === 1) {
                $offenders[] = basename($file) . ' -> literal color';
            }
            // 组件层必须至少消费一个语义 token（防止空文件或硬编码组件）
            if (!str_contains($css, 'var(--weline-')) {
                $offenders[] = basename($file) . ' -> no semantic token';
            }
        }
        self::assertSame([], $offenders, "Component CSS must be token-only:\n" . implode("\n", $offenders));
    }

    public function testPaletteBridgeAndControlScaleExist(): void
    {
        $tokens = (string)file_get_contents(self::uiCss() . '/tokens/apple.css');
        foreach ([
            '--color-primary',
            '--backend-color-primary',
            '--weline-theme-primary',
            '--weline-control-height',
            '--weline-control-height-sm',
            '--weline-control-height-lg',
            '--weline-radius-md',
            '--weline-theme-shadow-sm',
        ] as $needle) {
            self::assertStringContainsString($needle, $tokens, "tokens/apple.css must declare/bridge {$needle}.");
        }
        self::assertStringContainsString('[data-w-area="backend"]', $tokens, 'Backend palette bridge must stay scoped to backend area.');

        $compiled = (string)file_get_contents(BP . 'app/code/Weline/Theme/view/statics/ui/weline-foundation.css');
        foreach (['--weline-control-padding-inline', '--weline-radius-xl', '.w-switch', '.w-button'] as $needle) {
            self::assertStringContainsString($needle, $compiled, "Compiled weline-foundation.css is stale: missing {$needle}.");
        }
    }

    public function testCompiledComponentUsageAndInlineCssExist(): void
    {
        $usageFile = BP . 'app/code/Weline/Theme/view/statics/ui/components/usage.json';
        self::assertFileExists($usageFile, 'Compile-time component usage.json is missing (run resource:compile welineUi).');
        $usage = json_decode((string)file_get_contents($usageFile), true);
        self::assertIsArray($usage);
        self::assertArrayHasKey('backend', $usage['areas'] ?? []);
        self::assertContains('button', $usage['areas']['backend']);

        $service = new \Weline\Theme\Service\Ui\ComponentUsageService();
        $keys = $service->componentKeysForArea('backend');
        self::assertContains('button', $keys);
        self::assertContains('selection', $keys);
        $inline = $service->renderInlineStyle('backend');
        self::assertStringContainsString('--weline-control-height', $inline, 'Inline critical CSS must carry semantic tokens.');
        self::assertStringContainsString('.w-grid', $inline, 'Inline critical CSS must carry core layout utilities.');
        self::assertStringContainsString('.w-button', $inline);

        // 组件 CSS 已合并进单一关键包（一次请求、可缓存），并单独编译出 per-component 产物供后续按部件拆分。
        $foundation = (string)file_get_contents(BP . 'app/code/Weline/Theme/view/statics/ui/weline-foundation.css');
        self::assertStringContainsString('.w-switch', $foundation, 'Component layer must stay in the single critical foundation pack for correct cascade.');

        // 登录按钮双 loading 回归：显式 .w-spinner 存在时，禁止 ::after 再生成一个 spinner。
        $buttonCss = (string)file_get_contents(BP . 'app/code/Weline/Theme/view/ui/css/components/button.css');
        self::assertStringContainsString(':has(.w-spinner)::after', $buttonCss);
        self::assertStringContainsString(':has(.w-spinner)::after', $foundation, 'Compiled foundation must carry the double-spinner fix.');

        // 暗色 hover/active 对比度契约：active 走语义 token，禁止回落到前台浅色 hover，禁止纯白 active 文本。
        $tokens = (string)file_get_contents(BP . 'app/code/Weline/Theme/view/ui/css/tokens/apple.css');
        self::assertStringContainsString('--weline-theme-active-bg', $tokens);
        self::assertStringContainsString('--weline-theme-active-text', $tokens);
        self::assertStringContainsString('var(--backend-color-surface-hover', $tokens, 'Backend hover must map to backend palette, not frontend light fallback.');
        self::assertStringContainsString('#dbe4ff', $tokens, 'Dark active text must be soft lavender, not pure white.');
        $backendCss = (string)file_get_contents(BP . 'app/code/Weline/Theme/view/ui/css/backend.css');
        self::assertStringContainsString('var(--weline-theme-active-bg)', $backendCss);
        self::assertStringContainsString('var(--weline-theme-active-text)', $backendCss);
    }

    public function testCriticalCssHeadKeepsLayoutBlocking(): void
    {
        $pack = (string)file_get_contents(BP . 'app/code/Weline/Theme/Service/ThemeHeadChromeCssPack.php');
        // 非阻塞能力保留为可选 API，但布局 CSS 必须走阻塞主包。
        self::assertStringContainsString('function tryInlineCssHtml', $pack);
        self::assertStringContainsString('function tryPreloadLinkHtml', $pack);
        self::assertStringContainsString('rel="preload" as="style"', $pack);

        $backendHead = (string)file_get_contents(
            BP . 'app/code/Weline/Theme/view/theme/backend/partials/head/default.phtml'
        );
        self::assertStringContainsString('tryLinkHtml', $backendHead);
        self::assertStringContainsString('theme:layout-critical', $backendHead);
        self::assertStringNotContainsString('tryPreloadLinkHtml', $backendHead, 'Layout pack must stay render-blocking.');
        self::assertStringNotContainsString('renderInlineStyle', $backendHead);

        $frontendPrefix = (string)file_get_contents(
            BP . 'app/code/Weline/Theme/view/theme/frontend/partials/head/assets-prefix.phtml'
        );
        self::assertStringContainsString('tryLinkHtml', $frontendPrefix);
        self::assertStringNotContainsString('tryPreloadLinkHtml', $frontendPrefix, 'Storefront layout pack must stay render-blocking.');
        self::assertStringNotContainsString('renderInlineStyle', $frontendPrefix);

        // 设计主题皮肤与后台 widget CSS 必须阻塞，避免 LCP/骨架闪屏。
        $hanfuSkin = (string)file_get_contents(
            BP . 'app/design/Weline/hanfu/frontend/partials/head/hanfu-skin.phtml'
        );
        self::assertStringContainsString("return '<link rel=\"stylesheet\"", $hanfuSkin);
        self::assertStringNotContainsString('rel="preload" as="style"', $hanfuSkin);
        $ssa = (string)file_get_contents(
            BP . 'app/code/Weline/SiteSetupAssistant/view/hooks/Weline_Theme/backend/layouts/base/body-end.phtml'
        );
        self::assertStringContainsString("'<link rel=\"stylesheet\"", $ssa);
        self::assertStringNotContainsString('rel="preload" as="style"', $ssa);

        // 页面级非阻塞能力保留，但默认 css_merge_start 仍为 6（布局优先）。
        $taglib = (string)file_get_contents(BP . 'app/code/Weline/Framework/View/Taglib.php');
        self::assertStringContainsString('nonblocking', $taglib);
        $config = (string)file_get_contents(BP . 'app/code/Weline/Theme/Service/ThemeResourceConfig.php');
        self::assertStringContainsString("'default', 6", $config);
        self::assertStringContainsString('max(1, (int)$value)', $config);
    }

    public function testAllDesignThemesFollowLayoutFirstHeadContract(): void
    {
        $headFiles = glob(BP . 'app/design/*/**/frontend/partials/head/*.phtml') ?: [];
        $headFiles = array_merge($headFiles, glob(BP . 'app/design/*/frontend/partials/head/*.phtml') ?: []);
        self::assertNotEmpty($headFiles, 'Design theme head partials must exist.');
        $themesWithCritical = [];
        foreach ($headFiles as $file) {
            $src = (string)file_get_contents($file);
            $rel = str_replace(BP, '', $file);
            // 注释里的 PACK_* 提及不算发射；只在真实代码上断言。
            $code = (string)preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $src);
            self::assertStringNotContainsString('tryPreloadLinkHtml', $code, 'Design theme must not preload layout CSS: ' . $rel);
            self::assertStringNotContainsString('tryInlineCssHtml', $code, 'Design theme must not inline pack CSS: ' . $rel);
            self::assertStringNotContainsString('renderInlineStyle', $code, 'Design theme must not inline component CSS: ' . $rel);
            if (str_contains($code, 'PACK_STOREFRONT') && preg_match('/->try[A-Za-z]*LinkHtml\(/', $code) === 1) {
                self::assertStringContainsString('tryLinkHtml', $code, 'Pack-emitting theme head must stay blocking: ' . $rel);
            }
            if (str_contains($src, 'theme:layout-critical')) {
                $parts = explode('/frontend/partials/head/', $rel);
                $themesWithCritical[$parts[0]] = true;
            }
        }
        self::assertNotEmpty($themesWithCritical, 'Every design theme needs an inline layout-critical head.');
    }

    public function testTagStagesSeparateStaticAndDynamicRendering(): void
    {
        self::assertSame('compile', TagDescriptor::STAGE_COMPILE);
        self::assertSame('runtime', TagDescriptor::STAGE_RUNTIME);
        self::assertSame('auto', TagDescriptor::STAGE_AUTO);

        $registry = BuiltinRegistry::build();
        $seen = [];
        foreach (BuiltinRegistry::descriptors() as $descriptor) {
            self::assertContains(
                $descriptor->stage,
                [TagDescriptor::STAGE_COMPILE, TagDescriptor::STAGE_RUNTIME, TagDescriptor::STAGE_AUTO],
                'Builtin tag ' . $descriptor->name . ' declares an invalid stage.'
            );
            $seen[$descriptor->stage] = true;
            self::assertTrue($registry->has($descriptor->name), 'Builtin tag must be registered: ' . $descriptor->name);
        }
        self::assertArrayHasKey(TagDescriptor::STAGE_COMPILE, $seen, 'At least one compile-time (static) tag must exist.');
        self::assertArrayHasKey(TagDescriptor::STAGE_RUNTIME, $seen, 'At least one runtime (dynamic) tag must exist.');
    }

    public function testSolidButtonTextStaysReadableOnPrimaryBackground(): void
    {
        $buttonCss = (string)file_get_contents(self::uiCss() . '/components/button.css');
        self::assertStringContainsString(
            'color: var(--weline-theme-on-primary, var(--color-on-primary',
            $buttonCss,
            'Solid .w-button text must chain an on-primary fallback so labels never dissolve into the brand background.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/(?<![a-z-])color:\s*transparent/',
            $buttonCss,
            'Solid .w-button text must never be transparent.'
        );
        $foundation = (string)file_get_contents(BP . 'app/code/Weline/Theme/view/statics/ui/weline-foundation.css');
        self::assertStringContainsString(
            '--weline-theme-on-primary: var(--color-on-primary',
            $foundation,
            'Compiled weline-foundation.css must carry the solid button contrast fallback.'
        );
    }
}
