<?php

declare(strict_types=1);

use Weline\FileManager\Api\Data\FileAccessContext;
use Weline\FileManager\Api\FileAssetLibraryInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Model\Product\LocalDescription;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Storage\Api\Data\StorageDiskCode;

$_SERVER['WELINE_WEBSITE_ID'] = '0';

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$root = '/tmp/p-img-opt-wave23-20260919';
$bakedAsset = 'd170f95d-f057-48f3-990c-a280a85149f4';
$modelAsset = '94fcb4ae-dca7-432c-a7c0-66c694aa95eb';
$photoSizes = [
    '76bc4f18-8d3a-4f4a-b98b-f7b6d65cc7f4' => [864, 1152],
    'b382ac7a-02c2-4541-813e-12b0a3181e5f' => [864, 1152],
    $modelAsset => [864, 1152],
    'a742e218-7812-408a-9b23-7042a46288c6' => [864, 1152],
    '7e52d7f8-6e2b-49a6-850f-9f12eda2dd4a' => [864, 1152],
    '668334af-6267-43a0-a816-ee7cdb90916a' => [1152, 864],
    '5108c837-a6ce-4da0-8f31-47ada741a3af' => [864, 1152],
];

$env = include dirname(__DIR__, 5) . '/app/etc/env.php';
$db = $env['db']['master'];
$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s', $db['hostname'], $db['hostport'], $db['database']),
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$likeAssets = array_merge([$bakedAsset], array_keys($photoSizes));
$where = [];
$params = [];
foreach ($likeAssets as $i => $asset) {
    $where[] = 'description LIKE :a' . $i;
    $params['a' . $i] = '%' . $asset . '%';
}
$sql = 'SELECT product_id, local_code, description FROM w_weline_product_local WHERE ' . implode(' OR ', $where);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$localRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$eavWhere = [];
$eavParams = [];
foreach ($likeAssets as $i => $asset) {
    $eavWhere[] = 'value_string LIKE :a' . $i;
    $eavParams['a' . $i] = '%' . $asset . '%';
}
$eavSql = "SELECT entity_id, locale, value_string FROM w_product_ws_0_attribute_value WHERE entity_type = 'product' AND attribute_code = 'description' AND store_id = 0 AND (" . implode(' OR ', $eavWhere) . ')';
$eavStmt = $pdo->prepare($eavSql);
$eavStmt->execute($eavParams);
$eavRows = $eavStmt->fetchAll(PDO::FETCH_ASSOC);

if ($localRows === [] || $eavRows === []) {
    fwrite(STDERR, "no description rows\n");
    exit(1);
}

$copy = wave23Copy();
$changedLocal = [];
foreach ($localRows as $row) {
    $next = wave23Transform((string)$row['description'], (string)$row['local_code'], $bakedAsset, $modelAsset, $photoSizes, $copy);
    if ($next === (string)$row['description']) {
        fwrite(STDERR, "local unchanged {$row['product_id']} {$row['local_code']}\n");
        exit(1);
    }
    $changedLocal[] = [
        'product_id' => (int)$row['product_id'],
        'local_code' => (string)$row['local_code'],
        'before' => (string)$row['description'],
        'after' => $next,
    ];
}
$changedEav = [];
foreach ($eavRows as $row) {
    $next = wave23Transform((string)$row['value_string'], (string)$row['locale'], $bakedAsset, $modelAsset, $photoSizes, $copy);
    if ($next === (string)$row['value_string']) {
        fwrite(STDERR, "eav unchanged {$row['entity_id']} {$row['locale']}\n");
        exit(1);
    }
    $changedEav[] = [
        'product_id' => (int)$row['entity_id'],
        'locale' => (string)$row['locale'],
        'before' => (string)$row['value_string'],
        'after' => $next,
    ];
}

$stamp = date('Ymd-His');
$bakDir = $root . '/bak/html-' . $stamp;
if (is_dir($bakDir)) {
    fwrite(STDERR, "bak exists {$bakDir}\n");
    exit(1);
}
mkdir($bakDir, 0755, true);
$sums = '';
foreach ($changedLocal as $row) {
    $name = $row['product_id'] . '__' . ($row['local_code'] === '' ? '_empty' : $row['local_code']) . '.html';
    $path = $bakDir . '/' . $name;
    file_put_contents($path, $row['before']);
    $sums .= hash_file('sha256', $path) . '  ' . $name . "\n";
}
file_put_contents($bakDir . '/SHA256SUMS', $sums);
echo "html backup {$bakDir} files=" . count($changedLocal) . "\n";

$library = ObjectManager::getInstance(FileAssetLibraryInterface::class);
$access = new FileAccessContext(ScopeIdentity::global(), 'zh_Hans_CN', null, ['catalog_maintenance'], 'metadata_edit');
$disk = StorageDiskCode::BUILTIN_LOCAL_MEDIA;
$done = $root . '/done.tsv';
$jobs = [
    [
        'src' => $root . '/out/443_detail-02-993a13926c29.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/708123722007/detail-02-993a13926c29.jpg',
        'expect_id' => '5108c837-a6ce-4da0-8f31-47ada741a3af',
        'product_id' => '443',
        'before' => '570x884',
        'src_bpp' => 1.073,
    ],
    [
        'src' => $root . '/out/433_detail-07-41a417af044f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-mengran/705193029653/detail-07-41a417af044f.jpg',
        'expect_id' => '668334af-6267-43a0-a816-ee7cdb90916a',
        'product_id' => '433',
        'before' => '750x601',
        'src_bpp' => 1.088,
    ],
    [
        'src' => $root . '/out/408_detail-06-cf310057fea5.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/703151897208/detail-06-cf310057fea5.jpg',
        'expect_id' => '7e52d7f8-6e2b-49a6-850f-9f12eda2dd4a',
        'product_id' => '408',
        'before' => '631x800',
        'src_bpp' => 0.985,
    ],
    [
        'src' => $root . '/out/330_detail-02-9e8ebad6e19f.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-caibao/702862148463/detail-02-9e8ebad6e19f.jpg',
        'expect_id' => 'a742e218-7812-408a-9b23-7042a46288c6',
        'product_id' => '330',
        'before' => '608x788',
        'src_bpp' => 0.979,
    ],
    [
        'src' => $root . '/out/260_detail-04-bab08f651f99.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/1042443716530/detail-04-bab08f651f99.jpg',
        'expect_id' => '94fcb4ae-dca7-432c-a7c0-66c694aa95eb',
        'product_id' => '260',
        'before' => '498x1173',
        'src_bpp' => 0.962,
    ],
    [
        'src' => $root . '/out/208_detail-01-0a47ae8c05b0.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/895489782952/detail-01-0a47ae8c05b0.jpg',
        'expect_id' => '76bc4f18-8d3a-4f4a-b98b-f7b6d65cc7f4',
        'product_id' => '208',
        'before' => '608x800',
        'src_bpp' => 1.101,
    ],
    [
        'src' => $root . '/out/208_detail-03-aae82a50cd01.jpg',
        'object_key' => 'catalog/hanfu/1688/factory-huazhaoji-cx/895489782952/detail-03-aae82a50cd01.jpg',
        'expect_id' => 'b382ac7a-02c2-4541-813e-12b0a3181e5f',
        'product_id' => '208',
        'before' => '608x800',
        'src_bpp' => 1.1,
    ],
];

