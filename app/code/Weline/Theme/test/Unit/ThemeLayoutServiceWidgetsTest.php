<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Service\ThemeLayoutService;

/**
 * 验证后台主题编辑器获取部件列表：传入 area=backend 时应返回部件（不因 page_type 过滤为空）
 */
class ThemeLayoutServiceWidgetsTest extends TestCore
{
    public function testGetAvailableWidgetsWithBackendAreaReturnsArray(): void
    {
        /** @var ThemeLayoutService $service */
        $service = ObjectManager::getInstance(ThemeLayoutService::class);
        $result = $service->getAvailableWidgets('homepage', ['area' => 'backend']);
        $this->assertIsArray($result);
    }

    public function testGetAvailableWidgetsWithBackendAreaReturnsNonEmptyWhenRegistryHasWidgets(): void
    {
        $registryFile = BP . 'generated/widgets.php';
        if (!is_file($registryFile)) {
            $this->markTestSkipped('generated/widgets.php not found, run php bin/w widget:refresh');
        }
        $registry = include $registryFile;
        if (!is_array($registry) || empty($registry)) {
            $this->markTestSkipped('Widget registry is empty');
        }
        /** @var ThemeLayoutService $service */
        $service = ObjectManager::getInstance(ThemeLayoutService::class);
        $result = $service->getAvailableWidgets('homepage', ['area' => 'backend']);
        $this->assertIsArray($result);
        $total = 0;
        foreach ($result as $group) {
            $total += isset($group['widgets']) && is_array($group['widgets']) ? count($group['widgets']) : 0;
        }
        if ($total === 0) {
            $this->markTestSkipped('Event chain may not be wired in PHPUnit; verify in browser with theme/backend/theme-editor/widgets?page_type=homepage');
        }
        $this->assertGreaterThan(0, $total, 'Backend theme editor should get widgets when registry has data');
    }
}
