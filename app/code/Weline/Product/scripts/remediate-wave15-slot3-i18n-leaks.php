<?php
declare(strict_types=1);

/**
 * Wave15 翻译优化（槽③）：清非中文 description 中文渗漏；
 * 非 EN 的 Verse aside 眉题译为目标语；补全空 zh name。
 * 自模型真译映射；禁止 Ollama。
 *
 * php /tmp/p-ar-wave15-i18n-fix.php --dry-run
 * php /tmp/p-ar-wave15-i18n-fix.php --apply
 */

require '/Users/weline/Project/Official/框架/app/bootstrap.php';

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Product\LocalDescription;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Service\ProductStorefrontCacheInvalidator;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

$opts = getopt('', ['apply', 'dry-run']);
$apply = isset($opts['apply']) && !isset($opts['dry-run']);

$env = include '/Users/weline/Project/Official/框架/app/etc/env.php';
$db = $env['db']['master'];
$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', $db['hostname'], $db['hostport'], $db['database']),
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$productIds = [398, 414, 193, 195, 197, 441, 252, 526, 250, 374, 445, 531];
$websiteId = 0;
$enabledLocales = $pdo->query(
    'SELECT language_code FROM w_weline_websites_website_language WHERE website_id=0 ORDER BY language_code'
)->fetchAll(PDO::FETCH_COLUMN);

$shopLabel = [
    'en_US' => 'This shop',
    'es_ES' => 'Esta tienda',
    'fr_FR' => 'Cette boutique',
    'pt_BR' => 'Esta loja',
    'id_ID' => 'Toko ini',
    'ar_SA' => 'هذا المتجر',
    'bn_BD' => 'এই দোকান',
    'hi_IN' => 'यह दुकान',
    'ur_PK' => 'یہ دکان',
];
$silhouetteLabel = [
    'en_US' => 'silhouette',
    'es_ES' => 'silueta',
    'fr_FR' => 'silhouette',
    'pt_BR' => 'silhueta',
    'id_ID' => 'siluet',
    'ar_SA' => 'قصّة',
    'bn_BD' => 'আকৃতি',
    'hi_IN' => 'आकृति',
    'ur_PK' => 'شکل',
];

$brandLabel = [
    'en_US' => 'Huazhaoji',
    'es_ES' => 'Huazhaoji',
    'fr_FR' => 'Huazhaoji',
    'pt_BR' => 'Huazhaoji',
    'id_ID' => 'Huazhaoji',
    'ar_SA' => 'هواجاوجي',
    'bn_BD' => 'হুয়াজাওজি',
    'hi_IN' => 'हुआझाओजी',
    'ur_PK' => 'ہواژاوجی',
];

$taohuashenLabel = [
    'en_US' => 'Taohuashen (Peach Blossom Goddess)',
    'es_ES' => 'Taohuashen (Diosa del melocotón)',
    'fr_FR' => 'Taohuashen (Déesse du pêcher)',
    'pt_BR' => 'Taohuashen (Deusa do pêssego)',
    'id_ID' => 'Taohuashen (Dewi bunga persik)',
    'ar_SA' => 'تاوهواشن (إلهة زهر الخوخ)',
    'bn_BD' => 'তাওহুয়াশেন (পীচ ফুল দেবী)',
    'hi_IN' => 'ताओहुआशेन (आड़ू फूल देवी)',
    'ur_PK' => 'تاؤہواشیں (آڑو پھول دیوی)',
];

$yurengeLabel = [
    'en_US' => 'Yurenge (Jade Beauty Song)',
    'es_ES' => 'Yurenge (Canción de la belleza de jade)',
    'fr_FR' => 'Yurenge (Chant de la beauté de jade)',
    'pt_BR' => 'Yurenge (Canção da beleza de jade)',
    'id_ID' => 'Yurenge (Lagu kecantikan giok)',
    'ar_SA' => 'يورينغه (أغنية جمال اليشم)',
    'bn_BD' => 'ইউরেনগে (জেড সৌন্দর্যের গান)',
    'hi_IN' => 'यूरेंगे (जेड सौंदर्य गीत)',
    'ur_PK' => 'یورینگے (جیڈ خوبصورتی کا گیت)',
];

$longtengLabel = [
    'en_US' => 'Longteng (Dragon Rise)',
    'es_ES' => 'Longteng (Ascenso del dragón)',
    'fr_FR' => 'Longteng (Essor du dragon)',
    'pt_BR' => 'Longteng (Ascensão do dragão)',
    'id_ID' => 'Longteng (Bangkitnya naga)',
    'ar_SA' => 'لونغتنغ (صعود التنين)',
    'bn_BD' => 'লংটেং (ড্রাগনের উত্থান)',
    'hi_IN' => 'लोंगटेंग (ड्रैगन उदय)',
    'ur_PK' => 'لونگ ٹینگ (ڈریگن کا عروج)',
];

