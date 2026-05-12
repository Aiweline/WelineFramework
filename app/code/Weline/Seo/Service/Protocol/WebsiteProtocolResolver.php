<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Protocol;

use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;

class WebsiteProtocolResolver
{
    /**
     * @return array<string, mixed>
     */
    public function currentWebsite(): array
    {
        try {
            $websiteId = (int)w_env('website_id', 0);
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            if ($websiteId > 0) {
                $loaded = $website->reset()->load($websiteId);
                if ($loaded->getId()) {
                    return $this->normalizeWebsite($loaded->getData());
                }
            }

            $websites = $website->reset()->select()->fetchArray();
            $host = $this->currentHost();
            if ($host !== '') {
                foreach ($websites as $row) {
                    $siteHost = (string)parse_url((string)($row[Website::schema_fields_URL] ?? ''), PHP_URL_HOST);
                    if ($siteHost !== '' && strcasecmp($siteHost, $host) === 0) {
                        return $this->normalizeWebsite($row);
                    }
                }
            }

            if (!empty($websites[0])) {
                return $this->normalizeWebsite($websites[0]);
            }
        } catch (\Throwable) {
        }

        return [
            'website_id' => 0,
            'code' => 'default',
            'name' => 'Weline',
            'url' => $this->currentBaseUrl(),
        ];
    }

    public function currentBaseUrl(): string
    {
        $requestBaseUrl = $this->currentRequestBaseUrl();
        if ($requestBaseUrl !== '') {
            return $requestBaseUrl;
        }

        $url = rtrim((string)($_SERVER['WELINE_WEBSITE_URL'] ?? w_env('website_url', '') ?: w_env('website.url', '')), '/');
        return $url;
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
        if ($host !== '') {
            return $stripPort ? (preg_replace('/:\d+$/', '', $host) ?: $host) : $host;
        }
        return '';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeWebsite(array $row): array
    {
        $url = rtrim((string)($row[Website::schema_fields_URL] ?? $row['url'] ?? ''), '/');
        $requestBaseUrl = $this->currentRequestBaseUrl();
        if ($requestBaseUrl !== '') {
            $url = $requestBaseUrl;
        } elseif ($url === '') {
            $url = $this->currentBaseUrl();
        }

        return [
            'website_id' => (int)($row[Website::schema_fields_ID] ?? $row['website_id'] ?? $row['id'] ?? 0),
            'code' => (string)($row[Website::schema_fields_CODE] ?? $row['code'] ?? 'default'),
            'name' => (string)($row[Website::schema_fields_NAME] ?? $row['name'] ?? 'Weline'),
            'url' => $url,
        ];
    }
}
