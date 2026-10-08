<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Server\Api\Domain\LocalDomainPolicy;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\ProjectHostSiteMount;
use Weline\Websites\Service\Value\CanonicalStorefrontUrl;

/**
 * Real frontend-preview base URLs for a website scope.
 * Local project-Host /~site/{code} is always first and default.
 */
final class ThemeFrontendPreviewBaseCatalog
{
    public function __construct(
        private readonly InstallLocalStorefrontBaseResolver $installLocalBaseResolver,
    ) {
    }

    /**
     * @return list<array{
     *   id: string,
     *   kind: string,
     *   label: string,
     *   url: string,
     *   is_default: bool,
     *   badge: string,
     *   hint: string
     * }>
     */
    public function listForWebsite(int $websiteId, string $websiteCode = ''): array
    {
        if ($websiteId < Website::ID_DEFAULT) {
            return [];
        }

        $websiteCode = \strtolower(\trim($websiteCode));
        $items = [];
        $seen = [];

        $local = $this->resolveLocalShellBase($websiteId, $websiteCode);
        if ($local !== null) {
            $items[] = [
                'id' => 'local_shell',
                'kind' => 'local_shell',
                'label' => (string)\__('本机项目壳'),
                'url' => $local,
                'is_default' => true,
                'badge' => (string)\__('默认'),
                'hint' => (string)\__('本机开发推荐：可达，且看到的是本机改动，不是线上站。'),
            ];
            $seen[$this->normalizeUrlKey($local)] = true;
        }

        foreach (WebsiteData::domainsForWebsite($websiteId) as $domainRow) {
            if (!\is_array($domainRow)) {
                continue;
            }
            $domain = \strtolower(\trim((string)($domainRow['domain'] ?? '')));
            if ($domain === '') {
                continue;
            }
            $subPath = CanonicalStorefrontUrl::canonicalPath((string)($domainRow['sub_path'] ?? '/'));
            if (ProjectHostSiteMount::conflictsWithDomainSubPath($subPath)) {
                continue;
            }
            // Bare project Host is the default-site entry — already covered by local_shell.
            if (\class_exists(LocalDomainPolicy::class)
                && LocalDomainPolicy::isStandardProjectHost($domain)
                && ($subPath === '/' || $subPath === '')
            ) {
                continue;
            }

            $url = 'https://' . $domain;
            if ($subPath !== '/' && $subPath !== '') {
                $url .= $subPath;
            }
            $key = $this->normalizeUrlKey($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $isLocalHost = $this->isLocalAcceptanceHost($domain);
            $items[] = [
                'id' => 'domain:' . $domain . ($subPath === '/' ? '' : $subPath),
                'kind' => $isLocalHost ? 'local_domain' : 'production_domain',
                'label' => $domain . ($subPath === '/' || $subPath === '' ? '' : $subPath),
                'url' => $url,
                'is_default' => false,
                'badge' => $isLocalHost ? (string)\__('本机域') : (string)\__('生产域名'),
                'hint' => $isLocalHost
                    ? (string)\__('本机绑定域名。')
                    : (string)\__('生产域名：可能不可达，或打开的是线上内容而非本机开发站。上线验收时再选。'),
            ];
        }

        return $items;
    }

    public function isAllowedBaseUrl(string $candidate, int $websiteId, string $websiteCode = ''): bool
    {
        $candidateKey = $this->normalizeUrlKey($candidate);
        if ($candidateKey === '') {
            return false;
        }
        foreach ($this->listForWebsite($websiteId, $websiteCode) as $item) {
            if ($this->normalizeUrlKey((string)($item['url'] ?? '')) === $candidateKey) {
                return true;
            }
        }

        return false;
    }

    public function normalizeBaseUrl(string $url): string
    {
        $url = \trim($url);
        if ($url === '') {
            return '';
        }
        try {
            $canonical = CanonicalStorefrontUrl::fromStoreUrl($url);

            return $canonical->toString();
        } catch (\Throwable) {
            $parts = \parse_url($url);
            if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
                return '';
            }
            $scheme = \strtolower((string)$parts['scheme']);
            if (!\in_array($scheme, ['http', 'https'], true)) {
                return '';
            }
            $host = \strtolower((string)$parts['host']);
            $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
            $path = \rtrim((string)($parts['path'] ?? ''), '/');

            return $scheme . '://' . $host . $port . ($path === '' || $path === '/' ? '' : $path);
        }
    }

    private function resolveLocalShellBase(int $websiteId, string $websiteCode): ?string
    {
        try {
            $base = $this->installLocalBaseResolver->resolveForWebsite($websiteId, $websiteCode);
        } catch (\Throwable) {
            return null;
        }
        $base = $this->normalizeBaseUrl((string)$base);
        if ($base === '') {
            return null;
        }

        return $base;
    }

    private function normalizeUrlKey(string $url): string
    {
        $normalized = $this->normalizeBaseUrl($url);
        if ($normalized === '') {
            return '';
        }

        return \strtolower(\rtrim($normalized, '/'));
    }

    private function isLocalAcceptanceHost(string $host): bool
    {
        $host = \strtolower(\trim($host));
        if ($host === '') {
            return false;
        }
        if (\class_exists(LocalDomainPolicy::class) && LocalDomainPolicy::isStandardProjectHost($host)) {
            return true;
        }

        return \str_ends_with($host, '.test.weline.com')
            || \str_ends_with($host, '.weline.test')
            || $host === 'localhost'
            || $host === '127.0.0.1';
    }
}
