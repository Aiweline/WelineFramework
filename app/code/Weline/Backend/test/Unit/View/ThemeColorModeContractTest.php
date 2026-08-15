<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ThemeColorModeContractTest extends TestCase
{
    public function testBackendPersistsOnlySupportedPreferencesAndKeepsLegacyCompatibility(): void
    {
        self::assertSame('system', $this->invoke(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [[]]));
        self::assertSame('system', $this->invoke(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['theme-mode-switch' => 'system']]));
        self::assertSame('light', $this->invoke(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['theme-mode-switch' => 'light']]));
        self::assertSame('dark', $this->invoke(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['theme-mode-switch' => 'dark']]));
        self::assertSame('light', $this->invoke(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['dark-mode-switch' => 'false']]));
        self::assertSame('dark', $this->invoke(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['dark-mode-switch' => 'true']]));
        self::assertSame('dark', $this->invoke(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['light-mode-switch' => 'false']]));
        self::assertSame('light', $this->invoke(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['theme-mode-switch' => 'unsupported']]));
        self::assertSame('light', $this->invoke(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['theme-mode-switch' => false]]));
        self::assertSame('system', $this->invoke(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['layouts' => ['data-theme-preference' => 'system']]]));
        self::assertSame('dark', $this->invoke(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['layouts' => ['data-theme-preference' => 'dark']]]));
        self::assertSame('system', $this->invoke(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [[
            'dark-mode-switch' => true,
            'layouts' => ['data-theme-preference' => 'system', 'data-theme-mode' => 'dark'],
        ]]));

        $systemPayload = $this->invoke(\Weline\Backend\Controller\ThemeConfig\Set::class, 'normalizeThemePayload', [[
            'theme-mode-switch' => 'system',
            'layouts' => [
                'data-theme-mode' => 'dark',
                'data-layout-mode' => 'dark',
                'data-topbar' => 'dark',
                'data-sidebar' => 'dark',
            ],
        ]]);
        self::assertSame('system', $systemPayload['theme-mode-switch']);
        foreach (['data-theme-mode', 'data-layout-mode', 'data-topbar', 'data-sidebar'] as $resolvedAttribute) {
            self::assertArrayNotHasKey($resolvedAttribute, $systemPayload['layouts']);
        }
        self::assertSame('light', $this->invoke(\Weline\Backend\Controller\ThemeConfig\Set::class, 'normalizeThemePayload', [['dark-mode-switch' => 'false']])['theme-mode-switch']);
        self::assertSame('dark', $this->invoke(\Weline\Backend\Controller\ThemeConfig\Set::class, 'normalizeThemePayload', [['dark-mode-switch' => 'true']])['theme-mode-switch']);
        self::assertSame('system', $this->invoke(\Weline\Backend\Controller\ThemeConfig\Set::class, 'normalizeThemePayload', [[
            'dark-mode-switch' => true,
            'layouts' => ['data-theme-preference' => 'system', 'data-theme-mode' => 'dark'],
        ]])['theme-mode-switch']);
        self::assertSame('dark', $this->invoke(\Weline\Backend\Controller\ThemeConfig\Set::class, 'normalizeThemePayload', [[
            'layouts' => ['data-theme-preference' => 'dark'],
        ]])['theme-mode-switch']);

        $themeConfigSource = $this->read('app/code/Weline/Backend/Block/ThemeConfig.php');
        self::assertStringContainsString('public function getThemeHtmlAttributes(): string;', $this->read('app/code/Weline/Backend/Api/View/BackendThemeConfigInterface.php'));
        self::assertStringContainsString("'data-theme=\"' . \$resolvedMode", $themeConfigSource);
        self::assertLessThan(
            strpos($themeConfigSource, "if (!empty(\$themeConfig['rtl-mode-switch']))"),
            strpos($themeConfigSource, "if (\$themeMode === 'dark')")
        );
        $this->expectException(\InvalidArgumentException::class);
        $this->invoke(\Weline\Backend\Controller\ThemeConfig\Set::class, 'normalizeThemePayload', [['theme-mode-switch' => 'invalid']]);
    }

    public function testResolvedSystemAttributeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->invoke(\Weline\Backend\Controller\ThemeConfig\Set::class, 'normalizeThemePayload', [[
            'layouts' => ['data-theme-mode' => 'system'],
        ]]);
    }

    /** @dataProvider invalidDirectThemeConfigValues */
    public function testDirectThemeConfigRejectsEveryExplicitInvalidLayoutMode(array $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->invoke(\Weline\Backend\Block\ThemeConfig::class, 'assertThemeModePayload', [$payload]);
    }

    /** @return iterable<string, array{0:array<string,mixed>}> */
    public static function invalidDirectThemeConfigValues(): iterable
    {
        yield 'empty preference' => [['layouts' => ['data-theme-preference' => '']]];
        yield 'null preference' => [['layouts' => ['data-theme-preference' => null]]];
        yield 'whitespace resolved mode' => [['layouts' => ['data-theme-mode' => ' ']]];
        yield 'empty layout mode' => [['layouts' => ['data-layout-mode' => '']]];
        yield 'layouts null' => [['layouts' => null]];
        yield 'layouts empty string' => [['layouts' => '']];
        yield 'layouts scalar' => [['layouts' => 'dark']];
    }

    /** @dataProvider invalidExplicitThemeValues */
    public function testEveryExplicitThemeEntryRejectsEmptyAndNonStringValues(array $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->invoke(\Weline\Backend\Controller\ThemeConfig\Set::class, 'normalizeThemePayload', [$payload]);
    }

    /** @return iterable<string, array{0:array<string,mixed>}> */
    public static function invalidExplicitThemeValues(): iterable
    {
        yield 'switch null' => [['theme-mode-switch' => null]];
        yield 'switch empty' => [['theme-mode-switch' => '']];
        yield 'switch whitespace' => [['theme-mode-switch' => '   ']];
        yield 'switch array' => [['theme-mode-switch' => ['dark']]];
        yield 'layouts null' => [['layouts' => null]];
        yield 'layouts empty string' => [['layouts' => '']];
        yield 'layouts scalar' => [['layouts' => 'dark']];
        yield 'preference array' => [['layouts' => ['data-theme-preference' => ['system']]]];
        yield 'resolved system' => [['layouts' => ['data-theme-mode' => 'system']]];
        yield 'resolved invalid' => [['layouts' => ['data-layout-mode' => 'purple']]];
        yield 'resolved object' => [['layouts' => ['data-layout-mode' => new \stdClass()]]];
    }

    private function invoke(string $class, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionClass($class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $target = $reflection->getMethod($method);
        $target->setAccessible(true);
        return $target->invokeArgs($instance, $arguments);
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BP . '/' . $path);
        self::assertIsString($content, $path . ' must be readable');

        return $content;
    }
}
