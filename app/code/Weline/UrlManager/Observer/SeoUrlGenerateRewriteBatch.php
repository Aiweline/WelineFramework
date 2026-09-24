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
        $urls = $event->getData('data');
        if (!\is_array($urls) || $urls === []) {
            return;
        }
        /** @var array<array-key, string> $urls */
        $this->rewrite->prefetch($urls);
    }
}
