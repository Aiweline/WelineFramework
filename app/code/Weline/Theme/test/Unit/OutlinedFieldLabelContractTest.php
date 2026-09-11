<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 标准 w-field 默认 Outlined notch；Theme Input/FormGroup 组件须走同一套 DOM。
 */
final class OutlinedFieldLabelContractTest extends TestCase
{
    private function foundation(): string
    {
        $path = dirname(__DIR__, 2) . '/view/ui/css/foundation.css';
        $css = (string)file_get_contents($path);
        self::assertNotSame('', $css);

        return $css;
    }

    public function testOutlinedNotchLabelIsDefaultForLabeledFields(): void
    {
        $css = $this->foundation();

        self::assertStringContainsString('w-field--outlined-notch', $css);
        self::assertMatchesRegularExpression(
            '/\.w-field:has\(>\s*\.w-field__label\):has\([^)]*(?:\.w-input|\.w-select|\.w-textarea)/',
            $css
        );
        self::assertStringContainsString('position: absolute', $css);
        self::assertStringContainsString('translate: 0 -50%', $css);
        self::assertStringContainsString('inset-block-start: 0', $css);
        self::assertStringContainsString(
            'background: var(--weline-theme-field-label-bg, var(--weline-theme-surface))',
            $css
        );
        self::assertStringContainsString('font-size: var(--weline-font-size-xs)', $css);
        self::assertStringContainsString(':placeholder-shown', $css);
        self::assertStringContainsString('[data-floating-label="0"]', $css);
        self::assertStringContainsString('inset-block-start: 50%', $css);
        self::assertStringContainsString('display: inline-flex', $css);
        self::assertStringContainsString('-webkit-text-fill-color: transparent', $css);
        self::assertStringContainsString('opacity: 0', $css);
        self::assertStringContainsString(':focus-within :is(.w-input, .w-textarea)::placeholder', $css);
    }

    public function testSelectChevronIsInsetFromTrailingEdge(): void
    {
        $css = $this->foundation();
        self::assertStringContainsString('appearance: none', $css);
        self::assertStringContainsString('background-position: right var(--weline-space-3) center', $css);
        self::assertStringContainsString('padding-inline-end: calc(var(--weline-space-3) + 1.15rem)', $css);
    }

    public function testOutlinedNotchExcludesSearchCheckSwitchAndExplicitLayouts(): void
    {
        $css = $this->foundation();

        self::assertStringContainsString('[data-label-layout="stacked"]', $css);
        self::assertStringContainsString('[data-label-layout="inline"]', $css);
        self::assertStringContainsString('.w-theme-disk-field--inline', $css);
        self::assertStringContainsString(':has(> .w-check)', $css);
        self::assertStringContainsString(':has(> .w-switch)', $css);
        self::assertStringContainsString('.w-search-inline', $css);
        self::assertStringContainsString('.w-search-bar', $css);
    }

    public function testInputComponentUsesStandardWFieldOutlinedMarkup(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/components/input.phtml';
        $src = (string)file_get_contents($path);
        self::assertNotSame('', $src);
        self::assertStringContainsString('class="w-field', $src);
        self::assertStringContainsString('w-field__label', $src);
        self::assertStringContainsString('w-input', $src);
        self::assertStringContainsString('w-field__hint', $src);
        self::assertStringNotContainsString('w-form-label input-label', $src);
    }

    public function testFormGroupComponentUsesStandardWFieldOutlinedMarkup(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/components/form-group.phtml';
        $src = (string)file_get_contents($path);
        self::assertNotSame('', $src);
        self::assertStringContainsString('class="w-field', $src);
        self::assertStringContainsString('w-field__label', $src);
        self::assertStringContainsString('w-field__control', $src);
        self::assertStringContainsString('w-field__hint', $src);
        self::assertStringNotContainsString('w-form-label form-group-label', $src);
    }
}
