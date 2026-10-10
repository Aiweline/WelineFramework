<?php

declare(strict_types=1);

namespace Weline\Ops\Service;

/**
 * 运营台固定范围目录（入口一站看齐）。
 */
final class OpsScopeCatalog
{
    /** @return list<string> */
    public function codes(): array
    {
        return ['site', 'seo', 'social', 'insight', 'content', 'growth', 'promotion', 'catalog'];
    }

    /**
     * @return list<array{code:string,title:string,status:string}>
     */
    public function all(): array
    {
        $titles = [
            'site' => (string)__('站点画像与智能体'),
            'seo' => (string)__('GSC / 搜索'),
            'social' => (string)__('社媒'),
            'insight' => (string)__('站内事件洞察'),
            'content' => (string)__('博客日更'),
            'growth' => (string)__('增长运营'),
            'promotion' => (string)__('促销运营'),
            'catalog' => (string)__('商品目录运营'),
        ];
        $statuses = [
            'site' => 'ready',
            'seo' => 'deeplink',
            'social' => 'deeplink',
            'insight' => 'deeplink',
            'content' => 'ready',
            'growth' => 'deeplink',
            'promotion' => 'deeplink',
            'catalog' => 'placeholder',
        ];
        $rows = [];
        foreach ($this->codes() as $code) {
            $rows[] = [
                'code' => $code,
                'title' => $titles[$code] ?? $code,
                'status' => $statuses[$code] ?? 'placeholder',
            ];
        }

        return $rows;
    }
}
