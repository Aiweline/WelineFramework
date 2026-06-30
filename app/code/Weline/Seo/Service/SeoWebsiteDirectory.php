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
        $host = $this->hostFromUrl($url);
        if ($host === '') {
            return null;
        }

        foreach ($this->listWebsites() as $website) {
            $websiteHost = $this->hostFromUrl((string)($website['url'] ?? ''));
            if ($websiteHost !== '' && strcasecmp($websiteHost, $host) === 0) {
                return $website;
            }
        }

        return null;
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
        $fullUrl = (string)($_SERVER['WELINE_FULL_REQUEST_URI'] ?? '');
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
}
