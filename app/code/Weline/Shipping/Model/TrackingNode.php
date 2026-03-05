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
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
#[Table(comment: '物流跟踪节点表')]
#[Index(name: 'idx_tracking_id', columns: ['tracking_id'])]
#[Index(name: 'idx_node_time', columns: ['node_time'])]
#[Index(name: 'idx_node_type', columns: ['node_type'])]
class TrackingNode extends AbstractModel
{

    public const schema_table = 'w_shipping_tracking_nodes';
    public const schema_primary_key = 'node_id';
    public const schema_primary_keys = ['node_id'];
    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '节点ID')]
    public const schema_fields_ID = 'node_id';
    #[Col('int', null, nullable: false, comment: '跟踪记录ID')]
    public const schema_fields_TRACKING_ID = 'tracking_id';
    #[Col('datetime', nullable: false, comment: '节点时间')]
    public const schema_fields_NODE_TIME = 'node_time';
    #[Col('varchar', 255, comment: '节点位置')]
    public const schema_fields_NODE_LOCATION = 'node_location';
    #[Col('varchar', 100, nullable: false, comment: '节点状态描述')]
    public const schema_fields_NODE_STATUS = 'node_status';
    #[Col('text', comment: '节点详细描述')]
    public const schema_fields_NODE_DESCRIPTION = 'node_description';
    #[Col('varchar', 50, comment: '节点类型')]
    public const schema_fields_NODE_TYPE = 'node_type';
    #[Col('int', null, nullable: false, default: 0, comment: '排序')]
    public const schema_fields_SORT_ORDER = 'sort_order';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';

    // 节点类型常量
    public const TYPE_PICKUP = 'pickup';
    public const TYPE_TRANSIT = 'transit';
    public const TYPE_ARRIVAL = 'arrival';
    public const TYPE_DELIVERY = 'delivery';
    public const TYPE_EXCEPTION = 'exception';

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['node_id', 'tracking_id', 'node_time'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
    }

    /**
/**
     * 根据跟踪记录ID获取所有节点（按时间排序）
     * 
     * @param int $trackingId 跟踪记录ID
     * @return \Weline\Framework\Database\Model\Collection
     */
    public function getByTrackingId(int $trackingId): \Weline\Framework\Database\Model\Collection
    {
        return $this->reset()
            ->where(self::schema_fields_TRACKING_ID, $trackingId)
            ->order(self::schema_fields_NODE_TIME, 'ASC')
            ->order(self::schema_fields_SORT_ORDER, 'ASC')
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
                self::schema_fields_TRACKING_ID => $trackingId,
                self::schema_fields_NODE_TIME => $node['time'] ?? date('Y-m-d H:i:s'),
                self::schema_fields_NODE_LOCATION => $node['location'] ?? null,
                self::schema_fields_NODE_STATUS => $node['status'] ?? '',
                self::schema_fields_NODE_DESCRIPTION => $node['description'] ?? null,
                self::schema_fields_NODE_TYPE => $node['type'] ?? null,
                self::schema_fields_SORT_ORDER => $sortOrder++,
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
        $type = $type ?? $this->getData(self::schema_fields_NODE_TYPE);
        
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


