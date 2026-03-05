<?php

declare(strict_types=1);

namespace Weline\Backend\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Config\MenuXmlReader;
use Weline\Backend\Model\Menu;
use Weline\Backend\Service\MenuCollector;
use Weline\Framework\Manager\ObjectManager;

/**
 * MenuCollector 与 MenuXmlReader 单元测试
 * 验证 menu.xml 解析（含 _value 结构）及数据库写入
 */
class MenuCollectorTest extends TestCase
{
    private MenuXmlReader $menuReader;
    private MenuCollector $menuCollector;
    private Menu $menuModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->menuReader = ObjectManager::getInstance(MenuXmlReader::class);
        $this->menuCollector = ObjectManager::getInstance(MenuCollector::class);
        $this->menuModel = ObjectManager::getInstance(Menu::class);
    }

    /**
     * MenuXmlReader 能从 menu.xml 解析出菜单（含 _value 结构）
     */
    public function testMenuXmlReaderReturnsNonEmptyModulesWithMenuData(): void
    {
        $moduleMenus = $this->menuReader->read();
        $this->assertIsArray($moduleMenus, 'read() 应返回数组');
        $this->assertNotEmpty($moduleMenus, '应解析到至少一个模块的菜单');

        $totalItems = 0;
        foreach ($moduleMenus as $module => $menus) {
            $this->assertArrayHasKey('data', $menus, "模块 {$module} 应有 data 键");
            $data = $menus['data'] ?? [];
            $this->assertIsArray($data);
            $totalItems += count($data);
        }
        $this->assertGreaterThan(0, $totalItems, '应解析到至少一条菜单项（Parser _value 结构已正确解析）');
    }

    /**
     * MenuCollector collectWithDiagnostics 能解析到菜单
     */
    public function testMenuCollectorParseFileMenus(): void
    {
        $diag = $this->menuCollector->collectWithDiagnostics([]);
        $this->assertArrayHasKey('file_menu_count', $diag);
        $this->assertArrayHasKey('raw_config_count', $diag);
        $this->assertGreaterThan(0, $diag['raw_config_count'], 'Scanner 应发现 menu.xml 文件');
        $this->assertGreaterThan(0, $diag['file_menu_count'], '应解析到至少一条菜单');
    }

    /**
     * 执行 collect 后 weline_menu 表有记录
     */
    public function testMenuTableHasRecordsAfterCollect(): void
    {
        $this->menuCollector->collect([]);

        $count = $this->menuModel->reset()->total();
        $this->assertGreaterThan(0, $count, 'collect 后 weline_menu 表应有记录');
    }
}
