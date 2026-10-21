<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Backend;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 7) . '/app/bootstrap.php';

/**
 * 后端默认主题开发标准契约（Backend Theme Standard Contract）。
 *
 * 锁住 2026-10-21 起生效的后台 UI 标准，避免后续改动把旧壳/旧组件/同步脚本重新带回来：
 * - 后端字体必须是 sans（作用域 [data-w-area="backend"]，不改前台）
 * - 默认层 theme.css 禁止 !important（reduced-motion 例外）
 * - foundation 组件原语不得在 theme.css 里再写一套（避免 unlayered 覆盖 components）
 * - 后台 head 必须启动桩 + 核心脚本 defer
 * - 页面头必须走 w-backend-page__heading/__title + breadcrumb 语义类
 * - 旧的空壳组件/重复布局必须保持删除状态
 *
 * 标准正文：doc/backend/后端主题开发标准.md
 */
final class BackendThemeStandardContractTest extends TestCase
{
    private static function themeRoot(): string
    {
        return BP . 'app/code/Weline/Theme';
    }

    private static function backendArea(): string
    {
        return self::themeRoot() . '/view/theme/backend';
    }

    private static function uiSource(): string
    {
        return self::themeRoot() . '/view/ui/css';
    }

    public function testBackendFontScopeOverridesSerifWithoutTouchingFrontend(): void
    {
        $backendCss = (string)file_get_contents(self::uiSource() . '/backend.css');
        self::assertMatchesRegularExpression(
            '/\[data-w-area="backend"\]\s*\{[^}]*--weline-font-sans:\s*"Noto Sans SC"/s',
            $backendCss,
            'backend.css must scope a sans font stack to [data-w-area="backend"].'
        );
        self::assertStringContainsString('--weline-font-ui: var(--weline-font-sans);', $backendCss);
        self::assertStringContainsString('--weline-font-display: var(--weline-font-sans);', $backendCss);

        // foundation :root 的前台字体栈保持不动（后台差异只在 backend 作用域）。
        $foundation = (string)file_get_contents(self::uiSource() . '/foundation.css');
        self::assertMatchesRegularExpression(
            '/--weline-font-sans:\s*"Noto Serif SC"/',
            $foundation,
            'foundation.css :root front-end font stack must not be rewritten by backend work.'
        );
    }

    public function testBackendThemeCssHasNoBlockingImportant(): void
    {
        $css = (string)file_get_contents(self::backendArea() . '/assets/css/theme.css');
        // 注释里的「!important」是说明文字，不计入门禁。
        $css = (string)preg_replace('/\/\*.*?\*\//s', '', $css);
        $lines = preg_split('/\R/', $css) ?: [];
        $offenders = [];
        $reducedMotion = false;
        $depth = 0;
        $motionDepth = null;
        foreach ($lines as $index => $line) {
            $lower = strtolower($line);
            if (str_contains($lower, 'prefers-reduced-motion') && $motionDepth === null) {
                $motionDepth = $depth + 1;
            }
            $insideMotion = $motionDepth !== null && $depth >= $motionDepth;
            $isHidden = str_contains($lower, '[hidden]');
            if (str_contains($line, '!important') && !$insideMotion && !$isHidden) {
                $offenders[] = ($index + 1) . ': ' . trim($line);
            }
            $depth += substr_count($line, '{') - substr_count($line, '}');
            if ($motionDepth !== null && $depth < $motionDepth) {
                $motionDepth = null;
            }
        }
        self::assertSame([], $offenders, "theme.css must not use !important in the default layer:\n" . implode("\n", $offenders));
        self::assertSame(4, substr_count($css, '!important'), 'Only the prefers-reduced-motion block may keep !important.');
    }

    public function testThemeCssDoesNotForkFoundationPrimitives(): void
    {
        $css = (string)file_get_contents(self::backendArea() . '/assets/css/theme.css');
        foreach (['w-button', 'w-card', 'w-table', 'w-input', 'w-select', 'w-textarea', 'w-badge', 'w-alert', 'w-pagination', 'w-field', 'w-icon'] as $primitive) {
            self::assertDoesNotMatchRegularExpression(
                '/(?:^|\})\s*\.' . preg_quote($primitive, '/') . '\s*[,{]/m',
                $css,
                "theme.css must not re-declare foundation primitive .{$primitive}; use foundation tokens/classes instead."
            );
        }
    }

