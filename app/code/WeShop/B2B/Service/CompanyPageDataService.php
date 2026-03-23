<?php

declare(strict_types=1);

namespace WeShop\B2B\Service;

use WeShop\B2B\Model\Company;

class CompanyPageDataService
{
    public function __construct(
        private readonly CompanyService $companyService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId, ?string $contactEmail = null): array
    {
        $contactEmail = trim((string) $contactEmail);
        if ($customerId <= 0 || $contactEmail === '') {
            return [
                'company_summary' => $this->emptySummary(),
                'company_list' => [],
                'company_contact_email' => $contactEmail,
            ];
        }

        $summary = $this->companyService->getCompanySummaryByContactEmail($contactEmail);
        $companies = $this->companyService->getCompaniesByContactEmail($contactEmail, 6);

        $companies = array_values(array_filter($companies, fn($item) => is_array($item)));
        $companies = array_map([$this, 'mapCompany'], $companies);

        return [
            'company_summary' => $summary,
            'company_list' => $companies,
            'company_contact_email' => $contactEmail,
        ];
    }

    /**
     * @param array<string, mixed> $company
     * @return array<string, mixed>
     */
    private function mapCompany(array $company): array
    {
        $status = (string) ($company[Company::schema_fields_STATUS] ?? '');

        return [
            'company_id' => (int) ($company[Company::schema_fields_ID] ?? 0),
            'name' => (string) ($company[Company::schema_fields_NAME] ?? ''),
            'tax_id' => (string) ($company[Company::schema_fields_TAX_ID] ?? ''),
            'status' => $status,
            'created_at' => (string) ($company[Company::schema_fields_CREATED_AT] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'total' => 0,
            'status_breakdown' => [],
            'primary_status' => '',
        ];
    }
}