$fh = fopen($done, 'w');
fwrite($fh, "product_id\tasset_id\tobject_key\twhy\tbefore_wxh\tafter_wxh\toutpaint\tempty_upscale\n");
fwrite($fh, "192\t{$bakedAsset}\tcatalog/hanfu/1688/factory-huazhaoji-cx/831923117968/detail-02-08cac0deb4ea.jpg\tbaked_html\t570x896\thtml\t否\t否\n");

foreach ($jobs as $j) {
    $path = $j['src'];
    if (!is_file($path)) {
        fwrite(STDERR, "missing {$path}\n");
        exit(1);
    }
    $size = getimagesize($path);
    if (!is_array($size)) {
        fwrite(STDERR, "bad {$path}\n");
        exit(1);
    }
    $w = (int)$size[0];
    $h = (int)$size[1];
    $bpp = (filesize($path) * 8) / ($w * $h);
    if ($bpp < 1.2 || $bpp <= (float)$j['src_bpp'] || min($w, $h) < 800) {
        fwrite(STDERR, "gate fail {$path}: {$w}x{$h} bpp={$bpp}\n");
        exit(1);
    }
    $basename = basename($j['object_key']);
    $stream = fopen($path, 'rb');
    try {
        $desc = $library->replaceContent(
            $disk,
            $j['object_key'],
            $stream,
            $basename,
            'image/jpeg',
            'zh_Hans_CN',
            $access,
            $w,
            $h,
        );
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }
    $aid = (string)($desc['asset_id'] ?? '');
    if ($aid !== '' && $aid !== $j['expect_id']) {
        fwrite(STDERR, "id drift {$basename}: expect {$j['expect_id']} got {$aid}\n");
        exit(1);
    }
    fwrite($fh, "{$j['product_id']}\t{$j['expect_id']}\t{$j['object_key']}\tsoft\t{$j['before']}\t{$w}x{$h}\t否\t否\n");
    echo "OK #{$j['product_id']} {$basename} {$j['before']} -> {$w}x{$h} bpp=" . round($bpp, 3) . "\n";
}
fclose($fh);

$updLocal = $pdo->prepare('UPDATE w_weline_product_local SET description = :d WHERE product_id = :p AND local_code = :l');
$pdo->beginTransaction();
try {
    foreach ($changedLocal as $row) {
        $updLocal->execute([
            'd' => $row['after'],
            'p' => $row['product_id'],
            'l' => $row['local_code'],
        ]);
        if ($updLocal->rowCount() !== 1) {
            throw new RuntimeException('local update ' . $row['product_id'] . ' ' . $row['local_code']);
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "local html fail: {$e->getMessage()}\n");
    exit(1);
}

/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);
$byProduct = [];
foreach ($changedEav as $row) {
    $byProduct[$row['product_id']][] = $row;
}
foreach ($byProduct as $productId => $rows) {
    $attributes->mutateProductAttributes(0, (int)$productId, 0, function () use ($attributes, $productId, $rows): void {
        foreach ($rows as $row) {
            $attributes->writeTyped(0, 0, 'product', (int)$productId, 'description', $row['locale'], 'string', $row['after'], false);
        }
    });
    echo "eav #{$productId} locales=" . count($rows) . "\n";
}

$left = $pdo->prepare('SELECT count(*) FROM w_weline_product_local WHERE description LIKE :a');
$left->execute(['a' => '%' . $bakedAsset . '%']);
$remain = (int)$left->fetchColumn();
if ($remain !== 0) {
    fwrite(STDERR, "baked asset still in local html: {$remain}\n");
    exit(1);
}
echo "done baked_removed=1 photos=7\n";

/**
 * @param array<string, array{0:string,1:string,2:list<string>,3:string,4:string}> $copy
 * @param array<string, array{0:int,1:int}> $photoSizes
 */
