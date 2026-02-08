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

/**
 * 百度站长平台 SearchEngine 适配器
 *
 * 支持两种提交方式：
 * 1. 普通收录 API（每日有配额限制）
 * 2. 快速收录 API（需额外配额，通过 use_fast_push 开启）
 *
 * 配置字段说明：
 * - token: 百度站长平台 API Token
 * - site: 已验证的站点域名（如 https://www.example.com）
 * - use_fast_push: 是否使用快速收录（默认 false）
 *
 * @package Weline_Seo
 */
class BaiduSearchEngineAdapter implements SearchEngineAdapterInterface
{
    private const PUSH_API_URL = 'http://data.zz.baidu.com/urls';

    public function getCode(): string
    {
        return 'baidu_push_api';
    }

    public function getLabel(): string
    {
        return __('百度站长平台');
    }

    public function pushUrls(array $urls, array $options = []): array
    {
        $config = $this->resolveConfig($options);
        $token = $config['token'] ?? $config['api_key'] ?? '';
        $site = $config['site'] ?? $config['site_url'] ?? '';
        $useFastPush = !empty($config['use_fast_push']);

        if (empty($token)) {
            return [
                'success' => false,
                'message' => __('缺少百度站长平台 Token'),
            ];
        }

        if (empty($site) && !empty($urls[0])) {
            $parsed = parse_url((string)$urls[0]);
            $site = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
        }

        if (empty($site)) {
            return [
                'success' => false,
                'message' => __('缺少百度站点 URL'),
            ];
        }

        $filteredUrls = array_values(array_filter(array_map('trim', $urls)));
        if (empty($filteredUrls)) {
            return [
                'success' => false,
                'message' => __('URL 列表为空'),
            ];
        }

        // 百度每次最多提交 2000 条
        $chunks = array_chunk($filteredUrls, 2000);
        $totalSuccess = 0;
        $totalRemain = 0;
        $errors = [];

        foreach ($chunks as $chunk) {
            $result = $this->submitBatch($chunk, $site, $token, $useFastPush);
            $totalSuccess += $result['success_count'];
            $totalRemain = $result['remain'];
            if (!empty($result['error'])) {
                $errors[] = $result['error'];
            }
        }

        $type = $useFastPush ? __('快速收录') : __('普通收录');

        return [
            'success' => $totalSuccess > 0,
            'message' => $totalSuccess > 0
                ? __('已通过百度%{1}提交 %{2} 个 URL，剩余配额 %{3}', [$type, $totalSuccess, $totalRemain])
                : __('百度 URL 提交失败'),
            'data' => [
                'total_success' => $totalSuccess,
                'remain' => $totalRemain,
                'errors' => $errors,
            ],
        ];
    }

    public function submitSitemap(string $sitemapUrl, array $options = []): array
    {
        // 百度不支持直接提交 sitemap URL，使用链接提交 API 提交 sitemap URL 本身
        return $this->pushUrls([$sitemapUrl], $options);
    }

    public function getRequirements(): array
    {
        return [
            'token' => __('百度站长平台 API Token'),
            'site' => __('已在百度站长平台验证的站点 URL'),
        ];
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * 批量提交一组 URL 到百度
     */
    private function submitBatch(array $urls, string $site, string $token, bool $useFastPush): array
    {
        $apiUrl = self::PUSH_API_URL . '?site=' . urlencode($site) . '&token=' . urlencode($token);
        if ($useFastPush) {
            $apiUrl .= '&type=daily';
        }

        $postData = implode("\n", $urls);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/plain',
            ],
            CURLOPT_USERAGENT => 'Weline SEO URL Pusher/1.0',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'success_count' => 0,
                'remain' => 0,
                'error' => $error,
            ];
        }

        $result = json_decode($response, true);

        if (isset($result['error'])) {
            return [
                'success_count' => 0,
                'remain' => $result['remain'] ?? 0,
                'error' => ($result['message'] ?? '') . ' (' . ($result['error'] ?? '') . ')',
            ];
        }

        return [
            'success_count' => (int)($result['success'] ?? 0),
            'remain' => (int)($result['remain'] ?? 0),
            'error' => '',
        ];
    }

    /**
     * 从 options 中解析配置（兼容嵌套和平铺两种格式）
     */
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

        return $config;
    }
}
