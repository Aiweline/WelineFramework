<?php

declare(strict_types=1);

namespace WeShop\B2B\Service;

use WeShop\B2B\Model\ApprovalRule;
use WeShop\B2B\Model\Receivable;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class RiskControlService
{
    public function __construct(
        private readonly CreditService $creditService,
        private readonly ?EventsManager $eventsManager = null
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function evaluateCustomerWarnings(int $customerId): array
    {
        $warnings = [];
        $summary = $this->creditService->getCreditSummary($customerId);
        if (($summary['has_credit'] ?? false) !== true) {
            return $warnings;
        }

        $limit = (float) ($summary['credit_limit'] ?? 0);
        $used = (float) ($summary['used_credit'] ?? 0);
        if ($limit <= 0) {
            return $warnings;
        }

        $ratio = $used / $limit;
        $threshold = $this->resolveCreditUsageThreshold($customerId);
        if ($ratio >= $threshold) {
            $warnings[] = (string) __('B2B credit usage is at or above %{1}%.', [number_format($threshold * 100, 0)]);
            $this->dispatch('WeShop_B2B::credit_usage_warning', [
                'customer_id' => $customerId,
                'ratio' => $ratio,
                'threshold' => $threshold,
            ]);
        }

        return $warnings;
    }

    public function freezeIfSeverelyOverdue(int $customerId, int $minOverdueDays = 30): bool
    {
        /** @var Receivable $model */
        $model = ObjectManager::getInstance(Receivable::class);
        $model->clear()
            ->where(Receivable::schema_fields_CUSTOMER_ID, $customerId)
            ->where(Receivable::schema_fields_STATUS, ReceivableService::STATUS_OVERDUE);

        $rows = $model->select()->fetchArray() ?: [];
        $maxDays = 0;
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $maxDays = max($maxDays, (int) ($row[Receivable::schema_fields_OVERDUE_DAYS] ?? 0));
        }

        if ($maxDays < $minOverdueDays) {
            return false;
        }

        $this->creditService->freeze($customerId);
        $this->dispatch('WeShop_B2B::account_frozen_overdue', [
            'customer_id' => $customerId,
            'overdue_days' => $maxDays,
        ]);

        return true;
    }

    private function resolveCreditUsageThreshold(int $customerId): float
    {
        /** @var ApprovalRule $ruleModel */
        $ruleModel = ObjectManager::getInstance(ApprovalRule::class);
        $ruleModel->clear()
            ->where(ApprovalRule::schema_fields_CUSTOMER_ID, $customerId)
            ->where(ApprovalRule::schema_fields_RULE_CODE, 'credit_usage_warn')
            ->where(ApprovalRule::schema_fields_IS_ACTIVE, 1)
            ->limit(1);
        $rows = $ruleModel->select()->fetchArray();
        if (\is_array($rows) && $rows !== [] && \is_array($rows[0])) {
            $ratio = (float) ($rows[0][ApprovalRule::schema_fields_CREDIT_USAGE_RATIO] ?? 0.8);
            if ($ratio > 0 && $ratio <= 1) {
                return $ratio;
            }
        }

        return 0.8;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatch(string $event, array $payload): void
    {
        $manager = $this->eventsManager ?? ObjectManager::getInstance(EventsManager::class);
        $manager->dispatch($event, $payload);
    }
}
