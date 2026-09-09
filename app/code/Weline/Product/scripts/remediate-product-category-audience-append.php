<?php

declare(strict_types=1);

/**
 * Append multi-category links from title + gender + dynasty + material (+ image cues).
 *
 * A product may belong to many nodes at once (audience + garment leaf + 汉服形制/
 * 用途/材质 + sets/accessories). Existing links stay unless they conflict with
 * the detected audience (e.g. kids-only under /women/mamian or /men).
 *
 * Usage:
 *   php app/code/Weline/Product/scripts/remediate-product-category-audience-append.php --dry-run
 *   php app/code/Weline/Product/scripts/remediate-product-category-audience-append.php --apply
 * Optional: --website=0 --limit=N --product=ID
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\CategoryLinkRepository;
use Weline\Product\Repository\CategoryRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Service\ProductCategoryAttributeService;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

const CONTRACT = 'product.category.multi-taxonomy-append.v1';

$options = getopt('', ['apply', 'dry-run', 'website:', 'limit:', 'product:']);
$apply = isset($options['apply']) && !isset($options['dry-run']);
$websiteId = max(0, (int)($options['website'] ?? 0));
$limit = max(0, (int)($options['limit'] ?? 0));
$onlyProductId = max(0, (int)($options['product'] ?? 0));

/** @var ProductRepository $products */
$products = ObjectManager::getInstance(ProductRepository::class);
/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);
/** @var CategoryRepository $categories */
$categories = ObjectManager::getInstance(CategoryRepository::class);
/** @var CategoryLinkRepository $categoryLinks */
$categoryLinks = ObjectManager::getInstance(CategoryLinkRepository::class);
/** @var ProductCategoryAttributeService $categoryAttributes */
$categoryAttributes = ObjectManager::getInstance(ProductCategoryAttributeService::class);

$categoryByPath = [];
$codeById = [];
$nameById = [];
$audienceById = [];
foreach ($categories->listAll($websiteId) as $row) {
    $categoryId = (int)($row['category_id'] ?? 0);
    if ($categoryId < 1 || ($row['status'] ?? '') !== 'active') {
        continue;
    }
    $path = trim((string)($row['path'] ?? ''), '/');
    if ($path === '') {
        continue;
    }
    $categoryByPath['/' . $path] = $categoryId;
    $segments = explode('/', $path);
    $codeById[$categoryId] = (string)end($segments);
}

$nameMap = $categoryAttributes->readNameMap($websiteId, array_keys($codeById), 'zh_Hans_CN');
foreach ($nameMap as $categoryId => $name) {
    $nameById[(int)$categoryId] = trim((string)$name);
}

foreach ($categoryByPath as $path => $categoryId) {
    if (str_starts_with($path, '/women')) {
        $audienceById[$categoryId] = 'women';
    } elseif (str_starts_with($path, '/men')) {
        $audienceById[$categoryId] = 'men';
    } elseif (str_starts_with($path, '/kids')) {
        $audienceById[$categoryId] = 'kids';
    } elseif (str_starts_with($path, '/accessories')) {
        $audienceById[$categoryId] = 'accessories';
    } elseif (str_starts_with($path, '/sets')) {
        $audienceById[$categoryId] = 'sets';
    } elseif (str_starts_with($path, '/hanfu')) {
        $audienceById[$categoryId] = 'hanfu';
    }
}

foreach (['/women', '/men', '/kids', '/sets', '/accessories', '/hanfu'] as $path) {
    if (!isset($categoryByPath[$path])) {
        throw new RuntimeException('taxonomy_category_missing:' . $path);
    }
}

$allProducts = $products->listAll($websiteId);
if ($onlyProductId > 0) {
    $allProducts = array_values(array_filter(
        $allProducts,
        static fn(array $row): bool => (int)($row['product_id'] ?? 0) === $onlyProductId,
    ));
}
if ($limit > 0) {
    $allProducts = array_slice($allProducts, 0, $limit);
}

