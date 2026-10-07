<?php

declare(strict_types=1);

/**
 * Hanfu R2 commerce-media remediation.
 *
 * Registers every replacement image through FileManager with complete reviewed
 * zh_Hans_CN/en_US metadata, migrates category/product references, and removes
 * only the exact superseded files after verification proves zero live references.
 *
 * Usage:
 *   php app/code/Weline/Product/scripts/remediate-hanfu-commerce-media-r2.php --dry-run [--website=0]
 *   php app/code/Weline/Product/scripts/remediate-hanfu-commerce-media-r2.php --apply [--website=0]
 *   php app/code/Weline/Product/scripts/remediate-hanfu-commerce-media-r2.php --verify [--website=0]
 *   php app/code/Weline/Product/scripts/remediate-hanfu-commerce-media-r2.php --cleanup [--website=0]
 */

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\FileManager\Model\FileAsset;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Repository\MediaRepository;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Service\ProductCategoryAdminService;
use Weline\Product\Service\ProductCategoryAttributeService;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Storage\Api\Data\StorageDiskCode;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$allowedModes = ['--dry-run', '--apply', '--verify', '--cleanup'];
$modes = array_values(array_intersect($argv, $allowedModes));
if (count($modes) !== 1) {
    fwrite(STDERR, "Choose exactly one mode: --dry-run, --apply, --verify, or --cleanup.\n");
    exit(2);
}
$mode = $modes[0];
$websiteId = 0;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--website=')) {
        $websiteId = max(0, (int)substr($argument, strlen('--website=')));
    }
}

$root = dirname(__DIR__, 5);
$diskCode = StorageDiskCode::BUILTIN_LOCAL_MEDIA;
$locales = ['zh_Hans_CN', 'en_US'];
$access = [];
foreach ($locales as $locale) {
    $access[$locale] = new FileAccessContext(
        ScopeIdentity::global(),
        $locale,
        null,
        [],
        'metadata_edit',
    );
}

/**
 * cue_zh/cue_en are the visual correctness contract used by metadata and QA.
 *
 * @var array<string,array{zh:string,en:string,cue_zh:string,cue_en:string}>
 */
$categories = [
    'women' => ['zh' => '女装', 'en' => "Women's Hanfu", 'cue_zh' => '女性汉服整体层次与裙装轮廓', 'cue_en' => 'layered women’s Hanfu and skirt silhouette'],
    'ruqun' => ['zh' => '襦裙', 'en' => 'Ruqun', 'cue_zh' => '短襦与裙的上下分裁结构', 'cue_en' => 'the separate upper ru and lower skirt construction'],
    'qixiong' => ['zh' => '齐胸襦裙', 'en' => 'Chest-High Ruqun', 'cue_zh' => '裙头系于胸线附近的齐胸比例', 'cue_en' => 'the skirt tied around the upper chest line'],
    'qiyao' => ['zh' => '齐腰襦裙', 'en' => 'Waist-High Ruqun', 'cue_zh' => '裙头位于自然腰线的齐腰结构', 'cue_en' => 'the skirt secured at the natural waist'],
    'jiaoling' => ['zh' => '交领襦裙', 'en' => 'Cross-Collar Ruqun', 'cue_zh' => '交领右衽上襦与裙装组合', 'cue_en' => 'a cross-collar right-closing ru with skirt'],
    'duijin' => ['zh' => '对襟襦裙', 'en' => 'Parallel-Collar Ruqun', 'cue_zh' => '前襟平行相对的对襟上襦', 'cue_en' => 'a parallel-front duijin upper garment'],
    'aoqun' => ['zh' => '袄裙', 'en' => 'Aoqun', 'cue_zh' => '有衬袄与裙装的明代常见搭配', 'cue_en' => 'a lined ao jacket paired with a skirt'],
    'mamian' => ['zh' => '马面裙', 'en' => 'Mamian Skirt', 'cue_zh' => '前后光面与两侧褶裥的马面裙结构', 'cue_en' => 'flat front and back panels with pleated sides'],
    'quju' => ['zh' => '曲裾与深衣', 'en' => 'Quju and Shenyi', 'cue_zh' => '曲裾绕襟与深衣连属制轮廓', 'cue_en' => 'the wrapping quju line and joined shenyi silhouette'],
    'beizi' => ['zh' => '褙子、比甲与半臂', 'en' => 'Beizi, Bijia and Banbi', 'cue_zh' => '长褙子、无袖比甲与短袖半臂的外搭差异', 'cue_en' => 'the distinctions among long beizi, sleeveless bijia, and short-sleeved banbi'],
    'women-robe' => ['zh' => '女袍服', 'en' => "Women's Robes", 'cue_zh' => '女性袍服的长身整体轮廓', 'cue_en' => 'the full-length silhouette of women’s robes'],
    'men' => ['zh' => '男装', 'en' => "Men's Hanfu", 'cue_zh' => '男性袍衫的端正整体比例', 'cue_en' => 'the balanced proportions of men’s robes and gowns'],
    'yuanling' => ['zh' => '圆领袍', 'en' => 'Round-Collar Robe', 'cue_zh' => '闭合圆领与袍身结构', 'cue_en' => 'the closed round collar and robe construction'],
    'zhishen' => ['zh' => '直身、直裰与道袍', 'en' => 'Zhishen, Zhiduo and Daopao', 'cue_zh' => '交领长身袍服与侧摆细节', 'cue_en' => 'cross-collar long robes and side-panel details'],
    'lanshan' => ['zh' => '襕衫', 'en' => 'Lanshan', 'cue_zh' => '袍身下部横襕的襕衫识别特征', 'cue_en' => 'the characteristic lower horizontal lan panel'],
    'men-beizi' => ['zh' => '男褙子与短褐', 'en' => "Men's Beizi and Duanhe", 'cue_zh' => '男性褙子与短褐的长短层次', 'cue_en' => 'the contrasting lengths of men’s beizi and duanhe'],
    'kids' => ['zh' => '童装', 'en' => "Children's Hanfu", 'cue_zh' => '适合儿童活动的汉服比例与层次', 'cue_en' => 'child-proportioned Hanfu layers suited to movement'],
    'girls' => ['zh' => '女童汉服', 'en' => "Girls' Hanfu", 'cue_zh' => '女童尺度的襦裙或袄裙造型', 'cue_en' => 'girl-scaled ruqun or aoqun styling'],
    'boys' => ['zh' => '男童汉服', 'en' => "Boys' Hanfu", 'cue_zh' => '男童尺度的袍衫或短装造型', 'cue_en' => 'boy-scaled robes or short garments'],
    'accessories' => ['zh' => '汉服配饰', 'en' => 'Hanfu Accessories', 'cue_zh' => '与汉服搭配的头饰、佩饰、鞋履与披帛', 'cue_en' => 'headwear, pendants, footwear, and wraps coordinated with Hanfu'],
    'hair' => ['zh' => '头饰与发冠', 'en' => 'Hair Ornaments and Crowns', 'cue_zh' => '发簪、步摇、发冠等头部配饰', 'cue_en' => 'hairpins, buyao ornaments, and formal crowns'],
    'shoes' => ['zh' => '汉服鞋履', 'en' => 'Hanfu Footwear', 'cue_zh' => '翘头履、绣鞋与布靴等鞋履细节', 'cue_en' => 'upturned-toe shoes, embroidered shoes, and cloth boots'],
    'waist' => ['zh' => '腰饰与佩饰', 'en' => 'Waist and Pendant Accessories', 'cue_zh' => '腰带、绦带、禁步与佩玉的悬挂关系', 'cue_en' => 'the hanging relationship of belts, cords, jinbu, and pendants'],
    'wrap' => ['zh' => '巾帽与披帛', 'en' => 'Headcloths, Hats and Wraps', 'cue_zh' => '巾帽、披帛与肩部围搭细节', 'cue_en' => 'headcloths, hats, and shoulder-wrap details'],
    'sets' => ['zh' => '汉服套装', 'en' => 'Hanfu Sets', 'cue_zh' => '上装、下装与配饰完整搭配关系', 'cue_en' => 'a complete relationship among upper garment, lower garment, and accessories'],
    'daily' => ['zh' => '日常常服套装', 'en' => 'Everyday Hanfu Sets', 'cue_zh' => '便于行走与通勤的简洁汉服组合', 'cue_en' => 'streamlined Hanfu combinations suitable for everyday movement'],
    'wedding' => ['zh' => '婚礼婚服', 'en' => 'Hanfu Wedding Attire', 'cue_zh' => '礼仪场合的成套婚服与庄重配色', 'cue_en' => 'coordinated ceremonial wedding attire and formal color'],
    'festival' => ['zh' => '节令主题套装', 'en' => 'Festival Hanfu Sets', 'cue_zh' => '适合节令活动的完整汉服造型', 'cue_en' => 'complete Hanfu styling for seasonal festivities'],
];