    public function testBackendHeadUsesBootStubAndDeferredCoreScripts(): void
    {
        $header = (string)file_get_contents(
            BP . 'app/code/Weline/Backend/view/blocks/header/base.phtml'
        );
        self::assertStringContainsString('data-weline-boot-stub="1"', $header);
        self::assertStringContainsString('__bootQueue', $header);
        self::assertSame(
            6,
            preg_match_all('/<w:js\s+defer>/', $header),
            'The six backend core scripts must be emitted with defer (url-backend is loaded by weline.js).'
        );
        self::assertDoesNotMatchRegularExpression(
            '/<w:js>\s*Weline_Backend::js\/(?:runtime|cookie|weline-api|weline-api-business|weline)\.js/',
            $header,
            'Backend core scripts must not stay render-blocking.'
        );

        $welineJs = (string)file_get_contents(
            BP . 'app/code/Weline/Backend/view/statics/js/weline.js'
        );
        self::assertStringContainsString('drainBackendBootQueue', $welineJs);
        self::assertStringContainsString('__bootQueueDrained', $welineJs);
    }

    public function testBackendHeadThemeJsPacksAreNonBlocking(): void
    {
        $head = (string)file_get_contents(self::backendArea() . '/partials/head/default.phtml');
        self::assertStringContainsString('tryInlineScriptHtml', $head, 'Pre-paint pack should be inlined, not a blocking request.');
        self::assertMatchesRegularExpression(
            '/tryScriptHtml\([^;]*PACK_AFTER_CSS[^;]*true\s*\)/s',
            $head,
            'after-css pack should be emitted with defer=true.'
        );
    }

    public function testBackendPageHeadingContract(): void
    {
        $partial = (string)file_get_contents(self::backendArea() . '/partials/layout/main-content.phtml');
        self::assertStringContainsString('class="w-backend-page__heading"', $partial);
        self::assertStringContainsString('class="w-backend-page__title"', $partial);
        self::assertStringContainsString('class="w-backend-page__breadcrumb"', $partial);
        self::assertSame(1, preg_match_all('/<h1\b/', $partial), 'main-content must be the only h1 emitter.');

        // 骨架类必须在后端 area CSS 有定义，避免“有类名没样式”。
        $backendCss = (string)file_get_contents(self::uiSource() . '/backend.css');
        foreach (['w-backend-page__title', 'w-backend-page__subtitle', 'w-backend-page__actions', 'w-backend-page__breadcrumb'] as $class) {
            self::assertStringContainsString('.' . $class, $backendCss);
        }
    }

    public function testLegacyEmptyShellsStayDeleted(): void
    {
        $removed = [
            '/partials/header/default.phtml',
            '/partials/sidebar/default.phtml',
            '/partials/breadcrumb/default.phtml',
            '/partials/scripts/default.phtml',
            '/partials/footer/default.phtml',
            '/layouts/default/1280.phtml',
            '/layouts/default/1440.phtml',
            '/components/dropdown.phtml',
            '/components/pagination.phtml',
            '/components/form-group.phtml',
        ];
        foreach ($removed as $relative) {
            self::assertFileDoesNotExist(self::backendArea() . $relative, "Deleted legacy shell must not come back: {$relative}");
        }

        foreach (glob(self::backendArea() . '/components/*.phtml') ?: [] as $component) {
            self::assertGreaterThan(0, (int)filesize($component), 'Empty component shells are not allowed: ' . $component);
        }
    }

