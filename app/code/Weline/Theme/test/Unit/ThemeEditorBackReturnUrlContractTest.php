<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: theme-editor「返回」stores document.referrer on data-return-href and navigates on click.
 */
final class ThemeEditorBackReturnUrlContractTest extends TestCase
{
    public function testEditorBackUsesReferrerAttribute(): void
    {
        $editor = (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/templates/backend/ThemeEditor/index.phtml'
        );
        self::assertStringContainsString('id="themeEditorBackLink"', $editor);
        self::assertStringContainsString("document.referrer", $editor);
        self::assertStringContainsString("setAttribute(\n                                'data-return-href'", $editor);
        self::assertStringContainsString("getAttribute('data-return-href')", $editor);
        self::assertStringContainsString('window.location.assign(target)', $editor);
        self::assertStringNotContainsString('sessionStorage', $editor);
        self::assertStringNotContainsString('history.back', $editor);
    }
}
