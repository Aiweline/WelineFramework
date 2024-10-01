<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database;

use Weline\Backend\Model\Menu;
use Weline\Framework\Manager\ObjectManager;

abstract class Model extends AbstractModel implements ModelInterface
{
    public function columns(): array
    {
        $cache_key = $this->getTable() . '_columns';
        if ($columns = $this->_cache->get($cache_key)) {
            return $columns;
        }
        $columns = $this->query("SHOW FULL COLUMNS FROM {$this->getTable()} ")->fetchOrigin();
        $this->_cache->set($cache_key, $columns);
        return $columns;
    }

    /**
     * @DESC          # 获取菜单树
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/7/3 8:49
     * 参数区：
     *
     * @param string     $main_field      主要字段
     * @param string     $parent_id_field 父级字段
     * @param string|int $parent_id_value 父级字段值【用于判别顶层数据】
     * @param string     $order_field     排序字段
     * @param string     $order_sort      排序方式
     *
     * @return array
     */
    public function getTree(
        string     $main_field = '',
        string     $parent_id_field = 'parent_id',
        string|int $parent_id_value = 0,
        string     $order_field = 'position',
        string     $order_sort = 'ASC'
    ): array
    {
        $main_field = $main_field ?: $this::fields_ID;
        $top_menus  = $this->clearData()
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

    /**
     * @DESC          # 获取子节点
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
     * @param Model  $model           模型
     * @param string $main_field      主要字段
     * @param string $parent_id_field 父级字段
     * @param string $order_field     排序字段
     * @param string $order_sort      排序方式
     *
     * @return Model
     */
    public function getSubs(
        Model  &$model,
        string $main_field = '',
        string $parent_id_field = 'parent_id',
        string $order_field = 'position',
        string $order_sort = 'ASC'
    ): Model
    {
        $main_field = $main_field ?: $this::fields_ID;
        if ($subs = $this->clear()
                         ->where($parent_id_field, $model->getData($main_field))
                         ->order($order_field, $order_sort)
                         ->select()
                         ->fetch()
                         ->getItems()
        ) {
            foreach ($subs as &$sub) {
                $has_sub = $this->clear()->where($parent_id_field, $sub->getData($main_field))->find()->fetch();
                if ($has_sub->getData($main_field)) {
                    $sub = $this->getSubs($sub,$main_field,$parent_id_field,$order_field,$order_sort);
                }
            }
            $model = $model->setData('sub', $subs);
        } else {
            $model = $model->setData('sub', []);
        }
        return $model;
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
     * @param string                           $main_field
     * @param string                           $parent_id_field
     * @param string                           $order_field
     * @param string                           $order_sort
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
                    $parent = $this->getParentPaths($parent,$main_field,$parent_id_field,$order_field,$order_sort);
                }
            }
            $model->setData('parents', $parents);
        } else {
            $model->setData('parents', []);
        }
        return $model;

    }
}
