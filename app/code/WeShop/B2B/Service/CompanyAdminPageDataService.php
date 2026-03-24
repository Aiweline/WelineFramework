<?php

declare(strict_types=1);

namespace WeShop\B2B\Service;

use WeShop\B2B\Model\Company;

class CompanyAdminPageDataService
{
    public function __construct(
        private readonly CompanyService $companyService
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function getPageData(int $page = 1, int $pageSize = 20, array $filters = [], int $editingId = 0): array
    {
        $sanitizedFilters = $this->sanitizeFilters($filters);
        $result = $this->companyService->getCompanyList($page, $pageSize, $sanitizedFilters);
        $summary = $this->companyService->getCompanySummary();
        $statusOptions = $this->companyService->getStatusOptions();
        $editingRecord = $editingId ? $this->companyService->getCompanyRecord($editingId) : null;

        return [
            'companySummary' => $summary,
            'companyRecords' => array_map(fn (array $record): array => $this->normalizeRecord($record, $statusOptions), $result['items'] ?? []),
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

        if (!empty($filters['company_id'])) {
            $sanitized['company_id'] = max(0, (int) $filters['company_id']);
        }

        if (!empty($filters['name'])) {
            $sanitized['name'] = trim((string) $filters['name']);
        }

        if (!empty($filters['email'])) {
            $sanitized['email'] = strtolower(trim((string) $filters['email']));
        }

        if (!empty($filters['status']) && $this->companyService->isValidStatus((string) $filters['status'])) {
            $sanitized['status'] = strtolower(trim((string) $filters['status']));
        }

        return $sanitized;
    }

    private function normalizeModel(Company $company, array $statusOptions): array
    {
        $status = (string) ($company->getData(Company::schema_fields_STATUS) ?? CompanyService::STATUS_ACTIVE);

        return [
            'company_id' => (int) $company->getId(),
            'name' => (string) ($company->getData(Company::schema_fields_NAME) ?? ''),
            'email' => (string) ($company->getData(Company::schema_fields_EMAIL) ?? ''),
            'tax_id' => (string) ($company->getData(Company::schema_fields_TAX_ID) ?? ''),
            'phone' => (string) ($company->getData(Company::schema_fields_PHONE) ?? ''),
            'address' => (string) ($company->getData(Company::schema_fields_ADDRESS) ?? ''),
            'status' => $status,
            'status_label' => $statusOptions[$status] ?? $status,
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function normalizeRecord(array $record, array $statusOptions): array
    {
        $status = (string) ($record[Company::schema_fields_STATUS] ?? $record['status'] ?? CompanyService::STATUS_ACTIVE);

        return [
            'company_id' => (int) ($record[Company::schema_fields_ID] ?? $record['company_id'] ?? 0),
            'name' => (string) ($record[Company::schema_fields_NAME] ?? $record['name'] ?? ''),
            'email' => (string) ($record[Company::schema_fields_EMAIL] ?? $record['email'] ?? ''),
            'tax_id' => (string) ($record[Company::schema_fields_TAX_ID] ?? $record['tax_id'] ?? ''),
            'phone' => (string) ($record[Company::schema_fields_PHONE] ?? $record['phone'] ?? ''),
            'address' => (string) ($record[Company::schema_fields_ADDRESS] ?? $record['address'] ?? ''),
            'status' => $status,
            'status_label' => $statusOptions[$status] ?? $status,
            'created_at' => (string) ($record[Company::schema_fields_CREATED_AT] ?? $record['created_at'] ?? ''),
            'updated_at' => (string) ($record[Company::schema_fields_UPDATED_AT] ?? $record['updated_at'] ?? ''),
        ];
    }

    private function getEmptyRecord(array $statusOptions): array
    {
        $status = CompanyService::STATUS_ACTIVE;

        return [
            'company_id' => 0,
            'name' => '',
            'email' => '',
            'tax_id' => '',
            'phone' => '',
            'address' => '',
            'status' => $status,
            'status_label' => $statusOptions[$status] ?? $status,
        ];
    }
}
