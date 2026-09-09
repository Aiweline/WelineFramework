<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Adapter\GoogleSitemapAdapter;
use Weline\Seo\Api\Sitemap\WebsiteDirectoryInterface;

/**
 * Panel-only Google URL Inspection. Never invoked from storefront page render.
 */
final class PanelGoogleUrlInspectionService
{
    public function __construct(
        private readonly WebsiteDirectoryInterface $websiteDirectory,
        private readonly SeoWebsiteAccountBindingService $bindings,
        private readonly ?GoogleSitemapAdapter $adapter = null,
    ) {
    }

    /**
     * @return array{success:bool,status:string,message:string,data:array<string,mixed>}
     */
    public function inspect(string $inspectionUrl): array
    {
        $inspectionUrl = trim($inspectionUrl);
        if ($inspectionUrl === '' || !preg_match('#^https?://#i', $inspectionUrl)) {
            return [
                'success' => false,
                'status' => 'invalid_url',
                'message' => (string)__('请提供绝对 http(s) URL'),
                'data' => [],
            ];
        }

        $website = $this->matchWebsite($inspectionUrl);
        if ($website === null) {
            return [
                'success' => false,
                'status' => 'website_unmatched',
                'message' => (string)__('无法根据当前 URL 匹配已配置网站'),
                'data' => ['inspectionUrl' => $inspectionUrl],
            ];
        }

        $google = null;
        foreach ($this->bindings->getWebsiteAccountsWithPlatforms((int)$website->id, true) as $info) {
            $platform = strtolower(trim((string)($info['platform_code'] ?? '')));
            if (in_array($platform, ['google', 'google_search_console'], true)) {
                $google = $info;
                break;
            }
        }

        if ($google === null) {
            return [
                'success' => false,
                'status' => 'account_unbound',
                'message' => (string)__('当前网站未绑定 Google Search Console 账户。请到后台 SEO → 站点账户绑定。'),
                'data' => [
                    'inspectionUrl' => $inspectionUrl,
                    'website_id' => (int)$website->id,
                    'website_url' => (string)$website->url,
                    'bind_hint' => 'seo/backend/website-account',
                ],
            ];
        }

        $accountConfig = is_array($google['account_config'] ?? null) ? $google['account_config'] : [];
        $siteUrl = trim((string)($accountConfig['site_url'] ?? $accountConfig['website_url'] ?? $website->url));
        if ($siteUrl === '') {
            $siteUrl = (string)$website->url;
        }

        $adapter = $this->adapter ?? ObjectManager::getInstance(GoogleSitemapAdapter::class);
        $result = $adapter->inspectUrl($inspectionUrl, $siteUrl, [
            'config' => $accountConfig,
        ]);

        return [
            'success' => (bool)($result['success'] ?? false),
            'status' => !empty($result['success']) ? 'ok' : 'api_error',
            'message' => (string)($result['message'] ?? ''),
            'data' => array_merge(
                is_array($result['data'] ?? null) ? $result['data'] : [],
                [
                    'website_id' => (int)$website->id,
                    'account_id' => (int)($google['account_id'] ?? 0),
                    'siteUrl' => SeoAccountConfig::normalizeGoogleSiteProperty($siteUrl),
                ],
            ),
        ];
    }

    private function matchWebsite(string $inspectionUrl): ?\Weline\Seo\Api\Sitemap\Data\Website
    {
        $host = strtolower((string)(parse_url($inspectionUrl, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return null;
        }
        $host = preg_replace('/^www\./', '', $host) ?: $host;

        foreach ($this->websiteDirectory->all() as $website) {
            $base = trim((string)$website->url);
            $websiteHost = strtolower((string)(parse_url($base, PHP_URL_HOST) ?: ''));
            $websiteHost = preg_replace('/^www\./', '', $websiteHost) ?: $websiteHost;
            if ($websiteHost !== '' && $websiteHost === $host) {
                return $website;
            }
        }

        return null;
    }
}
