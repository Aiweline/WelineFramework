<?php

declare(strict_types=1);

/**
 * Redo product #196 description per ecommerce-detail-processing:
 * 古风 · 仅详情 · 抹烤字图 · 左右/双列 · 启用 locale 各语真译（禁 EN 兜底）
 *
 * php app/code/Weline/Product/scripts/beautify-product-196-detail-layout.php --apply
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Product\LocalDescription;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Service\DetailDescriptionTextifier;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run', 'website:']);
$apply = isset($options['apply']) && !isset($options['dry-run']);
$websiteId = max(0, (int)($options['website'] ?? 0));
$productId = 196;

/**
 * 分流：06/01/08–13/15/16 = 净实拍；02/03 字板、04/05/14 烤字、尺码烤图不入图（抽文案/表）
 */
$A = [
    'hero' => 'da1323ff-4c1c-45a2-92d2-1a3337ddade5',
    'look01' => '3bc5d557-d9f2-4352-b565-9487a2e0ccf9',
    'look08' => '3f666f46-a4f0-4566-9790-8ed6c6b0ada9',
    'look09' => 'dba3785e-3972-41d3-ab32-a674c5765478',
    'look10' => 'fb9d82d2-7783-40e3-aed6-ad67a9be286e',
    'look11' => 'bc96f642-1f58-4446-83a9-cd083b1f1c0f',
    'look12' => '8517f9d4-dff7-4bf1-a93b-71dc0c0eaf36',
    'look13' => '6d5d2a4c-eb43-4a90-aa01-ef079b451606',
    'print' => 'f4c339fb-9e76-43f6-a2cc-d8c8013016e7',
    'hem' => 'b35c6d41-eb42-4df0-af51-ae474d45b53f',
];

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$img = static function (string $assetId, string $alt, int $w, int $hgt) use ($h): string {
    return '<img src="asset://' . $h($assetId) . '" alt="' . $h($alt)
        . '" loading="lazy" decoding="async" width="' . $w . '" height="' . $hgt . '">';
};

$feature = static function (string $mediaHtml, string $copyHtml, bool $reverse = false): string {
    $cls = 'weline-detail-feature' . ($reverse ? ' weline-detail-feature--reverse' : '');

    return '<div class="' . $cls . '">'
        . '<div class="weline-detail-feature__media">' . $mediaHtml . '</div>'
        . '<div class="weline-detail-feature__copy">' . $copyHtml . '</div>'
        . '</div>';
};

$figureStack = static function (array $imgs): string {
    $rows = '';
    $n = count($imgs);
    for ($i = 0; $i < $n; $i += 2) {
        if ($i + 1 < $n) {
            $rows .= '<div class="weline-detail-figure-row weline-detail-figure-row--pair">'
                . '<div class="weline-detail-figure">' . $imgs[$i] . '</div>'
                . '<div class="weline-detail-figure">' . $imgs[$i + 1] . '</div>'
                . '</div>';
        } else {
            $rows .= '<div class="weline-detail-figure-row weline-detail-figure-row--solo">'
                . '<div class="weline-detail-figure">' . $imgs[$i] . '</div>'
                . '</div>';
        }
    }

    return '<div class="weline-detail-figure-stack">' . $rows . '</div>';
};

