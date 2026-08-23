<?php

declare(strict_types=1);

namespace Weline\Dashboard\Test\Unit\Integration\Theme;

use PHPUnit\Framework\TestCase;
use Weline\Dashboard\Integration\Theme\DashboardViewTargetTypeProvider;
use Weline\Dashboard\Model\DashboardView;
use Weline\Dashboard\Service\DashboardViewService;
use Weline\Theme\Api\Layout\LayoutIdentity;

final class DashboardViewTargetTypeProviderTest extends TestCase
{
    public function testMapsOpaqueLegacyScopeToWebsiteScopeAndDashboardTarget(): void
    {
        $view = $this->createMock(DashboardView::class);
        $view->method('clearData')->willReturnSelf();
        $view->method('clearQuery')->willReturnSelf();
        $view->method('load')->with(42)->willReturnSelf();
        $view->method('getViewId')->willReturn(42);
        $view->method('getWebsiteId')->willReturn(7);

        $service = $this->createMock(DashboardViewService::class);
        $service->method('layoutIdentity')->willReturn(new LayoutIdentity(
            layoutOption: 'default',
            scope: 'shop.default.default',
            targetType: 'dashboard_view',
            targetId: 42,
        ));
        $provider = new DashboardViewTargetTypeProvider($service, $view);

        $mapping = $provider->mapLegacyIdentity('dashboard_view:42', 'website', 7);

        self::assertNotNull($mapping);
        self::assertSame('shop.default.default', $mapping->scope);
        self::assertSame('dashboard_view', $mapping->targetType);
        self::assertSame(42, $mapping->targetId);
    }

    public function testRejectsLegacyIdentityWhenWebsiteTargetDoesNotMatchView(): void
    {
        $view = $this->createMock(DashboardView::class);
        $view->method('clearData')->willReturnSelf();
        $view->method('clearQuery')->willReturnSelf();
        $view->method('load')->willReturnSelf();
        $view->method('getViewId')->willReturn(42);
        $view->method('getWebsiteId')->willReturn(7);

        $provider = new DashboardViewTargetTypeProvider(
            $this->createMock(DashboardViewService::class),
            $view,
        );

        self::assertNull($provider->mapLegacyIdentity('dashboard_view:42', 'website', 8));
    }
}
