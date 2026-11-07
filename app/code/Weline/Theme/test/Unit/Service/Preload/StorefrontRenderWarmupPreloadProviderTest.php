<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Preload;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Theme\Service\Preload\StorefrontRenderWarmupPreloadProvider;

final class StorefrontRenderWarmupPreloadProviderTest extends TestCase
{
    public function testDeclaresItsOwnIdentity(): void
    {
        $provider = new StorefrontRenderWarmupPreloadProvider();

        self::assertSame('storefront_render', $provider->code());
        self::assertSame(WorkerPreloadContext::PHASE_BOOTSTRAP, $provider->phase());
    }

    public function testRunsAfterTheDataItReads(): void
    {
        $provider = new StorefrontRenderWarmupPreloadProvider();

        // The render resolves placeable widgets (theme catalogue) and the active
        // theme application (websites providers); running first would warm the
        // wrong theme's chrome.
        self::assertContains('theme_catalogue', $provider->dependsOn());
        self::assertContains('websites_theme_application', $provider->dependsOn());
        self::assertContains('website_directory', $provider->dependsOn());
    }

    public function testOwnsItsInvalidationKeys(): void
    {
        $provider = new StorefrontRenderWarmupPreloadProvider();

        self::assertSame(
            ['theme_catalogue', 'theme_layout', 'widget_registry'],
            $provider->invalidationKeys(),
        );
    }

    public function testOnlyRunsForPersistentWorkers(): void
    {
        $provider = new StorefrontRenderWarmupPreloadProvider();

        self::assertTrue($provider->isEnabled($this->context(RuntimeInterface::MODE_WLS)));
        self::assertFalse($provider->isEnabled($this->context('fpm')));
    }

    public function testModuleRegistersTheProvider(): void
    {
        $module = include \dirname(__DIR__, 4) . '/etc/module.php';
        self::assertIsArray($module);
        $provides = $module['provides'] ?? [];
        self::assertIsArray($provides);

        self::assertSame(
            StorefrontRenderWarmupPreloadProvider::class,
            $provides['worker_preload_provider.Weline_Theme_StorefrontRender'] ?? null,
        );
    }

    /**
     * 编译产物按语言分区，每个语言各存一份模板文件；只铺默认语言时，其余语言的
     * 首访仍要现场编译（线上实测每个新语言数秒）。
     *
     * 币种不是分区维度（只切换展示币种），铺开时不得乘以币种，否则模板份数爆炸。
     */
    public function testCompileContextCoversEveryLanguageWithDefaultCurrencyOnly(): void
    {
        $pairs = StorefrontRenderWarmupPreloadProvider::expandCompileContextPairs(
            ['en_US', 'USD'],
            ['en_US', 'ja_JP', 'de_DE'],
        );

        self::assertSame(['en_US', 'USD'], $pairs[0], '默认语言必须排第一，保证热路径先就绪');
        self::assertCount(3, $pairs);
        self::assertContains(['ja_JP', 'USD'], $pairs);
        self::assertContains(['de_DE', 'USD'], $pairs);
        foreach ($pairs as [, $currency]) {
            self::assertSame('USD', $currency, '币种不得参与分区');
        }
    }

    public function testCompileContextDeduplicatesAndFallsBackToDefaults(): void
    {
        self::assertSame(
            [['en_US', 'USD']],
            StorefrontRenderWarmupPreloadProvider::expandCompileContextPairs(['en_US', 'USD'], []),
        );

        // 默认语言同时出现在站点清单里时不得重复铺两遍。
        self::assertSame(
            [['en_US', 'USD'], ['ja_JP', 'USD']],
            StorefrontRenderWarmupPreloadProvider::expandCompileContextPairs(
                ['en_US', 'USD'],
                ['en_US', '', 'ja_JP'],
            ),
        );
    }

    private function context(string $runtimeMode): WorkerPreloadContext
    {
        return new WorkerPreloadContext(
            WorkerPreloadContext::PHASE_BOOTSTRAP,
            'worker',
            1,
            'test',
            0,
            $runtimeMode,
        );
    }
}
