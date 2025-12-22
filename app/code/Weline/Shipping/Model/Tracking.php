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

class Tracking extends AbstractModel
{
    public const table = 'w_shipping_tracking';
    
    public const fields_ID = 'tracking_id';
    public const fields_TRACKING_NUMBER = 'tracking_number';
    public const fields_CARRIER_ID = 'carrier_id';
    public const fields_ORDER_ID = 'order_id';
    public const fields_CUSTOMER_ID = 'customer_id';
    public const fields_STATUS = 'status';
    public const fields_CURRENT_LOCATION = 'current_location';
    public const fields_ESTIMATED_DELIVERY_DATE = 'estimated_delivery_date';
    public const fields_ACTUAL_DELIVERY_DATE = 'actual_delivery_date';
    public const fields_TRACKING_DATA = 'tracking_data';
    public const fields_LAST_TRACKED_AT = 'last_tracked_at';
    public const fields_TRACKING_COUNT = 'tracking_count';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';

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
        $this->_table = self::table;
        $this->_primary_key = self::fields_ID;
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('物流跟踪记录表')
                ->addColumn(
                    self::fields_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'primary key auto_increment',
                    '跟踪记录ID'
                )
                ->addColumn(
                    self::fields_TRACKING_NUMBER,
                    TableInterface::column_type_VARCHAR,
                    100,
                    'not null',
                    '物流单号'
                )
                ->addColumn(
                    self::fields_CARRIER_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'not null',
                    '快递公司ID'
                )
                ->addColumn(
                    self::fields_ORDER_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default null',
                    '订单ID（关联订单表，如需要）'
                )
                ->addColumn(
                    self::fields_CUSTOMER_ID,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default null',
                    '客户ID（关联客户表）'
                )
                ->addColumn(
                    self::fields_STATUS,
                    TableInterface::column_type_VARCHAR,
                    50,
                    'default \'pending\'',
                    '物流状态：pending/in_transit/delivered/exception/not_supported'
                )
                ->addColumn(
                    self::fields_CURRENT_LOCATION,
                    TableInterface::column_type_VARCHAR,
                    255,
                    'default null',
                    '当前位置'
                )
                ->addColumn(
                    self::fields_ESTIMATED_DELIVERY_DATE,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default null',
                    '预计送达时间'
                )
                ->addColumn(
                    self::fields_ACTUAL_DELIVERY_DATE,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default null',
                    '实际送达时间'
                )
                ->addColumn(
                    self::fields_TRACKING_DATA,
                    TableInterface::column_type_TEXT,
                    null,
                    'default null',
                    '详细跟踪数据（JSON格式，包含所有跟踪节点）'
                )
                ->addColumn(
                    self::fields_LAST_TRACKED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default null',
                    '最后跟踪时间'
                )
                ->addColumn(
                    self::fields_TRACKING_COUNT,
                    TableInterface::column_type_INTEGER,
                    null,
                    'default 0',
                    '跟踪次数'
                )
                ->addColumn(
                    self::fields_CREATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP',
                    '创建时间'
                )
                ->addColumn(
                    self::fields_UPDATED_AT,
                    TableInterface::column_type_DATETIME,
                    null,
                    'default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                    '更新时间'
                )
                ->addIndex(TableInterface::index_type_UNIQUE, 'idx_tracking_number_carrier', [self::fields_TRACKING_NUMBER, self::fields_CARRIER_ID], '物流单号和快递公司唯一索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_carrier_id', self::fields_CARRIER_ID, '快递公司ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_order_id', self::fields_ORDER_ID, '订单ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_customer_id', self::fields_CUSTOMER_ID, '客户ID索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_status', self::fields_STATUS, '物流状态索引')
                ->addIndex(TableInterface::index_type_KEY, 'idx_tracking_number', self::fields_TRACKING_NUMBER, '物流单号索引')
                ->create();
        }
    }

    public function upgrade(ModelSetup $setup, Context $context): void {}

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
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
            ->where(self::fields_TRACKING_NUMBER, $trackingNumber)
            ->where(self::fields_CARRIER_ID, $carrierId)
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
        $data = $this->getData(self::fields_TRACKING_DATA);
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
        $this->setData(self::fields_TRACKING_DATA, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * 增加跟踪次数
     * 
     * @return $this
     */
    public function incrementTrackingCount(): self
    {
        $count = (int)$this->getData(self::fields_TRACKING_COUNT);
        $this->setData(self::fields_TRACKING_COUNT, $count + 1);
        $this->setData(self::fields_LAST_TRACKED_AT, date('Y-m-d H:i:s'));
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
        $status = $status ?? $this->getData(self::fields_STATUS);
        
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

