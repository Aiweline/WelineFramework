<?php

declare(strict_types=1);

namespace Weline\Order\Api\Data;

/** Group shipping snapshot — one method/address; owner gets 100%. */
final class ShippingSnapshot
{
    /**
     * @param array<string, mixed> $address
     */
    public function __construct(
        public readonly string $method = '',
        public readonly int $amountMinor = 0,
        public readonly ?string $chargeOwnerOrderUuid = null,
        public readonly array $address = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'amount_minor' => $this->amountMinor,
            'charge_owner_order_uuid' => $this->chargeOwnerOrderUuid,
            'address' => $this->address,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $address = $data['address'] ?? [];
        return new self(
            method: (string)($data['method'] ?? ''),
            amountMinor: (int)($data['amount_minor'] ?? 0),
            chargeOwnerOrderUuid: isset($data['charge_owner_order_uuid']) ? (string)$data['charge_owner_order_uuid'] : null,
            address: is_array($address) ? $address : [],
        );
    }
}