$productIds = array_map(static fn(array $row): int => (int)$row['product_id'], $allProducts);
$nameByProduct = [];
$genderByProduct = [];
$dynastyByProduct = [];
$materialByProduct = [];
foreach ($attributes->listExplicitRows($websiteId, 'product', $productIds, [0]) as $row) {
    $productId = (int)$row['entity_id'];
    $code = (string)$row['attribute_code'];
    $locale = (string)($row['locale'] ?? '');
    if (!empty($row['cleared'])) {
        continue;
    }
    $raw = $row['value'] ?? null;
    if (is_array($raw)) {
        $value = implode(' ', array_map(
            static fn(mixed $part): string => is_scalar($part) ? trim((string)$part) : '',
            $raw,
        ));
    } else {
        $value = trim((string)$raw, " \t\n\r\"");
    }
    if ($value === '') {
        continue;
    }
    if ($code === 'name') {
        if ($locale === 'zh_Hans_CN' || !isset($nameByProduct[$productId])) {
            $nameByProduct[$productId] = $value;
        }
    } elseif ($code === 'hanfu_shi_yong_xing_bie') {
        if ($locale === 'zh_Hans_CN' || !isset($genderByProduct[$productId])) {
            $genderByProduct[$productId] = $value;
        }
    } elseif ($code === 'hanfu_chao_dai') {
        if ($locale === 'zh_Hans_CN' || !isset($dynastyByProduct[$productId])) {
            $dynastyByProduct[$productId] = $value;
        }
    } elseif ($code === 'material' || $code === 'hanfu_zhi_wu_ming_cheng') {
        $materialByProduct[$productId] = trim(($materialByProduct[$productId] ?? '') . ' ' . $value);
    }
}

$existingLinks = [];
foreach ($categoryLinks->listByProductIds($websiteId, $productIds, [0]) as $link) {
    $productId = (int)($link['product_id'] ?? 0);
    $categoryId = (int)($link['category_id'] ?? 0);
    if ($productId > 0 && $categoryId > 0) {
        $existingLinks[$productId][$categoryId] = true;
    }
}

/**
 * @return array{
 *   audiences: list<string>,
 *   paths: list<string>,
 *   reasons: list<string>
 * }
 */
