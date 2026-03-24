<?php

declare(strict_types=1);

namespace WeShop\Membership\Service;

use WeShop\Membership\Model\Membership;

class MembershipAdminPageDataService
{
    public function __construct(
        private readonly MembershipService $membershipService
    ) {
    }

    public function getPageData(int $page = 1, int $pageSize = 20, array $filters = [], int $editingId = 0): array
    {
        $sanitizedFilters = $this->sanitizeFilters($filters);
        $result = $this->membershipService->getMembershipList($page, $pageSize, $sanitizedFilters);
        $editingRecord = $editingId > 0 ? $this->membershipService->getMembershipRecord($editingId) : null;

        return [
            'memberships' => array_map(fn (array $record): array => $this->normalizeRecord($record), $result['items'] ?? []),
            'summary' => $this->membershipService->getMembershipSummary(),
            'filters' => $sanitizedFilters,
            'pagination' => $result['pagination'] ?? [],
            'levelOptions' => $this->membershipService->getLevelOptions(),
            'editingRecord' => $editingRecord ? $this->normalizeModel($editingRecord) : $this->getEmptyRecord(),
        ];
    }

    private function sanitizeFilters(array $filters): array
    {
        $sanitized = [];

        if (!empty($filters['customer_id'])) {
            $sanitized['customer_id'] = (int) $filters['customer_id'];
        }

        if (!empty($filters['level']) && $this->membershipService->isValidLevel((string) $filters['level'])) {
            $sanitized['level'] = strtolower((string) $filters['level']);
        }

        return $sanitized;
    }

    private function normalizeModel(Membership $membership): array
    {
        $level = strtolower((string) $membership->getData(Membership::schema_fields_LEVEL));

        return [
            'membership_id' => (int) $membership->getId(),
            'customer_id' => (int) $membership->getData(Membership::schema_fields_CUSTOMER_ID),
            'level' => $level,
            'level_label' => $this->membershipService->getLevelOptions()[$level] ?? $level,
            'points' => (int) $membership->getData(Membership::schema_fields_POINTS),
            'created_at' => (string) ($membership->getData(Membership::schema_fields_CREATED_AT) ?? ''),
            'updated_at' => (string) ($membership->getData(Membership::schema_fields_UPDATED_AT) ?? ''),
        ];
    }

    private function normalizeRecord(array $record): array
    {
        $level = strtolower((string) ($record[Membership::schema_fields_LEVEL] ?? $record['level'] ?? 'bronze'));

        return [
            'membership_id' => (int) ($record[Membership::schema_fields_ID] ?? $record['membership_id'] ?? 0),
            'customer_id' => (int) ($record[Membership::schema_fields_CUSTOMER_ID] ?? $record['customer_id'] ?? 0),
            'level' => $level,
            'level_label' => $this->membershipService->getLevelOptions()[$level] ?? $level,
            'points' => (int) ($record[Membership::schema_fields_POINTS] ?? $record['points'] ?? 0),
            'created_at' => (string) ($record[Membership::schema_fields_CREATED_AT] ?? $record['created_at'] ?? ''),
            'updated_at' => (string) ($record[Membership::schema_fields_UPDATED_AT] ?? $record['updated_at'] ?? ''),
        ];
    }

    private function getEmptyRecord(): array
    {
        return [
            'membership_id' => 0,
            'customer_id' => 0,
            'level' => 'bronze',
            'level_label' => $this->membershipService->getLevelOptions()['bronze'] ?? 'Bronze',
            'points' => 0,
            'created_at' => '',
            'updated_at' => '',
        ];
    }
}
