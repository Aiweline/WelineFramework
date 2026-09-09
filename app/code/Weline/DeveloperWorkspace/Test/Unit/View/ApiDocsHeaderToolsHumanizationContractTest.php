<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * API docs header tools: settings vs actions grouping + secondary docs CTA.
 * Live CSS is Theme `weline-developer-api.css`; `api-docs.css` keeps parity.
 */
final class ApiDocsHeaderToolsHumanizationContractTest extends TestCase
{
    public function testHeaderSplitsSettingsAndActions(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Docs/api-manager.phtml';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('w-api-docs__tool-settings', $src);
        self::assertStringContainsString('w-api-docs__tool-actions', $src);
        self::assertStringContainsString('data-api-theme', $src);
        self::assertStringContainsString('data-api-production', $src);
        self::assertStringContainsString('data-api-action="open-login"', $src);
        self::assertStringContainsString('data-variant="outline"', $src);

        $settingsPos = strpos($src, 'w-api-docs__tool-settings');
        $actionsPos = strpos($src, 'w-api-docs__tool-actions');
        $themePos = strpos($src, 'data-api-theme');
        $loginPos = strpos($src, 'data-api-action="open-login"');
        self::assertNotFalse($settingsPos);
        self::assertNotFalse($actionsPos);
        self::assertNotFalse($themePos);
        self::assertNotFalse($loginPos);
        self::assertGreaterThan($settingsPos, $themePos);
        self::assertLessThan($actionsPos, $themePos);
        self::assertGreaterThan($actionsPos, $loginPos);
    }

    public function testCssAlignsToolGroupsWithControlHeight(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $themeRoot = dirname($moduleRoot) . '/Theme';
        $paths = [
            $themeRoot . '/view/statics/ui/pages/weline-developer-api.css',
            $moduleRoot . '/view/statics/css/api-docs.css',
        ];
        foreach ($paths as $path) {
            self::assertFileExists($path, $path);
            $src = (string) file_get_contents($path);
            self::assertStringContainsString('.w-api-docs__tool-group', $src, $path);
            self::assertStringContainsString('var(--weline-control-height)', $src, $path);
            self::assertStringContainsString('.w-api-docs__tools', $src, $path);
            self::assertMatchesRegularExpression(
                '/\.w-api-docs__tools\s*\{[^}]*gap:\s*var\(--weline-space-5\)/s',
                $src,
                $path
            );
            self::assertMatchesRegularExpression(
                '/\.w-api-docs__theme-field\s*\{[^}]*display:\s*inline-flex/s',
                $src,
                $path
            );
            self::assertMatchesRegularExpression(
                '/\.w-api-docs__tool-actions\s+\.w-button\[data-tone="neutral"\]\[data-variant="outline"\]/s',
                $src,
                $path
            );
        }
    }
}
