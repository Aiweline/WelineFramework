<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class TrackingNode extends AbstractModel
{
    public const table = 'w_shipping_tracking_nodes';
    
    public const fields_ID = 'node_id';
    public const fields_TRACKING_ID = 'tracking_id';
    public const fields_NODE_TIME = 'node_time';
    public const fields_NODE_LOCATION = 'node_location';
    public const fields_NODE_STATUS = 'node_status';
    public const fields_NODE_DESCRIPTION = 'node_description';
    public const fields_NODE_TYPE = 'node_type';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_CREATED_AT = 'created_at';

    // 节点类型常量
    public const TYPE_PICKUP = 'pickup';
    public const TYPE_TRANSIT = 'transit';
    public const TYPE_ARRIVAL = 'arrival';
    public const TYPE_DELIVERY = 'delivery';
    public const TYPE_EXCEPTION = 'exception';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['node_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['node_id', 'tracking_id', 'node_time'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_table = self::table;
        $this->_primary_key = self::fields_ID;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('物流跟踪节点表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '节点ID'
                )
                ->addColumn(
                    self::fields_TRACKING_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'not null',
                    '跟踪记录ID'
                )
                ->addColumn(
                    self::fields_NODE_TIME,
                    TableInterface::column_type_DATETIME,
                    null,
                    'not null',
                    '节点时间'
                )
                ->addColumn(
                    self::fields_NODE_LOCATION,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'default null',
                    '节点位置'
                )
                ->addColumn(
                    self::fields_NODE_STATUS,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '节点状态描述'
                )
                ->addColumn(
                    self::fields_NODE_DESCRIPTION,
                    TableInterface::column_type_TEXT,
                    null,
                    'default null',
                    '节点详细描述'
                )
                ->addColumn(
                    self::fields_NODE_TYPE,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default null',
                    '节点类型：pickup/transit/arrival/delivery/exception'
                )
                ->addColumn(
                    self::fields_SORT_ORDER,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default 0',
                    '排序（按时间顺序）'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP',
                    '创建时间'
                )
                ->addIndex(TableInterface::index_type_KEY, 'idx_tracking_id', self::fields_TRACKING_ID, '跟踪记录ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_node_time', self::fields_NODE_TIME, '节点时间索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_node_type', self::fields_NODE_TYPE, '节点类型索引')
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void {}

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 根据跟踪记录ID获取所有节点（按时间排序）
     * 
     * @param int $trackingId 跟踪记录ID
     * @return \Weline\Framework\Database\Model\Collection
     */
    public function getByTrackingId(int $trackingId): \Weline\Framework\Database\Model\Collection
    {
        return $this->reset()
            ->where(self::fields_TRACKING_ID, $trackingId)
            ->order(self::fields_NODE_TIME, 'ASC')
            ->order(self::fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch();
    }

    /**
     * 批量添加节点
     * 
     * @param int $trackingId 跟踪记录ID
     * @param array $nodes 节点数据数组
     * @return int 成功添加的数量
     */
    public function batchAdd(int $trackingId, array $nodes): int
    {
        $count = 0;
        $sortOrder = 0;
        foreach ($nodes as $node) {
            $model = $this->reset();
            $model->setData([
                self::fields_TRACKING_ID => $trackingId,
                self::fields_NODE_TIME => $node['time'] ?? date('Y-m-d H:i:s'),
                self::fields_NODE_LOCATION => $node['location'] ?? null,
                self::fields_NODE_STATUS => $node['status'] ?? '',
                self::fields_NODE_DESCRIPTION => $node['description'] ?? null,
                self::fields_NODE_TYPE => $node['type'] ?? null,
                self::fields_SORT_ORDER => $sortOrder++,
            ]);
            try {
                $model->save();
                $count++;
            } catch (\Exception $e) {
                continue;
            }
        }
        return $count;
    }

    /**
     * 获取节点类型的翻译文本
     * 
     * @param string|null $type 节点类型代码，如果为null则使用当前模型的类型
     * @return string 翻译后的类型文本
     */
    public function getTypeLabel(?string $type = null): string
    {
        $type = $type ?? $this->getData(self::fields_NODE_TYPE);
        
        return match ($type) {
            self::TYPE_PICKUP => __('取件'),
            self::TYPE_TRANSIT => __('运输中'),
            self::TYPE_ARRIVAL => __('到达'),
            self::TYPE_DELIVERY => __('派送'),
            self::TYPE_EXCEPTION => __('异常'),
            default => $type,
        };
    }

    /**
     * 获取所有节点类型的选项数组（用于下拉选择等）
     * 
     * @return array [类型代码 => 翻译文本]
     */
    public static function getTypeOptions(): array
    {
        return [
            self::TYPE_PICKUP => __('取件'),
            self::TYPE_TRANSIT => __('运输中'),
            self::TYPE_ARRIVAL => __('到达'),
            self::TYPE_DELIVERY => __('派送'),
            self::TYPE_EXCEPTION => __('异常'),
        ];
    }
}

