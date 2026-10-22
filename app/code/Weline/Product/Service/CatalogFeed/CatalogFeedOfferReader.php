<?php

declare(strict_types=1);

namespace Weline\Product\Service\CatalogFeed;

use Weline\Product\Api\CatalogFeedOfferCandidateSourceInterface;
use Weline\Product\Api\CatalogFeedOfferReaderInterface;
use Weline\Product\Api\Data\CatalogFeedOffer;
use Weline\Product\Api\Data\CatalogFeedOfferCandidate;
use Weline\Product\Api\Data\CatalogFeedReadQuery;
use Weline\Product\Api\Data\StorefrontPriceContext;
use Weline\Product\Api\StorefrontOfferPriceAssemblerInterface;

/**
 * Turns published catalog offers into neutral feed facts.
 *
 * Unit price comes only from StorefrontOfferPriceAssemblerInterface.
 * Variant links keep their query string; this class does not call the SEO
 * path-only offer URL helper.
 */
final class CatalogFeedOfferReader implements CatalogFeedOfferReaderInterface
{
    private const SCAN_CAP = 20000;

    public function __construct(
        private readonly StorefrontOfferPriceAssemblerInterface $prices,
        private readonly CatalogFeedOfferCandidateSourceInterface $candidates,
    ) {
    }

    public function read(CatalogFeedReadQuery $query): iterable
    {
        $limit = max(1, min(500, $query->limit));
        $afterOfferId = max(0, $query->afterOfferId);
        $emitted = 0;
        $scanned = 0;

        while ($emitted < $limit) {
            $page = new CatalogFeedReadQuery(
                websiteId: max(0, $query->websiteId),
                storeId: max(0, $query->storeId),
                locale: trim($query->locale),
                currency: strtoupper(trim($query->currency)) ?: 'CNY',
                sellingMode: trim($query->sellingMode),
                limit: $limit,
                afterOfferId: $afterOfferId,
            );
            $count = 0;
            $lastOfferId = $afterOfferId;
            foreach ($this->candidates->collect($page) as $candidate) {
                if (!$candidate instanceof CatalogFeedOfferCandidate) {
                    continue;
                }
                ++$count;
                ++$scanned;
                if ($scanned > self::SCAN_CAP) {
                    throw new \LogicException('Catalog feed offer scan exceeded ' . self::SCAN_CAP . ' rows');
                }
                $lastOfferId = max($lastOfferId, $candidate->offerId);
                $offer = $this->map($page, $candidate);
                if ($offer === null) {
                    continue;
                }
                yield $offer;
                ++$emitted;
                if ($emitted >= $limit) {
                    break;
                }
            }
            if ($count === 0 || $lastOfferId <= $afterOfferId) {
                break;
            }
            $afterOfferId = $lastOfferId;
            if ($count < $limit) {
                break;
            }
        }
    }

