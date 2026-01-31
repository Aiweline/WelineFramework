<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Adapter;

/**
 * 百度站长平台 Sitemap 适配器
 *
 * 百度平台规则：
 * - 最大 50,000 条 URL
 * - 最大 10 MB（比 Google/Bing 更小）
 * - 支持普通收录 API
 * - 支持快速收录 API（需要配额）
 *
 * @package Weline_Seo
 */
class BaiduSitemapAdapter extends AbstractSitemapPlatformAdapter
{
    /**
     * 百度规则常量（注意：百度文件大小限制更小）
     */
    public const MAX_URLS = 50000;
    public const MAX_SIZE = 10485760; // 10 MB（百度限制）
    public const PUSH_API_URL = 'http://data.zz.baidu.com/urls';
    public const FAST_PUSH_API_URL = 'http://data.zz.baidu.com/urls?type=daily';

    public function getPlatformCode(): string
    {
        return 'baidu';
    }

    public function getPlatformName(): string
    {
        return '百度';
    }

    public function getPlatformColor(): string
    {
        return '#2932E1';
    }

    public function getMaxUrlsPerFile(): int
    {
        return self::MAX_URLS;
    }

    public function getMaxFileSizeBytes(): int
    {
        return self::MAX_SIZE;
    }

    public function supportsAutoSubmit(): bool
    {
        return true;
    }

    /**
     * 提交 sitemap 到百度
     *
     * 百度不支持直接提交 sitemap URL，需要使用链接提交 API
     * 这里我们提交 sitemap 索引 URL 作为一条链接
     */
    public function submitSitemap(string $sitemapUrl, array $accountConfig): array
    {
        $token = $accountConfig['token'] ?? $accountConfig['api_key'] ?? '';
        $site = $accountConfig['site'] ?? $accountConfig['site_url'] ?? '';
        
        if (empty($token)) {
            return [
                'success' => false,
                'message' => __('缺少百度站长平台 Token'),
                'response' => null,
            ];
        }
        
        if (empty($site)) {
            // 从 sitemap URL 提取站点
            $parsed = parse_url($sitemapUrl);
            $site = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
        }
        
        // 使用快速收录还是普通收录
        $useFastPush = !empty($accountConfig['use_fast_push']);
        
        return $this->submitViaPushApi($sitemapUrl, $site, $token, $useFastPush);
    }

    /**
     * 通过百度链接提交 API 提交
     */
    protected function submitViaPushApi(
        string $sitemapUrl,
        string $site,
        string $token,
        bool $useFastPush = false
    ): array {
        $apiUrl = $useFastPush ? self::FAST_PUSH_API_URL : self::PUSH_API_URL;
        $apiUrl .= '?site=' . urlencode($site) . '&token=' . urlencode($token);
        
        // 百度 API 接受每行一个 URL
        $postData = $sitemapUrl;
        
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
            CURLOPT_USERAGENT => 'Weline SEO Sitemap Submitter/1.0',
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return [
                'success' => false,
                'message' => __('百度 API 请求失败：%{1}', $error),
                'response' => null,
            ];
        }
        
        // 解析百度返回
        $result = json_decode($response, true);
        
        if (isset($result['error'])) {
            return [
                'success' => false,
                'message' => __('百度返回错误：%{1} - %{2}', [$result['error'], $result['message'] ?? '']),
                'response' => $result,
            ];
        }
        
        $success = isset($result['success']) && $result['success'] > 0;
        $type = $useFastPush ? __('快速收录') : __('普通收录');
        
        return [
            'success' => $success,
            'message' => $success 
                ? __('已成功通过%{1} API提交到百度，成功 %{2} 条', [$type, $result['success'] ?? 1])
                : __('百度 API 返回异常'),
            'response' => $result,
        ];
    }

    /**
     * 批量提交 URL 到百度（用于 URL 级别提交）
     *
     * @param array $urls URL 列表
     * @param string $site 站点
     * @param string $token Token
     * @param bool $useFastPush 是否使用快速收录
     * @return array
     */
    public function submitUrls(array $urls, string $site, string $token, bool $useFastPush = false): array
    {
        $apiUrl = $useFastPush ? self::FAST_PUSH_API_URL : self::PUSH_API_URL;
        $apiUrl .= '?site=' . urlencode($site) . '&token=' . urlencode($token);
        
        // 百度每次最多提交 2000 条
        $chunks = array_chunk($urls, 2000);
        $totalSuccess = 0;
        $totalRemain = 0;
        $errors = [];
        
        foreach ($chunks as $chunk) {
            $postData = implode("\n", $chunk);
            
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
            ]);
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            $result = json_decode($response, true);
            
            if (isset($result['success'])) {
                $totalSuccess += $result['success'];
            }
            if (isset($result['remain'])) {
                $totalRemain = $result['remain'];
            }
            if (isset($result['error'])) {
                $errors[] = $result['message'] ?? $result['error'];
            }
        }
        
        return [
            'success' => $totalSuccess > 0,
            'message' => __('百度提交完成：成功 %{1} 条，剩余配额 %{2}', [$totalSuccess, $totalRemain]),
            'response' => [
                'total_success' => $totalSuccess,
                'remain' => $totalRemain,
                'errors' => $errors,
            ],
        ];
    }
}