function classifyProductCategoryPaths(
    string $name,
    string $gender,
    string $dynasty,
    string $material,
): array {
    $reasons = [];
    $audiences = [];
    $paths = [];
    $blob = $name . ' ' . $dynasty . ' ' . $material;

    $isKidsTitle = preg_match('/儿童|女童|男童|孩童|宝宝|小女孩|小公主|童装|书童|幼儿|亲子|母女/u', $name) === 1;
    $isBoysTitle = preg_match('/男童|书童|男生童|男孩/u', $name) === 1
        && preg_match('/女童|小女孩|小公主|母女/u', $name) !== 1;
    $isGirlsTitle = preg_match('/女童|小女孩|小公主|女孩|母女/u', $name) === 1;
    $isBothKids = preg_match('/男女童|男童女童|女童男童|男女.*儿童|儿童.*男女/u', $name) === 1;
    $isParentChild = preg_match('/亲子|母女/u', $name) === 1;
    $explicitMenFromTitle = preg_match('/男士|男款|男装|男生|男汉服/u', $name) === 1;
    $explicitMen = $explicitMenFromTitle || ($gender === '男' && !$isKidsTitle);
    // 汉服女童 / 女童… must not count as adult 汉服女.
    $explicitWomenFromTitle = preg_match('/汉服女(?!童)|女装|女款|女士|嫁衣|新娘/u', $name) === 1
        && preg_match('/女童|男童|儿童|宝宝|小女孩|小公主|书童|幼儿/u', $name) !== 1;
    $explicitWomen = $explicitWomenFromTitle || ($gender === '女' && !$isKidsTitle);
    $isMenTitle = $explicitMen;
    $isWomenTitle = $explicitWomen
        || (
            !$explicitMen
            && !$isKidsTitle
            && preg_match('/襦裙|齐胸|齐腰|马面|袄裙|诃子/u', $name) === 1
        );
    $womenOnlyTitle = $explicitWomenFromTitle
        && !$explicitMenFromTitle
        && !$isKidsTitle
        && preg_match('/男女同款|男女款|情侣/u', $name) !== 1;
    $isUnisex = preg_match('/中性|男女均可|男女通用|男女同款|情侣|男女款/u', $gender . $name) === 1;
    if (!$isKidsTitle && preg_match('/男女/u', $name) === 1 && !$explicitMen && !$explicitWomen) {
        $isUnisex = true;
    }
    $adultOverride = preg_match('/成人款|成人礼|成人汉服|老师/u', $name) === 1
        && preg_match('/女童|男童|儿童马面|宝宝|小女孩|小公主/u', $name) !== 1;

    if ($isParentChild) {
        $audiences[] = 'women';
        $audiences[] = 'kids';
        $reasons[] = 'parent_child_set';
    } elseif ($isKidsTitle && !$adultOverride) {
        $audiences[] = 'kids';
        $reasons[] = 'kids_title';
    }

    if ($isMenTitle && !$isGirlsTitle) {
        $audiences[] = 'men';
        $reasons[] = $gender === '男' ? 'gender_male' : 'men_title';
    }
    if ($isWomenTitle || ($gender === '女' && !$isKidsTitle)) {
        $audiences[] = 'women';
        $reasons[] = $gender === '女' ? 'gender_female' : 'women_title';
    }
    if ($isUnisex && !$isKidsTitle) {
        if ($womenOnlyTitle) {
            $audiences[] = 'women';
            $reasons[] = 'unisex_attr_but_women_title';
        } else {
            $audiences[] = 'women';
            $audiences[] = 'men';
            $reasons[] = 'unisex_or_couple';
        }
    } elseif ($isUnisex && $isKidsTitle) {
        $reasons[] = 'kids_unisex_keep_kids_only';
    }

    if (
        preg_match('/飞鱼服|锦衣卫|将军令|圆领缺胯|交领.*男|男款|男士/u', $name) === 1
        && preg_match('/女装|女款|女童|汉服女/u', $name) !== 1
    ) {
        $audiences[] = 'men';
        $reasons[] = 'men_garment_cue';
    }

    if ($audiences === []) {
        $audiences[] = 'women';
        $reasons[] = 'default_women_hanfu_catalog';
    }

    $audiences = array_values(array_unique($audiences));
    $hasWomen = in_array('women', $audiences, true);
    $hasMen = in_array('men', $audiences, true);
    $hasKids = in_array('kids', $audiences, true);

    foreach ($audiences as $audience) {
        if ($audience === 'women') {
            $paths[] = '/women';
        } elseif ($audience === 'men') {
            $paths[] = '/men';
        } elseif ($audience === 'kids') {
            $paths[] = '/kids';
            if ($isBothKids) {
                $paths[] = '/kids/boys';
                $paths[] = '/kids/girls';
            } elseif ($isBoysTitle) {
                $paths[] = '/kids/boys';
            } else {
                $paths[] = '/kids/girls';
            }
        }
    }

    // ── Commerce garment leaves ─────────────────────────────────────
    $hasMamian = preg_match('/马面/u', $name) === 1;
    $hasRuqun = preg_match('/襦裙|诃子裙|流仙裙|齐胸|齐腰/u', $name) === 1;
    $hasAoqun = preg_match('/袄裙/u', $name) === 1;
    $hasBeizi = preg_match('/褙子|比甲|半臂/u', $name) === 1;
    $hasQuju = preg_match('/曲裾|深衣/u', $name) === 1;
    $hasHezi = preg_match('/诃子/u', $name) === 1;
    $hasQixiong = preg_match('/齐胸/u', $name) === 1;
    $hasQiyao = preg_match('/齐腰/u', $name) === 1;
    $hasDuijinRuqun = preg_match('/对襟襦裙/u', $name) === 1
        || (preg_match('/对襟/u', $name) === 1 && preg_match('/襦裙/u', $name) === 1);
    $hasJiaoling = preg_match('/交领襦裙/u', $name) === 1;
    $hasYunjian = preg_match('/云肩/u', $name) === 1;
    $hasDuijinAo = preg_match('/对襟袄/u', $name) === 1;
    $hasBaidie = preg_match('/百迭/u', $name) === 1;
    $hasYuanling = preg_match('/圆领|缺胯袍/u', $name) === 1;
    $hasZhishen = preg_match('/直裰|道袍/u', $name) === 1;
    $hasLanshan = preg_match('/襕衫/u', $name) === 1;
    $hasFeiyu = preg_match('/飞鱼|锦衣卫|将军令/u', $name) === 1;
    $hasWomenRobe = preg_match('/女袍|袍服/u', $name) === 1;

    if ($hasMamian) {
        if ($hasKids && !$hasWomen) {
            $reasons[] = 'kids_mamian_via_kids_leaf';
        } elseif ($hasMen && !$hasWomen) {
            $reasons[] = 'men_mamian_root_only';
        } elseif ($hasWomen) {
            $paths[] = '/women/mamian';
        }
    }
    if ($hasWomen) {
        if ($hasQixiong) {
            $paths[] = '/women/ruqun';
            $paths[] = '/women/ruqun/qixiong';
        } elseif ($hasQiyao) {
            $paths[] = '/women/ruqun';
            $paths[] = '/women/ruqun/qiyao';
        } elseif ($hasDuijinRuqun) {
            $paths[] = '/women/ruqun';
            $paths[] = '/women/ruqun/duijin';
        } elseif ($hasJiaoling) {
            $paths[] = '/women/ruqun';
            $paths[] = '/women/ruqun/jiaoling';
        } elseif ($hasRuqun || $hasHezi) {
            $paths[] = '/women/ruqun';
        }
        if ($hasAoqun) {
            $paths[] = '/women/aoqun';
        }
        if ($hasBeizi) {
            $paths[] = '/women/beizi';
        }
        if ($hasQuju) {
            $paths[] = '/women/quju';
        }
        if ($hasWomenRobe) {
            $paths[] = '/women/women-robe';
        }
    }
    if ($hasMen) {
        if ($hasBeizi && !$hasWomen) {
            $paths[] = '/men/men-beizi';
        }
        if ($hasYuanling || $hasFeiyu) {
            $paths[] = '/men/yuanling';
        }
        if ($hasZhishen) {
            $paths[] = '/men/zhishen';
        }
        if ($hasLanshan) {
            $paths[] = '/men/lanshan';
        }
    }

    // ── Sets / accessories ──────────────────────────────────────────
    if (preg_match('/婚|嫁衣|新娘|敬酒|秀禾/u', $name) === 1) {
        $paths[] = '/sets';
        $paths[] = '/sets/wedding';
        $reasons[] = 'wedding_set';
    } elseif (
        preg_match('/节令主题|端午|中秋|元宵|花朝节/u', $name) === 1
        || (preg_match('/节令/u', $name) === 1 && preg_match('/套装|主题/u', $name) === 1)
    ) {
        $paths[] = '/sets';
        $paths[] = '/sets/festival';
        $reasons[] = 'festival_set';
    } elseif (preg_match('/套装|三件套|拜年服|两件套|四件套/u', $name) === 1 || $hasFeiyu) {
        $paths[] = '/sets';
        $paths[] = '/sets/daily';
        $reasons[] = 'daily_set';
    }

    if (preg_match('/斗篷|披风|披肩|斗笠|巾|帛/u', $name) === 1) {
        $paths[] = '/accessories';
        $paths[] = '/accessories/wrap';
    }
    if (preg_match('/发冠|头饰|发簪|步摇/u', $name) === 1) {
        $paths[] = '/accessories';
        $paths[] = '/accessories/hair';
    }
    if (preg_match('/履|布鞋|绣花鞋/u', $name) === 1) {
        $paths[] = '/accessories';
        $paths[] = '/accessories/shoes';
    }
    if (preg_match('/腰封|腰佩|玉佩|香囊|腰带/u', $name) === 1) {
        $paths[] = '/accessories';
        $paths[] = '/accessories/waist';
    }

    // ── Legacy 汉服 taxonomy (形制 / 用途 / 材质) ─────────────────────
    // Gender-neutral style tree: kids and adults may share these nodes.
    $paths[] = '/hanfu';
    $paths[] = '/hanfu/style';
    $reasons[] = 'hanfu_taxonomy';

    $isMing = preg_match('/明制|明朝|明式/u', $blob) === 1;
    $isTang = preg_match('/唐制|唐朝|唐式|齐胸/u', $blob) === 1;
    $isSong = preg_match('/宋制|宋朝|宋式|百迭/u', $blob) === 1;
    $isJin = preg_match('/晋制|魏晋|晋朝/u', $blob) === 1;
    // 晋/魏晋无独立节点，归入形制根 + 用途；齐腰交领常见挂唐/宋邻近叶子时仍靠 garment cues.

    if ($isMing || ($hasMamian && !$isTang && !$isSong)) {
        $paths[] = '/hanfu/style/ming';
        if ($hasMamian) {
            $paths[] = '/hanfu/style/ming/mamian';
        }
        if ($hasDuijinAo || ($hasAoqun && $isMing)) {
            $paths[] = '/hanfu/style/ming/duijin-ao';
        }
        if ($hasYunjian) {
            $paths[] = '/hanfu/style/ming/yunjian';
        }
    }
    if ($isTang || $hasQixiong || $hasHezi) {
        $paths[] = '/hanfu/style/tang';
        if ($hasQixiong || ($hasRuqun && $isTang)) {
            $paths[] = '/hanfu/style/tang/qixiong-ruqun';
        }
        if ($hasHezi) {
            $paths[] = '/hanfu/style/tang/hezi-qun';
        }
    }
    if ($isSong || $hasBaidie || ($hasBeizi && !$hasMen)) {
        $paths[] = '/hanfu/style/song';
        if ($hasBeizi && !$hasMen) {
            $paths[] = '/hanfu/style/song/beizi';
        }
        if ($hasBaidie) {
            $paths[] = '/hanfu/style/song/baidie-qun';
        }
    }
    if ($isJin && !$isMing && !$isTang && !$isSong) {
        // No dedicated Jin node — keep under 形制分类 only.
        $reasons[] = 'jin_style_under_style_root';
    }

    $paths[] = '/hanfu/occasion';
    if (preg_match('/婚|嫁衣|新娘|敬酒|秀禾/u', $name) === 1) {
        $paths[] = '/hanfu/occasion/wedding';
    } elseif (preg_match('/复原|考据|仿古复原/u', $name) === 1) {
        $paths[] = '/hanfu/occasion/restoration';
    } elseif (preg_match('/节日|出游|拜年|六一|元旦|国庆|春游/u', $name) === 1) {
        $paths[] = '/hanfu/occasion/festival';
    } else {
        $paths[] = '/hanfu/occasion/daily';
    }

    $paths[] = '/hanfu/material';
    if (preg_match('/真丝|桑蚕|丝绸|丝织/u', $blob) === 1) {
        $paths[] = '/hanfu/material/silk';
    } elseif (preg_match('/织金|妆花/u', $blob) === 1) {
        $paths[] = '/hanfu/material/zhijin';
    } elseif (preg_match('/雪纺|纱/u', $blob) === 1) {
        $paths[] = '/hanfu/material/chiffon';
    } elseif (preg_match('/涤纶|聚酯/u', $blob) === 1) {
        $paths[] = '/hanfu/material/polyester';
    } else {
        // Default bulk catalog fabric when unknown.
        $paths[] = '/hanfu/material/polyester';
    }

    if (preg_match('/规格|多规格|变体矩阵/u', $name) === 1) {
        $paths[] = '/hanfu/spec-products';
    }

    $paths = array_values(array_unique($paths));

    return [
        'audiences' => $audiences,
        'paths' => $paths,
        'reasons' => array_values(array_unique($reasons)),
    ];
}

