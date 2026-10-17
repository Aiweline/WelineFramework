<?php

declare(strict_types=1);

namespace Weline\CKEditorEditorManager\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCKEditorEditorManagerViewContractsPass().
 */
final class CKEditorEditorManagerViewContractsTest extends TestCase
{
    public function testAllCKEditorEditorManagerViewContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'CkeditorThemeDarkContent::testBlockTemplateLoadsThemeBridgeStylesheet' => function () {
            $this->assert_CkeditorThemeDarkContent_testBlockTemplateLoadsThemeBridgeStylesheet();
        },
            'CkeditorThemeDarkContent::testThemeCssForcesLightContentIslandAndDarkChrome' => function () {
            $this->assert_CkeditorThemeDarkContent_testThemeCssForcesLightContentIslandAndDarkChrome();
        },
        ];
    }

private function root_CkeditorThemeDarkContent(): string
{
    return '/Users/weline/Project/Official/框架/app/code/Weline/CKEditorEditorManager';
}

private function assert_CkeditorThemeDarkContent_testBlockTemplateLoadsThemeBridgeStylesheet(): void
{
    $html = (string) file_get_contents($this->root_CkeditorThemeDarkContent() . '/view/blocks/ckeditor.html');
    self::assertStringContainsString('Weline_CKEditorEditorManager::css/ckeditor-theme.css', $html);
}

private function assert_CkeditorThemeDarkContent_testThemeCssForcesLightContentIslandAndDarkChrome(): void
{
    $cssPath = $this->root_CkeditorThemeDarkContent() . '/view/statics/css/ckeditor-theme.css';
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
