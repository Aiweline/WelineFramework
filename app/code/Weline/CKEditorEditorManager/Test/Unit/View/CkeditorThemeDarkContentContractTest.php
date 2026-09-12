<?php

declare(strict_types=1);

namespace Weline\CKEditorEditorManager\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CkeditorThemeDarkContentContractTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testBlockTemplateLoadsThemeBridgeStylesheet(): void
    {
        $html = (string) file_get_contents($this->root() . '/view/blocks/ckeditor.html');
        self::assertStringContainsString(
            'Weline_CKEditorEditorManager::css/ckeditor-theme.css',
            $html
        );
    }

    public function testThemeCssForcesLightContentIslandAndDarkChrome(): void
    {
        $cssPath = $this->root() . '/view/statics/css/ckeditor-theme.css';
        self::assertFileExists($cssPath);
        $css = (string) file_get_contents($cssPath);

        self::assertStringContainsString('color-scheme: light', $css);
        self::assertStringContainsString('--weline-theme-text: #182230', $css);
        self::assertStringContainsString('background-color: #ffffff', $css);
        self::assertStringContainsString('.ck-editor__editable', $css);
        self::assertStringContainsString('.ck-content', $css);
        self::assertStringContainsString('html[data-theme="dark"] .ck.ck-editor', $css);
        self::assertStringContainsString('--ck-color-toolbar-background: var(--weline-theme-surface)', $css);
        self::assertStringContainsString('.ck-content h1', $css);
        self::assertStringContainsString('.ck.ck-toolbar .ck-button', $css);
        self::assertStringContainsString('w-product-description-editor:has(.ck.ck-editor) > textarea.w-input', $css);
        self::assertStringContainsString('.ck-icon__fill', $css);
        self::assertStringContainsString('fill: var(--weline-theme-text) !important', $css);
    }
}
