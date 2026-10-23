<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Checkout\Setup;

use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;

/**
 * Checkout Module Installation Script
 *
 * Handles database schema creation for the Weline_Checkout module.
 * Connection is obtained via Setup::getDb() (set by setModuleContext before setup runs).
 *
 * @package Weline_Checkout
 */
class Install implements InstallInterface
{
    /**
     * Execute installation
     * 
     * Creates all required database tables and indexes for the Checkout module.
     * 
     * @param Setup $setup Setup instance
     * @param Context $context Installation context
     * @return void
     */
    public function setup(Setup $setup, Context $context): void
    {
        $connection = $setup->getDb();
        
        // 创建订单表
        
        // 创建订单项表
        
        // 创建支付交易表
    }
}

