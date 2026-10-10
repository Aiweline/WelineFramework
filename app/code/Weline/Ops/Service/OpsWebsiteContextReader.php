<?php

declare(strict_types=1);

namespace Weline\Ops\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * 读取站点运营证据包供画像生成（不硬编码站业务路径/slug）。
 *
 * 证据分层：站点身份 / 类目树 / 商品抽样 / 政策与 CMS / 语种市场；缺失写入 evidence_gaps。
 */
final class OpsWebsiteContextReader
{
    private const MAX_ROOT_CATEGORIES = 12;
    private const MAX_CHILD_CATEGORIES = 8;
    private const MAX_PRODUCT_SAMPLES = 24;
    private const MAX_POLICY_PAGES = 12;
    private const MAX_FAQ_THEMES = 12;
    private const MAX_POLICY_SECTION_HINTS = 4;

    /**
     * @return array{
     *   website_id:int,
     *   name:string,
     *   description:string,
     *   available:bool,
     *   error?:string,
     *   locales?:list<string>,
     *   primary_locale?:string,
     *   categories?:list<array{id:int,name:string,children:list<string>}>,
     *   catalog_sample?:list<array{sku:string,name:string,price?:float|null}>,
     *   price_band?:array{currency:string,min:?float,max:?float,count:int}|null,
     *   policies?:list<array{key:string,title:string,identifier:string,hints:list<string>}>,
     *   faq_themes?:list<string>,
     *   evidence_gaps?:list<string>,
     *   evidence_counts?:array<string,int>,
     *   site_facts?:array<string,mixed>
     * }
     */
    public function read(int $websiteId): array
    {
        $websiteId = max(0, $websiteId);
        if (!class_exists(\Weline\Websites\Model\Website::class)) {
            return [
                'website_id' => $websiteId,
                'name' => '',
                'description' => '',
                'available' => false,
                'error' => (string)__('Weline_Websites 未安装'),
            ];
        }

        try {
            /** @var \Weline\Websites\Model\Website $website */
            $website = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
            $website->load($websiteId);
            $id = (int)$website->getId();
            if ($id <= 0 && $websiteId > 0) {
                return [
                    'website_id' => $websiteId,
                    'name' => '',
                    'description' => '',
                    'available' => false,
                    'error' => (string)__('网站不存在'),
                ];
            }

            $name = method_exists($website, 'getName')
                ? (string)$website->getName()
                : (string)$website->getData('name');
            $description = method_exists($website, 'getDescription')
                ? (string)$website->getDescription()
                : (string)$website->getData('description');

            $gaps = [];
            $locales = $this->readLocales($websiteId, $gaps);
            $primaryLocale = $locales[0] ?? 'zh_Hans_CN';
            $categories = $this->readCategories($websiteId, $primaryLocale, $gaps);
            $catalog = $this->readCatalogSample($websiteId, $primaryLocale, $gaps);
            $policies = $this->readPolicies($websiteId, $gaps);
            $faqThemes = $this->readFaqThemes($websiteId, $gaps);
            $priceBand = $this->derivePriceBand($catalog);

            $siteFacts = $this->compressSiteFacts(
                $name,
                $description,
                $locales,
                $primaryLocale,
                $categories,
                $catalog,
                $priceBand,
                $policies,
                $faqThemes,
                $gaps,
            );

            return [
                'website_id' => $websiteId,
                'name' => $name,
                'description' => $description,
                'available' => true,
                'locales' => $locales,
                'primary_locale' => $primaryLocale,
                'categories' => $categories,
                'catalog_sample' => $catalog,
                'price_band' => $priceBand,
                'policies' => $policies,
                'faq_themes' => $faqThemes,
                'evidence_gaps' => array_values(array_unique($gaps)),
                'evidence_counts' => [
                    'locales' => count($locales),
                    'categories' => count($categories),
                    'products' => count($catalog),
                    'policies' => count($policies),
                    'faq_themes' => count($faqThemes),
                    'gaps' => count($gaps),
                ],
                'site_facts' => $siteFacts,
            ];
        } catch (\Throwable $e) {
            return [
                'website_id' => $websiteId,
                'name' => '',
                'description' => '',
                'available' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param list<string> $gaps
     * @return list<string>
     */
    private function readLocales(int $websiteId, array &$gaps): array
    {
        if (!class_exists(\Weline\Websites\Model\WebsiteLanguage::class)) {
            $gaps[] = 'locales_unavailable';

            return ['zh_Hans_CN', 'en_US'];
        }
        try {
            /** @var \Weline\Websites\Model\WebsiteLanguage $wl */
            $wl = ObjectManager::getInstance(\Weline\Websites\Model\WebsiteLanguage::class);
            $codes = $wl->getWebsiteLanguageCodes($websiteId);
            $out = [];
            foreach (is_array($codes) ? $codes : [] as $code) {
                $code = trim((string)$code);
                if ($code !== '') {
                    $out[] = $code;
                }
            }
            if ($out === []) {
                $gaps[] = 'locales_empty';

                return ['zh_Hans_CN', 'en_US'];
            }

            return array_values(array_unique($out));
        } catch (\Throwable) {
            $gaps[] = 'locales_read_failed';

            return ['zh_Hans_CN', 'en_US'];
        }
    }

    /**
     * @param list<string> $gaps
     * @return list<array{id:int,name:string,children:list<string>}>
     */
    private function readCategories(int $websiteId, string $locale, array &$gaps): array
    {
        if (!class_exists(\Weline\Product\Service\StorefrontCategoryTreeIndex::class)) {
            $gaps[] = 'categories_module_missing';

            return [];
        }
        try {
            /** @var \Weline\Product\Service\StorefrontCategoryTreeIndex $tree */
            $tree = ObjectManager::getInstance(\Weline\Product\Service\StorefrontCategoryTreeIndex::class);
            $roots = $tree->nestedRoots($websiteId);
            if ($roots === []) {
                // nestedRoots uses request locale cache; force website index children
                $roots = $tree->childrenOf($websiteId, 0);
            }
            $out = [];
            foreach (array_slice($roots, 0, self::MAX_ROOT_CATEGORIES) as $root) {
                if (!is_array($root)) {
                    continue;
                }
                $id = (int)($root['id'] ?? $root['category_id'] ?? 0);
                $name = trim((string)($root['name'] ?? $root['label'] ?? ''));
                if ($name === '' && $id > 0) {
                    $name = '#' . $id;
                }
                if ($name === '') {
                    continue;
                }
                $children = [];
                $childRows = is_array($root['children'] ?? null) ? $root['children'] : [];
                if ($childRows === [] && $id > 0) {
                    $childRows = $tree->childrenOf($websiteId, $id);
                }
                foreach (array_slice($childRows, 0, self::MAX_CHILD_CATEGORIES) as $child) {
                    if (!is_array($child)) {
                        continue;
                    }
                    $childName = trim((string)($child['name'] ?? $child['label'] ?? ''));
                    if ($childName !== '') {
                        $children[] = $childName;
                    }
                }
                $out[] = [
                    'id' => $id,
                    'name' => $name,
                    'children' => $children,
                ];
            }
            if ($out === []) {
                $gaps[] = 'categories_empty';
            }
            unset($locale);

            return $out;
        } catch (\Throwable) {
            $gaps[] = 'categories_read_failed';

            return [];
        }
    }

    /**
     * @param list<string> $gaps
     * @return list<array{sku:string,name:string,price:?float}>
     */
    private function readCatalogSample(int $websiteId, string $locale, array &$gaps): array
    {
        if (!class_exists(\Weline\Product\Model\Shard\Product::class)) {
            $gaps[] = 'products_module_missing';

            return [];
        }
        try {
            /** @var \Weline\Product\Model\Shard\Product $product */
            $product = ObjectManager::create(\Weline\Product\Model\Shard\Product::class, [], false)
                ->forWebsite($websiteId);
            $items = $product->clear()
                ->where(\Weline\Product\Model\Shard\Product::schema_fields_STATUS, \Weline\Product\Model\Shard\Product::STATUS_PUBLISHED)
                ->order(\Weline\Product\Model\Shard\Product::schema_fields_UPDATED_AT, 'DESC')
                ->limit(self::MAX_PRODUCT_SAMPLES)
                ->select()
                ->fetch()
                ->getItems();
            if ($items === []) {
                $gaps[] = 'products_empty';

                return [];
            }

            $out = [];
            $attrRepo = class_exists(\Weline\Product\Repository\AttributeValueRepository::class)
                ? ObjectManager::getInstance(\Weline\Product\Repository\AttributeValueRepository::class)
                : null;

            foreach ($items as $row) {
                $productId = (int)$row->getData(\Weline\Product\Model\Shard\Product::schema_fields_ID);
                $sku = trim((string)$row->getData(\Weline\Product\Model\Shard\Product::schema_fields_SKU));
                $name = $sku !== '' ? $sku : ('#' . $productId);
                $price = null;
                if ($attrRepo !== null && $productId > 0) {
                    try {
                        $resolved = $attrRepo->read(
                            $websiteId,
                            0,
                            'product',
                            $productId,
                            'name',
                            $locale,
                            [$locale, 'en_US', 'zh_Hans_CN', ''],
                        );
                        if ($resolved->isExplicit() && is_scalar($resolved->value)) {
                            $n = trim((string)$resolved->value);
                            if ($n !== '') {
                                $name = $n;
                            }
                        }
                    } catch (\Throwable) {
                        // keep sku fallback
                    }
                    try {
                        $priceResolved = $attrRepo->read(
                            $websiteId,
                            0,
                            'product',
                            $productId,
                            'price',
                            $locale,
                            [$locale, ''],
                        );
                        if ($priceResolved->isExplicit() && is_numeric($priceResolved->value)) {
                            $price = (float)$priceResolved->value;
                        }
                    } catch (\Throwable) {
                        // optional
                    }
                }
                $out[] = [
                    'sku' => $sku,
                    'name' => $name,
                    'price' => $price,
                ];
            }

            return $out;
        } catch (\Throwable) {
            $gaps[] = 'products_read_failed';

            return [];
        }
    }

    /**
     * @param list<array{sku:string,name:string,price:?float}> $catalog
     * @return array{currency:string,min:?float,max:?float,count:int}|null
     */
    private function derivePriceBand(array $catalog): ?array
    {
        $prices = [];
        foreach ($catalog as $row) {
            if (isset($row['price']) && is_numeric($row['price']) && (float)$row['price'] > 0) {
                $prices[] = (float)$row['price'];
            }
        }
        if ($prices === []) {
            return null;
        }
        sort($prices);

        return [
            'currency' => '',
            'min' => $prices[0],
            'max' => $prices[count($prices) - 1],
            'count' => count($prices),
        ];
    }

    /**
     * @param list<string> $gaps
     * @return list<array{key:string,title:string,identifier:string,hints:list<string>}>
     */
    private function readPolicies(int $websiteId, array &$gaps): array
    {
        $out = [];
        if (class_exists(\Weline\Cms\Model\Page::class)) {
            try {
                /** @var \Weline\Cms\Model\Page $page */
                $page = ObjectManager::getInstance(\Weline\Cms\Model\Page::class);
                $items = $page->clear()
                    ->where(\Weline\Cms\Model\Page::schema_fields_WEBSITE_ID, $websiteId)
                    ->where(\Weline\Cms\Model\Page::schema_fields_STATUS, \Weline\Cms\Model\Page::STATUS_PUBLISHED)
                    ->order(\Weline\Cms\Model\Page::schema_fields_UPDATED_AT, 'DESC')
                    ->limit(self::MAX_POLICY_PAGES)
                    ->select()
                    ->fetch()
                    ->getItems();
                foreach ($items as $row) {
                    $title = trim((string)$row->getData(\Weline\Cms\Model\Page::schema_fields_TITLE));
                    $identifier = trim((string)$row->getData(\Weline\Cms\Model\Page::schema_fields_IDENTIFIER));
                    $pathGroup = trim((string)$row->getData(\Weline\Cms\Model\Page::schema_fields_PATH_GROUP));
                    if ($title === '' && $identifier === '') {
                        continue;
                    }
                    $key = $pathGroup !== '' ? $pathGroup : ($identifier !== '' ? $identifier : 'cms');
                    $out[] = [
                        'key' => $key,
                        'title' => $title !== '' ? $title : $identifier,
                        'identifier' => $identifier,
                        'hints' => array_values(array_filter([$pathGroup, $identifier])),
                    ];
                }
            } catch (\Throwable) {
                $gaps[] = 'cms_policies_read_failed';
            }
        } else {
            $gaps[] = 'cms_module_missing';
        }

        // Theme 默认政策要点（履约边界提示，非站专属文案原文）
        if (class_exists(\Weline\Theme\Helper\PolicyDocumentDefaults::class)) {
            foreach (['shipping', 'refund', 'privacy', 'term-condition'] as $pageKey) {
                try {
                    $defaults = \Weline\Theme\Helper\PolicyDocumentDefaults::forPage($pageKey);
                    $title = trim((string)($defaults['title'] ?? $pageKey));
                    $hints = [];
                    foreach (array_slice((array)($defaults['sections'] ?? []), 0, self::MAX_POLICY_SECTION_HINTS) as $section) {
                        if (!is_array($section)) {
                            continue;
                        }
                        $heading = trim((string)($section['heading'] ?? $section['title'] ?? ''));
                        if ($heading !== '') {
                            $hints[] = $heading;
                        }
                    }
                    $out[] = [
                        'key' => 'theme_default:' . $pageKey,
                        'title' => $title,
                        'identifier' => 'policy/' . $pageKey,
                        'hints' => $hints,
                    ];
                } catch (\Throwable) {
                    // optional defaults
                }
            }
        } else {
            $gaps[] = 'theme_policy_defaults_missing';
        }

        if ($out === []) {
            $gaps[] = 'policies_empty';
        }

        return $out;
    }

    /**
     * @param list<string> $gaps
     * @return list<string>
     */
    private function readFaqThemes(int $websiteId, array &$gaps): array
    {
        if (!class_exists(\Weline\Faq\Model\FaqItem::class)) {
            $gaps[] = 'faq_module_missing';

            return [];
        }
        try {
            /** @var \Weline\Faq\Model\FaqItem $faq */
            $faq = ObjectManager::getInstance(\Weline\Faq\Model\FaqItem::class);
            $items = $faq->clear()
                ->where(\Weline\Faq\Model\FaqItem::schema_fields_WEBSITE_ID, $websiteId)
                ->where(\Weline\Faq\Model\FaqItem::schema_fields_STATUS, \Weline\Faq\Model\FaqItem::STATUS_ENABLED)
                ->order(\Weline\Faq\Model\FaqItem::schema_fields_SORT_ORDER, 'ASC')
                ->limit(self::MAX_FAQ_THEMES)
                ->select()
                ->fetch()
                ->getItems();
            if ($items === []) {
                // fallback global website_id=0
                $items = $faq->clear()
                    ->where(\Weline\Faq\Model\FaqItem::schema_fields_WEBSITE_ID, 0)
                    ->where(\Weline\Faq\Model\FaqItem::schema_fields_STATUS, \Weline\Faq\Model\FaqItem::STATUS_ENABLED)
                    ->order(\Weline\Faq\Model\FaqItem::schema_fields_SORT_ORDER, 'ASC')
                    ->limit(self::MAX_FAQ_THEMES)
                    ->select()
                    ->fetch()
                    ->getItems();
            }
            $themes = [];
            foreach ($items as $row) {
                $q = trim((string)$row->getData(\Weline\Faq\Model\FaqItem::schema_fields_QUESTION));
                if ($q !== '') {
                    $themes[] = mb_substr($q, 0, 80);
                }
            }
            if ($themes === []) {
                $gaps[] = 'faq_empty';
            }

            return $themes;
        } catch (\Throwable) {
            $gaps[] = 'faq_read_failed';

            return [];
        }
    }

    /**
     * @param list<string> $locales
     * @param list<array{id:int,name:string,children:list<string>}> $categories
     * @param list<array{sku:string,name:string,price:?float}> $catalog
     * @param array{currency:string,min:?float,max:?float,count:int}|null $priceBand
     * @param list<array{key:string,title:string,identifier:string,hints:list<string>}> $policies
     * @param list<string> $faqThemes
     * @param list<string> $gaps
     * @return array<string,mixed>
     */
    private function compressSiteFacts(
        string $name,
        string $description,
        array $locales,
        string $primaryLocale,
        array $categories,
        array $catalog,
        ?array $priceBand,
        array $policies,
        array $faqThemes,
        array $gaps,
    ): array {
        $categoryMap = [];
        foreach ($categories as $cat) {
            $line = (string)$cat['name'];
            if (($cat['children'] ?? []) !== []) {
                $line .= ' > ' . implode(' / ', array_slice($cat['children'], 0, 6));
            }
            $categoryMap[] = $line;
        }
        $productTitles = [];
        foreach (array_slice($catalog, 0, 16) as $row) {
            $productTitles[] = (string)$row['name'];
        }
        $policyDigest = [];
        foreach ($policies as $policy) {
            $policyDigest[] = [
                'key' => $policy['key'],
                'title' => $policy['title'],
                'hints' => array_slice($policy['hints'], 0, 4),
            ];
        }

        return [
            'site_name' => $name,
            'markets' => [
                'locales' => $locales,
                'primary' => $primaryLocale,
            ],
            'category_map' => $categoryMap,
            'catalog_titles' => $productTitles,
            'price_band' => $priceBand,
            'policy_digest' => $policyDigest,
            'faq_themes' => $faqThemes,
            'low_trust_blurb' => mb_substr(trim($description), 0, 1200),
            'evidence_gaps' => array_values(array_unique($gaps)),
        ];
    }
}
