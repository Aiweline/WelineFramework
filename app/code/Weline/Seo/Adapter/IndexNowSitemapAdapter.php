<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Adapter;

abstract class IndexNowSitemapAdapter extends CatalogSitemapAdapter
{
    protected const INDEXNOW_ENDPOINT = 'https://api.indexnow.org/indexnow';

    public function supportsAutoSubmit(): bool
    {
        return false;
    }

    public function submitSitemap(string $sitemapUrl, array $accountConfig): array
    {
        return (new \Weline\Seo\Service\Adapter\IndexNowSearchEngineAdapter())->submitSitemap($sitemapUrl, $accountConfig);
    }

    private function resolveConfig(array $accountConfig): array
    {
        $config = $accountConfig['config'] ?? $accountConfig;
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }

        return is_array($config) ? $config : [];
    }

    protected function getDefaultIndexNowEndpoint(): string
    {
        return static::INDEXNOW_ENDPOINT;
    }
}
