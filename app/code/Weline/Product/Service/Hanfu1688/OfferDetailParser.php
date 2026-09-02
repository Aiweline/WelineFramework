<?php

declare(strict_types=1);

namespace Weline\Product\Service\Hanfu1688;

final class OfferDetailParser
{
    /** @return array<string,mixed> */
    public function parse(string $html, string $url): array
    {
        $offerId = $this->offerIdFromUrl($url);
        if ($this->isBlocked($html)) {
            throw new \RuntimeException('hanfu_1688_offer_page_blocked');
        }
        $context = $this->context($html);
        $data = $context['result']['data'] ?? null;
        if (!is_array($data)) {
            throw new \RuntimeException('hanfu_1688_offer_context_invalid');
        }
        $titleFields = $this->fields($data, 'productTitle');
        $galleryFields = $this->fields($data, 'gallery');
        $priceFields = $this->fields($data, 'mainPrice');
        $descriptionFields = $this->fields($data, 'description');
        $packFields = $this->fields($data, 'productPackInfo');

        $title = trim((string)($titleFields['title'] ?? $galleryFields['subject'] ?? ''));
        if ($title === '') {
            throw new \RuntimeException('hanfu_1688_offer_title_missing');
        }
        $pageOfferId = trim((string)($galleryFields['offerId'] ?? ''));
        if ($pageOfferId !== '' && !hash_equals($offerId, $pageOfferId)) {
            throw new \RuntimeException('hanfu_1688_offer_identity_mismatch');
        }

        $trade = $priceFields['finalPriceModel']['tradeWithoutPromotion'] ?? [];
        $trade = is_array($trade) ? $trade : [];
        $price = $this->money($trade['offerMinPrice'] ?? null);
        if ($price === null && is_array($priceFields['priceModel']['currentPrices'] ?? null)) {
            $price = $this->money($priceFields['priceModel']['currentPrices'][0]['price'] ?? null);
        }
        $maxPrice = $this->money($trade['offerMaxPrice'] ?? null);

        $imageUrls = [];
        foreach (array_merge(
            is_array($galleryFields['mainImage'] ?? null) ? $galleryFields['mainImage'] : [],
            is_array($galleryFields['offerImgList'] ?? null) ? $galleryFields['offerImgList'] : [],
        ) as $imageUrl) {
            $imageUrl = $this->canonicalHttps((string)$imageUrl);
            if ($imageUrl !== '') {
                $imageUrls[$imageUrl] = true;
            }
        }

        $specifications = [];
        $cpv = is_array($galleryFields['CpvEnhance'] ?? null) ? $galleryFields['CpvEnhance'] : [];
        foreach (['decisionCpv', 'normalCpv'] as $group) {
            foreach (($cpv[$group] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = trim((string)($row['name'] ?? ''));
                $values = array_values(array_unique(array_filter(
                    is_array($row['values'] ?? null) ? array_map('strval', $row['values']) : [],
                    static fn(string $value): bool => trim($value) !== '',
                )));
                if ($name !== '' && $values !== []) {
                    $specifications[$name] = $values;
                }
            }
        }

        $variants = [];
        foreach (($trade['skuMapOriginal'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $skuId = trim((string)($row['skuId'] ?? ''));
            $specification = trim(html_entity_decode((string)($row['specAttrs'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($skuId === '' || $specification === '') {
                continue;
            }
            $variants[] = [
                'source_sku_id' => $skuId,
                'specification' => $specification,
                'price' => $this->money($row['discountPrice'] ?? $row['price'] ?? null),
                'public_available_quantity' => isset($row['canBookCount']) ? max(0, (int)$row['canBookCount']) : null,
            ];
        }
        usort($variants, static fn(array $left, array $right): int => strcmp(
            (string)$left['source_sku_id'],
            (string)$right['source_sku_id'],
        ));

        $weight = $packFields['unitWeight'] ?? null;
        $weight = is_numeric($weight) && (float)$weight >= 0 ? (float)$weight : null;
        $detailDescriptionUrl = $this->canonicalHttps((string)($descriptionFields['detailUrl'] ?? ''));
        $companyName = trim((string)($titleFields['shopInfo']['companyName']
            ?? $titleFields['shopInfo']['authCompanyName']
            ?? ''));

        return [
            'offer_id' => $offerId,
            'source_url' => $url,
            'title' => $title,
            'unit' => trim((string)($titleFields['unit'] ?? '')),
            'company_name' => $companyName,
            'price' => $price,
            'maximum_price' => $maxPrice,
            'currency' => 'CNY',
            'minimum_order_quantity' => isset($trade['offerBeginAmount'])
                ? max(0, (int)$trade['offerBeginAmount'])
                : null,
            'specifications' => $specifications,
            'variants' => $variants,
            'image_urls' => array_keys($imageUrls),
            'detail_description_url' => $detailDescriptionUrl !== '' ? $detailDescriptionUrl : null,
            'weight_kg' => $weight,
        ];
    }

    /** @return array<string,mixed> */
    private function context(string $html): array
    {
        $marker = '})(window.contextPath,';
        $position = strpos($html, $marker);
        if ($position === false) {
            throw new \RuntimeException('hanfu_1688_offer_context_missing');
        }
        $start = strpos($html, '{', $position + strlen($marker));
        if ($start === false) {
            throw new \RuntimeException('hanfu_1688_offer_context_missing');
        }
        $json = $this->balancedObject($html, $start);
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('hanfu_1688_offer_context_invalid', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('hanfu_1688_offer_context_invalid');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function fields(array $data, string $key): array
    {
        $fields = $data[$key]['fields'] ?? [];
        return is_array($fields) ? $fields : [];
    }

    private function offerIdFromUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== 'detail.1688.com'
            || preg_match('#^/offer/([1-9][0-9]{0,20})\.html$#D', (string)($parts['path'] ?? ''), $match) !== 1
        ) {
            throw new \InvalidArgumentException('hanfu_1688_offer_url_invalid');
        }
        return $match[1];
    }

    private function money(mixed $value): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $value = trim((string)$value);
        if (preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$/D', $value, $match) !== 1) {
            return null;
        }
        return $match[1] . '.' . str_pad($match[2] ?? '', 2, '0');
    }

    private function canonicalHttps(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        } elseif (str_starts_with($url, 'http://')) {
            $url = 'https://' . substr($url, 7);
        }
        return str_starts_with($url, 'https://') ? $url : '';
    }

    private function balancedObject(string $input, int $start): string
    {
        $depth = 0;
        $quoted = false;
        $escaped = false;
        $length = strlen($input);
        for ($index = $start; $index < $length; ++$index) {
            $character = $input[$index];
            if ($quoted) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $quoted = false;
                }
                continue;
            }
            if ($character === '"') {
                $quoted = true;
            } elseif ($character === '{') {
                ++$depth;
            } elseif ($character === '}') {
                --$depth;
                if ($depth === 0) {
                    return substr($input, $start, $index - $start + 1);
                }
            }
        }
        throw new \RuntimeException('hanfu_1688_json_object_unterminated');
    }

    private function isBlocked(string $html): bool
    {
        $lower = strtolower($html);
        return str_contains($lower, 'login.taobao.com')
            || str_contains($lower, '"action":"captcha"')
            || str_contains($lower, '_____tmd_____/punish');
    }
}
