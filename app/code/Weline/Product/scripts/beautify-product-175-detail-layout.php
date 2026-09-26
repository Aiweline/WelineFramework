<?php

declare(strict_types=1);

/**
 * #175 花朝记·四季春 · 唐制诃子裙刺绣大袖衫 · 详情杂志排版（强制）
 *
 * 分流：
 * | 资产 | 类型 | 动作 |
 * | fcb6f816 | caption_board(四季春烤字) | 弃用入栏 |
 * | c4639f19 | info_chart | textify→HTML |
 * | 95bf871a | size_chart | textify→HTML |
 * | b506b46c / 3a7ee03b / 99f78ddc / 67a824d5 / 194d4d15 | photo | 入杂志 |
 *
 * 卖点：立体花绣诃子 · 通袖薄纱大袖 · 渐变褶裙 · 唐制齐胸
 * 原型：lead → verse → poem-aside → feature → size → pair → prose → pair → quiet → bento → checklist → info → close
 *
 * php app/code/Weline/Product/scripts/beautify-product-175-detail-layout.php --dry-run
 * php app/code/Weline/Product/scripts/beautify-product-175-detail-layout.php --apply --website=0
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Model\Product\LocalDescription;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Service\DetailDescriptionTextifier;
use Weline\Product\Service\ProductStorefrontCacheInvalidator;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run', 'website:']);
$apply = isset($options['apply']) && !isset($options['dry-run']);
$websiteId = max(0, (int)($options['website'] ?? 0));
$productId = 175;

$fullZhName = '新款唐制汉服女诃子裙大袖衫刺绣古装超仙国风日常汉服春夏';
$shortZh = '四季春·唐制诃子裙刺绣大袖衫';
$shortEn = 'Sijichun · Tang Hezi skirt & embroidered wide sleeves';

/** @var array<string, array{id:string,w:int,h:int}> $A */
$A = [
    'hero' => ['id' => '194d4d15-7bcd-49cc-ad7c-3d95e1292ead', 'w' => 708, 'h' => 1000],
    'look_green' => ['id' => '99f78ddc-f8b4-4989-b4b3-6c3ac9b90f64', 'w' => 708, 'h' => 1000],
    'look_peach' => ['id' => '3a7ee03b-58ac-4813-b98d-c517d3658403', 'w' => 708, 'h' => 1000],
    'look_fabric' => ['id' => 'b506b46c-c6ac-486c-9212-5f75745fa70a', 'w' => 750, 'h' => 1140],
    'look_model' => ['id' => '67a824d5-afa2-4f2e-9f21-2204eefd1d0c', 'w' => 750, 'h' => 1186],
];

$sizeTables = [
    [
        'title_zh' => '大袖衫',
        'title_en' => 'Wide-sleeve robe',
        'headers_zh' => ['尺码', 'S', 'M', 'L'],
        'headers_en' => ['Size', 'S', 'M', 'L'],
        'rows_zh' => [
            ['衣长', '112', '115', '118'],
            ['胸围', '106', '110', '118'],
            ['通袖长', '186', '190', '194'],
        ],
        'rows_en' => [
            ['Length', '112', '115', '118'],
            ['Bust', '106', '110', '118'],
            ['Sleeve span', '186', '190', '194'],
        ],
    ],
    [
        'title_zh' => '诃子裙',
        'title_en' => 'Hezi skirt',
        'headers_zh' => ['尺码', 'S', 'M', 'L'],
        'headers_en' => ['Size', 'S', 'M', 'L'],
        'rows_zh' => [
            ['裙长', '116', '122', '126'],
            ['系带长', '150', '150', '150'],
        ],
        'rows_en' => [
            ['Skirt length', '116', '122', '126'],
            ['Tie length', '150', '150', '150'],
        ],
    ],
];

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$img = static function (array $a, string $alt) use ($h): string {
    return '<img src="asset://' . $h((string)$a['id']) . '" alt="' . $h($alt)
        . '" loading="lazy" decoding="async" width="' . (int)$a['w'] . '" height="' . (int)$a['h'] . '">';
};

$figureStack = static function (array $imgs, string $mod = ''): string {
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
    $cls = 'weline-detail-figure-stack' . ($mod !== '' ? ' ' . $mod : '');

    return '<div class="' . $cls . '">' . $rows . '</div>';
};

$tagReveal = static function (string $html): string {
    $out = preg_replace_callback(
        '/<(div)\s+class="(weline-detail-(?:prose|feature|figure-stack|figure-row|bento|text|quiet-spacer)[^"]*)"/i',
        static function (array $m): string {
            $cls = $m[2];
            if (str_contains($cls, 'weline-detail-reveal')) {
                return '<div class="' . $cls . '"';
            }

            return '<div class="' . $cls . ' weline-detail-reveal" data-weline-detail-reveal="1"';
        },
        $html
    );

    return is_string($out) ? $out : $html;
};

$sizeChartFor = static function (string $locale) use ($sizeTables): string {
    $isZh = str_starts_with($locale, 'zh');
    $tables = [];
    foreach ($sizeTables as $t) {
        $tables[] = [
            'title' => $isZh ? $t['title_zh'] : $t['title_en'],
            'headers' => $isZh ? $t['headers_zh'] : $t['headers_en'],
            'rows' => $isZh ? $t['rows_zh'] : $t['rows_en'],
        ];
    }
    if ($isZh) {
        return DetailDescriptionTextifier::buildMeasurementSizeChartZh($tables);
    }

    return DetailDescriptionTextifier::buildMeasurementSizeChartEn($tables);
};

/**
 * @return array<string, mixed>
 */
