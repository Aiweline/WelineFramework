<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Seo\Service\Adapter\SubmissionResult;

/** Account form boundary: write-only credentials and local configuration validation. */
final class SeoAccountConfig
{
    /**
     * Normalize Google Search Console property for API calls.
     * https://www.example.com/ → sc-domain:example.com
     */
    public static function normalizeGoogleSiteProperty(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^sc-domain:\s*(.+)$/i', $value, $matches) === 1) {
            return 'sc-domain:' . self::normalizeGoogleHost((string)$matches[1]);
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            $host = (string)(parse_url($value, PHP_URL_HOST) ?: '');
            if ($host === '') {
                return $value;
            }

            return 'sc-domain:' . self::normalizeGoogleHost($host);
        }

        if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $value) === 1) {
            return 'sc-domain:' . self::normalizeGoogleHost($value);
        }

        return $value;
    }

    private static function normalizeGoogleHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host;
    }

    public function isSensitive(array $field): bool
    {
        return !empty($field['sensitive']) || ($field['type'] ?? '') === 'password'
            || in_array($field['key'] ?? '', ['service_account', 'private_key', 'token', 'api_key', 'indexnow_key', 'access_token', 'refresh_token'], true);
    }

    public function merge(array $existing, array $posted, array $fields): array
    {
        $result = $existing;
        foreach ($fields as $field) {
            $key = (string)($field['key'] ?? '');
            if ($key === '' || !array_key_exists($key, $posted)) { continue; }
            $value = $posted[$key];
            if ($this->isSensitive($field) && ($value === '' || $value === null || $value === [])) { continue; }
            $result[$key] = $value;
        }
        return $result;
    }

    public function forDisplay(array $config, array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            $key = (string)($field['key'] ?? '');
            if ($key !== '' && !$this->isSensitive($field) && array_key_exists($key, $config)) { $result[$key] = $config[$key]; }
        }
        return $result;
    }

    public function configuredSecrets(array $config, array $fields): array
    {
        $keys = [];
        foreach ($fields as $field) {
            $key = (string)($field['key'] ?? '');
            if ($this->isSensitive($field) && !empty($config[$key])) { $keys[] = $key; }
        }
        return $keys;
    }

    /** Returns field errors only; never includes supplied credential values. */
    public function validate(string $platform, array $config, array $fields): array
    {
        $errors = [];
        foreach ($fields as $field) {
            $key = (string)($field['key'] ?? '');
            $value = $config[$key] ?? null;
            if (!empty($field['required']) && ($value === null || $value === '' || $value === [])) {
                $errors[] = __('请填写：%{1}', (string)($field['label'] ?? $key));
            }
            if (in_array($field['type'] ?? '', ['url', 'website_url'], true) && $value !== null && $value !== ''
                && (!is_string($value) || !SubmissionResult::validUrl($value))) {
                $errors[] = __('URL 格式无效：%{1}', (string)($field['label'] ?? $key));
            }
        }
        if ($platform === 'google' || $platform === 'google_search_console' || $platform === 'google_indexing_api') {
            if (isset($config['site_url']) && is_string($config['site_url'])) {
                $config['site_url'] = self::normalizeGoogleSiteProperty($config['site_url']);
            }
            $credentials = $config['service_account'] ?? [];
            if (is_string($credentials)) { $credentials = json_decode($credentials, true); }
            if (!is_array($credentials) || ($credentials['type'] ?? '') !== 'service_account'
                || !filter_var($credentials['client_email'] ?? '', FILTER_VALIDATE_EMAIL)
                || !is_string($credentials['private_key'] ?? null)
                || !@openssl_pkey_get_private($credentials['private_key'])) {
                $errors[] = __('Google Service Account JSON 或私钥无效');
            }
            $site = (string)($config['site_url'] ?? '');
            if (!SubmissionResult::validUrl($site) && !preg_match('/^sc-domain:[a-z0-9.-]+$/i', $site)) {
                $errors[] = __('请填写 Search Console 已验证的 URL 或 sc-domain 站点属性');
            }
        }
        $indexNow = !empty($config['use_indexnow']) || (!in_array($platform, ['google', 'bing', 'baidu'], true) && isset($config['indexnow_key']));
        if ($platform === 'bing' && !$indexNow && empty($config['api_key'])) { $errors[] = __('请填写 Bing Webmaster API Key'); }
        if ($indexNow) {
            if (!preg_match('/^[a-zA-Z0-9-]{8,128}$/', (string)($config['indexnow_key'] ?? ''))) { $errors[] = __('IndexNow Key 必须为 8-128 位字母、数字或连字符'); }
            $location = (string)($config['key_location'] ?? '');
            if ($location !== '' && !SubmissionResult::validUrl($location)) { $errors[] = __('IndexNow Key 文件 URL 无效'); }
            if (!empty($config['site_url']) && $location !== '' && strtolower((string)parse_url($location, PHP_URL_HOST)) !== strtolower((string)parse_url($config['site_url'], PHP_URL_HOST))) {
                $errors[] = __('IndexNow Key 文件必须与已配置站点同主机');
            }
        }
        return array_values(array_unique($errors));
    }
}