    private function map(CatalogFeedReadQuery $query, CatalogFeedOfferCandidate $candidate): ?CatalogFeedOffer
    {
        $offerUuid = trim($candidate->globalOfferUuid);
        if ($candidate->offerId <= 0 || $candidate->productId <= 0 || $offerUuid === '') {
            return null;
        }
        if (!$candidate->published || $candidate->quoteOnly) {
            return null;
        }

        $imageLink = $this->withoutPierce(trim($candidate->imageLink));
        if (!$this->isAbsoluteHttp($imageLink)) {
            return null;
        }

        $view = $this->prices->assemble(new StorefrontPriceContext(
            productId: $candidate->productId,
            catalogPriceMinor: max(0, $candidate->catalogPriceMinor),
            currency: $query->currency,
            websiteId: $query->websiteId,
            storeId: $query->storeId,
            locale: $query->locale,
            sku: trim($candidate->sku),
            globalOfferUuid: $offerUuid,
            sellingMode: $query->sellingMode,
        ));
        if ($view->finalPriceMinor <= 0) {
            return null;
        }

        if ($view->hasDeal) {
            $priceMinor = $view->compareAtMinor > 0 ? $view->compareAtMinor : $view->catalogPriceMinor;
            $salePriceMinor = $view->finalPriceMinor;
        } else {
            $priceMinor = $view->finalPriceMinor;
            $salePriceMinor = null;
        }
        if ($priceMinor <= 0) {
            return null;
        }

        $link = $this->variantLink($candidate->linkBase, $offerUuid);
        if (!$this->isAbsoluteHttp($link) || !str_contains($link, 'offer=')) {
            return null;
        }

        $sku = trim($candidate->sku);
        $currency = strtoupper(trim($view->currency)) ?: $query->currency;

        return new CatalogFeedOffer(
            id: $offerUuid,
            offerId: $candidate->offerId,
            productId: $candidate->productId,
            globalOfferUuid: $offerUuid,
            sku: $sku,
            title: trim($candidate->title),
            description: trim($candidate->description),
            brand: trim($candidate->brand),
            link: $link,
            imageLink: $imageLink,
            additionalImageLinks: [],
            availability: $this->availability($candidate),
            currency: $currency,
            priceMinor: $priceMinor,
            salePriceMinor: $salePriceMinor,
            gtin: $this->gtin($candidate->gtin, $sku),
            mpn: $this->blankToNull($candidate->mpn),
            itemGroupId: $candidate->variant ? (string)$candidate->productId : null,
            attributes: $this->attributes($candidate->attributes),
        );
    }

    private function availability(CatalogFeedOfferCandidate $candidate): string
    {
        if ($candidate->stock !== null) {
            return $candidate->stock > 0
                ? CatalogFeedOffer::AVAILABILITY_IN_STOCK
                : CatalogFeedOffer::AVAILABILITY_OUT_OF_STOCK;
        }
        if ($candidate->sellable === false) {
            return CatalogFeedOffer::AVAILABILITY_OUT_OF_STOCK;
        }

        return CatalogFeedOffer::AVAILABILITY_IN_STOCK;
    }

    /**
     * Keep an existing query and add the offer id.
     *
     * This is intentionally not the SEO helper that drops variant parameters.
     */
    private function variantLink(string $baseUrl, string $offerUuid): string
    {
        $baseUrl = trim($baseUrl);
        $offerUuid = trim($offerUuid);
        if ($baseUrl === '' || $offerUuid === '' || !$this->isAbsoluteHttp($baseUrl)) {
            return '';
        }
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $query = [];
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
        }
        unset($query['w_fpc_pierce'], $query['_wb_nc']);
        $query['offer'] = $offerUuid;

        $origin = strtolower((string)$parts['scheme']) . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        $path = (string)($parts['path'] ?? '');
        $link = $origin . $path . '?' . http_build_query($query);
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $link .= '#' . $parts['fragment'];
        }

        return $link;
    }

    private function gtin(?string $gtin, string $sku): ?string
    {
        $gtin = trim((string)$gtin);
        $sku = trim($sku);
        if ($gtin === '' || ($sku !== '' && strcasecmp($gtin, $sku) === 0)) {
            return null;
        }
        if (preg_match('/^(?:\d{8}|\d{12}|\d{13}|\d{14})$/', $gtin) !== 1) {
            return null;
        }

        return $gtin;
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }

    /**
     * @param list<array{code:string,label:string,value:string}> $rows
     * @return list<array{code:string,label:string,value:string}>
     */
    private function attributes(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row['code'] ?? ''));
            $value = trim((string)($row['value'] ?? ''));
            if ($code === '' || $value === '') {
                continue;
            }
            $out[] = [
                'code' => $code,
                'label' => trim((string)($row['label'] ?? '')),
                'value' => $value,
            ];
        }

        return $out;
    }

    private function withoutPierce(string $url): string
    {
        if ($url === '' || !str_contains($url, '?')) {
            return $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }
        $query = [];
        if (isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        unset($query['w_fpc_pierce'], $query['_wb_nc']);
        $origin = strtolower((string)$parts['scheme']) . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        $built = $origin . (string)($parts['path'] ?? '');
        if ($query !== []) {
            $built .= '?' . http_build_query($query);
        }

        return $built;
    }

    private function isAbsoluteHttp(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)
            && $parts['host'] !== '';
    }
}
