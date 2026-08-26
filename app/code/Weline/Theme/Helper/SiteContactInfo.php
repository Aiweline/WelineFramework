<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Backend\Api\Config\BackendConfigStore;
use Weline\Framework\App\Env;

/**
 * 前台帮助中心等页面读取站点公开联系与品牌信息。
 * 优先 Backend 基础配置，其次 env 常用键，最后给电商常见占位默认值。
 */
class SiteContactInfo
{
    public const CONFIG_MODULE = 'Weline_Backend';

    public function __construct(
        private readonly BackendConfigStore $backendConfig,
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
    public function resolve(): array
    {
        $siteName = $this->firstNonEmpty([
            $this->backend('site_name'),
            $this->env('site.name'),
            $this->env('system.site_name'),
            'Weline',
        ]);

        $siteDescription = $this->firstNonEmpty([
            $this->backend('site_description'),
            $this->env('site.description'),
            $this->env('system.site_description'),
            __('官方商城帮助与客户服务'),
        ]);

        $email = $this->firstNonEmpty([
            $this->backend('contact_email'),
            $this->backend('support_email'),
            $this->env('contact_email'),
            $this->env('site.contact_email'),
            $this->env('ssl.contact_email'),
            'support@example.com',
        ]);

        $phone = $this->firstNonEmpty([
            $this->backend('contact_phone'),
            $this->backend('support_phone'),
            $this->env('contact_phone'),
            $this->env('site.contact_phone'),
            '',
        ]);

        $hours = $this->firstNonEmpty([
            $this->backend('service_hours'),
            $this->backend('contact_hours'),
            $this->env('site.service_hours'),
            (string)__('周一至周五 9:00 - 18:00（法定节假日除外）'),
        ]);

        $address = $this->firstNonEmpty([
            $this->backend('contact_address'),
            $this->backend('site_address'),
            $this->env('site.address'),
            '',
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
