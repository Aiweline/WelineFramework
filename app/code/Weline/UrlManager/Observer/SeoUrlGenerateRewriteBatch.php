<?php

declare(strict_types=1);

namespace Weline\UrlManager\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

final class SeoUrlGenerateRewriteBatch implements ObserverInterface
{
    public function __construct(private SeoUrlGenerateRewrite $rewrite)
    {
    }

    public function execute(Event &$event): void
    {
        $this->rewrite->prefetch($event->getData('data'));
    }
}
