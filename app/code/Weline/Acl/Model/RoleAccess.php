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

use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Manager\ObjectManager;
use Weline\Acl\Service\ResourceTreeService;

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
    
    private ?ResourceTreeService $resourceTreeService = null;

    /**
     * 获取资源树服务（延迟加载）
     * 
     * @return ResourceTreeService
     */
    private function getResourceTreeService(): ResourceTreeService
    {
        if ($this->resourceTreeService === null) {
            $this->resourceTreeService = ObjectManager::getInstance(ResourceTreeService::class);
        }
        return $this->resourceTreeService;
    }

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
        return $this->buildAclTreeFromAcl($role);
    }

    /**
     * 从 ACL 表构建权限分配树（单一数据源）
     * 
     * @param Role $role
     * @return Acl[]
     */
    private function buildAclTreeFromAcl(Role $role): array
    {
        return $this->getResourceTreeService()->getAclAssignmentTree($role);
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
     * 按角色 ID 获取角色 ACL 条目列表（不加载 Role 模型，减少一次 DB 查询）
     * 用于 AclService 请求级缓存路径，与 getRoleAccessListArray 返回结构一致。
     */
    public function getRoleAccessListArrayByRoleId(int $roleId): array
    {
        if ($roleId <= 0) {
            return [];
        }
        // WLS 模式下使用新实例避免状态污染（JOIN/WHERE 条件残留）
        /** @var RoleAccess $freshInstance */
        $freshInstance = \Weline\Framework\Manager\ObjectManager::getInstance(self::class, [], false);
        return $freshInstance
            ->joinModel(Acl::class, 'a', 'main_table.source_id=a.source_id')
            ->where('main_table.role_id', $roleId)
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
