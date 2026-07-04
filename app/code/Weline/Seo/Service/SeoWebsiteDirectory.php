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
            if ((int)$website['website_id'] > 0) {
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
        if ($websiteId <= 0) {
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

        $website = $this->normalizeWebsite($this->unwrapRow($row));
        return (int)$website['website_id'] > 0 ? $website : null;
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
        if ($websiteId > 0) {
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
        $websiteUrl = (string)($_SERVER['WELINE_WEBSITE_URL'] ?? w_env('website_url', ''));
        if ($websiteUrl !== '' && preg_match('/^https?:\/\//i', $websiteUrl)) {
            $parts = parse_url($websiteUrl);
            if (is_array($parts) && !empty($parts['host'])) {
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                return (string)($parts['scheme'] ?? 'https') . '://' . (string)$parts['host'] . $port;
            }
        }

        $fullUrl = (string)($_SERVER['WELINE_FULL_REQUEST_URI'] ?? w_env('full_request_uri', ''));
        if ($fullUrl !== '' && preg_match('/^https?:\/\//i', $fullUrl)) {
            $parts = parse_url($fullUrl);
            if (is_array($parts) && !empty($parts['host'])) {
                $port = isset($parts['port']) ? ':' . $parts['port'] : '';
                return (string)($parts['scheme'] ?? 'https') . '://' . (string)$parts['host'] . $port;
            }
        }

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