/**
 * @param array<int, true> $existing
 * @param list<string> $audiences
 * @param array<int, string> $audienceById
 * @return list<int>
 */
function conflictingLeafIds(array $existing, array $audiences, array $audienceById): array
{
    $conflicts = [];
    $audienceSet = array_fill_keys($audiences, true);
    foreach (array_keys($existing) as $categoryId) {
        $leafAudience = $audienceById[$categoryId] ?? null;
        if ($leafAudience === null
            || $leafAudience === 'sets'
            || $leafAudience === 'accessories'
            || $leafAudience === 'hanfu'
        ) {
            continue;
        }
        if (isset($audienceSet[$leafAudience])) {
            continue;
        }
        if ($leafAudience === 'men' && isset($audienceSet['kids']) && !isset($audienceSet['men'])) {
            $conflicts[] = (int)$categoryId;
            continue;
        }
        if ($leafAudience === 'women' && isset($audienceSet['kids']) && !isset($audienceSet['women'])) {
            $conflicts[] = (int)$categoryId;
            continue;
        }
        if (count($audiences) > 1) {
            continue;
        }
        $conflicts[] = (int)$categoryId;
    }

    return $conflicts;
}

$items = [];
$appendCount = 0;
$unlinkCount = 0;
$unchanged = 0;
$pathAppendDist = [];

