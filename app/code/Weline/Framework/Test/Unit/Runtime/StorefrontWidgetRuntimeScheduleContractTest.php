<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\StorefrontRenderContext;
use Weline\Framework\Runtime\StorefrontWidgetRuntimeSchedule;

/**
 * N3 / F2：页级 WidgetRuntimeSchedule 契约——收集 inline codes → prefetchPolicy；禁平行权威袋 / 假 getMultiple。
 */
final class StorefrontWidgetRuntimeScheduleContractTest extends TestCase
{
    public function testCollectInlineSpecsFromCompiledPhpExport(): void
    {
        $php = <<<'PHP'
<?= \Weline\Widget\Taglib\Widget::renderRuntimeInline(array (
  'type' => 'block',
  'name' => 'hero-banner',
  'code' => 'hero-banner',
  'module' => 'Weline_Theme',
  'params' => array (),
)) ?>
<?= \Weline\Widget\Taglib\Widget::renderRuntimeInline(array (
  'type' => 'product',
  'name' => 'featured',
  'code' => 'featured-products',
  'module' => 'Weline_Product',
)) ?>
PHP;
        $specs = StorefrontWidgetRuntimeSchedule::collectInlineSpecsFromPhp($php);
        self::assertCount(2, $specs);
        self::assertSame('block', $specs[0]['type']);
        self::assertSame('hero-banner', $specs[0]['code']);
        self::assertSame('Weline_Theme', $specs[0]['module']);
        self::assertSame('featured-products', $specs[1]['code']);
        self::assertSame('Weline_Product', $specs[1]['module']);
    }

    public function testEmptyPhpYieldsNoSpecs(): void
    {
        self::assertSame([], StorefrontWidgetRuntimeSchedule::collectInlineSpecsFromPhp(''));
        self::assertSame([], StorefrontWidgetRuntimeSchedule::collectInlineSpecsFromPhp('<?php echo "hi";'));
    }

    public function testScheduleIsNotSecondRenderContextAuthorityBag(): void
    {
        self::assertNotSame(
            StorefrontRenderContext::BAG_KEY,
            StorefrontWidgetRuntimeSchedule::LATCH_KEY
        );
        self::assertStringContainsString('widget_runtime_schedule', StorefrontWidgetRuntimeSchedule::LATCH_KEY);
        self::assertStringNotContainsString('render_context', StorefrontWidgetRuntimeSchedule::LATCH_KEY);

        $scheduleSrc = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Runtime/StorefrontWidgetRuntimeSchedule.php'
        );
        self::assertStringContainsString('prefetchPolicy', $scheduleSrc);
        self::assertStringContainsString('不是第二套 RequestContext 渲染权威袋', $scheduleSrc);
        self::assertStringNotContainsString('getMultipleCustom', $scheduleSrc);
        self::assertStringContainsString('primeBeforeLayoutFetch', $scheduleSrc);
        self::assertStringContainsString('StorefrontWidgetRuntimeAssetPrimer', $scheduleSrc);
    }

    public function testThemeHookPrimesBeforeLayoutFetch(): void
    {
        $observer = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Theme/Observer/ControllerFetchFileAfter.php'
        );
        self::assertStringContainsString('StorefrontWidgetRuntimeSchedule', $observer);
        self::assertStringContainsString('primeWidgetRuntimeSchedule', $observer);
        self::assertStringContainsString('primeBeforeLayoutFetch', $observer);

        $primePos = strpos($observer, 'primeWidgetRuntimeSchedule');
        $fetchPos = strpos($observer, '$template->fetch(');
        self::assertNotFalse($primePos);
        self::assertNotFalse($fetchPos);
        // At least one prime call appears before the first layout fetch in the file.
        self::assertLessThan($fetchPos, $primePos);
    }

    public function testAssetPrimerUsesPrefetchSourcesNotFakeGetMultiple(): void
    {
        $primerPath = dirname(__DIR__, 4)
            . '/Theme/Service/Storefront/StorefrontWidgetRuntimeAssetPrimer.php';
        self::assertFileExists($primerPath);
        $src = (string)file_get_contents($primerPath);
        self::assertStringContainsString('prefetchSources', $src);
        self::assertStringContainsString('WidgetAssetArtifactPublisher', $src);
        self::assertStringNotContainsString('getMultiple(', $src);
        self::assertStringNotContainsString('CachePool::getMultiple', $src);
    }
}
