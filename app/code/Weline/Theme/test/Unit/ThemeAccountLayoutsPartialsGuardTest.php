<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 断言账号相关布局不再引用旧 Frontend public header/footer（应与 Theme Partials 同源）。
 */
final class ThemeAccountLayoutsPartialsGuardTest extends TestCase
{
    private const LEGACY_SNIPPETS = [
        'Weline_Frontend::templates/public/header.phtml',
        'Weline_Frontend::templates/public/footer.phtml',
    ];

    public function testAccountLayoutPhpFilesDoNotUseLegacyPublicChrome(): void
    {
        $base = dirname(__DIR__, 2) . '/view/theme/frontend/layouts';
        $patterns = [
            $base . '/account/*.phtml',
            $base . '/account_auth/*.phtml',
        ];
        $files = [];
        foreach ($patterns as $pattern) {
            $matched = glob($pattern);
            if (is_array($matched)) {
                $files = array_merge($files, $matched);
            }
        }
        $files = array_unique($files);
        $this->assertNotSame([], $files, '应至少存在一个账号布局模板文件');

        foreach ($files as $path) {
            $content = (string) file_get_contents($path);
            foreach (self::LEGACY_SNIPPETS as $snippet) {
                $this->assertStringNotContainsString(
                    $snippet,
                    $content,
                    basename((string) $path) . ' 仍包含旧 public chrome：' . $snippet
                );
            }
        }
    }

