<?php

declare(strict_types=1);

namespace Weline\CjDropshipping\Extends\Module\Weline_Dropship\DropshipProvider;

use Weline\CjDropshipping\Model\CjWarehouse;
use Weline\CjDropshipping\Service\CjApiClient;
use Weline\CjDropshipping\Service\CjCategoryLocalizer;
use Weline\CjDropshipping\Service\CjProductTitleLocalizer;
use Weline\CjDropshipping\Service\CjWarehouseMapper;
use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;
use Weline\Dropship\Model\DropshipFulfillment;
use Weline\Dropship\Interface\DropshipCatalogBrowseProviderInterface;
use Weline\Dropship\Interface\DropshipCatalogProviderInterface;
use Weline\Dropship\Interface\DropshipCategoryPathLocalizerInterface;
use Weline\Dropship\Interface\DropshipFreightProviderInterface;
use Weline\Dropship\Interface\DropshipFulfillmentProviderInterface;
use Weline\Dropship\Interface\DropshipWarehouseProviderInterface;
use Weline\Dropship\Interface\DropshipWebhookProviderInterface;
use Weline\Framework\Manager\ObjectManager;

class CjProvider implements
    DropshipCatalogBrowseProviderInterface,
    DropshipCatalogProviderInterface,
    DropshipCategoryPathLocalizerInterface,
    DropshipFulfillmentProviderInterface,
    DropshipFreightProviderInterface,
    DropshipWebhookProviderInterface,
    DropshipWarehouseProviderInterface
{
    public function getCode(): string
    {
        return 'cj';
    }

    public function localizeCategoryPath(string $path, string $locale): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $localized = CjCategoryLocalizer::localizeNodes([
            ['id' => '_', 'name' => $path, 'path' => $path],
        ], $locale);

        return trim((string)($localized[0]['path'] ?? $path));
    }

    public function getCapabilities(): array
    {
        return [
            'catalog' => true,
            'browse' => true,
            'browse_country_filter' => true,
            'fulfillment' => true,
            'freight' => true,
            'webhook' => true,
            // 壳能力全开；CJ 沙盒真推边界见 doc/功能现状.md（纠纷沙盒不可用，勿因此关掉 capability）
            'webhook_order' => true,
            'webhook_product' => true,
            'webhook_stock' => true,
            'webhook_logistics' => true,
            'webhook_makeup' => true,
            'webhook_private_order' => true,
            'webhook_dispute' => true,
            'warehouse' => true,
        ];
    }

    public function getDisplayMetadata(): array
    {
        return [
            'title' => 'CJ Dropshipping',
            'module' => 'Weline_CjDropshipping',
            'sort_order' => 10,
            'description' => '默认货源供应商',
        ];
    }

    public function getConfigSchema(): array
    {
        return [
            'fields' => ['email', 'api_key', 'access_token', 'order_sandbox', 'freight_on_failure'],
            'config_center' => [
                'module' => 'Weline_CjDropshipping',
                'area' => 'backend',
                'group' => 'dropship_channel_cj',
                'guide_key' => 'dropship/channel/cj/email',
                'guide_title' => 'CJ 凭证',
                'guide_summary' => '填写 CJ Email 与 API Key 后可探活/拉品/推单；可开订单沙盒；可配置运费试算失败策略。',
            ],
        ];
    }

    public function freightOnFailure(): string
    {
        return $this->client()->freightOnFailure();
    }

    public function probeConnection(array $context = []): array
    {
        return $this->client()->probe();
    }

    public function searchProducts(array $query): array
    {
        $locale = (string)($query['locale'] ?? '');
        // /product/list 同时返回 productName(中文) + productNameEn；listV2 仅 nameEn。
        $params = [
            'pageNum' => max(1, (int)($query['page'] ?? 1)),
            'pageSize' => min(100, max(1, (int)($query['size'] ?? 20))),
        ];
        $keyword = trim((string)($query['keyword'] ?? ''));
        if ($keyword !== '') {
            if (self::localePrefersZh($locale)) {
                $params['productName'] = $keyword;
            } else {
                $params['productNameEn'] = $keyword;
            }
        }
        $country = strtoupper(trim((string)($query['country_code'] ?? '')));
        if ($country !== '') {
            $params['countryCode'] = $country;
        }
        $categoryId = trim((string)($query['category_id'] ?? ''));
        if ($categoryId !== '') {
            $params['categoryId'] = $categoryId;
        }

        $resp = $this->client()->get('/product/list', $params);
        $code = (int)($resp['code'] ?? 0);
        if ($code !== 0 && $code !== 200) {
            throw new \RuntimeException((string)($resp['message'] ?? ('cj_list:' . $code)));
        }
        $out = [];
        foreach (self::extractProductRows($resp) as $row) {
            $snap = self::mapProductRow($row, $country, $locale);
            if ($snap !== null) {
                $out[] = $snap;
            }
        }
        if ($out !== [] && self::localePrefersZh($locale)) {
            $need = [];
            foreach ($out as $snap) {
                $t = trim((string)$snap->title);
                if ($t !== '' && !CjProductTitleLocalizer::containsCjk($t)) {
                    $need[$t] = true;
                }
            }
            if ($need !== []) {
                $localized = CjProductTitleLocalizer::localizeMany(array_keys($need), $locale);
                foreach ($out as $i => $snap) {
                    $t = trim((string)$snap->title);
                    $nt = $localized[$t] ?? $t;
                    if ($nt !== $t && $nt !== '') {
                        $arr = $snap->toArray();
                        $arr['title'] = $nt;
                        $out[$i] = DropshipCatalogSnapshot::fromArray($arr);
                    }
                }
            }
        }

        return $out;
    }

    public function listCategories(array $query = []): array
    {
        $resp = $this->client()->get('/product/getCategory');
        $code = (int)($resp['code'] ?? 0);
        if ($code !== 0 && $code !== 200) {
            throw new \RuntimeException((string)($resp['message'] ?? ('cj_getCategory:' . $code)));
        }
        $data = $resp['data'] ?? [];
        if (!is_array($data)) {
            return [];
        }
        $locale = (string)($query['locale'] ?? '');

        return CjCategoryLocalizer::localizeNodes(self::flattenCategoryTree($data), $locale);
    }

    /**
     * @param list<array<string, mixed>>|array<string, mixed> $tree
     * @return list<array{id:string,name:string,parent_id?:string,level?:int,path?:string}>
     */
    public static function flattenCategoryTree(array $tree): array
    {
        $out = [];
        foreach ($tree as $first) {
            if (!is_array($first)) {
                continue;
            }
            $firstName = trim((string)($first['categoryFirstName'] ?? $first['name'] ?? ''));
            $seconds = $first['categoryFirstList'] ?? $first['children'] ?? [];
            if (!is_array($seconds)) {
                continue;
            }
            foreach ($seconds as $second) {
                if (!is_array($second)) {
                    continue;
                }
                $secondName = trim((string)($second['categorySecondName'] ?? $second['name'] ?? ''));
                $thirds = $second['categorySecondList'] ?? $second['children'] ?? [];
                if (!is_array($thirds)) {
                    continue;
                }
                foreach ($thirds as $third) {
                    if (!is_array($third)) {
                        continue;
                    }
                    $id = trim((string)($third['categoryId'] ?? $third['id'] ?? ''));
                    $name = trim((string)($third['categoryName'] ?? $third['name'] ?? ''));
                    if ($id === '' || $name === '') {
                        continue;
                    }
                    $pathParts = array_values(array_filter([$firstName, $secondName, $name], static fn ($v) => $v !== ''));
                    $parentId = '';
                    if ($secondName !== '') {
                        $parentId = 'l2:' . md5($firstName . '|' . $secondName);
                    } elseif ($firstName !== '') {
                        $parentId = 'l1:' . md5($firstName);
                    }
                    $out[] = [
                        'id' => $id,
                        'name' => $name,
                        'parent_id' => $parentId,
                        'level' => 3,
                        'path' => implode(' / ', $pathParts),
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $resp
     * @return list<array<string, mixed>>
     */
    public static function extractProductRows(array $resp): array
    {
        $data = $resp['data'] ?? null;
        if (!is_array($data)) {
            return [];
        }
        foreach (['list', 'content', 'records', 'products', 'productList'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data = $data[$key];
                break;
            }
        }
        // 纯列表；过滤掉分页元数据被当成商品行的情况。
        if ($data === [] || !array_is_list($data)) {
            return [];
        }
        $rows = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            // listV2: content[] 常为 { productList: [...], relatedCategoryList, keyWord }
            if (isset($row['productList']) && is_array($row['productList']) && array_is_list($row['productList'])) {
                foreach ($row['productList'] as $product) {
                    if (is_array($product)) {
                        $rows[] = $product;
                    }
                }
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapProductRow(array $row, string $countryCode = '', string $locale = ''): ?DropshipCatalogSnapshot
    {
        $spu = trim((string)($row['pid'] ?? $row['id'] ?? $row['productId'] ?? $row['spu'] ?? ''));
        $title = self::pickLocalizedTitle($row, $locale);
        if ($spu === '' && $title === '') {
            return null;
        }
        $price = (float)($row['sellPrice'] ?? $row['nowPrice'] ?? $row['discountPrice'] ?? $row['price'] ?? 0);
        $qty = (int)($row['warehouseInventoryNum'] ?? $row['listedNum'] ?? $row['inventory'] ?? $row['stock'] ?? 0);
        $categoryId = trim((string)($row['categoryId'] ?? $row['category_id'] ?? ''));
        $categoryPath = self::buildCategoryPath($row, $locale);
        $thumb = trim((string)($row['bigImage'] ?? $row['image'] ?? $row['productImage'] ?? $row['img'] ?? ''));
        $media = [];
        if ($thumb !== '' && (str_starts_with($thumb, 'http://') || str_starts_with($thumb, 'https://'))) {
            $media[] = ['url' => $thumb, 'type' => 'image', 'role' => 'thumb'];
        }
        $currency = strtoupper(trim((string)($row['currency'] ?? $row['priceCurrency'] ?? $row['origin_currency'] ?? '')));
        if ($currency === '') {
            $currency = 'USD'; // CJ OpenAPI list 价默认美元；响应常省略 currency 字段
        }

        return DropshipCatalogSnapshot::fromArray([
            'provider_code' => 'cj',
            'external_spu' => $spu !== '' ? $spu : ('cj-' . substr(md5($title), 0, 8)),
            'external_sku' => (string)($row['productSku'] ?? $row['sku'] ?? ''),
            'title' => $title !== '' ? $title : $spu,
            'origin_currency' => $currency,
            'origin_price_minor' => (int)round($price * 100),
            'qty' => $qty,
            'shelf_status' => 'active',
            'country_code' => $countryCode,
            'category_id' => $categoryId,
            'category_path' => $categoryPath,
            'media' => $media,
            'shipping' => self::extractShippingDims($row),
            'raw' => $row,
            'suggested_eav' => ['dropship_source' => 'cj'],
        ]);
    }

    /**
     * Prefer Chinese productName when locale is zh* and the field contains CJK.
     * productName may be a JSON array string — take the first non-empty item.
     * When zh* but productName is English-only (common for US warehouse), prefer
     * the fuller productNameEn, then optionally localize via CjProductTitleLocalizer.
     *
     * @param array<string, mixed> $row
     */
    public static function pickLocalizedTitle(array $row, string $locale = ''): string
    {
        $zh = self::localePrefersZh($locale);
        $en = self::normalizeNameField($row['productNameEn'] ?? $row['nameEn'] ?? null);
        $zhName = self::normalizeNameField($row['productName'] ?? $row['productNameZh'] ?? $row['productNameCn'] ?? $row['name'] ?? null);
        if ($zh) {
            if (CjProductTitleLocalizer::containsCjk($zhName)) {
                return $zhName;
            }
            if (CjProductTitleLocalizer::containsCjk($en)) {
                return $en;
            }
            $base = $en !== '' ? $en : $zhName;

            return $base;
        }

        return $en !== '' ? $en : $zhName;
    }

    public static function localePrefersZh(string $locale): bool
    {
        $locale = strtolower(str_replace('-', '_', trim($locale)));
        if ($locale === '') {
            return true;
        }

        return str_starts_with($locale, 'zh');
    }

    private static function normalizeNameField(mixed $raw): string
    {
        if (is_array($raw)) {
            foreach ($raw as $part) {
                $t = trim((string)$part);
                if ($t !== '') {
                    return $t;
                }
            }

            return '';
        }
        $s = trim((string)($raw ?? ''));
        if ($s === '') {
            return '';
        }
        if ($s[0] === '[') {
            $decoded = json_decode($s, true);
            if (is_array($decoded)) {
                foreach ($decoded as $part) {
                    $t = trim((string)$part);
                    if ($t !== '') {
                        return $t;
                    }
                }
            }
        }

        return $s;
    }

    public function getProduct(array $identity): ?DropshipCatalogSnapshot
    {
        try {
            $resp = $this->client()->get('/product/query', [
                'pid' => (string)($identity['pid'] ?? $identity['external_spu'] ?? ''),
            ]);
            $row = $resp['data'] ?? null;
            if (!is_array($row)) {
                return null;
            }

            $locale = (string)($identity['locale'] ?? '');
            $country = strtoupper(trim((string)($identity['country_code'] ?? '')));

            return self::mapProductDetail($row, $country, $locale);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Full product/query payload → snapshot with gallery media + variant axes/images.
     *
     * @param array<string, mixed> $row
     */
    public static function mapProductDetail(array $row, string $countryCode = '', string $locale = ''): ?DropshipCatalogSnapshot
    {
        $base = self::mapProductRow($row, $countryCode, $locale);
        if ($base === null) {
            return null;
        }

        $media = self::extractGalleryMedia($row);
        if ($media === []) {
            $media = $base->media;
        }

        $variantMapped = self::mapVariants($row);
        $data = $base->toArray();
        $data['media'] = $media;
        $data['variants'] = $variantMapped;
        $data['external_sku'] = (string)($row['productSku'] ?? $data['external_sku'] ?? '');
        $data['description'] = self::extractDescription($row);
        $data['attributes'] = self::assembleAttributes($row);
        $data['shipping'] = self::extractShippingDims($row);
        $data['raw'] = $row;

        return DropshipCatalogSnapshot::fromArray($data);
    }

    /**
     * Normalize CJ weight/dims into shell shipping (kg / cm).
     * variantWeight is grams when >30; variant L/W/H are mm when >=100 else cm.
     *
     * @param array<string, mixed> $row
     * @return array{weight_kg:float,length_cm:float,width_cm:float,height_cm:float}
     */
    public static function extractShippingDims(array $row): array
    {
        $weightG = 0.0;
        $length = 0.0;
        $width = 0.0;
        $height = 0.0;

        $variants = $row['variants'] ?? null;
        if (is_array($variants)) {
            $fallback = null;
            foreach ($variants as $variant) {
                if (!is_array($variant)) {
                    continue;
                }
                $w = (float)($variant['variantWeight'] ?? 0);
                $l = (float)($variant['variantLength'] ?? 0);
                $wd = (float)($variant['variantWidth'] ?? 0);
                $h = (float)($variant['variantHeight'] ?? 0);
                if ($w <= 0 || $l <= 0 || $wd <= 0 || $h <= 0) {
                    continue;
                }
                $candidate = [$w, $l, $wd, $h];
                $inv = (int)($variant['inventoryNum'] ?? 0);
                if ($inv > 0) {
                    [$weightG, $length, $width, $height] = $candidate;
                    break;
                }
                if ($fallback === null) {
                    $fallback = $candidate;
                }
            }
            if ($weightG <= 0 && $fallback !== null) {
                [$weightG, $length, $width, $height] = $fallback;
            }
        }

        if ($weightG <= 0) {
            $weightG = self::parseWeightGrams((string)($row['packingWeight'] ?? $row['productWeight'] ?? ''));
        }

        [$lengthCm, $widthCm, $heightCm] = self::cjDimsToCm($length, $width, $height);

        return [
            'weight_kg' => self::gramsToKg($weightG),
            'length_cm' => $lengthCm,
            'width_cm' => $widthCm,
            'height_cm' => $heightCm,
        ];
    }

    public static function parseWeightGrams(string $raw): float
    {
        $raw = trim($raw);
        if ($raw === '') {
            return 0.0;
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*-\s*(\d+(?:\.\d+)?)/', $raw, $m)) {
            return ((float)$m[1] + (float)$m[2]) / 2.0;
        }
        if (preg_match('/(\d+(?:\.\d+)?)/', $raw, $m)) {
            return (float)$m[1];
        }

        return 0.0;
    }

    public static function gramsToKg(float $gramsOrKg): float
    {
        if ($gramsOrKg <= 0) {
            return 0.0;
        }
        // CJ weights are grams when clearly above parcel kg scale.
        return $gramsOrKg > 30.0 ? ($gramsOrKg / 1000.0) : $gramsOrKg;
    }

    public static function cjLengthToCm(float $value, bool $treatAsMm = false): float
    {
        if ($value <= 0) {
            return 0.0;
        }

        return $treatAsMm ? ($value / 10.0) : $value;
    }

    /**
     * @return array{0:float,1:float,2:float} length/width/height in cm
     */
    public static function cjDimsToCm(float $length, float $width, float $height): array
    {
        $asMm = max($length, $width, $height) >= 100.0;

        return [
            self::cjLengthToCm($length, $asMm),
            self::cjLengthToCm($width, $asMm),
            self::cjLengthToCm($height, $asMm),
        ];
    }

    /**
     * Hierarchical category path for shell ensure (/dropship/cj/...).
     *
     * @param array<string, mixed> $row
     */
    public static function buildCategoryPath(array $row, string $locale = ''): string
    {
        $parts = [];
        foreach ([
            $row['oneCategoryName'] ?? $row['categoryFirstName'] ?? null,
            $row['twoCategoryName'] ?? $row['categorySecondName'] ?? null,
            $row['threeCategoryName'] ?? null,
        ] as $raw) {
            $name = trim((string)($raw ?? ''));
            if ($name !== '') {
                $parts[] = $name;
            }
        }
        $leaf = trim((string)($row['categoryName'] ?? $row['category_path'] ?? ''));
        if ($leaf !== '' && ($parts === [] || !in_array($leaf, $parts, true))) {
            $parts[] = $leaf;
        }
        // Deduplicate consecutive identical segments.
        $deduped = [];
        foreach ($parts as $p) {
            if ($deduped === [] || $deduped[array_key_last($deduped)] !== $p) {
                $deduped[] = $p;
            }
        }
        $path = implode(' / ', $deduped);
        if ($path === '') {
            return '';
        }
        if (self::localePrefersZh($locale)) {
            $categoryId = trim((string)($row['categoryId'] ?? $row['category_id'] ?? ''));
            $localized = CjCategoryLocalizer::localizeNodes([
                ['id' => $categoryId !== '' ? $categoryId : '_', 'name' => $leaf !== '' ? $leaf : $path, 'path' => $path],
            ], $locale);
            $path = trim((string)($localized[0]['path'] ?? $path));
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function extractDescription(array $row): string
    {
        $html = trim((string)($row['description'] ?? ''));
        if ($html === '') {
            return '';
        }

        return mb_substr($html, 0, 18000);
    }

    /**
     * Provider-assembled ProductAdmin attribute rows (registered codes only).
     *
     * @param array<string, mixed> $row
     * @return list<array<string,mixed>>
     */
    public static function assembleAttributes(array $row): array
    {
        $attrs = [];
        $attrs[] = [
            'attribute_code' => 'source_platform',
            'value' => 'cj',
            'scope_state' => 'explicit',
        ];

        $specs = [];
        foreach ([
            'materialName' => '材质',
            'materialNameEn' => 'Material',
            'packingName' => '包装',
            'packingNameEn' => 'Packing',
            'productKey' => '规格键',
            'productPro' => '货品属性',
            'entryName' => '报关名',
            'entryNameEn' => 'Entry Name',
        ] as $field => $label) {
            $value = self::normalizeJsonListField($row[$field] ?? null);
            if ($value !== '') {
                $specs[$label] = $value;
            }
        }
        if ($specs !== []) {
            $attrs[] = [
                'attribute_code' => 'source_public_specs',
                'value' => json_encode($specs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'scope_state' => 'explicit',
            ];
        }

        return $attrs;
    }

    private static function normalizeJsonListField(mixed $raw): string
    {
        if (is_array($raw)) {
            $parts = [];
            foreach ($raw as $item) {
                $t = trim((string)$item);
                if ($t !== '') {
                    $parts[] = $t;
                }
            }

            return implode(',', $parts);
        }
        $s = trim((string)($raw ?? ''));
        if ($s === '') {
            return '';
        }
        if ($s[0] === '[') {
            $decoded = json_decode($s, true);
            if (is_array($decoded)) {
                return self::normalizeJsonListField($decoded);
            }
        }

        return $s;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array{url:string,type:string,role:string}>
     */
    public static function extractGalleryMedia(array $row): array
    {
        $urls = [];
        $big = trim((string)($row['bigImage'] ?? ''));
        if ($big !== '') {
            $urls[] = $big;
        }
        foreach (['productImage', 'productImageSet'] as $field) {
            $raw = $row[$field] ?? null;
            if (is_string($raw) && str_starts_with(trim($raw), '[')) {
                $decoded = json_decode($raw, true);
                $raw = is_array($decoded) ? $decoded : $raw;
            }
            if (is_string($raw) && $raw !== '') {
                $urls[] = trim($raw);
            } elseif (is_array($raw)) {
                foreach ($raw as $item) {
                    $u = trim((string)$item);
                    if ($u !== '') {
                        $urls[] = $u;
                    }
                }
            }
        }
        $media = [];
        $seen = [];
        foreach ($urls as $i => $url) {
            if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                continue;
            }
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $media[] = [
                'url' => $url,
                'type' => 'image',
                'role' => $i === 0 ? 'main' : 'gallery',
            ];
        }

        return $media;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{axes:list<array<string,mixed>>,offers:list<array<string,mixed>>}|array{}
     */
    public static function mapVariants(array $row): array
    {
        $variants = $row['variants'] ?? null;
        if (!is_array($variants) || $variants === []) {
            return [];
        }
        // One physical SKU only → treat as simple (no axes).
        if (count($variants) < 2) {
            return [];
        }

        $options = [];
        $offers = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $label = trim((string)($variant['variantKey'] ?? ''));
            if ($label === '') {
                $label = trim((string)($variant['variantName'] ?? $variant['variantNameEn'] ?? $variant['variantSku'] ?? ''));
            }
            if ($label === '') {
                continue;
            }
            $options[$label] = $label;
            $img = trim((string)($variant['variantImage'] ?? ''));
            $offers[] = [
                'sku' => (string)($variant['variantSku'] ?? $variant['vid'] ?? ''),
                'combination' => ['style_type' => $label],
                'image_url' => $img,
                'price_minor' => (int)round(((float)($variant['variantSellPrice'] ?? 0)) * 100),
                'qty' => (int)($variant['inventoryNum'] ?? 0),
                'external_vid' => (string)($variant['vid'] ?? ''),
            ];
        }
        if (count($options) < 2 || $offers === []) {
            return [];
        }

        $axisOptions = [];
        foreach ($options as $value) {
            $axisOptions[] = ['value' => $value, 'label' => $value];
        }

        return [
            'axes' => [[
                'code' => 'style_type',
                'label' => '规格',
                'options' => $axisOptions,
            ]],
            'offers' => $offers,
        ];
    }

    public function syncCatalogSnapshot(array $listingRow): ?DropshipCatalogSnapshot
    {
        return $this->getProduct([
            'external_spu' => $listingRow['external_spu'] ?? '',
            'pid' => $listingRow['external_spu'] ?? '',
        ]);
    }

    public function createFulfillment(array $command): array
    {
        try {
            $sandbox = array_key_exists('order_sandbox', $command)
                ? (bool)$command['order_sandbox']
                : $this->client()->isOrderSandboxEnabled();
            if (isset($command['lines']) && is_array($command['lines'])) {
                $command['lines'] = $this->enrichLinesWithVariantIds($command['lines']);
            }
            $payload = self::buildCreateOrderPayload($command, $sandbox);
            $resp = $this->client()->post('/shopping/order/createOrderV3', $payload);
            $ok = self::isCjApiOk($resp);
            $data = is_array($resp['data'] ?? null) ? $resp['data'] : [];
            $orderId = trim((string)($data['orderId'] ?? $data['orderNumber'] ?? ''));
            $shipmentOrderId = trim((string)($data['shipmentOrderId'] ?? ''));
            $message = (string)($resp['message'] ?? '');
            if (!$ok && self::isDuplicateCreateMessage($message)) {
                $recovered = $this->recoverExistingCreate($command, $message, $sandbox);
                if (($recovered['ok'] ?? false) === true) {
                    return $recovered;
                }
            }
            $out = [
                'ok' => $ok,
                'external_order_id' => $orderId,
                'message' => $message,
                'sandbox' => $sandbox,
                'status' => $ok ? 'created' : 'error',
                'raw' => $resp,
            ];
            if (!$ok || !$sandbox || $orderId === '') {
                return $out;
            }

            $trackNumber = self::defaultSandboxTrackNumber($orderId, (string)($command['order_uuid'] ?? ''));
            $advance = $this->advanceSandboxFulfillment($orderId, $shipmentOrderId, $trackNumber);
            $out['sandbox_advance'] = $advance;
            $out['status'] = !empty($advance['ok']) ? 'shipped' : 'created';
            $out['tracking_number'] = $trackNumber;
            $out['carrier'] = (string)($payload['logisticName'] ?? 'CJ Sandbox');
            if (empty($advance['ok'])) {
                $out['message'] = trim($out['message'] . ' sandbox_advance:' . (string)($advance['message'] ?? 'failed'));
            }

            return $out;
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Assemble createOrderV3 body. When $orderSandbox, set isSandbox=1 (CJ sandbox order).
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    public static function buildCreateOrderPayload(array $command, bool $orderSandbox = false): array
    {
        $products = [];
        foreach ((array)($command['lines'] ?? []) as $line) {
            $products[] = self::mapCreateOrderProductLine(is_array($line) ? $line : []);
        }
        $shipping = (array)($command['shipping'] ?? []);
        $countryCode = strtoupper(trim((string)($shipping['country_code'] ?? $shipping['country'] ?? '')));
        if (strlen($countryCode) !== 2) {
            $countryCode = strtoupper(trim((string)($shipping['country_code'] ?? '')));
        }
        $countryName = trim((string)($shipping['country_name'] ?? $shipping['country'] ?? ''));
        if ($countryName === '' || strlen($countryName) === 2) {
            $countryName = $countryCode !== '' ? $countryCode : 'US';
        }
        $storageId = trim((string)($command['storage_id'] ?? ''));
        $usePlatformStorage = $storageId !== '' && self::looksLikeCjStorageId($storageId);
        $fromCountry = strtoupper(trim((string)($command['from_country_code'] ?? '')));
        if ($fromCountry === '') {
            $fromCountry = 'CN';
        }
        // 短 areaId 仓映射国（常见 US）不可作商家物流发货国：confirm 会 Logistic not found / 无库存。
        if (!$usePlatformStorage) {
            $fromCountry = 'CN';
        }
        $logisticName = trim((string)($command['logistic_name'] ?? $shipping['logistic_name'] ?? ''));
        if ($logisticName === '') {
            $logisticName = 'CJPacket Ordinary';
        }
        $payload = [
            'orderNumber' => (string)($command['order_uuid'] ?? ''),
            'shippingCountryCode' => $countryCode !== '' ? $countryCode : 'US',
            'shippingCountry' => $countryName,
            'shippingProvince' => (string)($shipping['province'] ?? $shipping['region'] ?? ''),
            'shippingCity' => (string)($shipping['city'] ?? ''),
            'shippingAddress' => (string)($shipping['street'] ?? $shipping['address'] ?? ''),
            'shippingCustomerName' => (string)($shipping['name'] ?? $shipping['firstname'] ?? ''),
            'shippingPhone' => (string)($shipping['phone'] ?? $shipping['telephone'] ?? ''),
            'shippingZip' => (string)($shipping['postcode'] ?? $shipping['zip'] ?? ''),
            'fromCountryCode' => $fromCountry,
            'logisticName' => $logisticName,
            'products' => $products,
        ];
        // createOrderV3.storageId：仅在像 UUID/长 hex 的真实仓 ID 时用平台物流(1)；
        // areaId 短数字（如 "2"）会导致 5027 Platform not support，降级商家物流(2)。
        if ($usePlatformStorage) {
            $payload['storageId'] = $storageId;
            $payload['shopLogisticsType'] = 1;
        } else {
            $payload['shopLogisticsType'] = 2;
        }
        if ($orderSandbox) {
            $payload['isSandbox'] = 1;
        }

        return $payload;
    }

    public static function looksLikeCjStorageId(string $id): bool
    {
        $id = trim($id);
        if ($id === '') {
            return false;
        }
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $id) === 1) {
            return true;
        }

        return preg_match('/^[0-9a-fA-F]{24,}$/', $id) === 1;
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    public static function mapCreateOrderProductLine(array $line): array
    {
        $product = [
            'quantity' => max(1, (int)($line['qty'] ?? 1)),
            'storeLineItemId' => (string)($line['line_key'] ?? ''),
        ];
        $vid = trim((string)($line['external_vid'] ?? $line['vid'] ?? ''));
        $sku = trim((string)($line['external_sku'] ?? $line['sku'] ?? ''));
        // Listing 常把 CJ productSku 放进 external_sku；纯数字长 ID / UUID 实为 vid。
        if ($vid === '' && $sku !== '') {
            if (self::looksLikeCjVariantId($sku)) {
                $vid = $sku;
                $sku = '';
            }
        }
        if ($vid !== '') {
            $product['vid'] = $vid;
        } elseif ($sku !== '') {
            $product['sku'] = $sku;
        }

        return $product;
    }

    public static function looksLikeCjVariantId(string $id): bool
    {
        $id = trim($id);
        if ($id === '') {
            return false;
        }
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $id) === 1) {
            return true;
        }
        // CJ OpenAPI 常见数字 vid（≥15 位）
        return preg_match('/^\d{15,}$/', $id) === 1;
    }

    /**
     * Resolve CJ variant id when listing only stored productSku / SPU.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    public function enrichLinesWithVariantIds(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $mapped = self::mapCreateOrderProductLine($line);
            if (($mapped['vid'] ?? '') !== '' || ($mapped['sku'] ?? '') === '') {
                $out[] = $line;
                continue;
            }
            $sku = (string)$mapped['sku'];
            $vid = $this->resolveVariantIdBySku($sku, (string)($line['external_spu'] ?? ''));
            if ($vid !== '') {
                $line['external_vid'] = $vid;
            }
            $out[] = $line;
        }

        return $out;
    }

    private function resolveVariantIdBySku(string $sku, string $pid = ''): string
    {
        $sku = trim($sku);
        $pid = trim($pid);
        try {
            if ($pid !== '') {
                $resp = $this->client()->get('/product/query', ['pid' => $pid]);
                $vid = self::pickVariantIdFromProductData(is_array($resp['data'] ?? null) ? $resp['data'] : [], $sku);
                if ($vid !== '') {
                    return $vid;
                }
            }
            // productSku 检索
            $resp = $this->client()->get('/product/list', [
                'productSku' => $sku,
                'pageNum' => 1,
                'pageSize' => 5,
            ]);
            foreach (self::extractProductRows($resp) as $row) {
                $vid = self::pickVariantIdFromProductData($row, $sku);
                if ($vid !== '') {
                    return $vid;
                }
                $rowPid = trim((string)($row['pid'] ?? $row['id'] ?? ''));
                if ($rowPid === '') {
                    continue;
                }
                $detail = $this->client()->get('/product/query', ['pid' => $rowPid]);
                $vid = self::pickVariantIdFromProductData(is_array($detail['data'] ?? null) ? $detail['data'] : [], $sku);
                if ($vid !== '') {
                    return $vid;
                }
            }
        } catch (\Throwable) {
            return '';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function pickVariantIdFromProductData(array $row, string $sku = ''): string
    {
        $variants = $row['variants'] ?? null;
        if (!is_array($variants) || $variants === []) {
            return '';
        }
        $sku = trim($sku);
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $vid = trim((string)($variant['vid'] ?? ''));
            $vSku = trim((string)($variant['variantSku'] ?? ''));
            if ($vid === '') {
                continue;
            }
            if ($sku === '' || $vSku === $sku || str_starts_with($vSku, $sku) || $sku === trim((string)($row['productSku'] ?? ''))) {
                return $vid;
            }
        }
        $first = $variants[0] ?? null;

        return is_array($first) ? trim((string)($first['vid'] ?? '')) : '';
    }

    /**
     * Sandbox post-create steps: simulatePay → track → 400→500→600.
     *
     * @return list<array{path:string,body:array<string,mixed>}>
     */
    public static function sandboxAdvanceSteps(string $orderId, string $shipmentOrderId = '', string $trackNumber = ''): array
    {
        $orderId = trim($orderId);
        $shipmentOrderId = trim($shipmentOrderId);
        $trackNumber = trim($trackNumber);
        if ($trackNumber === '') {
            $trackNumber = self::defaultSandboxTrackNumber($orderId, '');
        }
        $payBody = $shipmentOrderId !== ''
            ? ['shipmentOrderId' => $shipmentOrderId]
            : ['orderId' => $orderId];

        return [
            ['method' => 'PATCH', 'path' => '/shopping/order/confirmOrder', 'body' => [
                'orderId' => $orderId,
            ]],
            ['path' => '/shopping/sandbox/simulatePay', 'body' => $payBody],
            ['path' => '/shopping/sandbox/updateTrackNumber', 'body' => [
                'orderId' => $orderId,
                'trackNumber' => $trackNumber,
            ]],
            ['path' => '/shopping/sandbox/updateStatus', 'body' => [
                'orderId' => $orderId,
                'targetStatus' => 400,
            ]],
            ['path' => '/shopping/sandbox/updateStatus', 'body' => [
                'orderId' => $orderId,
                'targetStatus' => 500,
            ]],
            ['path' => '/shopping/sandbox/updateStatus', 'body' => [
                'orderId' => $orderId,
                'targetStatus' => 600,
            ]],
        ];
    }

    public static function defaultSandboxTrackNumber(string $orderId, string $orderUuid = ''): string
    {
        $seed = $orderId !== '' ? $orderId : $orderUuid;

        return 'SBX' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $seed) ?: 'ORDER', -10));
    }

    /**
     * @param array<string, mixed> $resp
     */
    public static function isCjApiOk(array $resp): bool
    {
        $code = (int)($resp['code'] ?? 0);
        if ($code === 200) {
            return true;
        }

        return !empty($resp['result']) || !empty($resp['success']);
    }

    /** CJ 幂等建单：远端已存在同 orderNumber，应按成功恢复而非失败终态。 */
    public static function isDuplicateCreateMessage(string $message): bool
    {
        $message = trim($message);
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'Order exist')
            || str_contains($message, 'do not duplicate create');
    }

    /**
     * 从本地履约表 / getOrderDetail 恢复已建单。
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function recoverExistingCreate(array $command, string $message, bool $sandbox): array
    {
        $orderUuid = trim((string)($command['order_uuid'] ?? ''));
        $externalId = '';
        if ($orderUuid !== '') {
            /** @var DropshipFulfillment $model */
            $model = ObjectManager::getInstance(DropshipFulfillment::class);
            $row = $model->clear()
                ->where(DropshipFulfillment::schema_fields_PROVIDER_CODE, 'cj')
                ->where(DropshipFulfillment::schema_fields_ORDER_UUID, $orderUuid)
                ->find()
                ->fetch();
            if ($row && $row->getId()) {
                $externalId = trim((string)$row->getData(DropshipFulfillment::schema_fields_EXTERNAL_ORDER_ID));
            }
        }
        if ($externalId === '' && $orderUuid !== '') {
            try {
                $resp = $this->client()->get('/shopping/order/getOrderDetail', [
                    'orderNumber' => $orderUuid,
                ]);
                if (self::isCjApiOk($resp)) {
                    $data = is_array($resp['data'] ?? null) ? $resp['data'] : [];
                    $externalId = trim((string)($data['orderId'] ?? $data['orderNumber'] ?? ''));
                }
            } catch (\Throwable) {
                // fall through
            }
        }
        if ($externalId === '') {
            return [
                'ok' => false,
                'message' => $message,
                'status' => 'error',
                'duplicate' => true,
            ];
        }

        return [
            'ok' => true,
            'external_order_id' => $externalId,
            'message' => $message,
            'sandbox' => $sandbox,
            'status' => 'created',
            'recovered_existing' => true,
        ];
    }

    /**
     * @param array<string, mixed> $resp
     */
    public static function isCjRateLimited(array $resp): bool
    {
        $code = (int)($resp['code'] ?? 0);
        if ($code === 1600200) {
            return true;
        }
        $message = (string)($resp['message'] ?? '');

        return stripos($message, 'Too Many Requests') !== false
            || stripos($message, 'QPS') !== false;
    }

    /**
     * @return array{ok:bool,message?:string,steps?:list<array<string,mixed>>}
     */
    private function advanceSandboxFulfillment(string $orderId, string $shipmentOrderId, string $trackNumber): array
    {
        $steps = self::sandboxAdvanceSteps($orderId, $shipmentOrderId, $trackNumber);
        $ran = [];
        foreach ($steps as $i => $step) {
            if ($i > 0) {
                // CJ OpenAPI QPS ≈ 1/s；步骤间留足间隔避免末态 Too Many Requests。
                usleep(1_100_000);
            }
            $method = strtoupper((string)($step['method'] ?? 'POST'));
            $path = (string)$step['path'];
            $body = (array)$step['body'];
            $resp = $method === 'PATCH'
                ? $this->client()->patch($path, $body)
                : $this->client()->post($path, $body);
            if (!self::isCjApiOk($resp) && self::isCjRateLimited($resp)) {
                usleep(1_200_000);
                $resp = $method === 'PATCH'
                    ? $this->client()->patch($path, $body)
                    : $this->client()->post($path, $body);
            }
            $ran[] = [
                'path' => $path,
                'ok' => self::isCjApiOk($resp),
                'message' => (string)($resp['message'] ?? ''),
                'raw' => $resp,
            ];
            if (!self::isCjApiOk($resp)) {
                return [
                    'ok' => false,
                    'message' => (string)($resp['message'] ?? ('sandbox_step_failed:' . $path)),
                    'steps' => $ran,
                ];
            }
        }

        return ['ok' => true, 'steps' => $ran];
    }

    public function cancelFulfillment(array $command): array
    {
        return ['ok' => false, 'skipped' => true, 'message' => 'skipped:unsupported'];
    }

    public function queryFulfillment(array $query): array
    {
        try {
            $resp = $this->client()->get('/shopping/order/getOrderDetail', [
                'orderId' => (string)($query['external_order_id'] ?? ''),
            ]);
            $data = $resp['data'] ?? [];
            return [
                'ok' => true,
                'status' => (string)($data['orderStatus'] ?? 'unknown'),
                'tracking' => [
                    'number' => (string)($data['trackNumber'] ?? ''),
                    'carrier' => (string)($data['logisticName'] ?? ''),
                ],
                'raw' => is_array($data) ? $data : [],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function quoteFreight(array $request): array
    {
        try {
            $body = self::mapFreightCalculateRequest($request);
            if ($body['products'] === []) {
                return [];
            }
            $resp = $this->client()->post('/logistic/freightCalculate', $body);
            $list = $resp['data'] ?? [];
            $out = [];
            foreach ((array)$list as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $out[] = [
                    'code' => 'cj_' . $i,
                    'title' => (string)($row['logisticName'] ?? 'CJ Freight'),
                    'amount_minor' => (int)round(((float)($row['freight'] ?? 0)) * 100),
                    'currency' => 'USD',
                    'meta' => $row,
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Map shell-standard freight request (or already-CJ body) to freightCalculate payload.
     *
     * @param array<string, mixed> $request
     * @return array{startCountryCode:string,endCountryCode:string,zip?:string,products:list<array{vid:string,quantity:int}>}
     */
    public static function mapFreightCalculateRequest(array $request): array
    {
        $start = strtoupper(trim((string)($request['startCountryCode']
            ?? $request['start_country_code']
            ?? 'CN'))) ?: 'CN';
        $end = strtoupper(trim((string)($request['endCountryCode']
            ?? $request['end_country_code']
            ?? '')));
        if ($end === '') {
            $address = is_array($request['address'] ?? null) ? $request['address'] : [];
            $end = strtoupper(trim((string)($address['country_code'] ?? $address['country'] ?? 'US'))) ?: 'US';
        }
        $zip = trim((string)($request['zip']
            ?? $request['postal_code']
            ?? (is_array($request['address'] ?? null)
                ? ($request['address']['postal_code'] ?? $request['address']['postcode'] ?? '')
                : '')));
        $products = [];
        foreach ((array)($request['products'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = self::mapCreateOrderProductLine([
                'qty' => (int)($row['quantity'] ?? $row['qty'] ?? 1),
                'external_vid' => (string)($row['vid'] ?? $row['external_vid'] ?? ''),
                'external_sku' => (string)($row['sku'] ?? $row['external_sku'] ?? ''),
                'line_key' => (string)($row['line_key'] ?? ''),
            ]);
            $vid = trim((string)($mapped['vid'] ?? ''));
            if ($vid === '') {
                continue;
            }
            $products[] = [
                'vid' => $vid,
                'quantity' => max(1, (int)($mapped['quantity'] ?? 1)),
            ];
        }
        $body = [
            'startCountryCode' => $start,
            'endCountryCode' => $end,
            'products' => $products,
        ];
        if ($zip !== '') {
            $body['zip'] = $zip;
        }

        return $body;
    }

    public function verifyWebhook(array $headers, string $body): array
    {
        // CJ public hooks may omit signature; accept non-empty body. Extend with secret when configured.
        if (trim($body) === '') {
            return ['ok' => false, 'message' => 'empty_body'];
        }

        return ['ok' => true];
    }

    public function parseWebhook(array $headers, string $body): array
    {
        $payload = json_decode($body, true);
        if (!\is_array($payload)) {
            $payload = [];
        }

        $params = \is_array($payload['params'] ?? null) ? $payload['params'] : [];
        $type = strtoupper(trim((string)($payload['type'] ?? $payload['event'] ?? '')));
        $messageType = strtoupper(trim((string)($payload['messageType'] ?? '')));
        $externalId = (string)($payload['messageId']
            ?? $params['messageId']
            ?? $payload['id']
            ?? ($body !== '' ? md5($body) : ''));
        $event = (string)($payload['type'] ?? $payload['event'] ?? $payload['messageType'] ?? 'cj.event');

        return match ($type) {
            'PRODUCT', 'VARIANT' => $this->parseWebhookCatalog($payload, $params, $type, $event, $externalId, $messageType),
            'STOCK' => $this->parseWebhookStock($payload, $params, $event, $externalId),
            'LOGISTIC' => $this->parseWebhookLogistics($payload, $params, $event, $externalId),
            'MAKEUP' => $this->parseWebhookMakeup($payload, $params, $event, $externalId, $messageType),
            'PRIVATE_ORDER' => $this->parseWebhookPrivateOrder($payload, $params, $event, $externalId),
            'DISPUTE', 'DISPUTES' => $this->parseWebhookDispute($payload, $params, $event, $externalId),
            'ORDERSPLIT' => $this->parseWebhookOrderSplit($payload, $params, $event, $externalId),
            'ORDER' => $this->parseWebhookOrder($payload, $params, $event, $externalId, $messageType),
            default => $this->parseWebhookOrderLegacy($payload, $params, $event, $externalId),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function parseWebhookCatalog(array $payload, array $params, string $type, string $event, string $externalId, string $messageType): array
    {
        $src = $params !== [] ? $params : $payload;
        $spu = trim((string)($src['pid'] ?? $src['productId'] ?? $src['external_spu'] ?? ''));
        $sku = trim((string)($src['productSku'] ?? $src['variantSku'] ?? $src['external_sku'] ?? $src['vid'] ?? ''));
        $title = trim((string)($src['productNameEn'] ?? $src['productName'] ?? $src['variantName'] ?? $src['title'] ?? ''));
        $price = $src['productSellPrice'] ?? $src['variantSellPrice'] ?? null;
        $statusRaw = $src['productStatus'] ?? $src['variantStatus'] ?? null;
        $shelf = 'active';
        if ($messageType === 'DELETE') {
            $shelf = 'delisted';
        } elseif ($statusRaw !== null && $statusRaw !== '') {
            $st = (int)$statusRaw;
            // productStatus: 2=未在售 3=在售; variantStatus: 0=下架 1=在售
            if ($type === 'VARIANT') {
                $shelf = $st === 1 ? 'active' : 'delisted';
            } else {
                $shelf = $st === 3 ? 'active' : 'delisted';
            }
        }

        return [
            'ok' => true,
            'event' => $event,
            'topic' => 'product',
            'external_id' => $externalId,
            'catalog' => [
                'external_spu' => $spu,
                'external_sku' => $sku,
                'qty' => null,
                'shelf_status' => $shelf,
                'origin_price_minor' => $price === null || $price === '' ? null : (int)round(((float)$price) * 100),
                'origin_currency' => 'USD',
                'title' => $title,
            ],
            'fulfillment' => [],
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function parseWebhookStock(array $payload, array $params, string $event, string $externalId): array
    {
        $src = $params !== [] ? $params : $payload;
        $spu = '';
        $sku = '';
        $qty = 0;
        foreach ($src as $key => $rows) {
            if (!\is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                if ($spu === '') {
                    $spu = trim((string)($row['pid'] ?? ''));
                }
                if ($sku === '') {
                    $sku = trim((string)($row['vid'] ?? $key));
                }
                $qty += (int)($row['storageNum'] ?? $row['qty'] ?? 0);
            }
        }
        if ($spu === '') {
            $spu = trim((string)($src['pid'] ?? $src['external_spu'] ?? ''));
        }

        return [
            'ok' => true,
            'event' => $event,
            'topic' => 'stock',
            'external_id' => $externalId,
            'catalog' => [
                'external_spu' => $spu,
                'external_sku' => $sku,
                'qty' => $qty,
                'shelf_status' => $qty > 0 ? 'active' : 'delisted',
                'origin_price_minor' => null,
                'origin_currency' => 'USD',
                'title' => '',
            ],
            'fulfillment' => [],
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function parseWebhookLogistics(array $payload, array $params, string $event, string $externalId): array
    {
        $src = $params !== [] ? $params : $payload;
        $orderId = trim((string)($src['orderId'] ?? $src['cjOrderId'] ?? $src['external_order_id'] ?? ''));
        $storeOrders = $src['storeOrderNumbers'] ?? null;
        $orderUuid = '';
        if (\is_array($storeOrders) && $storeOrders !== []) {
            $orderUuid = trim((string)$storeOrders[0]);
        }

        return [
            'ok' => true,
            'event' => $event,
            'topic' => 'logistics',
            'external_id' => $externalId,
            'fulfillment' => [
                'external_order_id' => $orderId,
                'order_uuid' => $orderUuid,
                'tracking_number' => (string)($src['trackingNumber'] ?? $src['trackNumber'] ?? $src['tracking_number'] ?? ''),
                'carrier' => (string)($src['trackingProvider'] ?? $src['logisticName'] ?? $src['carrier'] ?? ''),
                'status' => isset($src['trackingStatus']) ? ('track_' . (string)$src['trackingStatus']) : 'updated',
            ],
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function parseWebhookMakeup(array $payload, array $params, string $event, string $externalId, string $messageType): array
    {
        $src = $params !== [] ? $params : $payload;
        $status = trim((string)($src['status'] ?? $messageType ?? 'UPDATED'));
        $amount = $src['amount'] ?? null;

        return [
            'ok' => true,
            'event' => $event,
            'topic' => 'makeup',
            'external_id' => $externalId,
            'makeup' => [
                'external_id' => trim((string)($src['orderId'] ?? $src['external_id'] ?? '')),
                'related_external_order_id' => trim((string)($src['relationOrderId'] ?? $src['related_external_order_id'] ?? '')),
                'status' => $status !== '' ? $status : 'UPDATED',
                'amount_minor' => $amount === null || $amount === '' ? null : (int)round(((float)$amount) * 100),
                'currency' => 'USD',
            ],
            'fulfillment' => [
                'external_order_id' => trim((string)($src['relationOrderId'] ?? '')),
                'order_uuid' => '',
                'tracking_number' => '',
                'carrier' => '',
                'status' => 'makeup_' . strtolower($status !== '' ? $status : 'updated'),
            ],
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function parseWebhookPrivateOrder(array $payload, array $params, string $event, string $externalId): array
    {
        $src = $params !== [] ? $params : $payload;

        return [
            'ok' => true,
            'event' => $event,
            'topic' => 'private_order',
            'external_id' => $externalId,
            'fulfillment' => [
                'external_order_id' => trim((string)($src['orderId'] ?? $src['cjOrderId'] ?? '')),
                'order_uuid' => trim((string)($src['orderNumber'] ?? $src['orderNum'] ?? '')),
                'tracking_number' => '',
                'carrier' => '',
                'status' => (string)($src['status'] ?? $src['orderStatus'] ?? 'updated'),
            ],
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function parseWebhookDispute(array $payload, array $params, string $event, string $externalId): array
    {
        $src = $params !== [] ? $params : $payload;
        $st = trim((string)($src['status'] ?? '1'));

        return [
            'ok' => true,
            'event' => $event,
            'topic' => 'dispute',
            'external_id' => $externalId,
            'fulfillment' => [
                'external_order_id' => trim((string)($src['orderId'] ?? $src['cjOrderId'] ?? '')),
                'order_uuid' => '',
                'tracking_number' => '',
                'carrier' => '',
                'status' => 'dispute_' . $st,
            ],
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function parseWebhookOrderSplit(array $payload, array $params, string $event, string $externalId): array
    {
        $src = $params !== [] ? $params : $payload;
        $original = trim((string)($src['originalOrderId'] ?? ''));
        $splits = \is_array($src['splitOrderList'] ?? null) ? $src['splitOrderList'] : [];
        $first = \is_array($splits[0] ?? null) ? $splits[0] : [];
        $child = trim((string)($first['orderCode'] ?? $first['cjOrderId'] ?? ''));

        return [
            'ok' => true,
            'event' => $event,
            'topic' => 'order',
            'external_id' => $externalId,
            'fulfillment' => [
                'external_order_id' => $child !== '' ? $child : $original,
                'order_uuid' => $original,
                'tracking_number' => '',
                'carrier' => '',
                'status' => 'split',
            ],
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function parseWebhookOrder(array $payload, array $params, string $event, string $externalId, string $messageType): array
    {
        $src = $params !== [] ? $params : $payload;
        $externalOrderId = (string)($src['cjOrderId']
            ?? $src['orderId']
            ?? $payload['orderId']
            ?? $payload['external_order_id']
            ?? '');
        $track = $src['trackNumber'] ?? $src['tracking_number'] ?? $payload['trackNumber'] ?? null;
        $carrier = $src['logisticName'] ?? $src['trackingProvider'] ?? $src['carrier'] ?? $payload['logisticName'] ?? null;
        $status = $src['orderStatus'] ?? $src['status'] ?? $payload['orderStatus'] ?? $payload['status'] ?? 'updated';
        $private = !empty($src['privateOutboundOrder']);
        $topic = $private ? 'private_order' : 'order';
        if ($messageType === 'INSERT' && $private) {
            $topic = 'private_order';
        }

        return [
            'ok' => true,
            'event' => $event,
            'topic' => $topic,
            'external_id' => $externalId,
            'fulfillment' => [
                'external_order_id' => $externalOrderId,
                'order_uuid' => (string)($src['orderNumber'] ?? $src['orderNum'] ?? $src['order_uuid'] ?? $payload['orderNumber'] ?? ''),
                'tracking_number' => $track === null ? '' : (string)$track,
                'carrier' => $carrier === null ? '' : (string)$carrier,
                'status' => (string)$status,
            ],
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function parseWebhookOrderLegacy(array $payload, array $params, string $event, string $externalId): array
    {
        $src = $params !== [] ? $params : $payload;
        $externalOrderId = (string)($src['cjOrderId']
            ?? $src['orderId']
            ?? $payload['orderId']
            ?? $payload['external_order_id']
            ?? $payload['id']
            ?? '');
        $track = $src['trackNumber'] ?? $src['tracking_number'] ?? $payload['trackNumber'] ?? null;
        $carrier = $src['logisticName'] ?? $src['carrier'] ?? $payload['logisticName'] ?? null;
        $status = $src['orderStatus'] ?? $src['status'] ?? $payload['orderStatus'] ?? $payload['status'] ?? 'updated';

        return [
            'ok' => true,
            'event' => $event !== '' ? $event : 'cj.event',
            'topic' => $externalOrderId !== '' ? 'order' : 'unknown',
            'external_id' => $externalId,
            'fulfillment' => [
                'external_order_id' => $externalOrderId,
                'order_uuid' => (string)($src['orderNumber'] ?? $src['orderNum'] ?? $src['order_uuid'] ?? $payload['orderNumber'] ?? ''),
                'tracking_number' => $track === null ? '' : (string)$track,
                'carrier' => $carrier === null ? '' : (string)$carrier,
                'status' => (string)$status,
            ],
            'payload' => $payload,
        ];
    }

    public function listWarehouses(array $context = []): array
    {
        $q = mb_strtolower(trim((string)($context['q'] ?? '')), 'UTF-8');
        $countryFilter = strtoupper(trim((string)($context['country_code'] ?? '')));
        $limit = max(1, min(100, (int)($context['limit'] ?? 50)));
        /** @var CjWarehouse $model */
        $model = ObjectManager::getInstance(CjWarehouse::class);
        $rows = $model->clear()
            ->where(CjWarehouse::schema_fields_ENABLED, 1)
            ->order(CjWarehouse::schema_fields_EXTERNAL_ID, 'ASC')
            ->limit(300)
            ->select()
            ->fetchArray();
        if ((!is_array($rows) || $rows === []) && empty($context['skip_auto_pull'])) {
            try {
                return $this->pullWarehouses(array_merge($context, ['skip_auto_pull' => true]));
            } catch (\Throwable) {
                return [];
            }
        }
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string)($row[CjWarehouse::schema_fields_EXTERNAL_ID] ?? ''));
            $name = trim((string)($row[CjWarehouse::schema_fields_NAME] ?? $id));
            $country = strtoupper(trim((string)($row[CjWarehouse::schema_fields_COUNTRY_CODE] ?? '')));
            if ($id === '') {
                continue;
            }
            if ($countryFilter !== '' && $country !== '' && $country !== $countryFilter) {
                continue;
            }
            $label = $name . ($country !== '' ? ' (' . $country . ')' : '') . ' [' . $id . ']';
            if ($q !== '') {
                $hay = mb_strtolower($id . ' ' . $name . ' ' . $country . ' ' . $label, 'UTF-8');
                if (!str_contains($hay, $q)) {
                    continue;
                }
            }
            $out[] = ['value' => $id, 'label' => $label, 'country_code' => $country];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function pullWarehouses(array $context = []): array
    {
        $resp = $this->client()->get('/product/globalWarehouseList');
        $code = (int)($resp['code'] ?? 0);
        $ok = $code === 200 || !empty($resp['success']) || !empty($resp['result']);
        if (!$ok) {
            $msg = trim((string)($resp['message'] ?? 'cj_warehouse_pull_failed'));
            throw new \RuntimeException($msg !== '' ? $msg : 'cj_warehouse_pull_failed');
        }
        $list = $resp['data'] ?? [];
        if (!is_array($list)) {
            $list = [];
        }
        $now = date('Y-m-d H:i:s');
        $saved = 0;
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = CjWarehouseMapper::mapApiRow($row);
            if ($mapped === null) {
                continue;
            }
            /** @var CjWarehouse $model */
            $model = ObjectManager::getInstance(CjWarehouse::class);
            $existing = $model->clear()
                ->where(CjWarehouse::schema_fields_EXTERNAL_ID, $mapped['external_id'])
                ->find()
                ->fetch();
            $data = [
                CjWarehouse::schema_fields_EXTERNAL_ID => $mapped['external_id'],
                CjWarehouse::schema_fields_NAME => $mapped['name'],
                CjWarehouse::schema_fields_COUNTRY_CODE => $mapped['country_code'],
                CjWarehouse::schema_fields_ENABLED => $mapped['enabled'],
                CjWarehouse::schema_fields_RAW_JSON => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CjWarehouse::schema_fields_UPDATED_AT => $now,
            ];
            if ($existing && $existing->getId()) {
                $existing->setData($data)->save();
            } else {
                $model->clear()->setData($data)->save();
            }
            ++$saved;
        }
        if ($saved === 0 && $list === []) {
            throw new \RuntimeException('cj_warehouse_list_empty');
        }

        return $this->listWarehouses(array_merge($context, ['skip_auto_pull' => true]));
    }

    private function client(): CjApiClient
    {
        return ObjectManager::getInstance(CjApiClient::class);
    }
}
