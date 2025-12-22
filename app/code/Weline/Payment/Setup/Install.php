<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Setup;

use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Model\PaymentTransaction;

class Install implements InstallInterface
{
    /**
     * 安装模块
     */
    public function setup(Setup $setup, Context $context): void
    {
        // 安装支付方式表
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = ObjectManager::getInstance(PaymentMethod::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($paymentMethod);
        $paymentMethod->setup($modelSetup, $context);
        
        // 安装支付交易表
        /** @var PaymentTransaction $paymentTransaction */
        $paymentTransaction = ObjectManager::getInstance(PaymentTransaction::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($paymentTransaction);
        $paymentTransaction->setup($modelSetup, $context);
    }
}

