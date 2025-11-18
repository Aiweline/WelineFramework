<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DeveloperWorkspace\Model\Document;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Catalog extends \Weline\Framework\Database\Model
{
    public string $table = 'developer_workspace_document_catalog';
    public const fields_ID = 'id';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_PID = 'pid';
    public const fields_level = 'level';
    public const fields_icon = 'icon';
    public const fields_selectedIcon = 'selectedIcon';
    public const fields_color = 'color';
    public const fields_backColor = 'backColor';
    public const fields_position = 'position';
    public const fields_is_active = 'is_active';
    public const fields_is_system = 'is_system';

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
        // 更新提示
        $setup->getPrinting()->setup($context->getVersion());

        // 确保ModelSetup关联当前模型
        $setup->putModel($this);

        // 先检查表是否存在，如果不存在则先创建（install方法会创建包含所有字段的完整表结构）
        if (!$setup->tableExist()) {
            $setup->getPrinting()->note(__('表不存在，正在创建...'));
            $this->install($setup, $context);
            return;
        }
        
        // 确保 is_system 字段存在（兼容旧版本数据库）
        if (!$setup->hasField(self::fields_is_system)) {
            $setup->getPrinting()->note(__('添加 is_system 字段...'));
            $alter = $setup->alterTable();
            $alter->addColumn(self::fields_is_system, '', 'integer', 1, 'default 0', '系统创建');
            $alter->alter();
            $setup->getPrinting()->success(__('is_system 字段已添加'));
        }
        
        // 修复唯一索引：移除 name 字段的唯一约束，添加联合唯一索引 (name, pid, level)
        // 允许不同层级有重名，但同一个 level 和同一个 pid 下不允许重名
        try {
            // 尝试删除旧的 name 唯一索引（如果存在）
            $setup->query("ALTER TABLE {$this->getTable()} DROP INDEX `name`;");
            $setup->getPrinting()->success(__('已删除旧的 name 唯一索引'));
        } catch (\Exception $e) {
            // 索引不存在时忽略
        }
        
        // 检查联合唯一索引是否存在
        if (!$setup->hasIndex('idx_unique_name_pid_level')) {
            $setup->getPrinting()->note(__('添加联合唯一索引 (name, pid, level)...'));
            $alter = $setup->alterTable();
            $alter->addIndex(
                TableInterface::index_type_UNIQUE,
                'idx_unique_name_pid_level',
                ['name', 'pid', 'level'],
                '分类名称、父ID和层级联合唯一索引'
            );
            $alter->alter();
            $setup->getPrinting()->success(__('联合唯一索引已添加'));
        }
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        // $setup->dropTable();
        if (!$setup->tableExist()) {
            $setup->getPrinting()->setup('安装数据表...', $setup->getTable());
            $setup->createTable('目录')
                ->addColumn('id', TableInterface::column_type_INTEGER, 0, 'primary key auto_increment', 'ID')
                ->addColumn('name', TableInterface::column_type_VARCHAR, 60, 'not null ', '目录名')
                ->addColumn(self::fields_DESCRIPTION, TableInterface::column_type_TEXT, 0, 'not null', '简介')
                ->addColumn('level', TableInterface::column_type_INTEGER, null, 'not null default 0', '目录层级')
                ->addColumn('icon', TableInterface::column_type_VARCHAR, 60, '', 'icon 图标')
                ->addColumn('selectedIcon', TableInterface::column_type_VARCHAR, 60, '', 'icon 选中图标')
                ->addColumn('color', TableInterface::column_type_VARCHAR, 60, '', '颜色')
                ->addColumn('backColor', TableInterface::column_type_VARCHAR, 60, '', '背景色')
                ->addColumn('position', TableInterface::column_type_INTEGER, null, 'default 0', '排序')
                ->addColumn('is_active', TableInterface::column_type_INTEGER, 1, 'default 0', '是否激活')
                ->addColumn('is_system', TableInterface::column_type_INTEGER, 1, 'default 0', '是否系统创建')
                ->addColumn('pid', TableInterface::column_type_INTEGER, 0, '', '父目录')
                // 添加联合唯一索引：同一个 level 和同一个 pid 下不允许重名，但不同层级可以重名
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_unique_name_pid_level', ['name', 'pid', 'level'], '分类名称、父ID和层级联合唯一索引')
                ->create();
        }
    }

    public function getName()
    {
        return $this->getData(self::fields_NAME);
    }

    public function setName(string $name): Catalog
    {
        return $this->setData(self::fields_NAME, $name);
    }

    public function getPid()
    {
        return $this->getData(self::fields_PID);
    }

    public function setPid(string|int $pid): Catalog
    {
        return $this->setData(self::fields_PID, $pid);
    }

    public function setDescription(string $description): Catalog
    {
        return $this->setData(self::fields_DESCRIPTION, $description);
    }

    public function getDescription(): string
    {
        return $this->getData(self::fields_DESCRIPTION) ?? '';
    }


    public function isActive(): bool
    {
        return $this->getData(self::fields_is_active) === 1;
    }

    public function setIsActive(bool $state): static
    {
        return $this->setData(self::fields_is_active, $state);
    }

    /**
     * 重写 getTree 方法，添加 is_active 过滤
     * 
     * @param string $parent_id_field
     * @param string|int $parent_id
     * @param string $order_field
     * @param string $order_sort
     * @param string $selected_field
     * @param array $selected
     * @param string $name_field
     * @param string $node_field
     * @return array
     */
    public function getTree(
        string     $parent_id_field = 'pid',
        string|int $parent_id = 0,
        string     $order_field = 'position',
        string     $order_sort = 'ASC',
        string     $selected_field = 'id',
        array      $selected = [],
        string     $name_field = 'name',
        string     $node_field = 'nodes'
    ): array
    {
        $nodes = [];
        $model = $this->reset()
            ->where(self::fields_is_active, 1)  // 只获取激活的分类
            ->order($order_field, $order_sort);
        if ($parent_id) {
            $model->where($parent_id_field, $parent_id);
        }
        if ($selected) {
            $model->where($selected_field, $selected, 'in');
        }
        $results = $model->select()->fetchArray();
        
        if (empty($results)) {
            return [];
        }
        
        // 初始化所有节点，确保每个节点都有 nodes 数组
        foreach ($results as $result) {
            $result[$node_field] = [];
            $nodes[$result[$selected_field]] = $result;
        }
        
        // 构建树结构：将子节点添加到父节点的 nodes 数组中
        // 注意：必须确保父节点存在，并且不会形成循环引用
        $childNodeIds = []; // 记录所有作为子节点的ID，这些节点不应该出现在根节点列表中
        foreach ($nodes as $id => &$node) {
            $parentId = $node[$parent_id_field] ?? null;
            // 只处理有父节点的节点，并且父节点必须存在
            if ($parentId && isset($nodes[$parentId])) {
                // 检查是否会形成循环引用（子节点的ID不能等于父节点的ID）
                if ($id != $parentId) {
                    // 确保父节点的 nodes 数组已初始化
                    if (!isset($nodes[$parentId][$node_field])) {
                        $nodes[$parentId][$node_field] = [];
                    }
                    // 将当前节点添加到父节点的 nodes 数组中（使用引用，确保子节点的子节点也能正确添加）
                    $nodes[$parentId][$node_field][] = &$node;
                    // 记录这个节点是子节点
                    $childNodeIds[$id] = true;
                }
            }
        }
        unset($node); // 释放引用，避免后续问题
        
        // 过滤出根节点（pid 为 0 或空的节点），并且排除所有子节点
        // 注意：子节点应该出现在它们父节点的 nodes 数组中，只是不应该出现在根节点列表中
        $items = array_values(array_filter($nodes, function ($node) use ($parent_id_field, $selected_field, $childNodeIds) {
            $pid = $node[$parent_id_field] ?? null;
            $id = $node[$selected_field] ?? null;
            // 必须是根节点（pid 为 0 或空），并且不是任何节点的子节点
            return (empty($pid) || $pid == 0) && !isset($childNodeIds[$id]);
        }));
        
        if (empty($selected)) {
            return $items;
        }
        return $this->buildSelectedTree($items, $selected_field, $selected, $name_field);
    }
}
