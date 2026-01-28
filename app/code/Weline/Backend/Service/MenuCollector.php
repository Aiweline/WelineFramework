<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Service;

use Weline\Acl\Model\Acl;
use Weline\Backend\Config\MenuXmlReader;
use Weline\Backend\Model\Menu;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

/**
 * 菜单收集服务
 *
 * - 单一职责：只负责“从菜单 XML 配置收集并落库 + 同步 ACL”
 * - 支持：
 *   - 不指定模块：收集所有启用模块的菜单
 *   - 指定模块数组：只收集这些模块（同样仅限已启用模块）
 * - 自动过滤：禁用模块的菜单不会被收集 / 保留
 */
class MenuCollector
{
    private Menu $menu;
    private MenuXmlReader $menuReader;

    public function __construct(
        Menu          $menu,
        MenuXmlReader $menuReader
    )
    {
        $this->menu = $menu;
        $this->menuReader = $menuReader;
    }

    /**
     * 收集菜单
     *
     * @param string[] $modulesFilter 指定需要收集的模块名数组；为空则收集所有已启用模块
     * @return array
     * @throws \Exception
     */
    public function collect(array $modulesFilter = []): array
    {
        $update_items = [];

        // 计算禁用模块列表（用于设置菜单 is_enable 状态）
        // 使用 getActiveModules() 获取启用模块列表，不在列表中的就是禁用模块
        $env = Env::getInstance();
        $allModules = $env->getModuleList();
        $activeModules = $env->getActiveModules();
        $activeModuleNames = array_keys($activeModules);
        $disabledModules = [];
        foreach ($allModules as $moduleName => $moduleConfig) {
            // 如果模块不在启用模块列表中，则视为禁用
            if (!in_array($moduleName, $activeModuleNames, true)) {
                $disabledModules[] = $moduleName;
            }
        }

        # 读取菜单配置（所有模块的菜单 XML，包括禁用的模块）
        $modules_xml_menus = $this->menuReader->read();

        // 如果指定了模块过滤列表，只处理这些模块的菜单
        if (!empty($modulesFilter)) {
            $modules_xml_menus = array_intersect_key(
                $modules_xml_menus,
                array_flip($modulesFilter)
            );
        }

        $modules_info = [];

        # 收集所有文件中的菜单 source，用于后续删除不在文件中的菜单
        $file_menu_sources = [];

        # 先更新顶层菜单
        foreach ($modules_xml_menus as $module => &$menus) {
            foreach ($menus['data'] as $key => $menu) {
                if (empty($menu['parent'])) {
                    unset($menu['parent']);
                    # 清空查询条件
                    $menu[Menu::fields_MODULE] = $module;
                    $menu[Menu::fields_PARENT_SOURCE] = '';
                    $menu[Menu::fields_PID] = 0;
                    $menu[Menu::fields_LEVEL] = 1;
                    $menu[Menu::fields_ACTION] = trim($menu[Menu::fields_ACTION], '/');
                    # 如果动作路径有*号，替换为路由所指模块的路由
                    $menu = $this->replaceModuleAction($menu, $modules_info);
                    # 根据模块状态设置菜单 is_enable：禁用模块的菜单设置为 0，启用模块的菜单设置为 1
                    $isDisabled = in_array($module, $disabledModules, true);
                    $menu[Menu::fields_IS_ENABLE] = $isDisabled ? 0 : 1;
                    # 收集文件中的菜单 source
                    $file_menu_sources[] = $menu[Menu::fields_SOURCE];
                    # 先查询一遍
                    /**@var Menu $menuModel */
                    $this->menu->clear();
                    // 以唯一source索引为准检测，存在更新不存在新增
                    $result = $this->menu->setData($menu)->save(true, 'source');
                    $menu[Menu::fields_PATH] = $this->menu->getData(Menu::fields_ID);
                    // 确保 is_enable 字段也被更新（使用 update 确保所有字段都被更新）
                    $this->menu->where(Menu::fields_SOURCE, $menu[Menu::fields_SOURCE])
                        ->update($menu)
                        ->fetch();
                    unset($menus['data'][$key]);
                }
            }
        }

        # 子菜单
        foreach ($modules_xml_menus as $module => $sub_menus) {
            foreach ($sub_menus['data'] as $menu) {
                # 清空查询条件
                $this->menu->clear();
                $menu[Menu::fields_MODULE] = $module;
                $menu[Menu::fields_PARENT_SOURCE] = $menu['parent'] ?? '';
                $menu[Menu::fields_ACTION] = trim($menu[Menu::fields_ACTION], '/');
                $menu = $this->replaceModuleAction($menu, $modules_info);
                # 根据模块状态设置菜单 is_enable：禁用模块的菜单设置为 0，启用模块的菜单设置为 1
                $menu[Menu::fields_IS_ENABLE] = in_array($module, $disabledModules, true) ? 0 : 1;
                # 收集文件中的菜单 source
                $file_menu_sources[] = $menu[Menu::fields_SOURCE];
                unset($menu['parent']);
                # 1 存在父资源 检查父资源的 ID
                $parent = clone $this->menu->where(Menu::fields_SOURCE, $menu[Menu::fields_PARENT_SOURCE])->find()->fetch();
                if ($pid = $parent->getId()) {
                    $menu[Menu::fields_PID] = $pid;
                    $menu[Menu::fields_LEVEL] = $parent->getData(Menu::fields_LEVEL) + 1;
                } else {
                    $menu[Menu::fields_PID] = 0;
                }
                $this->menu->clearData();
                $menu[Menu::fields_PID] = $menu[Menu::fields_PID] ?? 0;
                $result = $this->menu->setData($menu)->save(true, 'source');
                $parent_path = $parent->getData(Menu::fields_PATH);
                $path = ($parent_path ? ($parent_path . '/') : '') . $this->menu->getData(Menu::fields_ID);
                $menu[Menu::fields_PATH] = $path;
                // 确保 is_enable 字段也被更新
                $this->menu->where(Menu::fields_SOURCE, $menu[Menu::fields_SOURCE])
                    ->update($menu)
                    ->fetch();
            }
        }

        # 再次处理父菜单
        $this->menu->clearData();
        $top_menus = $this->menu->where(Menu::fields_PID, 0)->select()->fetch();
        foreach ($top_menus->getItems() as $menu) {
            # 如果存在父菜单，则更新父菜单的id到当前子菜单【pid】
            if ($menu[Menu::fields_PARENT_SOURCE]) {
                # 查找父菜单，获取父菜单的id
                $parent = $this->menu->where(Menu::fields_SOURCE, $menu[Menu::fields_PARENT_SOURCE])->find()->fetch();
                if ($pid = $parent->getData(Menu::fields_ID)) {
                    // 保存当前的 is_enable 值，避免被覆盖
                    $currentIsEnable = $menu[Menu::fields_IS_ENABLE] ?? null;
                    $menu[Menu::fields_PID] = $pid;
                    $menu[Menu::fields_LEVEL] = $parent->getData(Menu::fields_LEVEL) + 1;
                    $menu[Menu::fields_PATH] = $parent->getData(Menu::fields_PATH) . '/' . $menu[Menu::fields_PATH];
                    // 确保 is_enable 不被覆盖
                    if ($currentIsEnable !== null) {
                        $menu[Menu::fields_IS_ENABLE] = $currentIsEnable;
                    }
                    $this->menu->save($menu);
                }
            }
        }

        // 删除数据库中不在文件中的菜单项（以文件为准）
        // 需要递归删除：如果父菜单被删除，子菜单也应该被删除
        if (!empty($file_menu_sources)) {
            // 先找出所有需要处理的菜单（不在文件中的）
            $this->menu->reset();
            if (!empty($modulesFilter)) {
                // 仅删除指定模块的菜单
                $this->menu->where(Menu::fields_MODULE, $modulesFilter, 'in');
            }
            $menusToDelete = $this->menu
                ->where(Menu::fields_SOURCE, $file_menu_sources, 'not in')
                ->select()
                ->fetchArray();

            // 收集所有需要删除/禁用的菜单 source（包括子菜单）
            $sourcesToDelete = [];
            $sourcesToDisable = [];
            foreach ($menusToDelete as $menu) {
                $isDisabledModuleMenu = !empty($disabledModules) && in_array($menu[Menu::fields_MODULE] ?? '', $disabledModules, true);
                if ($isDisabledModuleMenu) {
                    // 对禁用模块的菜单做软禁用（设置 is_enable=0），而不是物理删除
                    $sourcesToDisable[] = $menu[Menu::fields_SOURCE];
                    $this->collectChildMenuSources($menu[Menu::fields_SOURCE], $sourcesToDisable);
                } else {
                    // 其他模块的无效菜单依然删除
                    $sourcesToDelete[] = $menu[Menu::fields_SOURCE];
                    // 递归查找所有子菜单
                    $this->collectChildMenuSources($menu[Menu::fields_SOURCE], $sourcesToDelete);
                }
            }

            // 删除所有需要删除的菜单（包括子菜单）
            if (!empty($sourcesToDelete)) {
                $this->menu->reset()
                    ->where(Menu::fields_SOURCE, $sourcesToDelete, 'in')
                    ->delete()
                    ->fetch();
            }
            // 软禁用禁用模块的菜单：is_enable=0
            if (!empty($sourcesToDisable)) {
                $this->menu->reset()
                    ->where(Menu::fields_SOURCE, $sourcesToDisable, 'in')
                    ->update([Menu::fields_IS_ENABLE => 0])
                    ->fetch();
            }
        } else {
            if (empty($modulesFilter)) {
                // 如果文件中没有任何菜单，删除所有菜单
                $this->menu->reset()->delete()->fetch();
            } else {
                // 仅删除指定模块的菜单
                $this->menu->reset()
                    ->where(Menu::fields_MODULE, $modulesFilter, 'in')
                    ->delete()
                    ->fetch();
            }
        }

        // 更新菜单到权限表
        $all_menus = $this->menu->reset()->order('order', 'ASC')->select()->fetchArray();

        // 先收集所有应该存在的菜单 source，用于清理权限表中不存在的菜单权限
        $collected_menu_sources = [];
        foreach ($all_menus as $menu) {
            $collected_menu_sources[] = $menu['source'];
        }

        // 删除权限表中不在收集列表中的菜单权限（type='menus'）
        /**@var \Weline\Acl\Model\Acl $alcModel */
        $alcModel = ObjectManager::getInstance(Acl::class);

        if (!empty($collected_menu_sources)) {
            // 删除所有不在收集列表中的菜单权限
            $alcModel->reset()
                ->where(Acl::fields_TYPE, 'menus')
                ->where(Acl::fields_SOURCE_ID, $collected_menu_sources, 'not in')
                ->delete()
                ->fetch();
        } else {
            // 如果没有收集到任何菜单，删除所有菜单权限
            $alcModel->reset()
                ->where(Acl::fields_TYPE, 'menus')
                ->delete()
                ->fetch();
        }

        // 插入或更新菜单权限
        $acl_items = [];
        foreach ($all_menus as $menu) {
            $acl_items[] = [
                Acl::fields_SOURCE_ID => $menu['source'],
                Acl::fields_ORDER => $menu['order'],
                Acl::fields_PARENT_SOURCE => $menu['parent_source'],
                Acl::fields_TYPE => 'menus',
                Acl::fields_CLASS => '',
                Acl::fields_MODULE => $menu['module'],
                Acl::fields_SOURCE_NAME => $menu['title'],
                Acl::fields_ROUTER => '',
                Acl::fields_ROUTE => trim($menu['action'], '/'),
                Acl::fields_METHOD => 'GET',
                Acl::fields_DOCUMENT => $menu['is_system'] ? __('系统菜单') : __('用户菜单'),
                Acl::fields_REWRITE => '',
                Acl::fields_ICON => $menu['icon'],
                Acl::fields_IS_ENABLE => $menu['is_enable'],
                Acl::fields_IS_BACKEND => $menu['is_backend'],
            ];
        }

        if ($acl_items) {
            $alcModel->reset()->insert($acl_items, 'source_id')
                ->fetch();
        }

        return [$modules_xml_menus, $update_items, $modules_info];
    }

