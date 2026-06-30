<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Interface\SearchEngineAdapterInterface;

/**
 * 搜索引擎适配器注册表
 *
 * 一供应商(provider) 对应一个适配器实现。
 */
class SearchEngineAdapterRegistry
{
    private ObjectManager $objectManager;

    /**
     * provider => adapterClass
     *
     * @var array<string,string>
     */
    private array $adapters = [];

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;

        // 内置适配器注册
        $this->adapters['google'] = \Weline\Seo\Service\Adapter\GoogleIndexingApiAdapter::class;
        $this->adapters['google_indexing_api'] = \Weline\Seo\Service\Adapter\GoogleIndexingApiAdapter::class;
        $this->adapters['google_search_console'] = \Weline\Seo\Service\Adapter\GoogleSearchConsoleAdapter::class;
        $this->adapters['bing'] = \Weline\Seo\Service\Adapter\BingSearchEngineAdapter::class;
        $this->adapters['bing_webmaster'] = \Weline\Seo\Service\Adapter\BingSearchEngineAdapter::class;
        $this->adapters['bing_indexnow'] = \Weline\Seo\Service\Adapter\BingSearchEngineAdapter::class;
        $this->adapters['baidu'] = \Weline\Seo\Service\Adapter\BaiduSearchEngineAdapter::class;
        $this->adapters['baidu_push_api'] = \Weline\Seo\Service\Adapter\BaiduSearchEngineAdapter::class;
        $this->adapters['baidu_zhanzhang'] = \Weline\Seo\Service\Adapter\BaiduSearchEngineAdapter::class;
        $this->adapters['yandex'] = \Weline\Seo\Service\Adapter\IndexNowSearchEngineAdapter::class;
        $this->adapters['yandex_indexnow'] = \Weline\Seo\Service\Adapter\IndexNowSearchEngineAdapter::class;
        $this->adapters['naver'] = \Weline\Seo\Service\Adapter\IndexNowSearchEngineAdapter::class;
        $this->adapters['naver_indexnow'] = \Weline\Seo\Service\Adapter\IndexNowSearchEngineAdapter::class;
        $this->adapters['naver_searchadvisor'] = \Weline\Seo\Service\Adapter\NaverSearchAdvisorAdapter::class;
        $this->adapters['naver_crawl_request'] = \Weline\Seo\Service\Adapter\NaverSearchAdvisorAdapter::class;
        $this->adapters['seznam'] = \Weline\Seo\Service\Adapter\IndexNowSearchEngineAdapter::class;
        $this->adapters['seznam_indexnow'] = \Weline\Seo\Service\Adapter\IndexNowSearchEngineAdapter::class;
        $this->adapters['yep'] = \Weline\Seo\Service\Adapter\IndexNowSearchEngineAdapter::class;
        $this->adapters['yep_indexnow'] = \Weline\Seo\Service\Adapter\IndexNowSearchEngineAdapter::class;
        $this->adapters['internetarchive'] = \Weline\Seo\Service\Adapter\IndexNowSearchEngineAdapter::class;
        $this->adapters['internetarchive_indexnow'] = \Weline\Seo\Service\Adapter\IndexNowSearchEngineAdapter::class;
        $this->adapters['amazonbot'] = \Weline\Seo\Service\Adapter\IndexNowSearchEngineAdapter::class;
        $this->adapters['amazonbot_indexnow'] = \Weline\Seo\Service\Adapter\IndexNowSearchEngineAdapter::class;
    }

    /**
     * 根据供应商代码获取适配器实例
     */
    public function getAdapter(string $provider): ?SearchEngineAdapterInterface
    {
        $provider = trim($provider);
        $this->loadExtendedAdapters();
        if ($provider === '' || !isset($this->adapters[$provider])) {
            return null;
        }

        $class = $this->adapters[$provider];
        $adapter = $this->objectManager->getInstance($class);

        return $adapter instanceof SearchEngineAdapterInterface ? $adapter : null;
    }

    /**
     * 注册/覆盖适配器
     */
    public function register(string $provider, string $adapterClass): void
    {
        $provider = trim($provider);
        if ($provider === '') {
            return;
        }
        $this->adapters[$provider] = $adapterClass;
    }

    public function hasProvider(string $provider): bool
    {
        $provider = trim($provider);
        if ($provider === '') {
            return false;
        }

        $this->loadExtendedAdapters();
        return isset($this->adapters[$provider]);
    }

    /**
     * @return string[]
     */
    public function getProviderCodes(): array
    {
        $this->loadExtendedAdapters();
        return array_keys($this->adapters);
    }

    private bool $extendedLoaded = false;

    private function loadExtendedAdapters(): void
    {
        if ($this->extendedLoaded) {
            return;
        }
        $this->extendedLoaded = true;

        $pattern = BP . '/app/code/*/extends/module/Weline_Seo/SearchEngineAdapter/*Adapter.php';
        foreach (glob($pattern) ?: [] as $file) {
            $normalized = str_replace('\\', '/', $file);
            if (!preg_match('#app/code/([^/]+)/([^/]+)/extends/module/Weline_Seo/SearchEngineAdapter/([^/]+Adapter)\.php$#', $normalized, $matches)) {
                continue;
            }

            $classes = [
                sprintf(
                    '%s\\%s\\Extends\\Module\\Weline_Seo\\SearchEngineAdapter\\%s',
                    $matches[1],
                    $matches[2],
                    $matches[3]
                ),
                sprintf(
                    '%s\\%s\\extends\\module\\Weline_Seo\\SearchEngineAdapter\\%s',
                    $matches[1],
                    $matches[2],
                    $matches[3]
                ),
            ];

            foreach ($classes as $class) {
                if (!class_exists($class)) {
                    continue;
                }

                $adapter = $this->objectManager->getInstance($class);
                if ($adapter instanceof SearchEngineAdapterInterface) {
                    $this->register($adapter->getCode(), $class);
                    break;
                }
            }
        }
    }
}

