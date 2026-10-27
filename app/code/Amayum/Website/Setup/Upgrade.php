<?php

declare(strict_types=1);

namespace Amayum\Website\Setup;

use Amayum\Website\Model\Inquiry;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;

final class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $modelSetup = ObjectManager::make(ModelSetup::class);
        /** @var Inquiry $inquiry */
        $inquiry = ObjectManager::getInstance(Inquiry::class);
        $modelSetup->putModel($inquiry);
    }
}
