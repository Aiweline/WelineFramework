<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Observer;

use Weline\DeveloperWorkspace\Service\Document\DocumentUniqueKeyHealer;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * Runs document unique-key healing before SchemaDiff DDL (UNIQUE indexes).
 * Failures must propagate — do not swallow.
 */
class BeforeSchemaDiffCommitObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var Printing $printing */
        $printing = ObjectManager::getInstance(Printing::class);
        $printing->note(__('Weline_DeveloperWorkspace: before_schema_diff_commit — DocumentUniqueKeyHealer'));

        /** @var DocumentUniqueKeyHealer $healer */
        $healer = ObjectManager::getInstance(DocumentUniqueKeyHealer::class);
        $healer->healAll($printing);
    }
}
