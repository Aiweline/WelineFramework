<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;

/**
 * publishBatch/publish must not leave a pre-publish request memo that makes
 * assertCurrentScopedLayoutPublished throw theme_scoped_layout_publish_receipt_invalid.
 */
final class ThemeScopedPublishRequestCacheContractTest extends TestCase
{
    public function testWritePathsFlushRequestLoadCacheAfterCommit(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Service/Scoped/ThemeScopedWorkspace.php'
        );

        self::assertStringContainsString('public function invalidateRequestLoadCache(): void', $source);
        self::assertStringContainsString(
            'Never reuse or refill request memo while a write transaction is open',
            $source,
        );

        foreach ([
            'public function applyChanges(',
            'public function replaceEffectivePayload(',
            'public function publish(',
            'public function publishBatch(',
            'public function rollbackReleaseBatch(',
        ] as $method) {
            $start = \strpos($source, $method);
            self::assertNotFalse($start, $method . ' missing');
            $next = \strpos($source, "\n    public function ", $start + \strlen($method));
            self::assertNotFalse($next, $method . ' next method missing');
            $body = \substr($source, $start, $next - $start);
            self::assertGreaterThanOrEqual(
                2,
                \substr_count($body, '$this->flushRequestLoadCache();'),
                $method . ' must flush request load cache before and after the write.',
            );
        }
    }

    public function testPublishAndExitAssertInvalidatesRequestLoadCache(): void
    {
        $controller = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Controller/Backend/ThemeEditor.php'
        );
        $start = \strpos($controller, 'private function assertCurrentScopedLayoutPublished(');
        $end = \strpos($controller, 'private function resolveEditorLockContextKey(', (int)$start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = \substr($controller, (int)$start, (int)$end - (int)$start);
        self::assertStringContainsString('invalidateRequestLoadCache()', $method);
    }

    public function testRequestServicePublishBatchInvalidatesAfterChromeSideEffects(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Service/Scoped/ThemeScopedWorkspaceRequestService.php'
        );
        $start = \strpos($source, 'public function publishBatch(');
        $end = \strpos($source, 'public function readBatch(', (int)$start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = \substr($source, (int)$start, (int)$end - (int)$start);
        self::assertStringContainsString('invalidateRequestLoadCache()', $method);
        self::assertTrue(
            \strpos($method, 'invalidateRequestLoadCache()')
                > \strpos($method, 'overwriteNonCarrierChromeAfterCarrierPublish('),
            'Cache invalidation must run after shared-chrome side effects.',
        );
        self::assertStringContainsString('publishPendingSiblingI18nLocales(', $method);
        self::assertTrue(
            \strpos($method, 'invalidateRequestLoadCache()')
                > \strpos($method, 'publishPendingSiblingI18nLocales('),
            'Cache invalidation must run after sibling i18n publish.',
        );
    }
}
