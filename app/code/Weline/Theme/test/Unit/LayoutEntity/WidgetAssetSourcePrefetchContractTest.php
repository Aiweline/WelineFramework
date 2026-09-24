<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\LayoutEntity\WidgetAssetArtifactPublisher;
use Weline\Theme\Service\LayoutEntity\WidgetAssetOptimizer;

/**
 * P6：部件资源在 transform 前页级 prefetchSources，避免 100+ 次单读 RPC。
 */
final class WidgetAssetSourcePrefetchContractTest extends TestCase
{
    public function testOptimizerPrefetchesSourcesBeforeTransformStreams(): void
    {
        $optimizerSrc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/WidgetAssetOptimizer.php'
        );
        self::assertMatchesRegularExpression(
            '/function\s+transform\s*\([^)]*\)[^{]*\{[^}]*prefetchSources\s*\(/s',
            $optimizerSrc
        );
    }

    public function testPublisherExposesPrefetchSourcesUsingWidgetAssetSourcePolicy(): void
    {
        self::assertTrue(method_exists(WidgetAssetArtifactPublisher::class, 'prefetchSources'));
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LayoutEntity/WidgetAssetArtifactPublisher.php'
        );
        self::assertStringContainsString('prefetchPolicy', $src);
        self::assertStringContainsString('theme.widget_asset_source', $src);
        self::assertStringContainsString('resolveModuleSourcePath', $src);
        self::assertStringContainsString('sourceCachePolicy', $src);
    }
}
