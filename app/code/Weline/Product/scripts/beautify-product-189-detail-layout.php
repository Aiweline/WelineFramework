<?php

declare(strict_types=1);

/**
 * #189 月狐生莲 · ecommerce-detail-suite（图处理 + 卖点表 + 多样美学）
 *
 * 图分流摘要：
 * detail-02 设计灵感字板 → 删图抽文
 * detail-04/05/06 烤字拼板 → 抽卖点；特写裁净后扩/高清再引用
 * detail-07 尺码字板 → 删图；语义表（勿 OCR「上家」）
 * detail-01/03/08/09/18 厂家框 → 裁净 + pil_crop_lanczos_upscale
 * detail-11/12/16/20/21 净实拍 → lanczos 短边≥1200
 * gallery 01–08 → remediate HD ffmpeg_lanczos_upscale / CDN
 *
 * 卖点表：
 * | 卖点 | 痛点/欲求 | 证据 | 视觉 | 原型 |
 * | 交领银绣 | 细节显气质 | 领缘/袖口可见银绣 | macro | macro_annotate |
 * | 狐莲裾印 | 华贵主视觉 | 裾边白狐粉莲 | 通栏特写 | stack / feature_lr |
 * | 束腰剪裁 | 修身显瘦 | 腰线收束可见 | 半身 | feature_lr |
 * | 飞机袖形 | 明制日常好穿 | 袖型挺括不臃 | 全身 | fullbleed / pair |
 *
 * 原型序列（落码锁）：
 * fullbleed_hero → editorial_prose(lead) → editorial_prose(verse) → pair_gallery
 * → stack_caption → macro_annotate → feature_lr#1 → triptych → quiet_spacer
 * → checklist_trust → feature_lr#2 → spec_panel → size_chart → editorial_prose(original)
 * → fullbleed_hero(close)
 * feature_lr 共 2 次且不相邻。
 *
 * php app/code/Weline/Product/scripts/beautify-product-189-detail-layout.php --apply
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
$productId = 189;

$A = [
    'hero' => '015f4525-7791-4abc-ba21-2acabc3cd05b',
    'pair_a' => 'e831316a-c787-42ad-8db5-9e7d843b3160',
    'pair_b' => 'dbdc4771-402d-4a83-8fdd-fca08d09363c',
    'stack' => '3f8e8412-d918-4b36-ad02-c9ce4f232a63',
    'macro_collar' => '2e271ae5-ad0f-4f3a-9fb5-a2c17a2939a5',
    'macro_waist' => '813fb409-a3f3-4c69-96d9-b26e178600ce',
    'feature_red' => '064df789-1703-4571-b414-e82aa9f54e5b',
    'g02' => '79d088ee-c4e9-43fa-a033-48096022f1f0',
    'g03' => 'cf50630e-8116-4805-986f-6ad61f52b4b0',
    'g04' => '64f95dfd-ba79-4128-a698-46e745e8a68c',
    'hem' => '2d7df0be-fc69-49be-8e72-05f78a6bb780',
    'close' => '1bc24667-6c56-4273-91c1-d37491d30a95',
    'look_red' => '4531b576-e758-4823-b58c-7eb975e031ee',
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

$figureStack = static function (array $imgs, string $mod = '') use ($h): string {
    $rows = '';
    $n = count($imgs);
    if ($n >= 3) {
        $rows .= '<div class="weline-detail-figure-row weline-detail-figure-row--triptych">';
        foreach ($imgs as $one) {
            $rows .= '<div class="weline-detail-figure">' . $one . '</div>';
        }
        $rows .= '</div>';
    } else {
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
    }
    $cls = 'weline-detail-figure-stack' . ($mod !== '' ? ' ' . $mod : '');

    return '<div class="' . $h($cls) . '">' . $rows . '</div>';
};

/** @var array<string, array<string, mixed>> $copy */
$copy = [
    'zh_Hans_CN' => [
        'intro_title' => '月狐生莲',
        'intro_body' => '明制两件：飞机袖上衣与刺绣马面裙。交领绣痕清浅，裾边狐影莲开，日常亦可端庄。',
        'inspire_title' => '设计心源',
        'inspire_lines' => ['月下有狐，莲心初绽。', '白狐穿云，粉菡浮波。'],
        'inspire_note' => '以「月狐生莲」为题眼——裾边印花与交领绣意相映；非平台货盘说辞，仅为形制与纹样之点题。',
        'stack_title' => '交领绣意',
        'stack_body' => '近观领缘银绣细润，袖口同纹相承；束腰后腰线分明，更显修长。',
        'macro_title' => '细处可辨',
        'collar_label' => '领缘绣花',
        'collar_body' => '交领叠合处铺银绣花枝，线脚齐整，拉长颈线。',
        'waist_label' => '束腰剪裁',
        'waist_body' => '上衣收束入裙，裙腰平展；腰饰流苏随步轻摆。',
        'hem_macro_label' => '裾边狐莲',
        'hem_macro_body' => '白狐穿云、粉莲点缀裾边，印花层次分明。',
        'sleeve_title' => '飞机袖形',
        'sleeve_body' => '明制飞机袖挺括利落，抬臂不显臃肿；袖口绣纹与领缘呼应。',
        'triptych_note' => '三色着装气韵',
        'quiet_line' => '狐影在裾，不在喧哗。',
        'checklist_title' => '衣袂可记',
        'checklist' => ['交领银绣可见于领缘与袖口', '马面裾边狐莲印花层次清晰', '束腰剪裁修饰腰身', '黑 / 红 / 蓝三色可选'],
        'hem_title' => '马面印花',
        'hem_body' => '裾边白狐与粉莲铺陈，暗花底纹托出华贵，褶影随步起伏。',
        'info_brand' => '花朝记',
        'info_name' => '月狐生莲',
        'info_color' => '黑色、红色、蓝色',
        'info_style' => '明制',
        'info_size' => 'S–XL',
        'info_fabric' => '精选面料（如图）',
        'info_parts' => '上衣、马面裙',
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
        't_top' => '上衣',
        't_skirt' => '马面裙',
        'h_size' => '尺码',
        'h_bust' => '胸围',
        'h_sleeve' => '通袖长',
        'h_cuff' => '袖口',
        'h_len' => '衣长',
        'h_skirt_len' => '总裙长',
        'h_waist_band' => '裙腰长',
        'h_gate' => '裙门',
        'h_height' => '建议身高',
        'original_title' => '原创心迹',
        'original_body' => '本款形制与纹样为花朝记原创设计，敬请珍惜衣冠、尊重匠心。',
        'close_caption' => '月狐生莲 · 明制日常',
        'alt_hero' => '月狐生莲 · 套装',
        'alt_look' => '月狐生莲 · 着装',
        'alt_macro' => '月狐生莲 · 细部',
        'alt_hem' => '月狐生莲 · 裾边',
        'alt_close' => '月狐生莲 · 合影',
        'c_soft' => '柔软',
        'c_soft_opts' => ['偏软', '适中', '偏硬'],
        'c_soft_sel' => '适中',
        'c_thick' => '厚度',
        'c_thick_opts' => ['薄', '适中', '厚'],
        'c_thick_sel' => '适中',
        'c_stretch' => '弹性',
        'c_stretch_opts' => ['无弹', '微弹', '高弹'],
        'c_stretch_sel' => '微弹',
    ],
    'en_US' => [
        'intro_title' => 'Moon Fox Lotus',
        'intro_body' => 'A Ming-style duo: airplane-sleeve top and embroidered mamian skirt. Silver collar stitches stay quiet; fox and lotus bloom along the hem—poised enough for daily wear.',
        'inspire_title' => 'Design wellspring',
        'inspire_lines' => ['A fox under moonlight; a lotus just opening.', 'White fox through mist; pink lotus on the wave.'],
        'inspire_note' => 'Named for “Moon Fox Lotus”—hem print and collar embroidery answer each other; not marketplace pitch, only a motif for cut and pattern.',
        'stack_title' => 'Cross-collar stitch',
        'stack_body' => 'Silver embroidery runs the collar edge; matching cuff motifs; a cinched waist keeps the line long and clear.',
        'macro_title' => 'Close looking',
        'collar_label' => 'Collar embroidery',
        'collar_body' => 'Silver floral stitches along the overlapping collar, neat and lengthening the neck.',
        'waist_label' => 'Waist cut',
        'waist_body' => 'Top tucked into a flat waistband; a hanging ornament sways with the step.',
        'hem_macro_label' => 'Fox & lotus hem',
        'hem_macro_body' => 'White foxes in mist and pink lotuses layer clearly at the hem.',
        'sleeve_title' => 'Airplane sleeves',
        'sleeve_body' => 'Ming airplane sleeves stay crisp without bulk; cuff motifs echo the collar.',
        'triptych_note' => 'Three color moods',
        'quiet_line' => 'The fox lives at the hem, not in noise.',
        'checklist_title' => 'Worth noting',
        'checklist' => ['Silver embroidery visible on collar and cuffs', 'Fox–lotus hem print with clear layers', 'Cinched cut that shapes the waist', 'Black / red / blue options'],
        'hem_title' => 'Mamian print',
        'hem_body' => 'White fox and pink lotus along the hem; tonal jacquard beneath; pleats move with each step.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Moon Fox Lotus',
        'info_color' => 'Black, red, blue',
        'info_style' => 'Ming style',
        'info_size' => 'S–XL',
        'info_fabric' => 'Selected fabric (as shown)',
        'info_parts' => 'Top, mamian skirt',
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
        'chart_title' => 'Size reference',
        'chart_note' => 'Centimeters. Handmade measure may vary by 1–3 cm.',
        't_top' => 'Top',
        't_skirt' => 'Mamian skirt',
        'h_size' => 'Size',
        'h_bust' => 'Bust',
        'h_sleeve' => 'Sleeve span',
        'h_cuff' => 'Cuff',
        'h_len' => 'Length',
        'h_skirt_len' => 'Skirt length',
        'h_waist_band' => 'Waist band',
        'h_gate' => 'Front panel',
        'h_height' => 'Suggested height',
        'original_title' => 'Original craft',
        'original_body' => 'Cut and motifs are Huazhaoji originals—please honor the craft.',
        'close_caption' => 'Moon Fox Lotus · Ming daily wear',
        'alt_hero' => 'Moon Fox Lotus · set',
        'alt_look' => 'Moon Fox Lotus · worn',
        'alt_macro' => 'Moon Fox Lotus · detail',
        'alt_hem' => 'Moon Fox Lotus · hem',
        'alt_close' => 'Moon Fox Lotus · closing',
        'c_soft' => 'Softness',
        'c_soft_opts' => ['Softer', 'Medium', 'Firmer'],
        'c_soft_sel' => 'Medium',
        'c_thick' => 'Thickness',
        'c_thick_opts' => ['Thin', 'Medium', 'Thick'],
        'c_thick_sel' => 'Medium',
        'c_stretch' => 'Stretch',
        'c_stretch_opts' => ['None', 'Slight', 'High'],
        'c_stretch_sel' => 'Slight',
    ],
    'es_ES' => [
        'intro_title' => 'Zorro lunar y loto',
        'intro_body' => 'Dúo estilo Ming: blusa de manga avión y falda mamian bordada. Bordado plateado en el cuello; zorro y loto en el bajo—elegante también a diario.',
        'inspire_title' => 'Manantial del diseño',
        'inspire_lines' => ['Zorro bajo la luna; loto que se abre.', 'Zorro blanco en la niebla; loto rosa en la ola.'],
        'inspire_note' => 'Titulado «Zorro lunar y loto»: estampa del bajo y bordado del cuello se responden; no es discurso de plataforma.',
        'stack_title' => 'Cuello cruzado bordado',
        'stack_body' => 'Bordado plateado en el borde del cuello; mismos motivos en el puño; cintura ceñida alarga la línea.',
        'macro_title' => 'De cerca',
        'collar_label' => 'Bordado del cuello',
        'collar_body' => 'Flores plateadas en el cuello cruzado, puntada limpia que alarga el cuello.',
        'waist_label' => 'Corte de cintura',
        'waist_body' => 'Blusa metida en pretina plana; adorno de cintura que oscila al caminar.',
        'hem_macro_label' => 'Zorro y loto',
        'hem_macro_body' => 'Zorros blancos en niebla y lotos rosa en capas claras.',
        'sleeve_title' => 'Manga avión',
        'sleeve_body' => 'Mangas Ming nítidas sin volumen; el puño responde al cuello.',
        'triptych_note' => 'Tres matices de color',
        'quiet_line' => 'El zorro vive en el bajo, no en el ruido.',
        'checklist_title' => 'Para recordar',
        'checklist' => ['Bordado plateado en cuello y puños', 'Estampa zorro-loto con capas claras', 'Corte que afina la cintura', 'Negro / rojo / azul'],
        'hem_title' => 'Estampa mamian',
        'hem_body' => 'Zorro blanco y loto rosa en el bajo; jacquard tonal debajo; pliegues con el paso.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Zorro lunar y loto',
        'info_color' => 'Negro, rojo, azul',
        'info_style' => 'Estilo Ming',
        'info_size' => 'S–XL',
        'info_fabric' => 'Tela seleccionada (como en imagen)',
        'info_parts' => 'Blusa, falda mamian',
        'info_title' => 'De un vistazo',
        'info_basics' => 'Básico',
        'info_comfort' => 'Al tacto',
        'label_brand' => 'Marca',
        'label_name' => 'Nombre',
        'label_color' => 'Color',
        'label_style' => 'Estilo',
        'label_size' => 'Talla',
        'label_fabric' => 'Tela',
        'label_parts' => 'Partes',
        'chart_title' => 'Referencia de tallas',
        'chart_note' => 'Centímetros. Medida manual puede variar 1–3 cm.',
        't_top' => 'Blusa',
        't_skirt' => 'Falda mamian',
        'h_size' => 'Talla',
        'h_bust' => 'Busto',
        'h_sleeve' => 'Manga total',
        'h_cuff' => 'Puño',
        'h_len' => 'Largo',
        'h_skirt_len' => 'Largo falda',
        'h_waist_band' => 'Cintura',
        'h_gate' => 'Panel frontal',
        'h_height' => 'Altura sugerida',
        'original_title' => 'Oficio original',
        'original_body' => 'Corte y motivos son originales de Huazhaoji; respeten la labor.',
        'close_caption' => 'Zorro lunar y loto · diario Ming',
        'alt_hero' => 'Zorro lunar y loto · conjunto',
        'alt_look' => 'Zorro lunar y loto · puesto',
        'alt_macro' => 'Zorro lunar y loto · detalle',
        'alt_hem' => 'Zorro lunar y loto · bajo',
        'alt_close' => 'Zorro lunar y loto · cierre',
        'c_soft' => 'Suavidad',
        'c_soft_opts' => ['Más suave', 'Medio', 'Más firme'],
        'c_soft_sel' => 'Medio',
        'c_thick' => 'Grosor',
        'c_thick_opts' => ['Fino', 'Medio', 'Grueso'],
        'c_thick_sel' => 'Medio',
        'c_stretch' => 'Elasticidad',
        'c_stretch_opts' => ['Ninguna', 'Ligera', 'Alta'],
        'c_stretch_sel' => 'Ligera',
    ],
    'fr_FR' => [
        'intro_title' => 'Renard lunaire et lotus',
        'intro_body' => 'Duo style Ming : haut à manches avion et jupe mamian brodée. Broderie argentée au col ; renard et lotus à l’ourlet—élégant aussi au quotidien.',
        'inspire_title' => 'Source du dessin',
        'inspire_lines' => ['Renard sous la lune ; lotus qui s’ouvre.', 'Renard blanc dans la brume ; lotus rose sur l’onde.'],
        'inspire_note' => 'Titré « Renard lunaire et lotus » : imprimé d’ourlet et broderie de col se répondent ; pas un discours de plateforme.',
        'stack_title' => 'Col croisé brodé',
        'stack_body' => 'Broderie argentée sur le bord du col ; motifs assortis au poignet ; taille cintrée pour allonger la ligne.',
        'macro_title' => 'De près',
        'collar_label' => 'Broderie du col',
        'collar_body' => 'Fleurs argentées sur le col croisé, points nets qui allongent le cou.',
        'waist_label' => 'Coupe de taille',
        'waist_body' => 'Haut rentré dans une ceinture plate ; ornement de taille qui balance au pas.',
        'hem_macro_label' => 'Renard et lotus',
        'hem_macro_body' => 'Renards blancs dans la brume et lotus roses en couches nettes.',
        'sleeve_title' => 'Manches avion',
        'sleeve_body' => 'Manches Ming nettes sans volume ; le poignet répond au col.',
        'triptych_note' => 'Trois nuances de couleur',
        'quiet_line' => 'Le renard vit à l’ourlet, pas dans le bruit.',
        'checklist_title' => 'À retenir',
        'checklist' => ['Broderie argentée au col et aux poignets', 'Imprimé renard-lotus en couches nettes', 'Coupe qui affine la taille', 'Noir / rouge / bleu'],
        'hem_title' => 'Imprimé mamian',
        'hem_body' => 'Renard blanc et lotus rose à l’ourlet ; jacquard tonal dessous ; plis au rythme du pas.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Renard lunaire et lotus',
        'info_color' => 'Noir, rouge, bleu',
        'info_style' => 'Style Ming',
        'info_size' => 'S–XL',
        'info_fabric' => 'Tissu sélectionné (comme sur l’image)',
        'info_parts' => 'Haut, jupe mamian',
        'info_title' => 'En un coup d’œil',
        'info_basics' => 'Base',
        'info_comfort' => 'Au toucher',
        'label_brand' => 'Marque',
        'label_name' => 'Nom',
        'label_color' => 'Couleur',
        'label_style' => 'Style',
        'label_size' => 'Taille',
        'label_fabric' => 'Tissu',
        'label_parts' => 'Pièces',
        'chart_title' => 'Référence des tailles',
        'chart_note' => 'Centimètres. Mesure manuelle : écart possible de 1–3 cm.',
        't_top' => 'Haut',
        't_skirt' => 'Jupe mamian',
        'h_size' => 'Taille',
        'h_bust' => 'Poitrine',
        'h_sleeve' => 'Manche totale',
        'h_cuff' => 'Poignet',
        'h_len' => 'Longueur',
        'h_skirt_len' => 'Longueur jupe',
        'h_waist_band' => 'Ceinture',
        'h_gate' => 'Panneau avant',
        'h_height' => 'Taille suggérée',
        'original_title' => 'Création originale',
        'original_body' => 'Coupe et motifs sont des créations Huazhaoji ; honorez le savoir-faire.',
        'close_caption' => 'Renard lunaire et lotus · quotidien Ming',
        'alt_hero' => 'Renard lunaire et lotus · ensemble',
        'alt_look' => 'Renard lunaire et lotus · porté',
        'alt_macro' => 'Renard lunaire et lotus · détail',
        'alt_hem' => 'Renard lunaire et lotus · ourlet',
        'alt_close' => 'Renard lunaire et lotus · clôture',
        'c_soft' => 'Douceur',
        'c_soft_opts' => ['Plus doux', 'Moyen', 'Plus ferme'],
        'c_soft_sel' => 'Moyen',
        'c_thick' => 'Épaisseur',
        'c_thick_opts' => ['Fin', 'Moyen', 'Épais'],
        'c_thick_sel' => 'Moyen',
        'c_stretch' => 'Élasticité',
        'c_stretch_opts' => ['Aucune', 'Légère', 'Forte'],
        'c_stretch_sel' => 'Légère',
    ],
    'pt_BR' => [
        'intro_title' => 'Raposa lunar e lótus',
        'intro_body' => 'Duo estilo Ming: blusa de manga avião e saia mamian bordada. Bordado prateado no colarinho; raposa e lótus na barra—elegante também no dia a dia.',
        'inspire_title' => 'Nascente do desenho',
        'inspire_lines' => ['Raposa sob a lua; lótus que se abre.', 'Raposa branca na névoa; lótus rosa na onda.'],
        'inspire_note' => 'Intitulado «Raposa lunar e lótus»: estampa da barra e bordado do colarinho se respondem; não é discurso de plataforma.',
        'stack_title' => 'Colarinho cruzado bordado',
        'stack_body' => 'Bordado prateado na borda do colarinho; motivos iguais no punho; cintura marcada alonga a linha.',
        'macro_title' => 'De perto',
        'collar_label' => 'Bordado do colarinho',
        'collar_body' => 'Flores prateadas no colarinho cruzado, ponto limpo que alonga o pescoço.',
        'waist_label' => 'Corte da cintura',
        'waist_body' => 'Blusa dentro de cós plano; ornamento de cintura balança ao caminhar.',
        'hem_macro_label' => 'Raposa e lótus',
        'hem_macro_body' => 'Raposas brancas na névoa e lótus rosa em camadas claras.',
        'sleeve_title' => 'Manga avião',
        'sleeve_body' => 'Mangas Ming nítidas sem volume; o punho ecoa o colarinho.',
        'triptych_note' => 'Três matizes de cor',
        'quiet_line' => 'A raposa vive na barra, não no barulho.',
        'checklist_title' => 'Para lembrar',
        'checklist' => ['Bordado prateado no colarinho e punhos', 'Estampa raposa-lótus com camadas claras', 'Corte que afina a cintura', 'Preto / vermelho / azul'],
        'hem_title' => 'Estampa mamian',
        'hem_body' => 'Raposa branca e lótus rosa na barra; jacquard tonal por baixo; pregas com o passo.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Raposa lunar e lótus',
        'info_color' => 'Preto, vermelho, azul',
        'info_style' => 'Estilo Ming',
        'info_size' => 'S–XL',
        'info_fabric' => 'Tecido selecionado (como na imagem)',
        'info_parts' => 'Blusa, saia mamian',
        'info_title' => 'Em um olhar',
        'info_basics' => 'Básico',
        'info_comfort' => 'Ao toque',
        'label_brand' => 'Marca',
        'label_name' => 'Nome',
        'label_color' => 'Cor',
        'label_style' => 'Estilo',
        'label_size' => 'Tamanho',
        'label_fabric' => 'Tecido',
        'label_parts' => 'Peças',
        'chart_title' => 'Referência de tamanhos',
        'chart_note' => 'Centímetros. Medida manual pode variar 1–3 cm.',
        't_top' => 'Blusa',
        't_skirt' => 'Saia mamian',
        'h_size' => 'Tamanho',
        'h_bust' => 'Busto',
        'h_sleeve' => 'Manga total',
        'h_cuff' => 'Punho',
        'h_len' => 'Comprimento',
        'h_skirt_len' => 'Comprimento saia',
        'h_waist_band' => 'Cintura',
        'h_gate' => 'Painel frontal',
        'h_height' => 'Altura sugerida',
        'original_title' => 'Ofício original',
        'original_body' => 'Corte e motivos são originais da Huazhaoji; honrem o ofício.',
        'close_caption' => 'Raposa lunar e lótus · cotidiano Ming',
        'alt_hero' => 'Raposa lunar e lótus · conjunto',
        'alt_look' => 'Raposa lunar e lótus · vestido',
        'alt_macro' => 'Raposa lunar e lótus · detalhe',
        'alt_hem' => 'Raposa lunar e lótus · barra',
        'alt_close' => 'Raposa lunar e lótus · fechamento',
        'c_soft' => 'Maciez',
        'c_soft_opts' => ['Mais macio', 'Médio', 'Mais firme'],
        'c_soft_sel' => 'Médio',
        'c_thick' => 'Espessura',
        'c_thick_opts' => ['Fino', 'Médio', 'Grosso'],
        'c_thick_sel' => 'Médio',
        'c_stretch' => 'Elasticidade',
        'c_stretch_opts' => ['Nenhuma', 'Leve', 'Alta'],
        'c_stretch_sel' => 'Leve',
    ],
    'id_ID' => [
        'intro_title' => 'Rubah bulan dan teratai',
        'intro_body' => 'Duet gaya Ming: atasan lengan pesawat dan rok mamian bersulam. Sulaman perak di kerah; rubah dan teratai di hem—anggun juga untuk sehari-hari.',
        'inspire_title' => 'Sumber desain',
        'inspire_lines' => ['Rubah di bawah bulan; teratai yang mekar.', 'Rubah putih di kabut; teratai merah muda di ombak.'],
        'inspire_note' => 'Bernama «Rubah bulan dan teratai»: cetakan hem dan sulaman kerah saling menjawab; bukan bahasa platform.',
        'stack_title' => 'Kerah silang bersulam',
        'stack_body' => 'Sulaman perak di tepi kerah; motif sama di manset; pinggang diikat memperpanjang garis.',
        'macro_title' => 'Dari dekat',
        'collar_label' => 'Sulaman kerah',
        'collar_body' => 'Bunga perak di kerah silang, jahitan rapi memanjangkan leher.',
        'waist_label' => 'Potongan pinggang',
        'waist_body' => 'Atasan masuk ke sabuk datar; ornamen pinggang bergoyang saat melangkah.',
        'hem_macro_label' => 'Rubah dan teratai',
        'hem_macro_body' => 'Rubah putih di kabut dan teratai merah muda berlapis jelas.',
        'sleeve_title' => 'Lengan pesawat',
        'sleeve_body' => 'Lengan Ming rapi tanpa menggembung; manset menjawab kerah.',
        'triptych_note' => 'Tiga nuansa warna',
        'quiet_line' => 'Rubah hidup di hem, bukan di keramaian.',
        'checklist_title' => 'Patut diingat',
        'checklist' => ['Sulaman perak di kerah dan manset', 'Cetakan rubah-teratai berlapis jelas', 'Potongan yang merampingkan pinggang', 'Hitam / merah / biru'],
        'hem_title' => 'Cetakan mamian',
        'hem_body' => 'Rubah putih dan teratai merah muda di hem; jacquard tonal di bawahnya; lipit mengikuti langkah.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Rubah bulan dan teratai',
        'info_color' => 'Hitam, merah, biru',
        'info_style' => 'Gaya Ming',
        'info_size' => 'S–XL',
        'info_fabric' => 'Kain pilihan (seperti di gambar)',
        'info_parts' => 'Atasan, rok mamian',
        'info_title' => 'Sekilas',
        'info_basics' => 'Dasar',
        'info_comfort' => 'Saat disentuh',
        'label_brand' => 'Merek',
        'label_name' => 'Nama',
        'label_color' => 'Warna',
        'label_style' => 'Gaya',
        'label_size' => 'Ukuran',
        'label_fabric' => 'Kain',
        'label_parts' => 'Bagian',
        'chart_title' => 'Referensi ukuran',
        'chart_note' => 'Sentimeter. Pengukuran tangan bisa selisih 1–3 cm.',
        't_top' => 'Atasan',
        't_skirt' => 'Rok mamian',
        'h_size' => 'Ukuran',
        'h_bust' => 'Dada',
        'h_sleeve' => 'Bentang lengan',
        'h_cuff' => 'Manset',
        'h_len' => 'Panjang',
        'h_skirt_len' => 'Panjang rok',
        'h_waist_band' => 'Pinggang',
        'h_gate' => 'Panel depan',
        'h_height' => 'Tinggi disarankan',
        'original_title' => 'Kriya asli',
        'original_body' => 'Potongan dan motif adalah karya asli Huazhaoji; hormati kriya.',
        'close_caption' => 'Rubah bulan dan teratai · harian Ming',
        'alt_hero' => 'Rubah bulan dan teratai · set',
        'alt_look' => 'Rubah bulan dan teratai · dipakai',
        'alt_macro' => 'Rubah bulan dan teratai · detail',
        'alt_hem' => 'Rubah bulan dan teratai · hem',
        'alt_close' => 'Rubah bulan dan teratai · penutup',
        'c_soft' => 'Kelembutan',
        'c_soft_opts' => ['Lebih lembut', 'Sedang', 'Lebih kaku'],
        'c_soft_sel' => 'Sedang',
        'c_thick' => 'Ketebalan',
        'c_thick_opts' => ['Tipis', 'Sedang', 'Tebal'],
        'c_thick_sel' => 'Sedang',
        'c_stretch' => 'Elastisitas',
        'c_stretch_opts' => ['Tidak ada', 'Sedikit', 'Tinggi'],
        'c_stretch_sel' => 'Sedikit',
    ],
    'ar_SA' => [
        'intro_title' => 'ثعلب القمر واللوتس',
        'intro_body' => 'طقم على طراز مينغ: قميص بأكمام طائرة وتنورة ماميان مطرّزة. تطريز الياقة فضي هادئ، وثعلب ولوتس على الحاشية—أنيق لليومي أيضاً.',
        'inspire_title' => 'منبع التصميم',
        'inspire_lines' => ['ثعلب تحت القمر؛ لوتس يتفتح.', 'ثعلب أبيض في الضباب؛ لوتس وردي على الموج.'],
        'inspire_note' => 'باسم «ثعلب القمر واللوتس»—طباعة الحاشية وتطريز الياقة يتناغمان؛ ليست لغة سوق، بل مفتاح للقص والنقش.',
        'stack_title' => 'تطريز الياقة المتداخلة',
        'stack_body' => 'تطريز فضي على حافة الياقة وأساور متطابقة؛ الخصر مشدود لخط أطول أوضح.',
        'macro_title' => 'تفاصيل قريبة',
        'collar_label' => 'تطريز الياقة',
        'collar_body' => 'زهور فضية على الياقة المتداخلة، خياطة مرتبة تطيل العنق.',
        'waist_label' => 'قصّ الخصر',
        'waist_body' => 'القميص داخل حزام مسطح؛ زينة الخصر تتمايل مع الخطوة.',
        'hem_macro_label' => 'ثعلب ولوتس على الحاشية',
        'hem_macro_body' => 'ثعالب بيضاء في الضباب ولوتس وردي بطبقات واضحة.',
        'sleeve_title' => 'الأكمام الطائرة',
        'sleeve_body' => 'أكمام مينغ الطائرة مشدودة بلا تضخم؛ نقش الكم يجاوب الياقة.',
        'triptych_note' => 'ثلاث أمزجة لونية',
        'quiet_line' => 'الثعلب في الحاشية، لا في الضجيج.',
        'checklist_title' => 'ما يُذكر',
        'checklist' => ['تطريز فضي ظاهر على الياقة والأساور', 'طباعة ثعلب ولوتس بطبقات واضحة', 'قصّ يشدّ الخصر', 'أسود / أحمر / أزرق'],
        'hem_title' => 'طباعة الماميان',
        'hem_body' => 'ثعلب أبيض ولوتس وردي على الحاشية؛ جاكار خفي تحتها؛ الطيات تتحرك مع الخطوة.',
        'info_brand' => 'هواجاوجي',
        'info_name' => 'ثعلب القمر واللوتس',
        'info_color' => 'أسود، أحمر، أزرق',
        'info_style' => 'أسلوب مينغ',
        'info_size' => 'S–XL',
        'info_fabric' => 'قماش مختار (كما في الصورة)',
        'info_parts' => 'قميص، تنورة ماميان',
        'info_title' => 'نظرة سريعة',
        'info_basics' => 'أساسي',
        'info_comfort' => 'الإحساس عند اللمس',
        'label_brand' => 'العلامة',
        'label_name' => 'الاسم',
        'label_color' => 'اللون',
        'label_style' => 'الطراز',
        'label_size' => 'المقاس',
        'label_fabric' => 'القماش',
        'label_parts' => 'الأجزاء',
        'chart_title' => 'مرجع المقاسات',
        'chart_note' => 'بالسنتيمتر. القياس اليدوي قد يختلف ١–٣ سم.',
        't_top' => 'القميص',
        't_skirt' => 'تنورة ماميان',
        'h_size' => 'المقاس',
        'h_bust' => 'الصدر',
        'h_sleeve' => 'امتداد الكم',
        'h_cuff' => 'فتحة الكم',
        'h_len' => 'الطول',
        'h_skirt_len' => 'طول التنورة',
        'h_waist_band' => 'طول الخصر',
        'h_gate' => 'لوحة الأمام',
        'h_height' => 'الطول المقترح',
        'original_title' => 'حرفة أصلية',
        'original_body' => 'القص والنقوش من تصميم هواجاوجي الأصلي—يُرجى احترام الحرفة.',
        'close_caption' => 'ثعلب القمر واللوتس · يومي على طراز مينغ',
        'alt_hero' => 'ثعلب القمر واللوتس · الطقم',
        'alt_look' => 'ثعلب القمر واللوتس · مرتدى',
        'alt_macro' => 'ثعلب القمر واللوتس · تفصيل',
        'alt_hem' => 'ثعلب القمر واللوتس · حاشية',
        'alt_close' => 'ثعلب القمر واللوتس · ختام',
        'c_soft' => 'النعومة',
        'c_soft_opts' => ['أنعم', 'متوسط', 'أصلب'],
        'c_soft_sel' => 'متوسط',
        'c_thick' => 'السماكة',
        'c_thick_opts' => ['رفيع', 'متوسط', 'سميك'],
        'c_thick_sel' => 'متوسط',
        'c_stretch' => 'المرونة',
        'c_stretch_opts' => ['بدون', 'خفيف', 'عالي'],
        'c_stretch_sel' => 'خفيف',
    ],
    'bn_BD' => [
        'intro_title' => 'চাঁদের শিয়াল ও পদ্ম',
        'intro_body' => 'মিং শৈলীর জুটি: এয়ারপ্লেন হাতা টপ ও সূচিকর্ম মামিয়ান স্কার্ট। কলারে রূপালি সূচিকর্ম; হেমে শিয়াল ও পদ্ম—দৈনন্দিনেও শোভন।',
        'inspire_title' => 'নকশার উৎস',
        'inspire_lines' => ['চাঁদের নিচে শিয়াল; পদ্ম ফুটছে।', 'কুয়াশায় সাদা শিয়াল; ঢেউয়ে গোলাপি পদ্ম।'],
        'inspire_note' => '«চাঁদের শিয়াল ও পদ্ম» নামে—হেম প্রিন্ট ও কলার সূচিকর্ম একে অপরের উত্তর; প্ল্যাটফর্মের বাক্য নয়।',
        'stack_title' => 'ক্রস কলার সূচিকর্ম',
        'stack_body' => 'কলার প্রান্তে রূপালি সূচিকর্ম; কফে একই মোটিফ; কোমর বাঁধা রেখা লম্বা করে।',
        'macro_title' => 'কাছ থেকে',
        'collar_label' => 'কলার সূচিকর্ম',
        'collar_body' => 'ক্রস কলারে রূপালি ফুল, পরিষ্কার সেলাই যা গলা লম্বা করে।',
        'waist_label' => 'কোমর কাট',
        'waist_body' => 'টপ সমতল কোমরবন্ধে ঢোকানো; কোমর অলংকার পায়ে পায়ে দোলে।',
        'hem_macro_label' => 'শিয়াল ও পদ্ম',
        'hem_macro_body' => 'কুয়াশায় সাদা শিয়াল ও স্পষ্ট স্তরে গোলাপি পদ্ম।',
        'sleeve_title' => 'এয়ারপ্লেন হাতা',
        'sleeve_body' => 'মিং এয়ারপ্লেন হাতা পরিষ্কার, ফোলা নয়; কফ কলারের উত্তর দেয়।',
        'triptych_note' => 'তিন রঙের মেজাজ',
        'quiet_line' => 'শিয়াল হেমে থাকে, হট্টগোলে নয়।',
        'checklist_title' => 'মনে রাখার মতো',
        'checklist' => ['কলার ও কফে রূপালি সূচিকর্ম', 'স্পষ্ট স্তরে শিয়াল-পদ্ম প্রিন্ট', 'কোমর গঠনকারী কাট', 'কালো / লাল / নীল'],
        'hem_title' => 'মামিয়ান প্রিন্ট',
        'hem_body' => 'হেমে সাদা শিয়াল ও গোলাপি পদ্ম; নিচে টোনাল জ্যাকোয়ার্ড; ভাঁজ পায়ে পায়ে ওঠে।',
        'info_brand' => 'হুয়াঝাওজি',
        'info_name' => 'চাঁদের শিয়াল ও পদ্ম',
        'info_color' => 'কালো, লাল, নীল',
        'info_style' => 'মিং শৈলী',
        'info_size' => 'S–XL',
        'info_fabric' => 'নির্বাচিত কাপড় (ছবি অনুযায়ী)',
        'info_parts' => 'টপ, মামিয়ান স্কার্ট',
        'info_title' => 'এক নজরে',
        'info_basics' => 'মূল',
        'info_comfort' => 'স্পর্শে অনুভূতি',
        'label_brand' => 'ব্র্যান্ড',
        'label_name' => 'নাম',
        'label_color' => 'রঙ',
        'label_style' => 'শৈলী',
        'label_size' => 'সাইজ',
        'label_fabric' => 'কাপড়',
        'label_parts' => 'অংশ',
        'chart_title' => 'সাইজ রেফারেন্স',
        'chart_note' => 'সেন্টিমিটার। হাতে মাপলে ১–৩ সেমি তফাত হতে পারে।',
        't_top' => 'টপ',
        't_skirt' => 'মামিয়ান স্কার্ট',
        'h_size' => 'সাইজ',
        'h_bust' => 'বুক',
        'h_sleeve' => 'হাতার বিস্তার',
        'h_cuff' => 'কফ',
        'h_len' => 'লম্বা',
        'h_skirt_len' => 'স্কার্ট লম্বা',
        'h_waist_band' => 'কোমর',
        'h_gate' => 'সামনের প্যানেল',
        'h_height' => 'প্রস্তাবিত উচ্চতা',
        'original_title' => 'মূল কারুকাজ',
        'original_body' => 'কাট ও মোটিফ হুয়াঝাওজির মূল—কারুকে সম্মান করুন।',
        'close_caption' => 'চাঁদের শিয়াল ও পদ্ম · মিং দৈনন্দিন',
        'alt_hero' => 'চাঁদের শিয়াল ও পদ্ম · সেট',
        'alt_look' => 'চাঁদের শিয়াল ও পদ্ম · পরা',
        'alt_macro' => 'চাঁদের শিয়াল ও পদ্ম · বিস্তারিত',
        'alt_hem' => 'চাঁদের শিয়াল ও পদ্ম · হেম',
        'alt_close' => 'চাঁদের শিয়াল ও পদ্ম · সমাপ্তি',
        'c_soft' => 'নরমতা',
        'c_soft_opts' => ['নরমতর', 'মাঝারি', 'কঠিনতর'],
        'c_soft_sel' => 'মাঝারি',
        'c_thick' => 'পুরুত্ব',
        'c_thick_opts' => ['পাতলা', 'মাঝারি', 'মোটা'],
        'c_thick_sel' => 'মাঝারি',
        'c_stretch' => 'স্থিতিস্থাপকতা',
        'c_stretch_opts' => ['নেই', 'সামান্য', 'উচ্চ'],
        'c_stretch_sel' => 'সামান্য',
    ],
    'hi_IN' => [
        'intro_title' => 'चंद्र लोमड़ी और कमल',
        'intro_body' => 'मिंग शैली का युग्म: एयरप्लेन स्लीव टॉप और कशीदा मामियन स्कर्ट। कॉलर पर चाँदी की कढ़ाई; हेम पर लोमड़ी और कमल—रोज भी शालीन।',
        'inspire_title' => 'डिज़ाइन स्रोत',
        'inspire_lines' => ['चाँद के नीचे लोमड़ी; खिलता कमल।', 'कोहरे में सफ़ेद लोमड़ी; लहर पर गुलाबी कमल।'],
        'inspire_note' => '«चंद्र लोमड़ी और कमल» नाम से—हेम प्रिंट और कॉलर कढ़ाई एक-दूसरे का उत्तर हैं; प्लेटफ़ॉर्म भाषा नहीं।',
        'stack_title' => 'क्रॉस कॉलर कढ़ाई',
        'stack_body' => 'कॉलर किनारे पर चाँदी की कढ़ाई; कफ़ पर वही रूप; कमर बाँधने से रेखा लंबी।',
        'macro_title' => 'पास से',
        'collar_label' => 'कॉलर कढ़ाई',
        'collar_body' => 'क्रॉस कॉलर पर चाँदी के फूल, साफ़ टाँके जो गर्दन लंबी करें।',
        'waist_label' => 'कमर कट',
        'waist_body' => 'टॉप सपाट कमरबंद में; कमर आभूषण कदम के साथ झूमे।',
        'hem_macro_label' => 'लोमड़ी और कमल',
        'hem_macro_body' => 'कोहरे में सफ़ेद लोमड़ियाँ और स्पष्ट परतों में गुलाबी कमल।',
        'sleeve_title' => 'एयरप्लेन स्लीव',
        'sleeve_body' => 'मिंग एयरप्लेन आस्तीन साफ़, फूली नहीं; कफ़ कॉलर का उत्तर देता है।',
        'triptych_note' => 'तीन रंग मूड',
        'quiet_line' => 'लोमड़ी हेम में रहती है, शोर में नहीं।',
        'checklist_title' => 'याद रखने योग्य',
        'checklist' => ['कॉलर और कफ़ पर चाँदी की कढ़ाई', 'स्पष्ट परतों में लोमड़ी-कमल प्रिंट', 'कमर गढ़ने वाला कट', 'काला / लाल / नीला'],
        'hem_title' => 'मामियन प्रिंट',
        'hem_body' => 'हेम पर सफ़ेद लोमड़ी और गुलाबी कमल; नीचे टोनल जैक्वार्ड; प्लीट कदम के साथ।',
        'info_brand' => 'हुआझाओजी',
        'info_name' => 'चंद्र लोमड़ी और कमल',
        'info_color' => 'काला, लाल, नीला',
        'info_style' => 'मिंग शैली',
        'info_size' => 'S–XL',
        'info_fabric' => 'चयनित कपड़ा (जैसा चित्र में)',
        'info_parts' => 'टॉप, मामियन स्कर्ट',
        'info_title' => 'एक नज़र में',
        'info_basics' => 'मूल',
        'info_comfort' => 'स्पर्श अनुभव',
        'label_brand' => 'ब्रांड',
        'label_name' => 'नाम',
        'label_color' => 'रंग',
        'label_style' => 'शैली',
        'label_size' => 'साइज़',
        'label_fabric' => 'कपड़ा',
        'label_parts' => 'भाग',
        'chart_title' => 'साइज़ संदर्भ',
        'chart_note' => 'सेंटीमीटर। हाथ से माप में १–३ सेमी अंतर हो सकता है।',
        't_top' => 'टॉप',
        't_skirt' => 'मामियन स्कर्ट',
        'h_size' => 'साइज़',
        'h_bust' => 'छाती',
        'h_sleeve' => 'आस्तीन फैलाव',
        'h_cuff' => 'कफ़',
        'h_len' => 'लंबाई',
        'h_skirt_len' => 'स्कर्ट लंबाई',
        'h_waist_band' => 'कमर',
        'h_gate' => 'सामने का पैनल',
        'h_height' => 'सुझाई ऊँचाई',
        'original_title' => 'मूल शिल्प',
        'original_body' => 'कट और रूप हुआझाओजी के मूल हैं—शिल्प का सम्मान करें।',
        'close_caption' => 'चंद्र लोमड़ी और कमल · मिंग दैनिक',
        'alt_hero' => 'चंद्र लोमड़ी और कमल · सेट',
        'alt_look' => 'चंद्र लोमड़ी और कमल · पहना',
        'alt_macro' => 'चंद्र लोमड़ी और कमल · विवरण',
        'alt_hem' => 'चंद्र लोमड़ी और कमल · हेम',
        'alt_close' => 'चंद्र लोमड़ी और कमल · समापन',
        'c_soft' => 'कोमलता',
        'c_soft_opts' => ['अधिक कोमल', 'मध्यम', 'अधिक सख्त'],
        'c_soft_sel' => 'मध्यम',
        'c_thick' => 'मोटाई',
        'c_thick_opts' => ['पतला', 'मध्यम', 'मोटा'],
        'c_thick_sel' => 'मध्यम',
        'c_stretch' => 'लचीलापन',
        'c_stretch_opts' => ['नहीं', 'हल्का', 'अधिक'],
        'c_stretch_sel' => 'हल्का',
    ],
    'ur_PK' => [
        'intro_title' => 'چاندی لومڑی اور کنول',
        'intro_body' => 'منگ طرز کا جوڑا: ایئرپلین آستین ٹاپ اور کڑھائی والی مامیان اسکرٹ۔ کالر پر چاندی کی کڑھائی؛ ہیم پر لومڑی اور کنول—روزانہ بھی شائستہ۔',
        'inspire_title' => 'ڈیزائن کا منبع',
        'inspire_lines' => ['چاند کے نیچے لومڑی؛ کھلتا کنول۔', 'کہر میں سفید لومڑی؛ لہر پر گلابی کنول۔'],
        'inspire_note' => '«چاندی لومڑی اور کنول» نام سے—ہیم پرنٹ اور کالر کڑھائی ایک دوسرے کا جواب ہیں؛ پلیٹ فارم کی زبان نہیں۔',
        'stack_title' => 'کراس کالر کڑھائی',
        'stack_body' => 'کالر کنارے پر چاندی کی کڑھائی؛ کف پر وہی نقش؛ کمر باندھنے سے لکیر لمبی۔',
        'macro_title' => 'قریب سے',
        'collar_label' => 'کالر کڑھائی',
        'collar_body' => 'کراس کالر پر چاندی کے پھول، صاف ٹانکے جو گردن لمبی کریں۔',
        'waist_label' => 'کمر کٹ',
        'waist_body' => 'ٹاپ ہموار کمر بند میں؛ کمر زیور قدم کے ساتھ جھومے۔',
        'hem_macro_label' => 'لومڑی اور کنول',
        'hem_macro_body' => 'کہر میں سفید لومڑیاں اور واضح تہوں میں گلابی کنول۔',
        'sleeve_title' => 'ایئرپلین آستین',
        'sleeve_body' => 'منگ ایئرپلین آستین صاف، پھولی نہیں؛ کف کالر کا جواب دیتا ہے۔',
        'triptych_note' => 'تین رنگوں کے موڈ',
        'quiet_line' => 'لومڑی ہیم میں رہتی ہے، شور میں نہیں۔',
        'checklist_title' => 'یاد رکھنے کے قابل',
        'checklist' => ['کالر اور کف پر چاندی کی کڑھائی', 'واضح تہوں میں لومڑی-کنول پرنٹ', 'کمر بنانے والا کٹ', 'سیاہ / سرخ / نیلا'],
        'hem_title' => 'مامیان پرنٹ',
        'hem_body' => 'ہیم پر سفید لومڑی اور گلابی کنول؛ نیچے ٹونل جیکوارڈ؛ پلیٹ قدم کے ساتھ۔',
        'info_brand' => 'ہواژاوجی',
        'info_name' => 'چاندی لومڑی اور کنول',
        'info_color' => 'سیاہ، سرخ، نیلا',
        'info_style' => 'منگ طرز',
        'info_size' => 'S–XL',
        'info_fabric' => 'منتخب کپڑا (جیسا تصویر میں)',
        'info_parts' => 'ٹاپ، مامیان اسکرٹ',
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
        't_top' => 'ٹاپ',
        't_skirt' => 'مامیان اسکرٹ',
        'h_size' => 'سائز',
        'h_bust' => 'سینہ',
        'h_sleeve' => 'آستین پھیلاؤ',
        'h_cuff' => 'کف',
        'h_len' => 'لمبائی',
        'h_skirt_len' => 'اسکرٹ لمبائی',
        'h_waist_band' => 'کمر',
        'h_gate' => 'سامنے کا پینل',
        'h_height' => 'مجوزہ قد',
        'original_title' => 'اصل دستکاری',
        'original_body' => 'کٹ اور نقوش ہواژاوجی کے اصل ہیں—دستکاری کا احترام کریں۔',
        'close_caption' => 'چاندی لومڑی اور کنول · منگ روزمرہ',
        'alt_hero' => 'چاندی لومڑی اور کنول · سیٹ',
        'alt_look' => 'چاندی لومڑی اور کنول · پہنا',
        'alt_macro' => 'چاندی لومڑی اور کنول · تفصیل',
        'alt_hem' => 'چاندی لومڑی اور کنول · ہیم',
        'alt_close' => 'چاندی لومڑی اور کنول · اختتام',
        'c_soft' => 'نرمی',
        'c_soft_opts' => ['زیادہ نرم', 'درمیانہ', 'زیادہ سخت'],
        'c_soft_sel' => 'درمیانہ',
        'c_thick' => 'موٹائی',
        'c_thick_opts' => ['پتلا', 'درمیانہ', 'موٹا'],
        'c_thick_sel' => 'درمیانہ',
        'c_stretch' => 'کھینچاؤ',
        'c_stretch_opts' => ['نہیں', 'ہلکا', 'زیادہ'],
        'c_stretch_sel' => 'ہلکا',
    ],
];

