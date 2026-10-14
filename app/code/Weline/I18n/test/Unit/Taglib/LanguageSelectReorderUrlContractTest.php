<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

/**
 * LanguageSelect multi-tag drag reorder: reorder-url attr → data-w-reorder-url + JS save.
 */
final class LanguageSelectReorderUrlContractTest extends TestCase
{
    public function testTaglibExposesReorderUrlAttributes(): void
    {
        $taglib = \file_get_contents(BP . '/app/code/Weline/I18n/Taglib/LanguageSelect.php');
        self::assertIsString($taglib);
        self::assertStringContainsString("'reorder-url' => false", $taglib);
        self::assertStringContainsString("'reorder-website-id' => false", $taglib);
        self::assertStringContainsString('Taglib__reorder_url', $taglib);
        self::assertStringContainsString('__wls_reorder_url', $taglib);
    }

    public function testMarkupAndRuntimeWireReorderDataset(): void
    {
        $markup = \file_get_contents(
            BP . '/app/code/Weline/I18n/view/templates/taglib/language-select-markup.phtml'
        );
        self::assertIsString($markup);
        self::assertStringContainsString('data-w-reorder-url', $markup);
        self::assertStringContainsString('data-w-reorder-website-id', $markup);
        // Trigger is a div[role=button] so HTML5 DnD on capsules works; remove controls may still be <button>.
        self::assertStringContainsString('role="button"', $markup);
        self::assertMatchesRegularExpression(
            '/<div\\s+class="w-language-select__trigger"/',
            $markup
        );
        self::assertStringNotContainsString('<button\n        class="w-language-select__trigger"', $markup);
        self::assertStringContainsString('w-language-select__tag', $markup);
        self::assertStringContainsString('w-language-select__drag-handle', $markup);

        $js = \file_get_contents(BP . '/app/code/Weline/I18n/view/statics/js/language-select.js');
        $css = \file_get_contents(BP . '/app/code/Weline/I18n/view/statics/css/language-select.css');
        self::assertIsString($js);
        self::assertIsString($css);
        self::assertStringContainsString('is-sortable', $js);
        self::assertStringContainsString('syncSelectOptionOrder', $js);
        self::assertStringContainsString('scheduleReorderSave', $js);
        self::assertStringContainsString('resolveReorderUrl', $js);
        self::assertStringContainsString('w-language-select__drag-handle', $js);
        self::assertStringContainsString('elementFromPoint', $js);
        self::assertStringContainsString('event.target instanceof Element ? event.target : null', $js);
        self::assertStringContainsString('insertBefore(dragging', $js);
        self::assertStringContainsString("dropEffect = 'move'", $js);
        self::assertStringContainsString('cursor: grab', $css);
        self::assertStringContainsString('w-language-select__drag-handle', $css);
        self::assertMatchesRegularExpression(
            '/\.w-language-select__tag\.is-dragging\s*\{[^}]*pointer-events:\s*none/s',
            $css
        );
        // :active 不得带 pointer-events:none，否则按下瞬间取消 HTML5 dragstart（拖不动）
        self::assertDoesNotMatchRegularExpression(
            '/\.w-language-select__tag\.is-sortable:active[^{]*\{[^}]*pointer-events:\s*none/s',
            $css
        );
        self::assertStringContainsString("closest('.w-language-select__tag.is-sortable')", $js);
        self::assertStringContainsString('listen(tags, \'pointerdown\'', $js);
    }
}
