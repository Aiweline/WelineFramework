<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

/**
 * SEO-facing website directory.
 *
 * Weline_Seo reads website data through the published websites query provider
 * instead of depending on Weline_Websites model classes.
 */
class SeoWebsiteDirectory
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listWebsites(): array
    {
        try {
            $rows = w_query('websites', 'getWebsiteList', []);
        } catch (\Throwable) {
            return [];
        }

        $websites = [];
        foreach ($this->unwrapRows($rows) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $website = $this->normalizeWebsite($row);
            if ($this->hasWebsiteIdentity($row) && (int)$website['website_id'] >= 0) {
                $websites[] = $website;
            }
        }

        return $websites;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getWebsiteById(int $websiteId): ?array
    {
        if ($websiteId < 0) {
            return null;
        }

        try {
            $row = w_query('websites', 'getWebsiteById', ['website_id' => $websiteId]);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($row)) {
            return null;
        }

        $row = $this->unwrapRow($row);
        if (!$this->hasWebsiteIdentity($row)) {
            return null;
        }

        $website = $this->normalizeWebsite($row);
        return (int)$website['website_id'] >= 0 ? $website : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchWebsiteByUrl(string $url): ?array
    {
        $websites = $this->matchWebsitesByUrl($url);
        return $websites[0] ?? null;
    }

    /**
     * Match every website that can own the URL.
     *
     * Multiple websites may share a host/path shape, and business entities such
     * as products can intentionally exist in more than one website. Callers that
     * handle URL assets must not collapse matches to a single website.
     *
     * @return list<array<string, mixed>>
     */
    public function matchWebsitesByUrl(string $url): array
    {
        $urlParts = $this->urlParts($url);
        if ($urlParts['host'] === '') {
            return [];
        }

        $matches = [];
        foreach ($this->listWebsites() as $website) {
            $websiteParts = $this->urlParts((string)($website['url'] ?? ''));
            if ($websiteParts['host'] === '' || strcasecmp($websiteParts['host'], $urlParts['host']) !== 0) {
                continue;
            }
            if (
                $websiteParts['port'] !== ''
                && $urlParts['port'] !== ''
                && $websiteParts['port'] !== $urlParts['port']
            ) {
                continue;
            }
            if (!$this->pathOwnsUrl($websiteParts['path'], $urlParts['path'])) {
                continue;
            }

            $matches[] = $website + ['_seo_match_path_length' => strlen($websiteParts['path'])];
        }

        usort(
            $matches,
            static fn (array $a, array $b): int => ((int)($b['_seo_match_path_length'] ?? 0))
                <=> ((int)($a['_seo_match_path_length'] ?? 0))
        );

        return array_map(static function (array $website): array {
            unset($website['_seo_match_path_length']);
            return $website;
        }, $matches);
    }

    /**
     * @return array<string, mixed>
     */
    public function currentWebsite(): array
    {
        $websiteId = (int)w_env('website_id', 0);
        if ($websiteId >= 0) {
            $website = $this->getWebsiteById($websiteId);
            if ($website !== null) {
                return $this->withCurrentRequestUrl($website);
            }
        }

        $requestBaseUrl = $this->currentRequestBaseUrl();
        if ($requestBaseUrl !== '') {
            $website = $this->matchWebsiteByUrl($requestBaseUrl);
            if ($website !== null) {
                return $this->withCurrentRequestUrl($website);
            }
        }

        $websites = $this->listWebsites();
        if ($websites !== []) {
            return $this->withCurrentRequestUrl($websites[0]);
        }

        return [
            'website_id' => 0,
            'code' => 'default',
            'name' => 'Weline',
            'url' => $this->currentBaseUrl(),
            'scope' => '',
        ];
    }

    public function currentBaseUrl(): string
    {
        $requestBaseUrl = $this->currentRequestBaseUrl();
        if ($requestBaseUrl !== '') {
            return $requestBaseUrl;
        }

        return rtrim((string)($_SERVER['WELINE_WEBSITE_URL'] ?? w_env('website_url', '') ?: w_env('website.url', '')), '/');
    }

    /**
     * Public base URL for sitemap/robots display and generation.
     *
     * Real configured website URLs stay authoritative. The system default website
     * keeps `http://localhost` / `127.0.0.1` as a DB placeholder; when that
     * placeholder is present, prefer the live request origin so admins see the
     * current project entry such as `https://p{hash}.weline.test:{port}`.
     *
     * @param array<string, mixed> $website
     */
    public function effectivePublicBaseUrl(array $website): string
    {
        $configured = rtrim(trim((string)($website['url'] ?? '')), '/');
        $requestBaseUrl = $this->currentBaseUrl();
        $websiteId = (int)($website['website_id'] ?? $website['id'] ?? -1);
        $code = trim((string)($website['code'] ?? ''));
        $isDefaultWebsite = $websiteId === 0 || $code === 'default';
        $needsLiveOrigin = $configured === ''
            || ($isDefaultWebsite && $this->isLoopbackBaseUrl($configured));

        if ($needsLiveOrigin && $requestBaseUrl !== '') {
            return $requestBaseUrl;
        }

        return $configured;
    }

    /**
     * Every public origin that should expose a sitemap for this website.
     *
     * Includes the canonical `Website.url` (via {@see effectivePublicBaseUrl()})
     * plus every active `WebsiteDomain` binding. Deduped by scheme+host+port+path.
     * Canonical generation / same-origin checks still use the primary origin only;
     * this list is for admin display and robots Sitemap declarations.
     *
     * @param array<string, mixed> $website
     * @return list<array{
     *   base_url:string,
     *   domain:string,
     *   sub_path:string,
     *   is_primary:bool,
     *   is_canonical:bool,
     *   source:string,
     *   sitemap_url:string
     * }>
     */
    public function listPublicOrigins(array $website): array
    {
        $canonicalBase = rtrim($this->effectivePublicBaseUrl($website), '/');
        $origins = [];
        $seen = [];

        $append = static function (
            string $baseUrl,
            string $domain,
            string $subPath,
            bool $isPrimary,
            bool $isCanonical,
            string $source,
        ) use (&$origins, &$seen): void {
            $baseUrl = rtrim(trim($baseUrl), '/');
            if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) {
                return;
            }
            $parts = parse_url($baseUrl);
            if (
                !is_array($parts)
                || trim((string)($parts['host'] ?? '')) === ''
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['query'])
                || isset($parts['fragment'])
            ) {
                return;
            }
            $host = strtolower((string)$parts['host']);
            $port = isset($parts['port']) ? (string)$parts['port'] : '';
            $path = '/' . trim((string)($parts['path'] ?? ''), '/');
            if ($path === '/') {
                $path = '';
            }
            $identity = strtolower((string)($parts['scheme'] ?? 'https')) . '|' . $host . '|' . $port . '|' . $path;
            if (isset($seen[$identity])) {
                if ($isPrimary && !$origins[$seen[$identity]]['is_primary']) {
                    $origins[$seen[$identity]]['is_primary'] = true;
                }
                if ($isCanonical) {
                    $origins[$seen[$identity]]['is_canonical'] = true;
                    $origins[$seen[$identity]]['source'] = 'website_url';
                }
                return;
            }
            $seen[$identity] = count($origins);
            $origins[] = [
                'base_url' => $baseUrl,
                'domain' => $domain !== '' ? $domain : $host,
                'sub_path' => $path,
                'is_primary' => $isPrimary,
                'is_canonical' => $isCanonical,
                'source' => $source,
                'sitemap_url' => $baseUrl . '/sitemap.xml',
            ];
        };

        if ($canonicalBase !== '') {
            $canonicalHost = strtolower((string)(parse_url($canonicalBase, PHP_URL_HOST) ?: ''));
            $append($canonicalBase, $canonicalHost, '', true, true, 'website_url');
        }

        $websiteId = (int)($website['website_id'] ?? $website['id'] ?? -1);
        if ($websiteId >= 0) {
            try {
                $rows = w_query('websites', 'getWebsiteDomains', ['website_id' => $websiteId]);
            } catch (\Throwable) {
                $rows = [];
            }
            foreach ($this->unwrapRows($rows) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $baseUrl = rtrim(trim((string)($row['base_url'] ?? '')), '/');
                $domain = strtolower(trim((string)($row['domain'] ?? '')));
                $subPath = trim((string)($row['sub_path'] ?? ''));
                $isPrimary = !empty($row['is_primary']);
                if ($baseUrl === '' && $domain !== '') {
                    $scheme = !empty($row['https_enabled']) ? 'https' : 'http';
                    $baseUrl = $scheme . '://' . $domain . ($subPath !== '' ? '/' . ltrim($subPath, '/') : '');
                }
                $append($baseUrl, $domain, $subPath, $isPrimary, false, 'website_domain');
            }
        }

        usort($origins, static function (array $left, array $right): int {
            if (($left['is_canonical'] ?? false) !== ($right['is_canonical'] ?? false)) {
                return ($left['is_canonical'] ?? false) ? -1 : 1;
            }
            if (($left['is_primary'] ?? false) !== ($right['is_primary'] ?? false)) {
                return ($left['is_primary'] ?? false) ? -1 : 1;
            }
            return strcmp((string)($left['domain'] ?? ''), (string)($right['domain'] ?? ''));
        });

        return $origins;
    }

    /**
     * Replace a loopback origin with the live public base while keeping path.
     * Non-loopback URLs are returned unchanged.
     */
    public function rewriteLoopbackPublicUrl(string $url, string $publicBaseUrl): string
    {
        $url = trim($url);
        $publicBaseUrl = rtrim(trim($publicBaseUrl), '/');
        if ($url === '' || $publicBaseUrl === '' || !$this->isLoopbackBaseUrl($url)) {
            return $url;
        }
        if ($this->isLoopbackBaseUrl($publicBaseUrl)) {
            return $url;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }
        $path = (string)($parts['path'] ?? '');
        if ($path === '') {
            $path = '/';
        }

        return $publicBaseUrl . ($path === '/' ? '' : $path);
    }

    /**
     * Rewrite loopback <loc> origins inside sitemap XML to the live public base.
     * Used when serving files that were generated under the localhost placeholder.
     */
    public function rewriteLoopbackOriginsInXml(string $xml, string $publicBaseUrl): string
    {
        $publicBaseUrl = rtrim(trim($publicBaseUrl), '/');
        if ($xml === '' || $publicBaseUrl === '' || $this->isLoopbackBaseUrl($publicBaseUrl)) {
            return $xml;
        }

        $rewritten = preg_replace_callback(
            '#(<loc>)([^<]*)(</loc>)#i',
            function (array $matches) use ($publicBaseUrl): string {
                $loc = html_entity_decode(trim($matches[2]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $next = $this->rewriteLoopbackPublicUrl($loc, $publicBaseUrl);
                return $matches[1]
                    . htmlspecialchars($next, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . $matches[3];
            },
            $xml,
        );

        return is_string($rewritten) ? $rewritten : $xml;
    }

    public function isLoopbackBaseUrl(string $url): bool
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return false;
        }

        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || $host === '[::1]'
            || str_ends_with($host, '.localhost');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function normalizeWebsite(array $row): array
    {
        $websiteId = (int)($row['website_id'] ?? $row['id'] ?? 0);
        $code = trim((string)($row['code'] ?? ''));
        if ($code === '') {
            $code = $websiteId > 0 ? 'website_' . $websiteId : 'default';
        }

        $url = rtrim((string)($row['url'] ?? ''), '/');
        $domain = trim((string)($row['domain'] ?? ''));
        if ($domain === '') {
            $domain = (string)(parse_url($url, PHP_URL_HOST) ?: '');
        }

        return [
            'website_id' => $websiteId,
            'id' => $websiteId,
            'name' => (string)($row['name'] ?? ($websiteId > 0 ? 'Website ' . $websiteId : 'Weline')),
            'code' => $code,
            'url' => $url,
            'domain' => $domain,
            'scope' => (string)($row['scope'] ?? ''),
            'is_default' => (int)($row['is_default'] ?? 0),
        ];
    }

    /**
     * @param mixed $rows
     * @return list<array<string, mixed>>
     */
    private function unwrapRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        foreach (['items', 'data', 'rows', 'list'] as $key) {
            if (isset($rows[$key]) && is_array($rows[$key])) {
                $rows = $rows[$key];
                break;
            }
        }

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = $this->unwrapRow($row);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function unwrapRow(array $row): array
    {
        foreach (['item', 'data', 'row'] as $key) {
            if (isset($row[$key]) && is_array($row[$key])) {
                return $row[$key];
            }
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function hasWebsiteIdentity(array $row): bool
    {
        return \array_key_exists('website_id', $row) || \array_key_exists('id', $row);
    }

    /**
     * @param array<string, mixed> $website
     * @return array<string, mixed>
     */
    private function withCurrentRequestUrl(array $website): array
    {
        $requestBaseUrl = $this->currentRequestBaseUrl();
        if ($requestBaseUrl !== '') {
            $website['url'] = $requestBaseUrl;
        } elseif ((string)($website['url'] ?? '') === '') {
            $website['url'] = $this->currentBaseUrl();
        }

        return $website;
    }

    private function currentRequestBaseUrl(): string
    {
        $fromRequestHost = $this->originFromRequestHost();

        $websiteUrl = (string)($_SERVER['WELINE_WEBSITE_URL'] ?? w_env('website_url', ''));
        if ($websiteUrl !== '' && preg_match('/^https?:\/\//i', $websiteUrl)) {
            $parts = parse_url($websiteUrl);
            if (is_array($parts) && !empty($parts['host'])) {
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                $fromWebsiteUrl = (string)($parts['scheme'] ?? 'https') . '://' . (string)$parts['host'] . $port;
                // DB/default placeholder `http://localhost` must not hide the live
                // project host (e.g. https://p0cc9fac7.weline.test:9513).
                if (!$this->isLoopbackBaseUrl($fromWebsiteUrl) || $fromRequestHost === '' || $this->isLoopbackBaseUrl($fromRequestHost)) {
                    return $fromWebsiteUrl;
                }
            }
        }

        $fullUrl = (string)($_SERVER['WELINE_FULL_REQUEST_URI'] ?? w_env('full_request_uri', ''));
        if ($fullUrl !== '' && preg_match('/^https?:\/\//i', $fullUrl)) {
            $parts = parse_url($fullUrl);
            if (is_array($parts) && !empty($parts['host'])) {
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                $fromFullUrl = (string)($parts['scheme'] ?? 'https') . '://' . (string)$parts['host'] . $port;
                if (!$this->isLoopbackBaseUrl($fromFullUrl) || $fromRequestHost === '' || $this->isLoopbackBaseUrl($fromRequestHost)) {
                    return $fromFullUrl;
                }
            }
        }

        return $fromRequestHost;
    }

    private function originFromRequestHost(): string
    {
        $scheme = (string)($_SERVER['REQUEST_SCHEME'] ?? w_env('request.scheme', ''));
        if ($scheme === '') {
            $https = (string)($_SERVER['HTTPS'] ?? w_env('server.https', ''));
            $forwardedProto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
            $scheme = $https !== '' && strtolower($https) !== 'off' ? 'https' : 'http';
            if ($forwardedProto !== '') {
                $scheme = strtolower(explode(',', $forwardedProto)[0]) === 'https' ? 'https' : $scheme;
            }
        }

        $host = $this->currentHost(false);
        $host = $this->withCurrentPort($host, $scheme);
        return $host !== '' ? $scheme . '://' . $host : '';
    }

    private function currentHost(bool $stripPort = true): string
    {
        $host = (string)(
            $_SERVER['HTTP_HOST']
            ?? $_SERVER['SERVER_NAME']
            ?? w_env('server.http_host', '')
            ?: w_env('request.host', '')
        );
        if ($host === '') {
            return '';
        }

        return $stripPort ? (preg_replace('/:\d+$/', '', $host) ?: $host) : $host;
    }

    private function withCurrentPort(string $host, string $scheme): string
    {
        if ($host === '' || preg_match('/:\d+$/', $host)) {
            return $host;
        }

        $port = (string)(
            $_SERVER['HTTP_X_FORWARDED_PORT']
            ?? $_SERVER['SERVER_PORT']
            ?? w_env('server.server_port', '')
            ?? w_env('server.port', '')
            ?: w_env('request.port', '')
        );
        if ($port === '' || !ctype_digit($port)) {
            return $host;
        }

        if (($scheme === 'http' && $port === '80') || ($scheme === 'https' && $port === '443')) {
            return $host;
        }

        return $host . ':' . $port;
    }

    private function hostFromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $host = (string)(parse_url($url, PHP_URL_HOST) ?: '');
        return strtolower(preg_replace('/^www\./i', '', $host) ?: $host);
    }

    /**
     * @return array{host:string,port:string,path:string}
     */
    private function urlParts(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['host' => '', 'port' => '', 'path' => '/'];
        }
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return ['host' => '', 'port' => '', 'path' => '/'];
        }

        $host = strtolower(preg_replace('/^www\./i', '', (string)($parts['host'] ?? '')) ?: (string)($parts['host'] ?? ''));
        $path = '/' . trim((string)($parts['path'] ?? ''), '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return [
            'host' => $host,
            'port' => isset($parts['port']) ? (string)$parts['port'] : '',
            'path' => $path,
        ];
    }

    private function pathOwnsUrl(string $basePath, string $urlPath): bool
    {
        $basePath = '/' . trim($basePath, '/');
        $urlPath = '/' . trim($urlPath, '/');
        if ($basePath === '/') {
            return true;
        }

        return $urlPath === $basePath || str_starts_with($urlPath, $basePath . '/');
    }
}
