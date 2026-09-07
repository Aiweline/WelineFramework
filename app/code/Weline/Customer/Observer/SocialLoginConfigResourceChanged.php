<?php

declare(strict_types=1);

namespace Weline\Customer\Observer;

use Weline\Customer\Service\SocialLogin\SocialLoginStorefrontCacheInvalidator;
use Weline\Framework\Api\Event\AsyncObserverInterface;
use Weline\Framework\Event\Async\Exception\NonRetryableAsyncEventException;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ResourceChange\ResourceChange;

/**
 * When SystemConfig social-login keys change, invalidate login-page FPC / hook caches.
 *
 * Mirrors Theme/Currency observers on `Weline_Framework::resource_changed`.
 * Local FPC/hook cleanup runs here; CDN remains with dedicated receivers.
 */
final class SocialLoginConfigResourceChanged implements AsyncObserverInterface
{
    public function __construct(
        private readonly SocialLoginStorefrontCacheInvalidator $invalidator,
    ) {
    }

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
                __('Customer ResourceChange Observer 只接受 v1 契约'),
            );
        }
        if (!$this->affectsSocialLoginConfig($change)) {
            return;
        }

        $this->invalidator->clearForSocialLoginConfig(
            'resource_change:system_config:social_login'
        );
    }

    private function affectsSocialLoginConfig(ResourceChange $change): bool
    {
        if ($change->resourceType() !== 'system_config') {
            return false;
        }
        $payload = $change->toArray();
        $after = $payload['after'] ?? null;
        if (!is_array($after) || ($after['module'] ?? '') !== 'Weline_Customer') {
            return false;
        }
        $changedFields = $payload['changed_fields'] ?? [];
        if (!is_array($changedFields)) {
            return false;
        }
        foreach ($changedFields as $field) {
            $field = strtolower(trim((string) $field));
            if ($field !== '' && str_starts_with($field, 'customer/social_login/')) {
                return true;
            }
        }

        return false;
    }
}
