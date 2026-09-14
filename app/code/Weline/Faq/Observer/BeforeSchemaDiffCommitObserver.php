<?php

declare(strict_types=1);

namespace Weline\Faq\Observer;

use Weline\Faq\Service\FaqItemScopeKeyHealer;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

final class BeforeSchemaDiffCommitObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $printing = ObjectManager::getInstance(Printing::class);
        $printing->note(__('Weline_Faq: before_schema_diff_commit — FaqItemScopeKeyHealer'));
        ObjectManager::getInstance(FaqItemScopeKeyHealer::class)->heal($printing);
    }
}