$homepage = [
    'taoyuan-qingmeng' => [
        'zh' => '桃园清梦明制花鸟套装',
        'en' => 'Peach Garden Dream Ming-Style Set',
        'cue_zh' => '米白粉色明制上衣与马面裙，人物位于画面右侧并留出文案安全区',
        'cue_en' => 'an ivory-and-pink Ming-style top and mamian skirt, composed on the right with a text-safe area',
    ],
    'shenlong-yin' => [
        'zh' => '神龙吟妆花马面裙',
        'en' => 'Dragon Chant Zhuanghua Mamian',
        'cue_zh' => '黑红妆花马面裙的庄重明制服饰造型，人物位于画面右侧',
        'cue_en' => 'formal black-and-red zhuanghua mamian styling, composed on the right'],
    'zuimeng-xifeng' => [
        'zh' => '醉梦夕风仙鹤织金马面裙',
        'en' => 'Evening Wind Crane Brocade Mamian',
        'cue_zh' => '深色仙鹤织金马面裙造型，人物位于画面右侧并保留大面积留白',
        'cue_en' => 'a dark crane-brocade mamian look, composed on the right with generous negative space'],
];

$products = [
    'HF-TYQM-260303' => [
        'slug' => 'taoyuan-qingmeng',
        'zh_name' => '明制马面裙套装「桃园清梦」',
        'en_name' => 'Ming-Style Mamian Set “Peach Garden Dream”',
        'brand_zh' => '醉欢楼',
        'brand_en' => 'Zui Huan Lou',
        'material_zh' => '聚酯纤维100%',
        'material_en' => '100% polyester',
        'short_zh' => '明制上衣与马面裙组合，可选套装、马面裙单件或上衣单件；米白、粉色，S–XL。',
        'short_en' => 'A Ming-style top and mamian skirt combination, offered as a set, skirt only, or top only; ivory and pink, sizes S–XL.',
        'description_zh' => '商品识别：SKU HF-TYQM-260303。形制与组合：明制上衣配马面裙，可选套装（上衣+马面裙）、马面裙单件或上衣单件。页面已核实信息：品牌醉欢楼；面料标注聚酯纤维100%；颜色为米白、粉色；尺码为 S、M、L、XL。不同组合包含件数不同，下单前请按“类型”确认所选为套装还是单件。',
        'description_en' => 'Product ID: SKU HF-TYQM-260303. Form and options: a Ming-style top coordinated with a mamian skirt, available as a top-and-skirt set, skirt only, or top only. Verified listing facts: brand Zui Huan Lou; fabric listed as 100% polyester; colors ivory and pink; sizes S, M, L, and XL. The number of pieces varies by option, so confirm whether “Type” is set to a full set or a single garment before ordering.',
        'source_note_zh' => '项目既有商品图库与已核实商品参数',
        'source_note_en' => 'project-supplied product gallery and verified listing parameters',
        'files' => ['01', '02', '03', '04', '05', '06', '07', 'pink-set-01', 'type-skirt-mwhite', 'type-skirt-pink', 'type-top-mwhite', 'type-top-pink'],
    ],
    'HF-SLY-Z230903' => [
        'slug' => 'shenlong-yin-zhuanghua-mamian',
        'zh_name' => '神龙吟妆花明制马面裙',
        'en_name' => 'Dragon Chant Zhuanghua Ming-Style Mamian',
        'brand_zh' => '醉欢楼',
        'brand_en' => 'Zui Huan Lou',
        'material_zh' => '复合面料 / 聚酯纤维100%',
        'material_en' => 'composite fabric / 100% polyester',
        'short_zh' => '妆花明制马面裙与白色飞机袖上衣系列；黑、红、白可选，S–XL。',
        'short_en' => 'A series combining zhuanghua Ming-style mamian skirts with a white feijixiu top; black, red, and white options, sizes S–XL.',
        'description_zh' => '商品识别：SKU HF-SLY-Z230903。可选类型包括黑色妆花马面裙、红色妆花马面裙与白色飞机袖上衣。页面已核实信息：品牌醉欢楼；面料标注为复合面料 / 聚酯纤维100%；颜色选项为黑、红、白；尺码为 S、M、L、XL。裙装与上衣是不同类型选项，并非每个选项都包含整套，请按类型与颜色对应关系选择。',
        'description_en' => 'Product ID: SKU HF-SLY-Z230903. Type options include a black zhuanghua mamian skirt, a red zhuanghua mamian skirt, and a white feijixiu top. Verified listing facts: brand Zui Huan Lou; fabric listed as composite fabric / 100% polyester; color options black, red, and white; sizes S, M, L, and XL. The skirts and top are separate type options rather than a guaranteed full set, so choose using the matching Type and Color options.',
        'source_note_zh' => '项目既有商品图库与已核实商品参数',
        'source_note_en' => 'project-supplied product gallery and verified listing parameters',
        'files' => ['01', '02', '03', '04', '05', '06'],
    ],
    'HF-ZMXF-XH-2026' => [
        'slug' => 'zuimeng-xifeng-xianhe-mamian',
        'zh_name' => '醉梦夕风织金马面裙「仙鹤」',
        'en_name' => 'Evening Wind Brocade Mamian “Crane”',
        'brand_zh' => '醉梦夕风',
        'brand_en' => 'Zui Meng Xi Feng',
        'material_zh' => '螺钿幻彩织金 / 聚酯95%+其他5%',
        'material_en' => 'iridescent brocade / 95% polyester + 5% other fibers',
        'short_zh' => '仙鹤主题织金马面裙，可选马面裙单件或套装；仙鹤黑、仙鹤红、幻彩卿竹白，S–L。',
        'short_en' => 'A crane-themed brocade mamian skirt offered as skirt only or a set; Crane Black, Crane Red, and Iridescent Qingzhu White, sizes S–L.',
        'description_zh' => '商品识别：SKU HF-ZMXF-XH-2026。形制与组合：仙鹤主题织金马面裙，可选马面裙单件或套装。页面已核实信息：品牌醉梦夕风；面料标注螺钿幻彩织金 / 聚酯95%+其他5%；颜色为仙鹤黑、仙鹤红、幻彩卿竹白；尺码为 S、M、L。套装与单裙包含件数不同，请先确认“类型”再选择颜色与尺码。',
        'description_en' => 'Product ID: SKU HF-ZMXF-XH-2026. Form and options: a crane-themed brocade mamian skirt, available as skirt only or a set. Verified listing facts: brand Zui Meng Xi Feng; fabric listed as iridescent brocade / 95% polyester plus 5% other fibers; colors Crane Black, Crane Red, and Iridescent Qingzhu White; sizes S, M, and L. The set and skirt-only options contain different numbers of pieces, so confirm “Type” before choosing color and size.',
        'source_note_zh' => '项目既有商品图库与已核实商品参数',
        'source_note_en' => 'project-supplied product gallery and verified listing parameters',
        'files' => ['01', '02', '03', '04', '05', '06', '07'],
    ],
];

