<?php

declare(strict_types=1);

namespace Weline\Feed\Service;

use Weline\Feed\Api\Data\FeedCollectQuery;
use Weline\Feed\Api\Data\FeedItem;
use Weline\Feed\Api\FeedSourceInterface;
use Weline\Product\Api\CatalogFeedOfferReaderInterface;
use Weline\Product\Api\Data\CatalogFeedOffer;
use Weline\Product\Api\Data\CatalogFeedReadQuery;

/**
 * Maps catalog offers into neutral feed items. Prices stay in minor units.
 */
final class CatalogFeedSource implements FeedSourceInterface
{
    public function __construct(
        private readonly CatalogFeedOfferReaderInterface $offers,
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
            );
        }
    }
}
