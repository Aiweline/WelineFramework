<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://aiweline.com
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
 * - 采用「先全量 diff 再批量执行」策略，确保删除 menu.xml 中的菜单时能被正确感知
 * - 支持：不指定模块时收集所有，指定模块时仅处理该模块
 * - 禁用模块的菜单做软禁用（is_enable=0）
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
     * 收集菜单（带诊断信息）
     *
     * @param string[] $modulesFilter 指定需要收集的模块名数组；为空则收集所有
     * @return array{file_menu_count: int, raw_config_count: int, result: array}
     */
    public function collectWithDiagnostics(array $modulesFilter = []): array
    {
        $fileListCount = count($this->menuReader->getFileList());
        [$modules_xml_menus, , , $file_menu_count] = $this->collectInternal($modulesFilter);
        return [
            'file_menu_count' => $file_menu_count,
            'raw_config_count' => $fileListCount,
            'result' => [$modules_xml_menus, [], []],
        ];
    }

    /**
     * 收集菜单
     *
     * @param string[] $modulesFilter 指定需要收集的模块名数组；为空则收集所有
     * @return array [modules_xml_menus, update_items, modules_info]
     * @throws \Exception
     */
    public function collect(array $modulesFilter = []): array
    {
        [$modules_xml_menus, , $modules_info, ] = $this->collectInternal($modulesFilter);
        return [$modules_xml_menus, [], $modules_info];
    }

    /**
     * @return array{0: array, 1: array, 2: array, 3: int} [modules_xml_menus, update_items, modules_info, file_menu_count]
     */
    private function collectInternal(array $modulesFilter = []): array
    {
        $modules_info = [];
        $disabledModules = $this->getDisabledModules();

        [$file_menus, $modules_xml_menus] = $this->buildFileMenus($modulesFilter, $disabledModules, $modules_info);
        $db_menus = $this->buildDbMenus($modulesFilter);

        // 保护：未指定模块且文件端为空时，不执行破坏性操作（避免 Scanner/缓存异常导致误删全表）
        if (empty($modulesFilter) && empty($file_menus)) {
            $this->syncAcl();
            return [$modules_xml_menus, [], $modules_info, count($file_menus)];
        }

        $diff = $this->computeDiff($file_menus, $db_menus, $disabledModules);

        $this->executeBatch($diff, $file_menus, $db_menus);

        $this->syncAcl();

        return [$modules_xml_menus, [], $modules_info, count($file_menus)];
    }

    /**
     * 构建文件端菜单状态（扁平 source => normalized_data）
     * @return array{0: array, 1: array} [file_menus, modules_xml_menus]
     */
    private function buildFileMenus(array $modulesFilter, array $disabledModules, array &$modules_info): array
    {
        $modules_xml_menus = $this->menuReader->read();
        if (!empty($modulesFilter)) {
            $modules_xml_menus = array_intersect_key(
                $modules_xml_menus,
                array_flip($modulesFilter)
            );
        }

        $file_menus = [];
        foreach ($modules_xml_menus as $module => $menus) {
            $data = $menus['data'] ?? [];
            foreach ($data as $menu) {
                $menu[Menu::schema_fields_MODULE] = $module;
                $menu[Menu::schema_fields_PARENT_SOURCE] = $menu['parent'] ?? '';
                $menu[Menu::schema_fields_ACTION] = trim($menu[Menu::schema_fields_ACTION] ?? '', '/');
                unset($menu['parent']);

                $menu = $this->replaceModuleAction($menu, $modules_info);
                $menu[Menu::schema_fields_IS_ENABLE] = in_array($module, $disabledModules, true) ? 0 : 1;

                $source = $menu[Menu::schema_fields_SOURCE] ?? '';
                if ($source !== '') {
                    $file_menus[$source] = $menu;
                }
            }
        }
        return [$file_menus, $modules_xml_menus];
    }

    /**
     * 构建数据库端菜单状态（source => row）
     */
    private function buildDbMenus(array $modulesFilter): array
    {
        $this->menu->reset();
        if (!empty($modulesFilter)) {
            $this->menu->where(Menu::schema_fields_MODULE, $modulesFilter, 'in');
        }
        $rows = $this->menu->select()->fetchArray();
        $db_menus = [];
        foreach ($rows as $row) {
            $source = $row[Menu::schema_fields_SOURCE] ?? '';
            if ($source !== '') {
                $db_menus[$source] = $row;
            }
        }
        return $db_menus;
    }

    /**
     * 计算 diff：to_add, to_update, to_delete, to_disable
     *
     * @return array{to_add: string[], to_update: array<string, array>, to_delete: string[], to_disable: string[]}
     */
    private function computeDiff(array $file_menus, array $db_menus, array $disabledModules): array
    {
        $file_sources = array_keys($file_menus);
        $db_sources = array_keys($db_menus);

        $to_add = array_diff($file_sources, $db_sources);
        $intersect = array_intersect($file_sources, $db_sources);

        $to_update = [];
        foreach ($intersect as $source) {
            $file = $file_menus[$source];
            $db = $db_menus[$source];
            if ($this->menuDataChanged($file, $db)) {
                $to_update[$source] = $file;
            }
        }

        $removed = array_diff($db_sources, $file_sources);
        $sourcesToDelete = [];
        $sourcesToDisable = [];
        foreach ($removed as $source) {
            $module = $db_menus[$source][Menu::schema_fields_MODULE] ?? '';
            $children = [];
            $this->collectChildMenuSources($source, $children);
            $all = array_merge([$source], $children);
            if (in_array($module, $disabledModules, true)) {
                foreach ($all as $s) {
                    if (!in_array($s, $sourcesToDisable, true)) {
                        $sourcesToDisable[] = $s;
                    }
                }
            } else {
                foreach ($all as $s) {
                    if (!in_array($s, $sourcesToDelete, true)) {
                        $sourcesToDelete[] = $s;
                    }
                }
            }
        }

        return [
            'to_add' => array_values($to_add),
            'to_update' => $to_update,
            'to_delete' => $sourcesToDelete,
            'to_disable' => $sourcesToDisable,
        ];
    }

    private function menuDataChanged(array $file, array $db): bool
    {
        $fields = [
            Menu::schema_fields_TITLE,
            Menu::schema_fields_ACTION,
            Menu::schema_fields_ICON,
            Menu::schema_fields_ORDER,
            Menu::schema_fields_PARENT_SOURCE,
            Menu::schema_fields_IS_ENABLE,
        ];
        foreach ($fields as $f) {
            $fv = $file[$f] ?? '';
            $dv = $db[$f] ?? '';
            if ((string)$fv !== (string)$dv) {
                return true;
            }
        }
        return false;
    }

    /**
     * 批量执行：DELETE → soft disable → UPDATE → INSERT
     */
    private function executeBatch(array $diff, array $file_menus, array $db_menus): void
    {
        $to_delete = $diff['to_delete'];
        $to_disable = $diff['to_disable'];
        $to_update = $diff['to_update'];
        $to_add_sources = $diff['to_add'];

        if (!empty($to_delete)) {
            $this->menu->reset()
                ->where(Menu::schema_fields_SOURCE, $to_delete, 'in')
                ->delete()
                ->fetch();
        }

        if (!empty($to_disable)) {
            $this->menu->reset()
                ->where(Menu::schema_fields_SOURCE, $to_disable, 'in')
                ->update([Menu::schema_fields_IS_ENABLE => 0])
                ->fetch();
        }

        $id_map = [];
        foreach ($db_menus as $source => $row) {
            $id_map[$source] = [
                'menu_id' => (int)($row[Menu::schema_fields_ID] ?? 0),
                'level' => (int)($row[Menu::schema_fields_LEVEL] ?? 0),
                'path' => (string)($row[Menu::schema_fields_PATH] ?? ''),
            ];
        }

        foreach ($to_update as $source => $file_data) {
            $this->applyMenuUpdate($source, $file_data, $db_menus[$source] ?? [], $id_map, $db_menus);
        }

        $to_add_ordered = $this->topologicalSortAdd($to_add_sources, $file_menus);
        foreach ($to_add_ordered as $source) {
            $file_data = $file_menus[$source] ?? [];
            if (empty($file_data)) {
                continue;
            }
            $this->applyMenuInsert($source, $file_data, $id_map);
        }
    }

    private function applyMenuUpdate(string $source, array $file_data, array $db_row, array &$id_map, array $db_menus): void
    {
        $parent_source = $file_data[Menu::schema_fields_PARENT_SOURCE] ?? '';
        $pid = 0;
        $level = 1;
        $path = '';
        $menu_id = (int)($db_row[Menu::schema_fields_ID] ?? 0);

        if ($parent_source !== '') {
            $pid = $id_map[$parent_source]['menu_id'] ?? 0;
            $parent_level = $id_map[$parent_source]['level'] ?? 0;
            $level = $parent_level + 1;
            $parent_path = $id_map[$parent_source]['path'] ?? ($db_menus[$parent_source][Menu::schema_fields_PATH] ?? '');
            $path = $parent_path ? ($parent_path . '/' . $menu_id) : (string)$menu_id;
        } else {
            $path = (string)$menu_id;
        }

        $row = [
            Menu::schema_fields_TITLE => $file_data[Menu::schema_fields_TITLE] ?? '',
            Menu::schema_fields_ACTION => $file_data[Menu::schema_fields_ACTION] ?? '',
            Menu::schema_fields_ICON => $file_data[Menu::schema_fields_ICON] ?? '',
            Menu::schema_fields_ORDER => (int)($file_data[Menu::schema_fields_ORDER] ?? 0),
            Menu::schema_fields_PARENT_SOURCE => $parent_source,
            Menu::schema_fields_PID => $pid,
            Menu::schema_fields_LEVEL => $level,
            Menu::schema_fields_PATH => $path,
            Menu::schema_fields_IS_ENABLE => (int)($file_data[Menu::schema_fields_IS_ENABLE] ?? 1),
        ];

        $this->menu->reset()
            ->where(Menu::schema_fields_SOURCE, $source)
            ->update($row)
            ->fetch();
    }

    private function applyMenuInsert(string $source, array $file_data, array &$id_map): void
    {
        $parent_source = $file_data[Menu::schema_fields_PARENT_SOURCE] ?? '';
        $pid = 0;
        $level = 1;

        if ($parent_source !== '') {
            $pid = $id_map[$parent_source]['menu_id'] ?? 0;
            $parent_level = $id_map[$parent_source]['level'] ?? 0;
            $level = $parent_level + 1;
        }

        $row = [
            Menu::schema_fields_SOURCE => $source,
            Menu::schema_fields_NAME => $file_data['name'] ?? $source,
            Menu::schema_fields_TITLE => $file_data[Menu::schema_fields_TITLE] ?? '',
            Menu::schema_fields_ACTION => $file_data[Menu::schema_fields_ACTION] ?? '',
            Menu::schema_fields_ICON => $file_data[Menu::schema_fields_ICON] ?? '',
            Menu::schema_fields_ORDER => (int)($file_data[Menu::schema_fields_ORDER] ?? 0),
            Menu::schema_fields_MODULE => $file_data[Menu::schema_fields_MODULE] ?? '',
            Menu::schema_fields_PARENT_SOURCE => $parent_source,
            Menu::schema_fields_PID => $pid,
            Menu::schema_fields_LEVEL => $level,
            Menu::schema_fields_PATH => '',
            Menu::schema_fields_IS_SYSTEM => (int)($file_data[Menu::schema_fields_IS_SYSTEM] ?? 0),
            Menu::schema_fields_IS_ENABLE => (int)($file_data[Menu::schema_fields_IS_ENABLE] ?? 1),
            Menu::schema_fields_IS_BACKEND => (int)($file_data[Menu::schema_fields_IS_BACKEND] ?? 1),
        ];

        $this->menu->clear()->setData($row)->save(true, 'source');
        $menu_id = (int)$this->menu->getData(Menu::schema_fields_ID);

        $parent_path = $parent_source !== '' ? ($id_map[$parent_source]['path'] ?? '') : '';
        $path = $parent_path ? ($parent_path . '/' . $menu_id) : (string)$menu_id;

        $id_map[$source] = ['menu_id' => $menu_id, 'level' => $level, 'path' => $path];

        $this->menu->reset()
            ->where(Menu::schema_fields_SOURCE, $source)
            ->update([Menu::schema_fields_PATH => $path])
            ->fetch();
    }

    /**
     * 按父先子后拓扑排序
     */
    private function topologicalSortAdd(array $sources, array $file_menus): array
    {
        $result = [];
        $seen = [];
        $queue = $sources;
        while (!empty($queue)) {
            $next = [];
            foreach ($queue as $source) {
                $parent = $file_menus[$source][Menu::schema_fields_PARENT_SOURCE] ?? '';
                if ($parent === '' || in_array($parent, $seen, true) || !isset($file_menus[$parent])) {
                    $result[] = $source;
                    $seen[] = $source;
                } else {
                    $next[] = $source;
                }
            }
            if (count($next) === count($queue)) {
                $result = array_merge($result, $next);
                break;
            }
            $queue = $next;
        }
        return $result;
    }

    private function getDisabledModules(): array
    {
        $all = Env::getInstance()->getModuleList();
        $active = array_keys(Env::getInstance()->getActiveModules());
        $disabled = [];
        foreach (array_keys($all) as $name) {
            if (!in_array($name, $active, true)) {
                $disabled[] = $name;
            }
        }
        return $disabled;
    }

    private function syncAcl(): void
    {
        $all_menus = $this->menu->reset()->order('order', 'ASC')->select()->fetchArray();
        $collected_menu_sources = array_column($all_menus, 'source');

        /** @var Acl $aclModel */
        $aclModel = ObjectManager::getInstance(Acl::class);

        if (!empty($collected_menu_sources)) {
            $aclModel->reset()
                ->where(Acl::schema_fields_TYPE, 'menus')
                ->where(Acl::schema_fields_SOURCE_ID, $collected_menu_sources, 'not in')
                ->update([Acl::schema_fields_TYPE => 'pc'])
                ->fetch();
            $aclModel->reset()
                ->where(Acl::schema_fields_TYPE, 'menus')
                ->where(Acl::schema_fields_SOURCE_ID, $collected_menu_sources, 'not in')
                ->delete()
                ->fetch();
        } else {
            $aclModel->reset()
                ->where(Acl::schema_fields_TYPE, 'menus')
                ->delete()
                ->fetch();
        }

        $acl_items = [];
        foreach ($all_menus as $menu) {
            $acl_items[] = [
                Acl::schema_fields_SOURCE_ID => $menu['source'],
                Acl::schema_fields_ORDER => $menu['order'],
                Acl::schema_fields_PARENT_SOURCE => $menu['parent_source'],
                Acl::schema_fields_TYPE => 'menus',
                Acl::schema_fields_CLASS => '',
                Acl::schema_fields_MODULE => $menu['module'],
                Acl::schema_fields_SOURCE_NAME => $menu['title'],
                Acl::schema_fields_ROUTER => '',
                Acl::schema_fields_ROUTE => trim($menu['action'] ?? '', '/'),
                Acl::schema_fields_METHOD => 'GET',
                Acl::schema_fields_DOCUMENT => ($menu['is_system'] ?? 0) ? __('系统菜单') : __('用户菜单'),
                Acl::schema_fields_REWRITE => '',
                Acl::schema_fields_ICON => $menu['icon'],
                Acl::schema_fields_IS_ENABLE => $menu['is_enable'],
                Acl::schema_fields_IS_BACKEND => $menu['is_backend'],
            ];
        }

        if (!empty($acl_items)) {
            $aclModel->reset()->insert($acl_items, 'source_id')->fetch();
        }
    }

    /**
     * 递归收集子菜单的 source（从当前 DB 查询）
     */
    private function collectChildMenuSources(string $parentSource, array &$sources): void
    {
        $children = $this->menu->reset()
            ->where(Menu::schema_fields_PARENT_SOURCE, $parentSource)
            ->select()
            ->fetchArray();
        foreach ($children as $child) {
            $s = $child[Menu::schema_fields_SOURCE] ?? '';
            if ($s !== '' && !in_array($s, $sources, true)) {
                $sources[] = $s;
                $this->collectChildMenuSources($s, $sources);
            }
        }
    }

    private function replaceModuleAction(array $menu, array &$modules_info): array
    {
        if (strpos($menu[Menu::schema_fields_ACTION] ?? '', '*') === false) {
            return $menu;
        }
        $module = $menu['module'] ?? '';
        $module_info = $modules_info[$module] ?? null;
        if (empty($module_info)) {
            $module_info = Env::getInstance()->getModuleInfo($module);
            $modules_info[$module] = $module_info;
            if (empty($module_info)) {
                throw new \Exception(__('模块不存在：%{1}', [$module]));
            }
        }
        $is_backend = $menu['is_backend'] ?? true;
        $backend_router = $module_info['backend_router'] ?? '';
        $front_router = $module_info['router'] ?? '';
        $fallback = strtolower($module);
        $router = $is_backend
            ? ($backend_router ?: $front_router ?: $fallback)
            : ($front_router ?: $fallback);
        $menu[Menu::schema_fields_ACTION] = str_replace('*', $router, $menu[Menu::schema_fields_ACTION]);
        return $menu;
    }
}
