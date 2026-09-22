<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: theme-editor「返回」stores document.referrer on data-return-href and navigates on click.
 * When referrer equals the current URL, or is itself a theme-editor URL, fall back to the theme-list href.
 */
final class ThemeEditorBackReturnUrlContractTest extends TestCase
{
    public function testEditorBackUsesReferrerAttribute(): void
    {
        $editor = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/templates/backend/ThemeEditor/index.phtml'
        );
        self::assertStringContainsString('id="themeEditorBackLink"', $editor);
        self::assertStringContainsString('document.referrer', $editor);
        self::assertStringContainsString("setAttribute('data-return-href', returnHref)", $editor);
        self::assertStringContainsString('returnHref === currentHref', $editor);
        self::assertStringContainsString('theme-editor', $editor);
        self::assertStringContainsString("getAttribute('data-return-href')", $editor);
        self::assertStringContainsString('window.location.assign(target)', $editor);
        self::assertStringNotContainsString('sessionStorage', $editor);
        self::assertStringNotContainsString('history.back', $editor);
    }
}
