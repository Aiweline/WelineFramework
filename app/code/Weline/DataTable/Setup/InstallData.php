<?php

namespace Weline\DataTable\Setup;

use Weline\Framework\Setup\InstallDataInterface;
use Weline\Framework\Setup\ModuleContextInterface;
use Weline\Framework\Setup\ModuleDataSetupInterface;

class InstallData implements InstallDataInterface
{
    /**
     * 安装数据
     */
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        // 创建测试数据表
        $this->createTestTables($setup);

        // 插入初始配置数据
        $this->insertInitialData($setup);

        $setup->endSetup();
    }

    /**
     * 创建测试数据表
     */
    private function createTestTables(ModuleDataSetupInterface $setup)
    {
        // 创建测试用户表
        $setup->getConnection()->query("
            CREATE TABLE IF NOT EXISTS `weline_datatable_test_user` (
                `user_id` int(11) NOT NULL AUTO_INCREMENT,
                `username` varchar(50) NOT NULL,
                `email` varchar(100) NOT NULL,
                `password` varchar(255) NOT NULL,
                `first_name` varchar(50) DEFAULT NULL,
                `last_name` varchar(50) DEFAULT NULL,
                `phone` varchar(20) DEFAULT NULL,
                `status` tinyint(1) DEFAULT 1,
                `gender` enum('male','female','other') DEFAULT 'other',
                `birth_date` date DEFAULT NULL,
                `avatar` varchar(255) DEFAULT NULL,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`),
                UNIQUE KEY `username` (`username`),
                UNIQUE KEY `email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // 创建测试产品表
        $setup->getConnection()->query("
            CREATE TABLE IF NOT EXISTS `weline_datatable_test_product` (
                `product_id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL,
                `sku` varchar(50) NOT NULL,
                `description` text,
                `price` decimal(10,2) NOT NULL DEFAULT 0.00,
                `cost` decimal(10,2) DEFAULT 0.00,
                `stock` int(11) DEFAULT 0,
                `category` varchar(50) DEFAULT NULL,
                `brand` varchar(50) DEFAULT NULL,
                `status` tinyint(1) DEFAULT 1,
                `featured` tinyint(1) DEFAULT 0,
                `image` varchar(255) DEFAULT NULL,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`product_id`),
                UNIQUE KEY `sku` (`sku`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // 创建测试订单表
        $setup->getConnection()->query("
            CREATE TABLE IF NOT EXISTS `weline_datatable_test_order` (
                `order_id` int(11) NOT NULL AUTO_INCREMENT,
                `order_number` varchar(50) NOT NULL,
                `user_id` int(11) NOT NULL,
                `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
                `payment_method` varchar(50) DEFAULT NULL,
                `payment_status` enum('pending','paid','failed','refunded') DEFAULT 'pending',
                `order_status` enum('pending','processing','shipped','delivered','cancelled') DEFAULT 'pending',
                `shipping_address` text,
                `billing_address` text,
                `notes` text,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`order_id`),
                UNIQUE KEY `order_number` (`order_number`),
                KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    /**
     * 插入初始数据
     */
    private function insertInitialData(ModuleDataSetupInterface $setup)
    {
        // 插入示例用户数据
        $setup->getConnection()->query("
            INSERT IGNORE INTO `weline_datatable_test_user` 
            (`username`, `email`, `password`, `first_name`, `last_name`, `phone`, `status`, `gender`, `birth_date`) 
            VALUES 
            ('admin', 'admin@example.com', '" . password_hash('admin123', PASSWORD_DEFAULT) . "', 'Admin', 'User', '13800138000', 1, 'male', '1990-01-01'),
            ('user1', 'user1@example.com', '" . password_hash('user123', PASSWORD_DEFAULT) . "', 'John', 'Doe', '13800138001', 1, 'male', '1992-05-15'),
            ('user2', 'user2@example.com', '" . password_hash('user123', PASSWORD_DEFAULT) . "', 'Jane', 'Smith', '13800138002', 1, 'female', '1988-12-20'),
            ('user3', 'user3@example.com', '" . password_hash('user123', PASSWORD_DEFAULT) . "', 'Mike', 'Johnson', '13800138003', 0, 'male', '1995-08-10'),
            ('user4', 'user4@example.com', '" . password_hash('user123', PASSWORD_DEFAULT) . "', 'Sarah', 'Wilson', '13800138004', 1, 'female', '1991-03-25');
        ");

        // 插入示例产品数据
        $setup->getConnection()->query("
            INSERT IGNORE INTO `weline_datatable_test_product` 
            (`name`, `sku`, `description`, `price`, `cost`, `stock`, `category`, `brand`, `status`, `featured`) 
            VALUES 
            ('iPhone 15 Pro', 'IPHONE15PRO', '最新款iPhone，搭载A17 Pro芯片', 8999.00, 6500.00, 100, '手机', 'Apple', 1, 1),
            ('MacBook Pro 14', 'MBP14', '14英寸MacBook Pro，M3芯片', 14999.00, 12000.00, 50, '笔记本', 'Apple', 1, 1),
            ('AirPods Pro', 'AIRPODSPRO', '主动降噪无线耳机', 1999.00, 1200.00, 200, '耳机', 'Apple', 1, 0),
            ('iPad Air', 'IPADAIR', '轻薄便携的iPad Air', 4799.00, 3500.00, 80, '平板', 'Apple', 1, 0),
            ('Apple Watch', 'APPLEWATCH', '智能手表，健康监测', 2999.00, 2000.00, 150, '手表', 'Apple', 1, 0);
        ");

        // 插入示例订单数据
        $setup->getConnection()->query("
            INSERT IGNORE INTO `weline_datatable_test_order` 
            (`order_number`, `user_id`, `total_amount`, `payment_method`, `payment_status`, `order_status`, `shipping_address`, `billing_address`) 
            VALUES 
            ('ORD202401001', 1, 8999.00, 'credit_card', 'paid', 'delivered', '北京市朝阳区xxx街道', '北京市朝阳区xxx街道'),
            ('ORD202401002', 2, 14999.00, 'alipay', 'paid', 'shipped', '上海市浦东新区xxx路', '上海市浦东新区xxx路'),
            ('ORD202401003', 3, 1999.00, 'wechat_pay', 'pending', 'pending', '广州市天河区xxx大道', '广州市天河区xxx大道'),
            ('ORD202401004', 4, 4799.00, 'credit_card', 'paid', 'processing', '深圳市南山区xxx街', '深圳市南山区xxx街'),
            ('ORD202401005', 1, 2999.00, 'alipay', 'failed', 'cancelled', '杭州市西湖区xxx路', '杭州市西湖区xxx路');
        ");
    }
} 