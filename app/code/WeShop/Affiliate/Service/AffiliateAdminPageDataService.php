<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Service;

use WeShop\Affiliate\Model\Affiliate;
use Weline\Framework\Manager\ObjectManager;

class AffiliateAdminPageDataService
{
    public function __construct(
        private readonly AffiliateService $affiliateService
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function getPageData(int $page = 1, int $pageSize = 20, array $filters = [], int $editingId = 0): array
    {
        $sanitizedFilters = $this->sanitizeFilters($filters);
        $result = $this->affiliateService->getAffiliateList($page, $pageSize, $sanitizedFilters);
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        $pagination = $this->normalizePagination($result, count($items), $page, $pageSize);
        $editingRecord = $editingId ? $this->affiliateService->getAffiliateRecord($editingId) : null;
        $statusOptions = $this->affiliateService->getStatusOptions();
        $editingRecordData = $editingRecord ? $this->normalizeModel($editingRecord, $statusOptions) : $this->getEmptyRecord($statusOptions);
        $summary = $this->getSummary();

        return [
            'affiliateRecords' => array_map(fn (array $record): array => $this->normalizeRecord($record, $statusOptions), $items),
            'filters' => $sanitizedFilters,
            'pagination' => $pagination,
            'statusOptions' => $statusOptions,
            'summary' => $summary,
            'editingRecord' => $editingRecordData,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        /** @var Affiliate $affiliate */
        $affiliate = clone ObjectManager::getInstance(Affiliate::class);

        $totalResult = $affiliate->clear()->select()->fetchArray();
        $total = is_array($totalResult) ? count($totalResult) : 0;

        $activeResult = $affiliate->clear()
            ->where(Affiliate::schema_fields_STATUS, AffiliateService::STATUS_ACTIVE)
            ->select()
            ->fetchArray();
        $active = is_array($activeResult) ? count($activeResult) : 0;

        $disabledResult = $affiliate->clear()
            ->where(Affiliate::schema_fields_STATUS, AffiliateService::STATUS_DISABLED)
            ->select()
            ->fetchArray();
        $disabled = is_array($disabledResult) ? count($disabledResult) : 0;

        $commissionResult = $affiliate->clear()
            ->select('SUM(' . Affiliate::schema_fields_TOTAL_COMMISSION . ') as total_commission')
            ->fetchArray();
        $totalCommission = 0.0;
        if (is_array($commissionResult) && isset($commissionResult[0]['total_commission'])) {
            $totalCommission = (float) $commissionResult[0]['total_commission'];
        }

        return [
            'total' => $total,
            'active' => $active,
            'disabled' => $disabled,
            'total_commission' => $totalCommission,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function sanitizeFilters(array $filters): array
    {
        $sanitized = [];

        if (!empty($filters['customer_id'])) {
            $sanitized['customer_id'] = max(0, (int) $filters['customer_id']);
        }

        if (!empty($filters['referral_code'])) {
            $sanitized['referral_code'] = trim((string) $filters['referral_code']);
        }

        if (!empty($filters['status']) && $this->affiliateService->isValidStatus((string) $filters['status'])) {
            $sanitized['status'] = (string) $filters['status'];
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function normalizePagination(array $result, int $visibleCount, int $page, int $pageSize): array
    {
        $pagination = is_array($result['pagination'] ?? null) ? $result['pagination'] : [];
        $total = max(
            0,
            (int) ($result['total'] ?? 0),
            (int) ($pagination['total'] ?? 0),
            $visibleCount
        );
        $pageSize = max(1, $pageSize);

        $pagination['page'] = max(1, (int) ($pagination['page'] ?? $page));
        $pagination['total'] = $total;
        $pagination['page_count'] = max(
            1,
            (int) ($pagination['page_count'] ?? 0),
            (int) ceil($total / $pageSize)
        );

        return $pagination;
    }

    private function normalizeModel(Affiliate $affiliate, array $statusOptions): array
    {
        $status = (string) ($affiliate->getData(Affiliate::schema_fields_STATUS) ?? '');
        $referralCode = (string) ($affiliate->getData(Affiliate::schema_fields_REFERRAL_CODE) ?? '');
        $referralLink = $this->affiliateService->getReferralLink($referralCode);

        $totalCommission = (float) ($affiliate->getData(Affiliate::schema_fields_TOTAL_COMMISSION) ?? 0.0);
        $paidCommission = (float) ($affiliate->getData(Affiliate::schema_fields_PAID_COMMISSION) ?? 0.0);
        $pendingCommission = max(0.0, round($totalCommission - $paidCommission, 2));

        return [
            'affiliate_id' => (int) $affiliate->getId(),
            'customer_id' => (int) ($affiliate->getData(Affiliate::schema_fields_CUSTOMER_ID) ?? 0),
            'referral_code' => $referralCode,
            'referral_link' => $referralLink,
            'commission_rate' => (float) ($affiliate->getData(Affiliate::schema_fields_COMMISSION_RATE) ?? 0.0),
            'total_commission' => $totalCommission,
            'paid_commission' => $paidCommission,
            'pending_commission' => $pendingCommission,
            'status' => $status,
            'status_label' => $statusOptions[$status] ?? $status,
            'created_at' => (string) ($affiliate->getData(Affiliate::schema_fields_CREATED_AT) ?? ''),
            'updated_at' => (string) ($affiliate->getData(Affiliate::schema_fields_UPDATED_AT) ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function normalizeRecord(array $record, array $statusOptions): array
    {
        $status = (string) ($record[Affiliate::schema_fields_STATUS] ?? $record['status'] ?? AffiliateService::STATUS_DISABLED);

        $totalCommission = (float) ($record[Affiliate::schema_fields_TOTAL_COMMISSION] ?? $record['total_commission'] ?? 0.0);
        $paidCommission = (float) ($record[Affiliate::schema_fields_PAID_COMMISSION] ?? $record['paid_commission'] ?? 0.0);
        $pendingCommission = max(0.0, round($totalCommission - $paidCommission, 2));

        return [
            'affiliate_id' => (int) ($record[Affiliate::schema_fields_ID] ?? $record['affiliate_id'] ?? 0),
            'customer_id' => (int) ($record[Affiliate::schema_fields_CUSTOMER_ID] ?? $record['customer_id'] ?? 0),
            'referral_code' => (string) ($record[Affiliate::schema_fields_REFERRAL_CODE] ?? $record['referral_code'] ?? ''),
            'commission_rate' => (float) ($record[Affiliate::schema_fields_COMMISSION_RATE] ?? $record['commission_rate'] ?? 0.0),
            'total_commission' => $totalCommission,
            'paid_commission' => $paidCommission,
            'pending_commission' => $pendingCommission,
            'status' => $status,
            'status_label' => $statusOptions[$status] ?? $status,
            'created_at' => (string) ($record[Affiliate::schema_fields_CREATED_AT] ?? $record['created_at'] ?? ''),
            'updated_at' => (string) ($record[Affiliate::schema_fields_UPDATED_AT] ?? $record['updated_at'] ?? ''),
        ];
    }

    private function getEmptyRecord(array $statusOptions): array
    {
        $status = AffiliateService::STATUS_ACTIVE;

        return [
            'affiliate_id' => 0,
            'customer_id' => '',
            'referral_code' => '',
            'commission_rate' => 0.10,
            'total_commission' => 0.0,
            'paid_commission' => 0.0,
            'pending_commission' => 0.0,
            'status' => $status,
            'status_label' => $statusOptions[$status] ?? $status,
        ];
    }
}
