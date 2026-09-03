<?php

declare(strict_types=1);

namespace Weline\Frontend\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Frontend\Service\Head\HeadProviderRegistry;

/**
 * Bridges the shared Frontend head-provider registry into Weline_Seo's integration event.
 */
final class SeoHeadContextResolve implements ObserverInterface
{
    public function __construct(
        private readonly HeadProviderRegistry $headProviderRegistry,
    ) {
    }

    public function execute(Event &$event): void
    {
        $template = $event->getData('template');
        $context = $event->getData('context');
        $context = is_array($context) ? $context : [];

        $headContext = $event->getData('head_context');
        $headContext = is_array($headContext) ? $headContext : [];

        try {
            $providers = $this->headProviderRegistry->getContextProviders();
        } catch (\Throwable) {
            return;
        }

        foreach ($providers as $provider) {
            try {
                $provided = $provider->provide(
                    $template,
                    array_replace_recursive($context, $headContext),
                );
            } catch (\Throwable) {
                continue;
            }

            if ($provided !== []) {
                $headContext = array_replace_recursive($headContext, $provided);
            }
        }

        $event->setData('head_context', $headContext);
    }
}
