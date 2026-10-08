<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;

/**
 * theme_binding load/parent fallback must follow ThemeApplication scope chain,
 * not process-active theme (cross-site leak into 「恢复继承」).
 */
final class ThemeScopedBindingProjectorInheritContractTest extends TestCase
{
    public function testLoadResolvesViaThemeApplicationNotActiveTheme(): void
    {
        $path = dirname(__DIR__, 4) . '/Service/Scoped/ThemeScopedBindingProjector.php';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('ThemeApplicationInterface', $source);
        self::assertStringContainsString('DefaultThemeInterface', $source);
        self::assertStringContainsString('resolveThemeIdForScope', $source);
        self::assertStringContainsString('$this->applications->resolve(', $source);
        self::assertStringContainsString('normalizeApplicationStoreMode', $source);
        self::assertStringContainsString('defaultApplicationReference', $source);

        // load() delegates to resolveThemeIdForScope — no direct activeThemeId call.
        self::assertMatchesRegularExpression(
            '/function load\\(ThemeEditorContext \\$context\\): array\\s*\\{\\s*return \\[\\s*\'theme_id\' => \\$this->resolveThemeIdForScope\\(\\$context\\),/s',
            $source
        );
        // activeThemeId only allowed as last-resort fallback.
        self::assertMatchesRegularExpression(
            '/function fallbackModuleDefaultThemeId\\([\\s\\S]*?activeThemeId\\(/',
            $source
        );
        self::assertStringContainsString('Never use process-active theme', $source);
    }
}