$baizeLabel = [
    'en_US' => 'Baize (White Ze)',
    'es_ES' => 'Baize (Ze blanco)',
    'fr_FR' => 'Baize (Ze blanc)',
    'pt_BR' => 'Baize (Ze branco)',
    'id_ID' => 'Baize (Ze putih)',
    'ar_SA' => 'باي تسي (باي زي)',
    'bn_BD' => 'বাইজে (শ্বেত জে)',
    'hi_IN' => 'बाइज़े (श्वेत ज़े)',
    'ur_PK' => 'بائی زے (سفید زی)',
];

$yuexiaLabel = [
    'en_US' => 'Yuexia Huamian (Flowers Sleep Under Moon)',
    'es_ES' => 'Yuexia Huamian (Flores dormidas bajo la luna)',
    'fr_FR' => 'Yuexia Huamian (Fleurs endormies sous la lune)',
    'pt_BR' => 'Yuexia Huamian (Flores dormindo sob a lua)',
    'id_ID' => 'Yuexia Huamian (Bunga tidur di bawah bulan)',
    'ar_SA' => 'يويشيا هواميان (زهور نائمة تحت القمر)',
    'bn_BD' => 'ইউয়েশিয়া হুয়ামিয়ান (চাঁদের নিচে ফুলঘুম)',
    'hi_IN' => 'युएशिया हुआमियान (चाँद के नीचे फूल निद्रा)',
    'ur_PK' => 'یوئیشیا ہوا میان (چاند کے نیچے پھولوں کی نیند)',
];

$yingciLabel = [
    'en_US' => 'Yingci (Ying Porcelain)',
    'es_ES' => 'Yingci (Porcelana Ying)',
    'fr_FR' => 'Yingci (Porcelaine Ying)',
    'pt_BR' => 'Yingci (Porcelana Ying)',
    'id_ID' => 'Yingci (Porselen Ying)',
    'ar_SA' => 'يينغ تسي (خزف ينغ)',
    'bn_BD' => 'ইয়িংচি (ইয়িং পোর্সেলিন)',
    'hi_IN' => 'यिंग्सी (यिंग पोर्सिलेन)',
    'ur_PK' => 'ینگ سی (ینگ چینی مٹی)',
];

$tanhuaLabel = [
    'en_US' => 'Tanhua (Epiphyllum)',
    'es_ES' => 'Tanhua (Epiphyllum)',
    'fr_FR' => 'Tanhua (Épiphyllum)',
    'pt_BR' => 'Tanhua (Epiphyllum)',
    'id_ID' => 'Tanhua (Epiphyllum)',
    'ar_SA' => 'تان هوا (إبيفيلوم)',
    'bn_BD' => 'তানহুয়া (এপিফাইলাম)',
    'hi_IN' => 'तानहुआ (एपिफ़ाइलम)',
    'ur_PK' => 'تان ہوا (ایپیفائلم)',
];

$caozhouLabel = [
    'en_US' => 'Caozhou rabbit-velvet Ming Hanfu',
    'es_ES' => 'Hanfu Ming de terciopelo de conejo de Caozhou',
    'fr_FR' => 'Hanfu Ming en velours de lapin de Caozhou',
    'pt_BR' => 'Hanfu Ming de veludo de coelho de Caozhou',
    'id_ID' => 'Hanfu Ming beludru kelinci Caozhou',
    'ar_SA' => 'هانفو مينغ من مخمل الأرانب من كاوتشو',
    'bn_BD' => 'কাওঝো খরগোশ-ভেলভেট মিং হানফু',
    'hi_IN' => 'काओझो खरगोश-मखमली मिंग हानफू',
    'ur_PK' => 'کاؤژو خرگوش مخمل منگ ہانفو',
];

$huajiaLabel = [
    'en_US' => 'Huajia bridal cloud-collar set',
    'es_ES' => 'conjunto nupcial Huajia con cuello de nube',
    'fr_FR' => 'ensemble nuptial Huajia à col nuage',
    'pt_BR' => 'conjunto nupcial Huajia com gola nuvem',
    'id_ID' => 'set pengantin Huajia kerah awan',
    'ar_SA' => 'طقم عروس هواجيا بياقة سحاب',
    'bn_BD' => 'হুয়াজিয়া ব্রাইডাল ক্লাউড-কলার সেট',
    'hi_IN' => 'हुआजिया ब्राइडल क्लाउड-कॉलर सेट',
    'ur_PK' => 'ہواجیا بریڈل کلاؤڈ کالر سیٹ',
];