$assemble = static function (array $t) use ($A, $img, $feature, $figureStack, $h): string {
    /** @var list<string> $lines */
    $lines = $t['inspire_lines'];
    /** @var list<string> $checklist */
    $checklist = $t['checklist'];

    $hero = $figureStack([
        $img($A['hero'], (string)$t['alt_hero'], 1200, 1670),
    ], 'weline-detail-figure-stack--fullbleed');

    $intro = '<div class="weline-detail-prose weline-detail-prose--lead"><h3>' . $h((string)$t['intro_title']) . '</h3><p>'
        . $h((string)$t['intro_body']) . '</p></div>';

    $inspire = '<div class="weline-detail-prose weline-detail-prose--verse"><h3>' . $h((string)$t['inspire_title']) . '</h3>';
    foreach ($lines as $line) {
        $inspire .= '<p>' . $h($line) . '</p>';
    }
    $inspire .= '<p class="weline-detail-feature__note">' . $h((string)$t['inspire_note']) . '</p></div>';

    $pair = $figureStack([
        $img($A['pair_a'], (string)$t['alt_look'] . ' 1', 1200, 1645),
        $img($A['pair_b'], (string)$t['alt_look'] . ' 2', 1200, 1646),
    ]);

    $stack = $figureStack([
        $img($A['stack'], (string)$t['alt_look'] . ' 3', 1200, 1907),
    ], 'weline-detail-figure-stack--caption')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['stack_title']) . '</h3><p>'
        . $h((string)$t['stack_body']) . '</p></div>';

    $macro = $figureStack([
        $img($A['macro_collar'], (string)$t['alt_macro'] . ' 1', 1341, 883),
        $img($A['macro_waist'], (string)$t['alt_macro'] . ' 2', 1200, 1200),
    ], 'weline-detail-figure-stack--caption')
        . '<div class="weline-detail-prose weline-detail-prose--macro"><h3>' . $h((string)$t['macro_title']) . '</h3>'
        . '<h4>' . $h((string)$t['collar_label']) . '</h4><p>' . $h((string)$t['collar_body']) . '</p>'
        . '<h4>' . $h((string)$t['waist_label']) . '</h4><p>' . $h((string)$t['waist_body']) . '</p>'
        . '<h4>' . $h((string)$t['hem_macro_label']) . '</h4><p>' . $h((string)$t['hem_macro_body']) . '</p></div>';

    $sleeve = $feature(
        $img($A['feature_red'], (string)$t['alt_look'] . ' 4', 1200, 1545),
        '<h3>' . $h((string)$t['sleeve_title']) . '</h3><p>' . $h((string)$t['sleeve_body']) . '</p>',
        false,
    );

    $triptych = $figureStack([
        $img($A['g02'], (string)$t['alt_look'] . ' 5', 1500, 1490),
        $img($A['g03'], (string)$t['alt_look'] . ' 6', 1500, 1953),
        $img($A['g04'], (string)$t['alt_look'] . ' 7', 2000, 2000),
    ])
        . '<div class="weline-detail-prose weline-detail-prose--quiet"><p class="weline-detail-feature__note">'
        . $h((string)$t['triptych_note']) . '</p></div>';

    $quiet = '<div class="weline-detail-prose weline-detail-prose--quiet"><p>' . $h((string)$t['quiet_line']) . '</p></div>';

    $check = '<div class="weline-detail-prose weline-detail-prose--checklist"><h3>' . $h((string)$t['checklist_title']) . '</h3><ul>';
    foreach ($checklist as $item) {
        $check .= '<li>' . $h($item) . '</li>';
    }
    $check .= '</ul></div>';

    $hem = $feature(
        $img($A['hem'], (string)$t['alt_hem'], 1286, 851),
        '<h3>' . $h((string)$t['hem_title']) . '</h3><p>' . $h((string)$t['hem_body']) . '</p>',
        true,
    );

    /** @var list<string> $softOpts */
    $softOpts = $t['c_soft_opts'];
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
            ['label' => (string)$t['c_soft'], 'options' => $softOpts, 'selected' => (string)$t['c_soft_sel']],
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
                'title' => (string)$t['t_top'],
                'headers' => [(string)$t['h_size'], (string)$t['h_bust'], (string)$t['h_sleeve'], (string)$t['h_cuff'], (string)$t['h_len']],
                'rows' => [
                    ['S', '≤86', '149', '25', '56'],
                    ['M', '≤92', '154', '26', '58'],
                    ['L', '≤98', '159', '27', '60'],
                    ['XL', '≤110', '162', '28', '62'],
                ],
            ],
            [
                'title' => (string)$t['t_skirt'],
                'headers' => [(string)$t['h_size'], (string)$t['h_skirt_len'], (string)$t['h_waist_band'], (string)$t['h_gate'], (string)$t['h_height']],
                'rows' => [
                    ['S', '90', '105', '24', '149–155'],
                    ['M', '95', '105', '24', '156–162'],
                    ['L', '100', '105', '24', '162–165'],
                    ['XL', '105', '105', '24', '165–172'],
                ],
            ],
        ],
        (string)$t['chart_title'],
        (string)$t['chart_note'],
    );

    $original = '<div class="weline-detail-prose"><h3>' . $h((string)$t['original_title']) . '</h3><p>'
        . $h((string)$t['original_body']) . '</p></div>';

    $close = $figureStack([
        $img($A['close'], (string)$t['alt_close'], 1500, 1928),
    ], 'weline-detail-figure-stack--fullbleed')
        . '<div class="weline-detail-prose weline-detail-prose--quiet"><p class="weline-detail-feature__note">'
        . $h((string)$t['close_caption']) . '</p></div>';

    return '<div data-weline-product-description="1688" data-weline-detail-suite="variety-v1">'
        . $hero . $intro . $inspire . $pair . $stack . $macro
        . $sleeve . $triptych . $quiet . $check . $hem
        . $info . $chart . $original . $close
        . '</div>';
};

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

