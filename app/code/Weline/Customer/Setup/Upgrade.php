<?php

declare(strict_types=1);

namespace Weline\Customer\Setup;

use Weline\Customer\Model\Customer;
use Weline\Customer\Model\Type;
use Weline\Framework\Database\Connection\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Db\Setup as DbSetup;
use Weline\Framework\Setup\UpgradeInterface;

/**
 * 客户模块升级：
 * - w_customer 幂等加列 customer_type（框架 Setup::columnExist 探测后 ALTER，pgsql/mysql 通用）
 * - 客户类型注册表种子（幂等 upsert）
 */
class Upgrade implements UpgradeInterface
{
    private const SEED_TYPES = [
        ['retail', '零售客户', 10, 1],
        ['b2b_wholesale', 'B2B批发客户', 20, 1],
        ['ai_acquisition', 'AI获客客户', 30, 1],
    ];

    public function setup(Setup $setup, Context $context): void
    {
        $this->ensureCustomerTypeColumn();
        $this->seedCustomerTypes();
    }

    /**
     * 幂等加列：列已存在则跳过（本地已手工加列，重复执行不报错）。
     */
    private function ensureCustomerTypeColumn(): void
    {
        /** @var Customer $customer */
        $customer = ObjectManager::getInstance(Customer::class);
        $connection = ConnectionFactory::getInstance();
        $dbSetup = (new DbSetup())->setConnection($connection);
        if ($dbSetup->columnExist($customer->getTable(), Customer::schema_fields_customer_type)) {
            return;
        }

        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($customer);
        // addColumn(field, after, type, length, options, comment)
        $modelSetup->alterTable()->addColumn(
            Customer::schema_fields_customer_type,
            Customer::schema_fields_is_anonymous,
            'varchar',
            32,
            "NOT NULL DEFAULT 'retail'",
            '客户类型代码(customer_type注册表)'
        );

        // 需工程确认：Customer.php 无类级 #[Index]，框架对存量表索引支持未验证，索引暂不加。
    }

    /**
     * 种子幂等：code 存在即跳过。
     */
    private function seedCustomerTypes(): void
    {
        /** @var Type $probe */
        $probe = ObjectManager::getInstance(Type::class);

        foreach (self::SEED_TYPES as [$code, $name, $sort, $isSystem]) {
            $existing = clone $probe;
            $row = $existing->clearData()->reset()
                ->where(Type::schema_fields_CODE, $code)
                ->find()
                ->fetchArray();
            if (is_array($row) && $row !== []) {
                continue;
            }

            /** @var Type $type */
            $type = clone $probe;
            $type->clearData()->reset()->setData([
                Type::schema_fields_CODE => $code,
                Type::schema_fields_NAME => $name,
                Type::schema_fields_SORT => $sort,
                Type::schema_fields_IS_SYSTEM => $isSystem,
            ])->save();
        }
    }
}
