<?php

declare(strict_types=1);

namespace Weline\Product\Service\Hanfu1688;

final class FactoryPageParser
{
    /** @return array<string,mixed> */
    public function parse(string $html, string $url): array
    {
        $this->assertFactoryUrl($url);
        if ($this->isBlocked($html)) {
            throw new \RuntimeException('hanfu_1688_factory_page_blocked');
        }
        $pageData = $this->decodeAssignedObject($html, 'window.$$pageData=');
        $offerList = null;
        $shopInfo = null;
        foreach ($pageData as $component) {
            if (!is_array($component)) {
                continue;
            }
            if ($offerList === null && isset($component['initOfferList']) && is_array($component['initOfferList'])) {
                $offerList = $component['initOfferList'];
            }
            if (isset($component['initShopInfo']) && is_array($component['initShopInfo'])) {
                $candidate = $component['initShopInfo'];
                if (trim((string)($candidate['memberId'] ?? '')) !== ''
                    && trim((string)($candidate['name'] ?? '')) !== ''
                ) {
                    $shopInfo = $candidate;
                }
            }
        }
        if (!is_array($offerList) || !is_array($shopInfo)) {
            throw new \RuntimeException('hanfu_1688_factory_page_structure_missing');
        }
        $params = is_array($offerList['__params__'] ?? null) ? $offerList['__params__'] : [];
        $memberId = trim((string)($params['factoryMemberId'] ?? $shopInfo['memberId'] ?? ''));
        $companyName = trim((string)($shopInfo['name'] ?? ''));
        $shopUrl = $this->canonicalShopUrl((string)($shopInfo['shopPcWpIndexUrl'] ?? ''));
        $total = max(0, (int)($offerList['total'] ?? 0));
        $page = max(1, (int)($offerList['page'] ?? $params['page'] ?? 1));
        $pageSize = max(1, (int)($offerList['pageSize'] ?? $params['pageSize'] ?? 20));
        if ($memberId === '' || $companyName === '' || $shopUrl === '' || $total < 1) {
            throw new \RuntimeException('hanfu_1688_factory_identity_incomplete');
        }

        $offers = $this->normalizeOffers(is_array($offerList['data'] ?? null) ? $offerList['data'] : []);
        if ($offers === [] && $total > 0) {
            throw new \RuntimeException('hanfu_1688_factory_offer_list_empty');
        }
        $lastPage = (int)ceil($total / $pageSize);

        return [
            'factory_url' => $url,
            'member_id' => $memberId,
            'company_name' => $companyName,
            'shop_url' => $shopUrl,
            'login_id' => trim((string)($shopInfo['loginId'] ?? '')),
            'self_brands' => $this->selfBrands($shopInfo),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'last_page' => $lastPage,
            'next_page' => $page < $lastPage ? $page + 1 : null,
            'offers' => $offers,
            'fingerprint' => hash('sha256', json_encode(
                [$memberId, $page, $total, array_column($offers, 'offer_id')],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )),
        ];
    }

