<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/12 21:15:14
 */

namespace Weline\Acl\Model;

use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\Menu;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
/** 复合主键 (role_id, source_id) 用 UNIQUE 约束实现，框架暂不支持复合主键声明 */
#[Table(comment: '角色资源访问表')]
#[Index(name: 'uk_role_source', columns: ['role_id', 'source_id'], type: 'UNIQUE', comment: '角色+资源唯一')]
class RoleAccess extends Model
{

    #[Col(type: 'int', nullable: false, comment: '角色ID')]
    public const schema_fields_ID = 'role_id';
    #[Col(type: 'int', nullable: false, comment: '角色ID')]
    public const schema_fields_ROLE_ID = 'role_id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '资源ID')]
    public const schema_fields_SOURCE_ID = 'source_id';

    private array $exist = [];
/**
     * @DESC          # 获取树形菜单【携带角色权限信息】
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/7/3 8:49
     * 参数区：
     *
     * @param string $main_field 主要字段
     * @param string $parent_id_field 父级字段
     * @param string|int $parent_id_value 父级字段值【用于判别顶层数据】
     * @param string $order_field 排序字段
     * @param string $order_sort 排序方式
     *
     * @return array
     */
    public function getTreeWithRole(
        ?Role      $role = null,
        string     $main_field = 'main_table.source_id',
        string     $parent_id_field = 'parent_source',
        string|int $parent_id_value = '',
        string     $order_field = 'source_id',
        string     $order_sort = 'ASC'
    ): array
    {
        return $this->buildTreeWithMenuAndAcl($role);
    }

