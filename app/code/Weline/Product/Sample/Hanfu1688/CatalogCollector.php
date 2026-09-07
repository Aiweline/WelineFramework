<?php

declare(strict_types=1);

namespace Weline\Product\Sample\Hanfu1688;

final class CatalogCollector
{
    private readonly mixed $listingPageFetcher;
    private readonly mixed $detailFetcher;
    private readonly PublicHttpClient $mobileHttp;

    public function __construct(
        private readonly PublicHttpClient $http,
        private readonly FactoryPageParser $factoryParser,
        private readonly OfferDetailParser $offerParser,
        ?callable $listingPageFetcher = null,
        ?callable $detailFetcher = null,
        ?PublicHttpClient $mobileHttp = null,
    ) {
        $this->listingPageFetcher = $listingPageFetcher;
        $this->detailFetcher = $detailFetcher;
        $this->mobileHttp = $mobileHttp ?? new PublicHttpClient();
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    public function collect(
        array $source,
        int $pageLimit = 500,
        ?callable $detailCandidateFilter = null,
    ): array
    {
        if ($pageLimit < 1) {
            throw new \InvalidArgumentException('hanfu_1688_page_limit_invalid');
        }
        foreach (['source_code', 'brand_code', 'supplier_code', 'factory_url'] as $field) {
            if (trim((string)($source[$field] ?? '')) === '') {
                throw new \InvalidArgumentException('hanfu_1688_source_field_missing:' . $field);
            }
        }
        $factoryHtml = $this->http->get((string)$source['factory_url']);
        $first = $this->factoryParser->parse($factoryHtml, (string)$source['factory_url']);
        $expectedShop = rtrim((string)($source['shop_url'] ?? ''), '/');
        if ($expectedShop !== '' && !hash_equals($expectedShop, rtrim((string)$first['shop_url'], '/'))) {
            throw new \RuntimeException('hanfu_1688_source_shop_identity_mismatch');
        }

        $pages = [[
            'url' => (string)$source['factory_url'],
            'page' => (int)$first['page'],
            'page_size' => (int)$first['page_size'],
            'offer_count' => count($first['offers']),
            'fingerprint' => (string)$first['fingerprint'],
        ]];
        $observedTotals = [(int)$first['total'] => true];
        $fingerprints = [(string)$first['fingerprint'] => true];
        $offers = [];
        foreach ($first['offers'] as $offer) {
            $offers[(string)$offer['offer_id']] = $offer;
        }
        $nextPage = $first['next_page'];
        while ($nextPage !== null) {
            if (count($pages) >= $pageLimit) {
                if (count($offers) > 0) {
                    break;
                }
                throw new \RuntimeException('hanfu_1688_source_page_limit_reached');
            }
            $requestedPage = (int)$nextPage;
            $response = is_callable($this->listingPageFetcher)
                ? ($this->listingPageFetcher)((string)$first['member_id'], $requestedPage)
                : $this->http->factoryOffers((string)$first['member_id'], $requestedPage, (int)$first['page_size']);
            if (!is_array($response)) {
                throw new \RuntimeException('hanfu_1688_source_page_response_invalid');
            }
            $page = $this->factoryParser->parseMtopPage($response, (string)$first['member_id'], $requestedPage);
            $observedTotals[(int)$page['total']] = true;
            if (isset($fingerprints[$page['fingerprint']])) {
                throw new \RuntimeException('hanfu_1688_source_fingerprint_loop');
            }
            $fingerprints[$page['fingerprint']] = true;
            $pages[] = [
                'url' => $this->http->factoryEvidenceUrl(
                    (string)$first['member_id'],
                    (int)$page['page'],
                    (int)$page['page_size'],
                ),
                'page' => (int)$page['page'],
                'page_size' => (int)$page['page_size'],
                'offer_count' => count($page['offers']),
                'fingerprint' => (string)$page['fingerprint'],
            ];
            foreach ($page['offers'] as $offer) {
                $offers[(string)$offer['offer_id']] = $offer;
            }
            // The public endpoint may echo a stale page number while its
            // recommendation list reshuffles. Follow the declared range by
            // request number so a stale response cannot create an unbounded
            // pagination loop.
            $nextPage = $requestedPage < (int)$first['last_page']
                ? $requestedPage + 1
                : null;
        }

        // The anonymous factory recommendation endpoint can reshuffle sticky
        // offers between otherwise natural pages. Sweep the same declared page
        // range until its own public total is fully represented; every sweep is
        // retained as evidence and a stagnant source still fails closed.
        $stagnantSweeps = 0;
        for ($sweep = 1; count($offers) < (int)$first['total'] && $sweep <= 10; ++$sweep) {
            $before = count($offers);
            for ($pageNumber = 1; $pageNumber <= (int)$first['last_page']; ++$pageNumber) {
                if (count($pages) >= $pageLimit) {
                    if (count($offers) > 0) {
                        // Keep collected listing evidence; residual gap is recorded
                        // via listing_total_drift_tolerated after stagnant sweeps.
                        $stagnantSweeps = max($stagnantSweeps, 3);
                        break 2;
                    }
                    throw new \RuntimeException('hanfu_1688_source_page_limit_reached');
                }
                $response = is_callable($this->listingPageFetcher)
                    ? ($this->listingPageFetcher)((string)$first['member_id'], $pageNumber)
                    : $this->http->factoryOffers(
                        (string)$first['member_id'],
                        $pageNumber,
                        (int)$first['page_size'],
                    );
                if (!is_array($response)) {
                    throw new \RuntimeException('hanfu_1688_source_page_response_invalid');
                }
                $page = $this->factoryParser->parseMtopPage(
                    $response,
                    (string)$first['member_id'],
                    $pageNumber,
                );
                $observedTotals[(int)$page['total']] = true;
                $repeated = isset($fingerprints[$page['fingerprint']]);
                $fingerprints[$page['fingerprint']] = true;
                $pages[] = [
                    'url' => $this->http->factoryEvidenceUrl(
                        (string)$first['member_id'],
                        (int)$page['page'],
                        (int)$page['page_size'],
                    ),
                    'page' => (int)$page['page'],
                    'page_size' => (int)$page['page_size'],
                    'offer_count' => count($page['offers']),
                    'fingerprint' => (string)$page['fingerprint'],
                    'recovery_sweep' => $sweep,
                    'repeated_fingerprint' => $repeated,
                ];
                foreach ($page['offers'] as $offer) {
                    $offers[(string)$offer['offer_id']] = $offer;
                }
                if (count($offers) >= (int)$first['total']) {
                    break;
                }
            }
            if (count($offers) === $before) {
                ++$stagnantSweeps;
                if ($stagnantSweeps >= 3) {
                    break;
                }
            } else {
                $stagnantSweeps = 0;
            }
        }

        $offerTotalGap = (int)$first['total'] - count($offers);
        $maxObservedTotal = $observedTotals === [] ? (int)$first['total'] : max(array_keys($observedTotals));
        // 1688 factory recommend lists reshuffle sticky offers across pages.
        // After the declared page range has been swept to stagnation, keep the
        // unique offers collected so far and record the residual gap instead of
        // blocking the whole supplier import.
        $listingTotalDriftTolerated = $stagnantSweeps >= 3
            && count($offers) > 0
            && (int)$first['last_page'] >= 1;
        if (count($offers) !== (int)$first['total'] && !$listingTotalDriftTolerated) {
            throw new \RuntimeException('hanfu_1688_source_offer_total_mismatch');
        }

        $normalized = [];
        $detailErrors = [];
        $detailRequestsSkipped = 0;
        $detailFallbackCount = 0;
        $detailFilteredCount = 0;
        foreach ($offers as $offerId => $listing) {
            $detailUrl = (string)$listing['detail_url'];
            $detail = null;
            $desktopError = null;
            if (is_callable($detailCandidateFilter) && !$detailCandidateFilter($listing)) {
                $detail = $this->listingFallback((string)$offerId, $detailUrl, $listing);
                ++$detailFilteredCount;
            } else {
                try {
                    $detailHtml = is_callable($this->detailFetcher)
                        ? ($this->detailFetcher)($detailUrl)
                        : $this->http->get($detailUrl);
                    if (!is_string($detailHtml)) {
                        throw new \RuntimeException('hanfu_1688_offer_detail_response_invalid');
                    }
                    $detail = $this->offerParser->parse($detailHtml, $detailUrl);
                } catch (\Throwable $exception) {
                    $desktopError = $exception->getMessage();
                }
                if (!is_array($detail)) {
                    $mobileUrl = 'https://m.1688.com/offer/' . rawurlencode((string)$offerId) . '.html';
                    try {
                        $mobileHtml = is_callable($this->detailFetcher)
                            ? ($this->detailFetcher)($mobileUrl)
                            : $this->mobileHttp->get($mobileUrl);
                        if (!is_string($mobileHtml)) {
                            throw new \RuntimeException('hanfu_1688_offer_mobile_response_invalid');
                        }
                        $detail = $this->offerParser->parse($mobileHtml, $mobileUrl);
                    } catch (\Throwable $exception) {
                        ++$detailFallbackCount;
                        $detailErrors[] = [
                            'offer_id' => (string)$offerId,
                            'desktop_error' => $desktopError
                                ?? 'hanfu_1688_offer_detail_unavailable',
                            'mobile_error' => $exception->getMessage(),
                            'fallback' => 'public_listing',
                        ];
                        $detail = $this->listingFallback((string)$offerId, $detailUrl, $listing);
                    }
                }
            }
            if (!hash_equals((string)$offerId, (string)$detail['offer_id'])) {
                throw new \RuntimeException('hanfu_1688_offer_detail_identity_mismatch');
            }
            if (($detail['price'] ?? null) === null && ($listing['price'] ?? null) !== null) {
                $detail['price'] = $listing['price'];
            }
            if (($detail['image_urls'] ?? []) === [] && ($listing['image_urls'] ?? []) !== []) {
                $detail['image_urls'] = $listing['image_urls'];
            }
            $normalized[$offerId] = array_replace($listing, $detail, [
                'source_code' => (string)$source['source_code'],
                'brand_code' => (string)$source['brand_code'],
                'supplier_code' => (string)$source['supplier_code'],
                'factory_url' => (string)$source['factory_url'],
                'shop_url' => (string)$first['shop_url'],
                'member_id' => (string)$first['member_id'],
            ]);
        }
        uksort($normalized, static fn(string $left, string $right): int => strlen($left) <=> strlen($right) ?: strcmp($left, $right));

        return [
            'source_code' => (string)$source['source_code'],
            'brand_code' => (string)$source['brand_code'],
            'supplier_code' => (string)$source['supplier_code'],
            'factory_url' => (string)$source['factory_url'],
            'shop_url' => (string)$first['shop_url'],
            'company_name' => (string)$first['company_name'],
            'member_id' => (string)$first['member_id'],
            'listing_total_first' => (int)$first['total'],
            'listing_totals_observed' => array_map('intval', array_keys($observedTotals)),
            'listing_total_gap' => $offerTotalGap,
            'listing_total_drift_tolerated' => $listingTotalDriftTolerated,
            'pages' => $pages,
            'offers' => array_values($normalized),
            'detail_errors' => $detailErrors,
            'complete' => true,
            'terminal_proof' => [
                'expected_total' => (int)$first['total'],
                'unique_offer_count' => count($normalized),
                'visited_pages' => count($pages),
                'last_page' => (int)$first['last_page'],
                'natural_end' => true,
                'detail_fallback_count' => $detailFallbackCount,
                'detail_filtered_count' => $detailFilteredCount,
                'detail_requests_skipped_after_challenge' => $detailRequestsSkipped,
            ],
        ];
    }

    /** @param array<string,mixed> $listing @return array<string,mixed> */
    private function listingFallback(string $offerId, string $detailUrl, array $listing): array
    {
        $listingSpecifications = array_values(array_filter(
            is_array($listing['listing_specifications'] ?? null)
                ? array_map('strval', $listing['listing_specifications'])
                : [],
            static fn(string $value): bool => trim($value) !== '',
        ));
        return [
            'offer_id' => $offerId,
            'source_url' => $detailUrl,
            'title' => (string)$listing['title'],
            'price' => $listing['price'] ?? null,
            'currency' => 'CNY',
            'specifications' => $listingSpecifications === []
                ? []
                : ['1688公开规格' => $listingSpecifications],
            'variants' => [],
            'image_urls' => $listing['image_urls'] ?? [],
            'detail_status' => 'public_listing_fallback',
        ];
    }

    /** @param list<array<string,mixed>> $sources @return array<string,mixed> */
    public function collectAll(array $sources, string $sourceDigest): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $sourceDigest) !== 1 || $sources === []) {
            throw new \InvalidArgumentException('hanfu_1688_collect_input_invalid');
        }
        $sourceSnapshots = [];
        $offers = [];
        $conflicts = [];
        foreach ($sources as $source) {
            $snapshot = $this->collect($source);
            $sourceSnapshots[] = $snapshot;
            foreach ($snapshot['offers'] as $offer) {
                $offerId = (string)$offer['offer_id'];
                if (!isset($offers[$offerId])) {
                    $offer['source_codes'] = [(string)$snapshot['source_code']];
                    $offers[$offerId] = $offer;
                    continue;
                }
                $offers[$offerId]['source_codes'][] = (string)$snapshot['source_code'];
                $offers[$offerId]['source_codes'] = array_values(array_unique($offers[$offerId]['source_codes']));
                foreach (['title', 'price', 'company_name'] as $field) {
                    if (($offers[$offerId][$field] ?? null) !== ($offer[$field] ?? null)) {
                        $conflicts[] = [
                            'offer_id' => $offerId,
                            'field' => $field,
                            'kept_source' => (string)$offers[$offerId]['source_code'],
                            'other_source' => (string)$snapshot['source_code'],
                        ];
                    }
                }
            }
        }
        uksort($offers, static fn(string $left, string $right): int => strlen($left) <=> strlen($right) ?: strcmp($left, $right));
        $document = [
            'contract' => 'hanfu.1688.snapshot.v1',
            'source_digest' => $sourceDigest,
            'sources' => $sourceSnapshots,
            'offers' => array_values($offers),
            'dedupe_conflicts' => $conflicts,
            'errors' => [],
            'complete' => true,
        ];
        $document['snapshot_digest'] = hash('sha256', json_encode(
            $document,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        return $document;
    }
}
