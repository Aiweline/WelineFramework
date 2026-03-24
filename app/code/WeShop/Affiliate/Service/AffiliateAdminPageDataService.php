<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Service;

use WeShop\Affiliate\Model\Affiliate;

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
        $editingRecord = $editingId ? $this->affiliateService->getAffiliateRecord($editingId) : null;
        $statusOptions = $this->affiliateService->getStatusOptions();

        return [
            'affiliateRecords' => array_map(fn (array $record): array => $this->normalizeRecord($record, $statusOptions), $result['items'] ?? []),
            'filters' => $sanitizedFilters,
            'pagination' => $result['pagination'] ?? [],
            'statusOptions' => $statusOptions,
            'editingRecord' => $editingRecord ? $this->normalizeModel($editingRecord, $statusOptions) : $this->getEmptyRecord($statusOptions),
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

    private function normalizeModel(Affiliate $affiliate, array $statusOptions): array
    {
        $status = (string) ($affiliate->getData(Affiliate::schema_fields_STATUS) ?? '');

        $totalCommission = (float) ($affiliate->getData(Affiliate::schema_fields_TOTAL_COMMISSION) ?? 0.0);
        $paidCommission = (float) ($affiliate->getData(Affiliate::schema_fields_PAID_COMMISSION) ?? 0.0);
        $pendingCommission = max(0.0, round($totalCommission - $paidCommission, 2));

        return [
            'affiliate_id' => (int) $affiliate->getId(),
            'customer_id' => (int) ($affiliate->getData(Affiliate::schema_fields_CUSTOMER_ID) ?? 0),
            'referral_code' => (string) ($affiliate->getData(Affiliate::schema_fields_REFERRAL_CODE) ?? ''),
            'commission_rate' => (float) ($affiliate->getData(Affiliate::schema_fields_COMMISSION_RATE) ?? 0.0),
            'total_commission' => $totalCommission,
            'paid_commission' => $paidCommission,
            'pending_commission' => $pendingCommission,
            'status' => $status,
            'status_label' => $statusOptions[$status] ?? $status,
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
            'customer_id' => 0,
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