    /**
     * 菜单表 + ACL 表关系树：菜单为主干，acl.parent_source 命中菜单的挂到菜单下，无菜单父级的 ACL 按 parent_source 成树
     *
     * @return Acl[] 顶层节点数组（Acl 兼容节点，含 sub）
     */
    private function buildTreeWithMenuAndAcl(Role $role): array
    {
        $roleId = (int) $role->getId();
        /** @var Menu $menuModel */
        $menuModel = ObjectManager::getInstance(Menu::class, [], false);
        // 加载全部菜单（含禁用模块），权限树需展示完整层级供分配，不按 is_enable 过滤
        $menuRows = $menuModel->reset()
            ->order(Menu::schema_fields_PARENT_SOURCE, 'ASC')
            ->order(Menu::schema_fields_ORDER, 'ASC')
            ->select()
            ->fetchArray();
        $menuSources = array_column($menuRows, Menu::schema_fields_SOURCE);
        $menuByParent = $this->groupMenusByParent($menuRows);

        /** @var Acl $aclModel */
        $aclModel = ObjectManager::getInstance(Acl::class, [], false);
        $aclWithRole = $aclModel->reset()
            ->joinModel(RoleAccess::class, 'ra', 'main_table.source_id=ra.source_id and ra.role_id=' . $roleId, 'left')
            ->order(Acl::schema_fields_PARENT_SOURCE, 'ASC')
            ->order(Acl::schema_fields_ORDER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        $roleSelectedSources = $this->getRoleSelectedSources($roleId);
        $aclByParent = $this->groupAclByParent($aclWithRole, $roleSelectedSources, 'ra_role_id');
        $menuSourceSet = array_flip($menuSources);

        $topMenuNodes = $this->buildMenuTreeLevel('', $menuByParent, $aclByParent, $menuSourceSet, $roleSelectedSources);
        $topAclOnlyNodes = $this->buildAclOnlyTreeLevel('', $aclByParent, $menuSourceSet, $roleSelectedSources);

        return array_merge($topMenuNodes, $topAclOnlyNodes);
    }

    /** @return array<string, list<array>> */
    private function groupMenusByParent(array $menuRows): array
    {
        $byParent = ['' => []];
        foreach ($menuRows as $row) {
            $parent = (string) ($row[Menu::schema_fields_PARENT_SOURCE] ?? '');
            if (!isset($byParent[$parent])) {
                $byParent[$parent] = [];
            }
            $byParent[$parent][] = $row;
        }
        foreach ($byParent as $p => $list) {
            usort($byParent[$p], static fn($a, $b) => (int) ($a[Menu::schema_fields_ORDER] ?? 0) <=> (int) ($b[Menu::schema_fields_ORDER] ?? 0));
        }
        return $byParent;
    }

    /**
     * @param Acl[] $aclItems
     * @return array<string, list<Acl>>
     */
    private function groupAclByParent(array $aclItems, array $roleSelectedSources, string $roleIdKey): array
    {
        $byParent = ['' => []];
        foreach ($aclItems as $acl) {
            $sid = (string) ($acl->getData(Acl::schema_fields_SOURCE_ID) ?? '');
            $acl->setData('role_id', $acl->getData($roleIdKey) ?: ($roleSelectedSources[$sid] ?? null));
            $parent = (string) ($acl->getParentSource());
            if (!isset($byParent[$parent])) {
                $byParent[$parent] = [];
            }
            $byParent[$parent][] = $acl;
        }
        foreach ($byParent as $p => $list) {
            usort($byParent[$p], static fn($a, $b) => (int) ($a->getOrder() ?? 0) <=> (int) ($b->getOrder() ?? 0));
        }
        return $byParent;
    }

    private function getRoleSelectedSources(int $roleId): array
    {
        $ra = ObjectManager::getInstance(RoleAccess::class, [], false);
        $rows = $ra->clear()->where(RoleAccess::schema_fields_ROLE_ID, $roleId)->select()->fetchArray();
        $out = [];
        foreach ($rows as $row) {
            $sid = $row[RoleAccess::schema_fields_SOURCE_ID] ?? '';
            if ($sid !== '') {
                $out[$sid] = true;
            }
        }
        return $out;
    }

    /**
     * @param array<string, list<array>> $menuByParent
     * @param array<string, list<Acl>> $aclByParent
     * @param array<int|string, true> $menuSourceSet
     * @param array<string, true> $roleSelectedSources
     * @return Acl[]
     */
    private function buildMenuTreeLevel(
        string $parentSource,
        array $menuByParent,
        array $aclByParent,
        array $menuSourceSet,
        array $roleSelectedSources
    ): array {
        $nodes = [];
        $menus = $menuByParent[$parentSource] ?? [];
        foreach ($menus as $menuRow) {
            $source = (string) ($menuRow[Menu::schema_fields_SOURCE] ?? '');
            if ($source === '') {
                continue;
            }
            /** @var Acl $node */
            $node = ObjectManager::getInstance(Acl::class, [], false);
            $node->setData(Acl::schema_fields_SOURCE_ID, $source);
            $node->setData(Acl::schema_fields_SOURCE_NAME, $menuRow[Menu::schema_fields_TITLE] ?? $source);
            $node->setData(Acl::schema_fields_TYPE, Acl::type_MENUS);
            $node->setData(Acl::schema_fields_ICON, $menuRow[Menu::schema_fields_ICON] ?? '');
            $node->setData(Acl::schema_fields_MODULE, $menuRow[Menu::schema_fields_MODULE] ?? '');
            $node->setData(Acl::schema_fields_METHOD, '');
            $node->setData(Acl::schema_fields_DOCUMENT, __('菜单'));
            $node->setData('role_id', $roleSelectedSources[$source] ?? null);

            $subMenus = $this->buildMenuTreeLevel($source, $menuByParent, $aclByParent, $menuSourceSet, $roleSelectedSources);
            $routeChildrenRaw = $aclByParent[$source] ?? [];
            // 排除已是菜单的 ACL，避免与 subMenus 重复（菜单以 subMenus 为准）
            $routeChildren = [];
            foreach ($routeChildrenRaw as $routeAcl) {
                if (isset($menuSourceSet[$routeAcl->getSourceId()])) {
                    continue;
                }
                $routeAcl->setData('sub', $this->buildAclOnlyTreeLevel(
                    $routeAcl->getSourceId(),
                    $aclByParent,
                    $menuSourceSet,
                    $roleSelectedSources
                ));
                $routeChildren[] = $routeAcl;
            }
            $node->setData('sub', array_merge($subMenus, $routeChildren));
            $nodes[] = $node;
        }
        return $nodes;
    }

    /**
     * @param array<string, list<Acl>> $aclByParent
     * @param array<int|string, true> $menuSourceSet
     * @param array<string, true> $roleSelectedSources
     * @return Acl[]
     */
    private function buildAclOnlyTreeLevel(
        string $parentSource,
        array $aclByParent,
        array $menuSourceSet,
        array $roleSelectedSources
    ): array {
        $candidates = $aclByParent[$parentSource] ?? [];
        $nodes = [];
        foreach ($candidates as $acl) {
            $sid = $acl->getSourceId();
            if ($sid === '') {
                continue;
            }
            if (isset($menuSourceSet[$sid])) {
                continue;
            }
            $children = $this->buildAclOnlyTreeLevel($sid, $aclByParent, $menuSourceSet, $roleSelectedSources);
            $acl->setData('sub', $children);
            $nodes[] = $acl;
        }
        return $nodes;
    }

    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/2/20 23:18
     * 参数区：
     * @return \Weline\Framework\Database\Model[]
     */
    public function getSub(): array
    {
        return $this->getData('sub') ?? [];
    }

    /**
     * @DESC          # 获取子节点
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/7/3 8:57
     * 参数区：
     *
     * @param Model $model 模型
     * @param string $main_field 主要字段
     * @param string $parent_id_field 父级字段
     * @param string $order_field 排序字段
     * @param string $order_sort 排序方式
     *
     * @return Model
     */
    public function getSubsWithRole(
        Role   &$role,
        Model  &$model,
        string $main_field = 'main_table.source_id',
        string $parent_id_field = 'parent_id',
        string $order_field = 'position',
        string $order_sort = 'ASC'
    ): Model
    {
        $main_field = $main_field ?: $this::schema_fields_ID;
        $model->setData('source_id', $model->getData('a_source_id'));
        if ($subs = $this->clear()
            ->joinModel(Acl::class, 'a', 'a.source_id=main_table.source_id and main_table.role_id=' . $role->getId(''), 'right')
            ->where($parent_id_field, $model->getData('a_source_id'))
            ->order($order_field, $order_sort)
            ->select()
            ->fetch()
            ->getItems()
        ) {
            foreach ($subs as &$sub) {
                $sub->setData('source_id', $sub->getData('a_source_id'));
                $has_sub_menu = $this->clear()
                    ->joinModel(Acl::class, 'a', 'a.source_id=main_table.source_id and main_table.role_id=' . $role->getId(''), 'right')
                    ->where($parent_id_field, $sub->getData('a_source_id'))
                    ->find()
                    ->fetch();
                if ($has_sub_menu->getData('a_source_id')) {
                    $sub = $this->getSubsWithRole($role, $sub, $main_field, $parent_id_field, $order_field, $order_sort);
                }
            }
            $model = $model->setData('sub', $subs);
        } else {
            $model = $model->setData('sub', []);
        }
        return $model;
    }

    public function getRoleAccessList(Role $roleModel): array
    {
        // WLS 兼容：清除上一请求的查询状态，避免 role_id 混用导致非超管只看到部分权限
        return $this->clear()
            ->joinModel($roleModel, 'r', 'main_table.role_id=r.role_id')
            ->joinModel(Acl::class, 'a', 'main_table.source_id=a.source_id')
            ->where('main_table.role_id', $roleModel->getId())
            ->select()
            ->fetchArray();
    }

    public function getRoleAccessListArray(Role $roleModel): array
    {
        // WLS 兼容：清除上一请求的查询状态
        return $this->clear()
            ->joinModel($roleModel, 'r', 'main_table.role_id=r.role_id')
            ->joinModel(Acl::class, 'a', 'main_table.source_id=a.source_id')
            ->where('main_table.role_id', $roleModel->getId())
            ->select()
            ->fetchArray();
    }

    /**
     * 获取权限树统计信息（按顶级节点分组）
     * 
     * @param Role $role 角色对象
     * @return array 统计信息数组，格式为 ['source_id' => ['total' => 总数, 'selected' => 已选数, 'module' => 模块名]]
     */
    public function getTreeStatistics(Role $role): array
    {
        $trees = $this->clear()->getTreeWithRole($role);
        $statistics = [];
        
        foreach ($trees as $tree) {
            $sourceId = $tree->getSourceId();
            $stats = $this->countNodeStatistics($tree);
            $module = $this->extractModuleFromSourceId($sourceId);
            
            $statistics[$sourceId] = [
                'source_id' => $sourceId,
                'source_name' => $tree->getSourceName(),
                'module' => $module,
                'type' => $tree->getType(),
                'total' => $stats['total'],
                'selected' => $stats['selected'],
            ];
        }
        
        return $statistics;
    }

    /**
     * 递归统计节点的总数和已选数
     * 
     * @param Model $node 节点
     * @return array ['total' => 总数, 'selected' => 已选数]
     */
    private function countNodeStatistics(Model $node): array
    {
        $total = 1;
        $selected = $node->getData('role_id') ? 1 : 0;
        
        $subs = $node->getSub();
        if (!empty($subs)) {
            foreach ($subs as $sub) {
                $subStats = $this->countNodeStatistics($sub);
                $total += $subStats['total'];
                $selected += $subStats['selected'];
            }
        }
        
        return ['total' => $total, 'selected' => $selected];
    }

    /**
     * 从 source_id 中提取模块名
     * 例如: Weline_Acl::acl_role => Weline_Acl
     * 
     * @param string $sourceId
     * @return string
     */
    private function extractModuleFromSourceId(string $sourceId): string
    {
        if (str_contains($sourceId, '::')) {
            return explode('::', $sourceId)[0];
        }
        return $sourceId;
    }

    /**
     * 获取所有模块列表（用于筛选器）
     * 
     * @return array
     */
    public function getModuleList(): array
    {
        $aclModel = ObjectManager::getInstance(Acl::class);
        // PostgreSQL 严格要求 SELECT 的非聚合列必须在 GROUP BY 中
        // 只查询 module 列，GROUP BY module 即可
        $acls = $aclModel->clear()
            ->fields('module')
            ->where('module', '', '!=')
            ->group('module')
            ->order('module', 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        
        $modules = [];
        foreach ($acls as $acl) {
            $module = $acl->getModule();
            if (!empty($module)) {
                $modules[] = $module;
            }
        }
        
        return $modules;
    }

    /**
     * 获取所有权限类型列表（用于筛选器）
     * 
     * @return array
     */
    public function getTypeList(): array
    {
        $aclModel = ObjectManager::getInstance(Acl::class);
        // 只查询 type 列，GROUP BY type 即可
        $acls = $aclModel->clear()
            ->fields('type')
            ->where('type', '', '!=')
            ->group('type')
            ->order('type', 'ASC')
            ->select()
            ->fetch()
            ->getItems();
        
        $types = [];
        foreach ($acls as $acl) {
            $type = $acl->getType();
            if (!empty($type)) {
                $types[] = $type;
            }
        }
        
        return $types;
    }
}