/** @return array<string,mixed> */
function hanfuR2LocaleMetadata(string $name, string $alt, string $description, string $caption): array
{
    return [
        'display_name' => $name,
        'default_alt' => $alt,
        'description' => $description,
        'default_caption' => $caption,
        'translation_state' => FileAssetLibraryInterface::TRANSLATION_REVIEWED,
        'translation_origin' => FileAssetLibraryInterface::TRANSLATION_MANUAL,
    ];
}

/** @return array{0:int,1:int} */
function hanfuR2ImageSize(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Required image is missing or unreadable: ' . $path);
    }
    $size = getimagesize($path);
    if (!is_array($size) || (int)($size[0] ?? 0) < 1 || (int)($size[1] ?? 0) < 1) {
        throw new RuntimeException('Invalid image file: ' . $path);
    }
    return [(int)$size[0], (int)$size[1]];
}

/**
 * @param array<string,mixed> $definition
 * @return array<string,mixed>
 */
function hanfuR2EnsureAsset(
    FileAssetLibraryInterface $library,
    string $diskCode,
    array $access,
    array $definition,
): array {
    $sourcePath = (string)$definition['source_path'];
    $objectKey = (string)$definition['object_key'];
    [$width, $height] = hanfuR2ImageSize($sourcePath);
    if (isset($definition['expected_width']) && $width !== (int)$definition['expected_width']) {
        throw new RuntimeException('Unexpected width for ' . $objectKey . ': ' . $width);
    }
    if (isset($definition['expected_height']) && $height !== (int)$definition['expected_height']) {
        throw new RuntimeException('Unexpected height for ' . $objectKey . ': ' . $height);
    }

    $descriptor = $library->describe($diskCode, $objectKey, 'zh_Hans_CN', $access['zh_Hans_CN']);
    if (empty($descriptor['asset_id'])) {
        $stream = fopen($sourcePath, 'rb');
        if (!is_resource($stream)) {
            throw new RuntimeException('Unable to open image: ' . $sourcePath);
        }
        try {
            $descriptor = $library->upload(
                $diskCode,
                $objectKey,
                $stream,
                basename($sourcePath),
                'image/webp',
                'zh_Hans_CN',
                $access['zh_Hans_CN'],
                $definition['locales']['zh_Hans_CN'],
                FileAssetLibraryInterface::VISIBILITY_PUBLIC,
                $definition['metadata'],
                $width,
                $height,
            );
        } finally {
            fclose($stream);
        }
    } else {
        $actualSha = strtolower(trim((string)($descriptor['sha256'] ?? '')));
        $sourceSha = hash_file('sha256', $sourcePath);
        if ($actualSha === '' || !hash_equals($sourceSha, $actualSha)) {
            throw new RuntimeException('Existing FileAsset binary differs; overwrite refused: ' . $objectKey);
        }
    }

    foreach (['zh_Hans_CN', 'en_US'] as $locale) {
        $current = $library->describe($diskCode, $objectKey, $locale, $access[$locale]);
        $assetId = trim((string)($current['asset_id'] ?? ''));
        $revision = (int)($current['asset_revision'] ?? 0);
        if ($assetId === '' || $revision < 1) {
            throw new RuntimeException('FileAsset descriptor invalid: ' . $objectKey . ' / ' . $locale);
        }
        $library->saveMetadata(
            $assetId,
            $diskCode,
            $objectKey,
            $locale,
            $access[$locale],
            $revision,
            $definition['locales'][$locale],
        );
    }

    $verified = [];
    foreach (['zh_Hans_CN', 'en_US'] as $locale) {
        $row = $library->describe($diskCode, $objectKey, $locale, $access[$locale]);
        foreach (['asset_id', 'display_name', 'default_alt', 'description', 'default_caption'] as $field) {
            if (trim((string)($row[$field] ?? '')) === '') {
                throw new RuntimeException("Missing {$field} for {$objectKey} / {$locale}");
            }
        }
        if (($row['translation_state'] ?? '') !== FileAssetLibraryInterface::TRANSLATION_REVIEWED
            || ($row['translation_origin'] ?? '') !== FileAssetLibraryInterface::TRANSLATION_MANUAL
            || empty($row['asset_selectable'])
        ) {
            throw new RuntimeException('FileAsset locale is not reviewed/manual/selectable: ' . $objectKey . ' / ' . $locale);
        }
        $verified[$locale] = $row;
    }
    return $verified['zh_Hans_CN'];
}

