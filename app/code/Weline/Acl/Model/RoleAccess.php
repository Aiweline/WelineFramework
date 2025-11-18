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
    ): array
    {
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
    ): Model
    {
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
            ->fetchArray();
    }

    public function getRoleAccessListArray(Role $roleModel): array
    {
        return $this->joinModel($roleModel, 'r', 'main_table.role_id=r.role_id')
            ->joinModel(Acl::class, 'a', 'main_table.source_id=a.source_id')
            ->where('main_table.role_id', $roleModel->getId())
            ->select()
            ->fetchArray();
    }
}