    /**
     * @param array<string,mixed> $response
     * @return array{total:int,page:int,page_size:int,last_page:int,next_page:?int,offers:list<array<string,mixed>>,fingerprint:string}
     */
    public function parseMtopPage(array $response, string $memberId, int $requestedPage): array
    {
        $ret = $response['ret'] ?? [];
        if (!is_array($ret) || !in_array('SUCCESS::调用成功', $ret, true)) {
            throw new \RuntimeException('hanfu_1688_mtop_request_failed');
        }
        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new \RuntimeException('hanfu_1688_mtop_structure_invalid');
        }
        $total = max(0, (int)($data['total'] ?? 0));
        $page = max(1, (int)($data['page'] ?? $requestedPage));
        $pageSize = max(1, (int)($data['pageSize'] ?? 20));
        $offers = $this->normalizeOffers(is_array($data['data'] ?? null) ? $data['data'] : []);
        if ($total > 0 && $offers === []) {
            throw new \RuntimeException('hanfu_1688_mtop_ambiguous_empty_page');
        }
        $lastPage = $total === 0 ? 1 : (int)ceil($total / $pageSize);
        return [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'last_page' => $lastPage,
            'next_page' => $page < $lastPage ? $page + 1 : null,
            'offers' => $offers,
            'fingerprint' => hash('sha256', json_encode(
                [$memberId, $page, $total, array_column($offers, 'offer_id')],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )),
        ];
    }

    /** @param list<mixed> $rows @return list<array<string,mixed>> */
    private function normalizeOffers(array $rows): array
    {
        $offers = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $offerId = trim((string)($row['offerId'] ?? $row['itemId'] ?? ''));
            $title = trim((string)($row['title'] ?? ''));
            if (preg_match('/^[1-9][0-9]{0,20}$/D', $offerId) !== 1 || $title === '') {
                continue;
            }
            $priceData = is_array($row['price'] ?? null) ? $row['price'] : [];
            $price = trim((string)($priceData['minPrice'] ?? ''));
            if ($price === '' && is_array($priceData['PriceRanges'] ?? null)) {
                $price = trim((string)($priceData['PriceRanges'][0]['price'] ?? ''));
            }
            if ($price !== '' && preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $price) !== 1) {
                $price = '';
            }
            $images = [];
            foreach (array_merge(
                is_array($row['itemPictureList'] ?? null) ? $row['itemPictureList'] : [],
                [(string)($row['image'] ?? $row['picture'] ?? $row['media'] ?? '')],
            ) as $image) {
                $image = $this->canonicalHttps((string)$image);
                if ($image !== '') {
                    $images[$image] = true;
                }
            }
            $offers[$offerId] = [
                'offer_id' => $offerId,
                'title' => $title,
                'short_title' => trim((string)($row['shortTitle'] ?? '')),
                'price' => $price !== '' ? $price : null,
                'currency' => 'CNY',
                'minimum_order_quantity' => max(0, (int)($row['minSaleNum'] ?? 0)),
                'unit' => trim((string)($row['saleCount']['unit'] ?? '')),
                'detail_url' => 'https://detail.1688.com/offer/' . $offerId . '.html',
                'image_urls' => array_keys($images),
                'listing_specifications' => array_values(array_filter(
                    is_array($row['cpvList'] ?? null) ? $row['cpvList'] : [],
                    static fn(mixed $value): bool => is_string($value) && trim($value) !== '',
                )),
            ];
        }
        uksort($offers, static fn(string $left, string $right): int => strlen($left) <=> strlen($right) ?: strcmp($left, $right));
        return array_values($offers);
    }

    /** @return list<string> */
    private function selfBrands(array $shopInfo): array
    {
        $brands = [];
        foreach (($shopInfo['brand']['selfBrandList'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['brand_name'] ?? ''));
            if ($name !== '') {
                $brands[$name] = true;
            }
        }
        return array_keys($brands);
    }

    /** @return array<string,mixed> */
    private function decodeAssignedObject(string $html, string $marker): array
    {
        $position = strpos($html, $marker);
        if ($position === false) {
            throw new \RuntimeException('hanfu_1688_factory_page_data_missing');
        }
        $start = strpos($html, '{', $position + strlen($marker));
        if ($start === false) {
            throw new \RuntimeException('hanfu_1688_factory_page_data_missing');
        }
        $json = $this->balancedObject($html, $start);
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('hanfu_1688_factory_page_data_invalid', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('hanfu_1688_factory_page_data_invalid');
        }
        return $decoded;
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

    private function assertFactoryUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== 'www.1688.com'
            || preg_match('#^/factory/b2b-[a-z0-9-]+\.html$#D', (string)($parts['path'] ?? '')) !== 1
        ) {
            throw new \InvalidArgumentException('hanfu_1688_factory_url_invalid');
        }
    }

    private function canonicalShopUrl(string $url): string
    {
        $url = $this->canonicalHttps($url);
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        return ($host === '1688.com' || str_ends_with($host, '.1688.com')) ? rtrim($url, '/') : '';
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

    private function isBlocked(string $html): bool
    {
        $lower = strtolower($html);
        return str_contains($lower, '"action":"captcha"')
            || str_contains($lower, '_____tmd_____/punish')
            || str_contains($lower, 'login.taobao.com');
    }
}
