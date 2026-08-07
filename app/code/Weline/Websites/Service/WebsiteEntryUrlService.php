<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\App\Env;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteDomain;

/**
 * 网站列表「访问前端 / 管理后端」入口 URL。
 */
final class WebsiteEntryUrlService
{
    public function __construct(
        private readonly WebsiteDomain $websiteDomain,
    ) {
    }

    /**
     * @param array<string, mixed> $websiteRow
     * @return array{frontend_url: string, backend_url: string}
     */
    public function resolveForListingRow(array $websiteRow): array
    {
        $websiteId = (int)($websiteRow[Website::schema_fields_ID] ?? $websiteRow['website_id'] ?? -1);
        $siteUrl = \trim((string)($websiteRow[Website::schema_fields_URL] ?? $websiteRow['url'] ?? ''));
        $domainList = $websiteRow['domain_list'] ?? null;
        if (!\is_array($domainList) && $websiteId >= 0) {
            $domainList = $this->websiteDomain->getDomainsWithStatus($websiteId);
        }
        if (!\is_array($domainList)) {
            $domainList = [];
        }

        $frontendUrl = $this->buildFrontendUrl($siteUrl, $domainList);
        $backendUrl = $frontendUrl !== '' ? $this->buildBackendUrl($frontendUrl) : '';

        return [
            'frontend_url' => $frontendUrl,
            'backend_url' => $backendUrl,
        ];
    }

    /**
     * @param list<array<string, mixed>> $domainList
     */
    private function buildFrontendUrl(string $siteUrl, array $domainList): string
    {
        if ($siteUrl !== '') {
            return \rtrim($siteUrl, '/');
        }
        if ($domainList === []) {
            return '';
        }
        $first = $domainList[0] ?? [];
        $host = \trim((string)($first['domain'] ?? ''));
        if ($host === '') {
            return '';
        }
        $scheme = !empty($first['https_enabled']) ? 'https' : 'http';

        return $scheme . '://' . $host;
    }

    private function buildBackendUrl(string $frontendUrl): string
    {
        $parts = \parse_url($frontendUrl);
        if (!\is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $scheme = (string)($parts['scheme'] ?? 'http');
        $host = (string)$parts['host'];
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $mount = \trim((string)($parts['path'] ?? ''), '/');
        $backendKey = \trim((string)(Env::getAreaRoutePrefix('backend') ?? ''), '/');
        if ($backendKey === '') {
            return '';
        }

        $base = $scheme . '://' . $host . $port;
        if ($mount !== '') {
            $base .= '/' . $mount;
        }

        return $base . '/' . $backendKey . '/admin/login';
    }
}
