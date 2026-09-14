<?php

declare(strict_types=1);

namespace Weline\Shipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\ShippingCommercePolicy;

/**
 * Resolve website commerce policy (return + split shipment).
 */
final class ShippingCommercePolicyService
{
    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    /**
     * @param array{website_id?:int,scope_type?:string,scope_id?:int}|null $context
     * @return array{return_policy:string,split_shipment_shipping:string}
     */
    public function resolve(?array $context = null): array
    {
        $scopeType = (string)($context['scope_type'] ?? ShippingCommercePolicy::SCOPE_WEBSITE);
        $scopeId = (int)($context['scope_id'] ?? $context['website_id'] ?? 0);
        $row = $this->load($scopeType, $scopeId);
        if ($row === null && !($scopeType === ShippingCommercePolicy::SCOPE_WEBSITE && $scopeId === 0)) {
            $row = $this->load(ShippingCommercePolicy::SCOPE_WEBSITE, 0);
        }

        return [
            'return_policy' => $row !== null
                ? $this->normalizeReturn((string)$row->getData(ShippingCommercePolicy::schema_fields_RETURN_POLICY))
                : ShippingCommercePolicy::RETURN_BUYER,
            'split_shipment_shipping' => $row !== null
                ? $this->normalizeSplit((string)$row->getData(ShippingCommercePolicy::schema_fields_SPLIT_SHIPMENT_SHIPPING))
                : ShippingCommercePolicy::SPLIT_FIRST_ONLY,
        ];
    }

    public function setPolicies(
        string $returnPolicy,
        string $splitShipment,
        string $scopeType = ShippingCommercePolicy::SCOPE_WEBSITE,
        int $scopeId = 0,
    ): void {
        $payload = [
            ShippingCommercePolicy::schema_fields_POLICY_CODE => ShippingCommercePolicy::SEED_CODE,
            ShippingCommercePolicy::schema_fields_RETURN_POLICY => $this->normalizeReturn($returnPolicy),
            ShippingCommercePolicy::schema_fields_SPLIT_SHIPMENT_SHIPPING => $this->normalizeSplit($splitShipment),
            ShippingCommercePolicy::schema_fields_IS_ACTIVE => 1,
        ];
        $existing = $this->load($scopeType, $scopeId);
        if ($existing !== null) {
            $existing->setData($payload)->save();

            return;
        }
        /** @var ShippingCommercePolicy $create */
        $create = $this->objectManager->getInstance(ShippingCommercePolicy::class, [], false);
        $create->setData(array_merge($payload, [
            ShippingCommercePolicy::schema_fields_SCOPE_TYPE => $scopeType,
            ShippingCommercePolicy::schema_fields_SCOPE_ID => $scopeId,
        ]))->save();
    }

    private function load(string $scopeType, int $scopeId): ?ShippingCommercePolicy
    {
        /** @var ShippingCommercePolicy $model */
        $model = $this->objectManager->getInstance(ShippingCommercePolicy::class, [], false);
        $items = $model->reset()
            ->where(ShippingCommercePolicy::schema_fields_SCOPE_TYPE, $scopeType)
            ->where(ShippingCommercePolicy::schema_fields_SCOPE_ID, $scopeId)
            ->where(ShippingCommercePolicy::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetch()
            ->getItems();
        $row = is_array($items) ? ($items[0] ?? null) : null;

        return $row instanceof ShippingCommercePolicy && (int)$row->getId() > 0 ? $row : null;
    }

    private function normalizeReturn(string $raw): string
    {
        $v = strtolower(trim($raw));

        return match ($v) {
            ShippingCommercePolicy::RETURN_SELLER,
            ShippingCommercePolicy::RETURN_SPLIT_50 => $v,
            default => ShippingCommercePolicy::RETURN_BUYER,
        };
    }

    private function normalizeSplit(string $raw): string
    {
        $v = strtolower(trim($raw));

        return $v === ShippingCommercePolicy::SPLIT_EACH
            ? ShippingCommercePolicy::SPLIT_EACH
            : ShippingCommercePolicy::SPLIT_FIRST_ONLY;
    }
}
