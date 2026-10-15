<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Product\Api\OrderDigitalDeliveryProjectionInterface;
use Weline\Product\Api\ProductDownloadEntitlementInterface;

/**
 * Projects DownloadEntitlement rows for a paid order into generic delivery CTAs.
 */
final class OrderDigitalDeliveryProjectionService implements OrderDigitalDeliveryProjectionInterface
{
    public const SCHEMA_VERSION = 'order-digital-delivery-projection.v1';

    public function __construct(
        private readonly ?ProductDownloadEntitlementInterface $entitlements = null,
        private readonly ?OrderFacadeInterface $orders = null,
    ) {
    }

    public function project(
        string $orderUuid,
        ?int $viewerCustomerId = null,
        bool $accessCapability = false,
    ): array {
        $orderUuid = trim($orderUuid);
        $empty = [
            'schema_version' => self::SCHEMA_VERSION,
            'order_uuid' => $orderUuid,
            'entries' => [],
        ];
        if ($orderUuid === '' || preg_match(
            '/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',
            $orderUuid,
        ) !== 1) {
            return $empty;
        }

        try {
            $order = $this->orders()->get($orderUuid);
        } catch (\Throwable) {
            return $empty;
        }

        $ownerId = $order->customerId !== null && (int)$order->customerId > 0
            ? (int)$order->customerId
            : 0;
        $viewer = $viewerCustomerId !== null && $viewerCustomerId > 0 ? $viewerCustomerId : 0;
        if ($ownerId > 0) {
            if ($viewer !== $ownerId && !$accessCapability) {
                return $empty;
            }
        } elseif (!$accessCapability) {
            // Guest digital packs are out of scope for this projection contract.
            return $empty;
        }

        $status = strtolower(trim((string)($order->status ?? '')));
        if (!in_array($status, ['paid', 'fulfilled', 'completed'], true)) {
            return $empty;
        }

        try {
            $grants = $this->entitlements()->grantForPaidOrder($orderUuid);
        } catch (\Throwable) {
            return $empty;
        }

        $nameByOffer = [];
        foreach ((array)($order->items ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $digital = $item['fulfillment_metadata']['digital_download'] ?? null;
            if (!is_array($digital)) {
                continue;
            }
            $offerUuid = trim((string)($digital['global_offer_uuid'] ?? ''));
            $name = trim((string)($item['name'] ?? $item['product_name'] ?? ''));
            if ($offerUuid !== '' && $name !== '') {
                $nameByOffer[$offerUuid] = $name;
            }
        }

        $entries = [];
        $seen = [];
        foreach ($grants as $grant) {
            if (!is_array($grant)) {
                continue;
            }
            $uuid = trim((string)($grant['entitlement_uuid'] ?? ''));
            if ($uuid === '' || isset($seen[$uuid])) {
                continue;
            }
            if (preg_match(
                '/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',
                $uuid,
            ) !== 1) {
                continue;
            }
            $seen[$uuid] = true;
            $offerUuid = trim((string)($grant['global_offer_uuid'] ?? ''));
            $label = trim((string)($grant['name'] ?? ''));
            $productName = $nameByOffer[$offerUuid] ?? $label;
            if ($label === '') {
                $label = $productName !== '' ? $productName : (string)__('下载交付');
            }
            $entries[] = [
                'entitlement_uuid' => $uuid,
                'label' => $label,
                'href' => '/product-download/' . rawurlencode($uuid),
                'kind' => 'file_download',
                'product_name' => $productName,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'order_uuid' => $orderUuid,
            'entries' => $entries,
        ];
    }

    private function entitlements(): ProductDownloadEntitlementInterface
    {
        if ($this->entitlements instanceof ProductDownloadEntitlementInterface) {
            return $this->entitlements;
        }
        $candidate = ObjectManager::getInstance(ProductDownloadEntitlementInterface::class);
        if (!$candidate instanceof ProductDownloadEntitlementInterface) {
            throw new \RuntimeException('product_download_entitlement_unavailable');
        }

        return $candidate;
    }

    private function orders(): OrderFacadeInterface
    {
        if ($this->orders instanceof OrderFacadeInterface) {
            return $this->orders;
        }
        $candidate = ObjectManager::getInstance(OrderFacadeInterface::class);
        if (!$candidate instanceof OrderFacadeInterface) {
            throw new \RuntimeException('order_facade_unavailable');
        }

        return $candidate;
    }
}
