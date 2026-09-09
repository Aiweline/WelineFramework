<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Geo\Service;

use Weline\Geo\Adapter\BaseAdapter;
use Weline\Geo\Adapter\GoogleSgeAdapter;
use Weline\Geo\Adapter\PerplexityAdapter;
use Weline\Geo\Adapter\BingChatAdapter;
use Weline\Geo\Adapter\OpenAiAdapter;
use Weline\Geo\Adapter\ClaudeAdapter;
use Weline\Geo\Adapter\YouAdapter;
use Weline\Geo\Adapter\BraveSearchAdapter;
use Weline\Geo\Adapter\DuckDuckGoAdapter;
use Weline\Geo\Adapter\BaiduAiAdapter;
use Weline\Geo\Adapter\CohereAdapter;
use Weline\Geo\Model\Platform;
use Weline\Framework\Manager\ObjectManager;

/**
 * 平台适配器服务
 * 
 * @package Weline_Geo
 */
class PlatformAdapterService
{
    /**
     * 适配器映射
     */
    protected array $adapterMap = [
        Platform::PLATFORM_GOOGLE_SGE => GoogleSgeAdapter::class,
        Platform::PLATFORM_PERPLEXITY => PerplexityAdapter::class,
        Platform::PLATFORM_BING_CHAT => BingChatAdapter::class,
        Platform::PLATFORM_OPENAI => OpenAiAdapter::class,
        Platform::PLATFORM_CLAUDE => ClaudeAdapter::class,
        // 新增国际平台
        Platform::PLATFORM_YOU => YouAdapter::class,
        Platform::PLATFORM_BRAVE_SEARCH => BraveSearchAdapter::class,
        Platform::PLATFORM_DUCKDUCKGO => DuckDuckGoAdapter::class,
        Platform::PLATFORM_BAIDU_AI => BaiduAiAdapter::class,
        Platform::PLATFORM_COHERE => CohereAdapter::class,
    ];

    /**
     * 获取平台适配器
     * 
     * @param Platform $platform 平台配置
     * @return BaseAdapter|null 适配器实例
     */
    public function getAdapter(Platform $platform): ?BaseAdapter
    {
        $platformCode = $platform->getData(Platform::schema_fields_PLATFORM_CODE);
        
        if (!isset($this->adapterMap[$platformCode])) {
            return null;
        }

        $adapterClass = $this->adapterMap[$platformCode];
        
        if (!class_exists($adapterClass)) {
            return null;
        }

        /** @var BaseAdapter $adapter */
        $adapter = ObjectManager::getInstance($adapterClass);
        
        return $adapter;
    }

    /**
     * 注册自定义适配器
     * 
     * @param string $platformCode 平台代码
     * @param string $adapterClass 适配器类名
     * @return self
     */
    public function registerAdapter(string $platformCode, string $adapterClass): self
    {
        $this->adapterMap[$platformCode] = $adapterClass;
        return $this;
    }

    /**
     * 获取所有支持的平台代码
     * 
     * @return array 平台代码数组
     */
    public function getSupportedPlatforms(): array
    {
        return array_keys($this->adapterMap);
    }

    /**
     * @return array<string, array{
     *   code:string,
     *   name:string,
     *   api_endpoint:string,
     *   feed_format:string,
     *   auth_mode:string,
     *   auth_hint:string,
     *   config_default:string
     * }>
     */
    public function getPlatformCatalog(): array
    {
        $names = [
            Platform::PLATFORM_GOOGLE_SGE => 'Google SGE / Indexing',
            Platform::PLATFORM_PERPLEXITY => 'Perplexity',
            Platform::PLATFORM_BING_CHAT => 'Bing Webmaster',
            Platform::PLATFORM_OPENAI => 'OpenAI',
            Platform::PLATFORM_CLAUDE => 'Claude',
            Platform::PLATFORM_YOU => 'You.com',
            Platform::PLATFORM_BRAVE_SEARCH => 'Brave Search',
            Platform::PLATFORM_DUCKDUCKGO => 'DuckDuckGo',
            Platform::PLATFORM_BAIDU_AI => '百度 AI',
            Platform::PLATFORM_COHERE => 'Cohere',
        ];

        $catalog = [];
        foreach ($this->adapterMap as $code => $adapterClass) {
            if (!\class_exists($adapterClass)) {
                continue;
            }
            /** @var BaseAdapter $adapter */
            $adapter = ObjectManager::getInstance($adapterClass);
            $catalog[$code] = [
                'code' => $code,
                'name' => $names[$code] ?? $code,
                'api_endpoint' => $adapter->getApiEndpoint(),
                'feed_format' => $adapter->getFeedFormat() ?: 'json_feed',
                'auth_mode' => 'api_key',
                'auth_hint' => (string)__('请在「平台账户」中填写 API 密钥；暂不支持一键 OAuth 授权。'),
                'config_default' => '{}',
            ];
        }

        return $catalog;
    }

    public function resolveDefaultEndpoint(string $platformCode): string
    {
        $catalog = $this->getPlatformCatalog();

        return (string)($catalog[$platformCode]['api_endpoint'] ?? '');
    }

    public function resolveDefaultFeedFormat(string $platformCode): string
    {
        $catalog = $this->getPlatformCatalog();

        return (string)($catalog[$platformCode]['feed_format'] ?? 'json_feed');
    }
}
