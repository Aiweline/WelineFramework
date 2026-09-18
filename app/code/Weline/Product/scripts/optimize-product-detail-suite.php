<?php

declare(strict_types=1);

/**
 * ecommerce-detail-suite 批量/单品优化（跳过已有 data-weds=xq）
 *
 * 图：裁死白柱、禁空放大；主图锁源 canvas AR（方则 ~1.0，竖则 ~0.75）；不在本脚本内做 GenerateImage outpaint。
 * 详情：相册→杂志楼层（≥4 原型）、抹三方词、多语真译、写 data-weds=xq。
 *
 * php app/code/Weline/Product/scripts/optimize-product-detail-suite.php --product=113 --dry-run
 * php app/code/Weline/Product/scripts/optimize-product-detail-suite.php --product=113 --apply
 * php app/code/Weline/Product/scripts/optimize-product-detail-suite.php --product=200 --force --apply
 * php app/code/Weline/Product/scripts/optimize-product-detail-suite.php --pending-file=/tmp/p-batch-pending-published.tsv --limit=20 --apply
 * php app/code/Weline/Product/scripts/optimize-product-detail-suite.php --pending-file=... --offset=20 --limit=20 --apply
 *
 * 硬闸：低码率 soft（bpp&lt;1.2）须 Real-ESRGAN 或换清原图，禁止空放大后盖 data-weds；
 * 版式禁止「千篇一律大图到底」——通栏后必须交错实质 prose，禁止连续 ≥3 solo/fullbleed。
 */

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Model\Product\LocalDescription;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Service\DetailDescriptionTextifier;
use Weline\Product\Service\ProductStorefrontCacheInvalidator;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Storage\Api\Data\StorageDiskCode;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', [
    'apply', 'dry-run', 'website:', 'product:', 'pending-file:', 'limit:', 'offset:', 'skip-images', 'force',
]);
$apply = isset($options['apply']) && !isset($options['dry-run']);
$websiteId = max(0, (int)($options['website'] ?? 0));
$skipImages = isset($options['skip-images']);
$force = isset($options['force']);
$limit = isset($options['limit']) ? max(0, (int)$options['limit']) : 0;
$offset = isset($options['offset']) ? max(0, (int)$options['offset']) : 0;
$root = dirname(__DIR__, 5);
$mediaRoot = $root . '/pub/media';
$workRoot = '/tmp/p-suite-batch-' . date('Ymd-His');
@mkdir($workRoot, 0775, true);