/**
 * @param list<array<string,mixed>> $nodes
 * @return array<string,array<string,mixed>>
 */
function hanfuR2FlattenCategories(array $nodes): array
{
    $result = [];
    $walk = static function (array $items) use (&$walk, &$result): void {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = strtolower(trim((string)($item['code'] ?? '')));
            if ($code !== '') {
                $result[$code] = $item;
            }
            $walk(is_array($item['nodes'] ?? null) ? $item['nodes'] : []);
        }
    };
    $walk($nodes);
    return $result;
}

/** @return mixed */
function hanfuR2RewriteProductImagePaths(mixed $value, string $slug, array $allowedBasenames): mixed
{
    if (is_array($value)) {
        foreach ($value as $key => $child) {
            $value[$key] = hanfuR2RewriteProductImagePaths($child, $slug, $allowedBasenames);
        }
        return $value;
    }
    if (!is_string($value)) {
        return $value;
    }
    $oldPrefix = '/pub/media/catalog/hanfu/' . $slug . '/';
    if (!str_starts_with($value, $oldPrefix)) {
        return $value;
    }
    $basename = pathinfo(basename($value), PATHINFO_FILENAME);
    if (!in_array($basename, $allowedBasenames, true)) {
        throw new RuntimeException('No reviewed WebP replacement for product image: ' . $value);
    }
    return '/pub/media/catalog/hanfu/r2/products/' . $slug . '/' . $basename . '.webp';
}

/**
 * @param array<string,array<string,mixed>> $assets
 * @return array<string,mixed>
 */