$enLeakMarkers = ['Design wellspring', 'Original craft', 'On the garment', 'At a glance', 'Hand feel', 'Close looking', 'Worth noting'];

$bakedBan = [
    '74685f0c-fd8d-4f03-8605-9787613d894b', // detail-02 字板
    '43ec7041-87b5-4eb0-a698-63145f51cd01', // detail-04 烤字
    '91c5679f-84ab-4e34-b0b9-6bb0f8a124c2', // detail-05 烤字
    '03a0c342-3780-46d1-9918-2612b43a59be', // detail-06 信息板
];

$writes = [];
foreach ($localePlan as $locale => $baseKey) {
    if (!isset($copy[$baseKey])) {
        fwrite(STDERR, "Missing locale pack: {$baseKey}\n");
        exit(2);
    }
    $html = $assemble($copy[$baseKey]);
    $banned = ['一件代发', '混批', '厂家直销', '旺旺', '货源', '阿里巴巴', '淘宝', '天猫', '拼多多', 'dropship', 'Dropship', '上家', '设计灵感'];
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
    foreach ($bakedBan as $baked) {
        if (str_contains($html, $baked)) {
            fwrite(STDERR, "Baked asset leaked into {$locale}: {$baked}\n");
            exit(2);
        }
    }
    if ($baseKey !== 'en_US') {
        foreach ($enLeakMarkers as $marker) {
            if (str_contains($html, $marker)) {
                fwrite(STDERR, "EN dump into {$locale}: {$marker}\n");
                exit(2);
            }
        }
    }
    $featureCount = preg_match_all('/class="weline-detail-feature(?: weline-detail-feature--reverse)?"/', $html) ?: 0;
    if ($featureCount > 2) {
        fwrite(STDERR, "feature_lr budget exceeded in {$locale}: {$featureCount}\n");
        exit(2);
    }
    $writes[] = [
        'locale' => $locale,
        'html' => $html,
        'len' => strlen($html),
        'features' => $featureCount,
        'imgs' => substr_count($html, '<img '),
        'triptych' => substr_count($html, 'weline-detail-figure-row--triptych'),
        'fullbleed' => substr_count($html, 'weline-detail-figure-stack--fullbleed'),
    ];
}

foreach ($writes as $w) {
    echo sprintf(
        "%s\t%d\tfeature=%d\timgs=%d\ttriptych=%d\tfullbleed=%d\n",
        $w['locale'] === '' ? '(empty)' : $w['locale'],
        $w['len'],
        $w['features'],
        $w['imgs'],
        $w['triptych'],
        $w['fullbleed'],
    );
}

if (!$apply) {
    echo "Dry-run. Pass --apply to write description only.\n";
    echo "Prototype sequence: fullbleed_hero → editorial_prose×2 → pair_gallery → stack_caption → macro_annotate → feature_lr#1 → triptych → quiet_spacer → checklist_trust → feature_lr#2 → spec_panel → size_chart → editorial_prose → fullbleed_hero(close)\n";
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
    'detail_suite_variety_189',
    ['product_ids' => [$productId]],
);
ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class)
    ->clearForCatalogChange('detail_suite_variety_189');

echo "Applied description-only writes: " . count($writes) . " locales.\n";
