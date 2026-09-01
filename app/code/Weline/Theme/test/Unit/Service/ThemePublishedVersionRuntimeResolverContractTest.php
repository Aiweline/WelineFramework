<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ThemePublishedVersionRuntimeResolverContractTest extends TestCase
{
    public function testResolverUsesThemeLayoutVersionNotScopeReleaseReason(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/ThemePublishedVersionRuntimeResolver.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('ThemeLayoutVersionService', $src);
        self::assertStringContainsString('getPublishedVersion', $src);
        self::assertStringContainsString('getDisplayName', $src);
        self::assertStringNotContainsString('ThemeRuntimeLayoutResolver', $src);
        self::assertStringContainsString('default.__website__.default', $src);
        self::assertMatchesRegularExpression('/版本权威来源为 theme_layout_version/', $src);
    }
}