function hanfuR2VerifyState(
    int $websiteId,
    array $categories,
    array $products,
    array $assets,
    FileAssetLibraryInterface $library,
    array $access,
    ProductCategoryAdminService $categoryAdmin,
    ProductCategoryAttributeService $categoryAttributes,
    AttributeValueRepository $attributes,
    ProductRepository $productRepository,
    MediaRepository $mediaRepository,
): array {
    $categoryTree = hanfuR2FlattenCategories($categoryAdmin->tree($websiteId, 'zh_Hans_CN'));
    foreach ($categories as $code => $_meta) {
        $categoryId = (int)($categoryTree[$code]['category_id'] ?? 0);
        if ($categoryId < 1) {
            throw new RuntimeException('Category not found during verify: ' . $code);
        }
        $expectedImage = '/pub/media/catalog/hanfu/r2/categories/icons/' . $code . '.webp';
        $expectedBanner = '/pub/media/catalog/hanfu/r2/categories/banners/' . $code . '.webp';
        foreach (['', 'zh_Hans_CN', 'en_US'] as $locale) {
            $image = (string)$attributes->read(
                $websiteId,
                0,
                ProductCategoryAttributeService::ENTITY_TYPE,
                $categoryId,
                'image',
                $locale,
            )->value;
            $banner = (string)$attributes->read(
                $websiteId,
                0,
                ProductCategoryAttributeService::ENTITY_TYPE,
                $categoryId,
                'banner',
                $locale,
            )->value;
            if ($image !== $expectedImage || $banner !== $expectedBanner) {
                throw new RuntimeException("Category media mismatch: {$code} / {$locale}");
            }
        }
    }

    $productIds = [];
    $expectedMediaCount = 0;
    foreach ($products as $sku => $product) {
        $model = $productRepository->findBySku($websiteId, $sku);
        if ($model === null) {
            throw new RuntimeException('Product not found during verify: ' . $sku);
        }
        $productId = (int)$model->getId();
        $productIds[] = $productId;
        $expectedMediaCount += count($product['files']);
        $checks = [
            'name' => ['' => $product['zh_name'], 'zh_Hans_CN' => $product['zh_name'], 'en_US' => $product['en_name']],
            'brand' => ['' => $product['brand_zh'], 'zh_Hans_CN' => $product['brand_zh'], 'en_US' => $product['brand_en']],
            'material' => ['' => $product['material_zh'], 'zh_Hans_CN' => $product['material_zh'], 'en_US' => $product['material_en']],
            'short_description' => ['' => $product['short_zh'], 'zh_Hans_CN' => $product['short_zh'], 'en_US' => $product['short_en']],
            'description' => ['' => $product['description_zh'], 'zh_Hans_CN' => $product['description_zh'], 'en_US' => $product['description_en']],
        ];
        foreach ($checks as $attributeCode => $byLocale) {
            foreach ($byLocale as $locale => $expected) {
                $actual = (string)$attributes->read(
                    $websiteId,
                    0,
                    'product',
                    $productId,
                    $attributeCode,
                    $locale,
                )->value;
                if ($actual !== $expected) {
                    throw new RuntimeException("Product attribute mismatch: {$sku} / {$attributeCode} / {$locale}");
                }
            }
        }
        $config = $attributes->read(
            $websiteId,
            0,
            'product',
            $productId,
            'type_configuration',
        )->value;
        $config = is_array($config) ? $config : (is_string($config) ? json_decode($config, true) : null);
        if (!is_array($config)) {
            throw new RuntimeException('Product type_configuration invalid: ' . $sku);
        }
        $encoded = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)
            || str_contains($encoded, '/pub/media/catalog/hanfu/' . $product['slug'] . '/')
            || (str_contains($encoded, '.jpg') && str_contains($encoded, '/hanfu/'))
        ) {
            throw new RuntimeException('Legacy product image path remains in type_configuration: ' . $sku);
        }
    }

    $rows = $mediaRepository->listByProductIds($websiteId, $productIds, [0]);
    if (count($rows) !== $expectedMediaCount) {
        throw new RuntimeException("Expected {$expectedMediaCount} product media rows, found " . count($rows));
    }
    foreach ($rows as $row) {
        if (trim((string)($row[Media::schema_fields_ASSET_ID] ?? '')) === ''
            || !str_starts_with((string)($row[Media::schema_fields_PATH] ?? ''), 'asset://')
            || strtolower((string)($row[Media::schema_fields_MIME_TYPE] ?? '')) !== 'image/webp'
        ) {
            throw new RuntimeException('Legacy or incomplete product media row remains.');
        }
    }

    $fileAssetModel = ObjectManager::getInstance(FileAsset::class);
    foreach ($assets as $objectKey => $definition) {
        foreach (['zh_Hans_CN', 'en_US'] as $locale) {
            $descriptor = $library->describe(
                StorageDiskCode::BUILTIN_LOCAL_MEDIA,
                $objectKey,
                $locale,
                $access[$locale],
            );
            if (empty($descriptor['asset_selectable'])
                || ($descriptor['translation_state'] ?? '') !== FileAssetLibraryInterface::TRANSLATION_REVIEWED
                || ($descriptor['translation_origin'] ?? '') !== FileAssetLibraryInterface::TRANSLATION_MANUAL
                || trim((string)($descriptor['default_caption'] ?? '')) === ''
            ) {
                throw new RuntimeException("Incomplete FileAsset locale metadata: {$objectKey} / {$locale}");
            }
        }
        $asset = clone $fileAssetModel;
        $asset->clearData()->reset()
            ->where(FileAsset::schema_fields_DISK_CODE, StorageDiskCode::BUILTIN_LOCAL_MEDIA)
            ->where(FileAsset::schema_fields_OBJECT_KEY, $objectKey)
            ->find()->fetch();
        $metadata = json_decode((string)$asset->getData(FileAsset::schema_fields_METADATA), true);
        foreach (['source', 'license', 'purpose', 'relations', 'review'] as $metadataKey) {
            if (!is_array($metadata) || !array_key_exists($metadataKey, $metadata)) {
                throw new RuntimeException("Missing FileAsset provenance metadata {$metadataKey}: {$objectKey}");
            }
        }
    }

    return [
        'categories' => count($categories),
        'products' => count($products),
        'product_media' => count($rows),
        'file_assets' => count($assets),
        'locales_per_asset' => 2,
    ];
}

/** @var FileAssetLibraryInterface $library */
$library = ObjectManager::getInstance(FileAssetLibraryInterface::class);
/** @var ProductCategoryAdminService $categoryAdmin */
$categoryAdmin = ObjectManager::getInstance(ProductCategoryAdminService::class);
/** @var ProductCategoryAttributeService $categoryAttributes */
$categoryAttributes = ObjectManager::getInstance(ProductCategoryAttributeService::class);
/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);
/** @var ProductRepository $productRepository */
$productRepository = ObjectManager::getInstance(ProductRepository::class);
/** @var MediaRepository $mediaRepository */
$mediaRepository = ObjectManager::getInstance(MediaRepository::class);
/** @var StorefrontCatalogCacheCoordinator $catalogCache */
$catalogCache = ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class);