function wave23Transform(string $html, string $locale, string $bakedAsset, string $modelAsset, array $photoSizes, array $copy): string
{
    if (str_contains($html, $bakedAsset)) {
        $key = $locale === '' ? 'en_US' : $locale;
        if (!isset($copy[$key])) {
            throw new RuntimeException('missing copy ' . $locale);
        }
        [$lang, $title, $lines, $a, $b] = $copy[$key];
        $block = wave23Block($lang, $title, $lines, $a, $b);
        $re = '#<div class="weline-detail-figure-stack weline-detail-figure-stack--caption"><div class="weline-detail-figure-row weline-detail-figure-row--solo"><div class="weline-detail-figure"><img src="asset://' . preg_quote($bakedAsset, '#') . '"[^>]*></div></div></div>#';
        $n = 0;
        $html = preg_replace($re, $block, $html, 1, $n) ?? $html;
        if ($n !== 1 || str_contains($html, $bakedAsset)) {
            throw new RuntimeException('baked replace fail ' . $locale);
        }
    }
    if (str_contains($html, $modelAsset) && !str_contains($html, 'data-weline-detail-text="model-fit"')) {
        $note = $locale === 'zh_Hans_CN'
            ? '<div class="weline-detail-text weline-detail-text--product-info" data-weline-detail-text="model-fit"><h3>模特展示</h3><table><tbody><tr><th>模特</th><td>阿护</td></tr><tr><th>身高</th><td>157 cm</td></tr><tr><th>体重</th><td>45 kg</td></tr><tr><th>样衣</th><td>M 码</td></tr></tbody></table><p>试穿较为宽大，衣长偏长。请根据自身喜好选择合适尺码。</p></div>'
            : '<div class="weline-detail-text weline-detail-text--product-info" data-weline-detail-text="model-fit"><h3>Model</h3><table><tbody><tr><th>Model</th><td>Ahu</td></tr><tr><th>Height</th><td>157 cm</td></tr><tr><th>Weight</th><td>45 kg</td></tr><tr><th>Sample</th><td>M</td></tr></tbody></table><p>The sample wears loose and the hem runs long. Choose the size you prefer.</p></div>';
        $token = '<img src="asset://' . $modelAsset . '"';
        $count = substr_count($html, $token);
        if ($count !== 1) {
            throw new RuntimeException('model img count ' . $count . ' ' . $locale);
        }
        $html = str_replace($token, $note . $token, $html);
    }
    foreach ($photoSizes as $asset => [$w, $h]) {
        if (!str_contains($html, $asset)) {
            continue;
        }
        $re = '#(<img src="asset://' . preg_quote($asset, '#') . '"[^>]*?)\swidth="\d+"\sheight="\d+"#';
        $n = 0;
        $html = preg_replace($re, '$1 width="' . $w . '" height="' . $h . '"', $html, -1, $n) ?? $html;
        if ($n < 1) {
            throw new RuntimeException('size attr fail ' . $asset . ' ' . $locale);
        }
    }

    return $html;
}

/**
 * @param list<string> $lines
 */
function wave23Block(string $lang, string $title, array $lines, string $a, string $b): string
{
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $poem = '';
    foreach ($lines as $line) {
        $poem .= '<p>' . $h($line) . '</p>';
    }

    return '<div class="weline-detail-text weline-detail-text--copy" data-weline-detail-text="design-inspiration"><h3>'
        . $h($title) . '</h3><p class="weline-detail-feature__note">SHE JI LING GAN</p><div class="weline-detail-prose weline-detail-prose--verse" lang="'
        . $h($lang) . '">' . $poem . '</div><p>' . $h($a) . '</p><p>' . $h($b) . '</p></div>';
}

/**
 * @return array<string, array{0:string,1:string,2:list<string>,3:string,4:string}>
 */
