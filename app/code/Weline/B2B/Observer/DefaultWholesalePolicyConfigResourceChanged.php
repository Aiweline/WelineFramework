<?php

declare(strict_types=1);

namespace Weline\B2B\Observer;

use Weline\B2B\Service\DefaultWholesalePolicy;
use Weline\Framework\Api\Event\AsyncObserverInterface;
use Weline\Framework\Event\Async\Exception\NonRetryableAsyncEventException;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Manager\ObjectManager;

/**
 * Invalidate storefront/FPC when default wholesale policy config changes.
 */
final class DefaultWholesalePolicyConfigResourceChanged implements AsyncObserverInterface
{
    private const POLICY_KEYS = [
        DefaultWholesalePolicy::KEY_MAX_DISCOUNT_BPS,
        DefaultWholesalePolicy::KEY_MIN_MARGIN_BPS,
        DefaultWholesalePolicy::KEY_TIER_POLICY_JSON,
    ];

    public function supportsAsyncEvent(string $eventName, int $schemaVersion): bool
    {
        return $eventName === ResourceChange::EVENT_NAME
            && $schemaVersion === ResourceChange::SCHEMA_VERSION;
    }

    public function execute(Event &$event): void
    {
        $change = $event->getData('data');
        if (!$change instanceof ResourceChange) {
            throw new NonRetryableAsyncEventException(
                'resource_change_contract_mismatch',
                __('B2B ResourceChange Observer 只接受 v1 契约'),
            );
        }
        if (!$this->affectsDefaultWholesalePolicy($change)) {
            return;
        }

        try {
            if (!class_exists(\Weline\Product\Service\ProductStorefrontCacheInvalidator::class)) {
                return;
            }
            $invalidator = ObjectManager::getInstance(
                \Weline\Product\Service\ProductStorefrontCacheInvalidator::class,
            );
            if (is_object($invalidator) && method_exists($invalidator, 'clearForCatalogChange')) {
                $invalidator->clearForCatalogChange('b2b_default_wholesale_policy');
            }
        } catch (\Throwable) {
            // Optional Product module; never block config write path.
        }
    }

    private function affectsDefaultWholesalePolicy(ResourceChange $change): bool
    {
        if ($change->resourceType() !== 'system_config') {
            return false;
        }
        $payload = $change->toArray();
        $after = $payload['after'] ?? null;
        if (!is_array($after) || ($after['module'] ?? '') !== DefaultWholesalePolicy::CONFIG_MODULE) {
            return false;
        }
        $changedFields = $payload['changed_fields'] ?? [];
        if (!is_array($changedFields)) {
            return false;
        }
        foreach ($changedFields as $field) {
            $field = strtolower(trim((string) $field));
            if ($field === '') {
                continue;
            }
            foreach (self::POLICY_KEYS as $key) {
                if ($field === $key || str_ends_with($field, '/' . $key) || str_contains($field, $key)) {
                    return true;
                }
            }
        }

        return false;
    }
}
