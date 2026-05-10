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

    public function testAccountAuthLayoutAllowsControllerToHideHeaderAndFooter(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/account/auth.phtml';

        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);
        $this->assertStringContainsString('<if condition="meta.showHeader">', $content);
        $this->assertStringContainsString('<if condition="meta.showFooter">', $content);
        $this->assertStringNotContainsString('$meta[\'showHeader\'] = true;', $content);
        $this->assertStringNotContainsString('$meta[\'showFooter\'] = true;', $content);
    }

    public function testAccountDashboardMainContentUsesPageWidthContainer(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/layouts/account/dashboard.phtml';

        $this->assertFileExists($path);
        $content = (string) file_get_contents($path);
        $this->assertStringContainsString('.account-main-content {', $content);
        $this->assertStringContainsString('max-width: var(--layout-max-width, 1600px);', $content);
        $this->assertStringContainsString('margin: 0 auto;', $content);
        $this->assertStringContainsString('<main class="account-main-content', $content);
        $this->assertStringNotContainsString('.account-dashboard__body {', $content);
    }
}