    public function testAccountChallengeLayoutEmbedsThemeChallengeWidget(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/account/challenge.phtml';

        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);
        $this->assertStringContainsString('body class="account-auth-layout account-challenge-layout', $content);
        $this->assertStringContainsString('type="header"', $content);
        $this->assertStringContainsString('type="footer"', $content);
        $this->assertStringContainsString('Weline\\Theme\\Block\\Partials', $content);
        $this->assertMatchesRegularExpression(
            '/<w:widget\\s+type="form"\\s+name="account-challenge"\\s*\\/>/',
            $content
        );
        $this->assertStringNotContainsString('#232f3e', $content);
        $this->assertStringNotContainsString('Weline_Frontend::templates/public/header.phtml', $content);
    }

    public function testAccountAuthLayoutRendersThemePartialsHeaderAndFooterByDefault(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/account/auth.phtml';

        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);
        $this->assertStringContainsString('body class="account-auth-layout', $content);
        $this->assertStringContainsString("\$this->setData('__weline_frontend_final_title', \$pageTitle);", $content);
        $this->assertStringContainsString("@param.showHeader {default=true", $content);
        $this->assertStringContainsString("@param.showFooter {default=true", $content);
        $this->assertStringContainsString('type="header"', $content);
        $this->assertStringContainsString('type="footer"', $content);
        $this->assertStringContainsString('Weline\\Theme\\Block\\Partials', $content);
        $this->assertStringContainsString('account-login', $content);
        $this->assertStringContainsString('account-auth-stage', $content);
        $this->assertStringContainsString('default-option="default"', $content);
        $this->assertStringNotContainsString('default-option="auth"', $content);
        $this->assertStringNotContainsString('auth_secondary_url', $content);
        // Header chrome includes mini-cart drawer; base body-end loads its CSS/JS.
        $this->assertStringContainsString(
            'Weline_Theme::frontend::layouts::base::body-end',
            $content
        );
        $this->assertMatchesRegularExpression(
            '/<w:widget\\s+type="form"\\s+name="account-login"\\s*\\/>/',
            $content
        );
        $this->assertMatchesRegularExpression(
            '/<w:widget\\s+type="form"\\s+name="account-register"\\s*\\/>/',
            $content
        );
        $this->assertStringNotContainsString('account-auth-layout__placeholder', $content);
        $this->assertDoesNotMatchRegularExpression('/default_injections\\s*=>/', $content);
        $this->assertStringNotContainsString('Weline_Frontend::templates/public/header.phtml', $content);
        $this->assertStringNotContainsString('Weline_Frontend::templates/public/footer.phtml', $content);
    }

    public function testAccountDashboardMainContentUsesPageWidthContainer(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/account/dashboard.phtml';

        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);
        $this->assertStringContainsString('.account-main-content {', $content);
        // 与 foundation .w-container / header-container 同一版心公式
        $this->assertStringContainsString(
            'width: min(100%, var(--weline-layout-content-max-width));',
            $content
        );
        $this->assertStringContainsString('padding-inline: var(--weline-layout-content-padding-inline);', $content);
        $this->assertStringContainsString('margin-inline: auto;', $content);
        $this->assertStringNotContainsString('max-width: var(--layout-max-width, 1600px);', $content);
        $this->assertStringContainsString('<main class="account-main-content', $content);
        $this->assertStringNotContainsString('.account-dashboard__body {', $content);
    }

    public function testAccountMainPanelIsNotNestedWhiteCardShell(): void
    {
        $base = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/account';
        $cases = [
            $base . '/dashboard.phtml' => '.account-main',
            $base . '/default.phtml' => '.account-main-content',
        ];

        foreach ($cases as $path => $selector) {
            $this->assertFileExists($path);
            $content = (string) file_get_contents($path);
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($selector, '/') . '\\s*\\{([^}]+)\\}/',
                $content,
                basename($path) . " 须声明 {$selector}"
            );
            preg_match('/' . preg_quote($selector, '/') . '\s*\{([^}]+)\}/', $content, $match);
            $block = (string) ($match[1] ?? '');
            $this->assertStringNotContainsString(
                'weline-layout-surface-primary',
                $block,
                basename($path) . " {$selector} 禁止白底壳（避免套两层）"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/box-shadow\s*:\s*(?!none\b)/',
                $block,
                basename($path) . " {$selector} 禁止非 none 阴影壳"
            );
            $this->assertStringContainsString(
                'background: transparent',
                $block,
                basename($path) . " {$selector} 须透明底"
            );
        }
    }

    public function testAccountLayoutsLargeScreenSpacingUsesThemeTokenFallbacks(): void
    {
        $base = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/account';
        $files = [
            $base . '/dashboard.phtml',
            $base . '/default.phtml',
        ];

        foreach ($files as $path) {
            $this->assertFileExists($path);
            $content = (string) file_get_contents($path);
            $label = basename($path);
            // 前台不加载 theme.css 时 --weline-layout-spacing-* 未定义；无 fallback 大屏 gap/padding 会为 0。
            $this->assertStringContainsString(
                'gap: var(--weline-layout-spacing-xl, var(--spacing-xl, 2rem));',
                $content,
                $label . ' 大屏 gap 须带 spacing-xl/2rem fallback'
            );
            $this->assertStringContainsString(
                'padding-inline: var(--weline-layout-content-padding-inline);',
                $content,
                $label . ' 版心水平 padding 须用 content-padding-inline'
            );
            $this->assertStringNotContainsString(
                'gap: var(--weline-layout-spacing-xl);',
                $content,
                $label . ' 禁止无 fallback 的 layout-spacing-xl gap'
            );
            $this->assertStringNotContainsString(
                'padding: var(--weline-layout-spacing-xl);',
                $content,
                $label . ' 禁止无 fallback 的 layout-spacing-xl padding'
            );
        }
    }

    public function testAccountDashboardRendersTheControllerOwnedDocumentTitle(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/account/dashboard.phtml';

        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString("\$this->getData('page_title')", $content);
        $this->assertStringContainsString("\$this->setData('__weline_frontend_final_title', \$pageTitle);", $content);
        $this->assertStringContainsString("<title><?= htmlspecialchars(\$pageTitle", $content);
        $this->assertStringContainsString("__('个人中心')", $content);
    }

    public function testAccountLayoutsDoNotRenderBreadcrumb(): void
    {
        $base = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/account';
        $files = [
            $base . '/default.phtml',
            $base . '/dashboard.phtml',
        ];

        foreach ($files as $path) {
            $this->assertFileExists($path);
            $content = (string) file_get_contents($path);

            $this->assertStringNotContainsString('account-dashboard__breadcrumb', $content);
            $this->assertStringNotContainsString('type="breadcrumb"', $content);
            $this->assertStringNotContainsString('Weline_Theme::frontend::layouts::account::breadcrumb-before', $content);
            $this->assertStringNotContainsString('Weline_Theme::frontend::layouts::account::breadcrumb-after', $content);
        }
    }

    public function testAccountLayoutsKeepContentFallbackHosts(): void
    {
        $base = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/account';
        $files = [
            $base . '/default.phtml',
            $base . '/dashboard.phtml',
        ];

        foreach ($files as $path) {
            $this->assertFileExists($path);
            $content = (string) file_get_contents($path);

            $this->assertStringContainsString('{{meta.content}}', $content);
            $this->assertStringContainsString('{{content}}', $content);
            $this->assertStringContainsString('meta.contentTemplate', $content);
            $this->assertStringContainsString('contentTemplate', $content);
            $this->assertStringContainsString("getChildHtml('content')", $content);
        }
    }
}
