<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\StorefrontNotFoundStaticGenerator;

/**
 * Regenerate cached storefront 404 HTML after setup:upgrade.
 *
 * Hot-path 404 serving reads pub/errors/storefront-not-found/*.html directly (zero DB).
 */
final class SetupUpgradeAfterPublishNotFoundStatic implements ObserverInterface
{
    public function __construct(
        private readonly StorefrontNotFoundStaticGenerator $generator,
    ) {
    }

    public function execute(Event &$event): void
    {
        try {
            $this->generator->publishAll();
        } catch (\Throwable $e) {
            w_log_warning(
                '[Theme] storefront 404 static publish failed: ' . $e->getMessage(),
                [],
                'theme_not_found_static'
            );
        }
    }
}