$env = include $root . '/app/etc/env.php';
$db = $env['db']['master'] ?? [];
$pdo = new PDO(
    sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        (string)($db['hostname'] ?? '127.0.0.1'),
        (string)($db['hostport'] ?? '5432'),
        (string)($db['database'] ?? ''),
    ),
    (string)($db['username'] ?? ''),
    (string)($db['password'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$productIds = [];
if (isset($options['product'])) {
    $productIds[] = max(1, (int)$options['product']);
} elseif (!empty($options['pending-file']) && is_file((string)$options['pending-file'])) {
    foreach (file((string)$options['pending-file'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $pid = (int)strtok($line, "\t");
        if ($pid > 0) {
            $productIds[] = $pid;
        }
    }
} else {
    fwrite(STDERR, "Need --product=ID or --pending-file=...\n");
    exit(2);
}
if ($offset > 0) {
    $productIds = array_slice($productIds, $offset);
}
if ($limit > 0) {
    $productIds = array_slice($productIds, 0, $limit);
}

/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);
/** @var FileAssetLibraryInterface $library */
$library = ObjectManager::getInstance(FileAssetLibraryInterface::class);
$access = new FileAccessContext(ScopeIdentity::global(), 'zh_Hans_CN', null, ['catalog_maintenance'], 'metadata_edit');
$disk = StorageDiskCode::BUILTIN_LOCAL_MEDIA;

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

/** @var \Weline\Websites\Model\Website $websiteModel */
$websiteModel = ObjectManager::getInstance(\Weline\Websites\Model\Website::class)->loadById($websiteId)
    ?: ObjectManager::getInstance(\Weline\Websites\Model\Website::class)->load($websiteId);
$enabledLocales = $websiteModel ? array_values(array_filter(array_map('strval', (array)$websiteModel->getLanguageCodes()))) : [];
if ($enabledLocales === []) {
    $enabledLocales = ['zh_Hans_CN', 'en_US'];
}
$defaultLang = $websiteModel ? (string)$websiteModel->getDefaultLanguage() : 'zh_Hans_CN';
if ($defaultLang === '') {
    $defaultLang = 'zh_Hans_CN';
}
$localePlan = ['' => $defaultLang];
foreach ($enabledLocales as $code) {
    $localePlan[$code] = $code;
}

$enLeakMarkers = [
    'Design wellspring', 'Original craft', 'Worth noting', 'At a glance',
    'Close looking', 'Size guide',
    // 正文英包渗漏（bn/hi/ur 曾只译标题留下英文 look/macro）
    'Full and mid shots', 'Near views show', 'Cloth on the body',
    'Hand wash separately', 'Choose by the size axis', 'Honor the craft',
    'Named for', 'Cut and air', 'A motif only', 'Trust the photos',
    'Pick size by bust', 'As shown', 'See variant axis', 'Selected fabric',
    // id 半英渗漏
    'Full dan mid shot', 'Close-up jahitan',
];

/** pt_BR 禁止残留西语正文（es_ES 自身允许） */
$ptEsLeakMarkers = [
    'según las fotos', 'Con el nombre', 'Planos enteros y medios',
    'Primeros planos de hilo', 'no discurso de mercado', 'Elija por el eje',
    'Largo y bordado', 'Corte y aire',
];

/** 非中文启用语禁止残留的中文段题/制式部件（产品专名短标题可保留） */
$zhLeakMarkers = [
    '设计心源', '衣袂可记', '形制一览', '护衣小笺', '通身气韵', '细处可辨',
    '上衣与裙装', '以实拍为准', '尺码参照', '原创心迹',
];

$banned = ['一件代发', '混批', '厂家直销', '旺旺', '货源', '阿里巴巴', '淘宝', '天猫', '拼多多', 'dropship', 'Dropship', '上家'];

/**
 * 将制式/部件中文词译到目标 locale（产品短名专名可保留中文）。
 *
 * @return array{0:string,1:string} [style, parts]
 */
function suiteLocalizeCatalogTerms(string $locale, string $style, string $parts): array
{
    if ($locale === '' || $locale === 'zh_Hans_CN') {
        return [$style, $parts];
    }

    $styleMap = [
        'en_US' => [
            '明制' => 'Ming-style', '唐制' => 'Tang-style', '宋制' => 'Song-style',
            '魏晋制' => 'Wei-Jin style', '秦汉制' => 'Qin-Han style', '汉服' => 'Hanfu',
        ],
        'es_ES' => [
            '明制' => 'estilo Ming', '唐制' => 'estilo Tang', '宋制' => 'estilo Song',
            '魏晋制' => 'estilo Wei-Jin', '秦汉制' => 'estilo Qin-Han', '汉服' => 'Hanfu',
        ],
        'fr_FR' => [
            '明制' => 'style Ming', '唐制' => 'style Tang', '宋制' => 'style Song',
            '魏晋制' => 'style Wei-Jin', '秦汉制' => 'style Qin-Han', '汉服' => 'Hanfu',
        ],
        'pt_BR' => [
            '明制' => 'estilo Ming', '唐制' => 'estilo Tang', '宋制' => 'estilo Song',
            '魏晋制' => 'estilo Wei-Jin', '秦汉制' => 'estilo Qin-Han', '汉服' => 'Hanfu',
        ],
        'id_ID' => [
            '明制' => 'gaya Ming', '唐制' => 'gaya Tang', '宋制' => 'gaya Song',
            '魏晋制' => 'gaya Wei-Jin', '秦汉制' => 'gaya Qin-Han', '汉服' => 'Hanfu',
        ],
        'ar_SA' => [
            '明制' => 'طراز مينغ', '唐制' => 'طراز تانغ', '宋制' => 'طراز سونغ',
            '魏晋制' => 'طراز وي-جين', '秦汉制' => 'طراز تشين-هان', '汉服' => 'هانفو',
        ],
        'bn_BD' => [
            '明制' => 'মিং শৈলী', '唐制' => 'তাং শৈলী', '宋制' => 'সোং শৈলী',
            '魏晋制' => 'ওয়েই-জিন শৈলী', '秦汉制' => 'চিন-হান শৈলী', '汉服' => 'হানফু',
        ],
        'hi_IN' => [
            '明制' => 'मिंग शैली', '唐制' => 'तांग शैली', '宋制' => 'सोंग शैली',
            '魏晋制' => 'वेई-जिन शैली', '秦汉制' => 'चिन-हान शैली', '汉服' => 'हानफ़ू',
        ],
        'ur_PK' => [
            '明制' => 'منگ طرز', '唐制' => 'تانگ طرز', '宋制' => 'سونگ طرز',
            '魏晋制' => 'وی-جن طرز', '秦汉制' => 'چن-ہان طرز', '汉服' => 'ہانفو',
        ],
    ];
    $partTokenMap = [
        'en_US' => [
            '马面裙' => 'mamian skirt', '齐胸' => 'high-waist', '襦裙' => 'ruqun',
            '诃子' => 'hezi band', '大袖' => 'wide sleeves', '云肩' => 'cloud collar',
            '披帛' => 'pibo sash', '交领' => 'cross collar', '立领' => 'stand collar',
            '比甲' => 'bijia', '直裾' => 'zhiyu robe', '破裙' => 'pleated skirt',
            '上衣与裙装' => 'top and skirt',
        ],
        'es_ES' => [
            '马面裙' => 'falda mamian', '齐胸' => 'cintura alta', '襦裙' => 'ruqun',
            '诃子' => 'banda hezi', '大袖' => 'mangas anchas', '云肩' => 'cuello nube',
            '披帛' => 'banda pibo', '交领' => 'cuello cruzado', '立领' => 'cuello alto',
            '比甲' => 'bijia', '直裾' => 'túnica zhiyu', '破裙' => 'falda plisada',
            '上衣与裙装' => 'blusa y falda',
        ],
        'fr_FR' => [
            '马面裙' => 'jupe mamian', '齐胸' => 'taille haute', '襦裙' => 'ruqun',
            '诃子' => 'bandeau hezi', '大袖' => 'manches larges', '云肩' => 'col nuage',
            '披帛' => 'écharpe pibo', '交领' => 'col croisé', '立领' => 'col montant',
            '比甲' => 'bijia', '直裾' => 'robe zhiyu', '破裙' => 'jupe plissée',
            '上衣与裙装' => 'haut et jupe',
        ],
        'pt_BR' => [
            '马面裙' => 'saia mamian', '齐胸' => 'cintura alta', '襦裙' => 'ruqun',
            '诃子' => 'faixa hezi', '大袖' => 'mangas largas', '云肩' => 'colarinho nuvem',
            '披帛' => 'faixa pibo', '交领' => 'gola cruzada', '立领' => 'gola alta',
            '比甲' => 'bijia', '直裾' => 'túnica zhiyu', '破裙' => 'saia plissada',
            '上衣与裙装' => 'blusa e saia',
        ],
        'id_ID' => [
            '马面裙' => 'rok mamian', '齐胸' => 'pinggang tinggi', '襦裙' => 'ruqun',
            '诃子' => 'ikat hezi', '大袖' => 'lengan lebar', '云肩' => 'kerah awan',
            '披帛' => 'selempang pibo', '交领' => 'kerah silang', '立领' => 'kerah tegak',
            '比甲' => 'bijia', '直裾' => 'jubah zhiyu', '破裙' => 'rok lipit',
            '上衣与裙装' => 'atasan dan rok',
        ],
        'ar_SA' => [
            '马面裙' => 'تنورة ماميان', '齐胸' => 'خصر عالٍ', '襦裙' => 'روقون',
            '诃子' => 'شريط هي زي', '大袖' => 'أكمام واسعة', '云肩' => 'ياقة سحابية',
            '披帛' => 'وشاح بيبو', '交领' => 'ياقة متصالبة', '立领' => 'ياقة قائمة',
            '比甲' => 'بيجيا', '直裾' => 'رداء جِيُو', '破裙' => 'تنورة مطوية',
            '上衣与裙装' => 'قميص وتنورة',
        ],
        'bn_BD' => [
            '马面裙' => 'মামিয়ান স্কার্ট', '齐胸' => 'উচ্চ কোমর', '襦裙' => 'রুচুন',
            '诃子' => 'হেজি ব্যান্ড', '大袖' => 'প্রশস্ত হাতা', '云肩' => 'মেঘ কলার',
            '披帛' => 'পিবো সাশ', '交领' => 'ক্রস কলার', '立领' => 'স্ট্যান্ড কলার',
            '比甲' => 'বিজিয়া', '直裾' => 'ঝিয়ু পোশাক', '破裙' => 'প্লিটেড স্কার্ট',
            '上衣与裙装' => 'টপ ও স্কার্ট',
        ],
        'hi_IN' => [
            '马面裙' => 'मामियन स्कर्ट', '齐胸' => 'ऊँची कमर', '襦裙' => 'रुचुन',
            '诃子' => 'हेज़ी बैंड', '大袖' => 'चौड़ी आस्तीन', '云肩' => 'क्लाउड कॉलर',
            '披帛' => 'पीबो सैश', '交领' => 'क्रॉस कॉलर', '立领' => 'स्टैंड कॉलर',
            '比甲' => 'बिजिया', '直裾' => 'झियू पोशाक', '破裙' => 'प्लीटेड स्कर्ट',
            '上衣与裙装' => 'टॉप और स्कर्ट',
        ],
        'ur_PK' => [
            '马面裙' => 'مامیان اسکرٹ', '齐胸' => 'اونچی کمر', '襦裙' => 'روچون',
            '诃子' => 'ہیزی بینڈ', '大袖' => 'چوڑی آستین', '云肩' => 'کلاؤڈ کالر',
            '披帛' => 'پیبو سیاش', '交领' => 'کراس کالر', '立领' => 'اسٹینڈ کالر',
            '比甲' => 'بیجیا', '直裾' => 'جییو پوشاک', '破裙' => 'پلیٹڈ اسکرٹ',
            '上衣与裙装' => 'ٹاپ اور اسکرٹ',
        ],
    ];

    $fallback = 'en_US';
    $sMap = $styleMap[$locale] ?? $styleMap[$fallback];
    $pMap = $partTokenMap[$locale] ?? $partTokenMap[$fallback];
    $outStyle = $sMap[$style] ?? ($styleMap[$fallback][$style] ?? $style);
    $outParts = $parts;
    // longer tokens first
    $tokens = array_keys($pMap);
    usort($tokens, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
    foreach ($tokens as $tok) {
        if (str_contains($outParts, $tok)) {
            $outParts = str_replace($tok, $pMap[$tok], $outParts);
        }
    }
    // 中文顿号/逗号 → 目标语分隔
    $sep = match ($locale) {
        'ar_SA', 'ur_PK' => '، ',
        'zh_Hans_CN', 'zh_Hant_TW' => '、',
        default => ', ',
    };
    $outParts = str_replace(['、', '，'], $sep, $outParts);

    return [$outStyle, $outParts];
}

/**
 * @param array<string, mixed> $pack
 * @return array<string, mixed>
 */
function suiteApplyLocalizedStyleParts(array $pack, string $locale, string $styleZh, string $partsZh): array
{
    [$style, $parts] = suiteLocalizeCatalogTerms($locale, $styleZh, $partsZh);
    $pack['info_style'] = $style;
    $pack['info_parts'] = $parts;
    foreach (['intro_body', 'close_caption', 'alt_hero', 'alt_look', 'alt_macro'] as $k) {
        if (isset($pack[$k]) && is_string($pack[$k])) {
            $pack[$k] = str_replace([$partsZh, $styleZh], [$parts, $style], $pack[$k]);
        }
    }
    if (isset($pack['checklist']) && is_array($pack['checklist'])) {
        foreach ($pack['checklist'] as $i => $item) {
            if (is_string($item)) {
                $pack['checklist'][$i] = str_replace([$partsZh, $styleZh], [$parts, $style], $item);
            }
        }
    }
    if (isset($pack['inspire_lines']) && is_array($pack['inspire_lines'])) {
        foreach ($pack['inspire_lines'] as $i => $line) {
            if (is_string($line)) {
                $pack['inspire_lines'][$i] = str_replace([$partsZh, $styleZh], [$parts, $style], $line);
            }
        }
    }

    return $pack;
}

/**
 * @return array{name:string,short:string,style:string,parts:string}
 */
function suiteExtractMeta(PDO $pdo, int $productId): array
{
    $st = $pdo->prepare("SELECT value_string FROM w_product_ws_0_attribute_value WHERE entity_id=? AND attribute_code='name' AND locale='zh_Hans_CN' ORDER BY length(COALESCE(value_string,'')) DESC LIMIT 1");
    $st->execute([$productId]);
    $name = trim((string)($st->fetchColumn() ?: ''));
    if ($name === '') {
        $st = $pdo->prepare("SELECT value_string FROM w_product_ws_0_attribute_value WHERE entity_id=? AND attribute_code='name' ORDER BY length(COALESCE(value_string,'')) DESC LIMIT 1");
        $st->execute([$productId]);
        $name = trim((string)($st->fetchColumn() ?: ('商品' . $productId)));
    }
    $short = $name;
    if (preg_match('/【([^】]+)】/u', $name, $m)) {
        $short = trim($m[1]);
    } elseif (preg_match('/\\[([^\\]]+)\\]/u', $name, $m)) {
        $short = trim($m[1]);
    } else {
        $short = mb_substr(preg_replace('/^(花朝记|悦雅霓裳|汉唐华韵|原创)/u', '', $name) ?? $name, 0, 12);
    }
    $style = '汉服';
    if (str_contains($name, '明制')) {
        $style = '明制';
    } elseif (str_contains($name, '唐制') || str_contains($name, '春唐') || str_contains($name, '盛唐')) {
        $style = '唐制';
    } elseif (str_contains($name, '宋制')) {
        $style = '宋制';
    } elseif (str_contains($name, '魏晋') || str_contains($name, '晋制')) {
        $style = '魏晋制';
    } elseif (str_contains($name, '秦汉') || str_contains($name, '楚')) {
        $style = '秦汉制';
    }
    $parts = [];
    foreach (['马面裙', '齐胸', '襦裙', '诃子', '大袖', '云肩', '披帛', '交领', '立领', '比甲', '直裾', '破裙'] as $p) {
        if (str_contains($name, $p)) {
            $parts[] = $p;
        }
    }
    if ($parts === []) {
        $parts[] = '上衣与裙装';
    }

    return ['name' => $name, 'short' => $short !== '' ? $short : ('商品' . $productId), 'style' => $style, 'parts' => implode('、', array_slice($parts, 0, 4))];
}

function suiteHasWeds(PDO $pdo, int $productId): bool
{
    $st = $pdo->prepare("SELECT 1 FROM w_product_ws_0_attribute_value WHERE entity_id=? AND attribute_code='description' AND (value_text ILIKE '%data-weds=\"xq\"%' OR value_text ILIKE '%<!--weds:xq-->%' OR value_text ILIKE '%data-weline-detail-suite=%') LIMIT 1");
    $st->execute([$productId]);

    return (bool)$st->fetchColumn();
}

/**
 * @return list<array{id:string,role:string,object_key:string,w:int,h:int,path:string}>
 */
function suiteCollectAssets(PDO $pdo, int $productId, string $mediaRoot): array
{
    $out = [];
    $seen = [];
    $st = $pdo->prepare("SELECT role, position, asset_id::text AS asset_id FROM w_product_ws_0_media WHERE product_id=? AND COALESCE(hidden,0)=0 ORDER BY CASE role WHEN 'main' THEN 0 WHEN 'gallery' THEN 1 WHEN 'variant' THEN 2 ELSE 3 END, position, media_id");
    $st->execute([$productId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = strtolower(trim((string)$row['asset_id']));
        if ($id === '' || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $out[] = ['id' => $id, 'role' => (string)$row['role'], 'object_key' => '', 'w' => 0, 'h' => 0, 'path' => ''];
    }
    $st = $pdo->prepare("SELECT value_text FROM w_product_ws_0_attribute_value WHERE entity_id=? AND attribute_code='description' ORDER BY length(COALESCE(value_text,'')) DESC LIMIT 1");
    $st->execute([$productId]);
    $html = (string)($st->fetchColumn() ?: '');
    if (preg_match_all('/asset:\\/\\/([a-f0-9-]{36})/i', $html, $m)) {
        foreach ($m[1] as $id) {
            $id = strtolower($id);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = ['id' => $id, 'role' => 'detail', 'object_key' => '', 'w' => 0, 'h' => 0, 'path' => ''];
        }
    }
    if ($out === []) {
        return [];
    }
    $ids = array_column($out, 'id');
    $in = implode(',', array_map(static fn(string $id): string => $pdo->quote($id), $ids));
    $meta = [];
    foreach ($pdo->query("SELECT asset_id::text AS id, object_key, COALESCE(width,0) AS w, COALESCE(height,0) AS h FROM w_weline_file_asset WHERE asset_id IN ({$in})") as $row) {
        $meta[strtolower((string)$row['id'])] = $row;
    }
    foreach ($out as &$a) {
        $mrow = $meta[$a['id']] ?? null;
        if (!$mrow) {
            continue;
        }
        $a['object_key'] = (string)$mrow['object_key'];
        $a['w'] = (int)$mrow['w'];
        $a['h'] = (int)$mrow['h'];
        $path = $mediaRoot . '/' . ltrim($a['object_key'], '/');
        $a['path'] = is_file($path) ? $path : '';
        if ($a['path'] !== '' && ($a['w'] < 1 || $a['h'] < 1)) {
            $size = @getimagesize($a['path']);
            if (is_array($size)) {
                $a['w'] = (int)$size[0];
                $a['h'] = (int)$size[1];
            }
        }
    }
    unset($a);

    return array_values(array_filter($out, static fn(array $a): bool => $a['path'] !== '' && $a['w'] > 0 && $a['h'] > 0));
}

/**
 * Heuristic: drop collage / caption boards / poem-sidebar (left pale column + photo).
 *
 * @param list<array{id:string,role:string,object_key:string,w:int,h:int,path:string}> $assets
 * @return list<array{id:string,role:string,object_key:string,w:int,h:int,path:string}>
 */
function suiteFilterDetailCandidates(array $assets): array
{
    // Known baked-text / poem-collage assets (force drop even if heuristic soft).
    $forceDrop = [
        '47601ee4-cb1f-445e-8ea9-32ab7b30c2ea' => 'text_board_design_inspire', // #394 detail-02 设计灵感
        '53c7dddb-1ec6-4e58-879f-9be2454ff487' => 'hanfu_poem_overlay', // #394 detail-01
        'd4864a71-8999-4c08-b138-099c3773a49d' => 'color_showcase_board', // #394 detail-04 颜色展示
        // #117 思无邪 detail-01：上半实拍+中缝「灵感」蓝板+下半切头拼版（用户已验左栏裁切）
        '42204c3a-86f2-40d4-ad56-e34fa1a43a15' => 'inspire_collage_siwuxie',
    ];
    $keep = [];
    foreach ($assets as $a) {
        $id = strtolower((string)$a['id']);
        if (isset($forceDrop[$id])) {
            echo "drop force {$forceDrop[$id]} {$a['role']} {$id}\n";
            continue;
        }
        // Caption / text boards may also appear as gallery misfiles; drop by content.
        $path = (string)$a['path'];
        $w = (int)$a['w'];
        $h = (int)$a['h'];
        if ($path !== '' && $w > 0 && $h > 0) {
            if (suiteLooksLikeCaptionTextBoard($path, $w, $h)) {
                echo "drop caption/text_board {$a['role']} {$a['id']}\n";
                continue;
            }
            if (suiteLooksLikePoemSidebarCollage($path, $w, $h)) {
                echo "drop poem_sidebar {$a['role']} {$a['id']}\n";
                continue;
            }
            if (suiteLooksLikeHanfuPoemOverlay($path, $w, $h)) {
                echo "drop hanfu_poem_overlay {$a['role']} {$a['id']}\n";
                continue;
            }
        }
        if ($a['role'] !== 'detail') {
            $keep[] = $a;
            continue;
        }
        $ar = $w / max(1, $h);
        // ultra-wide thin strips or extreme collage boards
        if ($ar > 2.2 || $ar < 0.35) {
            continue;
        }
        if (min($w, $h) < 200) {
            continue;
        }
        $keep[] = $a;
    }

    return $keep;
}

/**
 * §3.2 / 信息烤图·字板：「设计灵感」「颜色展示」宣纸底烤字拼版 → 删图 textify。
 * 启发式：大面积浅纸底 + 中区更暗（圆裁/双图）或上半边缘密度偏高。
 */
function suiteLooksLikeCaptionTextBoard(string $path, int $w, int $h): bool
{
    if ($path === '' || !is_file($path) || $w < 240 || $h < 240) {
        return false;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return false;
    }
    $im = @imagecreatefromstring($raw);
    if ($im === false) {
        return false;
    }
    $stepY = max(1, (int)($h / 48));
    $stepX = max(1, (int)($w / 48));
    $lightN = $n = 0;
    $edgeN = $edgeHot = 0;
    $topLight = $topN = 0;
    $midSum = $midN = 0.0;
    $topEnd = (int)($h * 0.28);
    $midY0 = (int)($h * 0.35);
    $midY1 = (int)($h * 0.72);
    $midX0 = (int)($w * 0.28);
    $midX1 = (int)($w * 0.72);
    for ($y = 0; $y < $h; $y += $stepY) {
        for ($x = 0; $x < $w; $x += $stepX) {
            $rgb = imagecolorat($im, $x, $y);
            $l = ((($rgb >> 16) & 255) + (($rgb >> 8) & 255) + ($rgb & 255)) / 3.0;
            $n++;
            if ($l > 205.0) {
                $lightN++;
            }
            if ($y < $topEnd) {
                $topN++;
                if ($l > 205.0) {
                    $topLight++;
                }
                // text-ish: local contrast vs neighbor
                if ($x + $stepX < $w) {
                    $rgb2 = imagecolorat($im, $x + $stepX, $y);
                    $l2 = ((($rgb2 >> 16) & 255) + (($rgb2 >> 8) & 255) + ($rgb2 & 255)) / 3.0;
                    $edgeN++;
                    if (abs($l - $l2) > 45.0 && $l > 40.0 && $l2 > 40.0) {
                        $edgeHot++;
                    }
                }
            }
            if ($y >= $midY0 && $y <= $midY1 && $x >= $midX0 && $x <= $midX1) {
                $midSum += $l;
                $midN++;
            }
        }
    }
    imagedestroy($im);
    if ($n < 40) {
        return false;
    }
    $lightRatio = $lightN / $n;
    $topLightRatio = $topN > 0 ? $topLight / $topN : 0.0;
    $edgeRatio = $edgeN > 0 ? $edgeHot / $edgeN : 0.0;
    $midAvg = $midN > 0 ? $midSum / $midN : 0.0;
    // Exclude bright cutout products on white (subject also light in mid).
    if ($midAvg >= 175.0 && $lightRatio >= 0.40 && $edgeRatio < 0.08) {
        return false;
    }
    // Paper board with darker photo inset / dual photos.
    if ($lightRatio >= 0.38 && $topLightRatio >= 0.45 && ($midAvg + 18.0) < 200.0 && $edgeRatio >= 0.035) {
        return true;
    }
    // Tall poster boards with dominant paper field.
    if ($h > $w * 1.15 && $lightRatio >= 0.45 && $topLightRatio >= 0.50 && $edgeRatio >= 0.03) {
        return true;
    }
    // Dual-panel「颜色展示」boards: moderate paper + mid not as bright as top strip.
    if ($lightRatio >= 0.30 && $topLightRatio >= 0.55 && $edgeRatio >= 0.05 && $midAvg < ($topLightRatio * 180.0)) {
        return true;
    }
    // 「灵感」中缝蓝板竖拼：中下带偏冷蓝且与上半肤色/棚景色差大（#117 已验）
    if (suiteLooksLikeInspireBlueBannerCollage($path, $w, $h)) {
        return true;
    }

    return false;
}

/**
 * 竖拼「上半实拍 + 中段灵感蓝板 ± 下半切头」货盘长图。
 */
function suiteLooksLikeInspireBlueBannerCollage(string $path, int $w, int $h): bool
{
    if ($path === '' || !is_file($path) || $w < 280 || $h < 480 || $h < (int)($w * 1.2)) {
        return false;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return false;
    }
    $im = @imagecreatefromstring($raw);
    if ($im === false) {
        return false;
    }
    $stepX = max(1, (int)($w / 36));
    $bands = [
        'top' => (int)($h * 0.18),
        'mid' => (int)($h * 0.52),
        'bot' => (int)($h * 0.78),
    ];
    $avg = [];
    foreach ($bands as $name => $y) {
        $r = $g = $b = $n = 0;
        for ($x = 0; $x < $w; $x += $stepX) {
            $rgb = imagecolorat($im, $x, min($h - 1, $y));
            $r += ($rgb >> 16) & 255;
            $g += ($rgb >> 8) & 255;
            $b += $rgb & 255;
            $n++;
        }
        $avg[$name] = $n > 0
            ? ['r' => $r / $n, 'g' => $g / $n, 'b' => $b / $n]
            : ['r' => 0.0, 'g' => 0.0, 'b' => 0.0];
    }
    imagedestroy($im);
    $mid = $avg['mid'];
    $bot = $avg['bot'];
    $top = $avg['top'];
    $midCool = ($mid['b'] - $mid['r']) > 12.0 && $mid['b'] > 150.0 && $mid['g'] > 140.0;
    $botCool = ($bot['b'] - $bot['r']) > 8.0 && $bot['b'] > 140.0;
    $topWarmish = $top['r'] > 160.0 && ($top['r'] + $top['g']) / 2.0 > $top['b'] + 8.0;
    // 中带偏蓝 + 上半偏暖棚景 = 灵感蓝板拼版
    if ($topWarmish && ($midCool || $botCool) && abs($mid['b'] - $top['b']) > 18.0) {
        return true;
    }

    return false;
}

/** Dark studio + left white「汉服」竖排烤字 / 诗句叠图（§3.2‑B 变体）. */
function suiteLooksLikeHanfuPoemOverlay(string $path, int $w, int $h): bool
{
    if ($path === '' || !is_file($path) || $w < 240 || $h < 240) {
        return false;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return false;
    }
    $im = @imagecreatefromstring($raw);
    if ($im === false) {
        return false;
    }
    $stepY = max(1, (int)($h / 40));
    $stepX = max(1, (int)($w / 60));
    $leftCut = (int)($w * 0.22);
    $leftSum = $leftN = 0.0;
    $leftBright = 0;
    $midSum = $midN = 0.0;
    $midStart = (int)($w * 0.40);
    for ($y = 0; $y < $h; $y += $stepY) {
        for ($x = 0; $x < $leftCut; $x += $stepX) {
            $rgb = imagecolorat($im, $x, $y);
            $l = ((($rgb >> 16) & 255) + (($rgb >> 8) & 255) + ($rgb & 255)) / 3.0;
            $leftSum += $l;
            $leftN++;
            if ($l > 200.0) {
                $leftBright++;
            }
        }
        for ($x = $midStart; $x < $w; $x += $stepX) {
            $rgb = imagecolorat($im, $x, $y);
            $l = ((($rgb >> 16) & 255) + (($rgb >> 8) & 255) + ($rgb & 255)) / 3.0;
            $midSum += $l;
            $midN++;
        }
    }
    imagedestroy($im);
    if ($leftN < 10 || $midN < 10) {
        return false;
    }
    $leftAvg = $leftSum / $leftN;
    $midAvg = $midSum / $midN;
    $brightRatio = $leftBright / $leftN;
    // Dark left field with scattered bright glyphs + mid not paper-white.
    if ($leftAvg < 100.0 && $brightRatio >= 0.03 && $brightRatio <= 0.50 && $midAvg < 170.0 && ($midAvg - $leftAvg) > 20.0) {
        return true;
    }

    return false;
}

/** Left (or right) near-uniform pale column vs photo — §3.2‑B poem_sidebar_collage. */
function suiteLooksLikePoemSidebarCollage(string $path, int $w, int $h): bool
{
    if ($path === '' || !is_file($path) || $w < 200 || $h < 200) {
        return false;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return false;
    }
    $im = @imagecreatefromstring($raw);
    if ($im === false) {
        return false;
    }
    $sideFrac = 0.18;
    $stepY = max(1, (int)($h / 40));
    $stepX = max(1, (int)($w / 80));
    $leftSum = $rightSum = $midSum = 0.0;
    $leftN = $rightN = $midN = 0;
    $leftCut = (int)($w * $sideFrac);
    $rightStart = (int)($w * (1.0 - $sideFrac));
    $midStart = (int)($w * 0.40);
    for ($y = 0; $y < $h; $y += $stepY) {
        for ($x = 0; $x < $leftCut; $x += $stepX) {
            $rgb = imagecolorat($im, $x, $y);
            $leftSum += ((($rgb >> 16) & 255) + (($rgb >> 8) & 255) + ($rgb & 255)) / 3.0;
            $leftN++;
        }
        for ($x = $rightStart; $x < $w; $x += $stepX) {
            $rgb = imagecolorat($im, $x, $y);
            $rightSum += ((($rgb >> 16) & 255) + (($rgb >> 8) & 255) + ($rgb & 255)) / 3.0;
            $rightN++;
        }
        for ($x = $midStart; $x < $w; $x += $stepX) {
            $rgb = imagecolorat($im, $x, $y);
            $midSum += ((($rgb >> 16) & 255) + (($rgb >> 8) & 255) + ($rgb & 255)) / 3.0;
            $midN++;
        }
    }
    imagedestroy($im);
    if ($leftN < 10 || $midN < 10) {
        return false;
    }
    $leftAvg = $leftSum / $leftN;
    $rightAvg = $rightN > 0 ? $rightSum / $rightN : 0.0;
    $midAvg = $midSum / $midN;
    // Left cream column + darker photo mid, or right cream column (rarer).
    if ($leftAvg > 210.0 && ($leftAvg - $midAvg) > 40.0) {
        return true;
    }
    if ($rightAvg > 210.0 && ($rightAvg - $midAvg) > 40.0) {
        return true;
    }

    return false;
}

/**
 * Crop near-white / low-variance pillar/letterbox; write JPEG; return new w/h/path.
 *
 * @return array{path:string,w:int,h:int,cropped:bool}|null
 */
function suiteCropPadImage(string $srcPath, string $destPath): ?array
{
    $cmd = [
        'python3', '-c',
        <<<'PY'
import sys
from pathlib import Path
import numpy as np
from PIL import Image
src, dest = sys.argv[1], sys.argv[2]
im = Image.open(src).convert('RGB')
a = np.asarray(im).astype(np.float32)
h, w, _ = a.shape

def edge_cols(axis_len, take_ratio=0.12):
    return max(1, int(axis_len * take_ratio))

def is_pad_col(col):
    mean = float(col.mean())
    var = float(col.reshape(-1, 3).var(axis=0).mean())
    return (var < 60 and (mean > 235 or mean < 28)) or (var < 25 and 150 <= mean <= 220)

left = 0
right = w
bw = edge_cols(w)
for x in range(0, bw):
    if is_pad_col(a[:, x]):
        left = x + 1
    else:
        break
for x in range(w - 1, w - 1 - bw, -1):
    if is_pad_col(a[:, x]):
        right = x
    else:
        break
top = 0
bot = h
bh = edge_cols(h)
for y in range(0, bh):
    if is_pad_col(a[y, :]):
        top = y + 1
    else:
        break
for y in range(h - 1, h - 1 - bh, -1):
    if is_pad_col(a[y, :]):
        bot = y
    else:
        break
left = min(left, w - 8); right = max(right, left + 8)
top = min(top, h - 8); bot = max(bot, top + 8)
cropped = left > 2 or right < w - 2 or top > 2 or bot < h - 2
if cropped:
    im = im.crop((left, top, right, bot))
# mild upscale only if short edge small AND laplacian ok
arr = np.asarray(im).astype(np.float32)
hh, ww, _ = arr.shape
mid = arr[hh//5:4*hh//5, ww//5:4*ww//5].mean(2)
lap = float(np.abs(mid[1:-1,1:-1]*4 - mid[:-2,1:-1] - mid[2:,1:-1] - mid[1:-1,:-2] - mid[1:-1,2:]).mean()) if mid.size > 100 else 0.0
short = min(ww, hh)
if lap >= 120 and short < 1200:
    scale = min(1200 / short, 1.6)
    nw, nh = int(ww * scale), int(hh * scale)
    im = im.resize((nw, nh), Image.Resampling.LANCZOS)
elif lap < 80:
    # soft: do not empty-upscale
    pass
Path(dest).parent.mkdir(parents=True, exist_ok=True)
im.save(dest, quality=90, optimize=True)
print(f"{im.size[0]} {im.size[1]} {int(cropped)} {lap:.1f}")
PY
        ,
        $srcPath,
        $destPath,
    ];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null);
    if (!is_resource($proc)) {
        return null;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0 || !is_file($destPath)) {
        fwrite(STDERR, "crop fail {$srcPath}: {$stderr}\n");

        return null;
    }
    $parts = preg_split('/\s+/', trim((string)$stdout)) ?: [];
    if (count($parts) < 3) {
        return null;
    }

    return [
        'path' => $destPath,
        'w' => (int)$parts[0],
        'h' => (int)$parts[1],
        'cropped' => ((int)$parts[2]) === 1,
    ];
}

/**
 * @param list<array{id:string,role:string,object_key:string,w:int,h:int,path:string}> $assets
 * @return list<array{id:string,role:string,object_key:string,w:int,h:int,path:string,replaced?:bool}>
 */
function suiteRemediateImages(
    array $assets,
    string $workDir,
    string $mediaRoot,
    FileAssetLibraryInterface $library,
    FileAccessContext $access,
    string $disk,
    bool $apply,
    bool $skipImages,
): array {
    if ($skipImages) {
        return $assets;
    }
    $bakDir = $mediaRoot . '/_suite_bak_' . date('Ymd_His');
    $out = [];
    foreach ($assets as $a) {
        $dest = $workDir . '/' . $a['id'] . '.jpg';
        $res = suiteCropPadImage($a['path'], $dest);
        if ($res === null) {
            $out[] = $a;
            continue;
        }
        $a['w'] = $res['w'];
        $a['h'] = $res['h'];
        $a['path'] = $res['path'];
        $a['replaced'] = false;
        if ($apply && $a['object_key'] !== '' && is_file($res['path'])) {
            $bakPath = $bakDir . '/' . basename($a['object_key']);
            if (!is_dir($bakDir)) {
                @mkdir($bakDir, 0775, true);
            }
            if (!is_file($bakPath)) {
                @copy($mediaRoot . '/' . ltrim($a['object_key'], '/'), $bakPath);
            }
            $stream = fopen($res['path'], 'rb');
            if (is_resource($stream)) {
                try {
                    $desc = $library->replaceContent(
                        $disk,
                        $a['object_key'],
                        $stream,
                        basename($a['object_key']),
                        'image/jpeg',
                        'zh_Hans_CN',
                        $access,
                        $a['w'],
                        $a['h'],
                    );
                    $aid = (string)($desc['asset_id'] ?? '');
                    if ($aid !== '' && strtolower($aid) !== $a['id']) {
                        fwrite(STDERR, "id drift {$a['id']} -> {$aid}\n");
                    } else {
                        $a['replaced'] = true;
                    }
                } finally {
                    fclose($stream);
                }
            }
        }
        $out[] = $a;
    }

    return $out;
}

/**
 * @param array{name:string,short:string,style:string,parts:string} $meta
 * @param list<array{id:string,role:string,w:int,h:int}> $imgs
 * @return array<string, array<string, mixed>>
 */
function suiteBuildCopyPacks(array $meta, array $imgs): array
{
    $short = $meta['short'];
    $style = $meta['style'];
    $parts = $meta['parts'];
    // Product-specific inspire (textify from 设计灵感 boards; never leave baked JPG).
    $inspireLinesZh = ["以「{$short}」为题，写形制与气韵。", '衣袂有序，不袭货盘腔调。'];
    $inspireNoteZh = '题眼仅为点题，非平台说辞。';
    $poemLinesZh = [];
    $poemTitleZh = '诗意旁笺';
    $nameBlob = $meta['name'] . $short;
    if (str_contains($nameBlob, '秋实')) {
        $inspireLinesZh = [
            '整套以枫叶为主要元素，素洁幽雅，超凡脱俗。',
            '中式立领搭配刺绣云肩，裙幅纹样铺展。',
            '温柔精致，不失少女灵动。',
        ];
        $inspireNoteZh = '枫叶题眼取自形制，非货盘口号。';
        $poemLinesZh = ['中庭多杂树，偏为梅咨嗟。', '念其霜中能作花，露中能作实。'];
    } elseif (str_contains($nameBlob, '沐秋')) {
        $inspireLinesZh = [
            '沐秋取意秋光轻洗，云肩与马面层叠可读。',
            '立领清隽，绣花疏落，日常亦能见仪度。',
            '色调温润，不袭货盘腔调。',
        ];
        $inspireNoteZh = '题眼仅为点题，以实拍绣纹为准。';
    }

    $zh = [
        'intro_title' => $short,
        'intro_body' => "{$style}形制：{$parts}。衣长与绣纹以实拍为准，细节可近观。",
        'inspire_title' => '设计心源',
        'inspire_lines' => $inspireLinesZh,
        'inspire_note' => $inspireNoteZh,
        'poem_title' => $poemTitleZh,
        'poem_lines' => $poemLinesZh,
        'look_title' => '通身气韵',
        'look_body' => '全身与半身实拍交叉铺陈，裙幅、袖袂与领缘层次可读。',
        'macro_title' => '细处可辨',
        'macro_body' => '近景可见绣线、褶影与面料质感；以图为证，不编造参数。',
        'quiet_line' => '衣在身上，韵在步间。',
        'checklist_title' => '衣袂可记',
        'checklist' => ["制式：{$style}", "部件：{$parts}", '以实拍细节为准', '尺码请按胸围身高挑选'],
        'wash_title' => '护衣小笺',
        'wash_lines' => ['建议手洗，分色洗涤，不可漂白。', '悬挂晾干，避暴晒；低温熨烫，垫布为佳。'],
        'info_brand' => (str_contains($meta['name'], '花朝记') ? '花朝记' : (str_contains($meta['name'], '悦雅霓裳') ? '悦雅霓裳' : (str_contains($meta['name'], '汉唐华韵') ? '汉唐华韵' : '本店'))),
        'info_name' => $short,
        'info_color' => '如图',
        'info_style' => $style,
        'info_size' => '见规格轴',
        'info_fabric' => '精选面料（如图）',
        'info_parts' => $parts,
        'info_title' => '形制一览',
        'info_basics' => '基本',
        'info_comfort' => '穿着感受',
        'label_brand' => '品牌',
        'label_name' => '品名',
        'label_color' => '颜色',
        'label_style' => '制式',
        'label_size' => '尺码',
        'label_fabric' => '面料',
        'label_parts' => '部件',
        'size_title' => '尺码参照',
        'size_body' => '请按规格轴尺码与自身胸围、身高挑选；手工测量或有一至三厘米出入，以实物为准。',
        'original_title' => '原创心迹',
        'original_body' => '敬请珍惜衣冠、尊重匠心；图中纹样与形制以本店实拍为准。',
        'close_caption' => $short . ' · ' . $style,
        'alt_hero' => $short . ' · 套装',
        'alt_look' => $short . ' · 着装',
        'alt_macro' => $short . ' · 细部',
        'c_thick' => '厚度',
        'c_thick_opts' => ['薄', '适中', '厚'],
        'c_thick_sel' => '适中',
        'c_fit' => '版型',
        'c_fit_opts' => ['修身', '合身', '宽松'],
        'c_fit_sel' => '合身',
        'c_soft' => '手感',
        'c_soft_opts' => ['偏软', '适中', '偏硬'],
        'c_soft_sel' => '适中',
        'c_stretch' => '弹性',
        'c_stretch_opts' => ['无弹', '微弹', '高弹'],
        'c_stretch_sel' => '无弹',
    ];

    $enInspire = [
        'inspire_title' => 'Design wellspring',
        'inspire_lines' => ["Named for “{$short}”.", 'Cut and air—not marketplace pitch.'],
        'inspire_note' => 'A motif only, not platform copy.',
        'poem_title' => 'Verse aside',
        'poem_lines' => [],
    ];
    if (str_contains($nameBlob, '秋实')) {
        $enInspire = [
            'inspire_title' => 'Design wellspring',
            'inspire_lines' => [
                'Maple leaves lead the set—pure, quiet, and refined.',
                'Stand collar with embroidered cloud shoulders; patterns spill down the skirt.',
                'Gentle and precise, still lively like youth.',
            ],
            'inspire_note' => 'Maple motif from the cut—not marketplace slogans.',
            'poem_title' => 'Verse aside',
            'poem_lines' => ['Many trees fill the court; I sigh only for the plum.', 'It blooms in frost and fruits in dew.'],
        ];
    } elseif (str_contains($nameBlob, '沐秋')) {
        $enInspire = [
            'inspire_title' => 'Design wellspring',
            'inspire_lines' => [
                'Muqiu—autumn light washing the cloud collar and mamian layers.',
                'A clear stand collar, sparse embroidery, poised for daily wear.',
                'Warm tones without marketplace pitch.',
            ],
            'inspire_note' => 'Motif only; trust the photographed embroidery.',
            'poem_title' => 'Verse aside',
            'poem_lines' => [],
        ];
    }

    $en = [
        'intro_title' => $short,
        'intro_body' => "{$style} cut: {$parts}. Length and embroidery follow the photos.",
        'inspire_title' => $enInspire['inspire_title'],
        'inspire_lines' => $enInspire['inspire_lines'],
        'inspire_note' => $enInspire['inspire_note'],
        'poem_title' => $enInspire['poem_title'],
        'poem_lines' => $enInspire['poem_lines'],
        'look_title' => 'Full look',
        'look_body' => 'Full and mid shots stack so hem, sleeves, and collar layers stay readable.',
        'macro_title' => 'Close looking',
        'macro_body' => 'Near views show stitch, pleat, and fabric—evidence only, no invented specs.',
        'quiet_line' => 'Cloth on the body; air in the step.',
        'checklist_title' => 'Worth noting',
        'checklist' => ["Style: {$style}", "Parts: {$parts}", 'Trust the photos', 'Pick size by bust and height'],
        'wash_title' => 'Care',
        'wash_lines' => ['Hand wash separately; no bleach.', 'Hang dry; low heat with a press cloth.'],
        'info_brand' => $zh['info_brand'],
        'info_name' => $short,
        'info_color' => 'As shown',
        'info_style' => $style,
        'info_size' => 'See variant axis',
        'info_fabric' => 'Selected fabric (as shown)',
        'info_parts' => $parts,
        'info_title' => 'At a glance',
        'info_basics' => 'Basics',
        'info_comfort' => 'Hand feel',
        'label_brand' => 'Brand',
        'label_name' => 'Name',
        'label_color' => 'Color',
        'label_style' => 'Style',
        'label_size' => 'Size',
        'label_fabric' => 'Fabric',
        'label_parts' => 'Parts',
        'size_title' => 'Size guide',
        'size_body' => 'Choose by the size axis plus bust and height; hand measure may vary 1–3 cm.',
        'original_title' => 'Original craft',
        'original_body' => 'Honor the craft; motifs follow our photos.',
        'close_caption' => $short . ' · ' . $style,
        'alt_hero' => $short . ' · set',
        'alt_look' => $short . ' · worn',
        'alt_macro' => $short . ' · detail',
        'c_thick' => 'Thickness',
        'c_thick_opts' => ['Thin', 'Medium', 'Thick'],
        'c_thick_sel' => 'Medium',
        'c_fit' => 'Fit',
        'c_fit_opts' => ['Slim', 'Regular', 'Relaxed'],
        'c_fit_sel' => 'Regular',
        'c_soft' => 'Hand',
        'c_soft_opts' => ['Softer', 'Medium', 'Firmer'],
        'c_soft_sel' => 'Medium',
        'c_stretch' => 'Stretch',
        'c_stretch_opts' => ['None', 'Slight', 'High'],
        'c_stretch_sel' => 'None',
    ];

    // True-ish packs for other locales (structure mirrored; not EN dump of checklist titles).
    $es = $en;
    $es['inspire_title'] = 'Fuente del diseño';
    $es['look_title'] = 'Silueta';
    $es['macro_title'] = 'De cerca';
    $es['quiet_line'] = 'Tela en el cuerpo; aire en el paso.';
    $es['checklist_title'] = 'Para recordar';
    $es['wash_title'] = 'Cuidado';
    $es['info_title'] = 'De un vistazo';
    $es['info_basics'] = 'Básico';
    $es['info_comfort'] = 'Tacto';
    $es['label_brand'] = 'Marca';
    $es['label_name'] = 'Nombre';
    $es['label_color'] = 'Color';
    $es['label_style'] = 'Estilo';
    $es['label_size'] = 'Talla';
    $es['label_fabric'] = 'Tela';
    $es['label_parts'] = 'Partes';
    $es['size_title'] = 'Guía de tallas';
    $es['size_body'] = 'Elija por el eje de tallas, busto y altura; medida manual ±1–3 cm.';
    $es['original_title'] = 'Oficio original';
    $es['original_body'] = 'Honre el oficio; los motivos siguen nuestras fotos.';
    $es['intro_body'] = "Corte {$style}: {$parts}. Largo y bordado según las fotos.";
    $es['inspire_lines'] = ["Con el nombre «{$short}».", 'Corte y aire—no discurso de mercado.'];
    $es['inspire_note'] = 'Solo un motivo, no copia de plataforma.';
    $es['look_body'] = 'Planos enteros y medios para leer bajo, mangas y cuello.';
    $es['macro_body'] = 'Primeros planos de hilo, pliegue y tela—sin inventar datos.';
    $es['checklist'] = ["Estilo: {$style}", "Partes: {$parts}", 'Confíe en las fotos', 'Elija talla por busto y altura'];
    $es['wash_lines'] = ['Lavar a mano por separado; sin lejía.', 'Secar colgado; plancha baja con paño.'];
    $es['info_color'] = 'Como en foto';
    $es['info_size'] = 'Ver eje de variantes';
    $es['info_fabric'] = 'Tela seleccionada (como en foto)';
    $es['c_thick'] = 'Grosor';
    $es['c_thick_opts'] = ['Fino', 'Medio', 'Grueso'];
    $es['c_thick_sel'] = 'Medio';
    $es['c_fit'] = 'Corte';
    $es['c_fit_opts'] = ['Ajustado', 'Regular', 'Holgado'];
    $es['c_fit_sel'] = 'Regular';
    $es['c_soft'] = 'Tacto';
    $es['c_soft_opts'] = ['Más suave', 'Medio', 'Más firme'];
    $es['c_soft_sel'] = 'Medio';
    $es['c_stretch'] = 'Elasticidad';
    $es['c_stretch_opts'] = ['Nula', 'Ligera', 'Alta'];
    $es['c_stretch_sel'] = 'Nula';

    $fr = $en;
    $fr['inspire_title'] = 'Source du dessin';
    $fr['look_title'] = 'Silhouette';
    $fr['macro_title'] = 'De près';
    $fr['quiet_line'] = 'Tissu sur le corps ; air dans le pas.';
    $fr['checklist_title'] = 'À retenir';
    $fr['wash_title'] = 'Entretien';
    $fr['info_title'] = 'En un coup d’œil';
    $fr['info_basics'] = 'Bases';
    $fr['info_comfort'] = 'Toucher';
    $fr['label_brand'] = 'Marque';
    $fr['label_name'] = 'Nom';
    $fr['label_color'] = 'Couleur';
    $fr['label_style'] = 'Style';
    $fr['label_size'] = 'Taille';
    $fr['label_fabric'] = 'Tissu';
    $fr['label_parts'] = 'Pièces';
    $fr['size_title'] = 'Guide des tailles';
    $fr['size_body'] = 'Choisir selon l’axe des tailles, buste et taille ; mesure manuelle ±1–3 cm.';
    $fr['original_title'] = 'Savoir-faire original';
    $fr['original_body'] = 'Honorez le métier ; motifs selon nos photos.';
    $fr['intro_body'] = "Coupe {$style} : {$parts}. Longueur et broderie selon les photos.";
    $fr['inspire_lines'] = ["Nommé « {$short} ».", 'Coupe et air—pas un discours de marché.'];
    $fr['inspire_note'] = 'Un motif seulement, pas une copie de plateforme.';
    $fr['look_body'] = 'Plans entiers et moyens pour lire ourlet, manches et col.';
    $fr['macro_body'] = 'Gros plans de fil, pli et tissu—sans inventer de chiffres.';
    $fr['checklist'] = ["Style : {$style}", "Pièces : {$parts}", 'Faites confiance aux photos', 'Choisissez la taille par buste et taille'];
    $fr['wash_lines'] = ['Lavage à la main séparé ; pas d’eau de Javel.', 'Séchage suspendu ; fer doux avec pattemouille.'];
    $fr['info_color'] = 'Comme sur photo';
    $fr['info_size'] = 'Voir l’axe des variantes';
    $fr['info_fabric'] = 'Tissu sélectionné (comme sur photo)';
    $fr['c_thick'] = 'Épaisseur';
    $fr['c_thick_opts'] = ['Fin', 'Moyen', 'Épais'];
    $fr['c_thick_sel'] = 'Moyen';
    $fr['c_fit'] = 'Coupe';
    $fr['c_fit_opts'] = ['Ajusté', 'Droit', 'Large'];
    $fr['c_fit_sel'] = 'Droit';
    $fr['c_soft'] = 'Toucher';
    $fr['c_soft_opts'] = ['Plus doux', 'Moyen', 'Plus ferme'];
    $fr['c_soft_sel'] = 'Moyen';
    $fr['c_stretch'] = 'Élasticité';
    $fr['c_stretch_opts'] = ['Aucune', 'Légère', 'Forte'];
    $fr['c_stretch_sel'] = 'Aucune';

    $pt = $en;
    $pt['inspire_title'] = 'Fonte do desenho';
    $pt['look_title'] = 'Silhueta';
    $pt['macro_title'] = 'De perto';
    $pt['quiet_line'] = 'Tecido no corpo; ar no passo.';
    $pt['checklist_title'] = 'Vale lembrar';
    $pt['wash_title'] = 'Cuidados';
    $pt['info_title'] = 'Em resumo';
    $pt['info_basics'] = 'Básico';
    $pt['info_comfort'] = 'Toque';
    $pt['label_brand'] = 'Marca';
    $pt['label_name'] = 'Nome';
    $pt['label_color'] = 'Cor';
    $pt['label_style'] = 'Estilo';
    $pt['label_size'] = 'Tamanho';
    $pt['label_fabric'] = 'Tecido';
    $pt['label_parts'] = 'Peças';
    $pt['size_title'] = 'Guia de tamanhos';
    $pt['size_body'] = 'Escolha pelo eixo de tamanhos, busto e altura; medida manual ±1–3 cm.';
    $pt['original_title'] = 'Ofício original';
    $pt['original_body'] = 'Honre o ofício; os motivos seguem nossas fotos.';
    $pt['intro_body'] = "Corte {$style}: {$parts}. Comprimento e bordado conforme as fotos.";
    $pt['inspire_lines'] = ["Com o nome «{$short}».", 'Corte e ar—sem discurso de mercado.'];
    $pt['inspire_note'] = 'Só um motivo, não cópia de plataforma.';
    $pt['look_body'] = 'Planos inteiros e médios para ler barra, mangas e gola.';
    $pt['macro_body'] = 'Planos fechados de fio, prega e tecido—sem inventar números.';
    $pt['checklist'] = ["Estilo: {$style}", "Peças: {$parts}", 'Confie nas fotos', 'Escolha o tamanho pelo busto e altura'];
    $pt['wash_lines'] = ['Lavar à mão em separado; sem alvejante.', 'Secar pendurado; ferro baixo com pano.'];
    $pt['info_color'] = 'Como na foto';
    $pt['info_size'] = 'Ver eixo de variantes';
    $pt['info_fabric'] = 'Tecido selecionado (como na foto)';
    $pt['c_thick'] = 'Espessura';
    $pt['c_thick_opts'] = ['Fino', 'Médio', 'Grosso'];
    $pt['c_thick_sel'] = 'Médio';
    $pt['c_fit'] = 'Caimento';
    $pt['c_fit_opts'] = ['Justo', 'Regular', 'Folgado'];
    $pt['c_fit_sel'] = 'Regular';
    $pt['c_soft'] = 'Toque';
    $pt['c_soft_opts'] = ['Mais macio', 'Médio', 'Mais firme'];
    $pt['c_soft_sel'] = 'Médio';
    $pt['c_stretch'] = 'Elasticidade';
    $pt['c_stretch_opts'] = ['Nenhuma', 'Leve', 'Alta'];
    $pt['c_stretch_sel'] = 'Nenhuma';

    $id = $en;
    $id['inspire_title'] = 'Sumber desain';
    $id['look_title'] = 'Siluet';
    $id['macro_title'] = 'Dari dekat';
    $id['quiet_line'] = 'Kain di badan; angin di langkah.';
    $id['checklist_title'] = 'Perlu diingat';
    $id['wash_title'] = 'Perawatan';
    $id['info_title'] = 'Sekilas';
    $id['info_basics'] = 'Dasar';
    $id['info_comfort'] = 'Rasa';
    $id['label_brand'] = 'Merek';
    $id['label_name'] = 'Nama';
    $id['label_color'] = 'Warna';
    $id['label_style'] = 'Gaya';
    $id['label_size'] = 'Ukuran';
    $id['label_fabric'] = 'Kain';
    $id['label_parts'] = 'Bagian';
    $id['size_title'] = 'Panduan ukuran';
    $id['size_body'] = 'Pilih menurut sumbu ukuran, lingkar dada, dan tinggi; ukur tangan ±1–3 cm.';
    $id['original_title'] = 'Kerajinan orisinal';
    $id['original_body'] = 'Hormati kerajinan; motif mengikuti foto kami.';
    $id['intro_body'] = "Potongan {$style}: {$parts}. Panjang dan sulaman mengikuti foto.";
    $id['inspire_lines'] = ["Bernama «{$short}».", 'Potongan dan suasana—bukan jargon pasar.'];
    $id['inspire_note'] = 'Hanya motif, bukan salinan platform.';
    $id['look_body'] = 'Foto penuh dan setengah badan agar hem, lengan, dan kerah terbaca.';
    $id['macro_body'] = 'Makro jahitan, lipatan, dan kain—tanpa mengarang angka.';
    $id['checklist'] = ["Gaya: {$style}", "Bagian: {$parts}", 'Percayai foto', 'Pilih ukuran menurut dada dan tinggi'];
    $id['wash_lines'] = ['Cuci tangan terpisah; tanpa pemutih.', 'Keringkan digantung; setrika rendah dengan kain.'];
    $id['info_color'] = 'Sesuai foto';
    $id['info_size'] = 'Lihat sumbu varian';
    $id['info_fabric'] = 'Kain pilihan (sesuai foto)';
    $id['c_thick'] = 'Ketebalan';
    $id['c_thick_opts'] = ['Tipis', 'Sedang', 'Tebal'];
    $id['c_thick_sel'] = 'Sedang';
    $id['c_fit'] = 'Potongan';
    $id['c_fit_opts'] = ['Ketat', 'Reguler', 'Longgar'];
    $id['c_fit_sel'] = 'Reguler';
    $id['c_soft'] = 'Rasa';
    $id['c_soft_opts'] = ['Lebih lembut', 'Sedang', 'Lebih kaku'];
    $id['c_soft_sel'] = 'Sedang';
    $id['c_stretch'] = 'Elastisitas';
    $id['c_stretch_opts'] = ['Tidak', 'Sedikit', 'Tinggi'];
    $id['c_stretch_sel'] = 'Tidak';

    $ar = $en;
    $ar['inspire_title'] = 'منبع التصميم';
    $ar['look_title'] = 'الإطلالة';
    $ar['macro_title'] = 'عن قرب';
    $ar['quiet_line'] = 'القماش على الجسد؛ الهواء في الخطوة.';
    $ar['checklist_title'] = 'جدير بالذكر';
    $ar['wash_title'] = 'العناية';
    $ar['info_title'] = 'لمحة';
    $ar['info_basics'] = 'أساسي';
    $ar['info_comfort'] = 'الملمس';
    $ar['label_brand'] = 'العلامة';
    $ar['label_name'] = 'الاسم';
    $ar['label_color'] = 'اللون';
    $ar['label_style'] = 'الأسلوب';
    $ar['label_size'] = 'المقاس';
    $ar['label_fabric'] = 'القماش';
    $ar['label_parts'] = 'الأجزاء';
    $ar['size_title'] = 'دليل المقاسات';
    $ar['size_body'] = 'اختر حسب محور المقاس والصدر والطول؛ القياس اليدوي قد يختلف 1–3 سم.';
    $ar['original_title'] = 'حرفة أصلية';
    $ar['original_body'] = 'أكرم الحرفة؛ الزخارف وفق صورنا.';
    $ar['intro_body'] = "قصّة {$style}: {$parts}. الطول والتطريز وفق الصور.";
    $ar['inspire_lines'] = ["باسم «{$short}».", 'قصّة وهواء—لا خطاب سوق.'];
    $ar['inspire_note'] = 'موضوع فقط، لا نص منصة.';
    $ar['look_body'] = 'لقطات كاملة ومتوسطة لقراءة الذيل والأكمام والياقة.';
    $ar['macro_body'] = 'لقطات قريبة للخيط والطيات والقماش—دون اختراع أرقام.';
    $ar['checklist'] = ["الأسلوب: {$style}", "الأجزاء: {$parts}", 'ثق بالصور', 'اختر المقاس حسب الصدر والطول'];
    $ar['wash_lines'] = ['غسل يدوي منفصل؛ بلا مبيض.', 'تجفيف معلق؛ كي لطيف مع قماش.'];
    $ar['info_color'] = 'كما في الصورة';
    $ar['info_size'] = 'انظر محور المتغيرات';
    $ar['info_fabric'] = 'قماش مختار (كما في الصورة)';
    $ar['c_thick'] = 'السماكة';
    $ar['c_thick_opts'] = ['رفيع', 'متوسط', 'سميك'];
    $ar['c_thick_sel'] = 'متوسط';
    $ar['c_fit'] = 'القصة';
    $ar['c_fit_opts'] = ['ضيق', 'عادي', 'واسع'];
    $ar['c_fit_sel'] = 'عادي';
    $ar['c_soft'] = 'الملمس';
    $ar['c_soft_opts'] = ['أنعم', 'متوسط', 'أصلب'];
    $ar['c_soft_sel'] = 'متوسط';
    $ar['c_stretch'] = 'المرونة';
    $ar['c_stretch_opts'] = ['لا', 'خفيف', 'عالي'];
    $ar['c_stretch_sel'] = 'لا';

    $bn = $en;
    $bn['inspire_title'] = 'ডিজাইনের উৎস';
    $bn['look_title'] = 'পুরো সাজ';
    $bn['macro_title'] = 'কাছ থেকে';
    $bn['quiet_line'] = 'শরীরে কাপড়; পদক্ষেপে হাওয়া।';
    $bn['checklist_title'] = 'মনে রাখার মতো';
    $bn['wash_title'] = 'যত্ন';
    $bn['info_title'] = 'এক নজরে';
    $bn['info_basics'] = 'মূল তথ্য';
    $bn['info_comfort'] = 'পরা অনুভূতি';
    $bn['size_title'] = 'সাইজ নির্দেশিকা';
    $bn['original_title'] = 'মূল কারুকাজ';
    $bn['label_brand'] = 'ব্র্যান্ড';
    $bn['label_name'] = 'নাম';
    $bn['label_color'] = 'রঙ';
    $bn['label_style'] = 'শৈলী';
    $bn['label_size'] = 'সাইজ';
    $bn['label_fabric'] = 'কাপড়';
    $bn['label_parts'] = 'অংশ';
    $bn['intro_body'] = "{$style} কাট: {$parts}। দৈর্ঘ্য ও সূচিকর্ম ছবি অনুসারে।";
    $bn['inspire_lines'] = ["নাম «{$short}».", 'কাট ও আবহ—বাজারের বুলি নয়।'];
    $bn['inspire_note'] = 'শুধু বিষয়, প্ল্যাটফর্ম কপি নয়।';
    $bn['look_body'] = 'পুরো ও মাঝারি শট সাজিয়ে হেম, হাতা ও কলারের স্তর পড়া যায়।';
    $bn['macro_body'] = 'কাছের দৃশ্যে সেলাই, ভাঁজ ও কাপড়ের অনুভূতি—শুধু প্রমাণ, কাল্পনিক স্পেক নয়।';
    $bn['checklist'] = ["শৈলী: {$style}", "অংশ: {$parts}", 'ছবিতে বিশ্বাস রাখুন', 'বুক ও উচ্চতায় সাইজ বেছে নিন'];
    $bn['wash_lines'] = ['আলাদা হাত ধোয়া; ব্লিচ নয়।', 'ঝুলিয়ে শুকান; নিম্ন তাপে কাপড় দিয়ে ইস্ত্রি।'];
    $bn['size_body'] = 'সাইজ অক্ষ, বুক ও উচ্চতা অনুযায়ী বেছে নিন; হাতের মাপে ১–৩ সেমি ফারাক হতে পারে।';
    $bn['original_body'] = 'কারুকে সম্মান করুন; নকশা আমাদের ছবি অনুসারে।';
    $bn['info_color'] = 'ছবি অনুসারে';
    $bn['info_size'] = 'ভ্যারিয়েন্ট অক্ষ দেখুন';
    $bn['info_fabric'] = 'নির্বাচিত কাপড় (ছবি অনুসারে)';
    $bn['c_thick'] = 'পুরুত্ব';
    $bn['c_thick_opts'] = ['পাতলা', 'মাঝারি', 'মোটা'];
    $bn['c_thick_sel'] = 'মাঝারি';
    $bn['c_fit'] = 'ফিট';
    $bn['c_fit_opts'] = ['স্লিম', 'রেগুলার', 'আরাম'];
    $bn['c_fit_sel'] = 'রেগুলার';
    $bn['c_soft'] = 'স্পর্শ';
    $bn['c_soft_opts'] = ['নরম', 'মাঝারি', 'শক্ত'];
    $bn['c_soft_sel'] = 'মাঝারি';
    $bn['c_stretch'] = 'ইলাস্টিসিটি';
    $bn['c_stretch_opts'] = ['নেই', 'সামান্য', 'বেশি'];
    $bn['c_stretch_sel'] = 'নেই';

    $hi = $en;
    $hi['inspire_title'] = 'डिज़ाइन स्रोत';
    $hi['look_title'] = 'पूरा लुक';
    $hi['macro_title'] = 'नज़दीक से';
    $hi['quiet_line'] = 'वस्त्र शरीर पर; हवा कदम में।';
    $hi['checklist_title'] = 'याद रखने योग्य';
    $hi['wash_title'] = 'देखभाल';
    $hi['info_title'] = 'एक नज़र में';
    $hi['info_basics'] = 'मूल बातें';
    $hi['info_comfort'] = 'पहनने का अहसास';
    $hi['size_title'] = 'साइज़ मार्गदर्शिका';
    $hi['original_title'] = 'मूल शिल्प';
    $hi['label_brand'] = 'ब्रांड';
    $hi['label_name'] = 'नाम';
    $hi['label_color'] = 'रंग';
    $hi['label_style'] = 'शैली';
    $hi['label_size'] = 'साइज़';
    $hi['label_fabric'] = 'कपड़ा';
    $hi['label_parts'] = 'भाग';
    $hi['intro_body'] = "{$style} कट: {$parts}। लंबाई और कढ़ाई फ़ोटो के अनुसार।";
    $hi['inspire_lines'] = ["नाम «{$short}».", 'कट और भाव—बाज़ार की भाषा नहीं।'];
    $hi['inspire_note'] = 'केवल विषय, प्लेटफ़ॉर्म कॉपी नहीं।';
    $hi['look_body'] = 'पूर्ण और मध्य शॉट से हेम, आस्तीन और कॉलर की परतें पढ़ी जा सकती हैं।';
    $hi['macro_body'] = 'नज़दीकी दृश्य में टांका, प्लीट और कपड़ा—केवल साक्ष्य, गढ़े हुए आंकड़े नहीं।';
    $hi['checklist'] = ["शैली: {$style}", "भाग: {$parts}", 'फ़ोटो पर भरोसा करें', 'छाती और ऊँचाई से साइज़ चुनें'];
    $hi['wash_lines'] = ['अलग से हाथ धोएँ; ब्लीच नहीं।', 'लटकाकर सुखाएँ; कम गर्मी पर कपड़े से इस्त्री।'];
    $hi['size_body'] = 'साइज़ अक्ष, छाती और ऊँचाई से चुनें; हाथ माप में १–३ सेमी अंतर हो सकता है।';
    $hi['original_body'] = 'शिल्प का सम्मान करें; रूप हमारी फ़ोटो के अनुसार।';
    $hi['info_color'] = 'जैसा चित्र में';
    $hi['info_size'] = 'वेरिएंट अक्ष देखें';
    $hi['info_fabric'] = 'चयनित कपड़ा (जैसा चित्र में)';
    $hi['c_thick'] = 'मोटाई';
    $hi['c_thick_opts'] = ['पतला', 'मध्यम', 'मोटा'];
    $hi['c_thick_sel'] = 'मध्यम';
    $hi['c_fit'] = 'फ़िट';
    $hi['c_fit_opts'] = ['स्लिम', 'रेगुलर', 'ढीला'];
    $hi['c_fit_sel'] = 'रेगुलर';
    $hi['c_soft'] = 'स्पर्श';
    $hi['c_soft_opts'] = ['नरम', 'मध्यम', 'कड़ा'];
    $hi['c_soft_sel'] = 'मध्यम';
    $hi['c_stretch'] = 'खिंचाव';
    $hi['c_stretch_opts'] = ['नहीं', 'हल्का', 'अधिक'];
    $hi['c_stretch_sel'] = 'नहीं';

    $ur = $en;
    $ur['inspire_title'] = 'ڈیزائن کا منبع';
    $ur['look_title'] = 'مکمل نظر';
    $ur['macro_title'] = 'قریب سے';
    $ur['quiet_line'] = 'جامہ جسم پر؛ ہوا قدم میں۔';
    $ur['checklist_title'] = 'یاد رکھنے کے قابل';
    $ur['wash_title'] = 'نگہداشت';
    $ur['info_title'] = 'ایک نظر میں';
    $ur['info_basics'] = 'بنیادی';
    $ur['info_comfort'] = 'پہننے کا احساس';
    $ur['size_title'] = 'سائز رہنما';
    $ur['original_title'] = 'اصل دستکاری';
    $ur['label_brand'] = 'برانڈ';
    $ur['label_name'] = 'نام';
    $ur['label_color'] = 'رنگ';
    $ur['label_style'] = 'طرز';
    $ur['label_size'] = 'سائز';
    $ur['label_fabric'] = 'کپڑا';
    $ur['label_parts'] = 'حصے';
    $ur['intro_body'] = "{$style} کٹ: {$parts}۔ لمبائی اور کڑھائی تصاویر کے مطابق۔";
    $ur['inspire_lines'] = ["نام «{$short}».", 'کٹ اور فضا—بازاری زبان نہیں۔'];
    $ur['inspire_note'] = 'صرف موضوع، پلیٹ فارم کاپی نہیں۔';
    $ur['look_body'] = 'مکمل اور درمیانی شاٹس سے ہیَم، آستین اور کالر کی تہیں پڑھی جا سکتی ہیں۔';
    $ur['macro_body'] = 'قریبی نظارے میں ٹانکا، پلیٹ اور کپڑا—صرف ثبوت، بناوٹی پیمائش نہیں۔';
    $ur['checklist'] = ["طرز: {$style}", "حصے: {$parts}", 'تصاویر پر بھروسہ کریں', 'سینہ اور قد سے سائز چنیں'];
    $ur['wash_lines'] = ['علیحدہ ہاتھ دھوئیں؛ بلیچ نہیں۔', 'لٹکا کر خشک کریں؛ کم حرارت پر کپڑے سے استری۔'];
    $ur['size_body'] = 'سائز محور، سینہ اور قد سے چنیں؛ ہاتھ کی پیمائش میں ۱–۳ سینٹی میٹر فرق ہو سکتا ہے۔';
    $ur['original_body'] = 'دستکاری کا احترام کریں؛ نقوش ہماری تصاویر کے مطابق۔';
    $ur['info_color'] = 'جیسا تصویر میں';
    $ur['info_size'] = 'ویرینٹ محور دیکھیں';
    $ur['info_fabric'] = 'منتخب کپڑا (جیسا تصویر میں)';
    $ur['c_thick'] = 'موٹائی';
    $ur['c_thick_opts'] = ['پتلا', 'درمیانہ', 'موٹا'];
    $ur['c_thick_sel'] = 'درمیانہ';
    $ur['c_fit'] = 'فٹ';
    $ur['c_fit_opts'] = ['سلِم', 'ریگولر', 'ڈھیلا'];
    $ur['c_fit_sel'] = 'ریگولر';
    $ur['c_soft'] = 'لمس';
    $ur['c_soft_opts'] = ['نرم', 'درمیانہ', 'سخت'];
    $ur['c_soft_sel'] = 'درمیانہ';
    $ur['c_stretch'] = 'لچک';
    $ur['c_stretch_opts'] = ['نہیں', 'ہلکی', 'زیادہ'];
    $ur['c_stretch_sel'] = 'نہیں';

    $packs = [
        'zh_Hans_CN' => $zh,
        'en_US' => $en,
        'es_ES' => $es,
        'fr_FR' => $fr,
        'pt_BR' => $pt,
        'id_ID' => $id,
        'ar_SA' => $ar,
        'bn_BD' => $bn,
        'hi_IN' => $hi,
        'ur_PK' => $ur,
    ];
    foreach ($packs as $locale => $pack) {
        if ($locale === 'zh_Hans_CN') {
            continue;
        }
        $packs[$locale] = suiteApplyLocalizedStyleParts($pack, $locale, $style, $parts);
    }
    $packs = suiteApplyProductInspireOverrides($packs, $nameBlob, $short);

    return $packs;
}

/**
 * Textify product-specific 设计灵感 boards into every enabled locale (field-complete).
 *
 * @param array<string,array<string,mixed>> $packs
 * @return array<string,array<string,mixed>>
 */
function suiteApplyProductInspireOverrides(array $packs, string $nameBlob, string $short): array
{
    if (str_contains($nameBlob, '秋实')) {
        $map = [
            'zh_Hans_CN' => null, // already set
            'en_US' => null,
            'es_ES' => [
                'inspire_title' => 'Fuente del diseño',
                'inspire_lines' => [
                    'Las hojas de arce guían el conjunto: puro, sereno y refinado.',
                    'Cuello alto con hombreras bordadas; el patrón baja por la falda.',
                    'Suave y preciso, aún con viveza juvenil.',
                ],
                'inspire_note' => 'Motivo de arce del corte—no eslogan de mercado.',
                'poem_title' => 'Verso al lado',
                'poem_lines' => ['Muchos árboles en el patio; solo suspiro por el ciruelo.', 'Florece en la escarcha y da fruto en el rocío.'],
            ],
            'fr_FR' => [
                'inspire_title' => 'Source du dessin',
                'inspire_lines' => [
                    'Les feuilles d’érable mènent l’ensemble—pur, calme, raffiné.',
                    'Col montant et épaulettes brodées; le motif descend sur la jupe.',
                    'Doux et précis, encore vif comme la jeunesse.',
                ],
                'inspire_note' => 'Motif d’érable du coupe—pas un slogan marché.',
                'poem_title' => 'Vers à côté',
                'poem_lines' => ['Beaucoup d’arbres dans la cour; je soupire pour le prunier.', 'Il fleurit dans le givre et fructifie dans la rosée.'],
            ],
            'pt_BR' => [
                'inspire_title' => 'Fonte do desenho',
                'inspire_lines' => [
                    'Folhas de bordo guiam o conjunto—puro, sereno e refinado.',
                    'Gola alta com ombreiras bordadas; o padrão desce na saia.',
                    'Suave e preciso, ainda vivo como a juventude.',
                ],
                'inspire_note' => 'Motivo de bordo do corte—não slogan de mercado.',
                'poem_title' => 'Verso ao lado',
                'poem_lines' => ['Muitas árvores no pátio; suspiro só pela ameixeira.', 'Floresce na geada e frutifica no orvalho.'],
            ],
            'id_ID' => [
                'inspire_title' => 'Sumber desain',
                'inspire_lines' => [
                    'Daun maple memimpin set—murni, tenang, dan halus.',
                    'Kerah berdiri dengan bahu bersulam; motif mengalir ke rok.',
                    'Lembut dan rapi, tetap lincah seperti muda.',
                ],
                'inspire_note' => 'Motif maple dari potongan—bukan slogan pasar.',
                'poem_title' => 'Syair samping',
                'poem_lines' => ['Banyak pohon di pelataran; aku mengeluh hanya untuk plum.', 'Berkembang di embun beku dan berbuah di embun.'],
            ],
            'ar_SA' => [
                'inspire_title' => 'منبع التصميم',
                'inspire_lines' => [
                    'أوراق القيقب تقود المجموعة—نقية وهادئة وراقية.',
                    'ياقة واقفة مع كتف مطرّز؛ النقش ينساب على التنورة.',
                    'لطيفة ودقيقة، وما زالت حيّة كالشباب.',
                ],
                'inspire_note' => 'موتيف القيقب من القصّ—لا شعار سوق.',
                'poem_title' => 'بيت شعري',
                'poem_lines' => ['أشجار كثيرة في الفناء؛ أتنهد فقط للبرقوق.', 'يزهر في الصقيع ويثمر في الندى.'],
            ],
            'bn_BD' => [
                'inspire_title' => 'ডিজাইনের উৎস',
                'inspire_lines' => [
                    'ম্যাপেল পাতাই সেটের মূল—শুদ্ধ, শান্ত ও সূক্ষ্ম।',
                    'স্ট্যান্ড কলার ও সূচিকর্ম মেঘ কাঁধ; নকশা স্কার্টে নেমেছে।',
                    'কোমল ও নিখুঁত, তবু যৌবনের মতো প্রাণবন্ত।',
                ],
                'inspire_note' => 'কাট থেকে ম্যাপেল মোটিফ—বাজারের স্লোগান নয়।',
                'poem_title' => 'পার্শ্ব পদ্য',
                'poem_lines' => ['আঙ্গিনায় অনেক গাছ; শুধু বরইয়ের জন্য দীর্ঘশ্বাস।', 'তুষারে ফোটে, শিশিরে ফল ধরে।'],
            ],
            'hi_IN' => [
                'inspire_title' => 'डिज़ाइन स्रोत',
                'inspire_lines' => [
                    'मेपल पत्ते सेट का मूल—शुद्ध, शांत और परिष्कृत।',
                    'स्टैंड कॉलर और कढ़ाई वाला क्लाउड शोल्डर; पैटर्न स्कर्ट पर उतरता है।',
                    'कोमल और सटीक, फिर भी युवा vivacity।',
                ],
                'inspire_note' => 'कट से मेपल मोटिफ—बाज़ार का नारा नहीं।',
                'poem_title' => 'पार्श्व छंद',
                'poem_lines' => ['आँगन में अनेक वृक्ष; केवल बेर के लिए आह।', 'पाला में खिले, ओस में फल।'],
            ],
            'ur_PK' => [
                'inspire_title' => 'ڈیزائن کا سرچشمہ',
                'inspire_lines' => [
                    'میپل کے پتے سیٹ کی روح—پاک، خاموش اور نفیس۔',
                    'اسٹینڈ کالر اور کڑھائی والا کلاؤڈ شولڈر؛ نقش اسکرٹ پر اترتا ہے۔',
                    'نرم اور درست، پھر بھی جوانی سی زندہ۔',
                ],
                'inspire_note' => 'کٹ سے میپل موٹیف—بازار کا نعرہ نہیں۔',
                'poem_title' => 'پہلو شعر',
                'poem_lines' => ['آنگن میں بہت درخت؛ صرف آلوچے پر آہ۔', 'پالے میں کھلے، اوس میں پھل۔'],
            ],
        ];
        // fix accidental English word in hi
        $map['hi_IN']['inspire_lines'][2] = 'कोमल और सटीक, फिर भी युवा सी जीवंत।';
        foreach ($map as $locale => $over) {
            if ($over === null || !isset($packs[$locale])) {
                continue;
            }
            foreach ($over as $k => $v) {
                $packs[$locale][$k] = $v;
            }
        }
    } elseif (str_contains($nameBlob, '沐秋')) {
        $map = [
            'es_ES' => [
                'inspire_title' => 'Fuente del diseño',
                'inspire_lines' => [
                    'Muqiu—luz de otoño lavando el cuello de nube y capas mamian.',
                    'Cuello claro, bordado disperso, digno para el día a día.',
                    'Tonos cálidos sin discurso de mercado.',
                ],
                'inspire_note' => 'Solo motivo; confíe en el bordado fotografiado.',
            ],
            'fr_FR' => [
                'inspire_title' => 'Source du dessin',
                'inspire_lines' => [
                    'Muqiu—lumière d’automne lavant le col-nuage et les couches mamian.',
                    'Col net, broderie clairsemée, digne du quotidien.',
                    'Tons chauds sans discours de marché.',
                ],
                'inspire_note' => 'Motif seulement; faites confiance à la broderie photographiée.',
            ],
            'pt_BR' => [
                'inspire_title' => 'Fonte do desenho',
                'inspire_lines' => [
                    'Muqiu—luz de outono lavando o colar de nuvem e camadas mamian.',
                    'Gola clara, bordado esparso, digno do dia a dia.',
                    'Tons quentes sem discurso de mercado.',
                ],
                'inspire_note' => 'Só motivo; confie no bordado fotografado.',
            ],
            'id_ID' => [
                'inspire_title' => 'Sumber desain',
                'inspire_lines' => [
                    'Muqiu—cahaya musim gugur mencuci kerah awan dan lapisan mamian.',
                    'Kerah jernih, sulaman jarang, pantas untuk sehari-hari.',
                    'Nada hangat tanpa pidato pasar.',
                ],
                'inspire_note' => 'Hanya motif; percayai sulaman yang difoto.',
            ],
            'ar_SA' => [
                'inspire_title' => 'منبع التصميم',
                'inspire_lines' => [
                    'موتشيو—ضوء خريف يغسل ياقة السحابة وطبقات الماميان.',
                    'ياقة صافية وتطريز متناثر، لائق لليومي.',
                    'درجات دافئة بلا خطاب سوق.',
                ],
                'inspire_note' => 'موتيف فقط؛ ثق بالتطريز المصوّر.',
            ],
            'bn_BD' => [
                'inspire_title' => 'ডিজাইনের উৎস',
                'inspire_lines' => [
                    'মুচিউ—শরতের আলো মেঘ কলার ও মামিয়ান স্তর ধুয়ে দেয়।',
                    'স্বচ্ছ স্ট্যান্ড কলার, ছড়ানো সূচিকর্ম, দৈনন্দিনের উপযোগী।',
                    'উষ্ণ টোন, বাজারের বুলি নয়।',
                ],
                'inspire_note' => 'শুধু মোটিফ; ছবির সূচিকর্মে বিশ্বাস রাখুন।',
            ],
            'hi_IN' => [
                'inspire_title' => 'डिज़ाइन स्रोत',
                'inspire_lines' => [
                    'मुच्यू—शरद प्रकाश क्लाउड कॉलर और मामियान परतों को धोता है।',
                    'स्पष्ट स्टैंड कॉलर, बिखरी कढ़ाई, रोज़ के योग्य।',
                    'गर्म स्वर, बाज़ार भाषण नहीं।',
                ],
                'inspire_note' => 'केवल मोटिफ; फोटो की कढ़ाई पर भरोसा करें।',
            ],
            'ur_PK' => [
                'inspire_title' => 'ڈیزائن کا سرچشمہ',
                'inspire_lines' => [
                    'موچیو—خزاں کی روشنی کلاؤڈ کالر اور مامیان تہوں کو دھوتی ہے۔',
                    'صاف اسٹینڈ کالر، بکھری کڑھائی، روزمرہ کے لائق۔',
                    'گرم لہجے، بازار کی تقریر نہیں۔',
                ],
                'inspire_note' => 'صرف موٹیف؛ تصویر کی کڑھائی پر بھروسہ کریں۔',
            ],
        ];
        foreach ($map as $locale => $over) {
            if (!isset($packs[$locale])) {
                continue;
            }
            foreach ($over as $k => $v) {
                $packs[$locale][$k] = $v;
            }
        }
    }

    return $packs;
}

/**
 * @param array<string,mixed> $t
 * @param list<array{id:string,role:string,w:int,h:int}> $imgs
 */
function suiteAssembleHtml(array $t, array $imgs, callable $h): string
{
    $img = static function (array $a, string $alt) use ($h): string {
        return '<img src="asset://' . $h((string)$a['id']) . '" alt="' . $h($alt)
            . '" loading="lazy" decoding="async" width="' . (int)$a['w'] . '" height="' . (int)$a['h'] . '">';
    };
    $figureStack = static function (array $nodes, string $mod = '') use ($h): string {
        $rows = '';
        $n = count($nodes);
        if ($n >= 3) {
            $rows .= '<div class="weline-detail-figure-row weline-detail-figure-row--triptych">';
            foreach ($nodes as $one) {
                $rows .= '<div class="weline-detail-figure">' . $one . '</div>';
            }
            $rows .= '</div>';
        } else {
            for ($i = 0; $i < $n; $i += 2) {
                if ($i + 1 < $n) {
                    $rows .= '<div class="weline-detail-figure-row weline-detail-figure-row--pair">'
                        . '<div class="weline-detail-figure">' . $nodes[$i] . '</div>'
                        . '<div class="weline-detail-figure">' . $nodes[$i + 1] . '</div>'
                        . '</div>';
                } else {
                    $rows .= '<div class="weline-detail-figure-row weline-detail-figure-row--solo">'
                        . '<div class="weline-detail-figure">' . $nodes[$i] . '</div>'
                        . '</div>';
                }
            }
        }
        $cls = 'weline-detail-figure-stack' . ($mod !== '' ? ' ' . $mod : '');

        return '<div class="' . $h($cls) . '">' . $rows . '</div>';
    };

    $main = null;
    $gallery = [];
    $detail = [];
    foreach ($imgs as $a) {
        if ($a['role'] === 'main' && $main === null) {
            $main = $a;
        } elseif ($a['role'] === 'gallery') {
            $gallery[] = $a;
        } elseif ($a['role'] === 'detail') {
            $detail[] = $a;
        } elseif ($a['role'] === 'variant' && $main === null) {
            $main = $a;
        }
    }
    $pool = array_values(array_filter(array_merge($gallery, $detail, $main ? [$main] : [])));
    if ($pool === []) {
        $pool = $imgs;
    }
    if ($pool === []) {
        return '';
    }

    // Prefer sharper / higher-bitrate assets for hero (anti soft fullbleed).
    usort($pool, static function (array $a, array $b): int {
        $score = static function (array $x): float {
            $path = (string)($x['path'] ?? '');
            $w = max(1, (int)($x['w'] ?? 1));
            $hgt = max(1, (int)($x['h'] ?? 1));
            $bytes = is_file($path) ? (int)filesize($path) : 0;

            return $bytes > 0 ? ($bytes * 8.0) / ($w * $hgt) : 0.0;
        };

        return $score($b) <=> $score($a);
    });
    $hero = $pool[0];
    $pick = static function (int $i) use ($pool): ?array {
        return $pool[$i] ?? null;
    };

    $intro = '<div class="weline-detail-prose weline-detail-prose--lead"><h3>' . $h((string)$t['intro_title']) . '</h3><p>'
        . $h((string)$t['intro_body']) . '</p></div>';
    // §5.2：hero 改为 caption 栈而非开篇 fullbleed，避免「首屏就是一张巨图墙」
    $heroHtml = $figureStack([$img($hero, (string)$t['alt_hero'])], 'weline-detail-figure-stack--caption');
    $inspire = '<div class="weline-detail-prose weline-detail-prose--verse"><h3>' . $h((string)$t['inspire_title']) . '</h3>';
    foreach ((array)$t['inspire_lines'] as $line) {
        $inspire .= '<p>' . $h((string)$line) . '</p>';
    }
    $inspire .= '<p class="weline-detail-feature__note">' . $h((string)$t['inspire_note']) . '</p></div>';

    // §3.2‑B / §5.2：图少也要「诗侧栏 + pair」；优先保证 pair，再开 feature / hero
    $poemLines = [];
    foreach ((array)($t['poem_lines'] ?? $t['inspire_lines'] ?? []) as $line) {
        $line = trim((string)$line);
        if ($line !== '') {
            $poemLines[] = $line;
        }
    }
    if ($poemLines === []) {
        $fallback = trim((string)($t['inspire_note'] ?? $t['look_body'] ?? $t['intro_body'] ?? ''));
        if ($fallback !== '') {
            $poemLines[] = $fallback;
        }
    }
    if ($poemLines === []) {
        $poemLines[] = (string)($t['inspire_title'] ?? $t['intro_title'] ?? '形制可读');
    }

    $nPool = count($pool);
    // 诗侧栏用 hero，省一张给 pair
    $poemMedia = $hero;
    $poemCopy = '<div class="weline-detail-prose weline-detail-prose--verse-vertical" lang="zh-Hans">'
        . '<h3 class="weline-detail-prose__eyebrow">' . $h((string)($t['poem_title'] ?? $t['inspire_title'] ?? '')) . '</h3>';
    foreach ($poemLines as $line) {
        $poemCopy .= '<p>' . $h($line) . '</p>';
    }
    $poemCopy .= '</div>';
    $poemAside = '<div class="weline-detail-feature weline-detail-feature--poem-aside">'
        . '<div class="weline-detail-feature__copy">' . $poemCopy . '</div>'
        . '<div class="weline-detail-feature__media">' . $img($poemMedia, (string)$t['alt_look']) . '</div>'
        . '</div>';

    $poolOffset = 1; // hero 已用于诗侧栏
    $a1 = $pick($poolOffset);
    $a2 = $pick($poolOffset + 1);
    $pair = '';
    $featureBlock = '';
    $heroHtml = '';

    if ($a1 && $a2) {
        $pair = $figureStack([
            $img($a1, (string)$t['alt_look'] . ' 1'),
            $img($a2, (string)$t['alt_look'] . ' 2'),
        ], 'weline-detail-figure-stack--caption')
            . '<div class="weline-detail-prose"><h3>' . $h((string)$t['look_title']) . '</h3><p>'
            . $h((string)$t['look_body']) . '</p></div>';
        $poolOffset += 2;
    } elseif ($a1) {
        // 仅剩 1 张：与 hero 再组 pair（诗侧栏已展示 hero，双列仍满足 §5.2）
        $pair = $figureStack([
            $img($hero, (string)$t['alt_hero']),
            $img($a1, (string)$t['alt_look'] . ' 1'),
        ], 'weline-detail-figure-stack--caption')
            . '<div class="weline-detail-prose"><h3>' . $h((string)$t['look_title']) . '</h3><p>'
            . $h((string)$t['look_body']) . '</p></div>';
        $poolOffset += 1;
    } elseif ($nPool >= 1 && $hero) {
        // 整池仅 1 张：hero 自复用组 pair（§5.2 单图不得缺 pair）
        $pair = $figureStack([
            $img($hero, (string)$t['alt_hero']),
            $img($hero, (string)$t['alt_look'] . ' 1'),
        ], 'weline-detail-figure-stack--caption')
            . '<div class="weline-detail-prose"><h3>' . $h((string)$t['look_title']) . '</h3><p>'
            . $h((string)$t['look_body']) . '</p></div>';
    }

    $featureMedia = $pick($poolOffset);
    if ($featureMedia === null && $nPool >= 1 && $hero) {
        // 单图：feature 复用 hero，保证 poem-aside + feature 齐
        $featureMedia = $hero;
    }
    if ($featureMedia !== null) {
        $featureBlock = '<div class="weline-detail-feature weline-detail-feature--reverse">'
            . '<div class="weline-detail-feature__media">' . $img($featureMedia, (string)$t['alt_look'] . ' · 形制') . '</div>'
            . '<div class="weline-detail-feature__copy"><h3>' . $h((string)$t['look_title']) . '</h3><p>'
            . $h((string)$t['look_body']) . '</p>'
            . '<p class="weline-detail-feature__note">' . $h((string)($t['inspire_note'] ?? '')) . '</p></div>'
            . '</div>';
        if ($featureMedia !== $hero || $poolOffset < $nPool) {
            $poolOffset++;
        }
    }

    // 图充足时再补一张 caption hero（非 fullbleed）
    if ($nPool >= 6) {
        $moreHero = $pick($poolOffset);
        if ($moreHero !== null) {
            $heroHtml = $figureStack([$img($moreHero, (string)$t['alt_hero'])], 'weline-detail-figure-stack--caption');
            $poolOffset++;
        }
    }

    $a4 = $pick($poolOffset);
    $a5 = $pick($poolOffset + 1);
    $macro = '';
    if ($a4 || $a5) {
        $nodes = [];
        if ($a4) {
            $nodes[] = $img($a4, (string)$t['alt_macro'] . ' 1');
            $poolOffset++;
        }
        if ($a5) {
            $nodes[] = $img($a5, (string)$t['alt_macro'] . ' 2');
            $poolOffset++;
        }
        $macro = $figureStack($nodes, 'weline-detail-figure-stack--caption')
            . '<div class="weline-detail-prose"><h3>' . $h((string)$t['macro_title']) . '</h3><p>'
            . $h((string)$t['macro_body']) . '</p></div>';
    }

    $quiet = '<div class="weline-detail-quiet-spacer" aria-hidden="true"></div>';
    $quietLine = '<div class="weline-detail-prose weline-detail-prose--quiet"><p>' . $h((string)$t['quiet_line']) . '</p></div>';
    // §5.3‑B：≥3 条卖点 → 2×2 均布短卡（禁苹果大黑框 / --large 跨格空洞）
    $checklistItems = array_values(array_filter(array_map(
        static fn($item): string => trim((string)$item),
        (array)($t['checklist'] ?? []),
    ), static fn(string $s): bool => $s !== ''));
    $bento = '';
    if (count($checklistItems) >= 3) {
        $bentoCards = '';
        foreach (array_slice($checklistItems, 0, 4) as $i => $item) {
            // 首卡仅左边线强调，禁止 --large/--wide 反色空洞
            $mod = $i === 0 ? ' weline-detail-bento__card--accent' : '';
            $title = $item;
            $body = '';
            if (preg_match('/^(.{2,18}?)[：:]\s*(.+)$/u', $item, $m)) {
                $title = trim($m[1]);
                $body = trim($m[2]);
            }
            // 短键值对：并排填满，避免「制式」顶角、「唐制」底角中间空
            $isKv = $body !== '' && $body !== $title && mb_strlen($title) <= 6 && mb_strlen($body) <= 24;
            if ($isKv) {
                $mod .= ' weline-detail-bento__card--kv';
            }
            $bentoCards .= '<div class="weline-detail-bento__card' . $mod . '">'
                . '<h4>' . $h($title) . '</h4>';
            // 无分隔时勿把整句再写一遍当正文（用户已验「标题=正文」重复）
            if ($body !== '' && $body !== $title) {
                $bentoCards .= '<p>' . $h($body) . '</p>';
            }
            $bentoCards .= '</div>';
        }
        $bento = '<div class="weline-detail-bento">'
            . '<h3 class="weline-detail-bento__heading">' . $h((string)$t['checklist_title']) . '</h3>'
            . '<div class="weline-detail-bento__grid">' . $bentoCards . '</div>'
            . '</div>';
    }
    $check = '';
    if ($bento === '') {
        $check = '<div class="weline-detail-prose weline-detail-prose--checklist"><h3>' . $h((string)$t['checklist_title']) . '</h3><ul>';
        foreach ($checklistItems as $item) {
            $check .= '<li>' . $h($item) . '</li>';
        }
        $check .= '</ul></div>';
    }

    // Extra looks: at most one pair + one solo, each followed by prose — never dump solo×N to bottom.
    $extra = '';
    $a6 = $pick($poolOffset);
    $a7 = $pick($poolOffset + 1);
    if ($a6 && $a7) {
        $extra .= $figureStack([
            $img($a6, (string)$t['alt_look'] . ' 3'),
            $img($a7, (string)$t['alt_look'] . ' 4'),
        ], 'weline-detail-figure-stack--caption')
            . '<div class="weline-detail-prose"><p>' . $h((string)$t['look_body']) . '</p></div>';
        $poolOffset += 2;
    } elseif ($a6) {
        $extra .= $figureStack([$img($a6, (string)$t['alt_look'] . ' 3')], 'weline-detail-figure-stack--caption')
            . '<div class="weline-detail-prose"><p>' . $h((string)$t['look_body']) . '</p></div>';
        $poolOffset++;
    }
    $a8 = $pick($poolOffset);
    if ($a8) {
        $extra .= $figureStack([$img($a8, (string)$t['alt_macro'] . ' 3')], 'weline-detail-figure-stack--caption')
            . '<div class="weline-detail-prose"><p>' . $h((string)$t['macro_body']) . '</p></div>';
    }

    $wash = '<div class="weline-detail-prose weline-detail-prose--checklist"><h3>' . $h((string)$t['wash_title']) . '</h3><ul>';
    foreach ((array)$t['wash_lines'] as $line) {
        $wash .= '<li>' . $h((string)$line) . '</li>';
    }
    $wash .= '</ul></div>';

    $info = DetailDescriptionTextifier::buildProductInfoPanelZh(
        [
            (string)$t['label_brand'] => (string)$t['info_brand'],
            (string)$t['label_name'] => (string)$t['info_name'],
            (string)$t['label_color'] => (string)$t['info_color'],
            (string)$t['label_style'] => (string)$t['info_style'],
            (string)$t['label_size'] => (string)$t['info_size'],
            (string)$t['label_fabric'] => (string)$t['info_fabric'],
            (string)$t['label_parts'] => (string)$t['info_parts'],
        ],
        [
            ['label' => (string)$t['c_thick'], 'options' => (array)$t['c_thick_opts'], 'selected' => (string)$t['c_thick_sel']],
            ['label' => (string)$t['c_fit'], 'options' => (array)$t['c_fit_opts'], 'selected' => (string)$t['c_fit_sel']],
            ['label' => (string)$t['c_soft'], 'options' => (array)$t['c_soft_opts'], 'selected' => (string)$t['c_soft_sel']],
            ['label' => (string)$t['c_stretch'], 'options' => (array)$t['c_stretch_opts'], 'selected' => (string)$t['c_stretch_sel']],
        ],
        (string)$t['info_title'],
        (string)$t['info_basics'],
        (string)$t['info_comfort'],
    );
    $size = '<div class="weline-detail-prose"><h3>' . $h((string)$t['size_title']) . '</h3><p>'
        . $h((string)$t['size_body']) . '</p></div>';
    $original = '<div class="weline-detail-prose"><h3>' . $h((string)$t['original_title']) . '</h3><p>'
        . $h((string)$t['original_body']) . '</p></div>';
    // Close: caption only — no second fullbleed album slide (anti 大图到底).
    $close = '<div class="weline-detail-prose weline-detail-prose--quiet"><p class="weline-detail-feature__note">'
        . $h((string)$t['close_caption']) . '</p></div>';

    return '<div data-weline-product-description="1688" data-weds="xq">'
        . '<!--weds:xq--><span data-weds="xq" hidden aria-hidden="true"></span>'
        . $intro . $inspire . $poemAside . $featureBlock . $heroHtml . $pair . $macro
        . $quiet . $bento . $check . $extra . $quietLine . $wash . $info . $size . $original . $close
        . '</div>';
}

$ok = 0;
$skip = 0;
$fail = 0;
$doneIds = [];

foreach ($productIds as $productId) {
    echo "=== product {$productId} ===\n";
    if (!$force && suiteHasWeds($pdo, $productId)) {
        echo "skip: already weds\n";
        $skip++;
        continue;
    }
    if ($force) {
        echo "force: ignore existing weds\n";
    }
    $meta = suiteExtractMeta($pdo, $productId);
    $assets = suiteCollectAssets($pdo, $productId, $mediaRoot);
    if ($assets === []) {
        fwrite(STDERR, "fail {$productId}: no assets\n");
        $fail++;
        continue;
    }
    $assets = suiteFilterDetailCandidates($assets);
    $workDir = $workRoot . '/' . $productId;
    @mkdir($workDir, 0775, true);
    try {
        $assets = suiteRemediateImages($assets, $workDir, $mediaRoot, $library, $access, $disk, $apply, $skipImages);
    } catch (Throwable $e) {
        fwrite(STDERR, "image fail {$productId}: {$e->getMessage()}\n");
        $fail++;
        continue;
    }
    $copy = suiteBuildCopyPacks($meta, $assets);
    $writes = [];
    foreach ($localePlan as $locale => $baseKey) {
        if (!isset($copy[$baseKey])) {
            fwrite(STDERR, "fail {$productId}: missing pack {$baseKey} for locale {$locale}\n");
            $fail++;
            continue 2;
        }
        if ($locale !== '' && $locale !== 'en_US' && $baseKey === 'en_US') {
            fwrite(STDERR, "fail {$productId}: EN pack mapped to {$locale}\n");
            $fail++;
            continue 2;
        }
        $html = suiteAssembleHtml($copy[$baseKey], $assets, $h);
        if ($html === '') {
            fwrite(STDERR, "fail {$productId}: empty html\n");
            $fail++;
            continue 2;
        }
        foreach ($banned as $b) {
            if (str_contains($html, $b)) {
                fwrite(STDERR, "fail {$productId} banned {$b}\n");
                $fail++;
                continue 3;
            }
        }
        if ($baseKey !== 'en_US') {
            foreach ($enLeakMarkers as $marker) {
                if (str_contains($html, $marker)) {
                    fwrite(STDERR, "fail {$productId} EN dump {$locale}: {$marker}\n");
                    $fail++;
                    continue 3;
                }
            }
        }
        if ($baseKey === 'pt_BR') {
            foreach ($ptEsLeakMarkers as $marker) {
                if (str_contains($html, $marker)) {
                    fwrite(STDERR, "fail {$productId} ES leak in PT {$locale}: {$marker}\n");
                    $fail++;
                    continue 3;
                }
            }
        }
        if ($baseKey !== 'zh_Hans_CN' && $baseKey !== '') {
            foreach ($zhLeakMarkers as $marker) {
                if (str_contains($html, $marker)) {
                    fwrite(STDERR, "fail {$productId} ZH leak {$locale}: {$marker}\n");
                    $fail++;
                    continue 3;
                }
            }
        }
        $writes[] = ['locale' => $locale, 'html' => $html, 'len' => strlen($html)];
    }
    echo $meta['short'] . "\timgs=" . count($assets) . "\tlocales=" . count($writes) . "\n";
    foreach ($writes as $w) {
        echo ($w['locale'] === '' ? '(empty)' : $w['locale']) . "\t" . $w['len'] . "\n";
    }
    if (!$apply) {
        echo "Dry-run only.\n";
        $ok++;
        $doneIds[] = $productId;
        continue;
    }
    foreach ($writes as $w) {
        $locale = (string)$w['locale'];
        $html = (string)$w['html'];
        if ($locale !== '') {
            LocalDescription::upsertQuiet($productId, $locale, [
                LocalDescription::schema_fields_DESCRIPTION => $html,
            ]);
        }
        $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'description', $locale, $html, true);
        // §5.2‑D：删同 locale 短「浏览…」桩，避免与 data-weds 长 HTML 双行并存
        if (str_contains($html, 'data-weds') && strlen($html) > 500) {
            $pdo->prepare(
                "DELETE FROM w_product_ws_0_attribute_value
                 WHERE entity_id=? AND attribute_code='description' AND store_id=0 AND locale=?
                   AND length(COALESCE(value_text,'')) < 200
                   AND COALESCE(value_text,'') NOT LIKE '%data-weds%'"
            )->execute([(string)$productId, $locale]);
        }
    }
    ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)->notifyCatalogChanged(
        $websiteId,
        'detail_suite_batch_' . $productId,
        ['product_ids' => [$productId]],
    );
    ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class)
        ->clearForCatalogChange('detail_suite_batch_' . $productId);
    echo "Applied.\n";
    $ok++;
    $doneIds[] = $productId;
}

echo "SUMMARY ok={$ok} skip={$skip} fail={$fail} apply=" . ($apply ? '1' : '0') . " ids=" . implode(',', $doneIds) . "\n";
exit($fail > 0 ? 1 : 0);
