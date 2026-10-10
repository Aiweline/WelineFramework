<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Cold-chaos: search type-filter must batch-prefetch WidgetI18n labels
 * before per-node label() (avoid N× dictionary DB on every /search).
 */
final class TypeFilterPrefetchContractTest extends TestCase
{
    public function testTypeFilterPrefetchesLabelsBeforeRender(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/partials/type-filter.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('WidgetI18n::prefetchLabels', $source);
        self::assertStringContainsString('$collectLabels', $source);
        self::assertStringContainsString('WidgetI18n::label', $source);
        $emptyGuard = strpos($source, 'if ($types === [])');
        $prefetch = strpos($source, 'WidgetI18n::prefetchLabels');
        $renderTree = strpos($source, '$renderTree = static function');
        self::assertNotFalse($emptyGuard);
        self::assertNotFalse($prefetch);
        self::assertNotFalse($renderTree);
        self::assertGreaterThan($emptyGuard, $prefetch);
        self::assertLessThan($renderTree, $prefetch);
    }
}
