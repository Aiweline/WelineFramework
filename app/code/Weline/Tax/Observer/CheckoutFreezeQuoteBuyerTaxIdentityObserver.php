<?php

declare(strict_types=1);

namespace Weline\Tax\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Tax\Service\BuyerTaxIdentityService;

/**
 * freeze_quote::enrich — Tax owns buyer tax identity in payload + TaxSnapshot buyer_* keys.
 */
final class CheckoutFreezeQuoteBuyerTaxIdentityObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $payload = $event->getData('payload');
        if (!\is_array($payload)) {
            return;
        }

        /** @var BuyerTaxIdentityService $service */
        $service = ObjectManager::getInstance(BuyerTaxIdentityService::class);
        $incoming = BuyerTaxIdentityService::extractFromBag([
            BuyerTaxIdentityService::PAYLOAD_KEY => $event->getData(BuyerTaxIdentityService::PAYLOAD_KEY),
            BuyerTaxIdentityService::LEGACY_PAYLOAD_KEY => $event->getData(BuyerTaxIdentityService::LEGACY_PAYLOAD_KEY),
        ]);
        if ($incoming === []) {
            $incoming = BuyerTaxIdentityService::extractFromBag($payload);
        }

        $billing = \is_array($payload['billing_address'] ?? null) ? $payload['billing_address'] : [];
        if ($billing === [] && \is_array($payload['address'] ?? null)) {
            $billing = $payload['address'];
        }

        $prev = BuyerTaxIdentityService::extractFromBag($payload);
        $prevCountry = strtoupper(trim((string)($prev['country_code'] ?? $payload['tax']['buyer_tax_country'] ?? '')));
        $country = strtoupper(trim((string)($billing['country_code'] ?? $billing['country'] ?? '')));
        if ($prevCountry !== '' && $country !== '' && $prevCountry !== $country) {
            $incoming = [];
        }

        $desc = $service->describeForAddress($billing, $incoming, false);
        if ($desc['errors'] !== []) {
            throw new \InvalidArgumentException((string)($desc['errors'][0] ?? 'buyer_tax_vat_invalid'));
        }

        if (!$desc['visible']) {
            unset(
                $payload[BuyerTaxIdentityService::PAYLOAD_KEY],
                $payload[BuyerTaxIdentityService::LEGACY_PAYLOAD_KEY],
            );
            if (\is_array($payload['tax'] ?? null)) {
                $payload['tax'] = $service->mergeIntoTaxSnapshot($payload['tax'], []);
            }
            $event->setData('payload', $payload);

            return;
        }

        $normalized = $service->normalize($incoming);
        if ($normalized !== []) {
            $normalized['country_code'] = $country;
            $payload[BuyerTaxIdentityService::PAYLOAD_KEY] = $normalized;
            // Keep legacy key in sync for older clients.
            $payload[BuyerTaxIdentityService::LEGACY_PAYLOAD_KEY] = [
                'vat_id' => (string)($normalized['tax_id'] ?? ''),
                'kind' => (string)($normalized['tax_id_type'] ?? ''),
                'country_code' => $country,
            ];
            $tax = \is_array($payload['tax'] ?? null) ? $payload['tax'] : [];
            $payload['tax'] = $service->mergeIntoTaxSnapshot($tax, $normalized);
        } else {
            unset(
                $payload[BuyerTaxIdentityService::PAYLOAD_KEY],
                $payload[BuyerTaxIdentityService::LEGACY_PAYLOAD_KEY],
            );
            if (\is_array($payload['tax'] ?? null)) {
                $payload['tax'] = $service->mergeIntoTaxSnapshot($payload['tax'], []);
            }
        }

        $event->setData('payload', $payload);
    }
}
