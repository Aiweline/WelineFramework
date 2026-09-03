<?php

declare(strict_types=1);

namespace Weline\Product\Service\Hanfu1688;

final class AcceptedOfferDetailEnricher
{
    private readonly mixed $fetcher;
    private readonly mixed $sleeper;

    public function __construct(
        private readonly OfferDetailParser $parser,
        ?callable $fetcher = null,
        ?callable $sleeper = null,
        private readonly int $cooldownMilliseconds = 2_000,
        private readonly int $maximumAttempts = 3,
    ) {
        if ($cooldownMilliseconds < 0 || $maximumAttempts < 1 || $maximumAttempts > 5) {
            throw new \InvalidArgumentException('hanfu_1688_detail_enricher_config_invalid');
        }
        $this->fetcher = $fetcher ?? static fn(string $url): string =>
            (new PublicHttpClient())->get($url);
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    /**
     * @param array<string,mixed> $collected
     * @return array<string,mixed>
     */
    public function enrich(array $collected, HanfuProductClassifier $classifier): array
    {
        $offers = is_array($collected['offers'] ?? null) ? $collected['offers'] : [];
        $enriched = 0;
        $errors = [];
        foreach ($offers as $index => $offer) {
            if (!is_array($offer)
                || ($offer['detail_status'] ?? null) !== 'public_listing_fallback'
                || !$classifier->classify((string)($offer['title'] ?? ''))['accepted']
            ) {
                continue;
            }
            $offerId = trim((string)($offer['offer_id'] ?? ''));
            if ($offerId === '' || preg_match('/^\d+$/D', $offerId) !== 1) {
                $errors[] = ['offer_id' => $offerId, 'error' => 'hanfu_1688_offer_id_invalid'];
                continue;
            }
            $url = 'https://m.1688.com/offer/' . rawurlencode($offerId) . '.html';
            $lastError = 'hanfu_1688_offer_mobile_unknown';
            for ($attempt = 1; $attempt <= $this->maximumAttempts; ++$attempt) {
                if ($this->cooldownMilliseconds > 0) {
                    ($this->sleeper)($this->cooldownMilliseconds * 1_000);
                }
                try {
                    $html = ($this->fetcher)($url);
                    if (!is_string($html)) {
                        throw new \RuntimeException('hanfu_1688_offer_mobile_response_invalid');
                    }
                    $detail = $this->parser->parse($html, $url);
                    $detail['image_urls'] = $this->mergeImages(
                        is_array($offer['image_urls'] ?? null) ? $offer['image_urls'] : [],
                        is_array($detail['image_urls'] ?? null) ? $detail['image_urls'] : [],
                    );
                    $offers[$index] = array_replace($offer, $detail);
                    ++$enriched;
                    continue 2;
                } catch (\Throwable $exception) {
                    $lastError = $exception->getMessage();
                }
            }
            $errors[] = [
                'offer_id' => $offerId,
                'error' => $lastError,
                'attempts' => $this->maximumAttempts,
            ];
        }
        $collected['offers'] = array_values($offers);
        $collected['accepted_detail_enrichment'] = [
            'enriched' => $enriched,
            'errors' => $errors,
        ];
        return $collected;
    }

    /**
     * Fetch the desktop description pointer and public detail body only after Hanfu classification.
     *
     * @param array<string,mixed> $collected
     * @return array<string,mixed>
     */
    public function enrichDescriptions(array $collected, HanfuProductClassifier $classifier): array
    {
        $offers = is_array($collected['offers'] ?? null) ? $collected['offers'] : [];
        $accepted = 0;
        $attempted = 0;
        $enriched = 0;
        $cached = 0;
        $errors = [];

        foreach ($offers as $index => $offer) {
            if (!is_array($offer) || !$classifier->classify((string)($offer['title'] ?? ''))['accepted']) {
                continue;
            }
            ++$accepted;
            if (trim((string)($offer['detail_html'] ?? '')) !== ''
                && is_array($offer['detail_image_urls'] ?? null)
            ) {
                ++$cached;
                continue;
            }

            ++$attempted;
            $offerId = trim((string)($offer['offer_id'] ?? ''));
            if (preg_match('/^[1-9][0-9]{0,20}$/D', $offerId) !== 1) {
                $errors[] = ['offer_id' => $offerId, 'error' => 'hanfu_1688_offer_id_invalid'];
                continue;
            }

            $descriptionUrl = trim((string)($offer['detail_description_url'] ?? ''));
            $lastError = 'hanfu_1688_description_url_missing';
            if ($descriptionUrl === '') {
                $desktopUrl = 'https://detail.1688.com/offer/' . rawurlencode($offerId) . '.html?forcePC=1';
                for ($attempt = 1; $attempt <= $this->maximumAttempts; ++$attempt) {
                    $this->descriptionCooldown();
                    try {
                        $html = ($this->fetcher)($desktopUrl);
                        if (!is_string($html)) {
                            throw new \RuntimeException('hanfu_1688_offer_desktop_response_invalid');
                        }
                        $descriptionUrl = $this->parser->parseDescriptionUrl($html, $desktopUrl);
                        if ($descriptionUrl === '') {
                            throw new \RuntimeException('hanfu_1688_description_url_missing');
                        }
                        break;
                    } catch (\Throwable $exception) {
                        $lastError = $exception->getMessage();
                    }
                }
            }
            if ($descriptionUrl === '') {
                $errors[] = ['offer_id' => $offerId, 'error' => $lastError];
                continue;
            }

            $description = null;
            for ($attempt = 1; $attempt <= $this->maximumAttempts; ++$attempt) {
                $this->descriptionCooldown();
                try {
                    $payload = ($this->fetcher)($descriptionUrl);
                    if (!is_string($payload)) {
                        throw new \RuntimeException('hanfu_1688_description_response_invalid');
                    }
                    $description = $this->parser->parseDescription($payload, $descriptionUrl);
                    break;
                } catch (\Throwable $exception) {
                    $lastError = $exception->getMessage();
                }
            }
            if (!is_array($description)) {
                $errors[] = ['offer_id' => $offerId, 'error' => $lastError];
                continue;
            }

            $offers[$index] = array_replace($offer, $description, [
                'detail_description_url' => $descriptionUrl,
            ]);
            ++$enriched;
        }

        $collected['offers'] = array_values($offers);
        $collected['accepted_description_enrichment'] = [
            'accepted' => $accepted,
            'attempted' => $attempted,
            'enriched' => $enriched,
            'cached' => $cached,
            'errors' => $errors,
        ];

        return $collected;
    }

    private function descriptionCooldown(): void
    {
        if ($this->cooldownMilliseconds > 0) {
            ($this->sleeper)($this->cooldownMilliseconds * 1_000);
        }
    }


    /** @param list<mixed> $listing @param list<mixed> $detail @return list<string> */
    private function mergeImages(array $listing, array $detail): array
    {
        $images = [];
        foreach (array_merge($listing, $detail) as $url) {
            $url = trim((string)$url);
            if ($url !== '') {
                $images[$url] = true;
            }
        }
        return array_keys($images);
    }
}
