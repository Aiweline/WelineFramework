<?php

declare(strict_types=1);

namespace Weline\Faq\Setup;

use Weline\Faq\Model\FaqItem;
use Weline\Faq\Service\FaqItemScopeKeyHealer;
use Weline\Faq\Service\FaqService;
use Weline\Faq\Service\FaqTemplateSeedService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\UpgradeInterface;

final class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        ObjectManager::getInstance(FaqItemScopeKeyHealer::class)->heal();

        $modelSetup = ObjectManager::make(ModelSetup::class);
        /** @var FaqItem $item */
        $item = ObjectManager::getInstance(FaqItem::class);
        $modelSetup->putModel($item);
        $item->upgrade($modelSetup, $context);

        ObjectManager::getInstance(FaqItemScopeKeyHealer::class)->heal();

        /** @var Install $install */
        $install = ObjectManager::getInstance(Install::class);
        $install->migrateHelpPathGroupToFaq();
        $install->ensureProductFaqPublished();
        ObjectManager::getInstance(FaqService::class)->seedSiteHubFaqs(0, '');
        $templateSeed = ObjectManager::getInstance(FaqTemplateSeedService::class);
        $templateSeed->migrateEmptyLocaleToZhHans();
        $templateSeed->seedAll();
        $install->rebuildFaqSearchIndex();
    }
}
