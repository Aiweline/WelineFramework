<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\RequestInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Api\Domain\LocalDomainPolicy;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\ProjectHostSiteMount;
use Weline\Websites\Service\Value\CanonicalStorefrontUrl;

/**
 * Capture / theme-card preview base: install Host + website mount path.
 * Never use Website.URL's public domain (e.g. daocharms.com) as screenshot Host.
 */
final class InstallLocalStorefrontBaseResolver
{
    /**
     * Absolute storefront base for local edit / preview capture (scheme://host[:port][/mount]).
     * On the standard project Host: always prefer synthetic /~site/{code}.
     * Do not default to WebsiteDomain sibling hosts (*.test.weline.com / *.weline.test).
     * Never uses production public domains (e.g. daocharms.com).
     */
    public function resolveForWebsite(int $websiteId, string $websiteCode = ''): ?string
    {
        if ($websiteId < Website::ID_DEFAULT) {
            return null;
        }

        $installOrigin = $this->resolveInstallOrigin();
        if ($installOrigin === null) {
            return null;
        }

        $localBase = $this->resolveLocalAcceptanceBaseForWebsite($websiteId, $websiteCode, $installOrigin);
        if ($localBase !== null) {
            return $localBase;
        }

        return $installOrigin;
    }

    /**
     * scheme://host[:port] for this install (not a bound site's public domain).
     */
    public function resolveInstallOrigin(): ?string
    {
        $fromRequest = $this->originFromCurrentRequest();
        if ($fromRequest !== null) {
            return $fromRequest;
        }

        $fromDefault = $this->originFromWebsiteUrl(Website::ID_DEFAULT);
        if ($fromDefault !== null) {
            return $fromDefault;
        }

        return $this->originFromServerInstance();
    }

