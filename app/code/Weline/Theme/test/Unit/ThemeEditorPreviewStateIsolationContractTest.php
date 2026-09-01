<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeEditorPreviewStateIsolationContractTest extends TestCase
{
    public function testEditorShellClearsStoredPreviewTokenBeforeRendering(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Controller/Backend/ThemeEditor.php'
        );

        self::assertMatchesRegularExpression(
            "/'shell'\\s*=>\\s*PreviewContextService::SHELL_THEME_EDITOR,\\s*"
            . "(?:\\/\\/[^\\n]*\\n\\s*)*"
            . "(?:\\/\\*.*?\\*\\/\\s*)?"
            . "'preview_token'\\s*=>\\s*'',/s",
            $source,
            'The top-level editor context must discard preview state left in the session.',
        );
    }

    public function testEditorIframeDoesNotMintLivePreviewToken(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Controller/Backend/ThemeEditor.php'
        );

        self::assertStringContainsString(
            "\$this->assign('initial_preview_token', '');",
            $source,
            'Editor iframe must not mint weline_preview_token; only #btnFrontendPreview / postStartPreview may.',
        );

        $indexStart = strpos($source, 'function index(');
        $assignPos = strpos(
            $source,
            "\$this->assign('initial_preview_token', '');",
            $indexStart ?: 0,
        );
        self::assertNotFalse($indexStart);
        self::assertNotFalse($assignPos);

        $indexBlock = substr($source, (int)$indexStart, (int)$assignPos - (int)$indexStart + 80);
        self::assertStringNotContainsString('generateToken(', $indexBlock);
        self::assertStringNotContainsString('setPreviewCookie(', $indexBlock);
        self::assertStringNotContainsString('withPreviewToken(', $indexBlock);
    }

    public function testWidgetPreviewHtmlIsolatesParseErrorsPerWidget(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Controller/Backend/ThemeEditor.php'
        );

        self::assertStringContainsString('function buildWidgetPreviewHtml(', $source);
        self::assertStringContainsString('widget-preview-error', $source);
        self::assertMatchesRegularExpression(
            '/catch\s*\(\s*\\\\Throwable\s+\$e\s*\)\s*\{[^}]*widget-preview-error/s',
            $source,
            'Widget library preview must catch Throwable (including ParseError) per widget.',
        );
    }

    public function testStartPreviewRemainsTheOnlyLivePreviewEntry(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/Controller/Backend/ThemeEditor.php'
        );

        self::assertStringContainsString('function postStartPreview()', $source);
        self::assertStringContainsString('setPreviewCookie($token)', $source);
        self::assertStringContainsString('SHELL_PREVIEW', $source);
        self::assertStringContainsString('buildFrontendPreviewUrl(', $source);
    }
}
