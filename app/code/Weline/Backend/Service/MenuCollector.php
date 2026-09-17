<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://aiweline.com
 */

namespace Weline\Backend\Service;

use Weline\Acl\Api\Authorization\AccessMode;
use Weline\Acl\Api\Resource\MenuRegistryInterface;
use Weline\Acl\Service\Resource\SourceIdRenameMap;
use Weline\Acl\Service\Resource\SourceIdRenameMigrator;
use Weline\Backend\Config\MenuXmlReader;
use Weline\Backend\Model\Menu;
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
    /**
     * 收集过程重入保护：事件/钩子链中若再次触发 before_route_collection 不再执行全量收集，避免循环或内存溢出。
     */
    private static bool $collecting = false;

    /**
     * 历史父级 source 兼容映射。
     * 统一在收集阶段归一化，避免旧模块菜单父级失效。
     *
     * @var array<string, string>
     */
    private const LEGACY_PARENT_SOURCE_MAP = [
        'Weline_Backend::system_service' => 'Weline_Backend::system_service_group',
    ];

    private MenuRegistryInterface $menuRegistry;
    private MenuXmlReader $menuReader;

    public function __construct(
        MenuRegistryInterface $menuRegistry,
        MenuXmlReader $menuReader
    )
    {
        $this->menuRegistry = $menuRegistry;
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
        [$modules_xml_menus, , , $file_menu_count, $diff] = $this->collectInternal($modulesFilter);
        return [
            'file_menu_count' => $file_menu_count,
            'raw_config_count' => $fileListCount,
            'diff' => $diff,
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
        [$modules_xml_menus, , $modules_info, , ] = $this->collectInternal($modulesFilter);
        return [$modules_xml_menus, [], $modules_info];
    }

    /**
     * @return array{0: array, 1: array, 2: array, 3: int, 4: array} [modules_xml_menus, update_items, modules_info, file_menu_count, diff]
     */
    private function collectInternal(array $modulesFilter = []): array
    {
        if (self::$collecting) {
            return [[], [], [], 0, []];
        }
        self::$collecting = true;
        try {
            $result = $this->doCollectInternal($modulesFilter);
            $this->menuReader->commitPendingFingerprints();

            return $result;
        } catch (\Throwable $e) {
            $this->menuReader->discardPendingFingerprints();
            throw $e;
        } finally {
            self::$collecting = false;
            MenuXmlReader::clearForceFullRequest();
        }
    }

    /**
     * @return array{0: array, 1: array, 2: array, 3: int, 4: array}
     */
    private function doCollectInternal(array $modulesFilter = []): array
    {
        $modules_info = [];
        $disabledModules = $this->getDisabledModules();

        [$file_menus, $modules_xml_menus] = $this->buildFileMenus($modulesFilter, $disabledModules, $modules_info);
        $file_sources = array_keys($file_menus);

        // 保护：未指定模块且文件端为空时，不执行破坏性操作
        // （指纹全命中跳过解析 → 空 file_menus；但 DB 产物为空时 MenuXmlReader 已强制重读）
        if (empty($modulesFilter) && empty($file_menus)) {
            return [$modules_xml_menus, [], $modules_info, count($file_menus), []];
        }

        // 指纹跳过导致仅部分模块进入 file_menus 时，diff 必须限定到这些模块，避免误删未扫模块菜单
        $effectiveFilter = $modulesFilter;
        if ($effectiveFilter === [] && $modules_xml_menus !== []) {
            $allFiles = $this->menuReader->getFileList();
            if (\count($modules_xml_menus) < \count($allFiles)) {
                $effectiveFilter = \array_keys($modules_xml_menus);
            }
        }

        // 搬家：自动推断（同模块 route/title 一对一）+ 可选 renamed_from 覆盖；须在 diff 删旧 id 前完成。
        $renameMap = \array_merge(
            SourceIdRenameMap::ROLE_ACCESS,
            $this->inferAutoRenameMap($file_menus, $effectiveFilter),
            $this->collectXmlRenameMap($file_menus)
        );
        ObjectManager::getInstance(SourceIdRenameMigrator::class)->migrate($renameMap);
        $this->migrateRenamedMenuRows($renameMap);
        $file_menus = $this->remapParentsThroughRenameMap($file_menus, $renameMap);

        // 流式遍历 DB，不构建完整 db_menus，直接产出 diff 与 seen_db_sources，避免大表内存溢出
        [$diff, $seen_db_sources] = $this->streamDbAndComputeDiff($file_menus, $file_sources, $effectiveFilter, $disabledModules);
        $this->validateMenuParentChain($file_menus, $seen_db_sources);

        $this->executeBatch($diff, $file_menus);
        $this->syncLegacyMenuTable($file_menus, $effectiveFilter);
        $this->queueDestinationFingerprints(
            $effectiveFilter !== [] ? $effectiveFilter : \array_keys($modules_xml_menus)
        );

        return [$modules_xml_menus, [], $modules_info, count($file_menus), $diff];
    }

    /**
     * 框架约定校验：menu.xml 的 parent_source 必须能在「本轮文件 + 本轮 DB + 全局 ACL 库」中找到。
     * 增量时 seen_db_sources 不全，缺失父级一律批量读库对比，不以内存集合臆断断层。
     *
     * @param array<string, array> $fileMenus
     * @param array<string, true> $seenDbSources 流式收集时仅保留 DB 中出现的 source_id 集合，不保留整行
     * @throws \Exception
     */
    private function validateMenuParentChain(array $fileMenus, array $seenDbSources): void
    {
        if (empty($fileMenus)) {
            return;
        }

        $knownSources = [];
        foreach ($fileMenus as $source => $menu) {
            $knownSources[(string)$source] = true;
        }
        foreach ($seenDbSources as $source => $_) {
            $knownSources[(string)$source] = true;
        }

        $missingParents = [];
        foreach ($fileMenus as $source => $menu) {
            $parentSource = \trim((string)($menu['parent_source'] ?? ''));
            if ($parentSource === '' || isset($knownSources[$parentSource])) {
                continue;
            }
            $missingParents[$parentSource] = true;
        }

        // 读库对比：批量查出全局 ACL 里真实存在的跨模块父级
        if ($missingParents !== []) {
            foreach ($this->menuRegistry->filterExistingManagedSources(\array_keys($missingParents)) as $sid => $_) {
                $knownSources[$sid] = true;
                unset($missingParents[$sid]);
            }
        }

        foreach ($fileMenus as $source => $menu) {
            $parentSource = \trim((string)($menu['parent_source'] ?? ''));
            if ($parentSource === '' || isset($knownSources[$parentSource])) {
                continue;
            }

            $module = (string)($menu['module'] ?? 'unknown');
            throw new \Exception(
                __('框架约定错误：菜单 ACL 断层。菜单 %{1}（模块 %{2}）声明了不存在的父级 %{3}（文件与数据库均未找到）。请修复 menu.xml 的 parent/source 链。', [
                    $source,
                    $module,
                    $parentSource,
                ])
            );
        }
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
        $batch = new \Weline\Framework\Php\FiberTaskBatch(null, true, 'WELINE_MENU_FIBER_CONCURRENCY');
        $batch->mapModules(
            $modules_xml_menus,
            function (string $module, mixed $menus) use ($disabledModules, &$modules_info): array {
                $rows = [];
                $data = \is_array($menus) ? ($menus['data'] ?? []) : [];
                foreach ($data as $menu) {
                    if (!\is_array($menu)) {
                        continue;
                    }
                    $menu['module'] = $module;
                    $menu['parent_source'] = $this->normalizeParentSource((string)($menu['parent'] ?? ''));
                    $menu['route'] = trim($menu['action'] ?? '', '/');
                    $menu['access_mode'] = $this->normalizeAccessMode(
                        (string)($menu['access_mode'] ?? $menu['accessMode'] ?? ''),
                        'GET'
                    );
                    $menu['scope_group'] = (string)($menu['scope_group'] ?? $menu['scopeGroup'] ?? '');
                    $menu['api_exposable'] = $this->normalizeMenuBoolean($menu['api_exposable'] ?? $menu['apiExposable'] ?? false);
                    $menu['renamed_from'] = \trim((string)($menu['renamed_from'] ?? $menu['renamedFrom'] ?? ''));
                    unset($menu['parent'], $menu['action']);

                    $menu = $this->replaceModuleAction($menu, $modules_info);
                    $menu['route'] = strtolower(trim((string)($menu['route'] ?? ''), '/'));
                    $menu['is_enable'] = in_array($module, $disabledModules, true) ? 0 : 1;

                    $source = $menu['source'] ?? '';
                    if ($source !== '') {
                        $rows[(string)$source] = $menu;
                    }
                }

                return $rows;
            },
            static function (string $phase, array $ctx) use (&$file_menus): void {
                if ($phase !== 'task' || !($ctx['ok'] ?? false)) {
                    return;
                }
                $rows = $ctx['result'] ?? null;
                if (!\is_array($rows)) {
                    return;
                }
                foreach ($rows as $source => $menu) {
                    $file_menus[(string)$source] = $menu;
                }
            },
            [
                'env' => 'WELINE_MENU_FIBER_CONCURRENCY',
                'fail_fast' => true,
                'label' => 'menu-normalize-collect',
                'keep_results' => false,
            ]
        );
        unset($batch);

        return [$file_menus, $modules_xml_menus];
    }

    /**
     * 归一化父级 source，兼容历史命名。
     */
    private function normalizeParentSource(string $parentSource): string
    {
        return self::LEGACY_PARENT_SOURCE_MAP[$parentSource] ?? $parentSource;
    }

    /**
     * @param array<string, array> $fileMenus
     * @return array<string, string> old => new
     */
    private function collectXmlRenameMap(array $fileMenus): array
    {
        $map = [];
        foreach ($fileMenus as $newSource => $menu) {
            $old = \trim((string)($menu['renamed_from'] ?? ''));
            $newSource = (string)$newSource;
            if ($old === '' || $old === $newSource) {
                continue;
            }
            $map[$old] = $newSource;
        }

        return $map;
    }

    /**
     * 自动推断 source 搬家：DB 有、文件无 与 文件有、DB 无 的节点，在同模块内按稳定键一对一配对。
     * 优先 route（非空）；分组/无路由则用 title。键冲突（多对一）不配对，避免误迁。
     * 无需手写 renamed_from；显式 renamed_from 仍可覆盖。
     *
     * @param array<string, array> $fileMenus
     * @param string[] $modulesFilter
     * @return array<string, string> old => new
     */
    private function inferAutoRenameMap(array $fileMenus, array $modulesFilter): array
    {
        if ($fileMenus === []) {
            return [];
        }

        $fileSources = [];
        foreach (\array_keys($fileMenus) as $source) {
            $fileSources[(string)$source] = true;
        }

        $dbRows = $this->menuRegistry->listManagedMenus($modulesFilter);
        $dbOnly = []; // source_id => row
        foreach ($dbRows as $row) {
            $sourceId = (string)($row['source_id'] ?? '');
            if ($sourceId === '' || isset($fileSources[$sourceId])) {
                continue;
            }
            $dbOnly[$sourceId] = $row;
        }
        if ($dbOnly === []) {
            return [];
        }

        $dbSourceSet = [];
        foreach ($dbRows as $row) {
            $sid = (string)($row['source_id'] ?? '');
            if ($sid !== '') {
                $dbSourceSet[$sid] = true;
            }
        }

        $fileOnly = [];
        foreach ($fileMenus as $source => $menu) {
            $source = (string)$source;
            if ($source === '' || isset($dbSourceSet[$source])) {
                continue;
            }
            $fileOnly[$source] = $menu;
        }
        if ($fileOnly === []) {
            return [];
        }

        $indexFile = $this->buildRenameMatchIndex($fileOnly, true);
        $indexDb = $this->buildRenameMatchIndex($dbOnly, false);

        $map = [];
        $usedNew = [];
        foreach ($indexDb as $key => $oldSources) {
            if (\count($oldSources) !== 1) {
                continue;
            }
            $newSources = $indexFile[$key] ?? [];
            if (\count($newSources) !== 1) {
                continue;
            }
            $old = $oldSources[0];
            $new = $newSources[0];
            if ($old === $new || isset($usedNew[$new])) {
                continue;
            }
            $map[$old] = $new;
            $usedNew[$new] = true;
        }

        return $map;
    }

    /**
     * @param array<string, array> $rows source => menu/db row
     * @return array<string, list<string>> matchKey => source ids
     */
    private function buildRenameMatchIndex(array $rows, bool $fromFile): array
    {
        $index = [];
        foreach ($rows as $source => $row) {
            $source = (string)$source;
            $module = \trim((string)($row['module'] ?? ''));
            if ($module === '') {
                continue;
            }
            $route = \strtolower(\trim((string)($row['route'] ?? ''), '/'));
            if ($route !== '') {
                $key = 'r|' . $module . '|' . $route;
            } else {
                $title = \trim((string)($fromFile
                    ? ($row['title'] ?? $row['name'] ?? $row['source_name'] ?? '')
                    : ($row['source_name'] ?? $row['title'] ?? $row['name'] ?? '')));
                if ($title === '') {
                    continue;
                }
                $key = 't|' . $module . '|' . $title;
            }
            $index[$key][] = $source;
        }

        return $index;
    }

    /**
     * @param array<string, string> $renameMap old => new
     */
    private function migrateRenamedMenuRows(array $renameMap): void
    {
        if ($renameMap === []) {
            return;
        }
        foreach ($renameMap as $old => $new) {
            $this->menuRegistry->renameManagedMenuSource($old, $new);
            $this->renameLegacyMenuSource($old, $new);
        }
    }

    private function renameLegacyMenuSource(string $oldSource, string $newSource): void
    {
        /** @var Menu $menuModel */
        $menuModel = ObjectManager::getInstance(Menu::class, [], false);
        $childCount = (int)$menuModel->reset()
            ->where(Menu::schema_fields_PARENT_SOURCE, $oldSource)
            ->total();
        if ($childCount > 0) {
            $menuModel->reset()
                ->where(Menu::schema_fields_PARENT_SOURCE, $oldSource)
                ->update([Menu::schema_fields_PARENT_SOURCE => $newSource])
                ->fetch();
        }

        $existingNew = ObjectManager::getInstance(Menu::class, [], false)
            ->reset()
            ->where(Menu::schema_fields_SOURCE, $newSource)
            ->find()
            ->fetch();
        if ((string)$existingNew->getData(Menu::schema_fields_SOURCE) === $newSource) {
            ObjectManager::getInstance(Menu::class, [], false)
                ->reset()
                ->where(Menu::schema_fields_SOURCE, $oldSource)
                ->delete()
                ->fetch();

            return;
        }

        $old = ObjectManager::getInstance(Menu::class, [], false)
            ->reset()
            ->where(Menu::schema_fields_SOURCE, $oldSource)
            ->find()
            ->fetch();
        if ((string)$old->getData(Menu::schema_fields_SOURCE) !== $oldSource) {
            return;
        }

        $row = [
            Menu::schema_fields_NAME => (string)$old->getData(Menu::schema_fields_NAME),
            Menu::schema_fields_TITLE => (string)$old->getData(Menu::schema_fields_TITLE),
            Menu::schema_fields_SOURCE => $newSource,
            Menu::schema_fields_PID => (int)$old->getData(Menu::schema_fields_PID),
            Menu::schema_fields_LEVEL => (int)$old->getData(Menu::schema_fields_LEVEL),
            Menu::schema_fields_PATH => (string)$old->getData(Menu::schema_fields_PATH),
            Menu::schema_fields_PARENT_SOURCE => (string)$old->getData(Menu::schema_fields_PARENT_SOURCE),
            Menu::schema_fields_ACTION => (string)$old->getData(Menu::schema_fields_ACTION),
            Menu::schema_fields_MODULE => (string)$old->getData(Menu::schema_fields_MODULE),
            Menu::schema_fields_ICON => (string)$old->getData(Menu::schema_fields_ICON),
            Menu::schema_fields_ORDER => (int)$old->getData(Menu::schema_fields_ORDER),
            Menu::schema_fields_IS_SYSTEM => (int)$old->getData(Menu::schema_fields_IS_SYSTEM),
            Menu::schema_fields_IS_ENABLE => (int)$old->getData(Menu::schema_fields_IS_ENABLE),
            Menu::schema_fields_IS_BACKEND => (int)$old->getData(Menu::schema_fields_IS_BACKEND),
        ];
        ObjectManager::getInstance(Menu::class, [], false)
            ->reset()
            ->clearData()
            ->setData($row)
            ->save();
        ObjectManager::getInstance(Menu::class, [], false)
            ->reset()
            ->where(Menu::schema_fields_SOURCE, $oldSource)
            ->delete()
            ->fetch();
    }

    /**
     * @param array<string, array> $fileMenus
     * @param array<string, string> $renameMap
     * @return array<string, array>
     */
    private function remapParentsThroughRenameMap(array $fileMenus, array $renameMap): array
    {
        if ($renameMap === []) {
            return $fileMenus;
        }
        foreach ($fileMenus as $source => $menu) {
            $parent = (string)($menu['parent_source'] ?? '');
            if ($parent !== '' && isset($renameMap[$parent])) {
                $fileMenus[$source]['parent_source'] = $renameMap[$parent];
            }
        }

        return $fileMenus;
    }

    /**
     * @param list<string>|array<int|string, string> $modules
     */
    private function queueDestinationFingerprints(array $modules): void
    {
        $updates = [];
        foreach ($modules as $module) {
            $module = \trim((string)$module);
            if ($module === '') {
                continue;
            }
            $updates['menu:dest:' . $module] = $this->menuRegistry->destinationFingerprint($module);
        }
        if ($updates !== []) {
            $this->menuReader->mergePendingFingerprints($updates);
        }
    }

    private function normalizeMenuBoolean(mixed $value): int
    {
        return (int)filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * 流式遍历 DB 菜单行，直接计算 diff，不构建完整 db_menus，避免大表内存溢出。
     * 只保留：seen_db_sources（source_id 集合）、to_update（仅变更项）、removed 列表（用于展开子节点后得到 to_delete/to_disable）。
     *
     * @return array{0: array{to_add: string[], to_update: array, to_delete: string[], to_disable: string[]}, 1: array<string, true>}
     */
    private function streamDbAndComputeDiff(
        array $file_menus,
        array $file_sources,
        array $modulesFilter,
        array $disabledModules
    ): array {
        $dbMenus = $this->menuRegistry->listManagedMenus($modulesFilter);

        $seen_db_sources = [];
        $to_update = [];
        $removed = []; // [source_id => module]，用于后续展开子节点并区分 to_delete / to_disable

        foreach ($dbMenus as $row) {
            $sourceId = (string)($row['source_id'] ?? '');
            if ($sourceId === '') {
                continue;
            }
            $seen_db_sources[$sourceId] = true;

            if (!isset($file_menus[$sourceId])) {
                $removed[$sourceId] = (string)($row['module'] ?? '');
                continue;
            }

            if ($this->menuDataChanged($file_menus[$sourceId], $row)) {
                $to_update[$sourceId] = $file_menus[$sourceId];
            }
        }

        $to_add = array_values(array_diff($file_sources, array_keys($seen_db_sources)));

        $sourcesToDelete = [];
        $sourcesToDisable = [];
        foreach ($removed as $source => $module) {
            $children = [];
            $visited = [];
            $this->collectChildAclSources($source, $children, $visited);
            $children = array_values(array_diff($children, $file_sources));
            $all = array_merge([$source], $children);
            if ($this->shouldSoftDisableRemovedMenu($module, $disabledModules)) {
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

        $diff = [
            'to_add' => $to_add,
            'to_update' => $to_update,
            'to_delete' => $sourcesToDelete,
            'to_disable' => $sourcesToDisable,
        ];

        return [$diff, $seen_db_sources];
    }

    /**
     * 已禁用但仍存在于代码目录中的模块，菜单保留为软禁用。
     * 若模块目录已被删除（异常卸载），则必须直接删除残留菜单，不能仅软禁用。
     */
    private function shouldSoftDisableRemovedMenu(string $module, array $disabledModules): bool
    {
        if ($module === '' || !in_array($module, $disabledModules, true)) {
            return false;
        }

        $moduleInfo = Env::getInstance()->getModuleByName($module);
        $basePath = (string)($moduleInfo['base_path'] ?? '');
        if ($basePath === '') {
            return false;
        }

        return is_dir($basePath);
    }

    /**
     * 比较菜单数据是否变化
     */
    private function menuDataChanged(array $file, array $db): bool
    {
        $fields = [
            'source_name' => 'source_name',
            'route' => 'route',
            'icon' => 'icon',
            'order' => 'order',
            'parent_source' => 'parent_source',
            'is_enable' => 'is_enable',
            'module' => 'module',
            'access_mode' => 'access_mode',
            'scope_group' => 'scope_group',
            'api_exposable' => 'api_exposable',
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
    private function executeBatch(array $diff, array $file_menus): void
    {
        $to_delete = $diff['to_delete'];
        $to_disable = $diff['to_disable'];
        $to_update = $diff['to_update'];
        $to_add_sources = $diff['to_add'];

        // 删除：从 ACL 表删除
        if (!empty($to_delete)) {
            $this->menuRegistry->deleteManagedMenus($to_delete);
        }

        // 软禁用：更新 is_enable = 0
        if (!empty($to_disable)) {
            $this->menuRegistry->disableManagedMenus($to_disable);
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
        $this->menuRegistry->upsertManagedMenu($source, $file_data);
    }

    /**
     * 插入 ACL 菜单记录
     */
    private function applyAclInsert(string $source, array $file_data): void
    {
        $this->menuRegistry->upsertManagedMenu($source, $file_data);
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
     * Keep the legacy m_menu table aligned for older admin helpers that still read it.
     * 删除范围必须与本轮 diff 模块范围一致：增量/指纹跳过时不得 not-in 清掉未参与模块的菜单。
     *
     * @param array<string, array> $fileMenus
     * @param string[] $modulesFilter 非空时仅同步这些模块的 legacy 行
     */
    private function syncLegacyMenuTable(array $fileMenus, array $modulesFilter = []): void
    {
        $fileSources = array_keys($fileMenus);

        /** @var Menu $menuModel */
        $menuModel = ObjectManager::getInstance(Menu::class, [], false);

        if ($fileSources === []) {
            // 指定模块且文件端为空：只清该范围内的 legacy；禁止无过滤整表 delete。
            if ($modulesFilter !== []) {
                $menuModel->reset()
                    ->where(Menu::schema_fields_MODULE, $modulesFilter, 'in')
                    ->delete()
                    ->fetch();
            }

            return;
        }

        $deleter = $menuModel->reset()
            ->where(Menu::schema_fields_SOURCE, $fileSources, 'not in');
        if ($modulesFilter !== []) {
            $deleter->where(Menu::schema_fields_MODULE, $modulesFilter, 'in');
        }
        $deleter->delete()->fetch();

        $sourceMeta = [];
        foreach ($this->topologicalSortAdd($fileSources, $fileMenus) as $source) {
            $menu = $fileMenus[$source] ?? [];
            if (empty($menu)) {
                continue;
            }

            $parentSource = (string)($menu['parent_source'] ?? '');
            $parentMeta = $sourceMeta[$parentSource] ?? null;
            $pid = $parentMeta['id'] ?? 0;
            $level = $parentMeta ? ((int)$parentMeta['level'] + 1) : 1;

            $row = [
                Menu::schema_fields_NAME => (string)($menu['name'] ?? $source),
                Menu::schema_fields_TITLE => (string)($menu['title'] ?? $menu['name'] ?? $source),
                Menu::schema_fields_SOURCE => $source,
                Menu::schema_fields_PID => $pid,
                Menu::schema_fields_LEVEL => $level,
                Menu::schema_fields_PATH => '',
                Menu::schema_fields_PARENT_SOURCE => $parentSource,
                Menu::schema_fields_ACTION => (string)($menu['route'] ?? ''),
                Menu::schema_fields_MODULE => (string)($menu['module'] ?? ''),
                Menu::schema_fields_ICON => (string)($menu['icon'] ?? ''),
                Menu::schema_fields_ORDER => (int)($menu['order'] ?? 0),
                Menu::schema_fields_IS_SYSTEM => (int)($menu['is_system'] ?? 1),
                Menu::schema_fields_IS_ENABLE => (int)($menu['is_enable'] ?? 1),
                Menu::schema_fields_IS_BACKEND => (int)($menu['is_backend'] ?? 1),
            ];

            /** @var Menu $lookup */
            $lookup = ObjectManager::getInstance(Menu::class, [], false);
            $lookup->reset()->clearData();
            $saved = $lookup->where(Menu::schema_fields_SOURCE, $source)->find()->fetch();
            if ($saved->getData(Menu::schema_fields_ID)) {
                /** @var Menu $updater */
                $updater = ObjectManager::getInstance(Menu::class, [], false);
                $updater->reset()->clearData();
                $updater->where(Menu::schema_fields_SOURCE, $source)
                    ->update($row)
                    ->fetch();
            } else {
                /** @var Menu $creator */
                $creator = ObjectManager::getInstance(Menu::class, [], false);
                $creator->reset()->clearData();
                $creator->setData($row)->save();

                /** @var Menu $createdLookup */
                $createdLookup = ObjectManager::getInstance(Menu::class, [], false);
                $createdLookup->reset()->clearData();
                $saved = $createdLookup->where(Menu::schema_fields_SOURCE, $source)->find()->fetch();
            }
            $id = (int)$saved->getData(Menu::schema_fields_ID);
            $path = $parentMeta && $parentMeta['path'] !== ''
                ? $parentMeta['path'] . '/' . $id
                : (string)$id;

            /** @var Menu $pathUpdater */
            $pathUpdater = ObjectManager::getInstance(Menu::class, [], false);
            $pathUpdater->reset()->clearData();
            $pathUpdater->where(Menu::schema_fields_SOURCE, $source)
                ->update([
                    Menu::schema_fields_PID => $pid,
                    Menu::schema_fields_LEVEL => $level,
                    Menu::schema_fields_PATH => $path,
                ])
                ->fetch();

            $sourceMeta[$source] = [
                'id' => $id,
                'level' => $level,
                'path' => $path,
            ];
        }
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
     * 递归收集子 ACL 菜单的 source_id。
     * 使用 $visited 防止 parent_source 成环时无限递归（脏数据或异常配置）。
     * 使用迭代器读取子节点，减少单次查询内存占用。
     *
     * @param array<string, true> $visited 本轮已访问的 parent_source，避免环
     */
    private function collectChildAclSources(string $parentSource, array &$sources, array &$visited = []): void
    {
        if (isset($visited[$parentSource])) {
            return;
        }
        $visited[$parentSource] = true;

        foreach ($this->menuRegistry->getManagedChildSources($parentSource) as $s) {
            if ($s !== '' && !in_array($s, $sources, true)) {
                $sources[] = $s;
                $this->collectChildAclSources($s, $sources, $visited);
            }
        }
    }

    private function normalizeAccessMode(?string $accessMode, ?string $httpMethod): string
    {
        $accessMode = strtolower(trim((string)$accessMode));
        if ($accessMode === AccessMode::READ || $accessMode === AccessMode::EDIT) {
            return $accessMode;
        }
        $method = strtoupper(trim((string)$httpMethod));
        return ($method === 'GET' || $method === 'HEAD') ? AccessMode::READ : AccessMode::EDIT;
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