$baseCopy = static function (string $locale) use ($shortZh, $shortEn, $sizeChartFor): array {
    $packs = [];

    $packs['zh_Hans_CN'] = [
        'intro_title' => $shortZh,
        'intro_body' => '唐制齐胸诃子裙配刺绣大袖衫：立体花绣、珠边收口、通袖薄纱与渐变褶裙，以本店实拍为准。',
        'inspire_title' => '设计心源',
        'inspire_lines' => [
            '以「四季春」写春夏仙气——绣在诃子，风在大袖，色在褶间。',
            '碧绿与粉桃两色可循规格轴切换；形制同属唐制齐胸套装。',
        ],
        'inspire_note' => '绣纹、珠边与渐变请对照实拍，不编造面料参数。',
        'poem_eyebrow' => '诗意旁笺',
        'poem_lines' => ['绣开花骨', '大袖生风', '齐胸承春'],
        'look_title' => '通身气韵',
        'look_body' => '半身与侧影交错：诃子立体花、通袖开合、裙幅垂落一目可读。',
        'look_note' => '规格轴「碧绿全套 / 粉桃」等变体以变体图为准。',
        'macro_title' => '细处可辨',
        'macro_body' => '近景可见绣线层叠、珠边与薄纱透光；证据在图，不写空话。',
        'bento_title' => '衣袂可记',
        'bento_style_label' => '制式',
        'bento_style' => '唐制',
        'bento_parts_label' => '部件',
        'bento_parts' => '大袖衫 · 齐胸诃子裙',
        'bento_a' => '立体花绣与珠边以实拍为准',
        'bento_b' => '按胸围与身高对照尺码表',
        'quiet_line' => '衣在身上，韵在水边。',
        'checklist_title' => '护衣小笺',
        'checklist' => [
            '建议手洗、分色洗涤，不可漂白。',
            '悬挂晾干，避暴晒；低温熨烫并垫布。',
            '大袖与绣片宜轻挂，避免长期重压。',
        ],
        'info_title' => '形制一览',
        'info_basics' => '基本信息',
        'info_comfort' => '舒适度',
        'label_brand' => '品牌',
        'label_name' => '品名',
        'label_color' => '颜色',
        'label_style' => '制式',
        'label_size' => '尺码',
        'label_fabric' => '面料',
        'label_parts' => '部件',
        'info_brand' => '长安汉服',
        'info_name' => '四季春',
        'info_color' => '图片色（规格轴可选）',
        'info_style' => '唐制',
        'info_size' => 'S–L（见尺码表）',
        'info_fabric' => '亲肤柔软',
        'info_parts' => '大袖衫、齐胸诃子裙',
        'c_thick' => '厚薄',
        'c_thick_opts' => ['超薄', '微薄', '适中', '厚'],
        'c_thick_sel' => '微薄',
        'c_stretch' => '弹力',
        'c_stretch_opts' => ['无弹', '微弹', '适中', '弹力'],
        'c_stretch_sel' => '无弹',
        'c_soft' => '柔软',
        'c_soft_opts' => ['柔软', '偏软', '适中', '偏硬'],
        'c_soft_sel' => '柔软',
        'c_fit' => '版型',
        'c_fit_opts' => ['紧身', '修身', '合体', '宽松'],
        'c_fit_sel' => '宽松',
        'size_title' => '尺码参照',
        'size_body' => '请按测量表与胸围、身高挑选；手工测量或有 1–3 cm 出入，以实物为准。',
        'original_title' => '原创心迹',
        'original_body' => '花朝记「四季春」形制与绣纹以本店实拍为准；敬请珍惜衣冠。',
        'close_caption' => $shortZh . ' · 唐制春夏',
        'alt_hero' => $shortZh . ' · 着装',
        'alt_look' => $shortZh . ' · 着装',
        'alt_macro' => $shortZh . ' · 绣纹细部',
        'size_chart_html' => $sizeChartFor('zh_Hans_CN'),
        'lang' => 'zh-Hans',
    ];

    $packs['en_US'] = [
        'intro_title' => $shortEn,
        'intro_body' => 'Tang-style chest-high Hezi skirt with embroidered wide sleeves: raised florals, pearl trim, sheer sleeve span and ombré pleats—follow our studio photos.',
        'inspire_title' => 'Design wellspring',
        'inspire_lines' => [
            '“Sijichun” (Four Seasons Spring): embroidery on the Hezi, air in the sleeves, color in the folds.',
            'Jasper-green and peach variants switch on the size/style axis; the cut stays Tang chest-high.',
        ],
        'inspire_note' => 'Match stitch, pearls and gradient to the photos—no invented fabric specs.',
        'poem_eyebrow' => 'Side verse',
        'poem_lines' => ['Florals rise in stitch', 'Sleeves catch the breeze', 'Spring at the chest'],
        'look_title' => 'Full-body rhythm',
        'look_body' => 'Mid and profile shots cross so Hezi florals, sleeve open-close and hem fall read at a glance.',
        'look_note' => 'Variants such as “full jasper-green set” follow the variant axis.',
        'macro_title' => 'Readable up close',
        'macro_body' => 'Close frames show layered stitch, pearl edge and sheer light—evidence only.',
        'bento_title' => 'Worth noting',
        'bento_style_label' => 'Cut',
        'bento_style' => 'Tang-style',
        'bento_parts_label' => 'Parts',
        'bento_parts' => 'Wide-sleeve robe · chest-high Hezi skirt',
        'bento_a' => 'Raised florals & pearls follow macros',
        'bento_b' => 'Pick size by bust and height on the chart',
        'quiet_line' => 'Cloth on the body; air by the water.',
        'checklist_title' => 'Care notes',
        'checklist' => [
            'Hand wash separately; do not bleach.',
            'Hang dry; avoid harsh sun; low iron with a cloth.',
            'Hang wide sleeves and embroidery lightly—avoid long heavy creasing.',
        ],
        'info_title' => 'At a glance',
        'info_basics' => 'Basics',
        'info_comfort' => 'Comfort & fit',
        'label_brand' => 'Brand',
        'label_name' => 'Name',
        'label_color' => 'Color',
        'label_style' => 'Style',
        'label_size' => 'Size',
        'label_fabric' => 'Fabric',
        'label_parts' => 'Parts',
        'info_brand' => "Chang'an Hanfu",
        'info_name' => 'Sijichun',
        'info_color' => 'As pictured (see variant axis)',
        'info_style' => 'Tang-style',
        'info_size' => 'S–L (see size chart)',
        'info_fabric' => 'Soft and skin-friendly',
        'info_parts' => 'Wide-sleeve robe, chest-high Hezi skirt',
        'c_thick' => 'Thickness',
        'c_thick_opts' => ['Ultra-thin', 'Lightweight', 'Moderate', 'Thick'],
        'c_thick_sel' => 'Lightweight',
        'c_stretch' => 'Stretch',
        'c_stretch_opts' => ['None', 'Slight', 'Moderate', 'Stretchy'],
        'c_stretch_sel' => 'None',
        'c_soft' => 'Softness',
        'c_soft_opts' => ['Soft', 'Slightly soft', 'Moderate', 'Firm'],
        'c_soft_sel' => 'Soft',
        'c_fit' => 'Fit',
        'c_fit_opts' => ['Tight', 'Slim', 'Regular', 'Relaxed'],
        'c_fit_sel' => 'Relaxed',
        'size_title' => 'Sizing',
        'size_body' => 'Match the tables to bust and height; hand measure may vary by 1–3 cm—the garment wins.',
        'original_title' => 'Original craft',
        'original_body' => 'Huazhaoji “Sijichun” cut and embroidery follow our studio photos—honor the craft.',
        'close_caption' => $shortEn . ' · Tang spring–summer',
        'alt_hero' => $shortEn . ' · worn',
        'alt_look' => $shortEn . ' · worn',
        'alt_macro' => $shortEn . ' · embroidery detail',
        'size_chart_html' => $sizeChartFor('en_US'),
        'lang' => 'en',
    ];

    $packs['es_ES'] = array_merge($packs['en_US'], [
        'intro_title' => 'Sijichun · Falda Hezi Tang y mangas bordadas',
        'intro_body' => 'Falda Hezi a la altura del pecho estilo Tang con mangas amplias bordadas: flores en relieve, perlas, gasa y pliegues degradados—según nuestras fotos de estudio.',
        'inspire_title' => 'Fuente del diseño',
        'inspire_lines' => [
            '“Sijichun” (primavera de las cuatro estaciones): el bordado en el Hezi, el aire en las mangas, el color en los pliegues.',
            'Las variantes verde jaspe y melocotón se eligen en el eje de tallas; el corte sigue siendo Tang.',
        ],
        'inspire_note' => 'Compare puntada, perlas y degradado con las fotos—sin inventar fichas de tela.',
        'poem_eyebrow' => 'Verso al lado',
        'poem_lines' => ['Flor en relieve', 'Mangas al viento', 'Primavera al pecho'],
        'look_title' => 'Ritmo de cuerpo entero',
        'look_body' => 'Planos medios y de perfil cruzan flor del Hezi, apertura de manga y caída del bajo.',
        'look_note' => 'Variantes como el set verde jaspe siguen el eje de variantes.',
        'macro_title' => 'Legible de cerca',
        'macro_body' => 'Los primeros planos muestran capas de hilo, borde de perlas y luz a través de la gasa.',
        'bento_title' => 'Para recordar',
        'bento_style_label' => 'Corte',
        'bento_style' => 'Estilo Tang',
        'bento_parts_label' => 'Piezas',
        'bento_parts' => 'Túnica de mangas amplias · falda Hezi',
        'bento_a' => 'Flores y perlas según macros',
        'bento_b' => 'Elija talla por pecho y altura',
        'quiet_line' => 'Tela en el cuerpo; aire junto al agua.',
        'checklist_title' => 'Cuidado',
        'checklist' => [
            'Lavar a mano por separado; no blanquear.',
            'Secar colgado; evitar sol fuerte; plancha baja con paño.',
            'Cuelgue mangas y bordados con cuidado—evite pliegues pesados.',
        ],
        'info_title' => 'De un vistazo',
        'info_basics' => 'Básicos',
        'info_comfort' => 'Confort',
        'label_brand' => 'Marca',
        'label_name' => 'Nombre',
        'label_color' => 'Color',
        'label_style' => 'Estilo',
        'label_size' => 'Talla',
        'label_fabric' => 'Tela',
        'label_parts' => 'Piezas',
        'info_brand' => "Chang'an Hanfu",
        'info_name' => 'Sijichun',
        'info_color' => 'Como en la foto (eje de variantes)',
        'info_style' => 'Estilo Tang',
        'info_size' => 'S–L (ver tabla)',
        'info_fabric' => 'Suave y agradable a la piel',
        'info_parts' => 'Túnica de mangas amplias, falda Hezi',
        'c_thick' => 'Grosor',
        'c_thick_opts' => ['Ultrafino', 'Ligero', 'Medio', 'Grueso'],
        'c_thick_sel' => 'Ligero',
        'c_stretch' => 'Elasticidad',
        'c_stretch_opts' => ['Ninguna', 'Leve', 'Media', 'Elástica'],
        'c_stretch_sel' => 'Ninguna',
        'c_soft' => 'Suavidad',
        'c_soft_opts' => ['Suave', 'Algo suave', 'Media', 'Firme'],
        'c_soft_sel' => 'Suave',
        'c_fit' => 'Corte',
        'c_fit_opts' => ['Ajustado', 'Slim', 'Regular', 'Holgado'],
        'c_fit_sel' => 'Holgado',
        'size_title' => 'Tallas',
        'size_body' => 'Compare las tablas con pecho y altura; la medida a mano puede variar 1–3 cm—manda la prenda.',
        'original_title' => 'Oficio original',
        'original_body' => 'El corte y bordado Huazhaoji “Sijichun” siguen nuestras fotos de estudio.',
        'close_caption' => 'Sijichun · Tang primavera–verano',
        'alt_hero' => 'Sijichun · puesto',
        'alt_look' => 'Sijichun · puesto',
        'alt_macro' => 'Sijichun · detalle de bordado',
        'size_chart_html' => $sizeChartFor('en_US'),
        'lang' => 'es',
    ]);

    $packs['fr_FR'] = array_merge($packs['en_US'], [
        'intro_title' => 'Sijichun · Jupe Hezi Tang et manches brodées',
        'intro_body' => 'Jupe Hezi haute sous la poitrine style Tang avec manches larges brodées : fleurs en relief, perles, mousseline et plis dégradés—selon nos photos studio.',
        'inspire_title' => 'Source du dessin',
        'inspire_lines' => [
            '« Sijichun » (printemps des quatre saisons) : broderie sur le Hezi, air dans les manches, couleur dans les plis.',
            'Vert jaspe et pêche se choisissent sur l’axe des variantes ; la coupe reste Tang.',
        ],
        'inspire_note' => 'Comparez point, perles et dégradé aux photos—pas de fiches tissu inventées.',
        'poem_eyebrow' => 'Vers à côté',
        'poem_lines' => ['Fleur en relief', 'Manches au vent', 'Printemps à la poitrine'],
        'look_title' => 'Rythme du corps',
        'look_body' => 'Plans moyens et de profil croisent fleur du Hezi, ouverture des manches et chute du bas.',
        'look_note' => 'Variantes comme le set vert jaspe suivent l’axe des variantes.',
        'macro_title' => 'Lisible de près',
        'macro_body' => 'Les gros plans montrent couches de fil, bord de perles et lumière à travers la mousseline.',
        'bento_title' => 'À retenir',
        'bento_style_label' => 'Coupe',
        'bento_style' => 'Style Tang',
        'bento_parts_label' => 'Pièces',
        'bento_parts' => 'Robe manches larges · jupe Hezi',
        'bento_a' => 'Fleurs et perles selon les macros',
        'bento_b' => 'Choisir la taille selon poitrine et taille',
        'quiet_line' => 'Tissu sur le corps ; air au bord de l’eau.',
        'checklist_title' => 'Entretien',
        'checklist' => [
            'Laver à la main séparément ; ne pas blanchir.',
            'Sécher à l’air ; éviter le soleil fort ; fer doux avec un linge.',
            'Suspendre manches et broderies sans pression longue.',
        ],
        'info_title' => 'En un coup d’œil',
        'info_basics' => 'Bases',
        'info_comfort' => 'Confort',
        'label_brand' => 'Marque',
        'label_name' => 'Nom',
        'label_color' => 'Couleur',
        'label_style' => 'Style',
        'label_size' => 'Taille',
        'label_fabric' => 'Tissu',
        'label_parts' => 'Pièces',
        'info_brand' => "Chang'an Hanfu",
        'info_name' => 'Sijichun',
        'info_color' => 'Comme sur la photo (axe des variantes)',
        'info_style' => 'Style Tang',
        'info_size' => 'S–L (voir tableau)',
        'info_fabric' => 'Doux et agréable',
        'info_parts' => 'Robe manches larges, jupe Hezi',
        'c_thick' => 'Épaisseur',
        'c_thick_opts' => ['Ultra-fin', 'Léger', 'Moyen', 'Épais'],
        'c_thick_sel' => 'Léger',
        'c_stretch' => 'Élasticité',
        'c_stretch_opts' => ['Aucune', 'Légère', 'Moyenne', 'Élastique'],
        'c_stretch_sel' => 'Aucune',
        'c_soft' => 'Douceur',
        'c_soft_opts' => ['Doux', 'Un peu doux', 'Moyen', 'Ferme'],
        'c_soft_sel' => 'Doux',
        'c_fit' => 'Coupe',
        'c_fit_opts' => ['Serré', 'Slim', 'Regular', 'Ample'],
        'c_fit_sel' => 'Ample',
        'size_title' => 'Tailles',
        'size_body' => 'Comparez aux tables poitrine/hauteur ; mesure à la main ±1–3 cm—le vêtement prime.',
        'original_title' => 'Savoir-faire original',
        'original_body' => 'Coupe et broderie Huazhaoji « Sijichun » suivent nos photos studio.',
        'close_caption' => 'Sijichun · Tang printemps–été',
        'alt_hero' => 'Sijichun · porté',
        'alt_look' => 'Sijichun · porté',
        'alt_macro' => 'Sijichun · détail broderie',
        'size_chart_html' => $sizeChartFor('en_US'),
        'lang' => 'fr',
    ]);

    $packs['pt_BR'] = array_merge($packs['en_US'], [
        'intro_title' => 'Sijichun · Saia Hezi Tang e mangas bordadas',
        'intro_body' => 'Saia Hezi no peito estilo Tang com mangas largas bordadas: flores em relevo, pérolas, gaze e pregas degradê—conforme nossas fotos de estúdio.',
        'inspire_title' => 'Fonte do desenho',
        'inspire_lines' => [
            '“Sijichun” (primavera das quatro estações): bordado no Hezi, ar nas mangas, cor nas pregas.',
            'Verde jaspe e pêssego mudam no eixo de variantes; o corte permanece Tang.',
        ],
        'inspire_note' => 'Compare ponto, pérolas e degradê com as fotos—sem inventar ficha de tecido.',
        'poem_eyebrow' => 'Verso ao lado',
        'poem_lines' => ['Flor em relevo', 'Mangas ao vento', 'Primavera no peito'],
        'look_title' => 'Ritmo do corpo',
        'look_body' => 'Planos médios e de perfil cruzam flor do Hezi, abertura da manga e queda da barra.',
        'look_note' => 'Variantes como o set verde jaspe seguem o eixo de variantes.',
        'macro_title' => 'Legível de perto',
        'macro_body' => 'Close-ups mostram camadas de fio, borda de pérolas e luz pela gaze.',
        'bento_title' => 'Vale notar',
        'bento_style_label' => 'Corte',
        'bento_style' => 'Estilo Tang',
        'bento_parts_label' => 'Peças',
        'bento_parts' => 'Túnica mangas largas · saia Hezi',
        'bento_a' => 'Flores e pérolas conforme macros',
        'bento_b' => 'Escolha o tamanho por busto e altura',
        'quiet_line' => 'Tecido no corpo; ar à beira d’água.',
        'checklist_title' => 'Cuidados',
        'checklist' => [
            'Lavar à mão separado; não alvejar.',
            'Secar pendurado; evitar sol forte; ferro baixo com pano.',
            'Pendure mangas e bordados com cuidado—evite vincos pesados.',
        ],
        'info_title' => 'Em um olhar',
        'info_basics' => 'Básicos',
        'info_comfort' => 'Conforto',
        'label_brand' => 'Marca',
        'label_name' => 'Nome',
        'label_color' => 'Cor',
        'label_style' => 'Estilo',
        'label_size' => 'Tamanho',
        'label_fabric' => 'Tecido',
        'label_parts' => 'Peças',
        'info_brand' => "Chang'an Hanfu",
        'info_name' => 'Sijichun',
        'info_color' => 'Como na foto (eixo de variantes)',
        'info_style' => 'Estilo Tang',
        'info_size' => 'S–L (ver tabela)',
        'info_fabric' => 'Macio e agradável à pele',
        'info_parts' => 'Túnica mangas largas, saia Hezi',
        'c_thick' => 'Espessura',
        'c_thick_opts' => ['Ultrafino', 'Leve', 'Médio', 'Grosso'],
        'c_thick_sel' => 'Leve',
        'c_stretch' => 'Elasticidade',
        'c_stretch_opts' => ['Nenhuma', 'Leve', 'Média', 'Elástica'],
        'c_stretch_sel' => 'Nenhuma',
        'c_soft' => 'Maciez',
        'c_soft_opts' => ['Macio', 'Um pouco macio', 'Médio', 'Firme'],
        'c_soft_sel' => 'Macio',
        'c_fit' => 'Caimento',
        'c_fit_opts' => ['Apertado', 'Slim', 'Regular', 'Folgado'],
        'c_fit_sel' => 'Folgado',
        'size_title' => 'Tamanhos',
        'size_body' => 'Compare as tabelas com busto e altura; medida manual pode variar 1–3 cm—a peça manda.',
        'original_title' => 'Ofício original',
        'original_body' => 'Corte e bordado Huazhaoji “Sijichun” seguem nossas fotos de estúdio.',
        'close_caption' => 'Sijichun · Tang primavera–verão',
        'alt_hero' => 'Sijichun · vestido',
        'alt_look' => 'Sijichun · vestido',
        'alt_macro' => 'Sijichun · detalhe do bordado',
        'size_chart_html' => $sizeChartFor('en_US'),
        'lang' => 'pt',
    ]);

    $packs['id_ID'] = array_merge($packs['en_US'], [
        'intro_title' => 'Sijichun · Rok Hezi Tang & lengan lebar bordir',
        'intro_body' => 'Rok Hezi setinggi dada gaya Tang dengan lengan lebar bersulam: bunga timbul, mutiara, kain tipis, dan lipit gradasi—mengikuti foto studio kami.',
        'inspire_title' => 'Sumber desain',
        'inspire_lines' => [
            '“Sijichun” (musim semi empat musim): sulaman di Hezi, angin di lengan, warna di lipatan.',
            'Varian hijau jasper dan peach dipilih di sumbu varian; potongan tetap Tang.',
        ],
        'inspire_note' => 'Sesuaikan jahitan, mutiara, dan gradasi dengan foto—jangan mengarang spek kain.',
        'poem_eyebrow' => 'Syair samping',
        'poem_lines' => ['Bunga timbul', 'Lengan menangkap angin', 'Musim semi di dada'],
        'look_title' => 'Irama seluruh tubuh',
        'look_body' => 'Shot sedang dan profil menyilang agar bunga Hezi, buka-tutup lengan, dan jatuhnya hem terbaca.',
        'look_note' => 'Varian seperti set hijau jasper mengikuti sumbu varian.',
        'macro_title' => 'Jelas dari dekat',
        'macro_body' => 'Bingkai dekat menampilkan lapisan jahitan, tepi mutiara, dan cahaya tembus.',
        'bento_title' => 'Patut dicatat',
        'bento_style_label' => 'Potongan',
        'bento_style' => 'Gaya Tang',
        'bento_parts_label' => 'Bagian',
        'bento_parts' => 'Jubah lengan lebar · rok Hezi',
        'bento_a' => 'Bunga & mutiara mengikuti makro',
        'bento_b' => 'Pilih ukuran menurut lingkar dada & tinggi',
        'quiet_line' => 'Kain di tubuh; udara di tepi air.',
        'checklist_title' => 'Perawatan',
        'checklist' => [
            'Cuci tangan terpisah; jangan diputihkan.',
            'Keringkan digantung; hindari matahari kuat; setrika rendah dengan kain.',
            'Gantung lengan lebar & sulaman ringan—hindari lipatan berat lama.',
        ],
        'info_title' => 'Sekilas',
        'info_basics' => 'Dasar',
        'info_comfort' => 'Kenyamanan',
        'label_brand' => 'Merek',
        'label_name' => 'Nama',
        'label_color' => 'Warna',
        'label_style' => 'Gaya',
        'label_size' => 'Ukuran',
        'label_fabric' => 'Kain',
        'label_parts' => 'Bagian',
        'info_brand' => "Chang'an Hanfu",
        'info_name' => 'Sijichun',
        'info_color' => 'Seperti gambar (sumbu varian)',
        'info_style' => 'Gaya Tang',
        'info_size' => 'S–L (lihat tabel)',
        'info_fabric' => 'Lembut dan ramah kulit',
        'info_parts' => 'Jubah lengan lebar, rok Hezi',
        'c_thick' => 'Ketebalan',
        'c_thick_opts' => ['Sangat tipis', 'Ringan', 'Sedang', 'Tebal'],
        'c_thick_sel' => 'Ringan',
        'c_stretch' => 'Elastisitas',
        'c_stretch_opts' => ['Tidak', 'Sedikit', 'Sedang', 'Elastis'],
        'c_stretch_sel' => 'Tidak',
        'c_soft' => 'Kelembutan',
        'c_soft_opts' => ['Lembut', 'Agak lembut', 'Sedang', 'Keras'],
        'c_soft_sel' => 'Lembut',
        'c_fit' => 'Potongan',
        'c_fit_opts' => ['Ketat', 'Slim', 'Regular', 'Longgar'],
        'c_fit_sel' => 'Longgar',
        'size_title' => 'Ukuran',
        'size_body' => 'Cocokkan tabel dengan dada & tinggi; ukur tangan bisa ±1–3 cm—pakaian yang benar.',
        'original_title' => 'Kerajinan orisinal',
        'original_body' => 'Potongan & sulaman Huazhaoji “Sijichun” mengikuti foto studio kami.',
        'close_caption' => 'Sijichun · Tang musim semi–panas',
        'alt_hero' => 'Sijichun · dikenakan',
        'alt_look' => 'Sijichun · dikenakan',
        'alt_macro' => 'Sijichun · detail sulaman',
        'size_chart_html' => $sizeChartFor('en_US'),
        'lang' => 'id',
    ]);

    $packs['hi_IN'] = array_merge($packs['en_US'], [
        'intro_title' => 'सिजीचुन · तांग हेज़ी स्कर्ट व कढ़ाईदार चौड़ी आस्तीन',
        'intro_body' => 'तांग शैली छाती-ऊँची हेज़ी स्कर्ट व कढ़ाईदार चौड़ी आस्तीन: उभरे फूल, मोती किनारा, पतला कपड़ा और ग्रेडिएंट प्लीट्स—हमारे स्टूडियो फ़ोटो के अनुसार।',
        'inspire_title' => 'डिज़ाइन स्रोत',
        'inspire_lines' => [
            '“सिजीचुन” (चार मौसमों की बसंत): हेज़ी पर कढ़ाई, आस्तीनों में हवा, तहों में रंग।',
            'जैस्पर हरा और पीच रंग आकार/शैली अक्ष पर बदलें; कट तांग ही रहता है।',
        ],
        'inspire_note' => 'सिलाई, मोती और ग्रेडिएंट फ़ोटो से मिलाएँ—कपड़े के झूठे स्पेक न लिखें।',
        'poem_eyebrow' => 'किनारे की कविता',
        'poem_lines' => ['उभरे फूल', 'आस्तीनों में हवा', 'छाती पर बसंत'],
        'look_title' => 'पूरे शरीर की लय',
        'look_body' => 'मध्य व प्रोफ़ाइल शॉट हेज़ी फूल, आस्तीन खुलना-बंद होना और हेम की गिरावट दिखाते हैं।',
        'look_note' => 'जैस्पर हरा पूरा सेट जैसे वेरिएंट अक्ष पर देखें।',
        'macro_title' => 'पास से पढ़ने योग्य',
        'macro_body' => 'क्लोज़-अप में सिलाई परतें, मोती किनारा और पारदर्शी रोशनी दिखती है।',
        'bento_title' => 'याद रखने योग्य',
        'bento_style_label' => 'कट',
        'bento_style' => 'तांग शैली',
        'bento_parts_label' => 'भाग',
        'bento_parts' => 'चौड़ी आस्तीन वस्त्र · हेज़ी स्कर्ट',
        'bento_a' => 'फूल व मोती मैक्रो के अनुसार',
        'bento_b' => 'छाती व ऊँचाई से साइज़ चुनें',
        'quiet_line' => 'शरीर पर वस्त्र; पानी किनारे हवा।',
        'checklist_title' => 'देखभाल',
        'checklist' => [
            'अलग से हाथ से धोएँ; ब्लीच न करें।',
            'टंगी सुखाएँ; तेज़ धूप से बचें; कपड़े के साथ कम गर्मी इस्त्री।',
            'चौड़ी आस्तीन व कढ़ाई हल्के लटकाएँ—लंबे भारी मोड़ से बचें।',
        ],
        'info_title' => 'एक नज़र में',
        'info_basics' => 'मूल',
        'info_comfort' => 'आराम',
        'label_brand' => 'ब्रांड',
        'label_name' => 'नाम',
        'label_color' => 'रंग',
        'label_style' => 'शैली',
        'label_size' => 'साइज़',
        'label_fabric' => 'कपड़ा',
        'label_parts' => 'भाग',
        'info_brand' => "Chang'an Hanfu",
        'info_name' => 'Sijichun',
        'info_color' => 'चित्र जैसा (वेरिएंट अक्ष)',
        'info_style' => 'तांग शैली',
        'info_size' => 'S–L (चार्ट देखें)',
        'info_fabric' => 'मुलायम व त्वचा-अनुकूल',
        'info_parts' => 'चौड़ी आस्तीन वस्त्र, हेज़ी स्कर्ट',
        'c_thick' => 'मोटाई',
        'c_thick_opts' => ['अति पतला', 'हल्का', 'मध्यम', 'मोटा'],
        'c_thick_sel' => 'हल्का',
        'c_stretch' => 'खिंचाव',
        'c_stretch_opts' => ['नहीं', 'थोड़ा', 'मध्यम', 'लचीला'],
        'c_stretch_sel' => 'नहीं',
        'c_soft' => 'कोमलता',
        'c_soft_opts' => ['मुलायम', 'थोड़ा मुलायम', 'मध्यम', 'कठोर'],
        'c_soft_sel' => 'मुलायम',
        'c_fit' => 'फिट',
        'c_fit_opts' => ['टाइट', 'स्लिम', 'रेगुलर', 'ढीला'],
        'c_fit_sel' => 'ढीला',
        'size_title' => 'साइज़िंग',
        'size_body' => 'तालिकाओं को छाती व ऊँचाई से मिलाएँ; हाथ माप ±1–3 सेमी—कपड़ा सही है।',
        'original_title' => 'मूल शिल्प',
        'original_body' => 'हुआझाओजी “सिजीचुन” कट व कढ़ाई हमारे स्टूडियो फ़ोटो का पालन करती है।',
        'close_caption' => 'सिजीचुन · तांग बसंत–ग्रीष्म',
        'alt_hero' => 'सिजीचुन · पहना हुआ',
        'alt_look' => 'सिजीचुन · पहना हुआ',
        'alt_macro' => 'सिजीचुन · कढ़ाई विवरण',
        'size_chart_html' => $sizeChartFor('en_US'),
        'lang' => 'hi',
    ]);

    $packs['ar_SA'] = array_merge($packs['en_US'], [
        'intro_title' => 'سيجي تشون · تنورة هيزي تانغ وأكمام مطرّزة',
        'intro_body' => 'تنورة هيزي عالية الصدر بطراز تانغ مع أكمام واسعة مطرّزة: زهور بارزة وحافة لآلئ وأقمشة شفافة وطيات متدرجة—وفق صور الاستوديو لدينا.',
        'inspire_title' => 'منبع التصميم',
        'inspire_lines' => [
            '«سيجي تشون» (ربيع الفصول الأربعة): تطريز على الهيزي، هواء في الأكمام، لون في الطيات.',
            'الأخضر اليشمي والخوخي يتبدّلان على محور المقاس؛ القصّ يبقى تانغ.',
        ],
        'inspire_note' => 'طابق الغرز واللآلئ والتدرّج مع الصور—لا تخترع مواصفات قماش.',
        'poem_eyebrow' => 'بيت جانبي',
        'poem_lines' => ['زهور بارزة', 'أكمام تلتقط النسيم', 'ربيع عند الصدر'],
        'look_title' => 'إيقاع الجسم كاملًا',
        'look_body' => 'لقطات متوسطة وجانبية تتقاطع لتُظهر زهر الهيزي وفتح الأكمام وسقوط الذيل.',
        'look_note' => 'المتغيرات مثل طقم الأخضر اليشمي تتبع محور المتغيرات.',
        'macro_title' => 'مقروء عن قرب',
        'macro_body' => 'اللقطات القريبة تُظهر طبقات الغرز وحافة اللآلئ وضوء الشاش.',
        'bento_title' => 'جدير بالذكر',
        'bento_style_label' => 'القصّ',
        'bento_style' => 'طراز تانغ',
        'bento_parts_label' => 'الأجزاء',
        'bento_parts' => 'رداء بأكمام واسعة · تنورة هيزي',
        'bento_a' => 'الزهور واللآلئ وفق اللقطات المقربة',
        'bento_b' => 'اختر المقاس حسب الصدر والطول',
        'quiet_line' => 'قماش على الجسد؛ هواء عند الماء.',
        'checklist_title' => 'العناية',
        'checklist' => [
            'اغسل يدويًا منفصلًا؛ لا تبيّض.',
            'جفّف معلّقًا؛ تجنّب الشمس القوية؛ كوي منخفض بقطعة قماش.',
            'علّق الأكمام والتطريز بخفة—تجنّب الطي الثقيل الطويل.',
        ],
        'info_title' => 'بنظرة واحدة',
        'info_basics' => 'أساسيات',
        'info_comfort' => 'الراحة',
        'label_brand' => 'العلامة',
        'label_name' => 'الاسم',
        'label_color' => 'اللون',
        'label_style' => 'الطراز',
        'label_size' => 'المقاس',
        'label_fabric' => 'القماش',
        'label_parts' => 'الأجزاء',
        'info_brand' => "Chang'an Hanfu",
        'info_name' => 'Sijichun',
        'info_color' => 'كما في الصورة (محور المتغيرات)',
        'info_style' => 'طراز تانغ',
        'info_size' => 'S–L (انظر الجدول)',
        'info_fabric' => 'ناعم ومريح للبشرة',
        'info_parts' => 'رداء بأكمام واسعة، تنورة هيزي',
        'c_thick' => 'السماكة',
        'c_thick_opts' => ['رفيع جدًا', 'خفيف', 'متوسط', 'سميك'],
        'c_thick_sel' => 'خفيف',
        'c_stretch' => 'المرونة',
        'c_stretch_opts' => ['لا', 'قليل', 'متوسط', 'مرن'],
        'c_stretch_sel' => 'لا',
        'c_soft' => 'النعومة',
        'c_soft_opts' => ['ناعم', 'ناعم قليلًا', 'متوسط', 'قاسٍ'],
        'c_soft_sel' => 'ناعم',
        'c_fit' => 'القصة',
        'c_fit_opts' => ['ضيق', 'نحيف', 'عادي', 'واسع'],
        'c_fit_sel' => 'واسع',
        'size_title' => 'المقاسات',
        'size_body' => 'طابق الجداول مع الصدر والطول؛ القياس اليدوي قد يختلف 1–3 سم—الثوب أصدق.',
        'original_title' => 'حرفة أصلية',
        'original_body' => 'قصّ وتطريز هواجاوجي «سيجي تشون» يتبعان صور الاستوديو لدينا.',
        'close_caption' => 'سيجي تشون · تانغ ربيع–صيف',
        'alt_hero' => 'سيجي تشون · مرتدى',
        'alt_look' => 'سيجي تشون · مرتدى',
        'alt_macro' => 'سيجي تشون · تفصيل التطريز',
        'size_chart_html' => $sizeChartFor('en_US'),
        'lang' => 'ar',
    ]);

    $packs['bn_BD'] = array_merge($packs['en_US'], [
        'intro_title' => 'সিজিচুন · তাং হেজি স্কার্ট ও সূচিকর্ম প্রশস্ত হাতা',
        'intro_body' => 'তাং শৈলীর বুক-উঁচু হেজি স্কার্ট ও সূচিকর্ম প্রশস্ত হাতা: উঁচু ফুল, মুক্তো কিনারা, পাতলা কাপড় ও গ্রেডিয়েন্ট প্লিট—আমাদের স্টুডিও ছবি অনুসারে।',
        'inspire_title' => 'ডিজাইনের উৎস',
        'inspire_lines' => [
            '“সিজিচুন” (চার ঋতুর বসন্ত): হেজিতে সূচিকর্ম, হাতায় হাওয়া, ভাঁজে রং।',
            'জ্যাসপার সবুজ ও পীচ রং ভ্যারিয়েন্ট অক্ষে বদলান; কাট তাংই থাকে।',
        ],
        'inspire_note' => 'সেলাই, মুক্তো ও গ্রেডিয়েন্ট ছবির সাথে মিলান—কাপড়ের মিথ্যা স্পেক লিখবেন না।',
        'poem_eyebrow' => 'পাশের কবিতা',
        'poem_lines' => ['উঁচু ফুল', 'হাতায় হাওয়া', 'বুকে বসন্ত'],
        'look_title' => 'পুরো শরীরের ছন্দ',
        'look_body' => 'মিড ও প্রোফাইল শট হেজির ফুল, হাতা খোলা-বন্ধ ও হেমের পতন দেখায়।',
        'look_note' => 'জ্যাসপার সবুজ পূর্ণ সেটের মতো ভ্যারিয়েন্ট অক্ষ অনুসরণ করে।',
        'macro_title' => 'কাছ থেকে পাঠযোগ্য',
        'macro_body' => 'ক্লোজ-আপে সেলাইয়ের স্তর, মুক্তো কিনারা ও স্বচ্ছ আলো দেখা যায়।',
        'bento_title' => 'মনে রাখার মতো',
        'bento_style_label' => 'কাট',
        'bento_style' => 'তাং শৈলী',
        'bento_parts_label' => 'অংশ',
        'bento_parts' => 'প্রশস্ত হাতার পোশাক · হেজি স্কার্ট',
        'bento_a' => 'ফুল ও মুক্তো ম্যাক্রো অনুসারে',
        'bento_b' => 'বুক ও উচ্চতা দিয়ে সাইজ বেছে নিন',
        'quiet_line' => 'শরীরে কাপড়; জলের ধারে হাওয়া।',
        'checklist_title' => 'যত্ন',
        'checklist' => [
            'আলাদা করে হাতে ধুয়ে নিন; ব্লিচ করবেন না।',
            'ঝুলিয়ে শুকান; তীব্র রোদ এড়ান; কাপড় দিয়ে কম তাপে ইস্ত্রি।',
            'প্রশস্ত হাতা ও সূচিকর্ম হালকা ঝুলান—দীর্ঘ ভারী ভাঁজ এড়ান।',
        ],
        'info_title' => 'এক নজরে',
        'info_basics' => 'মূল',
        'info_comfort' => 'আরাম',
        'label_brand' => 'ব্র্যান্ড',
        'label_name' => 'নাম',
        'label_color' => 'রং',
        'label_style' => 'শৈলী',
        'label_size' => 'সাইজ',
        'label_fabric' => 'কাপড়',
        'label_parts' => 'অংশ',
        'info_brand' => "Chang'an Hanfu",
        'info_name' => 'Sijichun',
        'info_color' => 'ছবির মতো (ভ্যারিয়েন্ট অক্ষ)',
        'info_style' => 'তাং শৈলী',
        'info_size' => 'S–L (টেবিল দেখুন)',
        'info_fabric' => 'নরম ও ত্বকবান্ধব',
        'info_parts' => 'প্রশস্ত হাতার পোশাক, হেজি স্কার্ট',
        'c_thick' => 'পুরুত্ব',
        'c_thick_opts' => ['অতি পাতলা', 'হালকা', 'মাঝারি', 'মোটা'],
        'c_thick_sel' => 'হালকা',
        'c_stretch' => 'স্থিতিস্থাপকতা',
        'c_stretch_opts' => ['নেই', 'সামান্য', 'মাঝারি', 'ইলাস্টিক'],
        'c_stretch_sel' => 'নেই',
        'c_soft' => 'নরমতা',
        'c_soft_opts' => ['নরম', 'একটু নরম', 'মাঝারি', 'শক্ত'],
        'c_soft_sel' => 'নরম',
        'c_fit' => 'ফিট',
        'c_fit_opts' => ['টাইট', 'স্লিম', 'রেগুলার', 'ঢিলা'],
        'c_fit_sel' => 'ঢিলা',
        'size_title' => 'সাইজিং',
        'size_body' => 'টেবিল বুক ও উচ্চতার সাথে মিলান; হাতের মাপ ±১–৩ সেমি—পোশাকই সত্য।',
        'original_title' => 'মূল কারুকাজ',
        'original_body' => 'হুয়াঝাওজি “সিজিচুন” কাট ও সূচিকর্ম আমাদের স্টুডিও ছবি অনুসরণ করে।',
        'close_caption' => 'সিজিচুন · তাং বসন্ত–গ্রীষ্ম',
        'alt_hero' => 'সিজিচুন · পরা',
        'alt_look' => 'সিজিচুন · পরা',
        'alt_macro' => 'সিজিচুন · সূচিকর্ম বিস্তারিত',
        'size_chart_html' => $sizeChartFor('en_US'),
        'lang' => 'bn',
    ]);

    $packs['ur_PK'] = array_merge($packs['en_US'], [
        'intro_title' => 'سیجی چن · تانگ ہیزی اسکرٹ اور کڑھائی والی چوڑی آستین',
        'intro_body' => 'تانگ طرز سینے تک ہیزی اسکرٹ مع کڑھائی والی چوڑی آستین: ابھری ہوئی پھول، موتی کنارہ، پتلا کپڑا اور گریڈینٹ پلیٹس—ہماری اسٹوڈیو تصاویر کے مطابق۔',
        'inspire_title' => 'ڈیزائن کا ماخذ',
        'inspire_lines' => [
            '“سیجی چن” (چار موسموں کی بہار): ہیزی پر کڑھائی، آستینوں میں ہوا، تہوں میں رنگ۔',
            'جاسپر سبز اور پیچ رنگ سائز/اسٹائل محور پر بدلیں؛ کٹ تانگ ہی رہتا ہے۔',
        ],
        'inspire_note' => 'سلائی، موتی اور گریڈینٹ تصاویر سے ملائیں—کپڑے کے جھوٹے اسپیک نہ لکھیں۔',
        'poem_eyebrow' => 'بغلی شعر',
        'poem_lines' => ['ابھرے پھول', 'آستینوں میں ہوا', 'سینے پر بہار'],
        'look_title' => 'پورے جسم کی لَے',
        'look_body' => 'درمیانی اور پروفائل شاٹس ہیزی کے پھول، آستین کھلنا بند ہونا اور ہیم کا گرنا دکھاتے ہیں۔',
        'look_note' => 'جاسپر سبز مکمل سیٹ جیسے ویرینٹ محور پر دیکھیں۔',
        'macro_title' => 'قریب سے پڑھنے کے قابل',
        'macro_body' => 'کلوز اپ میں سلائی کی تہیں، موتی کنارہ اور شفاف روشنی نظر آتی ہے۔',
        'bento_title' => 'یاد رکھنے کے قابل',
        'bento_style_label' => 'کٹ',
        'bento_style' => 'تانگ طرز',
        'bento_parts_label' => 'حصے',
        'bento_parts' => 'چوڑی آستین والا لباس · ہیزی اسکرٹ',
        'bento_a' => 'پھول اور موتی میکرو کے مطابق',
        'bento_b' => 'سینے اور قد سے سائز چنیں',
        'quiet_line' => 'جسم پر کپڑا؛ پانی کے کنارے ہوا۔',
        'checklist_title' => 'دیکھ بھال',
        'checklist' => [
            'الگ سے ہاتھ سے دھوئیں؛ بلیچ نہ کریں۔',
            'لٹکا کر خشک کریں؛ تیز دھوپ سے بچیں؛ کپڑے کے ساتھ کم حرارت استری۔',
            'چوڑی آستین اور کڑھائی ہلکے لٹکائیں—لمبی بھاری تہوں سے بچیں۔',
        ],
        'info_title' => 'ایک نظر میں',
        'info_basics' => 'بنیادی',
        'info_comfort' => 'آرام',
        'label_brand' => 'برانڈ',
        'label_name' => 'نام',
        'label_color' => 'رنگ',
        'label_style' => 'طرز',
        'label_size' => 'سائز',
        'label_fabric' => 'کپڑا',
        'label_parts' => 'حصے',
        'info_brand' => "Chang'an Hanfu",
        'info_name' => 'Sijichun',
        'info_color' => 'تصویر جیسا (ویرینٹ محور)',
        'info_style' => 'تانگ طرز',
        'info_size' => 'S–L (ٹیبل دیکھیں)',
        'info_fabric' => 'نرم اور جلد دوست',
        'info_parts' => 'چوڑی آستین والا لباس، ہیزی اسکرٹ',
        'c_thick' => 'موٹائی',
        'c_thick_opts' => ['انتہائی پتلا', 'ہلکا', 'درمیانہ', 'موٹا'],
        'c_thick_sel' => 'ہلکا',
        'c_stretch' => 'لچک',
        'c_stretch_opts' => ['نہیں', 'تھوڑی', 'درمیانہ', 'لچکدار'],
        'c_stretch_sel' => 'نہیں',
        'c_soft' => 'نرمی',
        'c_soft_opts' => ['نرم', 'تھوڑا نرم', 'درمیانہ', 'سخت'],
        'c_soft_sel' => 'نرم',
        'c_fit' => 'فٹ',
        'c_fit_opts' => ['ٹائٹ', 'سلِم', 'ریگولر', 'ڈھیلا'],
        'c_fit_sel' => 'ڈھیلا',
        'size_title' => 'سائزنگ',
        'size_body' => 'ٹیبلز کو سینے اور قد سے ملائیں؛ ہاتھ ناپ ±۱–۳ سینٹی میٹر—کپڑا درست ہے۔',
        'original_title' => 'اصل دستکاری',
        'original_body' => 'ہواژاؤجی “سیجی چن” کٹ اور کڑھائی ہماری اسٹوڈیو تصاویر کی پیروی کرتی ہے۔',
        'close_caption' => 'سیجی چن · تانگ بہار–گرمیاں',
        'alt_hero' => 'سیجی چن · پہنا ہوا',
        'alt_look' => 'سیجی چن · پہنا ہوا',
        'alt_macro' => 'سیجی چن · کڑھائی کی تفصیل',
        'size_chart_html' => $sizeChartFor('en_US'),
        'lang' => 'ur',
    ]);

    if (!isset($packs[$locale])) {
        return $packs['en_US'];
    }

    return $packs[$locale];
};

