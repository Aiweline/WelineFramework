<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeIndexPreviewInteractionTest extends TestCase
{
    private string $template;
    private string $activeScript;

    protected function setUp(): void
    {
        $templatePath = dirname(__DIR__, 6) . '/app/code/Weline/Theme/view/templates/backend/index.phtml';
        $this->template = (string)file_get_contents($templatePath);
        $scriptOffset = strpos($this->template, "const THEME_EDITOR_URL =");
        self::assertIsInt($scriptOffset);
        $this->activeScript = substr($this->template, $scriptOffset);
    }

    public function testSinglePreviewAcceptsFrameworkSuccessPayload(): void
    {
        self::assertStringContainsString('function isSuccessResponse(result)', $this->activeScript);
        self::assertStringContainsString('if (!isSuccessResponse(result)) {', $this->activeScript);
        self::assertStringContainsString('result.image_url', $this->activeScript);
        self::assertStringContainsString('function updateThemeCardPreview(card, imageUrl', $this->activeScript);
        self::assertMatchesRegularExpression(
            '/updateThemeCardPreview\(\s*card,\s*result\.image_url\s*\|\|\s*\'\'/',
            $this->activeScript
        );
    }

    public function testModalDismissControlsWorkWithFallbackAdapter(): void
    {
        self::assertStringContainsString('data-w-action="dialog.close"', $this->template);
        self::assertStringContainsString('hide: function() { return Weline.UI.dialog.close(element); }', $this->activeScript);
    }

    public function testThemeCardWebsiteSwitcherPatchesPreviewAndEditUrls(): void
    {
        self::assertStringContainsString('data-website-bindings', $this->template);
        self::assertStringContainsString('w:websites:website:select', $this->template);
        self::assertStringContainsString('theme-preview-link', $this->template);
        self::assertStringContainsString("weline.theme.list.website.", $this->activeScript);
        self::assertStringContainsString('function applyThemeCardWebsite(', $this->activeScript);
        self::assertStringContainsString('function patchThemeUrlWithWebsite(', $this->activeScript);
        self::assertStringContainsString("card.getAttribute('data-edit-href')", $this->activeScript);
        self::assertStringContainsString('[data-theme-card-website-select]', $this->activeScript);
    }

    public function testPreviewAndEditShareSingleBindingDirectJump(): void
    {
        self::assertStringContainsString('function resolveThemeCardWebsiteScope(', $this->activeScript);
        self::assertStringContainsString('function ensureThemeCardWebsiteScope(', $this->activeScript);
        self::assertStringContainsString('function openThemeCardPreview(', $this->activeScript);
        self::assertStringContainsString('function openThemeCardEditor(', $this->activeScript);
        self::assertStringContainsString('if (bindings.length === 1)', $this->activeScript);
        self::assertStringContainsString("document.querySelectorAll('.theme-card .theme-preview-link')", $this->activeScript);
        self::assertStringContainsString("document.querySelectorAll('.theme-card .theme-edit-link')", $this->activeScript);
        self::assertStringContainsString('openThemeCardPreview(card)', $this->activeScript);
        self::assertStringContainsString('openThemeCardEditor(card)', $this->activeScript);
    }
}
