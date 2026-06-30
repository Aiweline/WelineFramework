<?php

declare(strict_types=1);

namespace Weline\Payment\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Payment\Model\PaymentMethodAttributeEntity;

class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        /** @var PaymentMethodAttributeEntity $paymentMethodAttributeEntity */
        $paymentMethodAttributeEntity = ObjectManager::getInstance(PaymentMethodAttributeEntity::class);
        $modelSetup = ObjectManager::make(ModelSetup::class);
        $modelSetup->putModel($paymentMethodAttributeEntity);
        $paymentMethodAttributeEntity->setup($modelSetup, $context);
    }
}
