<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Http\RequestInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Api\Domain\LocalDomainPolicy;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\ProjectHostSiteMount;
use Weline\Websites\Service\Value\CanonicalStorefrontUrl;

/**
 * Real frontend-preview base URLs for a website scope.
 * Default: project-Host /~site/{code}. When the admin request is already on this
 * website's Host-only local domain, that domain becomes the default instead
 * (same entry Host as the editor — avoids surprising cross-Host preview URLs).
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
    public function listForWebsite(int $websiteId, string $websiteCode = '', ?string $preferHost = null): array
    {
        if ($websiteId < Website::ID_DEFAULT) {
            return [];
        }

        $websiteCode = \strtolower(\trim($websiteCode));
        $preferHost = \strtolower(\trim((string)($preferHost ?? $this->currentRequestHost())));
        $items = [];
        $seen = [];

        $local = $this->resolveLocalShellBase($websiteId, $websiteCode);
        if ($local !== null) {
            $items[] = [
                'id' => 'local_shell',
                'kind' => 'local_shell',
                'label' => (string)\__('本机项目壳（/~site）'),
                'url' => $local,
                'is_default' => true,
                'badge' => (string)\__('默认·待发布预览'),
                'hint' => (string)\__('项目 Host 上默认：/~site/{站点}，保证能正确预览当前要发布的版本。'),
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
                    ? (string)\__('本站本机域。后台已在此 Host 打开时，默认预览跟当前域（与画布一致）。')
                    : (string)\__('正式/外部域名可选。线上常见仍是已发布内容，可能看不到本机待发布改动；仅上线验收时选用。'),
            ];
        }

        return $this->applyPreferHostDefault($items, $preferHost);
    }

    /**
     * When admin HTTP_HOST matches a listed local_domain, make that the default.
     *
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function applyPreferHostDefault(array $items, string $preferHost): array
    {
        if ($preferHost === '' || $items === []) {
            return $items;
        }
        if (\class_exists(LocalDomainPolicy::class)
            && LocalDomainPolicy::isStandardProjectHost($preferHost)
        ) {
            return $items;
        }

        $matchIndex = null;
        foreach ($items as $i => $item) {
            if (($item['kind'] ?? '') !== 'local_domain') {
                continue;
            }
            $host = \strtolower((string)(\parse_url((string)($item['url'] ?? ''), \PHP_URL_HOST) ?: ''));
            if ($host !== '' && $host === $preferHost) {
                $matchIndex = $i;
                break;
            }
        }
        if ($matchIndex === null) {
            return $items;
        }

        foreach ($items as $i => &$item) {
            $item['is_default'] = ($i === $matchIndex);
            if ($i === $matchIndex) {
                $item['badge'] = (string)\__('默认·当前域');
                $item['hint'] = (string)\__('与当前后台 Host 一致，默认预览跟编辑入口同源。');
            } elseif (($item['kind'] ?? '') === 'local_shell') {
                $item['badge'] = (string)\__('本机项目壳');
                $item['hint'] = (string)\__('可选：切到项目壳 /~site/{站点} 预览。');
            }
        }
        unset($item);

        // Surface the preferred domain first in the picker.
        $preferred = $items[$matchIndex];
        unset($items[$matchIndex]);

        return \array_values(\array_merge([$preferred], $items));
    }

    private function currentRequestHost(): string
    {
        try {
            /** @var RequestInterface $request */
            $request = ObjectManager::getInstance(RequestInterface::class);
            $host = \strtolower(\trim((string)($request->getServer('HTTP_HOST') ?? '')));
            if ($host !== '' && \str_contains($host, ':')) {
                $host = \explode(':', $host, 2)[0];
            }

            return $host;
        } catch (\Throwable) {
            return '';
        }
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

        // Defense: non-default scopes must keep /~site/{code} on the project Host.
        $websiteCode = \strtolower(\trim($websiteCode));
        if ($websiteId > Website::ID_DEFAULT && $websiteCode !== '') {
            $mount = ProjectHostSiteMount::editorMountPath($websiteId, $websiteCode);
            if ($mount !== '' && !\str_contains(\parse_url($base, PHP_URL_PATH) ?: '', '/~site/')) {
                $parts = \parse_url($base);
                $host = \strtolower((string)($parts['host'] ?? ''));
                if ($host !== ''
                    && \class_exists(LocalDomainPolicy::class)
                    && LocalDomainPolicy::isStandardProjectHost($host)
                ) {
                    $scheme = \strtolower((string)($parts['scheme'] ?? 'https'));
                    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';

                    return $scheme . '://' . $host . $port . $mount;
                }
            }
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
