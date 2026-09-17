<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Event;

use PHPUnit\Framework\TestCase;

/**
 * setup:upgrade 卡在 AI 扫描后无输出时，必须能看到后续 upgrade_after 观察者进度。
 */
final class UpgradeAfterObserverProgressContractTest extends TestCase
{
    public function testEventDispatchPrintsUpgradeAfterObserverProgress(): void
    {
        $eventPhp = (string)file_get_contents(dirname(__DIR__, 3) . '/Event/Event.php');

        self::assertStringContainsString("Weline_Framework_Setup::upgrade_after", $eventPhp);
        self::assertStringContainsString('printUpgradeAfterObserverProgress', $eventPhp);
        self::assertStringContainsString('升级后观察者开始', $eventPhp);
        self::assertStringContainsString('升级后观察者完成', $eventPhp);
    }

    public function testSubsequentSilentObserversNowPrintProgress(): void
    {
        $themeRoot = dirname(__DIR__, 4) . '/Theme';
        $maintenanceRoot = dirname(__DIR__, 4) . '/Maintenance';
        $searchRoot = dirname(__DIR__, 4) . '/Search';

        $themeAfter = (string)file_get_contents($themeRoot . '/Observer/SetupUpgradeAfter.php');
        $publish404 = (string)file_get_contents($themeRoot . '/Observer/SetupUpgradeAfterPublishNotFoundStatic.php');
        $generator = (string)file_get_contents($themeRoot . '/Service/StorefrontNotFoundStaticGenerator.php');
        $maintenance = (string)file_get_contents($maintenanceRoot . '/Observer/LocaleCatalogChangedObserver.php');
        $search = (string)file_get_contents($searchRoot . '/Observer/SetupProviderIndexWarmupObserver.php');

        self::assertStringContainsString('正在推送主题预览 CDN 绕过规则', $themeAfter);
        self::assertStringContainsString('开始发布前台 404 静态页', $publish404);
        self::assertStringContainsString('website×locale', $generator);
        self::assertStringContainsString('FiberTaskRunner::yield', $generator);
        self::assertStringContainsString('开始发布维护模式静态页', $maintenance);
        self::assertStringContainsString('开始预热搜索 Provider 索引', $search);
    }
}
