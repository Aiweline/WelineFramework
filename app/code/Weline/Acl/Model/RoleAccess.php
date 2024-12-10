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
use Weline\Backend\Session\BackendSession;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class RoleAccess extends \Weline\Framework\Database\Model
{
    public const fields_ID = 'role_id';
    public const fields_ROLE_ID = Role::fields_ID;
    public const fields_SOURCE_ID = Acl::fields_ID;

    private array $exist = [];

    /**
     * @inheritDoc
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * @inheritDoc
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // TODO: Implement upgrade() method.
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
//        $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->createTable()
                ->addColumn(
                    self::fields_ROLE_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'not null',
                    '角色ID'
                )
                ->addColumn(
                    self::fields_SOURCE_ID,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'not null',
                    '资源ID'
                )
                /*->addForeignKey(
                    'ROLE_ACCESS_ROLE_ID',
                    self::fields_ROLE_ID,
                    $this->getTable('role'),
                    Role::fields_ID,
                    true
                )*/
                ->addConstraints("primary key (role_id,source_id)")
                ->create();
        }
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
    ): array {
        $main_field = $main_field ?: $this::fields_ID;
        $top_menus = $this->clearData()
            ->joinModel(Acl::class, 'a', 'a.source_id=main_table.source_id and main_table.role_id=' . $role->getId(''), 'right')
            ->where($parent_id_field, $parent_id_value)
            ->order($order_field, $order_sort)
            ->select()
            ->fetch()
            ->getItems();
        foreach ($top_menus as &$top_menu) {
            $top_menu->setData('source_id', $top_menu->getData('a_source_id'));
            $top_menu = $this->getSubsWithRole($role, $top_menu, $main_field, $parent_id_field, $order_field, $order_sort);
        }
        return $top_menus;
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
    ): Model {
        $main_field = $main_field ?: $this::fields_ID;
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
        return $this->joinModel($roleModel, 'r', 'main_table.role_id=r.role_id')
            ->joinModel(Acl::class, 'a', 'main_table.source_id=a.source_id')
            ->where('main_table.role_id', $roleModel->getId())
            ->select()
            ->fetch()
            ->getItems();
    }

    public function getRoleAccessListArray(Role $roleModel): array
    {
        return $this->joinModel($roleModel, 'r', 'main_table.role_id=r.role_id')
            ->joinModel(Acl::class, 'a', 'main_table.source_id=a.source_id')
            ->where('main_table.role_id', $roleModel->getId())
            ->select()
            ->fetchArray();
    }

    public function getRoleNotAccessList(Role $roleModel): array
    {
        /**@var Acl $acl */
        $acl = ObjectManager::getInstance(Acl::class);
        return $acl
            ->where("source_id not in (select source_id from {$this->getTable()} where {$this->getTable()}.role_id='{$roleModel->getId()}')")
            ->select()
            ->fetchArray();
    }

    /**
     * @DESC          # 获取角色权限树
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/2/9 0:38
     * 参数区：
     */
    public function getAccessTreeByRole(Role $role): array
    {
        $top_acls = [];
        if ($role->getId() !== 1) {
            // 以子权限扫描所有权限的父级
            $roleAccesses = $this->clear()
                ->joinModel(Acl::class, 'a', 'main_table.source_id=a.source_id')
                ->where('main_table.' . RoleAccess::fields_ROLE_ID, $role->getId(0))
                ->where('a.parent_source', '', '<>')
                ->select()
                ->fetch()
                ->getItems();
            $hasIds       = [];
            // 归并所有相同父级的权限,同时筛选出父级权限资源递归出子权限
            $mergerParentAcl = [];
            /**@var RoleAccess|Acl $roleAccess */
            foreach ($roleAccesses as $roleAccess) {
                $parentSource = $roleAccess->getParentSource();
                // 顶层资源,找出对应是否有权限的子权限
                if (empty($parentSource)) {
                    $top_acls[] = $this->getSubAccessesByRole($roleAccess, $role);
                } else {
                    // 归并需要查找父级的子权限
                    $mergerParentAcl[$parentSource][] = $roleAccess;
                }
            }
            foreach ($mergerParentAcl as $parentSource => $acls) {
                foreach ($acls as &$acl_) {
                    $this->getSubAccessesByRole($acl_, $role);
                }
                $acl = $this->clear()->fields('main_table.*')->joinModel(Acl::class, 'a', 'main_table.source_id=a.source_id', 'right')
                    ->where('a.source_id', $parentSource)->find()->fetch();
                $acl->setData('sub_accesses_by_role', $acls);
                $acl->setData('sub', $acls);
                $top_acl = $this->findTopAccesses($acl);
                if (!in_array($top_acl->getData('a_source_id'), $hasIds)) {
                    $top_acl->setData('source_id', $top_acl->getData('a_source_id'));
                    $top_acls[] = $top_acl;
                    $hasIds[]   = $top_acl->getData('a_source_id');
                }
            }
        } else {
            /**@var \Weline\Acl\Model\Acl $aclModel */
            $aclModel = ObjectManager::getInstance(\Weline\Acl\Model\Acl::class);
            $top_acls = $aclModel->getTree(
                $aclModel::fields_SOURCE_ID,
                $aclModel::fields_PARENT_SOURCE,
                '',
                $aclModel::fields_SOURCE_ID
            );
        }
        return $top_acls;
    }

    /**
     * @DESC          # 查找顶层菜单
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/1/31 23:25
     * 参数区：
     *
     *
     */
    private function findTopAccesses(RoleAccess|Acl &$acl): RoleAccess
    {
        $aclData = clone $acl;
        if (empty($aclData->getParentSource())) {
            return $aclData;
        } else {
            $parent = $this->clear()->joinModel(Acl::class, 'a', 'main_table.source_id=a.source_id', 'right')
                ->where('a.source_id', $aclData->getParentSource())->find()->fetch();
            $parent->setData('sub_accesses_by_role', [$aclData]);
            $parent->setData('sub', [$aclData]);
            return $this->findTopAccesses($parent);
        }
    }

    /**
     * @DESC          # 获取角色权限子菜单
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/2/20 23:18
     * 参数区：
     * @return Acl[]
     */
    public function getSubAccessByRole(): array
    {
        return $this->getData('sub_accesses_by_role') ?? [];
    }

    public function getSubAccessesByRole(RoleAccess|Acl &$acl, Role &$role): RoleAccess
    {
        $this->clear()
            ->joinModel(Acl::class, 'a', 'main_table.source_id=a.source_id')
            ->where('a.parent_source', $acl->getSourceId());
        if ($role->getId() !== 1) {
            $this->where('main_table.role_id', $role->getId());
        }

        // 有权限的
        if ($sub_acls = $this->select()->fetch()->getItems()) {
            /**@var RoleAccess|Acl $sub_acl */
            foreach ($sub_acls as &$sub_acl) {
                $this->clear()
                    ->joinModel(Acl::class, 'a', 'main_table.source_id=a.source_id')
                    ->where('a.' . Acl::fields_PARENT_SOURCE, $sub_acl->getSourceId());
                if ($role->getId() !== 1) {
                    $this->where('main_table.role_id', $role->getId());
                }
                $has_sub_acls = $this
                    ->select()
                    ->fetch()
                    ->getItems();
                foreach ($has_sub_acls as $has_sub_acl) {
                    if ($has_sub_acl->getId()) {
                        $sub_acl = $this->getSubAccessesByRole($sub_acl, $role);
                    }
                }
            }
            $acl = $acl->setData('sub_accesses_by_role', $sub_acls);
            $acl = $acl->setData('sub', $sub_acls);
        } else {
            $acl = $acl->setData('sub_accesses_by_role', []);
            $acl = $acl->setData('sub', []);
        }
        return $acl;
    }
}
