<?php

namespace Weline\DataTable\Setup;

use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Setup\Db\Setup as DbSetup;
use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;

/**
 * DataTable 演示夹具：建表/种子走 ORM Schema + Query，禁止业务手写 MySQL 方言 DDL/DML。
 */
class InstallData implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $db = $setup->getDbSetup();
        $connector = $db->getConnector();

        $this->createTestTables($db, $connector);
        $this->insertInitialData($connector, $db);
    }

    private function createTestTables(DbSetup $db, ConnectorInterface $connector): void
    {
        $userTable = $db->getTable('datatable_test_user');
        $productTable = $db->getTable('datatable_test_product');
        $orderTable = $db->getTable('datatable_test_order');

        if (!$connector->tableExist($userTable)) {
            $connector->createTableFromSchema($userTable, [
                'comment' => 'DataTable demo users',
                'columns' => [
                    ['name' => 'user_id', 'type' => 'int', 'nullable' => false, 'primaryKey' => true, 'autoIncrement' => true, 'comment' => '用户ID'],
                    ['name' => 'username', 'type' => 'varchar', 'length' => 50, 'nullable' => false, 'unique' => true, 'comment' => '用户名'],
                    ['name' => 'email', 'type' => 'varchar', 'length' => 100, 'nullable' => false, 'unique' => true, 'comment' => '邮箱'],
                    ['name' => 'password', 'type' => 'varchar', 'length' => 255, 'nullable' => false, 'comment' => '密码'],
                    ['name' => 'first_name', 'type' => 'varchar', 'length' => 50, 'nullable' => true, 'comment' => '名'],
                    ['name' => 'last_name', 'type' => 'varchar', 'length' => 50, 'nullable' => true, 'comment' => '姓'],
                    ['name' => 'phone', 'type' => 'varchar', 'length' => 20, 'nullable' => true, 'comment' => '电话'],
                    ['name' => 'status', 'type' => 'smallint', 'nullable' => true, 'default' => 1, 'comment' => '状态'],
                    ['name' => 'gender', 'type' => 'varchar', 'length' => 16, 'nullable' => true, 'default' => 'other', 'comment' => '性别'],
                    ['name' => 'birth_date', 'type' => 'date', 'nullable' => true, 'comment' => '生日'],
                    ['name' => 'avatar', 'type' => 'varchar', 'length' => 255, 'nullable' => true, 'comment' => '头像'],
                    ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true, 'default' => 'CURRENT_TIMESTAMP', 'comment' => '创建时间'],
                    ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true, 'default' => 'CURRENT_TIMESTAMP', 'comment' => '更新时间'],
                ],
            ]);
        }

        if (!$connector->tableExist($productTable)) {
            $connector->createTableFromSchema($productTable, [
                'comment' => 'DataTable demo products',
                'columns' => [
                    ['name' => 'product_id', 'type' => 'int', 'nullable' => false, 'primaryKey' => true, 'autoIncrement' => true, 'comment' => '商品ID'],
                    ['name' => 'name', 'type' => 'varchar', 'length' => 100, 'nullable' => false, 'comment' => '名称'],
                    ['name' => 'sku', 'type' => 'varchar', 'length' => 50, 'nullable' => false, 'unique' => true, 'comment' => 'SKU'],
                    ['name' => 'description', 'type' => 'text', 'nullable' => true, 'comment' => '描述'],
                    ['name' => 'price', 'type' => 'decimal', 'length' => '10,2', 'nullable' => false, 'default' => 0, 'comment' => '售价'],
                    ['name' => 'cost', 'type' => 'decimal', 'length' => '10,2', 'nullable' => true, 'default' => 0, 'comment' => '成本'],
                    ['name' => 'stock', 'type' => 'int', 'nullable' => true, 'default' => 0, 'comment' => '库存'],
                    ['name' => 'category', 'type' => 'varchar', 'length' => 50, 'nullable' => true, 'comment' => '分类'],
                    ['name' => 'brand', 'type' => 'varchar', 'length' => 50, 'nullable' => true, 'comment' => '品牌'],
                    ['name' => 'status', 'type' => 'smallint', 'nullable' => true, 'default' => 1, 'comment' => '状态'],
                    ['name' => 'featured', 'type' => 'smallint', 'nullable' => true, 'default' => 0, 'comment' => '精选'],
                    ['name' => 'image', 'type' => 'varchar', 'length' => 255, 'nullable' => true, 'comment' => '图片'],
                    ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true, 'default' => 'CURRENT_TIMESTAMP', 'comment' => '创建时间'],
                    ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true, 'default' => 'CURRENT_TIMESTAMP', 'comment' => '更新时间'],
                ],
            ]);
        }

        if (!$connector->tableExist($orderTable)) {
            $connector->createTableFromSchema($orderTable, [
                'comment' => 'DataTable demo orders',
                'columns' => [
                    ['name' => 'order_id', 'type' => 'int', 'nullable' => false, 'primaryKey' => true, 'autoIncrement' => true, 'comment' => '订单ID'],
                    ['name' => 'order_number', 'type' => 'varchar', 'length' => 50, 'nullable' => false, 'unique' => true, 'comment' => '订单号'],
                    ['name' => 'user_id', 'type' => 'int', 'nullable' => false, 'comment' => '用户ID'],
                    ['name' => 'total_amount', 'type' => 'decimal', 'length' => '10,2', 'nullable' => false, 'default' => 0, 'comment' => '总额'],
                    ['name' => 'payment_method', 'type' => 'varchar', 'length' => 50, 'nullable' => true, 'comment' => '支付方式'],
                    ['name' => 'payment_status', 'type' => 'varchar', 'length' => 32, 'nullable' => true, 'default' => 'pending', 'comment' => '支付状态'],
                    ['name' => 'order_status', 'type' => 'varchar', 'length' => 32, 'nullable' => true, 'default' => 'pending', 'comment' => '订单状态'],
                    ['name' => 'shipping_address', 'type' => 'text', 'nullable' => true, 'comment' => '收货地址'],
                    ['name' => 'billing_address', 'type' => 'text', 'nullable' => true, 'comment' => '账单地址'],
                    ['name' => 'notes', 'type' => 'text', 'nullable' => true, 'comment' => '备注'],
                    ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true, 'default' => 'CURRENT_TIMESTAMP', 'comment' => '创建时间'],
                    ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true, 'default' => 'CURRENT_TIMESTAMP', 'comment' => '更新时间'],
                ],
                'indexes' => [
                    ['name' => 'idx_datatable_test_order_user', 'columns' => ['user_id'], 'type' => 'INDEX'],
                ],
            ]);
        }
    }

    private function insertInitialData(ConnectorInterface $connector, DbSetup $db): void
    {
        $userTable = $db->getTable('datatable_test_user');
        $productTable = $db->getTable('datatable_test_product');
        $orderTable = $db->getTable('datatable_test_order');

        $users = [
            ['username' => 'admin', 'email' => 'admin@example.com', 'password' => password_hash('admin123', PASSWORD_DEFAULT), 'first_name' => 'Admin', 'last_name' => 'User', 'phone' => '13800138000', 'status' => 1, 'gender' => 'male', 'birth_date' => '1990-01-01'],
            ['username' => 'user1', 'email' => 'user1@example.com', 'password' => password_hash('user123', PASSWORD_DEFAULT), 'first_name' => 'John', 'last_name' => 'Doe', 'phone' => '13800138001', 'status' => 1, 'gender' => 'male', 'birth_date' => '1992-05-15'],
            ['username' => 'user2', 'email' => 'user2@example.com', 'password' => password_hash('user123', PASSWORD_DEFAULT), 'first_name' => 'Jane', 'last_name' => 'Smith', 'phone' => '13800138002', 'status' => 1, 'gender' => 'female', 'birth_date' => '1988-12-20'],
            ['username' => 'user3', 'email' => 'user3@example.com', 'password' => password_hash('user123', PASSWORD_DEFAULT), 'first_name' => 'Mike', 'last_name' => 'Johnson', 'phone' => '13800138003', 'status' => 0, 'gender' => 'male', 'birth_date' => '1995-08-10'],
            ['username' => 'user4', 'email' => 'user4@example.com', 'password' => password_hash('user123', PASSWORD_DEFAULT), 'first_name' => 'Sarah', 'last_name' => 'Wilson', 'phone' => '13800138004', 'status' => 1, 'gender' => 'female', 'birth_date' => '1991-03-25'],
        ];
        $products = [
            ['name' => 'iPhone 15 Pro', 'sku' => 'IPHONE15PRO', 'description' => '最新款iPhone，搭载A17 Pro芯片', 'price' => 8999.00, 'cost' => 6500.00, 'stock' => 100, 'category' => '手机', 'brand' => 'Apple', 'status' => 1, 'featured' => 1],
            ['name' => 'MacBook Pro 14', 'sku' => 'MBP14', 'description' => '14英寸MacBook Pro，M3芯片', 'price' => 14999.00, 'cost' => 12000.00, 'stock' => 50, 'category' => '笔记本', 'brand' => 'Apple', 'status' => 1, 'featured' => 1],
            ['name' => 'AirPods Pro', 'sku' => 'AIRPODSPRO', 'description' => '主动降噪无线耳机', 'price' => 1999.00, 'cost' => 1200.00, 'stock' => 200, 'category' => '耳机', 'brand' => 'Apple', 'status' => 1, 'featured' => 0],
            ['name' => 'iPad Air', 'sku' => 'IPADAIR', 'description' => '轻薄便携的iPad Air', 'price' => 4799.00, 'cost' => 3500.00, 'stock' => 80, 'category' => '平板', 'brand' => 'Apple', 'status' => 1, 'featured' => 0],
            ['name' => 'Apple Watch', 'sku' => 'APPLEWATCH', 'description' => '智能手表，健康监测', 'price' => 2999.00, 'cost' => 2000.00, 'stock' => 150, 'category' => '手表', 'brand' => 'Apple', 'status' => 1, 'featured' => 0],
        ];
        $orders = [
            ['order_number' => 'ORD202401001', 'user_id' => 1, 'total_amount' => 8999.00, 'payment_method' => 'credit_card', 'payment_status' => 'paid', 'order_status' => 'delivered', 'shipping_address' => '北京市朝阳区xxx街道', 'billing_address' => '北京市朝阳区xxx街道'],
            ['order_number' => 'ORD202401002', 'user_id' => 2, 'total_amount' => 14999.00, 'payment_method' => 'alipay', 'payment_status' => 'paid', 'order_status' => 'shipped', 'shipping_address' => '上海市浦东新区xxx路', 'billing_address' => '上海市浦东新区xxx路'],
            ['order_number' => 'ORD202401003', 'user_id' => 3, 'total_amount' => 1999.00, 'payment_method' => 'wechat_pay', 'payment_status' => 'pending', 'order_status' => 'pending', 'shipping_address' => '广州市天河区xxx大道', 'billing_address' => '广州市天河区xxx大道'],
            ['order_number' => 'ORD202401004', 'user_id' => 4, 'total_amount' => 4799.00, 'payment_method' => 'credit_card', 'payment_status' => 'paid', 'order_status' => 'processing', 'shipping_address' => '深圳市南山区xxx街', 'billing_address' => '深圳市南山区xxx街'],
            ['order_number' => 'ORD202401005', 'user_id' => 1, 'total_amount' => 2999.00, 'payment_method' => 'alipay', 'payment_status' => 'failed', 'order_status' => 'cancelled', 'shipping_address' => '杭州市西湖区xxx路', 'billing_address' => '杭州市西湖区xxx路'],
        ];

        $this->upsertSeedRows($connector, $userTable, $users, ['username'], 'username');
        $this->upsertSeedRows($connector, $productTable, $products, ['sku'], 'sku');
        $this->upsertSeedRows($connector, $orderTable, $orders, ['order_number'], 'order_number');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $conflictFields
     */
    private function upsertSeedRows(
        ConnectorInterface $connector,
        string $table,
        array $rows,
        array $conflictFields,
        string $noOpUpdateField,
    ): void {
        $connector->getQuery()
            ->table($table)
            ->insert($rows, $conflictFields, $noOpUpdateField)
            ->fetch();
    }
}
