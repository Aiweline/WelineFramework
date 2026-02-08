<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database;

use Weline\Framework\Database\Helper\Tool;
use Weline\Framework\Database\Schema\Column;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\LocalModel;
use Weline\I18n\Model\Locals;

/**
 * 业务模型基类
 *
 * 字段定义（向后兼容）：使用 const fields_* 声明字段名，如 const fields_NAME = 'name';
 *
 * 可选 - PHP 8.4+ 类型安全访问（Property Hooks 示例）：
 *   public string $name {
 *       get => (string)$this->getData(self::fields_NAME);
 *       set => $this->setData(self::fields_NAME, trim($value));
 *   }
 *
 * install/upgrade 中可使用 Column 流式 API（\Weline\Framework\Database\Schema\Column）：
 *   $setup->createTable('注释')
 *       ->addColumn(Column::integer(self::fields_ID)->primaryKey()->autoIncrement()->comment('ID'))
 *       ->addColumn(Column::varchar(self::fields_NAME, 255)->notNull()->comment('名称'))
 *       ->create();
 * 原有 addColumn(string, string, int, string, string) 签名仍支持。
 */
abstract class Model extends AbstractModel implements ModelInterface
{
    public function columns(): array
    {
        $cache_key = $this->getTable() . '_columns';
       if ($columns = $this->_cache->get($cache_key)) {
           return $columns;
       }
        $columns = $this->query("SHOW FULL COLUMNS FROM {$this->getTable()} ")->fetchArray();
        $this->_cache->set($cache_key, $columns);
        return $columns;
    }

    public static function count()
    {
        /** @var \Weline\Framework\Database\Model $model */
        $model = ObjectManager::getInstance(static::class);
        return $model->total();
    }

    /**
     * 表中搜索字段匹配的值
     * @param string $key
     * @param string $columns
     * @param string $logic
     * @return Model
     */
    public function search(string $key, string $columns = '', $logic = 'or'): static
    {
        if (!$columns) {
            $columns = $this->columns();
        } else {
            $columns = explode(',', $columns);
        }
        foreach ($columns as $column) {
            $this->where($column, "%{$key}%", 'like', $logic);
        }
        return $this;
    }

    public function total_fields(string $sql, string $fields, string $additional = ''): array
    {
        $sql = Tool::rm_sql_limit($sql);
        $sql = "SELECT {$fields} FROM ({$sql}) AS total_no_limit {$additional}";
        return $this->reset()->query($sql)->fetchArray();
    }

