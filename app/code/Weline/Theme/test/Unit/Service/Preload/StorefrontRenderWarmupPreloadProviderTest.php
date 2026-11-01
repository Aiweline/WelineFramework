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
