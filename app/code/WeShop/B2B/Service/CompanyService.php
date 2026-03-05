<?php

declare(strict_types=1);

namespace WeShop\B2B\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\B2B\Model\Company;

/**
 * B2B公司服务
 */
class CompanyService
{
    /**
     * 创建公司
     * 
     * @param array $companyData 公司数据
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
     * 获取公司
     * 
     * @param int $companyId 公司ID
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
}
