<?php

declare(strict_types=1);

namespace Weline\RssEDemo\Setup;

use Weline\RssEDemo\Model\DemoItem;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\InstallInterface;

class Install implements InstallInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        $existing = ObjectManager::getInstance(DemoItem::class);
        $row = (clone $existing)->clearData()->reset()
            ->where(DemoItem::schema_fields_NAME, 'rsse-demo-alpha')
            ->find()->fetch();
        if (!$row->getId()) {
            $item = clone $existing;
            $item->clearData()->reset();
            $item->setName('rsse-demo-alpha');
            $item->save();
        }
    }
}