/** @var array<string, array<string, mixed>> $copy */
$copy = [
    'zh_Hans_CN' => [
        'intro_title' => '碧云霞光',
        'intro_body' => '宋制三件：大袖衫、吊带、百褶裙。石青印花如云开霞起，广袖与褶影随步生风。',
        'inspire_title' => '设计心源',
        'inspire_lines' => [
            '渔阳鼙鼓动地来，惊破霓裳羽衣曲。',
            '九重城阙烟尘生，千乘万骑西南行。',
            '翠华摇摇行复止，西出都门百余里。',
            '六军不发无奈何，宛转蛾眉马前死。',
        ],
        'inspire_note' => '取白居易《长恨歌》句意，点题霓裳与盛世气度——非平台货盘说辞，仅为纹样与形制之题眼。',
        'original_title' => '原创心迹',
        'original_body' => '本款形制与纹样为花朝记原创设计，敬请珍惜衣冠、尊重匠心。',
        'highlight_title' => '衣袂要点',
        'top_label' => '上衣',
        'top_body' => '胸口印花细润平整，花光浅淡，望之清亮。',
        'cuff_label' => '袖袂',
        'cuff_body' => '大袖宽纾，袖口铺陈大片印花，闲雅有致。',
        'skirt_label' => '裙裳',
        'skirt_body' => '下裙渐变由浅入深，裙摆灵动，裙身垂顺利落。',
        'detail_title' => '细处',
        'detail_body' => '交领相叠，丝绦与流苏点缀腰间；近观印花层次与纱质垂坠。',
        'print_title' => '纹样',
        'print_body' => '羽翼与花枝铺于轻纱，留白处可见石青晕染。',
        'hem_title' => '裙裾',
        'hem_body' => '百褶渐变层层加深，裾边印花随褶影起伏。',
        'info_brand' => '花朝记',
        'info_name' => '碧云霞光',
        'info_color' => '如图',
        'info_style' => '宋制',
        'info_size' => 'XS–XL',
        'info_fabric' => '聚酯纤维',
        'info_parts' => '大袖衫、吊带、百褶裙',
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
        'chart_title' => '尺寸参照',
        'chart_note' => '单位：厘米。手工测量或有一至三厘米出入。',
        't_robe' => '大袖衫',
        't_skirt' => '百褶裙',
        't_cam' => '吊带',
        'h_size' => '尺码',
        'h_len' => '衣长',
        'h_bust' => '胸围',
        'h_sleeve' => '通袖长',
        'h_cuff' => '袖口宽',
        'h_skirt_len' => '裙长',
        'h_waist' => '腰围',
        'h_chest_sug' => '建议胸围',
        'h_strap' => '肩带宽',
        'alt_hero' => '碧云霞光 · 套装',
        'alt_look' => '碧云霞光 · 着装',
        'alt_print' => '碧云霞光 · 纹样',
        'alt_hem' => '碧云霞光 · 裙裾',
        'c_thick' => '厚度',
        'c_thick_opts' => ['薄', '适中', '厚'],
        'c_thick_sel' => '薄',
        'c_stretch' => '弹性',
        'c_stretch_opts' => ['无弹', '微弹', '高弹'],
        'c_stretch_sel' => '无弹',
    ],
    'en_US' => [
        'intro_title' => 'Azure Cloud Glow',
        'intro_body' => 'A Song-style triad: large-sleeve robe, camisole, and pleated skirt. Sage prints open like mist and rosy light; sleeves and pleats move with the step.',
        'inspire_title' => 'Design wellspring',
        'inspire_lines' => [
            'War drums from Yuyang shook the earth and broke the Rainbow Feather Dance.',
            'Dust rose over the ninefold gates as chariots rode southwest.',
            'Imperial banners swayed, then halted a hundred li west of the capital.',
            'The six armies would not march; the beauty died before the horses.',
        ],
        'inspire_note' => 'Drawn from Bai Juyi’s Song of Everlasting Regret—an emblem for the robe’s grace, not marketplace copy.',
        'original_title' => 'Original craft',
        'original_body' => 'Cut and motifs are original to Huazhaoji. Please honor the craft.',
        'highlight_title' => 'On the garment',
        'top_label' => 'Bodice',
        'top_body' => 'Chest florals lie smooth and light—quietly luminous.',
        'cuff_label' => 'Sleeves',
        'cuff_body' => 'Wide sleeves with expansive cuff prints—at ease and refined.',
        'skirt_label' => 'Skirt',
        'skirt_body' => 'A gradient pleated skirt: fluid hem, clean drape.',
        'detail_title' => 'Close looking',
        'detail_body' => 'Layered collars, sash and tassel at the waist—print depth and soft fall up close.',
        'print_title' => 'Motifs',
        'print_body' => 'Wings and blossoms across sheer cloth; sage washes in the open ground.',
        'hem_title' => 'Hem',
        'hem_body' => 'Pleats deepen fold by fold; florals follow the fall of the hem.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Azure Cloud Glow',
        'info_color' => 'As shown',
        'info_style' => 'Song style',
        'info_size' => 'XS–XL',
        'info_fabric' => 'Polyester',
        'info_parts' => 'Large-sleeve robe, camisole, pleated skirt',
        'info_title' => 'At a glance',
        'info_basics' => 'Basics',
        'info_comfort' => 'Hand feel',
        'label_brand' => 'Brand',
        'label_name' => 'Name',
        'label_color' => 'Color',
        'label_style' => 'Style',
        'label_size' => 'Sizes',
        'label_fabric' => 'Fabric',
        'label_parts' => 'Pieces',
        'chart_title' => 'Size reference',
        'chart_note' => 'Centimeters. Hand measure may vary by 1–3 cm.',
        't_robe' => 'Large-sleeve robe',
        't_skirt' => 'Pleated skirt',
        't_cam' => 'Camisole',
        'h_size' => 'Size',
        'h_len' => 'Length',
        'h_bust' => 'Bust',
        'h_sleeve' => 'Sleeve span',
        'h_cuff' => 'Cuff width',
        'h_skirt_len' => 'Skirt length',
        'h_waist' => 'Waist',
        'h_chest_sug' => 'Suggested bust',
        'h_strap' => 'Strap width',
        'alt_hero' => 'Azure Cloud Glow · set',
        'alt_look' => 'Azure Cloud Glow · worn',
        'alt_print' => 'Azure Cloud Glow · motif',
        'alt_hem' => 'Azure Cloud Glow · hem',
        'c_thick' => 'Thickness',
        'c_thick_opts' => ['Thin', 'Medium', 'Thick'],
        'c_thick_sel' => 'Thin',
        'c_stretch' => 'Stretch',
        'c_stretch_opts' => ['None', 'Slight', 'High'],
        'c_stretch_sel' => 'None',
    ],
    'ar_SA' => [
        'intro_title' => 'بريق السحاب اللازوردي',
        'intro_body' => 'طقم على الطراز السونغ: رداء بأكمام واسعة، وقميص داخلي، وتنورة مطوية. طباعة حجرية خضراء كالغيوم والضوء الوردي؛ تتحرك الأكمام والطيات مع الخطوة.',
        'inspire_title' => 'منبع التصميم',
        'inspire_lines' => [
            'قرعت طبول يويانغ الأرض وكسرت رقصة الريش القزحي.',
            'ارتفع الغبار فوق أبواب القصر التسع وسارت المركبات نحو الجنوب الغربي.',
            'تمايلت الرايات الإمبراطورية ثم توقفت على بعد مئة لي غرب العاصمة.',
            'لم تسر الجيوش الستة؛ وماتت الحسناء أمام الخيل.',
        ],
        'inspire_note' => 'مستوحى من قصيدة باي جويي «أغنية الحسرة الأبدية»—رمز لرقة الرداء وهيبة العصر، لا خطاب سوق.',
        'original_title' => 'صنعة أصيلة',
        'original_body' => 'القصّة والزخارف أصيلة من هواجاوجي. يُرجى إكرام الحرفة.',
        'highlight_title' => 'ملامح الثوب',
        'top_label' => 'الصدر',
        'top_body' => 'طباعة الصدر ناعمة مستوية، ضوء الزهر خفيف وواضح.',
        'cuff_label' => 'الأكمام',
        'cuff_body' => 'أكمام واسعة مع طباعة فسيحة عند الأساور—أنيقة ومريحة.',
        'skirt_label' => 'التنورة',
        'skirt_body' => 'تنورة مطوية بتدرج من الفاتح إلى الداكن؛ طرف حيّ وثنيّة منتظمة.',
        'detail_title' => 'تفاصيل قريبة',
        'detail_body' => 'ياقات متداخلة، وشاح وشراشيب عند الخصر؛ طبقات الطباعة وتدلي الشاش عن قرب.',
        'print_title' => 'الزخارف',
        'print_body' => 'أجنحة وأغصان على شاش خفيف؛ غسل لازوردي في المساحات المفتوحة.',
        'hem_title' => 'ذيل التنورة',
        'hem_body' => 'تتعمق الطيات طبقة بعد طبقة؛ تتبع الزهور سقوط الذيل.',
        'info_brand' => 'هواجاوجي',
        'info_name' => 'بريق السحاب اللازوردي',
        'info_color' => 'كما في الصورة',
        'info_style' => 'أسلوب سونغ',
        'info_size' => 'XS–XL',
        'info_fabric' => 'بوليستر',
        'info_parts' => 'رداء بأكمام واسعة، قميص داخلي، تنورة مطوية',
        'info_title' => 'لمحة عامة',
        'info_basics' => 'أساسيات',
        'info_comfort' => 'ملمس الارتداء',
        'label_brand' => 'العلامة',
        'label_name' => 'الاسم',
        'label_color' => 'اللون',
        'label_style' => 'الطراز',
        'label_size' => 'المقاسات',
        'label_fabric' => 'القماش',
        'label_parts' => 'القطعات',
        'chart_title' => 'مرجع المقاسات',
        'chart_note' => 'بالسنتيمتر. القياس اليدوي قد يختلف بمقدار ١–٣ سم.',
        't_robe' => 'رداء الأكمام الواسعة',
        't_skirt' => 'التنورة المطوية',
        't_cam' => 'القميص الداخلي',
        'h_size' => 'المقاس',
        'h_len' => 'الطول',
        'h_bust' => 'محيط الصدر',
        'h_sleeve' => 'مدى الكم',
        'h_cuff' => 'عرض الأسورة',
        'h_skirt_len' => 'طول التنورة',
        'h_waist' => 'الخصر',
        'h_chest_sug' => 'صدر مقترح',
        'h_strap' => 'عرض الشريط',
        'alt_hero' => 'بريق السحاب اللازوردي · الطقم',
        'alt_look' => 'بريق السحاب اللازوردي · مرتدى',
        'alt_print' => 'بريق السحاب اللازوردي · زخرفة',
        'alt_hem' => 'بريق السحاب اللازوردي · الذيل',
        'c_thick' => 'السماكة',
        'c_thick_opts' => ['خفيف', 'متوسط', 'سميك'],
        'c_thick_sel' => 'خفيف',
        'c_stretch' => 'المرونة',
        'c_stretch_opts' => ['بدون', 'خفيف', 'عالي'],
        'c_stretch_sel' => 'بدون',
    ],
    'es_ES' => [
        'intro_title' => 'Resplandor de Nube Azul',
        'intro_body' => 'Tríada al estilo Song: túnica de mangas anchas, camisola y falda plisada. Estampado verde-piedra como niebla y luz rosada; mangas y pliegues se mueven al paso.',
        'inspire_title' => 'Manantial del diseño',
        'inspire_lines' => [
            'Los tambores de Yuyang sacudieron la tierra y rompieron la Danza del Plumaje Arcoíris.',
            'El polvo se alzó sobre las nueve puertas mientras los carros iban al suroeste.',
            'Los estandartes imperiales oscilaron y se detuvieron a cien li al oeste de la capital.',
            'Los seis ejércitos no marcharon; la belleza murió ante los caballos.',
        ],
        'inspire_note' => 'Tomado de la Canción del Lamento Eterno de Bai Juyi—emblema de la gracia del traje, no discurso de mercado.',
        'original_title' => 'Oficio original',
        'original_body' => 'Corte y motivos son originales de Huazhaoji. Honren el oficio.',
        'highlight_title' => 'Sobre la prenda',
        'top_label' => 'Pechera',
        'top_body' => 'El estampado del pecho es fino y liso; la flor luce suave y clara.',
        'cuff_label' => 'Mangas',
        'cuff_body' => 'Mangas anchas con gran estampado en los puños—relajadas y refinadas.',
        'skirt_label' => 'Falda',
        'skirt_body' => 'Falda plisada en degradado: bajo fluido y caída limpia.',
        'detail_title' => 'De cerca',
        'detail_body' => 'Cuellos superpuestos, cinta y borla en la cintura; profundidad del estampado y caída de la gasa.',
        'print_title' => 'Motivos',
        'print_body' => 'Alas y ramas sobre gasa ligera; lavados verde-piedra en el vacío.',
        'hem_title' => 'Bajo',
        'hem_body' => 'Los pliegues se profundizan uno a uno; las flores siguen la caída del bajo.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Resplandor de Nube Azul',
        'info_color' => 'Como se muestra',
        'info_style' => 'Estilo Song',
        'info_size' => 'XS–XL',
        'info_fabric' => 'Poliéster',
        'info_parts' => 'Túnica mangas anchas, camisola, falda plisada',
        'info_title' => 'De un vistazo',
        'info_basics' => 'Básicos',
        'info_comfort' => 'Tacto',
        'label_brand' => 'Marca',
        'label_name' => 'Nombre',
        'label_color' => 'Color',
        'label_style' => 'Estilo',
        'label_size' => 'Tallas',
        'label_fabric' => 'Tejido',
        'label_parts' => 'Piezas',
        'chart_title' => 'Referencia de talla',
        'chart_note' => 'Centímetros. La medida manual puede variar 1–3 cm.',
        't_robe' => 'Túnica mangas anchas',
        't_skirt' => 'Falda plisada',
        't_cam' => 'Camisola',
        'h_size' => 'Talla',
        'h_len' => 'Largo',
        'h_bust' => 'Busto',
        'h_sleeve' => 'Manga total',
        'h_cuff' => 'Ancho puño',
        'h_skirt_len' => 'Largo falda',
        'h_waist' => 'Cintura',
        'h_chest_sug' => 'Busto sugerido',
        'h_strap' => 'Ancho tirante',
        'alt_hero' => 'Resplandor de Nube Azul · conjunto',
        'alt_look' => 'Resplandor de Nube Azul · puesto',
        'alt_print' => 'Resplandor de Nube Azul · motivo',
        'alt_hem' => 'Resplandor de Nube Azul · bajo',
        'c_thick' => 'Grosor',
        'c_thick_opts' => ['Fino', 'Medio', 'Grueso'],
        'c_thick_sel' => 'Fino',
        'c_stretch' => 'Elasticidad',
        'c_stretch_opts' => ['Nula', 'Ligera', 'Alta'],
        'c_stretch_sel' => 'Nula',
    ],
    'fr_FR' => [
        'intro_title' => 'Éclat Nuage Azur',
        'intro_body' => 'Triade style Song : robe à larges manches, caraco et jupe plissée. Imprimé vert pierre comme brume et lueur rosée ; manches et plis suivent le pas.',
        'inspire_title' => 'Source du dessin',
        'inspire_lines' => [
            'Les tambours de Yuyang ébranlèrent la terre et brisèrent la Danse des Plumes d’Arc-en-ciel.',
            'La poussière s’éleva sur les neuf portes tandis que les chars allaient au sud-ouest.',
            'Les bannières impériales oscillèrent puis s’arrêtèrent à cent li à l’ouest de la capitale.',
            'Les six armées ne marchèrent point ; la beauté mourut devant les chevaux.',
        ],
        'inspire_note' => 'Tirée du Chant du regret éternel de Bai Juyi—emblème de la grâce du costume, non un discours de marché.',
        'original_title' => 'Création originale',
        'original_body' => 'Coupe et motifs sont originaux Huazhaoji. Honorez le savoir-faire.',
        'highlight_title' => 'Sur le vêtement',
        'top_label' => 'Corsage',
        'top_body' => 'L’imprimé du corsage est fin et uni ; la fleur reste douce et claire.',
        'cuff_label' => 'Manches',
        'cuff_body' => 'Larges manches, grand imprimé aux poignets—aisé et raffiné.',
        'skirt_label' => 'Jupe',
        'skirt_body' => 'Jupe plissée en dégradé : bas fluide, chute nette.',
        'detail_title' => 'De près',
        'detail_body' => 'Cols superposés, cordon et pompon à la taille ; relief de l’imprimé et chute de la gaze.',
        'print_title' => 'Motifs',
        'print_body' => 'Ailes et rameaux sur gaze légère ; lavis vert pierre dans le vide.',
        'hem_title' => 'Bas',
        'hem_body' => 'Les plis s’approfondissent un à un ; les fleurs suivent la chute du bas.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Éclat Nuage Azur',
        'info_color' => 'Comme illustré',
        'info_style' => 'Style Song',
        'info_size' => 'XS–XL',
        'info_fabric' => 'Polyester',
        'info_parts' => 'Robe manches larges, caraco, jupe plissée',
        'info_title' => 'En un coup d’œil',
        'info_basics' => 'Essentiels',
        'info_comfort' => 'Tactile',
        'label_brand' => 'Marque',
        'label_name' => 'Nom',
        'label_color' => 'Couleur',
        'label_style' => 'Style',
        'label_size' => 'Tailles',
        'label_fabric' => 'Tissu',
        'label_parts' => 'Pièces',
        'chart_title' => 'Repères de taille',
        'chart_note' => 'Centimètres. Mesure manuelle : écart possible de 1–3 cm.',
        't_robe' => 'Robe manches larges',
        't_skirt' => 'Jupe plissée',
        't_cam' => 'Caraco',
        'h_size' => 'Taille',
        'h_len' => 'Longueur',
        'h_bust' => 'Poitrine',
        'h_sleeve' => 'Envergure manche',
        'h_cuff' => 'Largeur poignet',
        'h_skirt_len' => 'Longueur jupe',
        'h_waist' => 'Taille',
        'h_chest_sug' => 'Poitrine suggérée',
        'h_strap' => 'Largeur bretelle',
        'alt_hero' => 'Éclat Nuage Azur · ensemble',
        'alt_look' => 'Éclat Nuage Azur · porté',
        'alt_print' => 'Éclat Nuage Azur · motif',
        'alt_hem' => 'Éclat Nuage Azur · bas',
        'c_thick' => 'Épaisseur',
        'c_thick_opts' => ['Fin', 'Moyen', 'Épais'],
        'c_thick_sel' => 'Fin',
        'c_stretch' => 'Élasticité',
        'c_stretch_opts' => ['Nulle', 'Légère', 'Forte'],
        'c_stretch_sel' => 'Nulle',
    ],
    'pt_BR' => [
        'intro_title' => 'Brilho Nuvem Azul',
        'intro_body' => 'Tríade estilo Song: robe de mangas largas, camisola e saia plissada. Estampa verde-pedra como névoa e luz rosada; mangas e pregas acompanham o passo.',
        'inspire_title' => 'Nascente do desenho',
        'inspire_lines' => [
            'Os tambores de Yuyang abalaram a terra e romperam a Dança das Penas Arco-íris.',
            'A poeira ergueu-se sobre os nove portões enquanto as carruagens iam ao sudoeste.',
            'Os estandartes imperiais oscilaram e pararam a cem li a oeste da capital.',
            'Os seis exércitos não marcharam; a beldade morreu diante dos cavalos.',
        ],
        'inspire_note' => 'Tomado da Canção do Lamento Eterno de Bai Juyi—emblema da graça do traje, não discurso de mercado.',
        'original_title' => 'Ofício original',
        'original_body' => 'Corte e motivos são originais Huazhaoji. Honrem o ofício.',
        'highlight_title' => 'Na peça',
        'top_label' => 'Peito',
        'top_body' => 'A estampa do peito é fina e lisa; a flor fica suave e clara.',
        'cuff_label' => 'Mangas',
        'cuff_body' => 'Mangas largas com ampla estampa nos punhos—à vontade e refinada.',
        'skirt_label' => 'Saia',
        'skirt_body' => 'Saia plissada em degradê: barra fluida, caimento limpo.',
        'detail_title' => 'De perto',
        'detail_body' => 'Golas sobrepostas, faixa e borla na cintura; profundidade da estampa e queda da gaze.',
        'print_title' => 'Motivos',
        'print_body' => 'Asas e ramos sobre gaze leve; lavados verde-pedra no vazio.',
        'hem_title' => 'Barra',
        'hem_body' => 'As pregas aprofundam-se uma a uma; as flores seguem a queda da barra.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Brilho Nuvem Azul',
        'info_color' => 'Como na imagem',
        'info_style' => 'Estilo Song',
        'info_size' => 'XS–XL',
        'info_fabric' => 'Poliéster',
        'info_parts' => 'Robe mangas largas, camisola, saia plissada',
        'info_title' => 'À vista',
        'info_basics' => 'Básicos',
        'info_comfort' => 'Toque',
        'label_brand' => 'Marca',
        'label_name' => 'Nome',
        'label_color' => 'Cor',
        'label_style' => 'Estilo',
        'label_size' => 'Tamanhos',
        'label_fabric' => 'Tecido',
        'label_parts' => 'Peças',
        'chart_title' => 'Referência de tamanho',
        'chart_note' => 'Centímetros. Medição manual pode variar 1–3 cm.',
        't_robe' => 'Robe mangas largas',
        't_skirt' => 'Saia plissada',
        't_cam' => 'Camisola',
        'h_size' => 'Tamanho',
        'h_len' => 'Comprimento',
        'h_bust' => 'Busto',
        'h_sleeve' => 'Envergadura manga',
        'h_cuff' => 'Largura punho',
        'h_skirt_len' => 'Comprimento saia',
        'h_waist' => 'Cintura',
        'h_chest_sug' => 'Busto sugerido',
        'h_strap' => 'Largura alça',
        'alt_hero' => 'Brilho Nuvem Azul · conjunto',
        'alt_look' => 'Brilho Nuvem Azul · vestido',
        'alt_print' => 'Brilho Nuvem Azul · motivo',
        'alt_hem' => 'Brilho Nuvem Azul · barra',
        'c_thick' => 'Espessura',
        'c_thick_opts' => ['Fino', 'Médio', 'Grosso'],
        'c_thick_sel' => 'Fino',
        'c_stretch' => 'Elasticidade',
        'c_stretch_opts' => ['Nenhuma', 'Leve', 'Alta'],
        'c_stretch_sel' => 'Nenhuma',
    ],
    'id_ID' => [
        'intro_title' => 'Cahaya Awan Biru',
        'intro_body' => 'Tiga potong gaya Song: jubah lengan lebar, camisole, dan rok lipit. Cetak hijau batu bagai kabut dan cahaya merah muda; lengan dan lipit bergerak mengikuti langkah.',
        'inspire_title' => 'Sumber rancangan',
        'inspire_lines' => [
            'Genderang Yuyang mengguncang bumi dan mematahkan Tarian Bulu Pelangi.',
            'Debu naik di sembilan gerbang sementara kereta menuju barat daya.',
            'Panji kekaisaran bergoyang lalu berhenti seratus li di barat ibu kota.',
            'Enam pasukan tidak berangkat; sang jelita gugur di depan kuda.',
        ],
        'inspire_note' => 'Diambil dari Nyanyian Penyesalan Abadi Bai Juyi—lambang keanggunan busana, bukan bahasa pasar.',
        'original_title' => 'Kriya asli',
        'original_body' => 'Potongan dan motif asli Huazhaoji. Hormati kriyanya.',
        'highlight_title' => 'Pada busana',
        'top_label' => 'Dada',
        'top_body' => 'Cetak dada halus rata; cahaya bunga lembut dan jernih.',
        'cuff_label' => 'Lengan',
        'cuff_body' => 'Lengan lebar dengan cetak luas di manset—lega dan anggun.',
        'skirt_label' => 'Rok',
        'skirt_body' => 'Rok lipit bergradasi: hem mengalir, jatuh rapi.',
        'detail_title' => 'Rinci',
        'detail_body' => 'Kerah bertumpuk, sabuk dan rumbai di pinggang; kedalaman cetak dan jatuhnya kain tipis dari dekat.',
        'print_title' => 'Motif',
        'print_body' => 'Sayap dan ranting di kain tipis; cucian hijau batu di ruang kosong.',
        'hem_title' => 'Ujung rok',
        'hem_body' => 'Lipit semakin dalam lipat demi lipat; bunga mengikuti jatuhnya hem.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Cahaya Awan Biru',
        'info_color' => 'Seperti gambar',
        'info_style' => 'Gaya Song',
        'info_size' => 'XS–XL',
        'info_fabric' => 'Poliester',
        'info_parts' => 'Jubah lengan lebar, camisole, rok lipit',
        'info_title' => 'Sekilas',
        'info_basics' => 'Dasar',
        'info_comfort' => 'Rasa bahan',
        'label_brand' => 'Merek',
        'label_name' => 'Nama',
        'label_color' => 'Warna',
        'label_style' => 'Gaya',
        'label_size' => 'Ukuran',
        'label_fabric' => 'Bahan',
        'label_parts' => 'Bagian',
        'chart_title' => 'Acuan ukuran',
        'chart_note' => 'Sentimeter. Ukur tangan bisa berbeda 1–3 cm.',
        't_robe' => 'Jubah lengan lebar',
        't_skirt' => 'Rok lipit',
        't_cam' => 'Camisole',
        'h_size' => 'Ukuran',
        'h_len' => 'Panjang',
        'h_bust' => 'Dada',
        'h_sleeve' => 'Rentang lengan',
        'h_cuff' => 'Lebar manset',
        'h_skirt_len' => 'Panjang rok',
        'h_waist' => 'Pinggang',
        'h_chest_sug' => 'Dada disarankan',
        'h_strap' => 'Lebar tali',
        'alt_hero' => 'Cahaya Awan Biru · set',
        'alt_look' => 'Cahaya Awan Biru · dikenakan',
        'alt_print' => 'Cahaya Awan Biru · motif',
        'alt_hem' => 'Cahaya Awan Biru · hem',
        'c_thick' => 'Ketebalan',
        'c_thick_opts' => ['Tipis', 'Sedang', 'Tebal'],
        'c_thick_sel' => 'Tipis',
        'c_stretch' => 'Elastisitas',
        'c_stretch_opts' => ['Tidak', 'Sedikit', 'Tinggi'],
        'c_stretch_sel' => 'Tidak',
    ],
    'hi_IN' => [
        'intro_title' => 'नील मेघ आभा',
        'intro_body' => 'सोंग शैली का त्रय: चौड़ी आस्तीन वाला चोगा, कैमिसोल और प्लीटेड स्कर्ट। पत्थर-हरे प्रिंट धुंध और गुलाबी प्रकाश से खुलते हैं; आस्तीन और प्लीट कदम के संग चलते हैं।',
        'inspire_title' => 'डिज़ाइन का स्रोत',
        'inspire_lines' => [
            'युयांग के नगाड़ों ने धरती हिलाई और इंद्रधनुषी पंख नृत्य तोड़ दिया।',
            'नौ द्वारों पर धूल उठी जब रथ दक्षिण-पश्चिम गए।',
            'शाही ध्वज डोले फिर राजधानी से सौ ली पश्चिम रुक गए।',
            'छह सेनाएँ नहीं चलीं; सुंदरी घोड़ों के आगे गिर गई।',
        ],
        'inspire_note' => 'बाई जूयी के «शाश्वत पछतावे के गीत» से—वस्त्र की कृपा का प्रतीक, बाज़ार की भाषा नहीं।',
        'original_title' => 'मूल शिल्प',
        'original_body' => 'काट और रूपांक हुआझाओजी के मौलिक हैं। शिल्प का सम्मान करें।',
        'highlight_title' => 'वस्त्र पर',
        'top_label' => 'ऊपरी भाग',
        'top_body' => 'छाती का प्रिंट चिकना और हल्का—शांत चमक।',
        'cuff_label' => 'आस्तीन',
        'cuff_body' => 'चौड़ी आस्तीन, कफ पर विस्तृत प्रिंट—आरामदायक और परिष्कृत।',
        'skirt_label' => 'स्कर्ट',
        'skirt_body' => 'ग्रेडिएंट प्लीटेड स्कर्ट: तरल हेम, साफ़ लटकन।',
        'detail_title' => 'निकट दृष्टि',
        'detail_body' => 'परतदार कॉलर, कमर पर फीता और लटकन; प्रिंट की गहराई और जाली का गिरना।',
        'print_title' => 'रूपांक',
        'print_body' => 'पतले कपड़े पर पंख और शाखाएँ; खुले स्थान में पत्थर-हरा धुलन।',
        'hem_title' => 'हेम',
        'hem_body' => 'प्लीट तह-दर-तह गहरी होती हैं; फूल हेम के गिरने का अनुसरण करते हैं।',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'नील मेघ आभा',
        'info_color' => 'जैसा चित्र में',
        'info_style' => 'सोंग शैली',
        'info_size' => 'XS–XL',
        'info_fabric' => 'पॉलिएस्टर',
        'info_parts' => 'चौड़ी आस्तीन चोगा, कैमिसोल, प्लीटेड स्कर्ट',
        'info_title' => 'एक नज़र में',
        'info_basics' => 'मूल',
        'info_comfort' => 'स्पर्श',
        'label_brand' => 'ब्रांड',
        'label_name' => 'नाम',
        'label_color' => 'रंग',
        'label_style' => 'शैली',
        'label_size' => 'आकार',
        'label_fabric' => 'कपड़ा',
        'label_parts' => 'भाग',
        'chart_title' => 'आकार संदर्भ',
        'chart_note' => 'सेंटीमीटर। हाथ माप में 1–3 सेमी अंतर हो सकता है।',
        't_robe' => 'चौड़ी आस्तीन चोगा',
        't_skirt' => 'प्लीटेड स्कर्ट',
        't_cam' => 'कैमिसोल',
        'h_size' => 'आकार',
        'h_len' => 'लंबाई',
        'h_bust' => 'छाती',
        'h_sleeve' => 'आस्तीन फैलाव',
        'h_cuff' => 'कफ चौड़ाई',
        'h_skirt_len' => 'स्कर्ट लंबाई',
        'h_waist' => 'कमर',
        'h_chest_sug' => 'सुझाई छाती',
        'h_strap' => 'पट्टी चौड़ाई',
        'alt_hero' => 'नील मेघ आभा · सेट',
        'alt_look' => 'नील मेघ आभा · पहना',
        'alt_print' => 'नील मेघ आभा · रूपांक',
        'alt_hem' => 'नील मेघ आभा · हेम',
        'c_thick' => 'मोटाई',
        'c_thick_opts' => ['पतला', 'मध्यम', 'मोटा'],
        'c_thick_sel' => 'पतला',
        'c_stretch' => 'खिंचाव',
        'c_stretch_opts' => ['नहीं', 'हल्का', 'अधिक'],
        'c_stretch_sel' => 'नहीं',
    ],
    'bn_BD' => [
        'intro_title' => 'নীল মেঘের আভা',
        'intro_body' => 'সং শৈলীর ত্রয়ী: প্রশস্ত হাতার পোশাক, ক্যামিসোল ও প্লিটেড স্কার্ট। পাথর-সবুজ ছাপা কুয়াশা ও গোলাপি আলোর মতো; হাতা ও ভাঁজ পায়ে পায়ে চলে।',
        'inspire_title' => 'নকশার উৎস',
        'inspire_lines' => [
            'ইউইয়াং-এর ঢোল পৃথিবী কাঁপিয়ে রংধনু পালকের নৃত্য ভেঙে দিল।',
            'নয়টি ফটকে ধুলো উঠল যখন রথ দক্ষিণ-পশ্চিমে গেল।',
            'রাজকীয় পতাকা দুলল, তারপর রাজধানীর পশ্চিমে একশো লি দূরে থামল।',
            'ছয় সেনা চলল না; সুন্দরী ঘোড়ার সামনে পড়ল।',
        ],
        'inspire_note' => 'বাই জুয়ির «চিরন্তন অনুশোচনার গান» থেকে—পোশাকের কোমলতার প্রতীক, বাজারের ভাষা নয়।',
        'original_title' => 'মূল শিল্প',
        'original_body' => 'কাট ও মোটিফ হুয়াজাওজির মৌলিক। শিল্পকে সম্মান করুন।',
        'highlight_title' => 'পোশাকে',
        'top_label' => 'বুক',
        'top_body' => 'বুকের ছাপা মসৃণ হালকা—শান্ত উজ্জ্বল।',
        'cuff_label' => 'হাতা',
        'cuff_body' => 'প্রশস্ত হাতা, কাফে বিস্তৃত ছাপা—আরাম ও সৌন্দর্য।',
        'skirt_label' => 'স্কার্ট',
        'skirt_body' => 'গ্রেডিয়েন্ট প্লিটেড স্কার্ট: তরল হেম, পরিষ্কার ঝুলে পড়া।',
        'detail_title' => 'কাছ থেকে',
        'detail_body' => 'স্তরিত কলার, কোমরে ফিতা ও ঝালর; ছাপার গভীরতা ও জালির পতন।',
        'print_title' => 'মোটিফ',
        'print_body' => 'পাতলা কাপড়ে ডানা ও ডাল; খোলা জায়গায় পাথর-সবুজ ধোয়া।',
        'hem_title' => 'হেম',
        'hem_body' => 'প্লিট ভাঁজে ভাঁজে গভীর হয়; ফুল হেমের পতন অনুসরণ করে।',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'নীল মেঘের আভা',
        'info_color' => 'ছবির মতো',
        'info_style' => 'সং শৈলী',
        'info_size' => 'XS–XL',
        'info_fabric' => 'পলিয়েস্টার',
        'info_parts' => 'প্রশস্ত হাতার পোশাক, ক্যামিসোল, প্লিটেড স্কার্ট',
        'info_title' => 'এক নজরে',
        'info_basics' => 'মূল',
        'info_comfort' => 'স্পর্শ',
        'label_brand' => 'ব্র্যান্ড',
        'label_name' => 'নাম',
        'label_color' => 'রঙ',
        'label_style' => 'শৈলী',
        'label_size' => 'মাপ',
        'label_fabric' => 'কাপড়',
        'label_parts' => 'অংশ',
        'chart_title' => 'মাপের রেফারেন্স',
        'chart_note' => 'সেন্টিমিটার। হাতে মাপে ১–৩ সেমি ফারাক হতে পারে।',
        't_robe' => 'প্রশস্ত হাতার পোশাক',
        't_skirt' => 'প্লিটেড স্কার্ট',
        't_cam' => 'ক্যামিসোল',
        'h_size' => 'মাপ',
        'h_len' => 'দৈর্ঘ্য',
        'h_bust' => 'বুক',
        'h_sleeve' => 'হাতার বিস্তার',
        'h_cuff' => 'কাফের প্রস্থ',
        'h_skirt_len' => 'স্কার্টের দৈর্ঘ্য',
        'h_waist' => 'কোমর',
        'h_chest_sug' => 'প্রস্তাবিত বুক',
        'h_strap' => 'স্ট্র্যাপের প্রস্থ',
        'alt_hero' => 'নীল মেঘের আভা · সেট',
        'alt_look' => 'নীল মেঘের আভা · পরা',
        'alt_print' => 'নীল মেঘের আভা · মোটিফ',
        'alt_hem' => 'নীল মেঘের আভা · হেম',
        'c_thick' => 'পুরুত্ব',
        'c_thick_opts' => ['পাতলা', 'মাঝারি', 'মোটা'],
        'c_thick_sel' => 'পাতলা',
        'c_stretch' => 'ইলাস্টিসিটি',
        'c_stretch_opts' => ['নেই', 'হালকা', 'বেশি'],
        'c_stretch_sel' => 'নেই',
    ],
    'ur_PK' => [
        'intro_title' => 'نیل بادلوں کی چمک',
        'intro_body' => 'سونگ طرز کا سہ گانا: چوڑی آستین کا چوغہ، کمیسول اور پلیٹڈ اسکرٹ۔ پتھر سبز چھپائی دھند اور گلابی روشنی کی مانند؛ آستینیں اور پلیٹ قدم کے ساتھ چلتی ہیں۔',
        'inspire_title' => 'ڈیزائن کا چشمہ',
        'inspire_lines' => [
            'یویانگ کے ڈھولوں نے زمین ہلا دی اور قوس قزح پر کے رقص کو توڑ دیا۔',
            'نو دروازوں پر گرد اٹھی جب رتھ جنوب مغرب گئے۔',
            'شاہی جھنڈے لہرائے پھر دارالحکومت سے سو لی مغرب رک گئے۔',
            'چھ فوجیں نہ چلیں؛ حسن گھوڑوں کے آگے گر گیا۔',
        ],
        'inspire_note' => 'بائی جوئی کے «دائمی حسرت کے گیت» سے—لباس کی نزاکت کی علامت، بازاری زبان نہیں۔',
        'original_title' => 'اصل دستکاری',
        'original_body' => 'کٹ اور نقوش ہواژاوجی کے اصل ہیں۔ دستکاری کا احترام کریں۔',
        'highlight_title' => 'لباس پر',
        'top_label' => 'سینہ',
        'top_body' => 'سینے کی چھپائی ہموار اور ہلکی—خاموش چمک۔',
        'cuff_label' => 'آستینیں',
        'cuff_body' => 'چوڑی آستینیں، کف پر وسیع چھپائی—آرام دہ اور مہذب۔',
        'skirt_label' => 'اسکرٹ',
        'skirt_body' => 'گریڈیئنٹ پلیٹڈ اسکرٹ: بہتی ہیم، صاف لٹک۔',
        'detail_title' => 'قریب سے',
        'detail_body' => 'تہ دار کالر، کمر پر فیتہ اور جھالر؛ چھپائی کی گہرائی اور جالی کا گرنا۔',
        'print_title' => 'نقوش',
        'print_body' => 'پتلی کپڑے پر پروں اور شاخیں؛ کھلی جگہ میں پتھر سبز دھلائی۔',
        'hem_title' => 'ہیم',
        'hem_body' => 'پلیٹ تہ در تہ گہری ہوتی ہیں؛ پھول ہیم کے گرنے کی پیروی کرتے ہیں۔',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'نیل بادلوں کی چمک',
        'info_color' => 'جیسا تصویر میں',
        'info_style' => 'سونگ طرز',
        'info_size' => 'XS–XL',
        'info_fabric' => 'پالی ایسٹر',
        'info_parts' => 'چوڑی آستین چوغہ، کمیسول، پلیٹڈ اسکرٹ',
        'info_title' => 'ایک نظر میں',
        'info_basics' => 'بنیادی',
        'info_comfort' => 'چھونے کا احساس',
        'label_brand' => 'برانڈ',
        'label_name' => 'نام',
        'label_color' => 'رنگ',
        'label_style' => 'طرز',
        'label_size' => 'سائز',
        'label_fabric' => 'کپڑا',
        'label_parts' => 'حصے',
        'chart_title' => 'سائز حوالہ',
        'chart_note' => 'سینٹی میٹر۔ ہاتھ کی پیمائش میں ۱–۳ سینٹی میٹر فرق ہو سکتا ہے۔',
        't_robe' => 'چوڑی آستین چوغہ',
        't_skirt' => 'پلیٹڈ اسکرٹ',
        't_cam' => 'کمیسول',
        'h_size' => 'سائز',
        'h_len' => 'لمبائی',
        'h_bust' => 'سینہ',
        'h_sleeve' => 'آستین پھیلاؤ',
        'h_cuff' => 'کف چوڑائی',
        'h_skirt_len' => 'اسکرٹ لمبائی',
        'h_waist' => 'کمر',
        'h_chest_sug' => 'مجوزہ سینہ',
        'h_strap' => 'پٹی چوڑائی',
        'alt_hero' => 'نیل بادلوں کی چمک · سیٹ',
        'alt_look' => 'نیل بادلوں کی چمک · پہنا',
        'alt_print' => 'نیل بادلوں کی چمک · نقش',
        'alt_hem' => 'نیل بادلوں کی چمک · ہیم',
        'c_thick' => 'موٹائی',
        'c_thick_opts' => ['پتلا', 'درمیانہ', 'موٹا'],
        'c_thick_sel' => 'پتلا',
        'c_stretch' => 'کھینچاؤ',
        'c_stretch_opts' => ['نہیں', 'ہلکا', 'زیادہ'],
        'c_stretch_sel' => 'نہیں',
    ],
];

