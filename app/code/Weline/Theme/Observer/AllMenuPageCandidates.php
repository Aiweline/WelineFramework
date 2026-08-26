<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\AllMenu\PageCandidateService;

/**
 * Seeds all-menu page candidates with Theme Router shell pages (+ CMS when present).
 */
class AllMenuPageCandidates implements ObserverInterface
{
    public function __construct(
        private readonly PageCandidateService $pages = new PageCandidateService(),
    ) {
    }

    public function execute(Event &$event): void
    {
        $existing = $event->getData('candidates');
        if (!is_array($existing) || $existing === []) {
            $event->setData('candidates', $this->pages->collectPageCandidates());
        }
    }
}
