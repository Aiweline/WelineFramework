<?php

declare(strict_types=1);

namespace WeShop\Analytics\Observer;

use WeShop\Analytics\Service\PixelDispatcher;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class AffiliateLifecyclePixel implements ObserverInterface
{
    public function __construct(
        private readonly PixelDispatcher $pixelDispatcher
    ) {
    }

    public function execute(Event &$event): void
    {
        $eventName = strtolower(str_replace(['WeShop_Affiliate::', '-'], ['affiliate_', '_'], $event->getName()));
        $data = $event->getData();
        $data = is_array($data) ? $data : [];

        $this->pixelDispatcher->dispatch($eventName, $this->normalizePayload($data));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $result = [];
        foreach ([
            'share_code',
            'platform',
            'event_type',
            'order_id',
            'customer_id',
            'affiliate_id',
            'product_id',
            'status',
            'old_status',
            'new_status',
            'reason',
            'target_url',
        ] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $result[$key] = $payload[$key];
            }
        }

        foreach (['commission', 'touch', 'share', 'attribution'] as $objectKey) {
            $object = $payload[$objectKey] ?? null;
            if (!is_object($object) || !method_exists($object, 'getData')) {
                continue;
            }
            foreach (['affiliate_id', 'share_id', 'product_id', 'order_id', 'commission_amount', 'value'] as $field) {
                $value = $object->getData($field);
                if (is_scalar($value) && !array_key_exists($field, $result)) {
                    $result[$field] = $value;
                }
            }
        }

        if (isset($result['commission_amount']) && is_numeric($result['commission_amount'])) {
            $result['value'] = (float) $result['commission_amount'];
        }

        return $result;
    }
}
