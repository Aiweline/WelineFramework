<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Backend\Api\Config\BackendConfigStore;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\Websites\Service\SiteContactSeedService;

/**
 * 前台帮助中心等页面读取站点公开联系与品牌信息。
 * 站名/简介优先 Website 范围（SiteBrand），再 Backend / env；禁止硬编码商户品牌。
 * 联系地址/电话/服务时间：SystemConfig（Weline_Websites）按 Global→Website→Store 继承，再 Backend / env。
 */
class SiteContactInfo
{
    public const CONFIG_MODULE = 'Weline_Backend';

    public function __construct(
        private readonly BackendConfigStore $backendConfig,
        private readonly ?SiteBrand $siteBrand = null,
    ) {
    }

    /**
     * @return array{
     *   site_name: string,
     *   site_description: string,
     *   contact_email: string,
     *   contact_phone: string,
     *   service_hours: string,
     *   contact_address: string
     * }
     */
    public function resolve(?string $storageScope = null): array
    {
        $scope = $this->normalizeScope($storageScope);

        $siteName = '';
        $siteDescription = '';
        if ($this->siteBrand instanceof SiteBrand) {
            $siteName = trim($this->siteBrand->resolveFrontendSiteName());
            $siteDescription = trim($this->siteBrand->resolveFrontendSiteDescription());
        }
        if ($siteName === '') {
            $siteName = $this->firstNonEmpty([
                $this->backend('site_name'),
                $this->env('site.name'),
                $this->env('system.site_name'),
            ]);
        }
        if ($siteDescription === '') {
            $siteDescription = $this->firstNonEmpty([
                $this->backend('site_description'),
                $this->env('site.description'),
                $this->env('system.site_description'),
                (string)__('官方商城帮助与客户服务'),
            ]);
        }

        $email = $this->firstNonEmpty([
            $this->backend('contact_email'),
            $this->backend('support_email'),
            $this->env('contact_email'),
            $this->env('site.contact_email'),
            $this->env('ssl.contact_email'),
            'support@example.com',
        ]);

        $phone = $this->firstNonEmpty([
            $this->systemConfig(SiteContactSeedService::KEY_PHONE, $scope),
            $this->backend('contact_phone'),
            $this->backend('support_phone'),
            $this->env('contact_phone'),
            $this->env('site.contact_phone'),
            '',
        ]);

        $hours = $this->firstNonEmpty([
            $this->systemConfig(SiteContactSeedService::KEY_SERVICE_HOURS, $scope),
            $this->backend('service_hours'),
            $this->backend('contact_hours'),
            $this->env('site.service_hours'),
            (string)__('周一至周五 9:00 - 18:00（法定节假日除外）'),
        ]);

        $address = $this->firstNonEmpty([
            $this->systemConfig(SiteContactSeedService::KEY_ADDRESS, $scope),
            $this->backend('contact_address'),
            $this->backend('site_address'),
            $this->env('site.address'),
            SiteContactSeedService::DEFAULT_ADDRESS_EN,
        ]);

        return [
            'site_name' => $siteName,
            'site_description' => $siteDescription,
            'contact_email' => $email,
            'contact_phone' => $phone,
            'service_hours' => $hours,
            'contact_address' => $address,
        ];
    }

    /** 当前网站的法定资料独立于品牌与客服地址，不回退到全局商户。 */
    public function resolveLegalContact(?\Weline\Framework\Runtime\ScopeIdentity $identity): array
    {
        $values = ['legal_name' => '', 'registered_address' => '', 'legal_contact_email' => ''];
        if ($identity === null || $identity->websiteId === null || $identity->websiteCode === null) {
            return $values;
        }
        $hierarchy = ObjectManager::getInstance(\Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface::class);
        $websiteScope = $hierarchy->toStorageScope(\Weline\Framework\Runtime\ScopeIdentity::website(
            $identity->websiteId,
            $identity->websiteCode,
        ));
        $config = ObjectManager::getInstance(SystemConfig::class);
        foreach ($values as $field => $_) {
            $resolved = $config->resolveConfig('website/legal/' . $field, SiteContactSeedService::CONFIG_MODULE,
                SiteContactSeedService::CONFIG_AREA, $websiteScope, SystemConfig::LOCALE_DEFAULT, null);
            if (!empty($resolved['found']) && ($resolved['source']['scope'] ?? '') === $websiteScope) {
                $values[$field] = trim((string)($resolved['value'] ?? ''));
            }
        }
        return $values;
    }

    private function normalizeScope(?string $storageScope): string
    {
        $scope = trim((string)$storageScope);
        if ($scope === '' || str_starts_with($scope, '__')) {
            return SystemConfig::SCOPE_GLOBAL;
        }

        return $scope;
    }

    private function systemConfig(string $key, string $storageScope): string
    {
        try {
            /** @var SystemConfig $config */
            $config = ObjectManager::getInstance(SystemConfig::class);
            $resolved = $config->resolveConfig(
                $key,
                SiteContactSeedService::CONFIG_MODULE,
                SiteContactSeedService::CONFIG_AREA,
                $storageScope,
                SystemConfig::LOCALE_DEFAULT,
                null
            );
            if (empty($resolved['found'])) {
                return '';
            }

            return trim((string)($resolved['value'] ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    private function backend(string $key): string
    {
        try {
            return trim((string)($this->backendConfig->getConfig($key, self::CONFIG_MODULE) ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    private function env(string $key): string
    {
        try {
            $value = Env::getInstance()->getConfig($key);
            if (is_scalar($value) || $value === null) {
                return trim((string)($value ?? ''));
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * @param list<string> $candidates
     */
    private function firstNonEmpty(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string)$candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
