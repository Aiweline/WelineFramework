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
#[Table(comment: '物流跟踪记录表')]
#[Index(name: 'idx_tracking_number_carrier', columns: ['tracking_number', 'carrier_id'], type: 'UNIQUE')]
#[Index(name: 'idx_carrier_id', columns: ['carrier_id'])]
#[Index(name: 'idx_order_id', columns: ['order_id'])]
#[Index(name: 'idx_customer_id', columns: ['customer_id'])]
#[Index(name: 'idx_status', columns: ['status'])]
#[Index(name: 'idx_tracking_number', columns: ['tracking_number'])]
class Tracking extends AbstractModel
{

    public const schema_table = 'w_shipping_tracking';
    public const schema_primary_key = 'tracking_id';
    #[Col('int', null, nullable: false, primaryKey: true, autoIncrement: true, comment: '跟踪记录ID')]
    public const schema_fields_ID = 'tracking_id';
    #[Col('varchar', 100, nullable: false, comment: '物流单号')]
    public const schema_fields_TRACKING_NUMBER = 'tracking_number';
    #[Col('int', null, nullable: false, comment: '快递公司ID')]
    public const schema_fields_CARRIER_ID = 'carrier_id';
    #[Col('int', null, comment: '订单ID')]
    public const schema_fields_ORDER_ID = 'order_id';
    #[Col('int', null, comment: '客户ID')]
    public const schema_fields_CUSTOMER_ID = 'customer_id';
    #[Col('varchar', 50, nullable: false, default: 'pending', comment: '物流状态')]
    public const schema_fields_STATUS = 'status';
    #[Col('varchar', 255, comment: '当前位置')]
    public const schema_fields_CURRENT_LOCATION = 'current_location';
    #[Col('datetime', comment: '预计送达时间')]
    public const schema_fields_ESTIMATED_DELIVERY_DATE = 'estimated_delivery_date';
    #[Col('datetime', comment: '实际送达时间')]
    public const schema_fields_ACTUAL_DELIVERY_DATE = 'actual_delivery_date';
    #[Col('text', comment: '详细跟踪数据JSON')]
    public const schema_fields_TRACKING_DATA = 'tracking_data';
    #[Col('datetime', comment: '最后跟踪时间')]
    public const schema_fields_LAST_TRACKED_AT = 'last_tracked_at';
    #[Col('int', null, nullable: false, default: 0, comment: '跟踪次数')]
    public const schema_fields_TRACKING_COUNT = 'tracking_count';
    #[Col('datetime', comment: '创建时间')]
    public const schema_fields_CREATED_AT = 'created_at';
    #[Col('datetime', comment: '更新时间')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    // 物流状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_EXCEPTION = 'exception';
    public const STATUS_NOT_SUPPORTED = 'not_supported';

    /**
     * 主键字段
     */
    public array $_unit_primary_keys = ['tracking_id'];

    /**
     * 索引排序键
     */
    public array $_index_sort_keys = ['tracking_id', 'tracking_number', 'carrier_id', 'status'];

    /**
     * 初始化模型
     */
    public function _init(): void
    {
        $this->_table = self::schema_table;
        $this->_primary_key = self::schema_fields_ID;
    }
/**
     * 根据物流单号和快递公司查询
     * 
     * @param string $trackingNumber 物流单号
     * @param int $carrierId 快递公司ID
     * @return Tracking|null
     */
    public function getByTrackingNumberAndCarrier(string $trackingNumber, int $carrierId): ?Tracking
    {
        $model = $this->reset()
            ->where(self::schema_fields_TRACKING_NUMBER, $trackingNumber)
            ->where(self::schema_fields_CARRIER_ID, $carrierId)
            ->find()
            ->fetch();
        return $model->getId() ? $model : null;
    }

    /**
     * 获取跟踪数据
     * 
     * @return array
     */
    public function getTrackingData(): array
    {
        $data = $this->getData(self::schema_fields_TRACKING_DATA);
        if (empty($data)) {
            return [];
        }
        return json_decode($data, true) ?: [];
    }

    /**
     * 设置跟踪数据
     * 
     * @param array $data
     * @return $this
     */
    public function setTrackingData(array $data): self
    {
        $this->setData(self::schema_fields_TRACKING_DATA, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 增加跟踪次数
     * 
     * @return $this
     */
    public function incrementTrackingCount(): self
    {
        $count = (int)$this->getData(self::schema_fields_TRACKING_COUNT);
        $this->setData(self::schema_fields_TRACKING_COUNT, $count + 1);
        $this->setData(self::schema_fields_LAST_TRACKED_AT, date('Y-m-d H:i:s'));
        return $this;
    }

    /**
     * 获取状态的翻译文本
     * 
     * @param string|null $status 状态代码，如果为null则使用当前模型的状态
     * @return string 翻译后的状态文本
     */
    public function getStatusLabel(?string $status = null): string
    {
        $status = $status ?? $this->getData(self::schema_fields_STATUS);
        
        return match ($status) {
            self::STATUS_PENDING => __('待发货'),
            self::STATUS_IN_TRANSIT => __('运输中'),
            self::STATUS_DELIVERED => __('已送达'),
            self::STATUS_EXCEPTION => __('异常'),
            self::STATUS_NOT_SUPPORTED => __('不支持追踪'),
            default => $status,
        };
    }

    /**
     * 获取所有状态的选项数组（用于下拉选择等）
     * 
     * @return array [状态代码 => 翻译文本]
     */
    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_PENDING => __('待发货'),
            self::STATUS_IN_TRANSIT => __('运输中'),
            self::STATUS_DELIVERED => __('已送达'),
            self::STATUS_EXCEPTION => __('异常'),
            self::STATUS_NOT_SUPPORTED => __('不支持追踪'),
        ];
    }
}