$assemble = static function (array $t) use ($A, $img, $figureStack, $h): string {
    $intro = '<div class="weline-detail-prose weline-detail-prose--lead"><h3>' . $h((string)$t['intro_title']) . '</h3><p>'
        . $h((string)$t['intro_body']) . '</p></div>';

    $inspire = '<div class="weline-detail-prose weline-detail-prose--verse"><h3>' . $h((string)$t['inspire_title']) . '</h3>';
    foreach ((array)$t['inspire_lines'] as $line) {
        $inspire .= '<p>' . $h((string)$line) . '</p>';
    }
    $inspire .= '<p class="weline-detail-feature__note">' . $h((string)$t['inspire_note']) . '</p></div>';

    $poem = '<div class="weline-detail-feature weline-detail-feature--poem-aside weline-detail-orient--portrait">'
        . '<div class="weline-detail-feature__copy"><div class="weline-detail-prose weline-detail-prose--verse-vertical" lang="'
        . $h((string)$t['lang']) . '"><span class="weline-detail-prose__eyebrow">' . $h((string)$t['poem_eyebrow']) . '</span>';
    foreach ((array)$t['poem_lines'] as $line) {
        $poem .= '<p>' . $h((string)$line) . '</p>';
    }
    $poem .= '</div></div><div class="weline-detail-feature__media">'
        . $img($A['hero'], (string)$t['alt_hero']) . '</div></div>';

    $feature = '<div class="weline-detail-feature weline-detail-feature--reverse weline-detail-orient--portrait">'
        . '<div class="weline-detail-feature__media">' . $img($A['look_green'], (string)$t['alt_look']) . '</div>'
        . '<div class="weline-detail-feature__copy"><h3>' . $h((string)$t['look_title']) . '</h3><p>'
        . $h((string)$t['look_body']) . '</p><p class="weline-detail-feature__note">'
        . $h((string)$t['look_note']) . '</p></div></div>';

    $sizeChart = (string)$t['size_chart_html'];

    $pairLooks = $figureStack([
        $img($A['look_peach'], (string)$t['alt_look'] . ' · peach'),
        $img($A['look_green'], (string)$t['alt_look'] . ' · green'),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['look_title']) . '</h3><p>'
        . $h((string)$t['look_body']) . '</p></div>';

    $pairMacro = $figureStack([
        $img($A['look_fabric'], (string)$t['alt_macro'] . ' 1'),
        $img($A['look_model'], (string)$t['alt_macro'] . ' 2'),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait')
        . '<div class="weline-detail-prose weline-detail-prose--macro"><h3>' . $h((string)$t['macro_title']) . '</h3><p>'
        . $h((string)$t['macro_body']) . '</p></div>';

    $quiet = '<div class="weline-detail-quiet-spacer" aria-hidden="true"></div>';

    $bento = '<div class="weline-detail-bento"><h3 class="weline-detail-bento__heading">' . $h((string)$t['bento_title']) . '</h3>'
        . '<div class="weline-detail-bento__grid">'
        . '<div class="weline-detail-bento__card weline-detail-bento__card--accent weline-detail-bento__card--kv"><h4>'
        . $h((string)$t['bento_style_label']) . '</h4><p>' . $h((string)$t['bento_style']) . '</p></div>'
        . '<div class="weline-detail-bento__card weline-detail-bento__card--kv"><h4>'
        . $h((string)$t['bento_parts_label']) . '</h4><p>' . $h((string)$t['bento_parts']) . '</p></div>'
        . '<div class="weline-detail-bento__card weline-detail-bento__card--wide"><h4>' . $h((string)$t['bento_a']) . '</h4></div>'
        . '<div class="weline-detail-bento__card weline-detail-bento__card--wide"><h4>' . $h((string)$t['bento_b']) . '</h4></div>'
        . '</div></div>';

    $quietLine = '<div class="weline-detail-prose weline-detail-prose--quiet"><p>' . $h((string)$t['quiet_line']) . '</p></div>';

    $wash = '<div class="weline-detail-prose weline-detail-prose--checklist"><h3>' . $h((string)$t['checklist_title']) . '</h3><ul>';
    foreach ((array)$t['checklist'] as $line) {
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
            ['label' => (string)$t['c_stretch'], 'options' => (array)$t['c_stretch_opts'], 'selected' => (string)$t['c_stretch_sel']],
            ['label' => (string)$t['c_soft'], 'options' => (array)$t['c_soft_opts'], 'selected' => (string)$t['c_soft_sel']],
            ['label' => (string)$t['c_fit'], 'options' => (array)$t['c_fit_opts'], 'selected' => (string)$t['c_fit_sel']],
        ],
        (string)$t['info_title'],
        (string)$t['info_basics'],
        (string)$t['info_comfort'],
    );

    $sizeNote = '<div class="weline-detail-prose"><h3>' . $h((string)$t['size_title']) . '</h3><p>'
        . $h((string)$t['size_body']) . '</p></div>';
    $original = '<div class="weline-detail-prose"><h3>' . $h((string)$t['original_title']) . '</h3><p>'
        . $h((string)$t['original_body']) . '</p></div>';
    $close = '<div class="weline-detail-prose weline-detail-prose--quiet"><p class="weline-detail-feature__note">'
        . $h((string)$t['close_caption']) . '</p></div>';

    return '<div data-weline-product-description="1688" data-weds="xq">'
        . '<!--weds:xq--><span data-weds="xq" hidden aria-hidden="true"></span>'
        . $intro . $inspire . $poem . $feature . $sizeChart . $pairLooks . $pairMacro
        . $quiet . $bento . $quietLine . $wash . $info . $sizeNote . $original . $close
        . '</div>';
};

/** @var \Weline\Websites\Model\Website $website */
$website = ObjectManager::getInstance(\Weline\Websites\Model\Website::class)->loadById($websiteId)
    ?: ObjectManager::getInstance(\Weline\Websites\Model\Website::class)->load($websiteId);
$enabledLocales = $website ? array_values(array_filter(array_map('strval', (array)$website->getLanguageCodes()))) : [];
if ($enabledLocales === []) {
    $enabledLocales = ['zh_Hans_CN', 'en_US'];
}
$defaultLang = $website ? (string)$website->getDefaultLanguage() : 'zh_Hans_CN';
if ($defaultLang === '') {
    $defaultLang = 'en_US';
}

echo "Website {$websiteId} default={$defaultLang}\n";
echo 'Enabled (' . count($enabledLocales) . '): ' . implode(', ', $enabledLocales) . "\n";

$writes = [];
foreach (array_values(array_unique(array_merge([''], $enabledLocales))) as $locale) {
    $packLocale = $locale === '' ? $defaultLang : $locale;
    if (!in_array($packLocale, ['zh_Hans_CN', 'en_US', 'es_ES', 'fr_FR', 'pt_BR', 'id_ID', 'hi_IN', 'ar_SA', 'bn_BD', 'ur_PK'], true)) {
        $packLocale = 'en_US';
    }
    $html = $tagReveal($assemble($baseCopy($packLocale)));
    $ok = str_contains($html, 'prose--lead')
        && str_contains($html, 'poem-aside')
        && str_contains($html, 'figure-row--pair')
        && str_contains($html, 'prose--checklist')
        && str_contains($html, 'data-weline-detail-reveal')
        && str_contains($html, 'data-weds="xq"')
        && !str_contains($html, 'fcb6f816-899d-4228-91d3-ce846453334d')
        && !str_contains($html, 'c4639f19-e73f-45b5-bcc0-7a101998a560')
        && !str_contains($html, '95bf871a-2bbc-45c8-ba07-3c5bdc9ac796');
    $writes[] = ['locale' => $locale, 'html' => $html, 'ok' => $ok, 'pack' => $packLocale];
    $label = $locale === '' ? '(empty→' . $defaultLang . ')' : $locale;
    echo "  {$label} pack={$packLocale} len=" . strlen($html) . ' struct=' . ($ok ? 'PASS' : 'FAIL') . "\n";
    if (!$ok) {
        fwrite(STDERR, "structure fail for {$label}\n");
        exit(2);
    }
}

echo 'plan writes=' . count($writes) . ' apply=' . ($apply ? 'yes' : 'dry-run') . "\n";
if (!$apply) {
    echo "dry-run only\n";
    exit(0);
}

/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);

$attributes->writeExplicit($websiteId, 0, 'product', $productId, 'name', 'zh_Hans_CN', $fullZhName, true);
echo "name zh_Hans_CN => {$fullZhName}\n";

foreach ($writes as $w) {
    $locale = (string)$w['locale'];
    $html = (string)$w['html'];
    if ($locale !== '') {
        LocalDescription::upsertQuiet($productId, $locale, [
            LocalDescription::schema_fields_DESCRIPTION => $html,
        ]);
    }
    $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'description', $locale, $html, true);
    echo 'wrote description ' . ($locale === '' ? '(empty)' : $locale) . ' len=' . strlen($html) . "\n";
}

