<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ThemeScopedReleaseBatchEditorContractTest extends TestCase
{
    public function testBothEditorBundlesPublishExactlyOneCompleteBatch(): void
    {
        $root = \dirname(__DIR__, 3);
        foreach ([
            '/view/statics/ui/pages/weline-theme-editor.js',
        ] as $relative) {
            $source = (string)\file_get_contents($root . $relative);
            $start = \strpos($source, 'async function publishLoadedScopedWorkspaces(');
            $end = \strpos($source, 'function renderThemeBindingOwnership()', (int)$start);
            self::assertNotFalse($start, $relative);
            self::assertNotFalse($end, $relative);
            $method = \substr($source, (int)$start, (int)$end - (int)$start);

            self::assertStringContainsString(
                "['theme_binding', 'layout', 'meta', 'appearance', 'i18n']",
                $method,
                $relative,
            );
            self::assertSame(1, \substr_count($method, 'apiJson(config.apiPublishScopedReleaseBatch'), $relative);
            self::assertStringContainsString('resources:', $method, $relative);
            self::assertStringContainsString('state.lastScopedReleaseBatch', $method, $relative);
            self::assertStringContainsString('cache_retryable', $method, $relative);
            self::assertStringContainsString('Always refresh before publish', $method, $relative);
            self::assertStringContainsString('theme_scope_revision_conflict', $method, $relative);
            self::assertStringContainsString('allowRetry', $method, $relative);
            self::assertStringNotContainsString('apiJson(config.apiPublishScopedWorkspace', $method, $relative);
        }
    }

    public function testBothEditorBundlesReadTheBatchEndpointDataset(): void
    {
        $root = \dirname(__DIR__, 3);
        foreach ([
            '/view/statics/ui/pages/weline-theme-editor.js',
        ] as $relative) {
            $source = (string)\file_get_contents($root . $relative);
            self::assertStringContainsString('container.dataset.apiPublishScopedReleaseBatch', $source, $relative);
        }
    }
}
