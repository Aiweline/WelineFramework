<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Setup;

use Weline\DeveloperWorkspace\Service\Document\DocumentUniqueKeyHealer;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Data\Setup;
use Weline\Framework\Setup\UpgradeInterface;

/**
 * Idempotent post-heal: primary path is before_schema_diff_commit observer.
 */
class Upgrade implements UpgradeInterface
{
    public function setup(Setup $setup, Context $context): void
    {
        /** @var Printing $printing */
        $printing = ObjectManager::getInstance(Printing::class);
        $printing->note(__('Weline_DeveloperWorkspace Upgrade: idempotent DocumentUniqueKeyHealer'));

        /** @var DocumentUniqueKeyHealer $healer */
        $healer = ObjectManager::getInstance(DocumentUniqueKeyHealer::class);
        $healer->healAll($printing);
    }
}
