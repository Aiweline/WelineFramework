<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DataTable\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class TestOrder extends Model
{
    public const table = 'datatable_test_orders';

    public const fields_ID = 'id';
    public const fields_order_no = 'order_no';
    public const fields_user_id = 'user_id';
    public const fields_total_amount = 'total_amount';
    public const fields_discount_amount = 'discount_amount';
    public const fields_shipping_fee = 'shipping_fee';
    public const fields_final_amount = 'final_amount';
    public const fields_payment_method = 'payment_method';
    public const fields_payment_status = 'payment_status';
    public const fields_order_status = 'order_status';
    public const fields_shipping_address = 'shipping_address';
    public const fields_shipping_phone = 'shipping_phone';
    public const fields_shipping_name = 'shipping_name';
    public const fields_shipping_company = 'shipping_company';
    public const fields_tracking_number = 'tracking_number';
    public const fields_notes = 'notes';
    public const fields_created_at = 'created_at';
    public const fields_updated_at = 'updated_at';
    public const fields_paid_at = 'paid_at';
    public const fields_shipped_at = 'shipped_at';
    public const fields_completed_at = 'completed_at';

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
        // 升级逻辑
    }

    /**
     * @inheritDoc
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist()) {
            $setup->createTable('测试订单表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '订单ID')
                ->addColumn(self::fields_order_no, TableInterface::column_type_VARCHAR, 50, 'not null unique', '订单号')
                ->addColumn(self::fields_user_id, TableInterface::column_type_INTEGER, 0, 'not null', '用户ID')
                ->addColumn(self::fields_total_amount, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '订单总金额')
                ->addColumn(self::fields_discount_amount, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '优惠金额')
                ->addColumn(self::fields_shipping_fee, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '运费')
                ->addColumn(self::fields_final_amount, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '最终金额')
                ->addColumn(self::fields_payment_method, TableInterface::column_type_VARCHAR, 50, '', '支付方式')
                ->addColumn(self::fields_payment_status, TableInterface::column_type_INTEGER, 1, 'default 0', '支付状态：0-未支付，1-已支付，2-已退款')
                ->addColumn(self::fields_order_status, TableInterface::column_type_INTEGER, 1, 'default 0', '订单状态：0-待确认，1-已确认，2-已发货，3-已完成，4-已取消')
                ->addColumn(self::fields_shipping_address, TableInterface::column_type_TEXT, 0, '', '收货地址')
                ->addColumn(self::fields_shipping_phone, TableInterface::column_type_VARCHAR, 20, '', '收货电话')
                ->addColumn(self::fields_shipping_name, TableInterface::column_type_VARCHAR, 100, '', '收货人姓名')
                ->addColumn(self::fields_shipping_company, TableInterface::column_type_VARCHAR, 100, '', '快递公司')
                ->addColumn(self::fields_tracking_number, TableInterface::column_type_VARCHAR, 100, '', '快递单号')
                ->addColumn(self::fields_notes, TableInterface::column_type_TEXT, 0, '', '订单备注')
                ->addColumn(self::fields_created_at, TableInterface::column_type_DATETIME, 0, 'default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_updated_at, TableInterface::column_type_DATETIME, 0, 'default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', '更新时间')
                ->addColumn(self::fields_paid_at, TableInterface::column_type_DATETIME, 0, '', '支付时间')
                ->addColumn(self::fields_shipped_at, TableInterface::column_type_DATETIME, 0, '', '发货时间')
                ->addColumn(self::fields_completed_at, TableInterface::column_type_DATETIME, 0, '', '完成时间')
                ->create();
        }
    }

    /**
     * 获取测试数据
     */
    public function getTestData(): array
    {
        return [
            [
                'id' => 1,
                'order_no' => 'ORD20240101001',
                'user_id' => 1,
                'total_amount' => 8999.00,
                'discount_amount' => 500.00,
                'shipping_fee' => 0.00,
                'final_amount' => 8499.00,
                'payment_method' => '支付宝',
                'payment_status' => 1,
                'order_status' => 3,
                'shipping_address' => '北京市朝阳区建国门外大街1号',
                'shipping_phone' => '13800138001',
                'shipping_name' => '张三',
                'shipping_company' => '顺丰快递',
                'tracking_number' => 'SF1234567890',
                'notes' => '请尽快发货',
                'created_at' => '2024-01-01 10:00:00',
                'updated_at' => '2024-01-01 10:00:00',
                'paid_at' => '2024-01-01 10:05:00',
                'shipped_at' => '2024-01-02 14:30:00',
                'completed_at' => '2024-01-04 16:20:00'
            ],
            [
                'id' => 2,
                'order_no' => 'ORD20240102001',
                'user_id' => 2,
                'total_amount' => 14999.00,
                'discount_amount' => 1000.00,
                'shipping_fee' => 0.00,
                'final_amount' => 13999.00,
                'payment_method' => '微信支付',
                'payment_status' => 1,
                'order_status' => 2,
                'shipping_address' => '上海市浦东新区陆家嘴环路1000号',
                'shipping_phone' => '13800138002',
                'shipping_name' => '李四',
                'shipping_company' => '京东物流',
                'tracking_number' => 'JD9876543210',
                'notes' => '工作日送货',
                'created_at' => '2024-01-02 11:30:00',
                'updated_at' => '2024-01-02 11:30:00',
                'paid_at' => '2024-01-02 11:35:00',
                'shipped_at' => '2024-01-03 09:15:00',
                'completed_at' => null
            ],
            [
                'id' => 3,
                'order_no' => 'ORD20240103001',
                'user_id' => 3,
                'total_amount' => 1899.00,
                'discount_amount' => 0.00,
                'shipping_fee' => 10.00,
                'final_amount' => 1909.00,
                'payment_method' => '支付宝',
                'payment_status' => 1,
                'order_status' => 1,
                'shipping_address' => '广州市天河区珠江新城花城大道85号',
                'shipping_phone' => '13800138003',
                'shipping_name' => '王五',
                'shipping_company' => null,
                'tracking_number' => null,
                'notes' => null,
                'created_at' => '2024-01-03 09:15:00',
                'updated_at' => '2024-01-03 09:15:00',
                'paid_at' => '2024-01-03 09:20:00',
                'shipped_at' => null,
                'completed_at' => null
            ],
            [
                'id' => 4,
                'order_no' => 'ORD20240104001',
                'user_id' => 4,
                'total_amount' => 4799.00,
                'discount_amount' => 200.00,
                'shipping_fee' => 0.00,
                'final_amount' => 4599.00,
                'payment_method' => '微信支付',
                'payment_status' => 0,
                'order_status' => 0,
                'shipping_address' => '深圳市南山区科技园南区深圳湾科技生态园',
                'shipping_phone' => '13800138004',
                'shipping_name' => '赵六',
                'shipping_company' => null,
                'tracking_number' => null,
                'notes' => '请使用环保包装',
                'created_at' => '2024-01-04 14:20:00',
                'updated_at' => '2024-01-04 14:20:00',
                'paid_at' => null,
                'shipped_at' => null,
                'completed_at' => null
            ],
            [
                'id' => 5,
                'order_no' => 'ORD20240105001',
                'user_id' => 5,
                'total_amount' => 3299.00,
                'discount_amount' => 0.00,
                'shipping_fee' => 0.00,
                'final_amount' => 3299.00,
                'payment_method' => '支付宝',
                'payment_status' => 1,
                'order_status' => 3,
                'shipping_address' => '杭州市西湖区文三路259号',
                'shipping_phone' => '13800138005',
                'shipping_name' => '孙七',
                'shipping_company' => '圆通快递',
                'tracking_number' => 'YT1234567890',
                'notes' => '请轻拿轻放',
                'created_at' => '2024-01-05 16:45:00',
                'updated_at' => '2024-01-05 16:45:00',
                'paid_at' => '2024-01-05 16:50:00',
                'shipped_at' => '2024-01-06 10:30:00',
                'completed_at' => '2024-01-08 14:15:00'
            ],
            [
                'id' => 6,
                'order_no' => 'ORD20240106001',
                'user_id' => 6,
                'total_amount' => 6999.00,
                'discount_amount' => 500.00,
                'shipping_fee' => 0.00,
                'final_amount' => 6499.00,
                'payment_method' => '微信支付',
                'payment_status' => 1,
                'order_status' => 4,
                'shipping_address' => '成都市锦江区红星路三段1号',
                'shipping_phone' => '13800138006',
                'shipping_name' => '周八',
                'shipping_company' => null,
                'tracking_number' => null,
                'notes' => '已取消订单',
                'created_at' => '2024-01-06 08:30:00',
                'updated_at' => '2024-01-06 08:30:00',
                'paid_at' => '2024-01-06 08:35:00',
                'shipped_at' => null,
                'completed_at' => null
            ],
            [
                'id' => 7,
                'order_no' => 'ORD20240107001',
                'user_id' => 7,
                'total_amount' => 2899.00,
                'discount_amount' => 0.00,
                'shipping_fee' => 15.00,
                'final_amount' => 2914.00,
                'payment_method' => '支付宝',
                'payment_status' => 1,
                'order_status' => 2,
                'shipping_address' => '武汉市江汉区解放大道634号',
                'shipping_phone' => '13800138007',
                'shipping_name' => '吴九',
                'shipping_company' => '中通快递',
                'tracking_number' => 'ZT9876543210',
                'notes' => '请电话联系收货',
                'created_at' => '2024-01-07 13:10:00',
                'updated_at' => '2024-01-07 13:10:00',
                'paid_at' => '2024-01-07 13:15:00',
                'shipped_at' => '2024-01-08 16:45:00',
                'completed_at' => null
            ],
            [
                'id' => 8,
                'order_no' => 'ORD20240108001',
                'user_id' => 8,
                'total_amount' => 8999.00,
                'discount_amount' => 800.00,
                'shipping_fee' => 0.00,
                'final_amount' => 8199.00,
                'payment_method' => '微信支付',
                'payment_status' => 1,
                'order_status' => 1,
                'shipping_address' => '南京市鼓楼区中山路321号',
                'shipping_phone' => '13800138008',
                'shipping_name' => '郑十',
                'shipping_company' => null,
                'tracking_number' => null,
                'notes' => '请开具发票',
                'created_at' => '2024-01-08 15:20:00',
                'updated_at' => '2024-01-08 15:20:00',
                'paid_at' => '2024-01-08 15:25:00',
                'shipped_at' => null,
                'completed_at' => null
            ],
            [
                'id' => 9,
                'order_no' => 'ORD20240109001',
                'user_id' => 9,
                'total_amount' => 2299.00,
                'discount_amount' => 100.00,
                'shipping_fee' => 0.00,
                'final_amount' => 2199.00,
                'payment_method' => '支付宝',
                'payment_status' => 0,
                'order_status' => 0,
                'shipping_address' => '西安市雁塔区高新路25号',
                'shipping_phone' => '13800138009',
                'shipping_name' => '钱十一',
                'shipping_company' => null,
                'tracking_number' => null,
                'notes' => null,
                'created_at' => '2024-01-09 10:45:00',
                'updated_at' => '2024-01-09 10:45:00',
                'paid_at' => null,
                'shipped_at' => null,
                'completed_at' => null
            ],
            [
                'id' => 10,
                'order_no' => 'ORD20240110001',
                'user_id' => 10,
                'total_amount' => 3299.00,
                'discount_amount' => 0.00,
                'shipping_fee' => 20.00,
                'final_amount' => 3319.00,
                'payment_method' => '微信支付',
                'payment_status' => 1,
                'order_status' => 3,
                'shipping_address' => '重庆市渝中区解放碑步行街',
                'shipping_phone' => '13800138010',
                'shipping_name' => '孙十二',
                'shipping_company' => '韵达快递',
                'tracking_number' => 'YD1234567890',
                'notes' => '请下午送货',
                'created_at' => '2024-01-10 12:00:00',
                'updated_at' => '2024-01-10 12:00:00',
                'paid_at' => '2024-01-10 12:05:00',
                'shipped_at' => '2024-01-11 09:30:00',
                'completed_at' => '2024-01-13 15:45:00'
            ]
        ];
    }

    /**
     * 获取支付状态选项
     */
    public function getPaymentStatusOptions(): array
    {
        return [
            ['value' => 0, 'label' => '未支付'],
            ['value' => 1, 'label' => '已支付'],
            ['value' => 2, 'label' => '已退款']
        ];
    }

    /**
     * 获取订单状态选项
     */
    public function getOrderStatusOptions(): array
    {
        return [
            ['value' => 0, 'label' => '待确认'],
            ['value' => 1, 'label' => '已确认'],
            ['value' => 2, 'label' => '已发货'],
            ['value' => 3, 'label' => '已完成'],
            ['value' => 4, 'label' => '已取消']
        ];
    }

    /**
     * 获取支付方式选项
     */
    public function getPaymentMethodOptions(): array
    {
        return [
            ['value' => 'alipay', 'label' => '支付宝'],
            ['value' => 'wechat', 'label' => '微信支付'],
            ['value' => 'bank', 'label' => '银行转账'],
            ['value' => 'cash', 'label' => '货到付款']
        ];
    }

    /**
     * 支付状态获取器
     */
    public function getPaymentStatusTextAttribute($value): string
    {
        $options = [
            0 => '未支付',
            1 => '已支付',
            2 => '已退款'
        ];
        return $options[$value] ?? '未知';
    }

    /**
     * 订单状态获取器
     */
    public function getOrderStatusTextAttribute($value): string
    {
        $options = [
            0 => '待确认',
            1 => '已确认',
            2 => '已发货',
            3 => '已完成',
            4 => '已取消'
        ];
        return $options[$value] ?? '未知';
    }

    /**
     * 总金额获取器（格式化）
     */
    public function getTotalAmountFormattedAttribute($value): string
    {
        return '¥' . number_format($value, 2);
    }

    /**
     * 最终金额获取器（格式化）
     */
    public function getFinalAmountFormattedAttribute($value): string
    {
        return '¥' . number_format($value, 2);
    }

    /**
     * 优惠金额获取器（格式化）
     */
    public function getDiscountAmountFormattedAttribute($value): string
    {
        return '¥' . number_format($value, 2);
    }

    /**
     * 运费获取器（格式化）
     */
    public function getShippingFeeFormattedAttribute($value): string
    {
        return '¥' . number_format($value, 2);
    }
} 