    public function testBackendToolPagePrimitivesExistAndShipped(): void
    {
        $backendCss = (string)file_get_contents(self::uiSource() . '/backend.css');
        $primitives = ['w-split', 'w-log', 'w-terminal', 'w-status-dot'];
        foreach ($primitives as $primitive) {
            self::assertMatchesRegularExpression(
                '/\.' . preg_quote($primitive, '/') . '\b/',
                $backendCss,
                "Tool-page primitive .{$primitive} must live in the default-theme backend.css (standard §4/§12)."
            );
        }

        // 编译产物必须同步，否则页面读不到新原语（改 view/ui/css 后必须 resource:compile welineUi）。
        $compiledPath = self::themeRoot() . '/view/statics/ui/weline-backend.css';
        self::assertFileExists($compiledPath);
        $compiled = (string)file_get_contents($compiledPath);
        foreach ($primitives as $primitive) {
            self::assertStringContainsString('.' . $primitive, $compiled, "Compiled weline-backend.css is stale: missing .{$primitive}.");
        }

        // 工具页例外必须登记在案，不允许口头例外。
        $registry = (string)file_get_contents(self::themeRoot() . '/doc/backend/工具页迁移清单.md');
        foreach (['WlsPanel', 'WlsFileManager', 'enterprise.phtml', 'DevRelay/console.phtml'] as $needle) {
            self::assertStringContainsString($needle, $registry, "Tool-page exception must be registered: {$needle}.");
        }
    }

    public function testModuleBackendCssStaysTokenOnly(): void
    {
        // 迁移期基线：这些是本次迁移前既有的历史文件，允许后续批次清理；
        // 其余（新增/迁移过的）模块私有页面 CSS 必须 Token-only：禁 !important、禁颜色字面量。
        $legacyAllowlist = [
            'app/code/Weline/Product/view/statics/css/backend/product-admin.css',
            'app/code/Weline/Product/view/statics/css/backend/product-admin-picker.css',
            'app/code/Weline/Product/view/statics/css/backend/product-category-admin.css',
            'app/code/Weline/Product/view/statics/css/backend/catalog-entity-media.css',
            'app/code/Weline/Blog/view/statics/css/backend/blog-post-admin.css',
            'app/code/Weline/Social/view/statics/css/backend/social-admin.css',
            'app/code/Weline/Cdn/view/statics/css/backend/fpc-policy-management.css',
            'app/code/Weline/Catalog/view/statics/css/backend/category-admin.css',
            'app/code/Weline/Catalog/view/statics/css/backend/product-catalog-bulk.css',
            'app/code/Weline/Customer/view/statics/css/backend/customer-admin-select.css',
        ];

        // §12 已登记工具页：内部配色字面量允许暂存（登记在工具页迁移清单，后续批次 token 化），
        // 但 !important 对模块页面 CSS 一律禁止（无例外）。
        $toolPageLiteralAllowlist = [
            'app/code/Weline/Server/view/statics/css/backend/wls-panel.css',
            'app/code/Weline/Server/view/statics/css/backend/wls-panel-host.css',
        ];

        $offenders = [];
        foreach (glob(BP . 'app/code/*/*/view/statics/css/backend/*.css') ?: [] as $file) {
            $relative = str_replace(BP, '', $file);
            if (in_array($relative, $legacyAllowlist, true)) {
                continue;
            }
            $css = (string)preg_replace('/\/\*.*?\*\//s', '', (string)file_get_contents($file));
            if (str_contains($css, '!important')) {
                $offenders[] = $relative . ' -> !important 禁用（改用提高特异性或 @layer page）';
            }
            if (!in_array($relative, $toolPageLiteralAllowlist, true)
                && preg_match('/#[0-9a-fA-F]{3,8}\b|\brgba?\(|\bhsla?\(/', $css)) {
                $offenders[] = $relative . ' -> 颜色字面量禁用（只消费 Token；工具页例外需登记本测试 allowlist + 迁移清单）';
            }
        }
        self::assertSame([], $offenders, "Module backend page CSS must stay token-only (standard §6.2):\n" . implode("\n", $offenders));
    }

    public function testUserListingPageUsesStandardClassesOnly(): void
    {
        $path = BP . 'app/code/Weline/Admin/view/templates/backend/User/listing.phtml';
        $source = (string)file_get_contents($path);
        self::assertStringNotContainsString('<style>', $source, 'Page-local visual <style> is forbidden by the backend standard.');
        self::assertStringNotContainsString('!important', $source);
        self::assertStringNotContainsString('weline-user-avatar-placeholder', $source);
        self::assertStringNotContainsString('weline-superadmin-badge-sm', $source);
        self::assertStringContainsString('data-w-avatar="true"', $source);
        self::assertStringContainsString('data-w-background="primary"', $source);
        self::assertMatchesRegularExpression('/class="w-toolbar"/', $source);
    }
}
