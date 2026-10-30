<?php

declare(strict_types=1);

namespace Weline\Product\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Repository\AttributeValueRepository;

/**
 * Product-owned bridge: projection URL changes write GEO feed items when Weline_Geo is present.
 */
final class GeoFeedProjectionObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if (!class_exists(\Weline\Geo\Service\FeedSubmitService::class)) {
            return;
        }

        $change = $event->getData('data');
        if (!$change instanceof ResourceChange) {
            return;
        }
        if ($change->resourceType() !== 'product_search_projection') {
            return;
        }

        try {
            /** @var \Weline\Geo\Service\FeedSubmitService $feedSubmit */
            $feedSubmit = ObjectManager::getInstance(\Weline\Geo\Service\FeedSubmitService::class);
        } catch (\Throwable) {
            return;
        }

        $payload = $change->toArray();
        $impact = is_array($payload['impact'] ?? null) ? $payload['impact'] : [];
        $after = is_array($payload['after'] ?? null) ? $payload['after'] : [];
        $productId = (int)($after['target_id'] ?? 0);
        $websiteId = $change->websiteId();
        $urls = is_array($impact['urls'] ?? null) ? $impact['urls'] : [];

        // 根因：原先直接把 loc 当 title 写进 feed item，geo-feed 里 2412 条 product 的标题
        // 全成了 URL，对生成式引擎而言等于「无标题内容」。标题必须从产品属性取真实值。
        // 解析放在循环外：一次变更事件只查一遍，不要按 URL 数乘查询。
        [$title, $content] = $this->resolveProductTitle($websiteId, $productId);

        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url === '') {
                continue;
            }
            $feedSubmit->requestSubmit($url, 'product', [
                'subject_type' => 'product',
                'subject_id' => $productId,
                'item_type' => 'product',
                'item_id' => $productId,
                'website_id' => $websiteId,
                'title' => $title !== '' ? $title : $url,
                'content' => $content,
                'is_published' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
                'source' => 'Weline_Product::geo_feed_projection',
            ]);
        }
    }

    /**
     * 取产品真实标题与描述（EAV 属性 name/description）。
     *
     * 店铺级显式行优先于 store_id=0 的站点级兜底行；locale 按「空 → zh → en」取第一个非空。
     * 取不到时返回空串，由调用方退化为 URL（保持原有可用性，不因属性缺失而不写 feed）。
     *
     * @return array{0:string,1:string} [title, content]
     */
    private function resolveProductTitle(int $websiteId, int $productId): array
    {
        if ($productId <= 0 || $websiteId <= 0) {
            return ['', ''];
        }
        if (!class_exists(AttributeValueRepository::class)) {
            return ['', ''];
        }
        try {
            /** @var AttributeValueRepository $attributes */
            $attributes = ObjectManager::getInstance(AttributeValueRepository::class);
        } catch (\Throwable) {
            return ['', ''];
        }
        $out = [];
        // CatalogOverlayResolver 的层链：store_id=0 只读站点级层，locale 回退链由 localeFallback 决定。
        // 一次 read() 就够，不需要按 store/locale 组合反复探测（负数 store_id 会抛异常）。
        foreach (['name' => 'title', 'description' => 'content'] as $code => $target) {
            $best = '';
            try {
                $resolved = $attributes->read(
                    $websiteId,
                    0,
                    'product',
                    $productId,
                    $code,
                    '',
                    ['', 'zh_Hans_CN', 'zh_Hans', 'en_US', 'en'],
                );
                if ($resolved->isExplicit()) {
                    $best = trim((string)$resolved->value);
                }
            } catch (\Throwable) {
                $best = '';
            }
            $out[$target] = $best;
        }

        return [(string)$out['title'], (string)$out['content']];
    }
}