function wave23Copy(): array
{
    $en = [
        'en',
        'Design inspiration',
        [
            'The lacquered scented carriage will not be met again.',
            'Gorge clouds leave no trace, free to drift east or west.',
            'Pear blossoms fill the courtyard under a brimming moon.',
            'Willow catkins cross the pond on a faint breeze.',
        ],
        'Soft as catkin, clouds light as silk crowd the bright moon as it slowly rises. Clear light rings the scene in a colored halo, now deep, now pale, almost there and then gone. Fine bright threads glitter like ripples under the moonlight.',
        'The embroidery is dignified and stately. Embroidered floating pieces refine the finish, and the overall palette is gentle, elegant, and poised.',
    ];
    $enGb = $en;
    $enGb[0] = 'en-GB';
    $enGb[3] = 'Soft as catkin, clouds light as silk crowd the bright moon as it slowly rises. Clear light rings the scene in a coloured halo, now deep, now pale, almost there and then gone. Fine bright threads glitter like ripples under the moonlight.';

    return [
        'zh_Hans_CN' => [
            'zh-Hans',
            '设计灵感',
            ['油壁香车不再逢', '峡云无迹任西东', '梨花院落溶溶月', '柳絮池塘淡淡风'],
            '柔和似絮，轻盈如绢的浮云，簇拥着盈盈的皓月冉冉上升。清辉把周围映成一轮彩色的光圈，有深而浅，若有若无。浅浅亮丝在月光下显得波光粼粼。',
            '绣花工艺，端庄大气。再加上绣花飘片，精致升级，整体配色温柔、优雅、知性。',
        ],
        'en_US' => $en,
        'en_GB' => $enGb,
        'ar_SA' => [
            'ar',
            'إلهام التصميم',
            [
                'لن نلتقي ثانية تلك العربة المطيّبة ذات الجوانب المطلية.',
                'غيوم الوادي تمضي بلا أثر، حرّة بين الشرق والغرب.',
                'أزهار الكمثرى تملأ الفناء تحت قمر ممتلئ.',
                'زغب الصفصاف يعبر البركة مع نسيم خفيف.',
            ],
            'ناعمة كزغب الصفصاف، غيوم خفيفة كالحرير تحيط بالقمر الساطع وهو يصعد بتمهّل. يطوّق الضياء الصافي المشهد بهالة ملوّنة، عميقة تارة وشاحبة تارة، تكاد تُرى ثم تغيب. خيوط لامعة رفيعة تتلألأ كتموّج الماء تحت ضوء القمر.',
            'التطريز وقور رصين. ومع قطع التطريز العائمة ترتقي اللمسة، ولوحة الألوان في جملتها لطيفة وأنيقة وهادئة.',
        ],
        'bn_BD' => [
            'bn',
            'নকশার অনুপ্রেরণা',
            [
                'রঙিন সুগন্ধি রথ আর দেখা হবে না।',
                'গিরিখাতের মেঘ চিহ্নহীন, পূর্ব বা পশ্চিমে মুক্ত।',
                'নাশপাতি ফুলে ভরা উঠোন, পূর্ণ চাঁদ।',
                'উইলোর তুলো পুকুর পেরোয় মৃদু হাওয়ায়।',
            ],
            'তুলোর মতো নরম, রেশমের মতো হালকা মেঘ উজ্জ্বল চাঁদকে ঘিরে ধীরে ওঠে। স্বচ্ছ আলো চারপাশে রঙিন বলয় তৈরি করে, কখনও গাঢ় কখনও ফিকে, যেন আছে আবার নেই। চাঁদের আলোয় সূক্ষ্ম উজ্জ্বল সুতো ঢেউয়ের মতো ঝিলমিল করে।',
            'সূচিশিল্প মর্যাদাপূর্ণ। ভাসমান সূচিকর্ম যোগ করে সামগ্রিক রং নরম, মার্জিত ও বুদ্ধিদীপ্ত।',
        ],
        'hi_IN' => [
            'hi',
            'डिज़ाइन प्रेरणा',
            [
                'वह सुगंधित लाख की गाड़ी फिर नहीं मिलेगी।',
                'घाटी के बादल बिना निशान पूर्व या पश्चिम बहते हैं।',
                'नाशपाती के फूलों से भरा आंगन, छलकता चाँद।',
                'विलो के रोएँ तालाब को हल्की हवा में पार करते हैं।',
            ],
            'रुई सी कोमल, रेशम जैसे हल्के बादल चमकते चाँद को घेरते हुए धीरे उठते हैं। स्वच्छ प्रकाश दृश्य के चारों ओर रंगीन प्रभामंडल बनाता है, कभी गहरा कभी फीका, सा लगता है फिर ओझल। चाँदनी में पतले चमकते धागे लहरों की तरह झिलमिलाते हैं।',
            'कढ़ाई गरिमापूर्ण है। तैरते कढ़ाई के टुकड़े रूप को निखारते हैं, और समग्र रंग नरम, सुंदर और शांत है।',
        ],
        'ur_PK' => [
            'ur',
            'ڈیزائن کی تحریک',
            [
                'وہ خوشبودار رنگین گاڑی پھر نہیں ملے گی۔',
                'وادی کے بادل بے نشان مشرق یا مغرب بہتے ہیں۔',
                'ناشپاتی کے پھولوں سے بھرا صحن، چمکتا چاند۔',
                'بید کے ریشے ہلکی ہوا میں تالاب پار کرتے ہیں۔',
            ],
            'روئی سی نرم، ریشم جیسے ہلکے بادل چمکتے چاند کو گھیر کر آہستہ اٹھتے ہیں۔ صاف روشنی منظر کے گرد رنگین ہالہ بناتی ہے، کبھی گہرا کبھی مدھم، سا نظر آتا ہے پھر غائب۔ چاندنی میں باریک چمکدار دھاگے لہروں کی طرح جھلملاتے ہیں۔',
            'کشیدہ کاری باوقار ہے۔ تیرتے کشیدہ ٹکڑے حتمی صورت سنوارتے ہیں، اور مجموعی رنگ نرم، نفیس اور متین ہے۔',
        ],
        'id_ID' => [
            'id',
            'Inspirasi desain',
            [
                'Kereta wangi berpernis itu takkan dijumpai lagi.',
                'Awan lembah pergi tanpa jejak, bebas ke timur atau barat.',
                'Halaman penuh bunga pir di bawah bulan yang penuh.',
                'Kapuk dedalu menyeberangi kolam dalam angin sepoi.',
            ],
            'Lembut seperti kapuk, awan seringan sutra mengerumuni bulan terang yang naik perlahan. Cahaya jernih membingkai pemandangan dengan lingkaran warna, kadang dalam kadang pudar, hampir ada lalu hilang. Benang halus berkilau seperti riak di bawah cahaya bulan.',
            'Sulaman berwibawa dan anggun. Potongan sulam yang melayang menyempurnakan hasil akhir, dan palet keseluruhan lembut, elegan, serta tenang.',
        ],
        'es_ES' => [
            'es',
            'Inspiración de diseño',
            [
                'El carruaje perfumado y lacado no volverá a encontrarse.',
                'Las nubes del desfiladero no dejan rastro y vagan al este o al oeste.',
                'El patio de perales florecidos bajo una luna colmada.',
                'Los amentos del sauce cruzan el estanque con una brisa leve.',
            ],
            'Suave como el vilano, nubes ligeras como la seda rodean la luna clara mientras asciende despacio. La luz límpida ciñe la escena con un halo de color, ahora hondo, ahora pálido, casi presente y luego ausente. Hilos finos y brillantes centellean como ondas bajo la luna.',
            'El bordado es digno y solemne. Las piezas bordadas flotantes afinan el acabado, y la paleta general es suave, elegante y serena.',
        ],
        'es_MX' => [
            'es-MX',
            'Inspiración de diseño',
            [
                'Esa carroza perfumada y laqueada no se volverá a encontrar.',
                'Las nubes del desfiladero se van sin rastro, libres al este o al oeste.',
                'Patio de flores de peral bajo una luna llena.',
                'Los amentos del sauce cruzan el estanque con una brisa suave.',
            ],
            'Suave como el vilano, nubes ligeras como la seda rodean la luna clara mientras sube despacio. La luz limpia rodea la escena con un halo de color, a veces intenso y a veces pálido, casi ahí y luego no. Hilos finos y brillantes destellan como ondas bajo la luna.',
            'El bordado es digno y solemne. Las piezas bordadas que flotan afinan el acabado, y la paleta general es suave, elegante y discreta.',
        ],
        'ca_ES' => [
            'ca',
            'Inspiració de disseny',
            [
                'El carro envernissat i perfumat no es tornarà a trobar.',
                'Els núvols de la gorja no deixen rastre i van a l\'est o a l\'oest.',
                'Pati de flors de perera sota una lluna plena.',
                'El borrissol de salze creua l\'estany amb una brisa lleu.',
            ],
            'Suau com el borrissol, núvols lleugers com la seda envolten la lluna clara que puja a poc a poc. La claror envolta l\'escena amb un halo de color, ara fosc ara pàl·lid, present i després absent. Fils fins i lluminosos centellegen com ones sota la lluna.',
            'El brodat és digne i solemne. Amb peces brodades flotants el conjunt es refina, i la paleta general és suau, elegant i assenyada.',
        ],
        'fr_FR' => [
            'fr',
            'Inspiration du dessin',
            [
                'Le char parfumé et laqué ne se rencontrera plus.',
                'Les nuages de la gorge s\'effacent, libres vers l\'est ou l\'ouest.',
                'Cour de poiriers en fleur sous une lune pleine.',
                'Les chatons de saule traversent l\'étang dans une brise légère.',
            ],
            'Douce comme l\'aigrette, des nuées légères comme la soie entourent la lune claire qui s\'élève lentement. La clarté ceint la scène d\'un halo coloré, tantôt profond, tantôt pâle, presque là puis absent. De fins fils lumineux scintillent comme des rides sous la lune.',
            'La broderie est digne et solennelle. Les pièces brodées flottantes affinent la finition, et la palette d\'ensemble est douce, élégante et posée.',
        ],
        'fr_CA' => [
            'fr-CA',
            'Inspiration du design',
            [
                'Le carrosse parfumé et laqué ne se rencontrera plus.',
                'Les nuages de la gorge partent sans trace, libres vers l\'est ou l\'ouest.',
                'Cour de fleurs de poirier sous une lune pleine.',
                'Les chatons de saule traversent l\'étang dans une brise légère.',
            ],
            'Douce comme le duvet, des nuages légers comme la soie entourent la lune claire qui monte lentement. La lumière claire encercle la scène d\'une auréole colorée, parfois profonde, parfois pâle, presque là puis disparue. De fins fils brillants scintillent comme des rides sous la lune.',
            'La broderie est digne et solennelle. Les pièces brodées flottantes raffinent la finition, et la palette d\'ensemble est douce, élégante et raffinée.',
        ],
        'pt_PT' => [
            'pt-PT',
            'Inspiração de desenho',
            [
                'A carruagem perfumada e lacada não voltará a encontrar-se.',
                'As nuvens do desfiladeiro seguem sem rasto, livres a leste ou a oeste.',
                'Pátio de pereiras em flor sob uma lua cheia.',
                'Os amentilhos do salgueiro cruzam o lago numa brisa leve.',
            ],
            'Suave como a penugem, nuvens leves como a seda rodeiam a lua clara que sobe devagar. A luz límpida cerca a cena com um halo de cor, ora fundo ora pálido, quase presente e depois ausente. Fios finos e brilhantes cintilam como ondulações ao luar.',
            'O bordado é digno e solene. As peças bordadas flutuantes apuram o acabamento, e a paleta geral é suave, elegante e sóbria.',
        ],
        'pt_BR' => [
            'pt-BR',
            'Inspiração de design',
            [
                'A carruagem perfumada e laqueada não será encontrada de novo.',
                'As nuvens do desfiladeiro seguem sem rastro, livres a leste ou a oeste.',
                'Pátio de pereiras floridas sob uma lua cheia.',
                'Os amentilhos do salgueiro cruzam o lago numa brisa leve.',
            ],
            'Suave como a penugem, nuvens leves como a seda cercam a lua clara que sobe devagar. A luz límpida envolve a cena num halo colorido, ora fundo ora pálido, quase presente e depois ausente. Fios finos e brilhantes cintilam como ondulações ao luar.',
            'O bordado é digno e solene. As peças bordadas flutuantes refinam o acabamento, e a paleta geral é suave, elegante e serena.',
        ],
        'de_DE' => [
            'de',
            'Gestaltungsinspiration',
            [
                'Der lackierte, duftende Wagen wird nicht wieder begegnen.',
                'Schluchtwolken ziehen spurlos nach Osten oder Westen.',
                'Birnblütenhof unter einem vollen Mond.',
                'Weidenkätzchen überqueren den Teich in einem leisen Wind.',
            ],
            'Weich wie Flaum, seidenleichte Wolken umstehen den hellen Mond, der langsam aufgeht. Klares Licht legt einen farbigen Hof um die Szene, mal tief, mal blass, fast da und dann fort. Feine helle Fäden glitzern wie Wellen im Mondlicht.',
            'Die Stickerei ist würdevoll und stattlich. Schwebende Stickteile verfeinern die Ausführung, und die gesamte Farbgebung ist sanft, elegant und gefasst.',
        ],
        'it_IT' => [
            'it',
            'Ispirazione del disegno',
            [
                'Il carro profumato e laccato non si incontrerà più.',
                'Le nubi della gola svaniscono, libere a est o a ovest.',
                'Cortile di peri in fiore sotto una luna colma.',
                'Gli amenti del salice attraversano lo stagno in una brezza lieve.',
            ],
            'Morbida come il pappo, nubi leggere come seta attorniano la luna chiara che sale piano. La luce limpida cinge la scena di un alone colorato, ora fondo ora pallido, quasi presente e poi assente. Fili fini e luminosi scintillano come increspature al chiaro di luna.',
            'Il ricamo è dignitoso e solenne. I pezzi ricamati fluttuanti affinano la finitura, e la tavolozza complessiva è dolce, elegante e composta.',
        ],
        'nl_NL' => [
            'nl',
            'Ontwerpinspiratie',
            [
                'De geparfumeerde gelakte wagen wordt niet meer ontmoet.',
                'Wolken in de kloof laten geen spoor en drijven oost of west.',
                'Perenbloesemhof onder een volle maan.',
                'Wilgenkatjes steken de vijver over in een lichte bries.',
            ],
            'Zacht als pluis, wolken licht als zijde omringen de heldere maan die langzaam stijgt. Helder licht legt een gekleurde halo om het tafereel, nu diep, dan bleek, bijna daar en dan weg. Fijne lichte draden glinsteren als rimpelingen in het maanlicht.',
            'Het borduurwerk is waardig en statig. Zwevende geborduurde stukken verfijnen de afwerking, en het geheel aan kleuren is zacht, elegant en ingetogen.',
        ],
        'pl_PL' => [
            'pl',
            'Inspiracja projektu',
            [
                'Pachnący lakierowany powóz już się nie spotka.',
                'Chmury wąwozu nikną bez śladu, wolne na wschód lub zachód.',
                'Dziedziniec kwitnących grusz pod pełnym księżycem.',
                'Kotki wierzby przepływają staw w lekkim wietrze.',
            ],
            'Miękka jak puch, chmury lekkie jak jedwab otaczają jasny księżyc, który wschodzi powoli. Czyste światło otacza scenę barwną aureolą, raz głęboką, raz bladą, niemal obecną i znów nieobecną. Cienkie jasne nitki połyskują jak zmarszczki w świetle księżyca.',
            'Haft jest dostojny i uroczysty. Unoszące się haftowane elementy doskonalą wykończenie, a cała paleta jest łagodna, elegancka i spokojna.',
        ],
        'cs_CZ' => [
            'cs',
            'Inspirace návrhu',
            [
                'Voňavý lakovaný vůz se už nepotká.',
                'Mraky soutěsky mizí beze stopy na východ či západ.',
                'Dvůr kvetoucích hrušní pod plným měsícem.',
                'Jehnědy vrby přecházejí rybník v lehkém vánku.',
            ],
            'Měkké jako chmýří, mraky lehké jako hedvábí obklopují jasný měsíc, který pomalu vychází. Čisté světlo obkružuje scénu barevným halem, hned hlubokým, hned bledým, skoro přítomným a pak pryč. Jemné světlé nitě se třpytí jako vlnky v měsíčním světle.',
            'Výšivka je důstojná a slavnostní. Vznášející se vyšívané dílky zušlechťují provedení a celková paleta je jemná, elegantní a klidná.',
        ],
        'sk_SK' => [
            'sk',
            'Inšpirácia návrhu',
            [
                'Voňavý lakovaný voz sa už nestretne.',
                'Mraky tiesňavy miznú bez stopy na východ či západ.',
                'Dvor kvitnúcich hrušiek pod plným mesiacom.',
                'Jahňady vŕby prechádzajú rybník v ľahkom vánku.',
            ],
            'Mäkké ako páperie, mraky ľahké ako hodváb obklopujú jasný mesiac, ktorý pomaly vychádza. Čisté svetlo obkolesuje scénu farebným halom, raz hlbokým, raz bledým, takmer prítomným a potom preč. Jemné svetlé nite sa ligocú ako vlnky v mesačnom svetle.',
            'Výšivka je dôstojná a slávnostná. Vznášajúce sa vyšívané dieliky zušľachťujú prevedenie a celková paleta je jemná, elegantná a pokojná.',
        ],
        'hu_HU' => [
            'hu',
            'Tervezési ihlet',
            [
                'Az illatos, lakkozott hintó többé nem találkozik.',
                'A szurdok felhői nyomtalanul sodródnak keletre vagy nyugatra.',
                'Körtevirágos udvar telihold alatt.',
                'A fűz barkái könnyű szellőben átúsznak a tavon.',
            ],
            'Pihepuha, selyemkönnyű felhők veszik körül a fényes holdat, ahogy lassan felkel. A tiszta fény színes udvarral öleli a jelenetet, hol mélyen, hol halványan, szinte ott van, aztán eltűnik. Finom fényes szálak hullámzanak, mint a fodrok a holdfényben.',
            'A hímzés méltóságteljes és ünnepélyes. A lebegő hímzett darabok finomítják a kidolgozást, az összpaletta pedig lágy, elegáns és nyugodt.',
        ],
        'ro_RO' => [
            'ro',
            'Inspirație de design',
            [
                'Trăsura parfumată și lăcuită nu se va mai întâlni.',
                'Norii defileului pleacă fără urmă, liberi spre est sau vest.',
                'Curte de peri înfloriți sub o lună plină.',
                'Mâțișorii de salcie trec iazul într-o briză ușoară.',
            ],
            'Moale ca puful, nori ușori ca mătasea înconjoară luna luminoasă care răsare încet. Lumina limpede încinge scena cu un halo colorat, când adânc, când pal, aproape prezent și apoi absent. Fire fine și strălucitoare scapără ca undele în lumina lunii.',
            'Broderia este demnă și solemnă. Piesele brodate plutitoare rafinează finisajul, iar paleta de ansamblu este blândă, elegantă și liniștită.',
        ],
        'bg_BG' => [
            'bg',
            'Вдъхновение за дизайна',
            [
                'Ароматната лакирана кола няма да се срещне отново.',
                'Облаците в дефилето се носят без следа на изток или запад.',
                'Двор с цъфнали круши под пълна луна.',
                'Реси от върба прекосяват езерото в лек бриз.',
            ],
            'Мека като пух, облаци леки като коприна обграждат ярката луна, която бавно изгрява. Бистрата светлина опасва сцената с цветен ореол, ту дълбок, ту блед, почти тук и после изчезнал. Фини светли нишки блещукат като вълнички на лунна светлина.',
            'Бродерията е достойна и тържествена. Плаващите бродирани парчета изчистват завършека, а цялата палитра е нежна, елегантна и спокойна.',
        ],
        'el_GR' => [
            'el',
            'Έμπνευση σχεδιασμού',
            [
                'Η αρωματική λουστραρισμένη άμαξα δεν θα ξανασυναντηθεί.',
                'Τα σύννεφα του φαραγγιού φεύγουν χωρίς ίχνος, ανατολικά ή δυτικά.',
                'Αυλή με ανθισμένες αχλαδιές κάτω από ολόγιομο φεγγάρι.',
                'Οι ιουλοί της ιτιάς διασχίζουν τη λίμνη σε ελαφρό αεράκι.',
            ],
            'Απαλή σαν πούπουλο, σύννεφα ελαφριά σαν μετάξι τριγυρίζουν το φωτεινό φεγγάρι που ανεβαίνει αργά. Το καθαρό φως ζώνει τη σκηνή με χρωματιστή άλω, πότε βαθιά πότε χλωμή, σχεδόν παρούσα και έπειτα απόν. Λεπτά φωτεινά νήματα λαμπυρίζουν σαν κυματισμοί στο φεγγαρόφωτο.',
            'Το κέντημα είναι αξιοπρεπές και επίσημο. Τα αιωρούμενα κεντητά κομμάτια εξευγενίζουν το φινίρισμα, και η συνολική παλέτα είναι ήπια, κομψή και ήρεμη.',
        ],
        'ru_RU' => [
            'ru',
            'Источник замысла',
            [
                'Лакированной душистой повозке больше не встретиться.',
                'Облака ущелья уходят без следа на восток или на запад.',
                'Двор цветущих груш под полной луной.',
                'Серёжки ивы пересекают пруд в лёгком ветре.',
            ],
            'Мягкие, как пух, облака легче шёлка окружают ясный месяц, который медленно всходит. Чистый свет обводит сцену цветным ореолом: то глубже, то бледнее, едва есть и снова нет. Тонкие светлые нити мерцают, как рябь в лунном свете.',
            'Вышивка торжественна и величава. Парящие вышитые детали уточняют отделку, а вся палитра мягкая, изящная и сдержанная.',
        ],
        'uk_UA' => [
            'uk',
            'Джерело задуму',
            [
                'Лакованій запашній повозці більше не зустрітися.',
                'Хмари ущелини йдуть без сліду на схід чи на захід.',
                'Двір квітучих груш під повним місяцем.',
                'Котки верби перетинають ставок у легкому вітрі.',
            ],
            'М\'які, як пух, хмари легші за шовк оточують ясний місяць, що повільно сходить. Чисте світло обводить сцену кольоровим ореолом: то глибше, то блідіше, ледве є і знову немає. Тонкі світлі нитки мерехтять, як брижі в місячному світлі.',
            'Вишивка урочиста й величава. Завислі вишиті деталі уточнюють оздоблення, а вся палітра м\'яка, витончена й стримана.',
        ],
        'tr_TR' => [
            'tr',
            'Tasarım ilhamı',
            [
                'Kokulu lake araba bir daha karşılaşılmayacak.',
                'Boğaz bulutları iz bırakmadan doğuya ya da batıya gider.',
                'Dolunay altında armut çiçekli avlu.',
                'Söğüt kedicikleri hafif bir esintide gölü geçer.',
            ],
            'Tüy gibi yumuşak, ipek kadar hafif bulutlar, yavaşça yükselen parlak ayı kuşatır. Berrak ışık sahneyi renkli bir hale ile sarar; bazen derin, bazen soluk, neredeyse orada sonra yok. İnce parlak iplikler ay ışığında dalgacıklar gibi parıldar.',
            'Nakış vakur ve görkemlidir. Süzülen nakış parçaları bitişi incelttir, genel palet ise yumuşak, zarif ve durgundur.',
        ],
        'da_DK' => [
            'da',
            'Designinspiration',
            [
                'Den duftende lakerede vogn mødes ikke igen.',
                'Slugtskyer driver sporløst mod øst eller vest.',
                'Pæretræsblomster i gården under en fuld måne.',
                'Pilegæslinger krydser dammen i en let brise.',
            ],
            'Blød som fnug, skyer lette som silke omkranser den klare måne, der stiger langsomt. Klart lys lægger en farvet halo om scenen, snart dyb, snart bleg, næsten der og så væk. Fine lyse tråde glimter som krusninger i måneskin.',
            'Broderiet er værdigt og højtideligt. Svævende broderede stykker forfiner finishen, og den samlede palet er blid, elegant og rolig.',
        ],
        'sv_SE' => [
            'sv',
            'Designinspiration',
            [
                'Den doftande lackerade vagnen möts inte igen.',
                'Moln i klyftan driver spårlöst österut eller västerut.',
                'Päronblomsgård under en full måne.',
                'Videhängen korsar dammen i en lätt bris.',
            ],
            'Mjuk som dun, moln lätta som siden omger den klara månen som stiger långsamt. Klart ljus ringar in scenen med en färgad halo, än djup, än blek, nästan där och sedan borta. Fina ljusa trådar glittrar som krusningar i månsken.',
            'Broderiet är värdigt och högtidligt. Svävande broderade stycken förfinar finishen, och den samlade paletten är mjuk, elegant och stilla.',
        ],
        'nb_NO' => [
            'nb',
            'Designinspirasjon',
            [
                'Den duftende lakkerte vognen møtes ikke igjen.',
                'Skyer i kløften driver sporløst øst eller vest.',
                'Pæreblomstgård under en full måne.',
                'Seljerakler krysser dammen i en lett bris.',
            ],
            'Myk som dun, skyer lette som silke omkranser den klare månen som stiger langsomt. Klart lys legger en farget halo rundt scenen, snart dyp, snart blek, nesten der og så borte. Fine lyse tråder glitrer som krusninger i månelys.',
            'Broderiet er verdig og høytidelig. Svevende broderte stykker foredler finishen, og den samlede paletten er myk, elegant og rolig.',
        ],
        'fi_FI' => [
            'fi',
            'Suunnittelun inspiraatio',
            [
                'Tuoksuva lakattu vaunu ei kohtaa enää.',
                'Rotkon pilvet katoavat jälkiä jättämättä itään tai länteen.',
                'Päärynäkukkapiha täyden kuun alla.',
                'Pajunkissat ylittävät lammen kevyessä tuulessa.',
            ],
            'Pehmeä kuin hahtuva, silkinkevyet pilvet ympäröivät kirkasta kuuta sen noustessa hitaasti. Kirkas valo kehystää näkymän värillisellä kehällä, vuoroin syvänä, vuoroin haaleana, melkein läsnä ja sitten poissa. Ohuet kirkkaat langat kimaltelevat kuin väreet kuunvalossa.',
            'Kirjonta on arvokasta ja juhlallista. Leijuvat kirjotut palat viimeistelevät työn, ja koko väripaletti on lempeä, elegantti ja tyyni.',
        ],
        'et_EE' => [
            'et',
            'Kujunduse inspiratsioon',
            [
                'Lõhnavat lakitud tõlda enam ei kohta.',
                'Kuristiku pilved kaovad jäljetult itta või läände.',
                'Pirniõite õu täiskuu all.',
                'Paju urvad ületavad tiigi kerges tuules.',
            ],
            'Pehme kui udusulg, siidkerged pilved ümbritsevad eredat kuud, mis tõuseb aeglaselt. Selge valgus raamib stseeni värvilise haloga, kord sügav, kord kahvatu, peaaegu kohal ja siis kadunud. Peened heledad niidid sätendavad kuuvalgel nagu virvendus.',
            'Tikand on väärikas ja pidulik. Hõljuvad tikitud tükid viimistlevad tulemuse ning kogu palett on õrn, elegantne ja rahulik.',
        ],
        'lv_LV' => [
            'lv',
            'Dizaina iedvesma',
            [
                'Smaržīgais lakotais rati vairs netiks sastapts.',
                'Aizas mākoņi aizplūst bez pēdām uz austrumiem vai rietumiem.',
                'Bumbieru ziedu pagalms pilnmēness gaismā.',
                'Vītolu spurdzes šķērso dīķi vieglā vējā.',
            ],
            'Mīksts kā pūka, zīda viegluma mākoņi apņem spožo mēnesi, kas lēni uzlec. Skaidra gaisma ietver ainu krāsainā oreolā, te dziļā, te bālā, gandrīz klāt un tad prom. Smalki gaiši diegi mirdz kā viļņojums mēnesgaismā.',
            'Izšuvums ir cienīgs un svinīgs. Peldošie izšūtie gabali izsmalcina apdari, un kopējā palete ir maiga, eleganta un mierīga.',
        ],
        'lt_LT' => [
            'lt',
            'Dizaino įkvėpimas',
            [
                'Kvapnus lakuotas vežimas daugiau nebesusitiks.',
                'Tarpeklio debesys nueina be pėdsako į rytus ar vakarus.',
                'Kriaušių žiedų kiemas po pilnu mėnuliu.',
                'Gluosnio kačiukai kerta tvenkinį lengvame vėjyje.',
            ],
            'Minkšta kaip pūkas, šilko lengvumo debesys supa šviesų mėnulį, kuris kyla lėtai. Gryna šviesa apjuosia sceną spalvotu ratu, tai gili, tai blyški, beveik čia ir vėl dingusi. Plonos šviesios gijos mirga kaip raibuliai mėnesienoje.',
            'Siuvinėjimas orus ir iškilmingas. Plaukiantys siuvinėti gabalai ištobulina apdailą, o visa paletė švelni, elegantiška ir rami.',
        ],
        'hr_HR' => [
            'hr',
            'Inspiracija dizajna',
            [
                'Mirisne lakirane kočije više se neće sresti.',
                'Oblaci klanaca odlaze bez traga na istok ili zapad.',
                'Dvorište cvjetnih krušaka pod punim mjesecom.',
                'Vrbine mace prelaze jezerce u laganom povjetarcu.',
            ],
            'Meko poput paperja, oblaci lagani poput svile okružuju sjajan mjesec koji polako izlazi. Čista svjetlost opasuje prizor obojenim aureolom, sad dubokim, sad blijedim, gotovo tu i onda nestalim. Tanke svijetle niti svjetlucaju poput mreškanja na mjesečini.',
            'Vez je dostojanstven i svečan. Lebdeći vezni komadi profinjuju završnicu, a cijela paleta je nježna, elegantna i smirena.',
        ],
        'sl_SI' => [
            'sl',
            'Navdih oblikovanja',
            [
                'Dišeči lakirani voz se ne bo več srečal.',
                'Oblaki soteske odidejo brez sledi na vzhod ali zahod.',
                'Dvorišče cvetočih hrušk pod polno luno.',
                'Mačice vrbe prečkajo ribnik v lahkem vetriču.',
            ],
            'Mehko kot puh, oblaki lahki kot svila obkrožajo svetlo luno, ki počasi vzhaja. Čista svetloba obrobi prizor z barvnim sijem, zdaj globokim, zdaj bledim, skoraj tu in spet proč. Tanki svetli nitki se lesketajo kot valovanje v mesečini.',
            'Vez je dostojanstven in slovesen. Lebdeči vezeni kosi izpilijo zaključek, celotna paleta pa je nežna, elegantna in mirna.',
        ],
        'ga_IE' => [
            'ga',
            'Spreagadh dearaidh',
            [
                'Ní casfar arís ar an gcarr cumhra laicrithe.',
                'Imíonn scamaill an ghleanna gan rian, soir nó siar.',
                'Clós bláthanna piorra faoi ghealach lán.',
                'Caitíní saileach ag trasnú an linn i leoithne éadrom.',
            ],
            'Bog mar chaitín, scamaill chomh héadrom le síoda ag bailiú thart ar an ngealach gheal agus í ag éirí go mall. Cuireann solas glan fáinne daite timpeall na radhairc, domhain ansin éadrom, ann agus as. Snáitheanna míne geala ag lonrú mar thonnta faoin ghealach.',
            'Tá an bróidnéireacht dhínit agus shlachtmhar. Píosaí bróidnéireachta ar snámh a bheachtú an chríochnú, agus an pailéad iomlán bog, galánta agus ciallmhar.',
        ],
        'is_IS' => [
            'is',
            'Hönnunarhugmynd',
            [
                'Ilmandi lakkaður vagninn hittist ekki aftur.',
                'Ský gljúfursins hverfa sporlaust austur eða vestur.',
                'Garður perublóma undir fullu tungli.',
                'Víðireklar fara yfir tjörnina í léttri golu.',
            ],
            'Mjúkt sem dúnn, ský létt sem silki umlykja bjarta tunglið sem rís hægt. Tært ljós rammar senuna inn með lituðum bjarma, ýmist djúpum eða fölum, næstum hér og svo horfið. Fínir bjartir þræðir glitra eins og gárur í tunglsljósi.',
            'Útsaumurinn er virðulegur og hátíðlegur. Svifandi saumuð stykki fínpússa fráganginn og heildarlitirnir eru mildir, glæsilegir og rólegir.',
        ],
        'mt_MT' => [
            'mt',
            'Ispirazzjoni tad-disinn',
            [
                'Il-karozza fwejjaħa u llakkjata ma terġax tinstab.',
                'Sħab il-wied jitilqu bla traċċa lejn il-lvant jew il-punent.',
                'Bitha tal-fjuri tal-lanġas taħt qamar mimli.',
                'Il-qtajja\' tas-siġra tal-luq jaqsmu l-għadira f\'nifs ħafif.',
            ],
            'Artab bħar-rix, sħab ħafif daqs il-ħarir jdawru l-qamar jiddi waqt li jitla\' bil-mod. Dawl ċar idawwar ix-xena b\'alone kkulurit, kultant fond u kultant ċar, kważi hemm u mbagħad mar. Ħjut irqaq jleqqu bħal mewġ taħt id-dawl tal-qamar.',
            'Ir-rakkmu huwa dinjituż u solenni. Biċċiet irakkmati li jżommu fl-arja jirfinaw il-finitura, u l-paletta sħiħa hija ħelwa, eleganti u kalma.',
        ],
    ];
}