$assemble = static function (array $t) use ($A, $img, $feature, $figureStack, $h): string {
    /** @var list<string> $lines */
    $lines = $t['inspire_lines'];

    $intro = '<div class="weline-detail-prose"><h3>' . $h((string)$t['intro_title']) . '</h3><p>'
        . $h((string)$t['intro_body']) . '</p></div>';

    $hero = $figureStack([
        $img($A['hero'], (string)$t['alt_hero'], 1200, 1200),
        $img($A['look01'], (string)$t['alt_look'] . ' 1', 790, 1099),
    ]);

    $inspire = '<div class="weline-detail-prose"><h3>' . $h((string)$t['inspire_title']) . '</h3>';
    foreach ($lines as $line) {
        $inspire .= '<p>' . $h($line) . '</p>';
    }
    $inspire .= '<p class="weline-detail-feature__note">' . $h((string)$t['inspire_note']) . '</p></div>';

    $original = '<div class="weline-detail-prose"><h3>' . $h((string)$t['original_title']) . '</h3><p>'
        . $h((string)$t['original_body']) . '</p></div>';

    $looksA = $figureStack([
        $img($A['look08'], (string)$t['alt_look'] . ' 2', 790, 1082),
        $img($A['look09'], (string)$t['alt_look'] . ' 3', 790, 1109),
    ]);

    $highlight = $feature(
        $img($A['look10'], (string)$t['alt_look'] . ' 4', 790, 1089),
        '<h3>' . $h((string)$t['highlight_title']) . '</h3>'
        . '<h4>' . $h((string)$t['top_label']) . '</h4><p>' . $h((string)$t['top_body']) . '</p>'
        . '<h4>' . $h((string)$t['cuff_label']) . '</h4><p>' . $h((string)$t['cuff_body']) . '</p>',
        false,
    );

    $skirt = $feature(
        $img($A['look11'], (string)$t['alt_look'] . ' 5', 790, 1078),
        '<h3>' . $h((string)$t['skirt_label']) . '</h3><p>' . $h((string)$t['skirt_body']) . '</p>',
        true,
    );

    /** @var list<string> $thickOpts */
    $thickOpts = $t['c_thick_opts'];
    /** @var list<string> $stretchOpts */
    $stretchOpts = $t['c_stretch_opts'];
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
            ['label' => (string)$t['c_thick'], 'options' => $thickOpts, 'selected' => (string)$t['c_thick_sel']],
            ['label' => (string)$t['c_stretch'], 'options' => $stretchOpts, 'selected' => (string)$t['c_stretch_sel']],
        ],
        (string)$t['info_title'],
        (string)$t['info_basics'],
        (string)$t['info_comfort'],
    );

    $chart = DetailDescriptionTextifier::buildMeasurementSizeChartZh(
        [
            [
                'title' => (string)$t['t_robe'],
                'headers' => [(string)$t['h_size'], (string)$t['h_len'], (string)$t['h_bust'], (string)$t['h_sleeve'], (string)$t['h_cuff']],
                'rows' => [
                    ['XS', '118', '115', '175', '88'],
                    ['S', '120', '116', '180', '90'],
                    ['M', '123', '122', '185', '92'],
                    ['L', '126', '124', '190', '94'],
                    ['XL', '128', '126', '195', '96'],
                ],
            ],
            [
                'title' => (string)$t['t_skirt'],
                'headers' => [(string)$t['h_size'], (string)$t['h_skirt_len'], (string)$t['h_waist']],
                'rows' => [
                    ['XS', '95', '96'],
                    ['S', '96', '98'],
                    ['M', '100', '102'],
                    ['L', '105', '104'],
                    ['XL', '110', '106'],
                ],
            ],
            [
                'title' => (string)$t['t_cam'],
                'headers' => [(string)$t['h_size'], (string)$t['h_len'], (string)$t['h_chest_sug'], (string)$t['h_strap']],
                'rows' => [
                    ['XS', '35', '≤83', '1'],
                    ['S', '40', '≤84', '1'],
                    ['M', '42', '≤90', '1'],
                    ['L', '44', '≤94', '1'],
                    ['XL', '46', '≤100', '1'],
                ],
            ],
        ],
        (string)$t['chart_title'],
        (string)$t['chart_note'],
    );

    $looksB = $figureStack([
        $img($A['look12'], (string)$t['alt_look'] . ' 6', 790, 1076),
        $img($A['look13'], (string)$t['alt_look'] . ' 7', 790, 1056),
    ]);

    $detail = $feature(
        $img($A['print'], (string)$t['alt_print'], 790, 367),
        '<h3>' . $h((string)$t['detail_title']) . '</h3><p>' . $h((string)$t['detail_body']) . '</p>'
        . '<h4>' . $h((string)$t['print_title']) . '</h4><p>' . $h((string)$t['print_body']) . '</p>',
        false,
    );

    $hem = $feature(
        $img($A['hem'], (string)$t['alt_hem'], 790, 868),
        '<h3>' . $h((string)$t['hem_title']) . '</h3><p>' . $h((string)$t['hem_body']) . '</p>',
        true,
    );

    return '<div data-weline-product-description="1688">'
        . $intro . $hero . $inspire . $original . $looksA
        . $highlight . $skirt . $info . $chart . $looksB . $detail . $hem
        . '</div>';
};

