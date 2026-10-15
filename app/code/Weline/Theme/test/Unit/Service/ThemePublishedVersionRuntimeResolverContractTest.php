<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ThemePublishedVersionRuntimeResolverContractTest extends TestCase
{
    public function testResolverUsesThemeScopeVersionNotThemeLayoutVersionPageAxis(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/ThemePublishedVersionRuntimeResolver.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        // 版本权威已切到 ThemeScopeVersion 的 owner+V 选择，不再走 theme_layout_version 页面轴。
        self::assertStringContainsString('ThemeScopeVersionService', $src);
        self::assertStringContainsString('getPublished(', $src);
        self::assertStringContainsString('getVersionName()', $src);
        self::assertStringNotContainsString('ThemeLayoutVersionService', $src);
        self::assertStringNotContainsString('ThemeRuntimeLayoutResolver', $src);
        self::assertStringContainsString('default.__website__.default', $src);
        // Channel storage must come from ScopeIdentity::channel + hierarchy — never a literal.
        self::assertStringNotContainsString('default.__store__.__channel__', $src);
        self::assertStringContainsString('ScopeIdentity::channel', $src);
        self::assertStringContainsString('pushFrontendBoundChannelFallbackScopes', $src);
        self::assertMatchesRegularExpression('/Authority is ThemeScopeVersion/', $src);
        self::assertStringContainsString('resolveGenerationIdentity', $src);
        self::assertStringContainsString('not system deploy_version', $src);
        self::assertStringContainsString('collectAcceptableStorefrontGenerationIds', $src);
        self::assertStringContainsString('storefrontScopeProbeList', $src);
        self::assertStringContainsString('ThemeApplicationUsageService', $src);
    }
}
