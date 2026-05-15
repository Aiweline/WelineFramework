<?php
declare(strict_types=1);
/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
namespace Weline\DeveloperWorkspace\Model\Document;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
#[Table(comment: '目录')]
#[Index(name: 'idx_unique_name_pid', columns: ['name', 'pid'], type: 'UNIQUE')]
class Catalog extends Model
{
    public const schema_table = 'developer_workspace_document_catalog';
    public const schema_primary_key = 'id';
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 60, nullable: false, comment: '目录名')]
    public const schema_fields_NAME = 'name';
    #[Col('text', nullable: false, comment: '简介')]
    public const schema_fields_DESCRIPTION = 'description';
    #[Col('int', nullable: false, default: 0, comment: '父目录')]
    public const schema_fields_PID = 'pid';
    #[Col('int', nullable: false, default: 0, comment: '目录层级')]
    public const schema_fields_level = 'level';
    #[Col('varchar', 60, comment: 'icon图标')]
    public const schema_fields_icon = 'icon';
    #[Col('varchar', 60, comment: 'icon选中图标')]
    public const schema_fields_selectedIcon = 'selectedIcon';
    #[Col('varchar', 60, comment: '颜色')]
    public const schema_fields_color = 'color';
    #[Col('varchar', 60, comment: '背景色')]
    public const schema_fields_backColor = 'backColor';
    #[Col('int', default: 0, comment: '排序')]
    public const schema_fields_position = 'position';
    #[Col('int', 1, default: 0, comment: '是否激活')]
    public const schema_fields_is_active = 'is_active';
    #[Col('int', 1, default: 0, comment: '是否系统创建')]
    public const schema_fields_is_system = 'is_system';
public function getName()
    {
        return $this->getData(self::schema_fields_NAME);
    }
    public function setName(string $name): Catalog
    {
        return $this->setData(self::schema_fields_NAME, $name);
    }
    public function getPid()
    {
        return $this->getData(self::schema_fields_PID);
    }
    public function setPid(string|int $pid): Catalog
    {
        return $this->setData(self::schema_fields_PID, $pid);
    }
    public function setDescription(string $description): Catalog
    {
        return $this->setData(self::schema_fields_DESCRIPTION, $description);
    }
    public function getDescription(): string
    {
        return $this->getData(self::schema_fields_DESCRIPTION) ?? '';
    }
    public function isActive(): bool
    {
        return $this->getData(self::schema_fields_is_active) === 1;
    }
    public function setIsActive(bool $state): static
    {
        return $this->setData(self::schema_fields_is_active, $state);
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
            ->where(self::schema_fields_is_active, 1)  // 只获取激活的分类
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
