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
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

/**
 * 菜单收集服务
 *
 * - 直接将 menu.xml 写入 weline_acl(type=menus)
 * - 采用「先全量 diff 再批量执行」策略
 * - 支持：不指定模块时收集所有，指定模块时仅处理该模块
 * - 禁用模块的菜单做软禁用（is_enable=0）
 */
class MenuCollector
{
    private Acl $acl;
    private MenuXmlReader $menuReader;

    public function __construct(
        Acl           $acl,
        MenuXmlReader $menuReader
    )
    {
        $this->acl = $acl;
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
        $db_menus = $this->buildDbAclMenus($modulesFilter);

        // 保护：未指定模块且文件端为空时，不执行破坏性操作
        if (empty($modulesFilter) && empty($file_menus)) {
            return [$modules_xml_menus, [], $modules_info, count($file_menus)];
        }

        $diff = $this->computeDiff($file_menus, $db_menus, $disabledModules);

        $this->executeBatch($diff, $file_menus, $db_menus);

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
                $menu['module'] = $module;
                $menu['parent_source'] = $menu['parent'] ?? '';
                $menu['route'] = trim($menu['action'] ?? '', '/');
                unset($menu['parent'], $menu['action']);

                $menu = $this->replaceModuleAction($menu, $modules_info);
                $menu['is_enable'] = in_array($module, $disabledModules, true) ? 0 : 1;

                $source = $menu['source'] ?? '';
                if ($source !== '') {
                    $file_menus[$source] = $menu;
                }
            }
        }
        return [$file_menus, $modules_xml_menus];
    }

    /**
     * 构建数据库端 ACL 菜单状态（source_id => row）
     * 直接从 weline_acl 读取 type=menus 的记录
     */
    private function buildDbAclMenus(array $modulesFilter): array
    {
        $this->acl->reset();
        $this->acl->where(Acl::schema_fields_TYPE, Acl::type_MENUS);
        
        if (!empty($modulesFilter)) {
            $this->acl->where(Acl::schema_fields_MODULE, $modulesFilter, 'in');
        }
        
        $rows = $this->acl->select()->fetchArray();
        $db_menus = [];
        foreach ($rows as $row) {
            $sourceId = $row[Acl::schema_fields_SOURCE_ID] ?? '';
            if ($sourceId !== '') {
                $db_menus[$sourceId] = $row;
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
            $module = $db_menus[$source][Acl::schema_fields_MODULE] ?? '';
            $children = [];
            $this->collectChildAclSources($source, $children);
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

    /**
     * 比较菜单数据是否变化
     */
    private function menuDataChanged(array $file, array $db): bool
    {
        $fields = [
            'source_name' => Acl::schema_fields_SOURCE_NAME,
            'route' => Acl::schema_fields_ROUTE,
            'icon' => Acl::schema_fields_ICON,
            'order' => Acl::schema_fields_ORDER,
            'parent_source' => Acl::schema_fields_PARENT_SOURCE,
            'is_enable' => Acl::schema_fields_IS_ENABLE,
            'module' => Acl::schema_fields_MODULE,
        ];
        
        foreach ($fields as $fileKey => $dbKey) {
            $fv = $file[$fileKey] ?? '';
            $dv = $db[$dbKey] ?? '';
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

        // 删除：从 ACL 表删除
        if (!empty($to_delete)) {
            $this->acl->reset()
                ->where(Acl::schema_fields_SOURCE_ID, $to_delete, 'in')
                ->delete()
                ->fetch();
        }

        // 软禁用：更新 is_enable = 0
        if (!empty($to_disable)) {
            $this->acl->reset()
                ->where(Acl::schema_fields_SOURCE_ID, $to_disable, 'in')
                ->update([Acl::schema_fields_IS_ENABLE => 0])
                ->fetch();
        }

        // 更新
        foreach ($to_update as $source => $file_data) {
            $this->applyAclUpdate($source, $file_data);
        }

        // 新增：按拓扑排序确保父节点先插入
        $to_add_ordered = $this->topologicalSortAdd($to_add_sources, $file_menus);
        foreach ($to_add_ordered as $source) {
            $file_data = $file_menus[$source] ?? [];
            if (empty($file_data)) {
                continue;
            }
            $this->applyAclInsert($source, $file_data);
        }
    }

    /**
     * 更新 ACL 菜单记录
     */
    private function applyAclUpdate(string $source, array $file_data): void
    {
        $row = [
            Acl::schema_fields_SOURCE_NAME => $file_data['title'] ?? $file_data['name'] ?? $source,
            Acl::schema_fields_ROUTE => $file_data['route'] ?? '',
            Acl::schema_fields_ICON => $file_data['icon'] ?? '',
            Acl::schema_fields_ORDER => (int)($file_data['order'] ?? 0),
            Acl::schema_fields_PARENT_SOURCE => $file_data['parent_source'] ?? '',
            Acl::schema_fields_MODULE => $file_data['module'] ?? '',
            Acl::schema_fields_IS_ENABLE => (int)($file_data['is_enable'] ?? 1),
            Acl::schema_fields_IS_BACKEND => (int)($file_data['is_backend'] ?? 1),
            Acl::schema_fields_TYPE => Acl::type_MENUS,
            Acl::schema_fields_DOCUMENT => ($file_data['is_system'] ?? 0) ? __('系统菜单') : __('用户菜单'),
        ];

        $this->acl->reset()
            ->where(Acl::schema_fields_SOURCE_ID, $source)
            ->update($row)
            ->fetch();
    }

    /**
     * 插入 ACL 菜单记录
     */
    private function applyAclInsert(string $source, array $file_data): void
    {
        $row = [
            Acl::schema_fields_SOURCE_ID => $source,
            Acl::schema_fields_SOURCE_NAME => $file_data['title'] ?? $file_data['name'] ?? $source,
            Acl::schema_fields_ROUTE => $file_data['route'] ?? '',
            Acl::schema_fields_ROUTER => '',
            Acl::schema_fields_ICON => $file_data['icon'] ?? '',
            Acl::schema_fields_ORDER => (int)($file_data['order'] ?? 0),
            Acl::schema_fields_PARENT_SOURCE => $file_data['parent_source'] ?? '',
            Acl::schema_fields_MODULE => $file_data['module'] ?? '',
            Acl::schema_fields_CLASS => '',
            Acl::schema_fields_METHOD => 'GET',
            Acl::schema_fields_REWRITE => '',
            Acl::schema_fields_TYPE => Acl::type_MENUS,
            Acl::schema_fields_DOCUMENT => ($file_data['is_system'] ?? 0) ? __('系统菜单') : __('用户菜单'),
            Acl::schema_fields_IS_ENABLE => (int)($file_data['is_enable'] ?? 1),
            Acl::schema_fields_IS_BACKEND => (int)($file_data['is_backend'] ?? 1),
        ];

        $this->acl->reset()->setData($row)->save(true, Acl::schema_fields_SOURCE_ID);
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
                $parent = $file_menus[$source]['parent_source'] ?? '';
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

    /**
     * 获取禁用的模块列表
     */
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

    /**
     * 递归收集子 ACL 菜单的 source_id
     */
    private function collectChildAclSources(string $parentSource, array &$sources): void
    {
        $children = $this->acl->reset()
            ->where(Acl::schema_fields_PARENT_SOURCE, $parentSource)
            ->where(Acl::schema_fields_TYPE, Acl::type_MENUS)
            ->select()
            ->fetchArray();
        foreach ($children as $child) {
            $s = $child[Acl::schema_fields_SOURCE_ID] ?? '';
            if ($s !== '' && !in_array($s, $sources, true)) {
                $sources[] = $s;
                $this->collectChildAclSources($s, $sources);
            }
        }
    }

    /**
     * 替换模块路由占位符
     */
    private function replaceModuleAction(array $menu, array &$modules_info): array
    {
        if (strpos($menu['route'] ?? '', '*') === false) {
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
        $menu['route'] = str_replace('*', $router, $menu['route']);
        return $menu;
    }
}
