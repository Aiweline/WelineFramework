<?php

declare(strict_types=1);

namespace Weline\Customer\Setup;

use Weline\Customer\Model\Customer;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    /**
     * 安装时初始化默认客户（业务初始化，计划 3.10）
     */
    public function setup(Setup $setup, Context $context): void
    {
        /** @var Customer $customer */
        $customer = ObjectManager::getInstance(Customer::class);
        $exist = $customer->clear()->where(Customer::schema_fields_username, '秋枫雁飞')->find()->fetch();
        if ($exist && $exist->getId()) {
            return;
        }
        $customer->clear()->setUsername('秋枫雁飞')->setPassword('admin')->save();
    }
}
