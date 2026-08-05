<?php

declare(strict_types=1);

namespace Weline\Order\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Order\Service\OrderWriterGuard;

/**
 * Guards the legacy Checkout writer without making Checkout depend on an
 * internal Order Service.
 */
final class AssertLegacyCheckoutWriter implements ObserverInterface
{
    public function __construct(
        private readonly OrderWriterGuard $guard,
    ) {
    }

    public function execute(Event &$event): void
    {
        $payload = $event->getData();
        $orderData = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $subject = array_key_exists('website_id', $orderData)
            ? 'website:' . (int) $orderData['website_id']
            : '';

        $this->guard->assertLegacyCheckoutWritable($subject);
    }
}