    private function resolveLocalAcceptanceBaseForWebsite(
        int $websiteId,
        string $websiteCode,
        string $installOrigin,
    ): ?string {
        $websiteCode = \strtolower(\trim($websiteCode));
        $installHost = $this->hostFromOrigin($installOrigin);

        try {
            $rowCode = $websiteCode;
            $row = null;
            try {
                /** @var Website $probe */
                $probe = ObjectManager::getInstance(Website::class);
                $row = (clone $probe)->clearData()->clearQuery()->load($websiteId);
                $loadedId = $row->hasData(Website::schema_fields_ID)
                    ? (int)$row->getData(Website::schema_fields_ID)
                    : -1;
                if ($loadedId !== $websiteId) {
                    $row = null;
                } elseif ($websiteCode !== '') {
                    $code = \strtolower(\trim($row->getCode()));
                    if ($code !== '' && $code !== $websiteCode) {
                        return null;
                    }
                }
            } catch (\Throwable) {
                $row = null;
            }

            if ($row !== null) {
                $rowCode = \strtolower(\trim($row->getCode()));
                if ($rowCode === '' && $websiteCode !== '') {
                    $rowCode = $websiteCode;
                }
            } elseif ($websiteCode === '') {
                // No Website row and no caller code — cannot invent a mount.
                return null;
            }

            $domains = $row !== null ? WebsiteData::domainsForWebsite($websiteId) : [];
            $onStandardProjectHost = $installHost !== ''
                && \class_exists(LocalDomainPolicy::class)
                && LocalDomainPolicy::isStandardProjectHost($installHost);

            // 0) 本地编辑主通道：标准项目 Host → 合成 /~site/{code}。
            // 禁止默认拼 WebsiteDomain 独立域名（grocery.test / ai-test-*.weline.test）。
            if ($onStandardProjectHost) {
                $synthetic = ProjectHostSiteMount::editorMountPath($websiteId, $rowCode);
                if ($synthetic !== '') {
                    return \rtrim($installOrigin, '/') . $synthetic;
                }
                if ($websiteId === Website::ID_DEFAULT
                    && $rowCode === Website::CODE_DEFAULT
                ) {
                    return $installOrigin;
                }
            }

            // 1) Path-mount on this install Host (legacy WebsiteDomain sub_path).
            if ($installHost !== '' && $row !== null) {
                foreach ($domains as $domainRow) {
                    if (!\is_array($domainRow)) {
                        continue;
                    }
                    $domain = \strtolower(\trim((string)($domainRow['domain'] ?? '')));
                    if ($domain === '' || !$this->hostsMatch($domain, $installHost)) {
                        continue;
                    }
                    $subPath = CanonicalStorefrontUrl::canonicalPath(
                        (string)($domainRow['sub_path'] ?? '/')
                    );
                    if ($subPath === '/' || $subPath === '') {
                        return $installOrigin;
                    }
                    if (ProjectHostSiteMount::conflictsWithDomainSubPath($subPath)) {
                        continue;
                    }

                    return \rtrim($installOrigin, '/') . $subPath;
                }
            }

            // 2) Host-only 主验收域（*.test.weline.com）——仅当项目 Host 无法挂 /~site 时。
            // 预览基址白名单 / 非标准安装 Host 仍可用；本地编辑默认不走这里。
            foreach ($domains as $domainRow) {
                if (!\is_array($domainRow)) {
                    continue;
                }
                $domain = \strtolower(\trim((string)($domainRow['domain'] ?? '')));
                if ($domain === '' || !$this->isPrimaryLocalAcceptanceHost($domain)) {
                    continue;
                }
                if ($installHost !== '' && $this->hostsMatch($domain, $installHost)) {
                    continue;
                }
                $subPath = CanonicalStorefrontUrl::canonicalPath(
                    (string)($domainRow['sub_path'] ?? '/')
                );
                $origin = $this->formatOrigin('https', $domain, null);
                if ($origin === '') {
                    continue;
                }
                if ($subPath === '/' || $subPath === '') {
                    return $origin;
                }

                return \rtrim($origin, '/') . $subPath;
            }

            // 2b) Legacy Host-only *.weline.test — last resort only.
            foreach ($domains as $domainRow) {
                if (!\is_array($domainRow)) {
                    continue;
                }
                $domain = \strtolower(\trim((string)($domainRow['domain'] ?? '')));
                if ($domain === '' || !$this->isLegacyLocalAcceptanceHost($domain)) {
                    continue;
                }
                if ($installHost !== '' && $this->hostsMatch($domain, $installHost)) {
                    continue;
                }
                $subPath = CanonicalStorefrontUrl::canonicalPath(
                    (string)($domainRow['sub_path'] ?? '/')
                );
                $origin = $this->formatOrigin('https', $domain, null);
                if ($origin === '') {
                    continue;
                }
                if ($subPath === '/' || $subPath === '') {
                    return $origin;
                }

                return \rtrim($origin, '/') . $subPath;
            }

            if ($row === null) {
                return null;
            }

            // 3) Website.URL only when its host is already a local acceptance Host.
            $rawUrl = \trim($row->getUrl());
            if ($rawUrl !== '') {
                $canonical = CanonicalStorefrontUrl::fromStoreUrl($rawUrl);
                if ($this->isLocalAcceptanceHost($canonical->host)
                    || ($installHost !== '' && $this->hostsMatch($canonical->host, $installHost))
                ) {
                    return $canonical->toString();
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function isLocalAcceptanceHost(string $host): bool
    {
        return $this->isPrimaryLocalAcceptanceHost($host)
            || $this->isLegacyLocalAcceptanceHost($host)
            || $this->isLoopbackAcceptanceHost($host);
    }

    /** 主验收 Host：*.test.weline.com（含 grocery.test.weline.com）。 */
    private function isPrimaryLocalAcceptanceHost(string $host): bool
    {
        $host = \strtolower(\trim($host));

        return $host !== '' && \str_ends_with($host, '.test.weline.com');
    }

    /**
     * 遗留本机 Host：*.weline.test。不得压过项目 Host 的 path-mount / /~site/{code}。
     */
    private function isLegacyLocalAcceptanceHost(string $host): bool
    {
        $host = \strtolower(\trim($host));
        if ($host === '' || \str_ends_with($host, '.test.weline.com')) {
            return false;
        }

        return \str_ends_with($host, '.weline.test');
    }

    private function isLoopbackAcceptanceHost(string $host): bool
    {
        $host = \strtolower(\trim($host));

        return $host === 'localhost' || $host === '127.0.0.1';
    }

    private function originFromCurrentRequest(): ?string
    {
        try {
            /** @var RequestInterface $request */
            $request = ObjectManager::getInstance(RequestInterface::class);
            $baseHost = \trim((string)$request->getBaseHost());
            if ($baseHost === '') {
                return null;
            }
            $parsed = \parse_url($baseHost);
            if (!\is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
                return null;
            }

            return $this->formatOrigin(
                (string)$parsed['scheme'],
                (string)$parsed['host'],
                isset($parsed['port']) ? (int)$parsed['port'] : null,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function originFromWebsiteUrl(int $websiteId): ?string
    {
        $raw = Website::resolveStorefrontBaseUrl($websiteId);
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            $canonical = CanonicalStorefrontUrl::fromStoreUrl($raw);

            return $canonical->originString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function originFromServerInstance(): ?string
    {
        $path = BP . 'var/server/instances/default.json';
        if (!\is_file($path)) {
            return null;
        }
        try {
            $data = \json_decode((string)\file_get_contents($path), true);
            if (!\is_array($data)) {
                return null;
            }
            $publicOrigin = \trim((string)($data['public_origin'] ?? ''));
            if ($publicOrigin !== '') {
                $canonical = CanonicalStorefrontUrl::fromStoreUrl($publicOrigin);

                return $canonical->originString();
            }
            $publicHost = \trim((string)($data['public_host'] ?? ''));
            if ($publicHost === '') {
                return null;
            }
            $port = isset($data['main_port']) ? (int)$data['main_port'] : 0;
            $scheme = 'https';

            return $this->formatOrigin($scheme, $publicHost, $port > 0 ? $port : null);
        } catch (\Throwable $e) {
            Env::log('theme_preview', 'install_origin_instance_read_failed: ' . $e->getMessage());

            return null;
        }
    }

    private function formatOrigin(string $scheme, string $host, ?int $port): string
    {
        $scheme = \strtolower(\trim($scheme));
        $host = \strtolower(\trim($host));
        if ($scheme === '' || $host === '') {
            return '';
        }
        $authority = $host;
        if ($port !== null && $port > 0) {
            $isDefault = ($scheme === 'https' && $port === 443)
                || ($scheme === 'http' && $port === 80);
            if (!$isDefault) {
                $authority .= ':' . $port;
            }
        }

        return $scheme . '://' . $authority;
    }

    private function hostFromOrigin(string $origin): string
    {
        $parsed = \parse_url($origin);
        if (!\is_array($parsed) || empty($parsed['host'])) {
            return '';
        }

        return \strtolower((string)$parsed['host']);
    }

    private function hostsMatch(string $a, string $b): bool
    {
        $a = \strtolower(\trim($a));
        $b = \strtolower(\trim($b));

        return $a !== '' && $a === $b;
    }
}
