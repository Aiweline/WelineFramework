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

class TestProduct extends Model
{
    public const table = 'datatable_test_products';

    public const fields_ID = 'id';
    public const fields_name = 'name';
    public const fields_sku = 'sku';
    public const fields_description = 'description';
    public const fields_price = 'price';
    public const fields_cost = 'cost';
    public const fields_stock = 'stock';
    public const fields_category_id = 'category_id';
    public const fields_brand = 'brand';
    public const fields_weight = 'weight';
    public const fields_dimensions = 'dimensions';
    public const fields_status = 'status';
    public const fields_featured = 'featured';
    public const fields_image = 'image';
    public const fields_created_at = 'created_at';
    public const fields_updated_at = 'updated_at';

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
            $setup->createTable('测试产品表')
                ->addColumn(self::fields_ID, TableInterface::column_type_INTEGER, 0, 'auto_increment primary key', '产品ID')
                ->addColumn(self::fields_name, TableInterface::column_type_VARCHAR, 200, 'not null', '产品名称')
                ->addColumn(self::fields_sku, TableInterface::column_type_VARCHAR, 100, 'not null unique', '产品SKU')
                ->addColumn(self::fields_description, TableInterface::column_type_TEXT, 0, '', '产品描述')
                ->addColumn(self::fields_price, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '产品价格')
                ->addColumn(self::fields_cost, TableInterface::column_type_DECIMAL, '10,2', 'default 0.00', '产品成本')
                ->addColumn(self::fields_stock, TableInterface::column_type_INTEGER, 0, 'default 0', '库存数量')
                ->addColumn(self::fields_category_id, TableInterface::column_type_INTEGER, 0, 'default 0', '分类ID')
                ->addColumn(self::fields_brand, TableInterface::column_type_VARCHAR, 100, '', '品牌')
                ->addColumn(self::fields_weight, TableInterface::column_type_DECIMAL, '8,2', 'default 0.00', '重量(kg)')
                ->addColumn(self::fields_dimensions, TableInterface::column_type_VARCHAR, 50, '', '尺寸(长x宽x高)')
                ->addColumn(self::fields_status, TableInterface::column_type_INTEGER, 1, 'default 1', '状态：1-上架，0-下架')
                ->addColumn(self::fields_featured, TableInterface::column_type_INTEGER, 1, 'default 0', '是否推荐：1-是，0-否')
                ->addColumn(self::fields_image, TableInterface::column_type_VARCHAR, 255, '', '产品图片')
                ->addColumn(self::fields_created_at, TableInterface::column_type_DATETIME, 0, 'default CURRENT_TIMESTAMP', '创建时间')
                ->addColumn(self::fields_updated_at, TableInterface::column_type_DATETIME, 0, 'default CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', '更新时间')
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
                'name' => 'iPhone 15 Pro',
                'sku' => 'IPHONE15PRO-256',
                'description' => '苹果最新旗舰手机，搭载A17 Pro芯片',
                'price' => 8999.00,
                'cost' => 6500.00,
                'stock' => 50,
                'category_id' => 1,
                'brand' => 'Apple',
                'weight' => 0.187,
                'dimensions' => '146.7x71.5x8.25',
                'status' => 1,
                'featured' => 1,
                'image' => '/uploads/products/iphone15pro.jpg',
                'created_at' => '2024-01-01 10:00:00',
                'updated_at' => '2024-01-01 10:00:00'
            ],
            [
                'id' => 2,
                'name' => 'MacBook Pro 14英寸',
                'sku' => 'MBP14-M3-512',
                'description' => '搭载M3芯片的专业级笔记本电脑',
                'price' => 14999.00,
                'cost' => 11000.00,
                'stock' => 25,
                'category_id' => 2,
                'brand' => 'Apple',
                'weight' => 1.55,
                'dimensions' => '312.6x221.2x15.5',
                'status' => 1,
                'featured' => 1,
                'image' => '/uploads/products/macbook-pro-14.jpg',
                'created_at' => '2024-01-02 11:30:00',
                'updated_at' => '2024-01-02 11:30:00'
            ],
            [
                'id' => 3,
                'name' => 'AirPods Pro',
                'sku' => 'AIRPODS-PRO-2',
                'description' => '主动降噪无线耳机，支持空间音频',
                'price' => 1899.00,
                'cost' => 1200.00,
                'stock' => 100,
                'category_id' => 3,
                'brand' => 'Apple',
                'weight' => 0.045,
                'dimensions' => '30.9x21.8x24.0',
                'status' => 1,
                'featured' => 0,
                'image' => '/uploads/products/airpods-pro.jpg',
                'created_at' => '2024-01-03 09:15:00',
                'updated_at' => '2024-01-03 09:15:00'
            ],
            [
                'id' => 4,
                'name' => 'iPad Air',
                'sku' => 'IPAD-AIR-256',
                'description' => '轻薄便携的平板电脑，适合工作和娱乐',
                'price' => 4799.00,
                'cost' => 3200.00,
                'stock' => 75,
                'category_id' => 4,
                'brand' => 'Apple',
                'weight' => 0.461,
                'dimensions' => '247.6x178.5x6.1',
                'status' => 1,
                'featured' => 0,
                'image' => '/uploads/products/ipad-air.jpg',
                'created_at' => '2024-01-04 14:20:00',
                'updated_at' => '2024-01-04 14:20:00'
            ],
            [
                'id' => 5,
                'name' => 'Apple Watch Series 9',
                'sku' => 'AW-S9-45MM',
                'description' => '智能手表，健康监测和运动追踪',
                'price' => 3299.00,
                'cost' => 2200.00,
                'stock' => 60,
                'category_id' => 5,
                'brand' => 'Apple',
                'weight' => 0.038,
                'dimensions' => '45x38x10.7',
                'status' => 1,
                'featured' => 0,
                'image' => '/uploads/products/apple-watch-s9.jpg',
                'created_at' => '2024-01-05 16:45:00',
                'updated_at' => '2024-01-05 16:45:00'
            ],
            [
                'id' => 6,
                'name' => 'Samsung Galaxy S24',
                'sku' => 'SAMSUNG-S24-256',
                'description' => '三星旗舰手机，AI功能强大',
                'price' => 6999.00,
                'cost' => 4800.00,
                'stock' => 40,
                'category_id' => 1,
                'brand' => 'Samsung',
                'weight' => 0.168,
                'dimensions' => '147.0x70.6x7.6',
                'status' => 1,
                'featured' => 0,
                'image' => '/uploads/products/samsung-s24.jpg',
                'created_at' => '2024-01-06 08:30:00',
                'updated_at' => '2024-01-06 08:30:00'
            ],
            [
                'id' => 7,
                'name' => 'Sony WH-1000XM5',
                'sku' => 'SONY-WH1000XM5',
                'description' => '索尼降噪耳机，音质出色',
                'price' => 2899.00,
                'cost' => 1800.00,
                'stock' => 30,
                'category_id' => 3,
                'brand' => 'Sony',
                'weight' => 0.250,
                'dimensions' => '248x167x72',
                'status' => 1,
                'featured' => 0,
                'image' => '/uploads/products/sony-wh1000xm5.jpg',
                'created_at' => '2024-01-07 13:10:00',
                'updated_at' => '2024-01-07 13:10:00'
            ],
            [
                'id' => 8,
                'name' => 'Dell XPS 13',
                'sku' => 'DELL-XPS13-512',
                'description' => '戴尔超薄笔记本电脑，性能强劲',
                'price' => 8999.00,
                'cost' => 6500.00,
                'stock' => 20,
                'category_id' => 2,
                'brand' => 'Dell',
                'weight' => 1.17,
                'dimensions' => '295.7x199.0x14.8',
                'status' => 1,
                'featured' => 0,
                'image' => '/uploads/products/dell-xps13.jpg',
                'created_at' => '2024-01-08 15:20:00',
                'updated_at' => '2024-01-08 15:20:00'
            ],
            [
                'id' => 9,
                'name' => 'Nintendo Switch OLED',
                'sku' => 'NINTENDO-SWITCH-OLED',
                'description' => '任天堂游戏机，OLED屏幕',
                'price' => 2299.00,
                'cost' => 1500.00,
                'stock' => 80,
                'category_id' => 6,
                'brand' => 'Nintendo',
                'weight' => 0.420,
                'dimensions' => '242x102x13.9',
                'status' => 1,
                'featured' => 0,
                'image' => '/uploads/products/nintendo-switch-oled.jpg',
                'created_at' => '2024-01-09 10:45:00',
                'updated_at' => '2024-01-09 10:45:00'
            ],
            [
                'id' => 10,
                'name' => 'GoPro Hero 12',
                'sku' => 'GOPRO-HERO12',
                'description' => '运动相机，4K视频拍摄',
                'price' => 3299.00,
                'cost' => 2200.00,
                'stock' => 45,
                'category_id' => 7,
                'brand' => 'GoPro',
                'weight' => 0.154,
                'dimensions' => '71.8x50.8x33.6',
                'status' => 0,
                'featured' => 0,
                'image' => '/uploads/products/gopro-hero12.jpg',
                'created_at' => '2024-01-10 12:00:00',
                'updated_at' => '2024-01-10 12:00:00'
            ]
        ];
    }

    /**
     * 获取状态选项
     */
    public function getStatusOptions(): array
    {
        return [
            ['value' => 1, 'label' => '上架'],
            ['value' => 0, 'label' => '下架']
        ];
    }

    /**
     * 获取推荐选项
     */
    public function getFeaturedOptions(): array
    {
        return [
            ['value' => 1, 'label' => '是'],
            ['value' => 0, 'label' => '否']
        ];
    }

    /**
     * 状态获取器
     */
    public function getStatusTextAttribute($value): string
    {
        return $value == 1 ? '上架' : '下架';
    }

    /**
     * 推荐获取器
     */
    public function getFeaturedTextAttribute($value): string
    {
        return $value == 1 ? '是' : '否';
    }

    /**
     * 价格获取器（格式化）
     */
    public function getPriceFormattedAttribute($value): string
    {
        return '¥' . number_format($value, 2);
    }

    /**
     * 成本获取器（格式化）
     */
    public function getCostFormattedAttribute($value): string
    {
        return '¥' . number_format($value, 2);
    }
} 