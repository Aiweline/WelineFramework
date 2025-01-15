<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Observer;

use Weline\Acl\Model\Acl;
use Weline\Backend\Config\MenuXmlReader;
use Weline\Backend\Model\Menu;
use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

class UpgradeMenu implements ObserverInterface
{
    private \Weline\Backend\Model\Menu $menu;
    private MenuXmlReader $menuReader;

    public function __construct(
        \Weline\Backend\Model\Menu $menu,
        MenuXmlReader              $menuReader
    )
    {
        $this->menu = $menu;
        $this->menuReader = $menuReader;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event $event)
    {
        list($menus, $menu, $modules_info) = $this->collectMenus();
        return array($menus, $menu, $modules_info);
    }

    /**
     * @DESC          # 如果动作路径有*号，替换为路由所指模块的路由
     *
     * @AUTH  秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 7/4/2024 下午4:40
     * 参数区：
     * @param mixed $menu
     * @param array $modules_info
     * @param mixed $module
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
                    throw new \Exception(__('模块不存在：%1', $module_info['name']));
                }
            }
            $menu[Menu::fields_ACTION] = str_replace('*', $module_info['router'], $menu[Menu::fields_ACTION]);
        }
        return $menu;
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function collectMenus(): array
    {
        $update_items = [];
        # 清空表
        $this->menu->query("TRUNCATE TABLE {$this->menu->getTable()}")->fetch();
        # 读取菜单配置
        $modules_xml_menus = $this->menuReader->read();
        $modules_info = [];
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
                    $menu = $this->replaceModuleAction($menu, $modules_info, $module);
                    # 先查询一遍
                    /**@var Menu $menuModel */
                    $this->menu->clear();
                    // 以唯一source索引为准检测，存在更新不存在新增
                    $result = $this->menu->setData($menu)->save(true, 'source');
                    $menu[Menu::fields_PATH] = $this->menu->getData(Menu::fields_ID);
                    $this->menu->setData($menu)
                        ->save(true, 'source');
                    unset($menus['data'][$key]);
                }
            }
//            if ($module == 'Weline_BackendActivity' || $module == 'Weline_Admin') {
//                $d[] = $menus;
//            }else{
//                unset($modules_xml_menus[$module]);
//            }
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
                $this->menu->where(Menu::fields_SOURCE, $menu[Menu::fields_SOURCE])
                    ->update($menu)
                    ->fetch();
                # 2 检查自身是否被别的模块作为父分类
//                $this->menu->clearData();
//                if ($this_menu_id = $this->menu->getId() && $is_others_parent = $this->menu->where(Menu::fields_PARENT_SOURCE, $menu[Menu::fields_SOURCE])->select()->fetch()) {
//                    foreach ($is_others_parent as $other_menu) {
//                        if (empty($other_menu['pid'])) {
//                            $other_menu['pid'] = $this_menu_id;
//                            $other_menu[Menu::fields_PATH] = $this->menu->getData(Menu::fields_PATH).'/'. $other_menu[Menu::fields_ID];
//                            $other_menu[Menu::fields_LEVEL] = $this->menu->getData(Menu::fields_LEVEL)+1;
//                            $this->menu->save($other_menu,'source');
//                        }
//                    }
//                }
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
                    $menu[Menu::fields_PID] = $pid;
                    $menu[Menu::fields_LEVEL] = $parent->getData(Menu::fields_LEVEL) + 1;
                    $menu[Menu::fields_PATH] = $parent->getData(Menu::fields_PATH) . '/' . $menu[Menu::fields_PATH];
                    $this->menu->save($menu);
                }
            }
        }
        // 更新菜单到权限表
        $all_menus = $this->menu->reset()->order('order', 'ASC')->select()->fetchArray();
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
            /**@var \Weline\Acl\Model\Acl $alcModel */
            $alcModel = ObjectManager::getInstance(Acl::class);
            $alcModel->insert($acl_items,'source_id')
                ->fetch();
        }
        return array($menus, $menu, $modules_info);
    }
}
