<?php

declare(strict_types=1);

namespace Weline\Tax\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Tax\Service\BuyerTaxIdentityService;

/**
 * Validate / normalize buyer tax identity on checkout validate::before.
 */
class CheckoutBuyerTaxIdentityObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!\is_array($data)) {
            return;
        }
        /** @var BuyerTaxIdentityService $service */
        $service = ObjectManager::getInstance(BuyerTaxIdentityService::class);
        $identity = BuyerTaxIdentityService::extractFromBag($data);
        if ($identity === [] && isset($data['data']) && \is_array($data['data'])) {
            $identity = BuyerTaxIdentityService::extractFromBag($data['data']);
        }
        $address = [];
        foreach (['billing_address', 'shipping_address', 'address'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                $address = $data[$key];
                break;
            }
            if (isset($data['data'][$key]) && \is_array($data['data'][$key])) {
                $address = $data['data'][$key];
                break;
            }
        }
        $desc = $service->describeForAddress($address, $identity, false);
        if ($desc['errors'] !== []) {
            $data['can_continue'] = false;
            $data['errors'] = array_merge(
                \is_array($data['errors'] ?? null) ? $data['errors'] : [],
                $desc['errors'],
            );
            $data['_buyer_tax_errors'] = $desc['errors'];
            if (isset($data['data']) && \is_array($data['data'])) {
                $data['data']['_buyer_tax_errors'] = $desc['errors'];
            }
            $event->setData($data);
        }
        $normalized = $service->normalize($identity);
        if ($normalized !== []) {
            $data[BuyerTaxIdentityService::PAYLOAD_KEY] = $normalized;
            $data[BuyerTaxIdentityService::LEGACY_PAYLOAD_KEY] = [
                'vat_id' => (string)($normalized['tax_id'] ?? ''),
                'kind' => (string)($normalized['tax_id_type'] ?? ''),
            ];
            if (isset($data['data']) && \is_array($data['data'])) {
                $data['data'][BuyerTaxIdentityService::PAYLOAD_KEY] = $normalized;
            }
            $event->setData($data);
        }
    }
}