// 站内启用 locale → 各自完整文案键（禁止非英语映射到 en_US）
$localePlan = [
    'zh_Hans_CN' => 'zh_Hans_CN',
    '' => 'zh_Hans_CN',
    'en_US' => 'en_US',
    'es_ES' => 'es_ES',
    'fr_FR' => 'fr_FR',
    'pt_BR' => 'pt_BR',
    'id_ID' => 'id_ID',
    'ar_SA' => 'ar_SA',
    'bn_BD' => 'bn_BD',
    'hi_IN' => 'hi_IN',
    'ur_PK' => 'ur_PK',
];

$enLeakMarkers = ['Design wellspring', 'Original craft', 'On the garment', 'At a glance'];

$writes = [];
foreach ($localePlan as $locale => $baseKey) {
    if (!isset($copy[$baseKey])) {
        fwrite(STDERR, "Missing locale pack: {$baseKey}\n");
        exit(2);
    }
    $html = $assemble($copy[$baseKey]);
    $banned = ['一件代发', '混批', '厂家直销', '旺旺', '货源', '阿里巴巴', '淘宝', '天猫', '拼多多', 'dropship', 'Dropship'];
    foreach ($banned as $b) {
        if (str_contains($html, $b)) {
            fwrite(STDERR, "Banned phrase in {$locale}: {$b}\n");
            exit(2);
        }
    }
    if (preg_match('/1688/', preg_replace('/data-weline-product-description="1688"/', '', $html) ?? $html)) {
        fwrite(STDERR, "Visible 1688 leak in {$locale}\n");
        exit(2);
    }
    if ($baseKey !== 'en_US') {
        foreach ($enLeakMarkers as $marker) {
            if (str_contains($html, $marker)) {
                fwrite(STDERR, "EN dump into {$locale}: {$marker}\n");
                exit(2);
            }
        }
    }
    $writes[] = [
        'locale' => $locale,
        'html' => $html,
        'len' => strlen($html),
        'features' => substr_count($html, 'weline-detail-feature'),
        'imgs' => substr_count($html, '<img '),
    ];
}

foreach ($writes as $w) {
    echo sprintf(
        "%s\t%d\tfeature=%d\timgs=%d\n",
        $w['locale'] === '' ? '(empty)' : $w['locale'],
        $w['len'],
        $w['features'],
        $w['imgs'],
    );
}

if (!$apply) {
    echo "Dry-run. Pass --apply to write description only.\n";
    exit(0);
}

/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);

foreach ($writes as $w) {
    $locale = (string)$w['locale'];
    $html = (string)$w['html'];
    if ($locale !== '') {
        LocalDescription::upsertQuiet($productId, $locale, [
            LocalDescription::schema_fields_DESCRIPTION => $html,
        ]);
    }
    $attributes->writeExplicit(
        $websiteId,
        0,
        'product',
        $productId,
        'description',
        $locale,
        $html,
        true,
    );
}

ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)->notifyCatalogChanged(
    $websiteId,
    'detail_locale_true_translate_196',
    ['product_ids' => [$productId]],
);

echo "Applied description-only writes: " . count($writes) . " locales.\n";