    /**
     * 递归收集子菜单的 source
     *
     * @param string $parentSource 父菜单的 source
     * @param array  $sourcesToDelete 要删除的菜单 source 数组（引用传递）
     * @return void
     */
    private function collectChildMenuSources(string $parentSource, array &$sourcesToDelete): void
    {
        $childMenus = $this->menu->reset()
            ->where(Menu::fields_PARENT_SOURCE, $parentSource)
            ->select()
            ->fetchArray();

        foreach ($childMenus as $childMenu) {
            $childSource = $childMenu[Menu::fields_SOURCE];
            if (!in_array($childSource, $sourcesToDelete, true)) {
                $sourcesToDelete[] = $childSource;
                // 递归查找子菜单的子菜单
                $this->collectChildMenuSources($childSource, $sourcesToDelete);
            }
        }
    }

    /**
     * 如果动作路径有*号，替换为路由所指模块的路由
     *
     * @param array $menu
     * @param array $modules_info
     * @return array
     * @throws \Exception
     */
    private function replaceModuleAction(array &$menu, array &$modules_info): array
    {
        if (strpos($menu[Menu::fields_ACTION], '*') !== false) {
            $module_info = $modules_info[$menu['module']] ?? [];
            if (empty($module_info)) {
                $module_info = Env::getInstance()->getModuleInfo($menu['module']);
                $modules_info[$menu['module']] = $module_info;
                if (empty($module_info)) {
                    throw new \Exception(__('模块不存在：%{1}', $menu['module']));
                }
            }
            if (empty($menu['is_backend'])) {
                $menu['is_backend'] = true;
            }
            $router = $module_info['router'];
            if ($menu['is_backend']) {
                $router = $module_info['backend_router'];
            }
            $menu[Menu::fields_ACTION] = str_replace('*', $router, $menu[Menu::fields_ACTION]);
        }
        return $menu;
    }
}