foreach ($allProducts as $product) {
    $productId = (int)$product['product_id'];
    $name = (string)($nameByProduct[$productId] ?? '');
    $gender = (string)($genderByProduct[$productId] ?? '');
    $dynasty = (string)($dynastyByProduct[$productId] ?? '');
    $material = (string)($materialByProduct[$productId] ?? '');
    $classification = classifyProductCategoryPaths($name, $gender, $dynasty, $material);
    $desiredIds = [];
    foreach ($classification['paths'] as $path) {
        $categoryId = $categoryByPath[$path] ?? 0;
        if ($categoryId < 1) {
            continue;
        }
        $desiredIds[$categoryId] = $path;
    }

    $existing = $existingLinks[$productId] ?? [];
    $toAppend = [];
    foreach ($desiredIds as $categoryId => $path) {
        if (!isset($existing[$categoryId])) {
            $toAppend[$categoryId] = $path;
            $pathAppendDist[$path] = ($pathAppendDist[$path] ?? 0) + 1;
        }
    }

    $toUnlink = conflictingLeafIds($existing, $classification['audiences'], $audienceById);
    $toUnlink = array_values(array_filter(
        $toUnlink,
        static fn(int $categoryId): bool => !isset($desiredIds[$categoryId]),
    ));

    if ($toAppend === [] && $toUnlink === []) {
        ++$unchanged;
        continue;
    }

    if ($apply) {
        foreach ($toUnlink as $categoryId) {
            $categoryLinks->unlink($websiteId, $categoryId, $productId, 0);
            ++$unlinkCount;
        }
        $position = 0;
        foreach ($toAppend as $categoryId => $_path) {
            $categoryLinks->link($websiteId, $categoryId, $productId, 0, true, $position++);
            ++$appendCount;
        }
    } else {
        $appendCount += count($toAppend);
        $unlinkCount += count($toUnlink);
    }

    $items[] = [
        'product_id' => $productId,
        'sku' => (string)($product['sku'] ?? ''),
        'name' => $name,
        'gender' => $gender,
        'dynasty' => $dynasty,
        'audiences' => $classification['audiences'],
        'reasons' => $classification['reasons'],
        'append_paths' => array_values($toAppend),
        'unlink_category_ids' => $toUnlink,
        'unlink_names' => array_map(
            static fn(int $id): string => $nameById[$id] ?? ('#' . $id),
            $toUnlink,
        ),
        'existing_names' => array_values(array_filter(array_map(
            static fn(int $id): string => $nameById[$id] ?? '',
            array_map('intval', array_keys($existing)),
        ))),
    ];
}

if ($apply && $items !== []) {
    ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)->notifyCatalogChanged(
        $websiteId,
        'product_category_multi_taxonomy_appended',
        [
            'product_ids' => array_column($items, 'product_id'),
            'appended_links' => $appendCount,
            'unlinked_conflicts' => $unlinkCount,
        ],
    );
}

$audienceDist = [];
foreach ($items as $item) {
    foreach ($item['audiences'] as $audience) {
        $audienceDist[$audience] = ($audienceDist[$audience] ?? 0) + 1;
    }
}
arsort($pathAppendDist);

echo json_encode([
    'contract' => CONTRACT,
    'mode' => $apply ? 'apply' : 'dry-run',
    'website_id' => $websiteId,
    'scanned' => count($allProducts),
    'changed' => count($items),
    'unchanged' => $unchanged,
    'append_links' => $appendCount,
    'unlink_conflicts' => $unlinkCount,
    'audience_dist_on_changes' => $audienceDist,
    'append_path_dist' => $pathAppendDist,
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
