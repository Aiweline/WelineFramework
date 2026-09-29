<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Cdn;

use Weline\Cdn\Api\WarmupProviderInterface;
use Weline\Cdn\Service\WarmupLocaleUrlExpander;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Product\Service\StorefrontProductWidgetCatalog;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * Product 扩展：按店面热度 TopN × 站点已启用语种产出 PDP 绝对 URL。
 */
final class ProductHeatUrls implements WarmupProviderInterface
{
    public const SOURCE_MODULE = 'Weline_Product';
    public const UI_LABEL = '商品热度 PDP';
    public const UI_HINT = '热度 Top N × 站点已启用语种，非全量 catalog';
    public const TOP_N = 48;

    public static function execute(): array
    {
        /** @var WebsiteCatalogInterface $websites */
        $websites = ObjectManager::getInstance(WebsiteCatalogInterface::class);
        /** @var StorefrontProductWidgetCatalog $catalog */
        $catalog = ObjectManager::getInstance(StorefrontProductWidgetCatalog::class);
        /** @var WarmupLocaleUrlExpander $localeExpander */
        $localeExpander = ObjectManager::getInstance(WarmupLocaleUrlExpander::class);

        $out = [];
        $seen = [];
        foreach ($websites->all() as $website) {
            $siteId = (int)$website->id;
            $base = rtrim(trim((string)($website->url ?? '')), '/');
            if ($base === '' || preg_match('#^https?://#i', $base) !== 1) {
                continue;
            }
            $code = trim((string)($website->code ?? 'default'));
            if ($code === '') {
                $code = 'default';
            }

            $prevId = RequestContext::getWelineWebsiteId();
            $prevCode = RequestContext::getWelineWebsiteCode();
            $prevUrl = RequestContext::getWelineWebsiteUrl();
            try {
                RequestContext::setWelineWebsiteId($siteId);
                RequestContext::setWelineWebsiteCode($code);
                RequestContext::setWelineWebsiteUrl($base . '/');
                $cards = $catalog->bestSellerCards(self::TOP_N);
            } catch (\Throwable $e) {
                w_log_error('ProductHeatUrls failed for website ' . $siteId . ': ' . $e->getMessage());
                $cards = [];
            } finally {
                RequestContext::setWelineWebsiteId($prevId);
                RequestContext::setWelineWebsiteCode($prevCode);
                RequestContext::setWelineWebsiteUrl($prevUrl);
            }

            if (!is_array($cards)) {
                continue;
            }
            foreach ($cards as $card) {
                if (!is_array($card)) {
                    continue;
                }
                $slug = strtolower(trim((string)($card['slug'] ?? '')));
                $productId = max(0, (int)($card['product_id'] ?? $card['id'] ?? 0));
                $path = $slug !== '' ? '/product/' . $slug : ($productId > 0 ? '/product/' . $productId : '');
                if ($path === '') {
                    continue;
                }
                foreach ($localeExpander->expandRoute($siteId, $base, $path) as $row) {
                    $url = $row['url'];
                    if (isset($seen[$url])) {
                        continue;
                    }
                    $seen[$url] = true;
                    $out[] = ['url' => $url, 'site_id' => $siteId];
                }
            }
        }

        return $out;
    }
}