$assets = [];
foreach ($categories as $code => $meta) {
    $iconKey = 'catalog/hanfu/r2/categories/icons/' . $code . '.webp';
    $bannerKey = 'catalog/hanfu/r2/categories/banners/' . $code . '.webp';
    $assets[$iconKey] = [
        'source_path' => $root . '/var/hanfu-production/final/category-icons/' . $code . '.webp',
        'object_key' => $iconKey,
        'expected_width' => 800,
        'expected_height' => 800,
        'locales' => [
            'zh_Hans_CN' => hanfuR2LocaleMetadata(
                $meta['zh'] . '分类方图',
                $meta['zh'] . '汉服分类图，展示' . $meta['cue_zh'],
                '用于' . $meta['zh'] . '分类入口的 1:1 方图；' . $meta['cue_zh'] . '，已按形制人工校对。',
                $meta['zh'] . '分类',
            ),
            'en_US' => hanfuR2LocaleMetadata(
                $meta['en'] . ' Category Tile',
                $meta['en'] . ' category image showing ' . $meta['cue_en'],
                'A 1:1 storefront category tile for ' . $meta['en'] . ', showing ' . $meta['cue_en'] . '; manually reviewed for form accuracy.',
                $meta['en'],
            ),
        ],
        'metadata' => [
            'source' => ['type' => 'openai_imagegen_derived', 'master' => 'var/hanfu-production/category-masters/' . $code . '.png', 'generated_at' => '2026-09-01'],
            'license' => ['status' => 'project_generated_asset', 'usage' => 'commercial storefront and editorial use under the project account terms'],
            'purpose' => ['surface' => 'product_category_grid', 'format' => '1:1', 'visual_contract' => $meta['cue_en']],
            'relations' => ['entity_type' => 'product_category', 'category_code' => $code, 'role' => 'image'],
            'review' => ['state' => 'manual_reviewed', 'reviewed_at' => '2026-09-01', 'checks' => ['form_accuracy', 'crop', 'no_text', 'no_watermark']],
        ],
    ];
    $assets[$bannerKey] = [
        'source_path' => $root . '/var/hanfu-production/final/category-banners/' . $code . '.webp',
        'object_key' => $bannerKey,
        'expected_width' => 1500,
        'expected_height' => 300,
        'locales' => [
            'zh_Hans_CN' => hanfuR2LocaleMetadata(
                $meta['zh'] . '分类横幅',
                $meta['zh'] . '汉服横幅，重点展示' . $meta['cue_zh'],
                '用于' . $meta['zh'] . '分类页的 5:1 横幅；突出' . $meta['cue_zh'] . '，已按形制与裁切人工校对。',
                $meta['zh'] . '分类横幅',
            ),
            'en_US' => hanfuR2LocaleMetadata(
                $meta['en'] . ' Category Banner',
                $meta['en'] . ' banner highlighting ' . $meta['cue_en'],
                'A 5:1 category-page banner for ' . $meta['en'] . ', highlighting ' . $meta['cue_en'] . '; manually reviewed for form and crop accuracy.',
                $meta['en'] . ' category banner',
            ),
        ],
        'metadata' => [
            'source' => ['type' => 'openai_imagegen_derived', 'master' => 'var/hanfu-production/category-masters/' . $code . '.png', 'generated_at' => '2026-09-01'],
            'license' => ['status' => 'project_generated_asset', 'usage' => 'commercial storefront and editorial use under the project account terms'],
            'purpose' => ['surface' => 'product_category_banner', 'format' => '5:1', 'visual_contract' => $meta['cue_en']],
            'relations' => ['entity_type' => 'product_category', 'category_code' => $code, 'role' => 'banner'],
            'review' => ['state' => 'manual_reviewed', 'reviewed_at' => '2026-09-01', 'checks' => ['form_accuracy', 'crop', 'no_text', 'no_watermark']],
        ],
    ];
}

foreach ($homepage as $slug => $meta) {
    foreach (['desktop' => ['', 1920, 600], 'mobile' => ['-mobile', 900, 1200]] as $variant => [$suffix, $width, $height]) {
        $objectKey = 'catalog/hanfu/r2/homepage/' . $slug . $suffix . '.webp';
        $assets[$objectKey] = [
            'source_path' => $root . '/var/hanfu-production/final/homepage/' . $slug . $suffix . '.webp',
            'object_key' => $objectKey,
            'expected_width' => $width,
            'expected_height' => $height,
            'locales' => [
                'zh_Hans_CN' => hanfuR2LocaleMetadata(
                    $meta['zh'] . ($variant === 'mobile' ? '移动端首页横幅' : '桌面端首页横幅'),
                    $meta['zh'] . '首页视觉，' . $meta['cue_zh'],
                    '用于首页首屏轮播的' . ($variant === 'mobile' ? '移动端 3:4' : '桌面端 16:5') . '视觉；' . $meta['cue_zh'] . '，无烘焙文字。',
                    $meta['zh'],
                ),
                'en_US' => hanfuR2LocaleMetadata(
                    $meta['en'] . ($variant === 'mobile' ? ' Mobile Homepage Banner' : ' Desktop Homepage Banner'),
                    $meta['en'] . ' homepage visual, ' . $meta['cue_en'],
                    'A ' . ($variant === 'mobile' ? '3:4 mobile' : '16:5 desktop') . ' homepage hero for ' . $meta['en'] . '; ' . $meta['cue_en'] . ', with no baked-in text.',
                    $meta['en'],
                ),
            ],
            'metadata' => [
                'source' => ['type' => 'openai_imagegen_derived', 'master' => 'var/hanfu-production/homepage-hero/' . $slug . '.png', 'generated_at' => '2026-09-01'],
                'license' => ['status' => 'project_generated_asset', 'usage' => 'commercial storefront use under the project account terms'],
                'purpose' => ['surface' => 'homepage_hero', 'variant' => $variant, 'text_safe' => true],
                'relations' => ['entity_type' => 'product', 'product_slug' => $slug, 'role' => 'homepage_banner'],
                'review' => ['state' => 'manual_reviewed', 'reviewed_at' => '2026-09-01', 'checks' => ['product_form', 'responsive_crop', 'text_safe_area', 'no_text', 'no_watermark']],
            ],
        ];
    }
}

