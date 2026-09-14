<?php

declare(strict_types=1);

namespace Weline\Product\Sample\Hanfu1688;

final class OfferDetailParser
{
    private const MAX_DESCRIPTION_RESPONSE_BYTES = 4_194_304;
    private const MAX_DESCRIPTION_IMAGES = 56;
    private const DESCRIPTION_ALLOWED_TAGS = [
        'div', 'p', 'br', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'strong', 'em', 'span',
    ];
    private const DESCRIPTION_DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input',
        'button', 'textarea', 'select', 'option', 'svg', 'math', 'video',
        'audio', 'source', 'link', 'meta', 'base',
    ];
    /** @return array<string,mixed> */
    public function parse(string $html, string $url): array
    {
        $offerId = $this->offerIdFromUrl($url);
        if ($this->isBlocked($html)) {
            throw new \RuntimeException('hanfu_1688_offer_page_blocked');
        }
        if (str_contains($html, '"skuProps"') && str_contains($html, 'component-data/json')) {
            return $this->parseMobile($html, $url, $offerId);
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
            $variant = [
                'source_sku_id' => $skuId,
                'specification' => $specification,
                'price' => $this->money($row['discountPrice'] ?? $row['price'] ?? null),
                'public_available_quantity' => isset($row['canBookCount']) ? max(0, (int)$row['canBookCount']) : null,
            ];
            $variantImageUrl = $this->canonicalHttps((string)(
                $row['imageUrl'] ?? $row['skuImageUrl'] ?? $row['image'] ?? ''
            ));
            if ($variantImageUrl !== '') {
                $variant['image_url'] = $variantImageUrl;
                $imageUrls[$variantImageUrl] = true;
            }
            $variants[] = $variant;
        }
        usort($variants, static fn(array $left, array $right): int => strcmp(
            (string)$left['source_sku_id'],
            (string)$right['source_sku_id'],
        ));

        $weight = $packFields['unitWeight'] ?? null;
        $weight = is_numeric($weight) ? (float)$weight : null;
        // Reject tiny placeholders (e.g. 0.001kg / 1g) the same way as pieceWeightScale.
        if ($weight === null || $weight < 0.05) {
            $weight = $this->pieceWeightScaleKg($packFields);
        }
        if ($weight !== null && $weight < 0.05) {
            $weight = null;
        }
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
            'source_brand_name' => $this->brandNameInNode($data),
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
    private function parseMobile(string $html, string $url, string $offerId): array
    {
        preg_match_all(
            '#<script[^>]+type="(?:component-data/json|component/json)"[^>]*>(.*?)</script>#si',
            $html,
            $matches,
        );
        $component = null;
        foreach ($matches[1] ?? [] as $raw) {
            try {
                $decoded = json_decode(
                    html_entity_decode(trim((string)$raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
            } catch (\JsonException) {
                continue;
            }
            if (is_array($decoded)
                && hash_equals($offerId, trim((string)($decoded['offerId'] ?? '')))
                && is_array($decoded['skuProps'] ?? null)
                && is_array($decoded['skuMap'] ?? null)
            ) {
                $component = $decoded;
                break;
            }
        }
        if (!is_array($component)) {
            throw new \RuntimeException('hanfu_1688_offer_mobile_component_missing');
        }

        $title = '';
        if (preg_match('#<title[^>]*>(.*?)</title>#si', $html, $titleMatch) === 1) {
            $title = trim(html_entity_decode(strip_tags((string)$titleMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $title = preg_replace('/\s*-\s*阿里巴巴\s*$/u', '', $title) ?? $title;
        }
        if ($title === '') {
            throw new \RuntimeException('hanfu_1688_offer_title_missing');
        }

        $specifications = [];
        $imageUrls = [];
        $optionImages = [];
        $previewImage = $this->canonicalHttps((string)($component['previewImageUrl'] ?? ''));
        if ($previewImage !== '') {
            $imageUrls[$previewImage] = true;
        }
        foreach ($component['skuProps'] as $axis) {
            if (!is_array($axis)) {
                continue;
            }
            $name = trim((string)($axis['prop'] ?? ''));
            $values = [];
            foreach (is_array($axis['value'] ?? null) ? $axis['value'] : [] as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $label = trim((string)($option['name'] ?? ''));
                if ($label !== '') {
                    $values[$label] = true;
                }
                $imageUrl = $this->canonicalHttps((string)($option['imageUrl'] ?? ''));
                if ($imageUrl !== '') {
                    $imageUrls[$imageUrl] = true;
                    if ($label !== '') {
                        $optionImages[$label] = $imageUrl;
                    }
                }
            }
            if ($name !== '' && $values !== []) {
                $specifications[$name] = array_keys($values);
            }
        }

        $variants = [];
        foreach ($component['skuMap'] as $specification => $row) {
            if (!is_array($row)) {
                continue;
            }
            $skuId = trim((string)($row['skuId'] ?? ''));
            $specification = trim(html_entity_decode((string)$specification, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($skuId === '' || $specification === '') {
                continue;
            }
            $variant = [
                'source_sku_id' => $skuId,
                'specification' => $specification,
                'price' => $this->money($row['discountPrice'] ?? $row['price'] ?? null),
                'public_available_quantity' => isset($row['canBookCount'])
                    ? max(0, (int)$row['canBookCount'])
                    : null,
            ];
            $matchedLabel = '';
            foreach ($optionImages as $label => $imageUrl) {
                if (mb_stripos($specification, $label) !== false
                    && mb_strlen($label) > mb_strlen($matchedLabel)
                ) {
                    $variant['image_url'] = $imageUrl;
                    $matchedLabel = $label;
                }
            }
            $variants[] = $variant;
        }
        usort($variants, static fn(array $left, array $right): int => strcmp(
            (string)$left['source_sku_id'],
            (string)$right['source_sku_id'],
        ));

        $price = $this->money($component['promotionDisplayPrice'] ?? $component['displayPrice'] ?? null);
        return [
            'offer_id' => $offerId,
            'source_url' => $url,
            'title' => $title,
            'source_brand_name' => $this->parseBrandName($html),
            'price' => $price,
            'maximum_price' => $price,
            'currency' => 'CNY',
            'minimum_order_quantity' => isset($component['beginAmount'])
                ? max(0, (int)$component['beginAmount'])
                : null,
            'specifications' => $specifications,
            'variants' => $variants,
            'image_urls' => array_keys($imageUrls),
            'detail_status' => 'mobile_public_detail',
            'weight_kg' => null,
        ];
    }

    public function parseBrandName(string $html): string
    {
        if ($this->isBlocked($html)) {
            throw new \RuntimeException('hanfu_1688_offer_page_blocked');
        }

        try {
            $brandName = $this->brandNameInNode($this->context($html));
            if ($brandName !== '') {
                return $brandName;
            }
        } catch (\RuntimeException) {
            // Mobile payloads do not expose window.context; inspect their JSON scripts next.
        }

        $decodedHtml = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('#<script[^>]*>(.*?)</script>#si', $decodedHtml, $scriptMatches);
        foreach ($scriptMatches[1] ?? [] as $rawScript) {
            $candidate = trim((string)$rawScript);
            if ($candidate === '' || !in_array($candidate[0], ['{', '['], true)) {
                continue;
            }
            try {
                $decoded = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            $brandName = $this->brandNameInNode($decoded);
            if ($brandName !== '') {
                return $brandName;
            }
        }

        foreach ([
            '/"name"\s*:\s*"品牌"\s*,\s*"value"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/u',
            '/"name"\s*:\s*"品牌"\s*,\s*"values"\s*:\s*\[\s*"((?:\\\\.|[^"\\\\])*)"/u',
        ] as $pattern) {
            if (preg_match($pattern, $decodedHtml, $match) !== 1) {
                continue;
            }
            try {
                $brandName = json_decode('"' . $match[1] . '"', true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $brandName = $match[1];
            }
            if (is_string($brandName) && trim($brandName) !== '') {
                return trim($brandName);
            }
        }

        if (str_contains($decodedHtml, '"propsList"')
            || str_contains($decodedHtml, '"skuProps"')
            || str_contains($decodedHtml, '"CpvEnhance"')
        ) {
            return '';
        }

        throw new \RuntimeException('hanfu_1688_offer_brand_context_missing');
    }

    private function brandNameInNode(mixed $node): string
    {
        if (!is_array($node)) {
            return '';
        }
        $name = trim((string)($node['name'] ?? ''));
        if (in_array($name, ['品牌', '品牌名称'], true)) {
            $values = $node['values'] ?? $node['value'] ?? [];
            $values = is_array($values) ? $values : [$values];
            foreach ($values as $value) {
                $value = trim((string)$value);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        foreach ($node as $child) {
            $brandName = $this->brandNameInNode($child);
            if ($brandName !== '') {
                return $brandName;
            }
        }
        return '';
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
        $json = $this->normalizeJavascriptObject($this->balancedObject($html, $start));
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

    private function normalizeJavascriptObject(string $input): string
    {
        $output = '';
        $quoted = false;
        $escaped = false;
        $length = strlen($input);
        for ($index = 0; $index < $length; ++$index) {
            $character = $input[$index];
            $output .= $character;
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
                continue;
            }
            if ($character !== '{' && $character !== ',') {
                continue;
            }
            $cursor = $index + 1;
            $whitespace = '';
            while ($cursor < $length && ctype_space($input[$cursor])) {
                $whitespace .= $input[$cursor];
                ++$cursor;
            }
            if ($cursor >= $length || $input[$cursor] === '"') {
                continue;
            }
            $keyStart = $cursor;
            while ($cursor < $length
                && (ctype_alnum($input[$cursor]) || in_array($input[$cursor], ['_', '$', '-'], true))
            ) {
                ++$cursor;
            }
            if ($cursor === $keyStart) {
                continue;
            }
            $key = substr($input, $keyStart, $cursor - $keyStart);
            $afterKey = $cursor;
            while ($afterKey < $length && ctype_space($input[$afterKey])) {
                ++$afterKey;
            }
            if ($afterKey >= $length || $input[$afterKey] !== ':') {
                continue;
            }
            $output .= $whitespace . '"' . $key . '"';
            $index = $cursor - 1;
        }
        return $output;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    /**
     * Convert the official public MTop offer-detail response to the same normalized
     * shape used by the desktop/mobile HTML parsers.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function parseMtop(array $payload, string $sourceUrl): array
    {
        $data = $payload;
        if (is_array($payload['data'] ?? null)) {
            $data = $payload['data'];
        } elseif (is_array($payload['result'] ?? null)) {
            $data = $payload['result'];
        }
        if ($data === []) {
            throw new \RuntimeException('hanfu_1688_mtop_detail_empty');
        }

        $components = $this->mtopComponents($data);
        $temp = is_array($data['tempModel'] ?? null) ? $data['tempModel'] : [];
        $share = is_array($data['shareModel'] ?? null) ? $data['shareModel'] : [];
        $skuModel = is_array($data['skuModel'] ?? null) ? $data['skuModel'] : [];
        $orderModel = is_array($data['orderParamModel'] ?? null) ? $data['orderParamModel'] : [];
        $banner = is_array($components['detail_image_banner'][0]['data'] ?? null)
            ? $components['detail_image_banner'][0]['data']
            : [];
        $titleComponent = is_array($components['detail_od_title'][0]['data'] ?? null)
            ? $components['detail_od_title'][0]['data']
            : [];

        $title = trim((string)($temp['offerTitle']
            ?? $share['subject']
            ?? $titleComponent['title']
            ?? $banner['offerInfoModel']['title']
            ?? ''));
        if ($title === '') {
            throw new \RuntimeException('hanfu_1688_offer_title_missing');
        }

        $offerId = trim((string)($temp['offerId']
            ?? $data['detailModel']['offerId']
            ?? $banner['offerId']
            ?? ''));
        if ($offerId === '' && preg_match('#/offer/([0-9]+)\.html#', $sourceUrl, $match) === 1) {
            $offerId = (string)$match[1];
        }
        if (preg_match('/^[1-9][0-9]{5,20}$/D', $offerId) !== 1) {
            throw new \RuntimeException('hanfu_1688_offer_id_invalid');
        }

        $imageUrls = [];
        $addImage = function (mixed $value) use (&$imageUrls): string {
            $url = $this->canonicalHttps(trim((string)$value));
            if ($url !== '') {
                $imageUrls[$url] = true;
            }
            return $url;
        };
        $addImage($temp['defaultOfferImg'] ?? '');
        $addImage($share['picUrl'] ?? '');
        foreach (is_array($share['imageUrls'] ?? null) ? $share['imageUrls'] : [] as $url) {
            $addImage($url);
        }
        foreach (is_array($banner['offerImgList'] ?? null) ? $banner['offerImgList'] : [] as $url) {
            $addImage($url);
        }
        foreach (is_array($banner['offerImages'] ?? null) ? $banner['offerImages'] : [] as $image) {
            if (is_array($image)) {
                $addImage($image['imgUrl'] ?? $image['imageUrl'] ?? '');
            }
        }

        $specifications = [];
        $sourceBrand = '';
        foreach (is_array($components['detail_od_property'] ?? null)
            ? $components['detail_od_property']
            : [] as $propertyComponent
        ) {
            $propertyData = is_array($propertyComponent['data'] ?? null) ? $propertyComponent['data'] : [];
            foreach (is_array($propertyData['propsList'] ?? null) ? $propertyData['propsList'] : [] as $property) {
                if (!is_array($property)) {
                    continue;
                }
                $name = trim((string)($property['name'] ?? ''));
                $rawValue = $property['value'] ?? '';
                if (is_array($rawValue)) {
                    $values = [];
                    foreach ($rawValue as $value) {
                        $value = trim((string)(is_array($value) ? ($value['name'] ?? $value['value'] ?? '') : $value));
                        if ($value !== '') {
                            $values[$value] = true;
                        }
                    }
                } else {
                    $value = trim((string)$rawValue);
                    $values = $value !== '' ? [$value => true] : [];
                }
                if ($name === '' || $values === []) {
                    continue;
                }
                $specifications[$name] = array_keys($values);
                if ($sourceBrand === '' && preg_match('/品牌/u', $name) === 1) {
                    $sourceBrand = (string)array_key_first($values);
                }
            }
        }

        $variantAxes = [];
        $optionImages = [];
        foreach (is_array($skuModel['skuProps'] ?? null) ? $skuModel['skuProps'] : [] as $axis) {
            if (!is_array($axis)) {
                continue;
            }
            $name = trim((string)($axis['prop'] ?? $axis['name'] ?? ''));
            $values = [];
            foreach (is_array($axis['value'] ?? null) ? $axis['value'] : [] as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $optionName = trim((string)($option['name'] ?? $option['value'] ?? ''));
                if ($optionName === '') {
                    continue;
                }
                $values[$optionName] = true;
                foreach (['imageUrl', 'imageURL', 'imgUrl', 'image'] as $imageKey) {
                    if (trim((string)($option[$imageKey] ?? '')) === '') {
                        continue;
                    }
                    $optionImage = $addImage($option[$imageKey]);
                    if ($optionImage !== '') {
                        $optionImages[$optionName] = $optionImage;
                    }
                    break;
                }
            }
            if ($name !== '' && $values !== []) {
                $axisValues = array_keys($values);
                $variantAxes[] = ['name' => $name, 'values' => $axisValues];
                $specifications[$name] = $axisValues;
            }
        }

        $normalizePrice = static function (mixed $value): ?string {
            $value = trim((string)$value);
            if ($value === '' || preg_match('/[0-9]+(?:\.[0-9]+)?/', $value, $match) !== 1) {
                return null;
            }
            return number_format((float)$match[0], 2, '.', '');
        };
        $basePrice = $normalizePrice($temp['price'] ?? $skuModel['skuPriceScale'] ?? null);

        $priceRanges = [];
        $rangeSource = $orderModel['orderParam']['skuParam']['skuRangePrices']
            ?? $orderModel['carOrderParam']['skuParam']['skuRangePrices']
            ?? [];
        foreach (is_array($rangeSource) ? $rangeSource : [] as $range) {
            if (!is_array($range)) {
                continue;
            }
            $rangePrice = $normalizePrice($range['price'] ?? null);
            $begin = max(1, (int)($range['beginAmount'] ?? $range['startAmount'] ?? 1));
            if ($rangePrice !== null) {
                $priceRanges[] = ['begin_amount' => $begin, 'price' => $rangePrice];
                $basePrice ??= $rangePrice;
            }
        }

        $skuMap = $skuModel['skuInfoMapOriginal']
            ?? $skuModel['skuInfoMap']
            ?? $skuModel['skuInfoMapAddCar']
            ?? [];
        $variants = [];
        $maximumPrice = $basePrice;
        foreach (is_array($skuMap) ? $skuMap : [] as $key => $sku) {
            if (!is_array($sku)) {
                continue;
            }
            $specification = trim(html_entity_decode(
                (string)($sku['specAttrs'] ?? $key),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            ));
            $variantPrice = $normalizePrice(
                $sku['price']
                ?? $sku['discountPrice']
                ?? $sku['promotionPrice']
                ?? $sku['salePrice']
                ?? $basePrice,
            );
            $variantImage = '';
            foreach (preg_split('/\s*>\s*/u', $specification) ?: [] as $optionName) {
                $optionName = trim($optionName);
                if (isset($optionImages[$optionName])) {
                    $variantImage = $optionImages[$optionName];
                    break;
                }
            }
            $sourceSkuId = trim((string)($sku['skuId'] ?? $sku['specId'] ?? ''));
            if ($sourceSkuId === '') {
                $sourceSkuId = $offerId . '-' . (count($variants) + 1);
            }
            $variants[] = [
                'source_sku_id' => $sourceSkuId,
                'specification' => $specification,
                'price' => $variantPrice,
                'public_available_quantity' => max(
                    0,
                    (int)($sku['canBookCount'] ?? $sku['amount'] ?? $sku['quantity'] ?? 0),
                ),
                'image_url' => $variantImage,
            ];
            if ($variantPrice !== null
                && ($maximumPrice === null || (float)$variantPrice > (float)$maximumPrice)
            ) {
                $maximumPrice = $variantPrice;
            }
        }

        $minimumOrderQuantity = max(1, (int)(
            $orderModel['orderParam']['beginNum']
            ?? $orderModel['carOrderParam']['beginNum']
            ?? $priceRanges[0]['begin_amount']
            ?? 1
        ));
        $detailModel = is_array($data['detailModel'] ?? null) ? $data['detailModel'] : [];
        $descriptionUrl = $this->mtopDescriptionUrl((string)($detailModel['detailUrl'] ?? ''));
        $weightKg = $this->mtopUnitWeightKg($data, $components, $temp);

        $result = [
            'offer_id' => $offerId,
            'title' => $title,
            'source_url' => $sourceUrl,
            'detail_url' => $sourceUrl,
            'detail_status' => 'mtop_public_detail',
            'currency' => 'CNY',
            'minimum_order_quantity' => $minimumOrderQuantity,
            'unit' => trim((string)($temp['offerUnit'] ?? '')) ?: '件',
            'image_urls' => array_keys($imageUrls),
            'variant_axes' => $variantAxes,
            'variants' => $variants,
            'specifications' => $specifications,
            'source_brand_name' => $sourceBrand,
            'source_brand_checked' => true,
            'source_brand_surface' => $sourceBrand !== ''
                ? 'mtop_property'
                : 'mtop_property_checked_empty',
            'price_ranges' => $priceRanges,
            'weight_kg' => $weightKg,
        ];
        if ($basePrice !== null) {
            $result['price'] = $basePrice;
        }
        if ($maximumPrice !== null) {
            $result['maximum_price'] = $maximumPrice;
        }
        if ($descriptionUrl !== '') {
            $result['detail_description_url'] = $descriptionUrl;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, list<array<string, mixed>>> $components
     * @param array<string, mixed> $temp
     */
    private function mtopUnitWeightKg(array $data, array $components, array $temp): ?float
    {
        $candidates = [
            $temp['unitWeight'] ?? null,
            $temp['weight'] ?? null,
            $data['productPackInfo']['fields']['unitWeight'] ?? null,
            $data['productPackInfo']['unitWeight'] ?? null,
            $data['packInfo']['unitWeight'] ?? null,
        ];
        foreach (['detail_od_pack', 'detail_pack_info', 'productPackInfo'] as $componentType) {
            foreach ($components[$componentType] ?? [] as $component) {
                if (!is_array($component)) {
                    continue;
                }
                $payload = is_array($component['data'] ?? null) ? $component['data'] : $component;
                $candidates[] = $payload['unitWeight'] ?? null;
                $candidates[] = $payload['fields']['unitWeight'] ?? null;
                $candidates[] = $payload['weight'] ?? null;
            }
        }
        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                $value = (float)$candidate;
                if ($value >= 0.05 && $value < 500) {
                    return $value;
                }
            }
        }
        $directPack = is_array($data['productPackInfo']['fields'] ?? null)
            ? $data['productPackInfo']['fields']
            : (is_array($data['productPackInfo'] ?? null) ? $data['productPackInfo'] : []);
        $fromDirect = $this->pieceWeightScaleKg($directPack);
        if ($fromDirect !== null) {
            return $fromDirect;
        }
        foreach (['detail_od_pack', 'detail_pack_info', 'productPackInfo'] as $componentType) {
            foreach ($components[$componentType] ?? [] as $component) {
                if (!is_array($component)) {
                    continue;
                }
                $payload = is_array($component['data'] ?? null) ? $component['data'] : $component;
                $fromScale = $this->pieceWeightScaleKg($payload);
                if ($fromScale !== null) {
                    return $fromScale;
                }
                $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : null;
                if (is_array($fields)) {
                    $fromScale = $this->pieceWeightScaleKg($fields);
                    if ($fromScale !== null) {
                        return $fromScale;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Prefer seller unitWeight (kg). Fallback: pieceWeightScale 重量(g) → kg.
     * Reject obvious placeholders (all 1g / resulting kg < 0.05).
     *
     * @param array<string, mixed> $packFields
     */
    private function pieceWeightScaleKg(array $packFields): ?float
    {
        $scale = $packFields['pieceWeightScale'] ?? null;
        if (!is_array($scale)) {
            return null;
        }
        $rows = $scale['pieceWeightScaleInfo'] ?? null;
        if (!is_array($rows) || $rows === []) {
            return null;
        }
        $isGrams = false;
        foreach (is_array($scale['columnList'] ?? null) ? $scale['columnList'] : [] as $column) {
            if (!is_array($column) || (string)($column['name'] ?? '') !== 'weight') {
                continue;
            }
            $label = strtolower((string)($column['label'] ?? ''));
            $isGrams = str_contains($label, '(g)') || str_contains($label, '（g）') || str_contains($label, '克');
            break;
        }
        $values = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_numeric($row['weight'] ?? null)) {
                continue;
            }
            $raw = (float)$row['weight'];
            if ($raw <= 0) {
                continue;
            }
            $values[] = $raw;
        }
        if ($values === []) {
            return null;
        }
        // Sellers often leave every SKU at placeholder "1".
        $unique = array_values(array_unique(array_map(static fn(float $v): string => (string)$v, $values)));
        if (count($unique) === 1 && abs((float)$unique[0] - 1.0) < 0.0001) {
            return null;
        }
        $max = max($values);
        $kg = $isGrams ? ($max / 1000.0) : $max;
        if ($kg < 0.05 || $kg >= 500) {
            return null;
        }

        return round($kg, 3);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, list<array<string, mixed>>>
     */
    private function mtopComponents(array $data): array
    {
        $layout = is_array($data['layoutProtocol'] ?? null) ? $data['layoutProtocol'] : [];
        $components = [];
        foreach (is_array($layout['components'] ?? null) ? $layout['components'] : [] as $component) {
            if (!is_array($component)) {
                continue;
            }
            $type = trim((string)($component['componentType'] ?? ''));
            $payload = $component['componentData'] ?? [];
            if (is_string($payload) && trim($payload) !== '') {
                try {
                    $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $payload = [];
                }
            }
            if ($type !== '' && is_array($payload)) {
                $components[$type][] = $payload;
            }
        }

        return $components;
    }

    private function mtopDescriptionUrl(string $url): string
    {
        $url = $this->canonicalHttps(html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return '';
        }
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === 'air.1688.com') {
            parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
            $embedded = $this->canonicalHttps((string)($query['url'] ?? ''));
            $embeddedHost = strtolower((string)parse_url($embedded, PHP_URL_HOST));
            if (in_array($embeddedHost, ['itemcdn.tmall.com', 'cbu01.alicdn.com', 'img.alicdn.com'], true)) {
                return $embedded;
            }
        }
        if (in_array($host, [
            'itemcdn.tmall.com',
            'cbu01.alicdn.com',
            'img.alicdn.com',
            'air.1688.com',
        ], true)) {
            return $url;
        }

        return '';
    }

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
            || !in_array(strtolower((string)($parts['host'] ?? '')), ['detail.1688.com', 'm.1688.com'], true)
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

    /**
     * Parse the public JavaScript wrapper returned by the 1688 detail document endpoint.
     *
     * @return array{detail_html:string,detail_image_urls:list<string>}
     */
    public function parseDescriptionUrl(string $html, string $sourceUrl): string
    {
        $this->offerIdFromUrl($sourceUrl);
        if (strlen($html) > self::MAX_DESCRIPTION_RESPONSE_BYTES) {
            throw new \RuntimeException('hanfu_1688_offer_response_too_large');
        }
        if ($this->isBlocked($html)) {
            throw new \RuntimeException('hanfu_1688_offer_page_blocked');
        }
        if (preg_match_all(
            '/"detailUrl"\s*:\s*"((?:\\\\.|[^"\\\\])+)"/u',
            $html,
            $matches,
        ) < 1) {
            throw new \RuntimeException('hanfu_1688_description_url_missing');
        }

        $lastError = null;
        foreach ($matches[1] as $encodedUrl) {
            try {
                $decodedUrl = json_decode('"' . $encodedUrl . '"', true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!is_string($decodedUrl)) {
                continue;
            }
            $descriptionUrl = $this->canonicalHttps($decodedUrl);
            if ($descriptionUrl === '') {
                continue;
            }
            try {
                $this->assertDescriptionUrl($descriptionUrl);
                return $descriptionUrl;
            } catch (\InvalidArgumentException $exception) {
                $lastError = $exception;
            }
        }

        if ($lastError instanceof \InvalidArgumentException) {
            throw $lastError;
        }
        throw new \RuntimeException('hanfu_1688_description_url_missing');
    }

    public function parseDescription(string $payload, string $sourceUrl): array
    {
        if (strlen($payload) > self::MAX_DESCRIPTION_RESPONSE_BYTES) {
            throw new \RuntimeException('hanfu_1688_description_response_too_large');
        }
        $this->assertDescriptionUrl($sourceUrl);
        if (preg_match('/^\s*var\s+offer_details\s*=\s*(\{.*\})\s*;?\s*$/sD', $payload, $match) !== 1) {
            throw new \RuntimeException('hanfu_1688_description_wrapper_invalid');
        }

        try {
            $decoded = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('hanfu_1688_description_json_invalid', 0, $exception);
        }
        $content = is_array($decoded) ? ($decoded['content'] ?? null) : null;
        if (!is_string($content) || trim($content) === '') {
            throw new \RuntimeException('hanfu_1688_description_content_missing');
        }

        [$document, $root] = $this->descriptionFragment($content);
        $images = [];
        $this->sanitizeDescriptionChildren($root, $images);
        $inner = trim($this->descriptionInnerHtml($document, $root));
        $plain = trim((string)preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ));
        if ($inner === '' || ($plain === '' && $images === [])) {
            throw new \RuntimeException('hanfu_1688_description_content_empty');
        }

        return [
            'detail_html' => '<div data-weline-product-description="1688">' . $inner . '</div>',
            'detail_image_urls' => array_keys($images),
        ];
    }

    /**
     * @param array<string,string> $assetIdsByUrl
     */
    public function localizeDescription(
        string $html,
        array $assetIdsByUrl,
        array $skippedImageUrls = [],
    ): string {
        if (!str_contains($html, 'data-weline-product-description="1688"')) {
            throw new \RuntimeException('hanfu_1688_description_marker_missing');
        }
        [$document, $container] = $this->descriptionFragment($html);
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('.//*[@data-weline-product-description="1688"]', $container);
        $root = $nodes !== false ? $nodes->item(0) : null;
        if (!$root instanceof \DOMElement) {
            throw new \RuntimeException('hanfu_1688_description_marker_missing');
        }

        $skipped = [];
        foreach ($skippedImageUrls as $url) {
            $url = trim((string)$url);
            if ($url !== '') {
                $skipped[$url] = true;
            }
        }
        $images = [];
        foreach ($root->getElementsByTagName('img') as $image) {
            $images[] = $image;
        }
        foreach ($images as $image) {
            $sourceUrl = trim($image->getAttribute('src'));
            $assetId = strtolower(trim((string)($assetIdsByUrl[$sourceUrl] ?? '')));
            if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $assetId) !== 1) {
                if (isset($skipped[$sourceUrl]) && $image->parentNode instanceof \DOMNode) {
                    $image->parentNode->removeChild($image);
                    continue;
                }
                throw new \RuntimeException('hanfu_1688_description_asset_missing');
            }
            $image->setAttribute('src', 'asset://' . $assetId);
        }

        $localized = trim((string)$document->saveHTML($root));
        if ($localized === '' || preg_match('#<img\b[^>]*\bsrc=["\']https?://#i', $localized) === 1) {
            throw new \RuntimeException('hanfu_1688_description_localization_failed');
        }

        return $localized;
    }

    private function assertDescriptionUrl(string $url): void
    {
        $parts = parse_url(trim($url));
        $host = is_array($parts) ? strtolower(rtrim((string)($parts['host'] ?? ''), '.')) : '';
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || !in_array($host, ['itemcdn.tmall.com', 'desc.alicdn.com'], true)
        ) {
            throw new \InvalidArgumentException('hanfu_1688_description_url_invalid');
        }
    }

    /** @return array{0:\DOMDocument,1:\DOMElement} */
    private function descriptionFragment(string $html): array
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<!doctype html><html><head><meta charset="utf-8"></head><body>'
                . '<div id="weline-description-root">' . $html . '</div>'
                . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new \RuntimeException('hanfu_1688_description_html_invalid');
        }
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//*[@id="weline-description-root"]');
        $root = $nodes !== false ? $nodes->item(0) : null;
        if (!$root instanceof \DOMElement) {
            throw new \RuntimeException('hanfu_1688_description_html_invalid');
        }

        return [$document, $root];
    }

    /** @param array<string,true> $images */
    private function sanitizeDescriptionChildren(\DOMNode $parent, array &$images): void
    {
        for ($child = $parent->firstChild; $child !== null; $child = $next) {
            $next = $child->nextSibling;
            if ($child instanceof \DOMComment) {
                $parent->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DESCRIPTION_DROP_WITH_CONTENT, true)) {
                $parent->removeChild($child);
                continue;
            }
            if (!in_array($tag, self::DESCRIPTION_ALLOWED_TAGS, true)) {
                $this->sanitizeDescriptionChildren($child, $images);
                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }
            if ($tag === 'img') {
                $sourceUrl = '';
                foreach (['src', 'data-src', 'data-lazyload-src', 'data-original'] as $attribute) {
                    $sourceUrl = $this->canonicalDescriptionImageUrl($child->getAttribute($attribute));
                    if ($sourceUrl !== '') {
                        break;
                    }
                }
                if ($sourceUrl === ''
                    || (!isset($images[$sourceUrl]) && count($images) >= self::MAX_DESCRIPTION_IMAGES)
                ) {
                    $parent->removeChild($child);
                    continue;
                }
                $alt = mb_substr(trim($child->getAttribute('alt')), 0, 180);
                $this->clearDescriptionAttributes($child);
                $child->setAttribute('src', $sourceUrl);
                $child->setAttribute('alt', $alt);
                $child->setAttribute('loading', 'lazy');
                $child->setAttribute('decoding', 'async');
                $images[$sourceUrl] = true;
                continue;
            }

            if ($tag === 'table' && $this->isPromoOrRecommendedProductBlock($child)) {
                $parent->removeChild($child);
                continue;
            }

            $this->clearDescriptionAttributes($child);
            $this->sanitizeDescriptionChildren($child, $images);
        }
    }

    private function clearDescriptionAttributes(\DOMElement $element): void
    {
        while ($element->attributes->length > 0) {
            $attribute = $element->attributes->item(0);
            if ($attribute === null) {
                break;
            }
            $element->removeAttributeNode($attribute);
        }
    }

    private function isPromoOrRecommendedProductBlock(\DOMElement $element): bool
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $element->textContent ?? ''));
        if ($text === '') {
            return false;
        }
        if (preg_match('/火爆大促销|狂欢购|猜你喜欢|推荐商品|店铺推荐|看了又看|同类热销|WUYIKUANHUANGOU/u', $text) === 1) {
            return true;
        }
        if (preg_match('/[￥¥]\s*\d+/u', $text) !== 1) {
            return false;
        }

        return $element->getElementsByTagName('img')->length > 0
            || preg_match('/批发/u', $text) === 1;
    }

    private function canonicalDescriptionImageUrl(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        } elseif (str_starts_with($url, 'http://')) {
            $url = 'https://' . substr($url, 7);
        }
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower(rtrim((string)($parts['host'] ?? ''), '.')) : '';
        $allowedHost = $host === 'alicdn.com'
            || str_ends_with($host, '.alicdn.com')
            || $host === 'tbcdn.cn'
            || str_ends_with($host, '.tbcdn.cn');
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || filter_var($host, FILTER_VALIDATE_IP)
            || !$allowedHost
        ) {
            return '';
        }

        return $url;
    }

    private function descriptionInnerHtml(\DOMDocument $document, \DOMNode $root): string
    {
        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= (string)$document->saveHTML($child);
        }

        return $html;
    }

    private function isBlocked(string $html): bool
    {
        $lower = strtolower($html);
        return str_contains($lower, '"action":"captcha"')
            || str_contains($lower, '_____tmd_____/punish')
            || (str_contains($lower, 'login.taobao.com') && !str_contains($html, 'window.context='));
    }
}
