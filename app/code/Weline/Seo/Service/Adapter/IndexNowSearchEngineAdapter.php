<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Service\Adapter;

use Weline\Seo\Interface\SearchEngineAdapterInterface;

class IndexNowSearchEngineAdapter implements SearchEngineAdapterInterface
{
    private const INDEX_NOW_URL = 'https://api.indexnow.org/indexnow';

    public function getCode(): string
    {
        return 'indexnow';
    }

    public function getLabel(): string
    {
        return 'IndexNow';
    }

    public function pushUrls(array $urls, array $options = []): array
    {
        $config = $this->resolveConfig($options);
        $key = trim((string)($config['indexnow_key'] ?? $config['key'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9-]{8,128}$/', $key)) {
            return SubmissionResult::failure(__('请配置有效的 IndexNow Key（8-128 位字母、数字或连字符）'), 'not_configured');
        }
        $urls = SubmissionResult::urls($urls);
        if ($urls === []) { return SubmissionResult::failure(__('URL 列表为空')); }
        $host = strtolower((string)(parse_url($urls[0], PHP_URL_HOST) ?: ''));
        $keyLocation = trim((string)($config['key_location'] ?? $config['keyLocation'] ?? ''));
        if ($keyLocation !== '' && (!SubmissionResult::validUrl($keyLocation) || strtolower((string)parse_url($keyLocation, PHP_URL_HOST)) !== $host)) {
            return SubmissionResult::failure(__('IndexNow Key 文件必须与提交 URL 同主机'), 'not_configured');
        }
        foreach ($urls as $url) {
            if (!SubmissionResult::validUrl($url) || strtolower((string)parse_url($url, PHP_URL_HOST)) !== $host
                || (!empty($config['site_url']) && !SubmissionResult::belongsToSite($url, (string)$config['site_url']))) {
                return SubmissionResult::failure(__('IndexNow URL 必须属于同一已配置站点'));
            }
            if ($keyLocation !== '') {
                $keyDirectory = rtrim(str_replace('\\', '/', dirname((string)parse_url($keyLocation, PHP_URL_PATH))), '/');
                if ($keyDirectory !== '' && $keyDirectory !== '.' && !str_starts_with((string)parse_url($url, PHP_URL_PATH), $keyDirectory . '/')) {
                    return SubmissionResult::failure(__('IndexNow URL 不在 Key 文件验证范围内'));
                }
            }
        }
        $endpoint = trim((string)($config['indexnow_endpoint'] ?? '')) ?: self::INDEX_NOW_URL;
        if (!SubmissionResult::validUrl($endpoint) || strtolower((string)parse_url($endpoint, PHP_URL_SCHEME)) !== 'https') {
            return SubmissionResult::failure(__('IndexNow 端点必须为 HTTPS URL'), 'not_configured');
        }
        $submitted = $pending = $rejected = $errors = [];
        foreach (array_chunk($urls, 10000) as $chunk) {
            $payload = ['host' => $host, 'key' => $key, 'urlList' => $chunk];
            if ($keyLocation !== '') { $payload['keyLocation'] = $keyLocation; }
            $response = $this->httpRequest($endpoint, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $httpCode = (int)($response['http_code'] ?? 0);
            if (empty($response['error']) && $httpCode === 200) { array_push($submitted, ...$chunk); }
            elseif (empty($response['error']) && $httpCode === 202) { array_push($pending, ...$chunk); }
            else {
                array_push($rejected, ...$chunk);
                $errors[] = __('IndexNow 请求失败（HTTP %{1}）', $httpCode);
            }
        }
        return SubmissionResult::complete(count($urls), $submitted, $pending, $rejected, $errors);
    }

    public function submitSitemap(string $sitemapUrl, array $options = []): array
    {
        return SubmissionResult::failure(__('IndexNow 仅通知页面 URL 变更；Sitemap 请通过 robots.txt 或站长平台提交'), 'unsupported');
    }

    public function getRequirements(): array
    {
        return [
            'indexnow_key' => __('IndexNow Key'),
            'key_location' => __('可公开访问的 IndexNow Key 文件地址'),
        ];
    }

    public function getAccountConfigFields(): array
    {
        return [
            [
                'key' => 'indexnow_key',
                'label' => (string)__('IndexNow Key'),
                'type' => 'password',
                'required' => true,
                'placeholder' => '8-128 位密钥',
                'hint' => (string)__('网站根目录 Key 文件内容须与此一致'),
            ],
            [
                'key' => 'key_location',
                'label' => (string)__('Key 文件公开地址'),
                'type' => 'url',
                'required' => true,
                'placeholder' => 'https://example.com/your-indexnow-key.txt',
            ],
            [
                'key' => 'indexnow_endpoint',
                'label' => (string)__('IndexNow Endpoint（可选）'),
                'type' => 'url',
                'required' => false,
                'placeholder' => 'https://api.indexnow.org/indexnow',
                'hint' => (string)__('留空则使用平台默认 IndexNow 端点'),
            ],
        ];
    }

    public function isConfigured(): bool
    {
        return true;
    }

    private function resolveConfig(array $options): array
    {
        $config = $options['config'] ?? [];
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }

        if (empty($config)) {
            $config = $options;
        }

        return is_array($config) ? $config : [];
    }

    private function httpRequest(string $endpoint, string $body): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
            ],
            CURLOPT_USERAGENT => 'Weline SEO IndexNow Pusher/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'http_code' => $httpCode,
            'body' => $response ?: '',
            'error' => $error,
        ];
    }
}