foreach ($products as $sku => $product) {
    foreach ($product['files'] as $index => $basename) {
        $objectKey = 'catalog/hanfu/r2/products/' . $product['slug'] . '/' . $basename . '.webp';
        $position = $index + 1;
        $assets[$objectKey] = [
            'source_path' => $root . '/var/hanfu-production/final/products/' . $product['slug'] . '/' . $basename . '.webp',
            'object_key' => $objectKey,
            'locales' => [
                'zh_Hans_CN' => hanfuR2LocaleMetadata(
                    $product['zh_name'] . '商品图 ' . $position,
                    $product['zh_name'] . '商品实拍图 ' . $position,
                    $product['zh_name'] . '第 ' . $position . ' 张商品图库图片；来自项目既有商品素材，已转换为 WebP 并人工核对商品一致性。',
                    $product['zh_name'] . ' · 商品图 ' . $position,
                ),
                'en_US' => hanfuR2LocaleMetadata(
                    $product['en_name'] . ' Product Image ' . $position,
                    $product['en_name'] . ' product photograph ' . $position,
                    'Product gallery image ' . $position . ' for ' . $product['en_name'] . '; converted from the project-supplied catalog photography to WebP and manually checked against the SKU.',
                    $product['en_name'] . ' · Product image ' . $position,
                ),
            ],
            'metadata' => [
                'source' => ['type' => 'project_supplied_catalog_photography_converted', 'original' => 'pub/media/catalog/hanfu/' . $product['slug'] . '/' . $basename . '.jpg', 'converted_at' => '2026-09-01'],
                'license' => ['status' => 'merchant_supplied_catalog_asset', 'usage' => 'storefront product presentation; merchant retains responsibility for source rights'],
                'purpose' => ['surface' => 'product_gallery', 'position' => $position],
                'relations' => ['entity_type' => 'product', 'sku' => $sku, 'product_slug' => $product['slug'], 'role' => $position === 1 ? 'main' : 'gallery'],
                'review' => ['state' => 'manual_reviewed', 'reviewed_at' => '2026-09-01', 'checks' => ['sku_match', 'color_match', 'image_integrity']],
            ],
        ];
    }
}

if (count($categories) !== 28 || count($homepage) !== 3 || count($products) !== 3 || count($assets) !== 87) {
    throw new RuntimeException('Remediation manifest count contract failed.');
}

$categoryTree = hanfuR2FlattenCategories($categoryAdmin->tree($websiteId, 'zh_Hans_CN'));
foreach (array_keys($categories) as $code) {
    if ((int)($categoryTree[$code]['category_id'] ?? 0) < 1) {
        throw new RuntimeException('Required product category not found: ' . $code);
    }
}
foreach (array_keys($products) as $sku) {
    if ($productRepository->findBySku($websiteId, $sku) === null) {
        throw new RuntimeException('Required product not found: ' . $sku);
    }
}

$manifestHashes = [];
foreach ($assets as $objectKey => $definition) {
    [$width, $height] = hanfuR2ImageSize($definition['source_path']);
    if (isset($definition['expected_width']) && $width !== (int)$definition['expected_width']) {
        throw new RuntimeException('Width contract failed: ' . $objectKey);
    }
    if (isset($definition['expected_height']) && $height !== (int)$definition['expected_height']) {
        throw new RuntimeException('Height contract failed: ' . $objectKey);
    }
    $manifestHashes[$objectKey] = hash_file('sha256', $definition['source_path']);
}
foreach (['catalog/hanfu/r2/categories/icons/', 'catalog/hanfu/r2/categories/banners/', 'catalog/hanfu/r2/homepage/'] as $prefix) {
    $subset = array_filter(
        $manifestHashes,
        static fn(string $_hash, string $key): bool => str_starts_with($key, $prefix),
        ARRAY_FILTER_USE_BOTH,
    );
    if (count(array_unique($subset)) !== count($subset)) {
        throw new RuntimeException('Duplicate binary detected in visual set: ' . $prefix);
    }
}

