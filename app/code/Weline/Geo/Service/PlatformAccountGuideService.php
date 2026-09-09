<?php

declare(strict_types=1);

namespace Weline\Geo\Service;

use Weline\Geo\Model\Platform;

/**
 * 各平台「添加账户」配置指南（按 platform_code）。
 */
class PlatformAccountGuideService
{
    /**
     * @return array{
     *   code:string,
     *   title:string,
     *   summary:string,
     *   steps:list<string>,
     *   api_key_input:string,
     *   api_key_label:string,
     *   api_key_hint:string,
     *   api_secret_label:string,
     *   api_secret_required:bool,
     *   api_secret_hint:string,
     *   docs_url:string,
     *   docs_label:string
     * }
     */
    public function forPlatformCode(string $platformCode): array
    {
        $code = trim($platformCode);
        $guides = $this->all();
        $guide = $guides[$code] ?? $this->fallback($code);
        $guide['code'] = $code !== '' ? $code : 'unknown';
        if (!isset($guide['api_key_input']) || !is_string($guide['api_key_input']) || $guide['api_key_input'] === '') {
            $guide['api_key_input'] = 'secret';
        }

        return $guide;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function all(): array
    {
        return [
            Platform::PLATFORM_GOOGLE_SGE => [
                'title' => (string)__('Google Indexing / SGE 账户指南'),
                'summary' => (string)__('使用 Google Cloud 服务账号调用 Indexing API；上传下载的 JSON 密钥文件即可，一般不需要 Secret。'),
                'steps' => [
                    (string)__('打开 Google Cloud Console，创建或选择项目。'),
                    (string)__('启用「Indexing API」。'),
                    (string)__('创建服务账号并下载 JSON 密钥文件。'),
                    (string)__('在 Google Search Console 将该服务账号邮箱添加为网站所有者。'),
                    (string)__('下方直接上传该 JSON 文件；Secret 留空即可。'),
                ],
                'api_key_input' => 'json_file',
                'api_key_label' => (string)__('服务账号 JSON'),
                'api_key_hint' => (string)__('选择 Google 下载的 .json 密钥文件（含 private_key / client_email）'),
                'api_secret_label' => (string)__('API Secret'),
                'api_secret_required' => false,
                'api_secret_hint' => (string)__('Indexing API 通常不需要，可留空'),
                'docs_url' => 'https://developers.google.com/search/apis/indexing-api/v3/quickstart',
                'docs_label' => (string)__('Google Indexing API 文档'),
            ],
            Platform::PLATFORM_BING_CHAT => [
                'title' => (string)__('Bing Webmaster 账户指南'),
                'summary' => (string)__('在 Bing Webmaster Tools 生成 API Key，用于提交站点内容。'),
                'steps' => [
                    (string)__('登录 Bing Webmaster Tools 并验证网站。'),
                    (string)__('进入「设置 → API 访问」生成密钥。'),
                    (string)__('将密钥填入「API Key」；Secret 一般留空。'),
                ],
                'api_key_label' => (string)__('Bing API Key'),
                'api_key_hint' => (string)__('Webmaster Tools 中生成的 API Key'),
                'api_secret_label' => (string)__('API Secret'),
                'api_secret_required' => false,
                'api_secret_hint' => (string)__('通常不需要'),
                'docs_url' => 'https://www.bing.com/webmasters/help/url-submission-902343ef',
                'docs_label' => (string)__('Bing URL 提交说明'),
            ],
            Platform::PLATFORM_PERPLEXITY => [
                'title' => (string)__('Perplexity 账户指南'),
                'summary' => (string)__('使用 Perplexity API Key；若控制台提供了额外密钥再填 Secret。'),
                'steps' => [
                    (string)__('登录 Perplexity 开发者/API 控制台。'),
                    (string)__('创建 API Key 并复制。'),
                    (string)__('填入「API Key」；若有第二段密钥再填 Secret。'),
                ],
                'api_key_label' => (string)__('Perplexity API Key'),
                'api_key_hint' => (string)__('控制台创建的主密钥'),
                'api_secret_label' => (string)__('API Secret'),
                'api_secret_required' => false,
                'api_secret_hint' => (string)__('仅当控制台提供时填写'),
                'docs_url' => 'https://docs.perplexity.ai/',
                'docs_label' => (string)__('Perplexity 文档'),
            ],
            Platform::PLATFORM_OPENAI => [
                'title' => (string)__('OpenAI 账户指南'),
                'summary' => (string)__('使用 OpenAI API Key（sk-…）；组织级若要求再填 Secret。'),
                'steps' => [
                    (string)__('打开 platform.openai.com → API keys。'),
                    (string)__('创建密钥并立即复制保存。'),
                    (string)__('填入「API Key」；Organization / 项目密钥如有要求再填 Secret。'),
                ],
                'api_key_label' => (string)__('OpenAI API Key'),
                'api_key_hint' => (string)__('以 sk- 开头的密钥'),
                'api_secret_label' => (string)__('Organization / Secret'),
                'api_secret_required' => false,
                'api_secret_hint' => (string)__('可选'),
                'docs_url' => 'https://platform.openai.com/api-keys',
                'docs_label' => (string)__('OpenAI API Keys'),
            ],
            Platform::PLATFORM_CLAUDE => [
                'title' => (string)__('Claude / Anthropic 账户指南'),
                'summary' => (string)__('在 Anthropic Console 创建 API Key。'),
                'steps' => [
                    (string)__('登录 console.anthropic.com。'),
                    (string)__('创建 API Key 并复制。'),
                    (string)__('填入「API Key」；Secret 通常留空。'),
                ],
                'api_key_label' => (string)__('Anthropic API Key'),
                'api_key_hint' => (string)__('Console 中的 API Key'),
                'api_secret_label' => (string)__('API Secret'),
                'api_secret_required' => false,
                'api_secret_hint' => (string)__('通常不需要'),
                'docs_url' => 'https://console.anthropic.com/',
                'docs_label' => (string)__('Anthropic Console'),
            ],
            Platform::PLATFORM_BAIDU_AI => [
                'title' => (string)__('百度 AI 账户指南'),
                'summary' => (string)__('百度智能云应用需要 API Key 与 Secret Key。'),
                'steps' => [
                    (string)__('登录百度智能云控制台，创建应用。'),
                    (string)__('在应用详情中复制 API Key 与 Secret Key。'),
                    (string)__('分别填入下方两个字段（Secret 必填）。'),
                ],
                'api_key_label' => (string)__('API Key'),
                'api_key_hint' => (string)__('应用的 API Key'),
                'api_secret_label' => (string)__('Secret Key'),
                'api_secret_required' => true,
                'api_secret_hint' => (string)__('与 API Key 成对使用，必填'),
                'docs_url' => 'https://cloud.baidu.com/doc/index.html',
                'docs_label' => (string)__('百度智能云文档'),
            ],
            Platform::PLATFORM_BRAVE_SEARCH => [
                'title' => (string)__('Brave Search 账户指南'),
                'summary' => (string)__('在 Brave Search API 控制台申请订阅密钥。'),
                'steps' => [
                    (string)__('打开 api.search.brave.com 并注册。'),
                    (string)__('创建订阅并复制 API Key。'),
                    (string)__('填入「API Key」；Secret 留空。'),
                ],
                'api_key_label' => (string)__('Brave API Key'),
                'api_key_hint' => (string)__('Subscription token / API Key'),
                'api_secret_label' => (string)__('API Secret'),
                'api_secret_required' => false,
                'api_secret_hint' => (string)__('通常不需要'),
                'docs_url' => 'https://brave.com/search/api/',
                'docs_label' => (string)__('Brave Search API'),
            ],
            Platform::PLATFORM_COHERE => [
                'title' => (string)__('Cohere 账户指南'),
                'summary' => (string)__('在 Cohere Dashboard 创建 API Key。'),
                'steps' => [
                    (string)__('登录 dashboard.cohere.com。'),
                    (string)__('创建 API Key 并复制。'),
                    (string)__('填入「API Key」。'),
                ],
                'api_key_label' => (string)__('Cohere API Key'),
                'api_key_hint' => (string)__('Dashboard 中的 API Key'),
                'api_secret_label' => (string)__('API Secret'),
                'api_secret_required' => false,
                'api_secret_hint' => (string)__('通常不需要'),
                'docs_url' => 'https://dashboard.cohere.com/',
                'docs_label' => (string)__('Cohere Dashboard'),
            ],
            Platform::PLATFORM_YOU => [
                'title' => (string)__('You.com 账户指南'),
                'summary' => (string)__('在 You.com 开发者控制台获取 API 凭证。'),
                'steps' => [
                    (string)__('登录 You.com 开发者/API 后台。'),
                    (string)__('创建或复制 API Key。'),
                    (string)__('填入「API Key」；若有第二密钥再填 Secret。'),
                ],
                'api_key_label' => (string)__('You.com API Key'),
                'api_key_hint' => (string)__('控制台主密钥'),
                'api_secret_label' => (string)__('API Secret'),
                'api_secret_required' => false,
                'api_secret_hint' => (string)__('仅当控制台提供时填写'),
                'docs_url' => 'https://you.com/',
                'docs_label' => (string)__('You.com'),
            ],
            Platform::PLATFORM_DUCKDUCKGO => [
                'title' => (string)__('DuckDuckGo 账户指南'),
                'summary' => (string)__('若使用 Instant Answer / 合作方 API，按控制台说明填写密钥。'),
                'steps' => [
                    (string)__('确认已获得 DuckDuckGo 侧可用的 API 凭证。'),
                    (string)__('将主密钥填入「API Key」。'),
                    (string)__('按对方文档决定是否填写 Secret。'),
                ],
                'api_key_label' => (string)__('API Key'),
                'api_key_hint' => (string)__('合作方提供的主密钥'),
                'api_secret_label' => (string)__('API Secret'),
                'api_secret_required' => false,
                'api_secret_hint' => (string)__('按文档选填'),
                'docs_url' => 'https://duckduckgo.com/',
                'docs_label' => (string)__('DuckDuckGo'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fallback(string $code): array
    {
        return [
            'title' => (string)__('账户配置指南'),
            'summary' => (string)__('请按该平台控制台说明填写 API 凭证；密钥会加密保存。'),
            'steps' => [
                (string)__('打开对应平台的开发者控制台。'),
                (string)__('创建或复制 API Key。'),
                (string)__('填入下方字段；仅当平台要求时再填 Secret。'),
            ],
            'api_key_input' => 'secret',
            'api_key_label' => (string)__('API Key'),
            'api_key_hint' => (string)__('平台主密钥'),
            'api_secret_label' => (string)__('API Secret'),
            'api_secret_required' => false,
            'api_secret_hint' => (string)__('可选'),
            'docs_url' => '',
            'docs_label' => '',
        ];
    }
}
