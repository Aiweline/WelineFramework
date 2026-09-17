<?php

declare(strict_types=1);

namespace Weline\I18n\test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

final class LocalTranslationTriggerClampContractTest extends TestCase
{
    public function testTriggerTextUsesThreeLineClampAndAllowsWrap(): void
    {
        $css = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/local-translation.css',
        );
        $taglib = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Taglib/Local.php',
        );

        self::assertStringContainsString('Tag-level affordance: light primary wash', $css);
        self::assertStringContainsString('w-local-translation__trigger-text', $css);
        self::assertStringContainsString('-webkit-line-clamp: 3', $css);
        self::assertStringContainsString('line-clamp: 3', $css);
        self::assertStringContainsString('white-space: normal', $css);
        self::assertStringContainsString('inline-size: 100%', $css);
        self::assertStringContainsString('Override .w-button inline-flex/nowrap', $css);

        self::assertStringContainsString('w-local-translation__trigger-text', $taglib);
        self::assertStringContainsString('<div class="w-local-translation__wrap"', $taglib);
        self::assertStringContainsString('<div class="w-local-translation__trigger-text">', $taglib);
        self::assertStringNotContainsString('<span class="w-local-translation__wrap"', $taglib);
        self::assertStringContainsString('20260916-trigger-bg2', $taglib);
        self::assertStringContainsString('[data-tone="quiet"]', $css);
    }
}
