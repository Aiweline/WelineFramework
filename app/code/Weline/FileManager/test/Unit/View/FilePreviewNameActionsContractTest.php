<?php

declare(strict_types=1);

namespace Weline\FileManager\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Selected media chips must show a filename caption and keep hover remove inside the card.
 * Audio/file chips use a horizontal track layout (not a floating centered action pill).
 */
final class FilePreviewNameActionsContractTest extends TestCase
{
    public function testCssReservesCaptionAndInCardActions(): void
    {
        $css = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/css/file-picker.css'
        );
        self::assertStringContainsString('.w-file-preview__name', $css);
        self::assertStringContainsString('.w-file-preview__media', $css);
        self::assertStringContainsString('line-clamp: 2', $css);
        self::assertStringContainsString('z-index: 2', $css);
        self::assertStringContainsString('pointer-events: none', $css);
        self::assertStringContainsString('@media (hover: none), (pointer: coarse)', $css);
        self::assertStringContainsString('block-size: auto', $css);
        self::assertStringContainsString('.w-file-preview__item[data-kind="audio"]', $css);
        self::assertStringContainsString('flex-direction: row', $css);
        self::assertStringContainsString('position: static', $css);
        self::assertStringContainsString('.w-file-picker__actions', $css);
        self::assertStringContainsString('data-w-file-picker-open', $css);
        self::assertStringContainsString('inline-size: auto', $css);
    }

    public function testJsCreatesNameAndMediaWrap(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/file-picker.js'
        );
        self::assertStringContainsString('function displayNameFromPath', $js);
        self::assertStringContainsString("name.className = 'w-file-preview__name'", $js);
        self::assertStringContainsString("media.className = 'w-file-preview__media'", $js);
        self::assertStringContainsString('item.append(media, name, actions)', $js);
        self::assertStringContainsString('media.contains(actions)', $js);
        self::assertStringContainsString('nameEl.after(actions)', $js);
    }

    public function testJsResolvesImageKindWithoutDemotingUuidPaths(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/file-picker.js'
        );
        self::assertStringContainsString('function resolveItemPreviewKind', $js);
        self::assertStringContainsString('function fileFormatBadge', $js);
        self::assertStringContainsString('function fileKindGlyphText', $js);
        self::assertStringContainsString('resolveItemPreviewKind(item, path)', $js);
        // Mount upgrade must not prefer extensionless UUID over declared image / file-image node.
        self::assertStringNotContainsString(
            'const kind = previewKindFromPath(path) || item.dataset.kind || \'file\'',
            $js
        );
        self::assertStringContainsString("glyph.dataset.kind = 'image'", $js);
        self::assertStringContainsString('fileFormatBadge(path', $js);
    }

    public function testMediaManagerBlockRendersNameInsideCard(): void
    {
        $tpl = (string)file_get_contents(
            dirname(__DIR__, 4) . '/MediaManager/view/blocks/weline-media.phtml'
        );
        self::assertStringContainsString('w-file-preview__media', $tpl);
        self::assertStringContainsString('w-file-preview__name', $tpl);
        self::assertStringContainsString('title="{{v.name}}"', $tpl);
        self::assertStringContainsString('>{{v.name}}</span>', $tpl);
        self::assertStringContainsString('w-file-preview__actions', $tpl);
        self::assertStringContainsString('w-file-picker__actions', $tpl);
        self::assertStringContainsString('data-align="start"', $tpl);
        self::assertStringContainsString('data-w-file-picker-open', $tpl);
        self::assertStringNotContainsString('pathInfo.name', $tpl);
        // Actions must be siblings of media+name (not nested inside media overlay).
        self::assertMatchesRegularExpression(
            '/w-file-preview__name[\s\S]*?w-file-preview__actions/',
            $tpl
        );
    }
}
