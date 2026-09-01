<?php

declare(strict_types=1);

namespace Weline\Inquiry\Setup;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;
use Weline\Inquiry\Service\InquiryFormBootstrap;

final class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        ObjectManager::getInstance(InquiryFormBootstrap::class)->ensureSupplierApplication();
    }
}
