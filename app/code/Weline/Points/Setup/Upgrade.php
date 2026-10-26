<?php

declare(strict_types=1);

namespace Weline\Points\Setup;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Db\Setup as DbSetup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Points\Model\CustomerState;
use Weline\Points\Model\EarnRecord;
use Weline\Points\Service\PointsProductAttributeInstaller;

class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $this->ensureEarnRecordColumns();
        $this->ensureCustomerStateLifetimePoints();
        try {
            ObjectManager::getInstance(PointsProductAttributeInstaller::class)->ensureAttributes();
        } catch (\Throwable) {
            // The EAV/product schema may not be ready during a partial install.
        }
    }

    private function ensureEarnRecordColumns(): void
    {
        /** @var EarnRecord $model */
        $model = ObjectManager::getInstance(EarnRecord::class);
        $dbSetup = (new DbSetup())->setConnection(ConnectionFactory::getInstance());
        $columns = [
            [EarnRecord::schema_fields_CHECKOUT_CURRENCY, EarnRecord::schema_fields_CURRENCY, 'varchar', 10, "NOT NULL DEFAULT ''", 'Checkout currency'],
            [EarnRecord::schema_fields_CHECKOUT_CASH_MINOR, EarnRecord::schema_fields_CASH_MINOR, 'bigint', 20, 'NOT NULL DEFAULT 0', 'Cash paid in checkout currency minor units'],
            [EarnRecord::schema_fields_BASE_CURRENCY, EarnRecord::schema_fields_CHECKOUT_CASH_MINOR, 'varchar', 10, "NOT NULL DEFAULT ''", 'Website base currency'],
            [EarnRecord::schema_fields_CASH_BASE_MINOR, EarnRecord::schema_fields_BASE_CURRENCY, 'bigint', 20, 'NOT NULL DEFAULT 0', 'Cash paid in base currency minor units'],
            [EarnRecord::schema_fields_ITEMS_SNAPSHOT_JSON, EarnRecord::schema_fields_CASH_BASE_MINOR, 'text', null, "NOT NULL DEFAULT '[]'", 'Per-line earning snapshot JSON'],
        ];
        foreach ($columns as [$field, $after, $type, $length, $options, $comment]) {
            if ($dbSetup->columnExist($model->getTable(), $field)) {
                continue;
            }
            $modelSetup = ObjectManager::make(ModelSetup::class);
            $modelSetup->putModel($model);
            $modelSetup->alterTable()->addColumn($field, $after, $type, $length, $options, $comment);
        }
    }

    private function ensureCustomerStateLifetimePoints(): void
    {
        /** @var CustomerState $model */
        $model = ObjectManager::getInstance(CustomerState::class);
        $dbSetup = (new DbSetup())->setConnection(ConnectionFactory::getInstance());
        if ($dbSetup->columnExist($model->getTable(), CustomerState::schema_fields_LIFETIME_POINTS)) {
            return;
        }

        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($model);
        $modelSetup->alterTable()->addColumn(
            CustomerState::schema_fields_LIFETIME_POINTS,
            CustomerState::schema_fields_GROWTH_VALUE,
            'bigint',
            20,
            'NOT NULL DEFAULT 0',
            'Lifetime earned points; never reduced by redemption',
        );
    }
}
