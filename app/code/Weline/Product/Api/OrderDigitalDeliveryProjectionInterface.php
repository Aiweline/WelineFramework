<?php

declare(strict_types=1);

namespace Weline\Product\Api;

/**
 * Read-only projection of paid digital delivery entries for checkout/account UI.
 *
 * Consumers (Checkout success, account order) must not branch on product_type.
 */
interface OrderDigitalDeliveryProjectionInterface
{
    /**
     * @return array{
     *   schema_version: string,
     *   order_uuid: string,
     *   entries: list<array{
     *     entitlement_uuid: string,
     *     label: string,
     *     href: string,
     *     kind: string,
     *     product_name: string
     *   }>
     * }
     */
    public function project(
        string $orderUuid,
        ?int $viewerCustomerId = null,
        bool $accessCapability = false,
    ): array;
}
