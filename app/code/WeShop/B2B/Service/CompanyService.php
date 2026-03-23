<?php
declare(strict_types=1);

namespace WeShop\B2B\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\B2B\Model\Company;

/**
 * B2B鍏徃鏈嶅姟
 */
class CompanyService
{
    /**
     * 鍒涘缓鍏徃
     * 
     * @param array $companyData 鍏徃鏁版嵁
     * @return Company
     */
    public function createCompany(array $companyData): Company
    {
        /** @var Company $company */
        $company = ObjectManager::getInstance(Company::class);
        
        $company->clearData()
            ->setData(Company::schema_fields_NAME, $companyData['name'] ?? '')
            ->setData(Company::schema_fields_TAX_ID, $companyData['tax_id'] ?? '')
            ->setData(Company::schema_fields_ADDRESS, $companyData['address'] ?? '')
            ->setData(Company::schema_fields_PHONE, $companyData['phone'] ?? '')
            ->setData(Company::schema_fields_EMAIL, $companyData['email'] ?? '')
            ->setData(Company::schema_fields_STATUS, $companyData['status'] ?? 'active')
            ->save();
        
        return $company;
    }
    
    /**
     * 鑾峰彇鍏徃
     * 
     * @param int $companyId 鍏徃ID
     * @return Company|null
     */
    public function getCompany(int $companyId): ?Company
    {
        /** @var Company $company */
        $company = ObjectManager::getInstance(Company::class);
        $company->load($companyId);
        
        if ($company->getId()) {
            return $company;
        }
        
        return null;
    }

    /**
     * 鑾峰彇鏃堕棿锛屽姩鍙?琛?鏁扮殑鎺у埗
     * 
     * @param int $limit
     * @param int $offset
     * @return array<int, array<string, mixed>>
     */
    public function getCompanies(int $limit = 0, int $offset = 0): array
    {
        return $this->fetchCompanies($limit, $offset);
    }

    /**
     * 按企业联系邮箱获取公司列表
     *
     * @param string $contactEmail
     * @param int $limit
     * @param int $offset
     * @return array<int, array<string, mixed>>
     */
    public function getCompaniesByContactEmail(string $contactEmail, int $limit = 0, int $offset = 0): array
    {
        $contactEmail = trim($contactEmail);
        if ($contactEmail === '') {
            return [];
        }

        return $this->fetchCompanies($limit, $offset, $contactEmail);
    }

    /**
     * 鑾峰彇鍏徃鏁版嵁鏁版嵁鍜岀敤鎴蜂綅缃?
     * 
     * @return array<string, mixed>
     */
    public function getCompanySummary(): array
    {
        return $this->buildCompanySummary($this->getCompanies());
    }

    /**
     * 按企业联系邮箱获取公司概览
     *
     * @param string $contactEmail
     * @return array<string, mixed>
     */
    public function getCompanySummaryByContactEmail(string $contactEmail): array
    {
        return $this->buildCompanySummary($this->getCompaniesByContactEmail($contactEmail));
    }

    /**
     * @param int $limit
     * @param int $offset
     * @param string|null $contactEmail
     * @return array<int, array<string, mixed>>
     */
    private function fetchCompanies(int $limit = 0, int $offset = 0, ?string $contactEmail = null): array
    {
        /** @var Company $company */
        $company = ObjectManager::getInstance(Company::class);
        $query = $company->clearData();
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

        return $records;
    }

    /**
     * @param array<int, array<string, mixed>> $companies
     * @return array<string, mixed>
     */
    private function buildCompanySummary(array $companies): array
    {
        $statusBreakdown = [];
        foreach ($companies as $company) {
            if (!is_array($company)) {
                continue;
            }
            $status = strtolower((string) ($company[Company::schema_fields_STATUS] ?? 'active'));
            $statusBreakdown[$status] = ($statusBreakdown[$status] ?? 0) + 1;
        }
        
        arsort($statusBreakdown);
        $primaryStatus = array_key_first($statusBreakdown) ?? '';
        
        return [
            'total' => count($companies),
            'status_breakdown' => $statusBreakdown,
            'primary_status' => $primaryStatus,
        ];
    }
}