// Drop duplicate short description rows (keep longest per locale)
$env = include dirname(__DIR__, 5) . '/app/etc/env.php';
$db = $env['db']['master'] ?? [];
$driver = strtolower((string)($db['model'] ?? $db['type'] ?? 'pgsql'));
$isMysql = str_contains($driver, 'mysql') || (($db['adapter'] ?? '') === 'mysql');
try {
    if ($isMysql) {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                (string)($db['hostname'] ?? '127.0.0.1'),
                (string)($db['hostport'] ?? '3306'),
                (string)($db['database'] ?? ''),
            ),
            (string)($db['username'] ?? ''),
            (string)($db['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $lenExpr = 'CHAR_LENGTH(value_text)';
    } else {
        $pdo = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                (string)($db['hostname'] ?? '127.0.0.1'),
                (string)($db['hostport'] ?? '5432'),
                (string)($db['database'] ?? ''),
            ),
            (string)($db['username'] ?? ''),
            (string)($db['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $lenExpr = 'length(value_text)';
    }
    $stmt = $pdo->prepare(
        "SELECT value_id, locale, {$lenExpr} AS len
         FROM w_product_ws_0_attribute_value
         WHERE entity_id = ? AND attribute_code = 'description' AND store_id = 0
         ORDER BY locale, len DESC"
    );
    $stmt->execute([$productId]);
    $perLocale = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $perLocale[(string)$r['locale']][] = ['value_id' => (int)$r['value_id'], 'len' => (int)$r['len']];
    }
    foreach ($perLocale as $loc => $rows) {
        if (count($rows) <= 1) {
            continue;
        }
        usort($rows, static fn($a, $b) => $b['len'] <=> $a['len']);
        array_shift($rows);
        $ids = array_column($rows, 'value_id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $del = $pdo->prepare("DELETE FROM w_product_ws_0_attribute_value WHERE value_id IN ($in)");
        $del->execute($ids);
        echo 'deleted short duplicates locale=' . ($loc === '' ? '(empty)' : $loc) . ' ids=' . implode(',', $ids) . "\n";
    }
} catch (Throwable $e) {
    echo 'dedupe soft-fail: ' . $e->getMessage() . "\n";
}

try {
    /** @var ProductStorefrontCacheInvalidator $inv */
    $inv = ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class);
    $inv->invalidateProduct($productId, $websiteId);
} catch (Throwable $e) {
    echo 'cache invalidate soft-fail: ' . $e->getMessage() . "\n";
}
try {
    /** @var StorefrontCatalogCacheCoordinator $coord */
    $coord = ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class);
    $coord->notifyCatalogChanged($websiteId, 'detail_suite_p175', ['product_id' => $productId]);
} catch (Throwable $e) {
    echo 'catalog notify soft-fail: ' . $e->getMessage() . "\n";
}

echo "DONE product {$productId}\n";