$yujinLabel = [
    'en_US' => 'Yujin (Guyuefang)',
    'es_ES' => 'Yujin (Guyuefang)',
    'fr_FR' => 'Yujin (Guyuefang)',
    'pt_BR' => 'Yujin (Guyuefang)',
    'id_ID' => 'Yujin (Guyuefang)',
    'ar_SA' => 'يوجين (غويويفانگ)',
    'bn_BD' => 'ইউজিন (গুইউয়েফাং)',
    'hi_IN' => 'युजिन (गुयुएफांग)',
    'ur_PK' => 'یوجن (گویوئفانگ)',
];

$guyuefangLabel = [
    'en_US' => 'Guyuefang',
    'es_ES' => 'Guyuefang',
    'fr_FR' => 'Guyuefang',
    'pt_BR' => 'Guyuefang',
    'id_ID' => 'Guyuefang',
    'ar_SA' => 'غويويفانگ',
    'bn_BD' => 'গুইউয়েফাং',
    'hi_IN' => 'गुयुएफांग',
    'ur_PK' => 'گویوئفانگ',
];

$zhuanghuaLabel = [
    'en_US' => 'zhuanghua brocade',
    'es_ES' => 'brocado zhuanghua',
    'fr_FR' => 'brocart zhuanghua',
    'pt_BR' => 'brocado zhuanghua',
    'id_ID' => 'brokat zhuanghua',
    'ar_SA' => 'ديباج تشوانغ هوا',
    'bn_BD' => 'ঝুয়াংহুয়া ব্রোকেড',
    'hi_IN' => 'झुआंगहुआ ब्रोकेड',
    'ur_PK' => 'ژوانگ ہوا بروکیڈ',
];

$zhongguofengLabel = [
    'en_US' => 'Chinese style',
    'es_ES' => 'estilo chino',
    'fr_FR' => 'style chinois',
    'pt_BR' => 'estilo chinês',
    'id_ID' => 'gaya Tionghoa',
    'ar_SA' => 'طراز صيني',
    'bn_BD' => 'চীনা শৈলী',
    'hi_IN' => 'चीनी शैली',
    'ur_PK' => 'چینی طرز',
];

$hunfuLabel = [
    'en_US' => 'wedding Hanfu attire',
    'es_ES' => 'atuendo Hanfu nupcial',
    'fr_FR' => 'tenue Hanfu nuptiale',
    'pt_BR' => 'traje Hanfu nupcial',
    'id_ID' => 'busana Hanfu pernikahan',
    'ar_SA' => 'زي هانفو للزفاف',
    'bn_BD' => 'বিয়ের হানফু পোশাক',
    'hi_IN' => 'विवाह हानफू वस्त्र',
    'ur_PK' => 'شادی ہانفو لباس',
];

$mingzhiLabel = [
    'en_US' => 'Ming-style',
    'es_ES' => 'estilo Ming',
    'fr_FR' => 'style Ming',
    'pt_BR' => 'estilo Ming',
    'id_ID' => 'gaya Ming',
    'ar_SA' => 'طراز مينغ',
    'bn_BD' => 'মিং শৈলী',
    'hi_IN' => 'मिंग शैली',
    'ur_PK' => 'منگ طرز',
];

$tangzhiLabel = [
    'en_US' => 'Tang-style',
    'es_ES' => 'estilo Tang',
    'fr_FR' => 'style Tang',
    'pt_BR' => 'estilo Tang',
    'id_ID' => 'gaya Tang',
    'ar_SA' => 'طراز تانغ',
    'bn_BD' => 'তাং শৈলী',
    'hi_IN' => 'तांग शैली',
    'ur_PK' => 'تانگ طرز',
];

$songzhiLabel = [
    'en_US' => 'Song-style',
    'es_ES' => 'estilo Song',
    'fr_FR' => 'style Song',
    'pt_BR' => 'estilo Song',
    'id_ID' => 'gaya Song',
    'ar_SA' => 'طراز سونغ',
    'bn_BD' => 'সোং শৈলী',
    'hi_IN' => 'सोंग शैली',
    'ur_PK' => 'سونگ طرز',
];

$weijinLabel = [
    'en_US' => 'Wei-Jin style',
    'es_ES' => 'estilo Wei-Jin',
    'fr_FR' => 'style Wei-Jin',
    'pt_BR' => 'estilo Wei-Jin',
    'id_ID' => 'gaya Wei-Jin',
    'ar_SA' => 'طراز وي-جين',
    'bn_BD' => 'ওয়েই-জিন শৈলী',
    'hi_IN' => 'वेई-जिन शैली',
    'ur_PK' => 'وی-جن طرز',
];

$verseAsideLabel = [
    'es_ES' => 'Nota en verso',
    'fr_FR' => 'Aparté en vers',
    'pt_BR' => 'Nota em verso',
    'id_ID' => 'Catatan puisi',
    'ar_SA' => 'هامش شعري',
    'bn_BD' => 'কাব্যিক টীকা',
    'hi_IN' => 'काव्य टिप्पणी',
    'ur_PK' => 'شعری حاشیہ',
];

