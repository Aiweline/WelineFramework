<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;
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

    /**
     * Backend chrome (e.g. topbar「访问前端」): prefer live request base over DB localhost.
     */
    public function resolveStorefrontForBackendChrome(Request $request, array $websiteRow): string
    {
        $fromRequest = \trim($request->getBaseHost());
        if ($fromRequest !== '') {
            return \rtrim($fromRequest, '/');
        }

        $entry = $this->resolveForListingRow($websiteRow);

        return $this->withCurrentRequestPort((string)($entry['frontend_url'] ?? ''));
    }

    /**
     * Local WLS often stores website.url without :port while the live request has one.
     */
    public function withCurrentRequestPort(string $url): string
    {
        $url = \trim($url);
        if ($url === '') {
            return '';
        }
        $parts = \parse_url($url);
        if (!\is_array($parts) || empty($parts['host']) || isset($parts['port'])) {
            return $url;
        }
        $httpHost = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($httpHost === '' || !\preg_match('/:(\d+)\z/', $httpHost, $m)) {
            return $url;
        }
        $port = (int)$m[1];
        if ($port <= 0) {
            return $url;
        }
        $scheme = (string)($parts['scheme'] ?? 'http');
        $httpsOn = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $forwarded = \strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($httpsOn || $forwarded === 'https') {
            $scheme = 'https';
        }
        $rebuild = $scheme . '://' . $parts['host'] . ':' . $port;
        if (!empty($parts['path'])) {
            $rebuild .= $parts['path'];
        }
        if (isset($parts['query']) && $parts['query'] !== '') {
            $rebuild .= '?' . $parts['query'];
        }

        return $rebuild;
    }
}
