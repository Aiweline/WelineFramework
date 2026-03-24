<?php

declare(strict_types=1);

namespace WeShop\B2B\Service;

use WeShop\B2B\Model\Company;
use Weline\Framework\Manager\ObjectManager;

class CompanyService
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_DISABLED = 'disabled';

    public function getStatusOptions(): array
    {
        return [
            self::STATUS_ACTIVE => (string) __('Active'),
            self::STATUS_PENDING => (string) __('Pending Review'),
            self::STATUS_APPROVED => (string) __('Approved'),
            self::STATUS_REJECTED => (string) __('Rejected'),
            self::STATUS_DISABLED => (string) __('Disabled'),
        ];
    }

    public function isValidStatus(string $status): bool
    {
        return isset($this->getStatusOptions()[strtolower(trim($status))]);
    }

    public function createCompany(array $companyData): Company
    {
        return $this->saveCompany($companyData);
    }

    public function getCompany(int $companyId): ?Company
    {
        /** @var Company $company */
        $company = ObjectManager::getInstance(Company::class);
        $company->load($companyId);

        return $company->getId() ? $company : null;
    }

    public function getCompanyRecord(int $companyId): ?Company
    {
        return $this->getCompany($companyId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCompanies(int $limit = 0, int $offset = 0): array
    {
        return $this->fetchCompanies($limit, $offset);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCompaniesByContactEmail(string $contactEmail, int $limit = 0, int $offset = 0): array
    {
        $contactEmail = $this->normalizeEmail($contactEmail);
        if ($contactEmail === '') {
            return [];
        }

        return $this->fetchCompanies($limit, $offset, $contactEmail);
    }

    /**
     * @return array{total:int,status_breakdown:array<string,int>,primary_status:string,active:int,pending:int,approved:int,rejected:int,disabled:int}
     */
    public function getCompanySummary(): array
    {
        $summary = $this->buildCompanySummary($this->getCompanies());

        foreach (array_keys($this->getStatusOptions()) as $status) {
            $summary[$status] = (int) ($summary['status_breakdown'][$status] ?? 0);
        }

        return $summary;
    }

    /**
     * @return array{total:int,status_breakdown:array<string,int>,primary_status:string}
     */
    public function getCompanySummaryByContactEmail(string $contactEmail): array
    {
        return $this->buildCompanySummary($this->getCompaniesByContactEmail($contactEmail));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items:array<int, array<string, mixed>>,total:int,pagination:array<string, mixed>}
     */
    public function getCompanyList(int $page = 1, int $pageSize = 20, array $filters = []): array
    {
        /** @var Company $company */
        $company = ObjectManager::getInstance(Company::class);
        $company->clear();

        $this->applyListFilters($company, $filters);

        $company->order(Company::schema_fields_UPDATED_AT, 'DESC')
            ->order(Company::schema_fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize);

        return [
            'items' => $company->select()->fetchArray(),
            'total' => $company->getTotalCount(),
            'pagination' => $company->getPagination(),
        ];
    }

    public function saveCompany(array $data): Company
    {
        $companyId = (int) ($data['company_id'] ?? 0);
        $name = $this->requireCompanyName((string) ($data['name'] ?? ''));
        $email = $this->normalizeEmail((string) ($data['email'] ?? ''), true);
        $taxId = $this->normalizeText((string) ($data['tax_id'] ?? ''));
        $address = $this->normalizeText((string) ($data['address'] ?? ''));
        $phone = $this->normalizeText((string) ($data['phone'] ?? ''));
        $status = $this->normalizeStatus((string) ($data['status'] ?? self::STATUS_ACTIVE));

        /** @var Company $company */
        $company = ObjectManager::getInstance(Company::class);
        if ($companyId > 0) {
            $company->load($companyId);
            if (!$company->getId()) {
                throw new \InvalidArgumentException((string) __('Company record not found.'));
            }
        } else {
            $company->clearData();
        }

        $now = date('Y-m-d H:i:s');
        $company->setData(Company::schema_fields_NAME, $name)
            ->setData(Company::schema_fields_EMAIL, $email)
            ->setData(Company::schema_fields_TAX_ID, $taxId)
            ->setData(Company::schema_fields_ADDRESS, $address)
            ->setData(Company::schema_fields_PHONE, $phone)
            ->setData(Company::schema_fields_STATUS, $status)
            ->setData(Company::schema_fields_UPDATED_AT, $now);

        if (!$company->getId()) {
            $company->setData(Company::schema_fields_CREATED_AT, $now);
        }

        $company->save();

        return $company;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyListFilters(Company $company, array $filters): void
    {
        if (!empty($filters['company_id'])) {
            $company->where(Company::schema_fields_ID, (int) $filters['company_id']);
        }

        if (!empty($filters['name'])) {
            $company->where(Company::schema_fields_NAME, '%' . trim((string) $filters['name']) . '%', 'LIKE');
        }

        if (!empty($filters['email'])) {
            $email = $this->normalizeEmail((string) $filters['email']);
            if ($email !== '') {
                $company->where(Company::schema_fields_EMAIL, '%' . $email . '%', 'LIKE');
            }
        }

        if (!empty($filters['status'])) {
            $status = strtolower(trim((string) $filters['status']));
            if ($this->isValidStatus($status)) {
                $company->where(Company::schema_fields_STATUS, $status);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchCompanies(int $limit = 0, int $offset = 0, ?string $contactEmail = null): array
    {
        /** @var Company $company */
        $company = ObjectManager::getInstance(Company::class);
        $query = $company->clear();

        if ($contactEmail !== null && $contactEmail !== '') {
            $query = $query->where(Company::schema_fields_EMAIL, $contactEmail);
        }

        $query = $query->order(Company::schema_fields_CREATED_AT, 'DESC');

        if ($limit > 0) {
            $query = $query->limit($limit, $offset);
        }

        $records = $query->select()->fetchArray();
        if (!is_array($records)) {
            return [];
        }

        return array_values(array_filter($records, static fn ($record): bool => is_array($record)));
    }

    /**
     * @param array<int, array<string, mixed>> $companies
     * @return array{total:int,status_breakdown:array<string,int>,primary_status:string}
     */
    private function buildCompanySummary(array $companies): array
    {
        $statusBreakdown = [];
        foreach ($companies as $company) {
            if (!is_array($company)) {
                continue;
            }

            $status = strtolower((string) ($company[Company::schema_fields_STATUS] ?? self::STATUS_ACTIVE));
            $statusBreakdown[$status] = ($statusBreakdown[$status] ?? 0) + 1;
        }

        arsort($statusBreakdown);

        return [
            'total' => count($companies),
            'status_breakdown' => $statusBreakdown,
            'primary_status' => array_key_first($statusBreakdown) ?? '',
        ];
    }

    private function requireCompanyName(string $name): string
    {
        $name = $this->normalizeText($name);
        if ($name === '') {
            throw new \InvalidArgumentException((string) __('Company name is required.'));
        }

        return $name;
    }

    private function normalizeEmail(string $email, bool $required = false): string
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            if ($required) {
                throw new \InvalidArgumentException((string) __('Company contact email is required.'));
            }

            return '';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException((string) __('Company contact email is invalid.'));
        }

        return $email;
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (!$this->isValidStatus($status)) {
            throw new \InvalidArgumentException((string) __('Unsupported company status.'));
        }

        return $status;
    }

    private function normalizeText(string $value): string
    {
        return trim($value);
    }
}