$htmlLang = [
    'es_ES' => 'es',
    'fr_FR' => 'fr',
    'pt_BR' => 'pt-BR',
    'id_ID' => 'id',
    'ar_SA' => 'ar',
    'bn_BD' => 'bn',
    'hi_IN' => 'hi',
    'ur_PK' => 'ur',
    'en_US' => 'en',
    'zh_Hans_CN' => 'zh-Hans',
];

$zhFullNames = [
    398 => '曹州成人兔绒绒汉服女明制立领',
    414 => '新款原创昙花宋制汉服女仙气',
    193 => '桃花神唐制刺绣诃子裙',
    195 => '玉人歌唐制刺绣齐胸汉服',
    197 => '龙腾魏晋制齐腰交领汉服',
    441 => '白泽正品原创锦织一片式穿孔汉服',
    252 => '月下花眠明制圆领印花马面裙汉服',
    526 => '新款原创重工刺绣花嫁明制汉服云肩',
    250 => '盈瓷明制加绒琵琶袖比甲马面裙汉服',
    374 => '成人汉服妆花马面裙中国风女装',
    445 => '古月坊虞瑾原创正品一片式刺绣襦裙',
    531 => '明制汉服婚服2024年新款中工礼服',
];

$motifTitles = [
    398 => ['曹州成人兔绒绒汉服女明制', '曹州成人兔绒绒汉服女', '曹州', '兔绒绒', '成人立领刺绣马面裙汉服'],
    414 => ['新款原创昙花宋制汉服女仙', '新款原创昙花', '汉服女仙', '昙花', '原创新款春夏渐变汉服'],
    193 => ['桃花神唐制刺绣诃子裙', '桃花神'],
    195 => ['玉人歌唐制刺绣齐胸汉服', '玉人歌'],
    197 => ['龙腾魏晋制齐腰交领汉服', '龙腾'],
    441 => ['白泽正品原创锦织一片式', '一片式魏晋穿孔男女汉服', '白泽'],
    252 => ['月下花眠明制圆领印花马面裙汉服', '月下花眠'],
    526 => ['新款原创重工刺绣花嫁明制', '新款原创重工刺绣花嫁', '花嫁原创新款春秋季云肩', '花嫁'],
    250 => ['盈瓷明制加绒琵琶袖比甲马面裙汉服', '盈瓷'],
    374 => ['成人汉服妆花马面裙中国风', '结婚婚宴成人新款马面裙', '妆花', '中国风'],
    445 => ['古月坊《虞瑾》原创正品一', '古月坊虞瑾原创正品一', '春夏一片式原创日常襦裙', '古月坊', '虞瑾', '原创正品一'],
    531 => ['明制汉服婚服2024年新', '明制汉服婚服', '汉服婚服', '古代情侣款新娘新款汉服', '婚服', '年新'],
];

$bakDir = '/tmp/p-ar-wave15-i18n-bak-' . date('Ymd-His');
@mkdir($bakDir, 0775, true);

