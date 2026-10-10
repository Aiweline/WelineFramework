<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Taglib\ThemeSelect;
use Weline\Theme\Taglib\ThemeVersionSelect;

final class ThemeSelectContractTest extends TestCase
{
    public function testThemeSelectNameAttrsAndSearchSelectBridge(): void
    {
        self::assertSame('theme:select', ThemeSelect::name());
        self::assertTrue(ThemeSelect::attr()['id']);
        self::assertArrayHasKey('options-json', ThemeSelect::attr());
        self::assertArrayHasKey('allow-empty', ThemeSelect::attr());
        self::assertTrue(ThemeSelect::tag_self_close());
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Taglib/ThemeSelect.php');
        self::assertStringContainsString('SearchSelect::buildMarkup', $src);
        self::assertStringContainsString('listFrontendThemes', $src);
        self::assertStringContainsString('不修改（保持当前）', $src);
    }

    public function testThemeVersionSelectNameAttrsAndSearchSelectBridge(): void
    {
        self::assertSame('theme:version:select', ThemeVersionSelect::name());
        self::assertTrue(ThemeVersionSelect::attr()['id']);
        self::assertArrayHasKey('theme-id', ThemeVersionSelect::attr());
        self::assertArrayHasKey('scope', ThemeVersionSelect::attr());
        self::assertArrayHasKey('options-json', ThemeVersionSelect::attr());
        self::assertTrue(ThemeVersionSelect::tag_self_close());
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Taglib/ThemeVersionSelect.php');
        self::assertStringContainsString('SearchSelect::buildMarkup', $src);
        self::assertStringContainsString('listVersions', $src);
    }

    public function testScopeBindingPartialUsesThemeSelectTaglibs(): void
    {
        $partial = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/templates/backend/partials/scope-theme-binding-section.phtml'
        );
        self::assertStringContainsString('<w:theme:select', $partial);
        self::assertStringContainsString('<w:theme:version:select', $partial);
        self::assertStringContainsString('extensions[theme][theme_id]', $partial);
        self::assertStringContainsString('extensions[theme][version_id]', $partial);
        self::assertStringNotContainsString('<select class="w-select"', $partial);
    }
}
