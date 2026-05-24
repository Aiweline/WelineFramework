<?php

declare(strict_types=1);

namespace WeShop\Review\Setup;

use WeShop\Review\Service\ReviewRatingOptionService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        ObjectManager::getInstance(ReviewReplySchemaSetup::class)->ensure($setup);
        ObjectManager::getInstance(ReviewRatingOptionService::class)->seedDefaultOptions();
    }
}
