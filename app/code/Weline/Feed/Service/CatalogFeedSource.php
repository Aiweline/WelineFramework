<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

use Weline\Feed\Api\Data\FeedCollectQuery;
use Weline\Feed\Api\Data\FeedItem;
use Weline\Feed\Api\FeedSourceInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Api\CatalogFeedOfferReaderInterface;
use Weline\Product\Api\Data\CatalogFeedOffer;
use Weline\Product\Api\Data\CatalogFeedReadQuery;
use Weline\Shipping\Api\Data\Shipping\ShippingCoverageRow;
use Weline\Shipping\Api\ShippingCoverageSourceInterface;

/**
 * Maps catalog offers into neutral feed items. Prices stay in minor units.
 */
final class CatalogFeedSource implements FeedSourceInterface
{
    /** 每条商品最多带多少个国家的运费，避免 Feed 体积失控；其余走 GMC 账户级运费。 */
    private const MAX_SHIPPING_ROWS = 50;

    public function __construct(
        private readonly CatalogFeedOfferReaderInterface $offers,
        private readonly ?ShippingCoverageSourceInterface $shipping = null,
    ) {
    }

    public function sourceCode(): string
    {
        return 'catalog';
    }

    public function collect(FeedCollectQuery $query): iterable
    {
        if ($query->sourceCode !== '' && $query->sourceCode !== $this->sourceCode()) {
            return;
        }
        $read = new CatalogFeedReadQuery(
            websiteId: $query->websiteId,
            storeId: $query->storeId,
            locale: $query->locale,
            currency: $query->currency,
            sellingMode: $query->sellingMode,
            limit: 5000,
        );
        $shipping = $this->shippingRows((string)$query->currency);
        foreach ($this->offers->read($read) as $offer) {
            if (!$offer instanceof CatalogFeedOffer) {
                continue;
            }
            $id = trim($offer->globalOfferUuid);
            if ($id === '') {
                continue;
            }
            yield new FeedItem(
                id: $id,
                productId: $offer->productId,
                globalOfferUuid: $id,
                groupId: (string)$offer->productId,
                sku: $offer->sku,
                title: $offer->title,
                description: $offer->description,
                brand: $offer->brand,
                link: $offer->link,
                imageLink: $offer->imageLink,
                additionalImageLinks: $offer->additionalImageLinks,
                availability: $offer->availability,
                currency: $offer->currency,
                priceMinor: $offer->priceMinor,
                salePriceMinor: $offer->salePriceMinor,
                gtin: $offer->gtin,
                mpn: $offer->mpn,
                attributes: $offer->attributes,
                shipping: $shipping,
            );
        }
    }

    /**
     * 配送覆盖 → Feed 行：费率模板币种与 feed 币种不一致时按货币服务换算；
     * 换算不可用则跳过该条，绝不输出错价。
     *
     * @return list<array{country:string,cost_minor:int,currency:string,days_min:int,days_max:int}>
     */
    private function shippingRows(string $targetCurrency): array
    {
        $targetCurrency = strtoupper(trim($targetCurrency));
        if ($targetCurrency === '') {
            return [];
        }
        $source = $this->shipping;
        if (!$source instanceof ShippingCoverageSourceInterface) {
            try {
                $source = ObjectManager::getInstance(ShippingCoverageSourceInterface::class);
            } catch (\Throwable) {
                return [];
            }
        }
        if (!$source instanceof ShippingCoverageSourceInterface) {
            return [];
        }
        try {
            $rows = $source->listCoverage();
        } catch (\Throwable) {
            return [];
        }
        $converter = null;
        if (class_exists(\Weline\Currency\Service\CurrencyRateService::class)) {
            try {
                $converter = ObjectManager::getInstance(\Weline\Currency\Service\CurrencyRateService::class);
            } catch (\Throwable) {
                $converter = null;
            }
        }
        $result = [];
        foreach ($rows as $row) {
            if (!$row instanceof ShippingCoverageRow || !$row->hasCost()) {
                continue;
            }
            // 时效未知（0/0）的国家不对外报运费：宁可少报，不可报错价与假时效。
            if (!$row->hasTransitDays()) {
                continue;
            }
            $currency = strtoupper(trim($row->currency));
            $costMinor = $row->costMinor;
            if ($currency !== $targetCurrency) {
                if (!is_object($converter) || !method_exists($converter, 'tryConvert')) {
                    continue;
                }
                try {
                    $converted = $converter->tryConvert($costMinor / 100, $currency, $targetCurrency);
                } catch (\Throwable) {
                    $converted = null;
                }
                if (!is_numeric($converted) || (float)$converted <= 0) {
                    continue;
                }
                $costMinor = (int)round((float)$converted * 100);
                $currency = $targetCurrency;
            }
            $result[] = [
                'country' => strtoupper(trim($row->countryCode)),
                'cost_minor' => $costMinor,
                'currency' => $currency,
                'days_min' => $row->daysMin,
                'days_max' => $row->daysMax,
            ];
            if (count($result) >= self::MAX_SHIPPING_ROWS) {
                break;
            }
        }

        return $result;
    }
}
