<?php

declare(strict_types=1);

namespace Weline\Feed\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Weline.UI.dialog moves an open dialog under <body>, outside the catalog root.
 * Dialog actions and dialog fields must keep working from there.
 */
final class FeedCatalogScriptContractTest extends TestCase
{
    private function script(): string
    {
        $path = \dirname(__DIR__, 2) . '/view/statics/backend/feed-catalog.js';
        self::assertFileExists($path);

        return (string)\file_get_contents($path);
    }

    public function testDialogActionsAreHandledOnTheDialogsThemselves(): void
    {
        $source = $this->script();

        self::assertMatchesRegularExpression(
            '/var clickSurfaces = \[formDialog, categoryDialog, disableDialog, archiveDialog, root\]/',
            $source,
            'dialogs must be listed before root so a nested dialog is handled once',
        );
        self::assertStringContainsString("surface.addEventListener('click', onClick);", $source);
        self::assertStringContainsString('if (owner !== event.currentTarget) {', $source);
        self::assertStringNotContainsString('!root.contains(trigger)', $source);
    }

    public function testDialogFieldsAreNotLookedUpThroughRootAtRuntime(): void
    {
        $source = $this->script();

        foreach (['data-feed-oauth', 'data-feed-apikey', 'data-feed-site-picker', 'data-feed-scope-lock'] as $hook) {
            self::assertSame(
                1,
                \substr_count($source, "root.querySelector('[" . $hook . "]')"),
                $hook . ' must be resolved once at start-up, while the dialog is still inside root',
            );
        }
    }

    public function testGenerationRefreshesTheLastGeneratedTime(): void
    {
        $script = $this->script();
        self::assertStringContainsString('next.last_generated_at = data.last_generated_at;', $script);

        $service = (string)\file_get_contents(\dirname(__DIR__, 2) . '/Service/FeedAdminCatalog.php');
        self::assertStringContainsString("'last_generated_at' => (string)\$persisted->lastGeneratedAt,", $service);
    }
}