$in = implode(',', array_map('intval', $productIds));
$rows = $pdo->query("
    SELECT product_id, local_code, coalesce(name,'') AS name,
           coalesce(short_description,'') AS short_description,
           coalesce(description,'') AS description,
           coalesce(meta_name,'') AS meta_name,
           coalesce(meta_description,'') AS meta_description
    FROM w_weline_product_local
    WHERE product_id IN ({$in})
")->fetchAll(PDO::FETCH_ASSOC);

$by = [];
foreach ($rows as $r) {
    $by[(int)$r['product_id']][$r['local_code']] = $r;
}

file_put_contents($bakDir . '/product_local.json', json_encode($by, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "backup={$bakDir}/product_local.json\n";
echo "apply=" . ($apply ? '1' : '0') . "\n";
echo "enabled=" . json_encode(array_merge([''], $enabledLocales), JSON_UNESCAPED_UNICODE) . "\n";

/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);

$stats = ['desc_fixed' => 0, 'zh_name_filled' => 0, 'verse_fixed' => 0, 'products' => []];

foreach ($productIds as $pid) {
    $locals = $by[$pid] ?? [];
    $zh = $locals['zh_Hans_CN'] ?? null;
    if (!$zh) {
        echo "MISS zh row pid={$pid}\n";
        continue;
    }

    $zhVariants = $motifTitles[$pid] ?? [];
    if (preg_match('/<h3>([^<]+)<\/h3>/u', (string)$zh['description'], $m)) {
        $zhVariants[] = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (trim((string)$zh['name']) !== '') {
        $zhVariants[] = trim((string)$zh['name']);
    }
    if (trim((string)$zh['short_description']) !== '') {
        $zhVariants[] = trim((string)$zh['short_description']);
    }
    if (isset($zhFullNames[$pid])) {
        $zhVariants[] = $zhFullNames[$pid];
    }
    foreach ($locals as $loc => $row) {
        if ($loc === 'zh_Hans_CN') {
            continue;
        }
        if (preg_match_all('/[\x{4e00}-\x{9fff}]{2,}/u', (string)$row['description'], $mm)) {
            foreach ($mm[0] as $s) {
                if (!in_array($s, [
                    '形制', '本店', '花朝记', '织金', '新款', '正品', '花嫁', '汉服', '成人',
                    '明制', '唐制', '宋制', '魏晋制', '桃花神', '玉人歌', '龙腾', '白泽',
                    '月下花眠', '盈瓷', '昙花', '曹州', '兔绒绒', '妆花', '中国风',
                    '古月坊', '虞瑾', '婚服', '年新', '原创', '重工刺绣', '汉服女仙',
                    '汉服婚服', '原创正品一',
                ], true)) {
                    $zhVariants[] = $s;
                }
            }
        }
    }
    $zhVariants = array_values(array_unique(array_filter($zhVariants)));
    usort($zhVariants, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));

    $zhName = trim((string)$zh['name']);
    $zhShort = trim((string)$zh['short_description']);
    if ($zhName === '') {
        $zhName = $zhFullNames[$pid] ?? ($zhShort !== '' ? $zhShort : ($zhVariants[0] ?? ''));
        if ($zhName !== '') {
            echo "FILL zh name pid={$pid} => {$zhName}\n";
            $stats['zh_name_filled']++;
            if ($apply) {
                LocalDescription::upsertQuiet($pid, 'zh_Hans_CN', [
                    LocalDescription::schema_fields_NAME => $zhName,
                ]);
                $attributes->writeExplicit($websiteId, 0, 'product', $pid, 'name', 'zh_Hans_CN', $zhName, true);
            }
        }
    }

    foreach ($enabledLocales as $loc) {
        if ($loc === 'zh_Hans_CN') {
            continue;
        }
        $row = $locals[$loc] ?? null;
        if (!$row) {
            echo "MISS locale row pid={$pid} loc={$loc}\n";
            continue;
        }
        $desc = (string)$row['description'];
        if ($desc === '') {
            continue;
        }
        $display = trim((string)$row['short_description']);
        if ($display === '') {
            $display = trim((string)$row['name']);
        }
        if ($display === '') {
            $display = $zhShort !== '' ? $zhShort : $zhName;
        }
        if (mb_strlen($display) > 80) {
            $display = mb_substr($display, 0, 80);
        }

        $orig = $desc;
        $shop = $shopLabel[$loc] ?? 'This shop';
        $sil = $silhouetteLabel[$loc] ?? 'silhouette';
        $brand = $brandLabel[$loc] ?? 'Huazhaoji';
        $taohua = $taohuashenLabel[$loc] ?? 'Taohuashen';
        $yuren = $yurengeLabel[$loc] ?? 'Yurenge';
        $longteng = $longtengLabel[$loc] ?? 'Longteng';
        $baize = $baizeLabel[$loc] ?? 'Baize';
        $yuexia = $yuexiaLabel[$loc] ?? 'Yuexia Huamian';
        $yingci = $yingciLabel[$loc] ?? 'Yingci';
        $tanhua = $tanhuaLabel[$loc] ?? 'Tanhua';
        $caozhou = $caozhouLabel[$loc] ?? 'Caozhou';
        $huajia = $huajiaLabel[$loc] ?? 'Huajia bridal';
        $yujin = $yujinLabel[$loc] ?? 'Yujin';
        $guyue = $guyuefangLabel[$loc] ?? 'Guyuefang';
        $zhuanghua = $zhuanghuaLabel[$loc] ?? 'zhuanghua brocade';
        $zhongguofeng = $zhongguofengLabel[$loc] ?? 'Chinese style';
        $hunfu = $hunfuLabel[$loc] ?? 'wedding attire';
        $ming = $mingzhiLabel[$loc] ?? 'Ming-style';
        $tang = $tangzhiLabel[$loc] ?? 'Tang-style';
        $song = $songzhiLabel[$loc] ?? 'Song-style';
        $weijin = $weijinLabel[$loc] ?? 'Wei-Jin style';

        foreach ($zhVariants as $variant) {
            if ($variant === '' || mb_strlen($variant) < 2) {
                continue;
            }
            if (str_contains($desc, $variant)) {
                $repl = $display;
                if ($variant === '桃花神' || str_contains($variant, '桃花神')) {
                    $repl = $taohua;
                } elseif ($variant === '玉人歌' || str_contains($variant, '玉人歌')) {
                    $repl = $yuren;
                } elseif ($variant === '龙腾' || str_contains($variant, '龙腾')) {
                    $repl = $longteng;
                } elseif ($variant === '白泽' || str_contains($variant, '白泽')) {
                    $repl = $baize;
                } elseif ($variant === '月下花眠' || str_contains($variant, '月下花眠')) {
                    $repl = $yuexia;
                } elseif ($variant === '盈瓷' || str_contains($variant, '盈瓷')) {
                    $repl = $yingci;
                } elseif ($variant === '昙花' || str_contains($variant, '昙花')) {
                    $repl = $tanhua;
                } elseif ($variant === '曹州' || str_contains($variant, '曹州') || str_contains($variant, '兔绒绒')) {
                    $repl = $caozhou;
                } elseif ($variant === '花嫁' || str_contains($variant, '花嫁')) {
                    $repl = $huajia;
                } elseif ($variant === '虞瑾' || str_contains($variant, '虞瑾') || str_contains($variant, '古月坊')) {
                    $repl = $yujin;
                } elseif ($variant === '妆花') {
                    $repl = $zhuanghua;
                } elseif ($variant === '中国风') {
                    $repl = $zhongguofeng;
                } elseif ($variant === '婚服' || str_contains($variant, '婚服')) {
                    $repl = $hunfu;
                } elseif ($variant === '明制' || $variant === '明制汉服') {
                    $repl = $ming;
                } elseif ($variant === '唐制') {
                    $repl = $tang;
                } elseif ($variant === '宋制') {
                    $repl = $song;
                } elseif ($variant === '魏晋制') {
                    $repl = $weijin;
                } elseif ($variant === '年新') {
                    $repl = '2024 new';
                }
                $desc = str_replace($variant, $repl, $desc);
            }
        }

        $desc = str_replace(
            [
                '花朝记', '桃花神', '玉人歌', '龙腾', '白泽', '月下花眠', '盈瓷', '昙花', '曹州', '兔绒绒',
                '花嫁', '虞瑾', '古月坊', '妆花', '中国风', '婚服', '明制', '唐制', '宋制', '魏晋制',
                '本店', '形制', '正品', '织金', '新款', '汉服', '成人', '春夏季', '原创', '重工刺绣', '年新',
                '汉服女仙', '原创正品一', '汉服婚服',
            ],
            [
                $brand, $taohua, $yuren, $longteng, $baize, $yuexia, $yingci, $tanhua, $caozhou, 'rabbit velvet',
                $huajia, $yujin, $guyue, $zhuanghua, $zhongguofeng, $hunfu, $ming, $tang, $song, $weijin,
                $shop, $sil, 'authentic', 'gold-woven', 'new', 'Hanfu', 'adult', 'spring-summer', 'original', 'heavy embroidery', '2024 new',
                'immortal Hanfu', 'original authentic piece', $hunfu,
            ],
            $desc
        );
        if (in_array($loc, ['ar_SA', 'bn_BD', 'hi_IN', 'ur_PK', 'es_ES', 'fr_FR', 'pt_BR', 'id_ID'], true)) {
            $lex = [
                'ar_SA' => [
                    'authentic' => 'أصلي', 'gold-woven' => 'نسيج ذهبي', 'new' => 'جديد', 'Hanfu' => 'هانفو',
                    'adult' => 'للبالغين', 'spring-summer' => 'ربيع-صيف', 'original' => 'أصلي',
                    'heavy embroidery' => 'تطريز كثيف', '2024 new' => 'جديد 2024',
                    'rabbit velvet' => 'مخمل الأرانب', 'immortal Hanfu' => 'هانفو خيالي',
                    'original authentic piece' => 'قطعة أصلية',
                ],
                'bn_BD' => [
                    'authentic' => 'অরিজিনাল', 'gold-woven' => 'স্বর্ণবোনা', 'new' => 'নতুন', 'Hanfu' => 'হানফু',
                    'adult' => 'প্রাপ্তবয়স্ক', 'spring-summer' => 'বসন্ত-গ্রীষ্ম', 'original' => 'মূল',
                    'heavy embroidery' => 'ভারী সূচিকর্ম', '2024 new' => '২০২৪ নতুন',
                    'rabbit velvet' => 'খরগোশ ভেলভেট', 'immortal Hanfu' => 'পরী হানফু',
                    'original authentic piece' => 'মূল আসল টুকরো',
                ],
                'hi_IN' => [
                    'authentic' => 'प्रामाणिक', 'gold-woven' => 'स्वर्ण-बुना', 'new' => 'नया', 'Hanfu' => 'हानफू',
                    'adult' => 'वयस्क', 'spring-summer' => 'वसंत-ग्रीष्म', 'original' => 'मूल',
                    'heavy embroidery' => 'भारी कढ़ाई', '2024 new' => '2024 नया',
                    'rabbit velvet' => 'खरगोश मखमल', 'immortal Hanfu' => 'अप्सरा हानफू',
                    'original authentic piece' => 'मूल प्रामाणिक टुकड़ा',
                ],
                'ur_PK' => [
                    'authentic' => 'اصلی', 'gold-woven' => 'سونے کی بنائی', 'new' => 'نیا', 'Hanfu' => 'ہانفو',
                    'adult' => 'بالغ', 'spring-summer' => 'بہار-گرمی', 'original' => 'اصل',
                    'heavy embroidery' => 'بھاری کڑھائی', '2024 new' => '2024 نیا',
                    'rabbit velvet' => 'خرگوش مخمل', 'immortal Hanfu' => 'پری ہانفو',
                    'original authentic piece' => 'اصل مستند ٹکڑا',
                ],
                'es_ES' => [
                    'authentic' => 'auténtico', 'gold-woven' => 'tejido dorado', 'new' => 'nuevo', 'Hanfu' => 'Hanfu',
                    'adult' => 'adulto', 'spring-summer' => 'primavera-verano', 'original' => 'original',
                    'heavy embroidery' => 'bordado elaborado', '2024 new' => 'nuevo 2024',
                    'rabbit velvet' => 'terciopelo de conejo', 'immortal Hanfu' => 'Hanfu de hada',
                    'original authentic piece' => 'pieza original auténtica',
                ],
                'fr_FR' => [
                    'authentic' => 'authentique', 'gold-woven' => 'tissé or', 'new' => 'nouveau', 'Hanfu' => 'Hanfu',
                    'adult' => 'adulte', 'spring-summer' => 'printemps-été', 'original' => 'original',
                    'heavy embroidery' => 'broderie dense', '2024 new' => 'nouveau 2024',
                    'rabbit velvet' => 'velours de lapin', 'immortal Hanfu' => 'Hanfu féerique',
                    'original authentic piece' => 'pièce originale authentique',
                ],
                'id_ID' => [
                    'authentic' => 'asli', 'gold-woven' => 'tenun emas', 'new' => 'baru', 'Hanfu' => 'Hanfu',
                    'adult' => 'dewasa', 'spring-summer' => 'musim semi-panas', 'original' => 'asli',
                    'heavy embroidery' => 'sulaman padat', '2024 new' => 'baru 2024',
                    'rabbit velvet' => 'beludru kelinci', 'immortal Hanfu' => 'Hanfu peri',
                    'original authentic piece' => 'potongan asli autentik',
                ],
                'pt_BR' => [
                    'authentic' => 'autêntico', 'gold-woven' => 'tecido dourado', 'new' => 'novo', 'Hanfu' => 'Hanfu',
                    'adult' => 'adulto', 'spring-summer' => 'primavera-verão', 'original' => 'original',
                    'heavy embroidery' => 'bordado elaborado', '2024 new' => 'novo 2024',
                    'rabbit velvet' => 'veludo de coelho', 'immortal Hanfu' => 'Hanfu de fada',
                    'original authentic piece' => 'peça original autêntica',
                ],
            ];
            if (isset($lex[$loc])) {
                $desc = str_replace(array_keys($lex[$loc]), array_values($lex[$loc]), $desc);
            }
        }

        $desc = preg_replace_callback(
            '/[\x{4e00}-\x{9fff}]{2,}/u',
            static function (array $m) use (
                $display, $sil, $shop, $brand, $taohua, $yuren, $longteng, $baize, $yuexia, $yingci,
                $tanhua, $caozhou, $huajia, $yujin, $guyue, $zhuanghua, $zhongguofeng, $hunfu,
                $ming, $tang, $song, $weijin
            ): string {
                $s = $m[0];
                return match ($s) {
                    '形制' => $sil,
                    '本店' => $shop,
                    '花朝记' => $brand,
                    '桃花神' => $taohua,
                    '玉人歌' => $yuren,
                    '龙腾' => $longteng,
                    '白泽' => $baize,
                    '月下花眠' => $yuexia,
                    '盈瓷' => $yingci,
                    '昙花' => $tanhua,
                    '曹州' => $caozhou,
                    '花嫁' => $huajia,
                    '虞瑾' => $yujin,
                    '古月坊' => $guyue,
                    '妆花' => $zhuanghua,
                    '中国风' => $zhongguofeng,
                    '婚服' => $hunfu,
                    '明制' => $ming,
                    '唐制' => $tang,
                    '宋制' => $song,
                    '魏晋制' => $weijin,
                    '年新' => '2024 new',
                    default => $display,
                };
            },
            $desc
        ) ?? $desc;

        $desc = str_replace(
            [
                '本店', '形制', '花朝记', '桃花神', '玉人歌', '龙腾', '白泽', '月下花眠', '盈瓷', '昙花',
                '曹州', '花嫁', '虞瑾', '古月坊', '妆花', '中国风', '婚服', '明制', '唐制', '宋制', '魏晋制',
            ],
            [
                $shop, $sil, $brand, $taohua, $yuren, $longteng, $baize, $yuexia, $yingci, $tanhua,
                $caozhou, $huajia, $yujin, $guyue, $zhuanghua, $zhongguofeng, $hunfu, $ming, $tang, $song, $weijin,
            ],
            $desc
        );

        $desc = preg_replace_callback(
            '/[\x{4e00}-\x{9fff}]/u',
            static function (array $m): string {
                return '';
            },
            $desc
        ) ?? $desc;
        $desc = preg_replace('/[«「""][»」""]/u', '', $desc) ?? $desc;
        $desc = preg_replace('/\s{2,}/u', ' ', $desc) ?? $desc;

        if ($loc !== 'en_US' && isset($verseAsideLabel[$loc]) && str_contains($desc, 'Verse aside')) {
            $desc = str_replace('Verse aside', $verseAsideLabel[$loc], $desc);
            $stats['verse_fixed']++;
        }

        if ($loc !== 'en_US' && isset($htmlLang[$loc])) {
            $lang = $htmlLang[$loc];
            $desc = preg_replace(
                '/(<aside[^>]*class=["\'][^"\']*verse[^"\']*["\'][^>]*\slang=["\'])en(?:-US)?(["\'])/iu',
                '$1' . $lang . '$2',
                $desc
            ) ?? $desc;
            $desc = preg_replace(
                '/(<aside[^>]*\slang=["\'])en(?:-US)?(["\'][^>]*class=["\'][^"\']*verse)/iu',
                '$1' . $lang . '$2',
                $desc
            ) ?? $desc;
            $desc = preg_replace(
                '/(class=["\'][^"\']*verse[^"\']*["\'][^>]*\slang=["\'])zh(?:-Hans)?(["\'])/iu',
                '$1' . $lang . '$2',
                $desc
            ) ?? $desc;
            $desc = preg_replace(
                '/(\slang=["\'])zh(?:-Hans)?(["\'][^>]*class=["\'][^"\']*verse)/iu',
                '$1' . $lang . '$2',
                $desc
            ) ?? $desc;
            $desc = preg_replace(
                '/(weline-detail-prose--verse[^"\']*["\'][^>]*\slang=["\'])zh(?:-Hans)?(["\'])/iu',
                '$1' . $lang . '$2',
                $desc
            ) ?? $desc;
        }
        if ($loc === 'en_US') {
            $desc = preg_replace(
                '/(weline-detail-prose--verse[^"\']*["\'][^>]*\slang=["\'])zh(?:-Hans)?(["\'])/iu',
                '$1en$2',
                $desc
            ) ?? $desc;
            $desc = preg_replace(
                '/(\slang=["\'])zh(?:-Hans)?(["\'][^>]*class=["\'][^"\']*verse)/iu',
                '$1en$2',
                $desc
            ) ?? $desc;
        }

        $desc = preg_replace('/(·\s*){2,}/u', ' · ', $desc) ?? $desc;
        $desc = preg_replace('/[「「]{2,}/u', '「', $desc) ?? $desc;
        $desc = preg_replace('/[“「]{2,}/u', '“', $desc) ?? $desc;

        if ($desc === $orig) {
            continue;
        }

        $cjkLeft = preg_match_all('/[\x{4e00}-\x{9fff}]/u', $desc);
        echo "FIX desc pid={$pid} loc={$loc} cjk_left={$cjkLeft} delta_len=" . (strlen($desc) - strlen($orig)) . "\n";
        $stats['desc_fixed']++;
        $stats['products'][$pid] = ($stats['products'][$pid] ?? 0) + 1;

        if ($apply) {
            LocalDescription::upsertQuiet($pid, $loc, [
                LocalDescription::schema_fields_DESCRIPTION => $desc,
            ]);
            $attributes->writeExplicit($websiteId, 0, 'product', $pid, 'description', $loc, $desc, true);
            if (str_contains($desc, 'data-weds') && strlen($desc) > 500) {
                $pdo->prepare(
                    "DELETE FROM w_product_ws_0_attribute_value
                     WHERE entity_id=? AND attribute_code='description' AND store_id=0 AND locale=?
                       AND length(COALESCE(value_text,'')) < 200
                       AND COALESCE(value_text,'') NOT LIKE '%data-weds%'"
                )->execute([(string)$pid, $loc]);
            }
        }
    }

    if ($apply) {
        ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)->notifyCatalogChanged(
            $websiteId,
            'i18n_wave15_fix_' . $pid,
            ['product_ids' => [$pid]],
        );
        ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class)
            ->clearForCatalogChange('i18n_wave15_fix_' . $pid);
    }
}

echo "SUMMARY " . json_encode($stats, JSON_UNESCAPED_UNICODE) . "\n";