    /**
     * @DESC          # 获取菜单树
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
    public function getCustomTree(
        string     $main_field = '',
        string     $parent_id_field = 'parent_id',
        string|int $parent_id_value = 0,
        string     $order_field = 'position',
        string     $order_sort = 'ASC'
    ): array
    {
        $main_field = $main_field ?: $this::fields_ID;
        $top_menus = $this->clearData()
            ->where($parent_id_field, $parent_id_value)
            ->order($order_field, $order_sort)
            ->select()
            ->fetch()
            ->getItems();
        foreach ($top_menus as &$top_menu) {
            $top_menu = $this->getSubs($top_menu, $main_field, $parent_id_field, $order_field, $order_sort);
        }
        return $top_menus;
    }

    public function getTree(
        string     $parent_id_field = 'parent_id',
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
            ->order($order_field, $order_sort);
        if ($parent_id) {
            $model->where($parent_id_field, $parent_id);
        }
        if ($selected) {
            $model->where($selected_field, $selected, 'in');
        }
        $results = $model->select()->fetchArray();
        foreach ($results as $result) {
            $nodes[$result[$selected_field]] = $result;
//            $nodes[$result[$selected_field]][$node_field][] = $result;
        }
        foreach ($nodes as $id => &$node) {
            if (isset($node[$parent_id_field])) {
                if ($node[$parent_id_field]) {
                    $nodes[$node[$parent_id_field]][$node_field][] = &$node;
                }
            }
        }
        $items = array_values(array_filter($nodes, function ($node) use ($parent_id_field) {
            if (empty($node[$parent_id_field])) {
                return true;
            }
            return false;
        }));
        if (empty($selected)) {
            return $items;
        }
        return $this->buildSelectedTree($items, $selected_field, $selected, $name_field);
    }

    function buildSelectedTree(array $tree, string &$selected_field, array &$selectedLeaves, string &$name_field, $node_field = 'nodes'): array
    {
        $result = [];

        foreach ($tree as $node) {
            // 检查当前节点是否是选中的叶子节点
            if (isset($node[$selected_field]) and in_array($node[$selected_field], $selectedLeaves)) {
                $result[] = $node;
            } elseif (!empty($node[$node_field])) {
                // 递归处理子节点
                $children = $this->buildSelectedTree($node[$node_field], $selected_field, $selectedLeaves, $name_field);
                if (!empty($children)) {
                    $node[$node_field] = $children;
                    $result[] = $node;
                }
            }
        }

        return $result;
    }

    /**
     * @DESC          # 父路径查询
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/16 13:01
     * 参数区：
     *
     * @param \Weline\Framework\Database\Model $model
     * @param string $main_field
     * @param string $parent_id_field
     * @param string $order_field
     * @param string $order_sort
     *
     * @return \Weline\Framework\Database\Model
     */
    public function getParentPaths(Model  &$model,
                                   string $main_field = '',
                                   string $parent_id_field = 'parent_id',
                                   string $order_field = 'position',
                                   string $order_sort = 'ASC'): Model
    {
        $main_field = $main_field ?: $this::fields_ID;
        $parents = $this->reset()
            ->where($main_field, $model->getData($parent_id_field))
            ->order($order_field, $order_sort)
            ->select()
            ->fetch()
            ->getItems();
        $this->unsetData('0');
        if ($parents) {
            foreach ($parents as &$parent) {
                $has_parent = $this->reset()
                    ->where($main_field, $parent->getData($parent_id_field))
                    ->find()
                    ->fetch();
                if ($has_parent->getData($main_field)) {
                    $parent = $this->getParentPaths($parent, $main_field, $parent_id_field, $order_field, $order_sort);
                }
            }
            $model->setData('parents', $parents);
        } else {
            $model->setData('parents', []);
        }
        return $model;

    }

    /**
     * @DESC          # 获取全部数据
     * @param bool $object
     * @return array|mixed|AbstractModel|Connection\Api\Sql\QueryInterface
     */
    static function all(bool $object = false)
    {
        /** @var \Weline\Framework\Database\Model $model */
        $model = ObjectManager::getInstance(static::class);
        if ($object) {
            return $model->select()->fetch();
        }
        return $model->select()->fetchArray();
    }

    public function loadLocalDescription(string $local_code = '', string|LocalModel $model = ''): static
    {
        if (empty($model)) {
            $model = $this::class . '\\LocalDescription';
        }
        if (is_string($model)) {
            $model = ObjectManager::make($model);
            if (!$model instanceof LocalModel) {
                throw new \InvalidArgumentException(__('参数必须是LocalModel的子类或者LocalModel实例'));
            }
        }
        if (empty($local_code)) {
            $local_code = Cookie::getLang();
        }
        $idField = $this::fields_ID;
        $modelIdField = $model::fields_ID;
        $this->joinModel(
            $model,
            'local',
            "main_table.{$idField}=local.{$modelIdField} and local.local_code='$local_code'",
            'left'
        );
        return $this;
    }

    public function loadLocalName(string $model_local_field, string $field = ''): self
    {
        $localCode = $this->getData($model_local_field);
        if (empty($localCode)) {
            throw new \InvalidArgumentException(__('本地化字段不能为空! %{model}中找不到%{field}字段', ['model' => $this::class, 'field' => $model_local_field]));
        }
        if (empty($field)) {
            $field = $model_local_field . '_name';
        }
        /**@var Locals $local */
        $local = ObjectManager::make(Locals::class);
        $local = $local->where(Locals::fields_CODE, $localCode)
            ->where(Locals::fields_TARGET_CODE, Cookie::getLangLocal())
            ->find()->fetch();
        if ($local->getId()) {
            $this->setData($field, $local->getName());
        }
        return $this;
    }
}
