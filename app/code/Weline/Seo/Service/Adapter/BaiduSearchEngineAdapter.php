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
        $token = trim((string)($config['token'] ?? $config['api_key'] ?? ''));
        $site = trim((string)($config['site'] ?? $config['site_url'] ?? ''));
        if ($token === '' || !SubmissionResult::validUrl($site)) {
            return SubmissionResult::failure(__('请配置百度 Token 和已验证站点 URL'), 'not_configured');
        }
        if (strtolower((string)($options['action'] ?? '')) === 'delete') {
            return SubmissionResult::failure(__('百度普通链接提交不提供删除接口，请在资源平台处理死链'), 'unsupported');
        }
        $urls = SubmissionResult::urls($urls);
        foreach ($urls as $url) {
            if (!SubmissionResult::belongsToSite($url, $site)) { return SubmissionResult::failure(__('百度 URL 必须属于已配置站点')); }
        }
        $submitted = $rejected = $errors = [];
        $remain = null;
        foreach (array_chunk($urls, 2000) as $chunk) {
            $result = $this->submitBatch($chunk, $site, $token, !empty($config['use_fast_push']));
            $remain = $result['remain'];
            $rejectedChunk = array_values(array_intersect($chunk, $result['rejected_urls']));
            $acceptedChunk = array_values(array_diff($chunk, $rejectedChunk));
            // Baidu returns a count, not an accepted list. Never guess which URL succeeded.
            if ($result['success_count'] === count($acceptedChunk)) { array_push($submitted, ...$acceptedChunk); }
            elseif ($result['success_count'] > 0) {
                return [
                    'success' => false, 'accepted' => true, 'status' => 'partial',
                    'message' => __('百度部分接收 %{1} 个 URL，无法确定其余 URL；请在资源平台核对后再提交', $result['success_count'] + count($submitted)),
                    'data' => ['submitted_urls' => $result['success_count'] + count($submitted), 'remain' => $remain, 'rejected_urls' => $rejectedChunk, 'acceptance_unknown' => true],
                ];
            } else { $rejectedChunk = $chunk; }
            array_push($rejected, ...$rejectedChunk);
            if ($result['error'] !== '') { $errors[] = $result['error']; }
        }
        return SubmissionResult::complete(count($urls), $submitted, [], $rejected, $errors, ['remain' => $remain, 'total_success' => count($submitted)]);
    }

    public function submitSitemap(string $sitemapUrl, array $options = []): array
    {
        return SubmissionResult::failure(__('百度未提供公开 Sitemap 提交 API，请在搜索资源平台提交 Sitemap；链接 API 仅推送页面 URL'), 'unsupported');
    }

    public function getRequirements(): array
    {
        return [
            'token' => __('百度站长平台 API Token'),
            'site' => __('已在百度站长平台验证的站点 URL'),
        ];
    }

    public function getAccountConfigFields(): array
    {
        return [
            [
                'key' => 'token',
                'label' => (string)__('百度站长平台 Token'),
                'type' => 'password',
                'required' => true,
                'placeholder' => 'xxxxx',
                'hint' => (string)__('在百度搜索资源平台「链接提交 → 主动推送」接口地址中的 token 参数'),
            ],
            [
                'key' => 'site',
                'label' => (string)__('已验证站点 URL'),
                'type' => 'website_url',
                'required' => true,
                'placeholder' => 'https://www.example.com',
                'hint' => (string)__('从网站列表选择；须与百度站长平台已验证站点一致'),
            ],
            [
                'key' => 'use_fast_push',
                'label' => (string)__('启用快速收录（需配额）'),
                'type' => 'checkbox',
                'required' => false,
                'hint' => (string)__('开启后走百度快速收录接口；无配额时请关闭'),
            ],
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

        $result = json_decode((string)$response, true);
        if ($error || $httpCode < 200 || $httpCode >= 300 || !is_array($result) || isset($result['error'])) {
            return ['success_count' => 0, 'remain' => null, 'rejected_urls' => $urls,
                'error' => __('百度请求失败（HTTP %{1}），请检查 Token、站点权限与配额', $httpCode)];
        }
        $rejected = array_merge((array)($result['not_valid'] ?? []), (array)($result['not_same_site'] ?? []));
        return [
            'success_count' => max(0, min(count($urls), (int)($result[$useFastPush ? 'success_daily' : 'success'] ?? $result['success'] ?? 0))),
            'remain' => isset($result['remain_daily']) ? (int)$result['remain_daily'] : (isset($result['remain']) ? (int)$result['remain'] : null),
            'rejected_urls' => $rejected,
            'error' => $rejected === [] ? '' : __('百度拒绝部分 URL（无效 URL 或不属于该站点）'),
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
