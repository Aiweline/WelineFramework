<?php

declare(strict_types=1);

namespace Weline\Currency\Setup;

use Weline\Currency\Model\Currency;
use Weline\Currency\Model\Currency\LocalDescription;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;

class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        /** @var Currency $currency */
        $currency = ObjectManager::getInstance(Currency::class);

        if (!$setup->getDb()->tableExist($currency->getOriginTableName())
            || !$setup->getDb()->tableExist(LocalDescription::schema_table)
        ) {
            return;
        }

        ObjectManager::getInstance(CurrencyLocalDescriptionSeed::class)->seedDefaults();
    }
}