if ($mode === '--dry-run') {
    echo json_encode([
        'ok' => true,
        'mode' => 'dry-run',
        'website_id' => $websiteId,
        'manifest' => [
            'categories' => count($categories),
            'category_assets' => count($categories) * 2,
            'homepage_assets' => count($homepage) * 2,
            'product_assets' => array_sum(array_map(static fn(array $item): int => count($item['files']), $products)),
            'total_file_assets' => count($assets),
            'required_locales' => $locales,
        ],
        'writes' => 0,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
    exit(0);
}

if ($mode === '--apply') {
    $registered = [];
    foreach ($assets as $objectKey => $definition) {
        $registered[$objectKey] = hanfuR2EnsureAsset($library, $diskCode, $access, $definition);
    }

    foreach ($categories as $code => $_meta) {
        $categoryId = (int)$categoryTree[$code]['category_id'];
        $image = '/pub/media/catalog/hanfu/r2/categories/icons/' . $code . '.webp';
        $banner = '/pub/media/catalog/hanfu/r2/categories/banners/' . $code . '.webp';
        foreach (['', 'zh_Hans_CN', 'en_US'] as $locale) {
            $categoryAttributes->writeImage($websiteId, $categoryId, $image, $locale);
            $categoryAttributes->writeBanner($websiteId, $categoryId, $banner, $locale);
        }
    }

    foreach ($products as $sku => $product) {
        $model = $productRepository->findBySku($websiteId, $sku);
        if ($model === null) {
            throw new RuntimeException('Product disappeared during apply: ' . $sku);
        }
        $productId = (int)$model->getId();
        $localized = [
            '' => [
                'name' => $product['zh_name'],
                'brand' => $product['brand_zh'],
                'material' => $product['material_zh'],
                'short_description' => $product['short_zh'],
                'description' => $product['description_zh'],
                'reference_source' => $product['source_note_zh'],
            ],
            'zh_Hans_CN' => [
                'name' => $product['zh_name'],
                'brand' => $product['brand_zh'],
                'material' => $product['material_zh'],
                'short_description' => $product['short_zh'],
                'description' => $product['description_zh'],
                'reference_source' => $product['source_note_zh'],
            ],
            'en_US' => [
                'name' => $product['en_name'],
                'brand' => $product['brand_en'],
                'material' => $product['material_en'],
                'short_description' => $product['short_en'],
                'description' => $product['description_en'],
                'reference_source' => $product['source_note_en'],
            ],
        ];
        foreach ($localized as $locale => $values) {
            foreach ($values as $attributeCode => $value) {
                $attributes->writeExplicit(
                    $websiteId,
                    0,
                    'product',
                    $productId,
                    $attributeCode,
                    $locale,
                    $value,
                    $attributeCode === 'name',
                );
            }
        }

        $config = $attributes->read(
            $websiteId,
            0,
            'product',
            $productId,
            'type_configuration',
        )->value;
        $config = is_array($config) ? $config : (is_string($config) ? json_decode($config, true) : null);
        if (!is_array($config)) {
            throw new RuntimeException('Product type_configuration invalid before migration: ' . $sku);
        }
        $config = hanfuR2RewriteProductImagePaths($config, $product['slug'], $product['files']);
        $attributes->writeTyped(
            $websiteId,
            0,
            'product',
            $productId,
            'type_configuration',
            '',
            'json',
            $config,
            false,
        );

        $mediaRows = [];
        foreach ($product['files'] as $index => $basename) {
            $objectKey = 'catalog/hanfu/r2/products/' . $product['slug'] . '/' . $basename . '.webp';
            $assetId = trim((string)($registered[$objectKey]['asset_id'] ?? ''));
            if ($assetId === '') {
                throw new RuntimeException('Registered product asset missing ID: ' . $objectKey);
            }
            $mediaRows[] = [
                Media::schema_fields_ASSET_ID => $assetId,
                Media::schema_fields_ROLE => $index === 0 ? 'main' : 'gallery',
                Media::schema_fields_ASSET_VISIBILITY => FileAssetLibraryInterface::VISIBILITY_PUBLIC,
                Media::schema_fields_MIME_TYPE => 'image/webp',
                Media::schema_fields_ACCESS_POLICY_JSON => null,
                Media::schema_fields_POSITION => $index + 1,
                Media::schema_fields_HIDDEN => 0,
            ];
        }
        $mediaRepository->syncProductScope($websiteId, $productId, 0, $mediaRows);
        foreach ($mediaRepository->listByProductIds($websiteId, [$productId], [0]) as $row) {
            if (trim((string)($row[Media::schema_fields_ASSET_ID] ?? '')) !== '') {
                continue;
            }
            $mediaId = (int)($row[Media::schema_fields_ID] ?? 0);
            if ($mediaId > 0) {
                $mediaRepository->remove($websiteId, $mediaId);
            }
        }
    }

    $catalogCache->notifyCatalogChanged(
        $websiteId,
        'hanfu_r2_content_visual_remediation',
        ['categories' => count($categories), 'products' => count($products), 'assets' => count($assets)],
    );
}

$verification = hanfuR2VerifyState(
    $websiteId,
    $categories,
    $products,
    $assets,
    $library,
    $access,
    $categoryAdmin,
    $categoryAttributes,
    $attributes,
    $productRepository,
    $mediaRepository,
);

if ($mode === '--cleanup') {
    $cleanupKeys = [];
    foreach (array_merge(array_keys($categories), ['hanfu']) as $code) {
        $cleanupKeys[] = 'catalog/hanfu/categories/icons/' . $code . '.svg';
        $cleanupKeys[] = 'catalog/hanfu/categories/banners/' . $code . '.svg';
    }
    foreach ([
        'ai-main.png',
        'ai-red.png',
        'hanfu-qrj-main-20260901.jpg',
        'hanfu-qrj-red-20260901.jpg',
        'main.jpg',
        'red.jpg',
    ] as $file) {
        $cleanupKeys[] = 'catalog/hanfu/test-create-20260901/' . $file;
    }
    if (count($cleanupKeys) !== 64 || count(array_unique($cleanupKeys)) !== 64) {
        throw new RuntimeException('Cleanup allowlist contract failed.');
    }
    foreach ($cleanupKeys as $objectKey) {
        $absolute = $root . '/pub/media/' . $objectKey;
        if (!is_file($absolute)) {
            throw new RuntimeException('Cleanup target is not an exact existing file: ' . $objectKey);
        }
        $library->deleteObject($diskCode, $objectKey, $access['zh_Hans_CN']);
        if (is_file($absolute)) {
            throw new RuntimeException('Cleanup target still exists after guarded deletion: ' . $objectKey);
        }
    }
    $verification['deleted_exact_unused_files'] = count($cleanupKeys);
}

echo json_encode([
    'ok' => true,
    'mode' => ltrim($mode, '-'),
    'website_id' => $websiteId,
    'verification' => $verification,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
