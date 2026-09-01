<?php

declare(strict_types=1);

namespace Weline\Maintenance\Observer;

use Weline\Framework\Api\Event\AsyncObserverInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Event\Async\Exception\NonRetryableAsyncEventException;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Maintenance\Service\MaintenanceContactEmailResolver;
use Weline\Maintenance\Service\MaintenanceStaticGenerator;
use Weline\Maintenance\Service\UpgradeWaveService;
use Weline\Maintenance\Service\WaitGiftCampaignSyncService;

/**
 * Regenerates maintenance static pages when developer email is saved via unified config center.
 */
final class MaintenanceConfigResourceChangedObserver implements AsyncObserverInterface
{
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
                __('Maintenance ResourceChange Observer 只接受 v1 契约'),
            );
        }

        if (!$this->affectsMaintenanceConfig($change)) {
            return;
        }

        try {
            if ($this->affectsWaitGift($change)) {
                $waves = new UpgradeWaveService();
                $env = Env::getInstance();
                $config = [
                    'enabled' => (bool)$env->getConfig('maintenance/wait_gift/enabled', $env->getConfig('maintenance.wait_gift.enabled', false)),
                    'discount_type' => (string)$env->getConfig('maintenance/wait_gift/discount_type', $env->getConfig('maintenance.wait_gift.discount_type', 'fixed_amount')),
                    'discount_value' => (float)$env->getConfig('maintenance/wait_gift/discount_value', $env->getConfig('maintenance.wait_gift.discount_value', 0)),
                    'min_wait_sec' => (int)$env->getConfig('maintenance/wait_gift/min_wait_sec', $env->getConfig('maintenance.wait_gift.min_wait_sec', UpgradeWaveService::MIN_WAIT_SECONDS_DEFAULT)),
                    'marketing_rule_id' => (int)$env->getConfig('maintenance/wait_gift/marketing_rule_id', $env->getConfig('maintenance.wait_gift.marketing_rule_id', 0)),
                ];
                $synced = (new WaitGiftCampaignSyncService())->sync($config);
                if (($synced['rule_id'] ?? 0) > 0) {
                    $env->setConfig('maintenance/wait_gift/marketing_rule_id', (int)$synced['rule_id']);
                }
            }
            $retryAfter = (int)(Env::getInstance()->getConfig('maintenance_retry_after', 60));
            (new MaintenanceStaticGenerator())->publishAll($retryAfter);
        } catch (\Throwable $exception) {
            w_log_error(
                'Maintenance static page publish failed after config change: ' . $exception->getMessage(),
                [],
                'maintenance',
            );
            throw $exception;
        }
    }

    private function affectsMaintenanceConfig(ResourceChange $change): bool
    {
        return $this->affectsMaintenanceContactEmail($change) || $this->affectsWaitGift($change);
    }

    private function affectsWaitGift(ResourceChange $change): bool
    {
        if ($change->resourceType() !== 'system_config') {
            return false;
        }
        $after = $change->toArray()['after'] ?? null;
        if (!is_array($after) || ($after['module'] ?? '') !== MaintenanceContactEmailResolver::MODULE) {
            return false;
        }
        $changedFields = $change->toArray()['changed_fields'] ?? [];
        if (!is_array($changedFields)) {
            return false;
        }
        foreach ($changedFields as $field) {
            $field = (string)$field;
            if (str_starts_with($field, 'maintenance/wait_gift/') || str_starts_with($field, 'maintenance.wait_gift.')) {
                return true;
            }
        }

        return false;
    }

    private function affectsMaintenanceContactEmail(ResourceChange $change): bool
    {
        if ($change->resourceType() !== 'system_config') {
            return false;
        }

        $after = $change->toArray()['after'] ?? null;
        if (!is_array($after) || ($after['module'] ?? '') !== MaintenanceContactEmailResolver::MODULE) {
            return false;
        }

        $changedFields = $change->toArray()['changed_fields'] ?? [];
        return is_array($changedFields)
            && in_array(MaintenanceContactEmailResolver::CONFIG_KEY, $changedFields, true);
    }
}
