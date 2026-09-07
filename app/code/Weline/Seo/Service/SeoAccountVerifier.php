<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Seo\Adapter\GoogleSitemapAdapter;
use Weline\Seo\Service\Adapter\BingSearchEngineAdapter;
use Weline\Seo\Service\Adapter\SubmissionResult;

final class SeoAccountVerifier
{
    /** Called only by the administrator's explicit Verify action. */
    public function verify(string $platform, array $config): array
    {
        if ($platform === 'google') { return (new GoogleSitemapAdapter())->verifyAccount(['config' => $config]); }
        if ($platform === 'bing' && empty($config['use_indexnow'])) { return (new BingSearchEngineAdapter())->verifyAccount($config); }
        if ($platform === 'baidu') {
            return ['success' => true, 'remote_verified' => false, 'status' => 'configuration_valid',
                'message' => __('百度配置字段有效；平台无不扣额 Token 验证接口，凭证权限需通过实际页面提交结果确认')];
        }
        if (!empty($config['indexnow_key'])) {
            $location = trim((string)($config['key_location'] ?? ''));
            if ($location === '' && !empty($config['site_url'])) { $location = rtrim($config['site_url'], '/') . '/' . $config['indexnow_key'] . '.txt'; }
            if (!SubmissionResult::validUrl($location)) { return SubmissionResult::failure(__('请填写可公开访问的 IndexNow Key 文件地址'), 'not_configured'); }
            $ch = curl_init();
            curl_setopt_array($ch, [CURLOPT_URL => $location, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            $success = $error === '' && $code === 200 && hash_equals((string)$config['indexnow_key'], trim((string)$body));
            return ['success' => $success, 'remote_verified' => false, 'status' => $success ? 'key_file_verified' : 'failed',
                'message' => $success ? __('IndexNow Key 文件可访问且内容匹配；搜索引擎验证状态以提交响应为准') : __('IndexNow Key 文件无法访问或内容不匹配')];
        }
        return ['success' => true, 'remote_verified' => false, 'status' => 'configuration_valid', 'message' => __('该平台没有公开自动提交接口，请通过站长平台验证')];
    }
}
