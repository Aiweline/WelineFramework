<?php

declare(strict_types=1);

/**
 * #188 红染 · ecommerce-detail-suite（主图+规格+详情）
 *
 * 卖点表：
 * | 卖点 | 证据 | 原型 |
 * | 花嫁正红 | 全身/半身红衣 | fullbleed |
 * | 立领绣花 | 领口金白绣 | stack |
 * | 云肩珍珠 | 云肩+珍珠串 | stack |
 * | 重工蝶花绣 | detail-01/08 | macro |
 * | 马面重工下摆 | 坐姿/全身 | pair/stack |
 * | 囍字婚庆场景 | vanity 真横 | feature_lr×1 |
 *
 * 原型：editorial → fullbleed → editorial → pair → stack_caption → macro → feature_lr×1
 *       → quiet → checklist → pair → stack → pair → quiet → wash → spec → size → original → fullbleed
 * detail-02 拼版烤字不入 HTML。
 *
 * php app/code/Weline/Product/scripts/beautify-product-188-detail-layout.php --apply
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
$productId = 188;

$A = [
    'hero' => ['id' => '55e19edf-c882-45ef-9fb5-f8ab7db9ee2e', 'w' => 1024, 'h' => 1024],
    'look02' => ['id' => 'ae1a96f7-0c84-4f08-bb3f-9b9775319d23', 'w' => 1024, 'h' => 1024],
    'look03' => ['id' => 'b4dcffeb-1d4c-4c07-96d0-3ae45308f03b', 'w' => 1024, 'h' => 1024],
    'look04' => ['id' => '659b5838-5783-4053-9818-415d97dba7db', 'w' => 1024, 'h' => 1024],
    'look05' => ['id' => '7e12c326-a93d-4eab-a93e-a269f88c2886', 'w' => 1024, 'h' => 1024],
    'sit' => ['id' => 'a1004b6b-4dd3-4e2c-bd13-4187eb1f0d42', 'w' => 1024, 'h' => 1024],
    'macro' => ['id' => '5e8512df-15c9-470f-a104-4eb1b55920ea', 'w' => 750, 'h' => 495],
    'full03' => ['id' => '3d089508-be5e-4c03-a4c9-a1326be3b6dd', 'w' => 465, 'h' => 861],
    'veil' => ['id' => '530860d8-73d2-4f4a-bb97-7c8c52141fa5', 'w' => 750, 'h' => 997],
    'portrait' => ['id' => 'bc2162d4-edf5-49a6-9ab8-2777c004c7cd', 'w' => 1024, 'h' => 1024],
    'look06' => ['id' => 'ed5f8b65-8aac-4589-8e20-be00904456e1', 'w' => 750, 'h' => 1182],
    'half' => ['id' => 'd0221ce9-f604-4992-abbb-bb5466e41c98', 'w' => 750, 'h' => 1184],
    'detail08' => ['id' => '5bece2e2-d29a-4b5c-bef2-804c8a3b8b2b', 'w' => 750, 'h' => 1008],
    'look09' => ['id' => 'ccc7758c-d6ab-45c3-a5cf-1861f3728e3d', 'w' => 750, 'h' => 1191],
    'look10' => ['id' => '23e26e18-c0ed-49dc-901d-ac799941334a', 'w' => 750, 'h' => 1194],
    'vanity' => ['id' => '52978184-fbd7-426a-99b5-910e8ce0549b', 'w' => 736, 'h' => 502],
    'close' => ['id' => 'ea297bc8-2be8-42f4-9d91-dfecb68e3186', 'w' => 750, 'h' => 1176],
];

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$img = static function (array $a, string $alt) use ($h): string {
    return '<img src="asset://' . $h((string)$a['id']) . '" alt="' . $h($alt)
        . '" loading="lazy" decoding="async" width="' . (int)$a['w'] . '" height="' . (int)$a['h'] . '">';
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

$copy = [
    'zh_Hans_CN' => [
        'intro_title' => '红染',
        'intro_body' => '明制花嫁套装：立领绣花上衣、重工云肩与马面裙相叠。正红为主色，蝶花绣与珍珠流苏可见；囍字婚庆棚景烘托仪式感。',
        'inspire_title' => '设计心源',
        'inspire_lines' => ['红染衣冠，愿与君远行。', '披星戴月，不辞万里。'],
        'inspire_note' => '取「红染」为题眼——仅为形制与喜庆气韵之点题，非平台货盘说辞。',
        'collar_title' => '立领与云肩',
        'collar_body' => '立领金绣清晰可读；云肩分层铺陈，珍珠串随步轻晃，领线与肩线一并拉长。',
        'macro_title' => '细处可辨',
        'macro_label' => '蝶花重工绣',
        'macro_body' => '门襟与袖幅可见牡丹、蝴蝶与金线锁边；线脚齐整，近观层次分明。',
        'macro2_label' => '面料与图案',
        'macro2_body' => '红底饱和、绣面浮凸；原创形制与蝶花纹样相映，不为货盘字板所扰。',
        'scene_title' => '囍字婚庆场景',
        'scene_body' => '妆台坐姿与落地灯「囍」字、红玫瑰同框，婚礼仪式感一目了然。',
        'look_title' => '通身气韵',
        'look_body' => '盖头凤冠与广袖马面同亮；走动时裙摆密绣随褶起伏，衣长可读。',
        'sit_title' => '云肩与裙摆',
        'sit_body' => '坐姿铺开马面下摆，重工绣带环环相扣；云肩白金层次托出正红主色。',
        'quiet_line' => '红在衣上，喜在灯下。',
        'checklist_title' => '衣袂可记',
        'checklist' => ['立领金绣与云肩珍珠可见', '蝶花重工绣于门襟袖幅', '马面下摆密绣层次清晰', '囍字婚庆棚景可辨', '尺码 S–2XL 可选'],
        'wash_title' => '护衣小笺',
        'wash_lines' => ['建议手洗，分色洗涤，不可漂白。', '悬挂晾干，避暴晒；低温熨烫，垫布为佳。'],
        'info_brand' => '花朝记',
        'info_name' => '红染',
        'info_color' => '正红',
        'info_style' => '明制',
        'info_size' => 'S–2XL',
        'info_fabric' => '精选面料（如图）',
        'info_parts' => '立领上衣、云肩、马面裙',
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
        'size_body' => '提供 S、M、L、XL、2XL。请按自身胸围与身高挑选；手工测量或有一至三厘米出入。具体以收到实物为准。',
        'original_title' => '原创心迹',
        'original_body' => '本款形制与纹样为花朝记原创设计，敬请珍惜衣冠、尊重匠心。',
        'close_caption' => '红染 · 明制花嫁',
        'alt_hero' => '红染 · 套装',
        'alt_look' => '红染 · 着装',
        'alt_macro' => '红染 · 细部',
        'alt_scene' => '红染 · 妆台',
        'alt_close' => '红染 · 合影',
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
    ],
    'en_US' => [
        'intro_title' => 'Hongran',
        'intro_body' => 'A Ming-style bridal set: stand-collar embroidered top, layered yunjian, and mamian skirt in saturated red. Butterfly-and-peony stitches and pearl strands read clearly; a Double-Happiness set frames the ceremony.',
        'inspire_title' => 'Design wellspring',
        'inspire_lines' => ['Crimson on the robe; far roads with you.', 'By starlight and moonlight, a thousand miles.'],
        'inspire_note' => 'Named “Hongran”—a motif for cut and festive air, not marketplace pitch.',
        'collar_title' => 'Stand collar & yunjian',
        'collar_body' => 'Gold stitches on the stand collar stay readable; the yunjian layers with pearl strands that sway lightly and lengthen the neck and shoulder line.',
        'macro_title' => 'Close looking',
        'macro_label' => 'Heavy butterfly-peony embroidery',
        'macro_body' => 'Peonies, butterflies, and gold-edged borders on the placket and sleeves; neat stitches with clear layers up close.',
        'macro2_label' => 'Fabric & pattern',
        'macro2_body' => 'Saturated red ground with raised embroidery; original cut and butterfly-peony motifs—without wholesale caption boards.',
        'scene_title' => 'Double-Happiness bridal set',
        'scene_body' => 'Vanity seating with a floor lamp marked 囍 and red roses makes the wedding mood unmistakable.',
        'look_title' => 'Full look',
        'look_body' => 'Veil, crown, wide sleeves, and mamian glow together; dense hem embroidery moves with the pleats; garment length stays readable.',
        'sit_title' => 'Yunjian & hem',
        'sit_body' => 'Seated, the mamian hem fans out in ringed embroidered bands; white-gold yunjian layers lift the crimson.',
        'quiet_line' => 'Red on the robe; joy under the lamp.',
        'checklist_title' => 'Worth noting',
        'checklist' => ['Gold stand-collar stitch and pearl yunjian', 'Heavy butterfly-peony work on placket and sleeves', 'Dense mamian hem layers', 'Bridal Double-Happiness set visible', 'Sizes S–2XL'],
        'wash_title' => 'Care',
        'wash_lines' => ['Hand wash separately; no bleach.', 'Hang dry, avoid hard sun; low heat with a press cloth.'],
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Hongran',
        'info_color' => 'Crimson',
        'info_style' => 'Ming style',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Selected fabric (as shown)',
        'info_parts' => 'Stand-collar top, yunjian, mamian skirt',
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
        'size_body' => 'Available in S, M, L, XL, and 2XL. Choose by bust and height; hand measure may vary by 1–3 cm. Confirm against the garment you receive.',
        'original_title' => 'Original craft',
        'original_body' => 'Cut and motifs are Huazhaoji originals—please honor the craft.',
        'close_caption' => 'Hongran · Ming bridal',
        'alt_hero' => 'Hongran · set',
        'alt_look' => 'Hongran · worn',
        'alt_macro' => 'Hongran · detail',
        'alt_scene' => 'Hongran · vanity',
        'alt_close' => 'Hongran · close',
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
    ],
    'es_ES' => [
        'intro_title' => 'Hongran',
        'intro_body' => 'Conjunto nupcial estilo Ming: top de cuello alto bordado, yunjian en capas y falda mamian en rojo intenso. Bordados de peonía y mariposa y perlas se leen con claridad; el decorado de Doble Felicidad enmarca la ceremonia.',
        'inspire_title' => 'Fuente del diseño',
        'inspire_lines' => ['Carmesí en el traje; lejos contigo.', 'A la luz de estrellas y luna, mil millas.'],
        'inspire_note' => 'Llamado «Hongran»: motivo de corte y aire festivo, no discurso de mercado.',
        'collar_title' => 'Cuello alto y yunjian',
        'collar_body' => 'Puntadas doradas legibles en el cuello; el yunjian en capas con perlas alarga cuello y hombros.',
        'macro_title' => 'De cerca',
        'macro_label' => 'Bordado pesado peonía-mariposa',
        'macro_body' => 'Peonías, mariposas y bordes dorados en delantera y mangas; puntadas ordenadas.',
        'macro2_label' => 'Tela y patrón',
        'macro2_body' => 'Rojo saturado con bordado en relieve; corte original sin carteles de mayorista.',
        'scene_title' => 'Escena nupcial 囍',
        'scene_body' => 'Tocador con lámpara 囍 y rosas rojas deja clara la atmósfera de boda.',
        'look_title' => 'Silueta completa',
        'look_body' => 'Velo, corona, mangas amplias y mamian brillan juntos; el ruedo bordado sigue los pliegues.',
        'sit_title' => 'Yunjian y ruedo',
        'sit_body' => 'Sentada, el ruedo mamian se abre en franjas bordadas; el yunjian blanco-dorado eleva el carmesí.',
        'quiet_line' => 'Rojo en el traje; alegría bajo la lámpara.',
        'checklist_title' => 'Para recordar',
        'checklist' => ['Cuello dorado y yunjian de perlas', 'Bordado pesado en delantera y mangas', 'Capas densas en el ruedo mamian', 'Decorado nupcial 囍 visible', 'Tallas S–2XL'],
        'wash_title' => 'Cuidado',
        'wash_lines' => ['Lavar a mano por separado; sin lejía.', 'Secar colgado, sin sol fuerte; plancha baja con paño.'],
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Hongran',
        'info_color' => 'Carmesí',
        'info_style' => 'Estilo Ming',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Tela seleccionada (como en foto)',
        'info_parts' => 'Top de cuello alto, yunjian, falda mamian',
        'info_title' => 'De un vistazo',
        'info_basics' => 'Básico',
        'info_comfort' => 'Tacto',
        'label_brand' => 'Marca',
        'label_name' => 'Nombre',
        'label_color' => 'Color',
        'label_style' => 'Estilo',
        'label_size' => 'Talla',
        'label_fabric' => 'Tela',
        'label_parts' => 'Partes',
        'size_title' => 'Guía de tallas',
        'size_body' => 'Disponible en S, M, L, XL y 2XL. Elija por busto y altura; la medida manual puede variar 1–3 cm.',
        'original_title' => 'Oficio original',
        'original_body' => 'Corte y motivos son originales de Huazhaoji—honre el oficio.',
        'close_caption' => 'Hongran · nupcial Ming',
        'alt_hero' => 'Hongran · conjunto',
        'alt_look' => 'Hongran · puesto',
        'alt_macro' => 'Hongran · detalle',
        'alt_scene' => 'Hongran · tocador',
        'alt_close' => 'Hongran · cierre',
        'c_thick' => 'Grosor',
        'c_thick_opts' => ['Fino', 'Medio', 'Grueso'],
        'c_thick_sel' => 'Medio',
        'c_fit' => 'Corte',
        'c_fit_opts' => ['Ajustado', 'Regular', 'Holgado'],
        'c_fit_sel' => 'Regular',
        'c_soft' => 'Tacto',
        'c_soft_opts' => ['Más suave', 'Medio', 'Más firme'],
        'c_soft_sel' => 'Medio',
        'c_stretch' => 'Elasticidad',
        'c_stretch_opts' => ['Nula', 'Ligera', 'Alta'],
        'c_stretch_sel' => 'Nula',
    ],
    'fr_FR' => [
        'intro_title' => 'Hongran',
        'intro_body' => 'Ensemble nuptial style Ming : haut à col montant brodé, yunjian superposé et jupe mamian rouge saturé. Broderies pivoine-papillon et perles lisibles ; le décor Double Bonheur cadre la cérémonie.',
        'inspire_title' => 'Source du dessin',
        'inspire_lines' => ['Cramoisi sur la robe ; loin avec toi.', 'Sous les étoiles et la lune, mille lieues.'],
        'inspire_note' => 'Nommé « Hongran » — motif de coupe et d’air festif, pas un discours de marché.',
        'collar_title' => 'Col montant et yunjian',
        'collar_body' => 'Points d’or lisibles sur le col ; yunjian en couches avec perles qui allongent cou et épaules.',
        'macro_title' => 'De près',
        'macro_label' => 'Broderie lourde pivoine-papillon',
        'macro_body' => 'Pivoines, papillons et bordures dorées sur plastron et manches ; points nets.',
        'macro2_label' => 'Tissu et motif',
        'macro2_body' => 'Rouge saturé, broderie en relief ; coupe originale sans pancartes grossistes.',
        'scene_title' => 'Scène nuptiale 囍',
        'scene_body' => 'Coiffeuse avec lampe 囍 et roses rouges rend l’ambiance de mariage évidente.',
        'look_title' => 'Silhouette entière',
        'look_body' => 'Voile, couronne, larges manches et mamian brillent ensemble ; l’ourlet brodé suit les plis.',
        'sit_title' => 'Yunjian et ourlet',
        'sit_body' => 'Assise, l’ourlet mamian s’ouvre en bandes brodées ; le yunjian blanc-or relève le cramoisi.',
        'quiet_line' => 'Rouge sur la robe ; joie sous la lampe.',
        'checklist_title' => 'À retenir',
        'checklist' => ['Col doré et yunjian de perles', 'Broderie lourde sur plastron et manches', 'Couches denses sur l’ourlet mamian', 'Décor nuptial 囍 visible', 'Tailles S–2XL'],
        'wash_title' => 'Entretien',
        'wash_lines' => ['Lavage à la main séparé ; pas d’eau de Javel.', 'Séchage suspendu, sans soleil dur ; fer doux avec pattemouille.'],
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Hongran',
        'info_color' => 'Cramoisi',
        'info_style' => 'Style Ming',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Tissu sélectionné (comme sur photo)',
        'info_parts' => 'Haut col montant, yunjian, jupe mamian',
        'info_title' => 'En un coup d’œil',
        'info_basics' => 'Bases',
        'info_comfort' => 'Toucher',
        'label_brand' => 'Marque',
        'label_name' => 'Nom',
        'label_color' => 'Couleur',
        'label_style' => 'Style',
        'label_size' => 'Taille',
        'label_fabric' => 'Tissu',
        'label_parts' => 'Pièces',
        'size_title' => 'Guide des tailles',
        'size_body' => 'Disponible en S, M, L, XL et 2XL. Choisir selon buste et taille ; mesure manuelle ±1–3 cm.',
        'original_title' => 'Savoir-faire original',
        'original_body' => 'Coupe et motifs sont originaux Huazhaoji — honorez le métier.',
        'close_caption' => 'Hongran · nuptial Ming',
        'alt_hero' => 'Hongran · ensemble',
        'alt_look' => 'Hongran · porté',
        'alt_macro' => 'Hongran · détail',
        'alt_scene' => 'Hongran · coiffeuse',
        'alt_close' => 'Hongran · clôture',
        'c_thick' => 'Épaisseur',
        'c_thick_opts' => ['Fin', 'Moyen', 'Épais'],
        'c_thick_sel' => 'Moyen',
        'c_fit' => 'Coupe',
        'c_fit_opts' => ['Ajusté', 'Droit', 'Large'],
        'c_fit_sel' => 'Droit',
        'c_soft' => 'Toucher',
        'c_soft_opts' => ['Plus doux', 'Moyen', 'Plus ferme'],
        'c_soft_sel' => 'Moyen',
        'c_stretch' => 'Élasticité',
        'c_stretch_opts' => ['Aucune', 'Légère', 'Forte'],
        'c_stretch_sel' => 'Aucune',
    ],
    'pt_BR' => [
        'intro_title' => 'Hongran',
        'intro_body' => 'Conjunto nupcial estilo Ming: top de gola alta bordada, yunjian em camadas e saia mamian vermelha. Bordados de peônia e borboleta e pérolas legíveis; cenário de Dupla Felicidade.',
        'inspire_title' => 'Fonte do desenho',
        'inspire_lines' => ['Carmesim na roupa; longe com você.', 'Sob estrelas e lua, mil milhas.'],
        'inspire_note' => 'Chamado «Hongran» — motivo de corte e ar festivo, não discurso de mercado.',
        'collar_title' => 'Stand collar & yunjian',
        'collar_body' => 'Gold stitches on the stand collar stay readable; the yunjian layers with pearl strands that sway lightly and lengthen the neck and shoulder line.',
        'macro_title' => 'Close looking',
        'macro_label' => 'Heavy butterfly-peony embroidery',
        'macro_body' => 'Peonies, butterflies, and gold-edged borders on the placket and sleeves; neat stitches with clear layers up close.',
        'macro2_label' => 'Fabric & pattern',
        'macro2_body' => 'Saturated red ground with raised embroidery; original cut and butterfly-peony motifs—without wholesale caption boards.',
        'scene_title' => 'Double-Happiness bridal set',
        'scene_body' => 'Vanity seating with a floor lamp marked 囍 and red roses makes the wedding mood unmistakable.',
        'look_title' => 'Full look',
        'look_body' => 'Veil, crown, wide sleeves, and mamian glow together; dense hem embroidery moves with the pleats; garment length stays readable.',
        'sit_title' => 'Yunjian & hem',
        'sit_body' => 'Seated, the mamian hem fans out in ringed embroidered bands; white-gold yunjian layers lift the crimson.',
        'quiet_line' => 'Red on the robe; joy under the lamp.',
        'checklist_title' => 'Worth noting',
        'checklist' => ['Gold stand-collar stitch and pearl yunjian', 'Heavy butterfly-peony work on placket and sleeves', 'Dense mamian hem layers', 'Bridal Double-Happiness set visible', 'Sizes S–2XL'],
        'wash_title' => 'Care',
        'wash_lines' => ['Hand wash separately; no bleach.', 'Hang dry, avoid hard sun; low heat with a press cloth.'],
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Hongran',
        'info_color' => 'Crimson',
        'info_style' => 'Ming style',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Selected fabric (as shown)',
        'info_parts' => 'Stand-collar top, yunjian, mamian skirt',
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
        'size_body' => 'Available in S, M, L, XL, and 2XL. Choose by bust and height; hand measure may vary by 1–3 cm. Confirm against the garment you receive.',
        'original_title' => 'Original craft',
        'original_body' => 'Cut and motifs are Huazhaoji originals—please honor the craft.',
        'close_caption' => 'Hongran · Ming bridal',
        'alt_hero' => 'Hongran · set',
        'alt_look' => 'Hongran · worn',
        'alt_macro' => 'Hongran · detail',
        'alt_scene' => 'Hongran · vanity',
        'alt_close' => 'Hongran · close',
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
    ],
    'id_ID' => [
        'intro_title' => 'Hongran',
        'intro_body' => 'Set pengantin gaya Ming: atasan kerah tinggi bersulam, yunjian berlapis, dan rok mamian merah. Sulaman peoni-kupu serta mutiara terbaca jelas; set Double Happiness.',
        'inspire_title' => 'Sumber desain',
        'inspire_lines' => ['Merah pada jubah; jauh bersamamu.', 'Di bawah bintang dan bulan, seribu mil.'],
        'inspire_note' => 'Bernama «Hongran» — motif potongan dan suasana pesta, bukan jargon pasar.',
        'collar_title' => 'Stand collar & yunjian',
        'collar_body' => 'Gold stitches on the stand collar stay readable; the yunjian layers with pearl strands that sway lightly and lengthen the neck and shoulder line.',
        'macro_title' => 'Close looking',
        'macro_label' => 'Heavy butterfly-peony embroidery',
        'macro_body' => 'Peonies, butterflies, and gold-edged borders on the placket and sleeves; neat stitches with clear layers up close.',
        'macro2_label' => 'Fabric & pattern',
        'macro2_body' => 'Saturated red ground with raised embroidery; original cut and butterfly-peony motifs—without wholesale caption boards.',
        'scene_title' => 'Double-Happiness bridal set',
        'scene_body' => 'Vanity seating with a floor lamp marked 囍 and red roses makes the wedding mood unmistakable.',
        'look_title' => 'Full look',
        'look_body' => 'Veil, crown, wide sleeves, and mamian glow together; dense hem embroidery moves with the pleats; garment length stays readable.',
        'sit_title' => 'Yunjian & hem',
        'sit_body' => 'Seated, the mamian hem fans out in ringed embroidered bands; white-gold yunjian layers lift the crimson.',
        'quiet_line' => 'Red on the robe; joy under the lamp.',
        'checklist_title' => 'Worth noting',
        'checklist' => ['Gold stand-collar stitch and pearl yunjian', 'Heavy butterfly-peony work on placket and sleeves', 'Dense mamian hem layers', 'Bridal Double-Happiness set visible', 'Sizes S–2XL'],
        'wash_title' => 'Care',
        'wash_lines' => ['Hand wash separately; no bleach.', 'Hang dry, avoid hard sun; low heat with a press cloth.'],
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Hongran',
        'info_color' => 'Crimson',
        'info_style' => 'Ming style',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Selected fabric (as shown)',
        'info_parts' => 'Stand-collar top, yunjian, mamian skirt',
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
        'size_body' => 'Available in S, M, L, XL, and 2XL. Choose by bust and height; hand measure may vary by 1–3 cm. Confirm against the garment you receive.',
        'original_title' => 'Original craft',
        'original_body' => 'Cut and motifs are Huazhaoji originals—please honor the craft.',
        'close_caption' => 'Hongran · Ming bridal',
        'alt_hero' => 'Hongran · set',
        'alt_look' => 'Hongran · worn',
        'alt_macro' => 'Hongran · detail',
        'alt_scene' => 'Hongran · vanity',
        'alt_close' => 'Hongran · close',
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
    ],
    'ar_SA' => [
        'intro_title' => 'هونغران',
        'intro_body' => 'طقم زفاف على طراز مينغ: قميص ياقة واقفة مطرزة وكتف سحابي وطية ماميان حمراء. تطريز الفاوانيا والفراشة واللؤلؤ واضح؛ ديكور السعادة المزدوجة.',
        'inspire_title' => 'منبع التصميم',
        'inspire_lines' => ['قرمزي على الثوب؛ بعيدًا معك.', 'تحت النجوم والقمر ألف ميل.'],
        'inspire_note' => 'اسم «هونغران» لشكل وبهجة الاحتفال، لا لخطاب السوق.',
        'collar_title' => 'الياقة والكتف السحابي',
        'collar_body' => 'تطريز ذهبي واضح على الياقة؛ كتف سحابي بطبقات ولآلئ تطيل العنق والكتف.',
        'macro_title' => 'عن قرب',
        'macro_label' => 'تطريز ثقيل',
        'macro_body' => 'فاوانيا وفراشات وحواف ذهبية على الصدر والأكمام.',
        'macro2_label' => 'القماش والنقش',
        'macro2_body' => 'أحمر مشبع وتطريز بارز؛ قص أصلي بلا لوحات جملة.',
        'scene_title' => 'مشهد الزفاف 囍',
        'scene_body' => 'طاولة زينة ومصباح 囍 وورود حمراء توضح أجواء الزفاف.',
        'look_title' => 'الإطلالة كاملة',
        'look_body' => 'غطاء رأس وتاج وأكمام واسعة وماميان معًا؛ تطريز الذيل يتبع الطيات.',
        'sit_title' => 'الكتف السحابي والذيل',
        'sit_body' => 'جلوسًا ينفرج ذيل الماميان بشرائط مطرزة.',
        'quiet_line' => 'أحمر على الثوب؛ فرح تحت المصباح.',
        'checklist_title' => 'جدير بالذكر',
        'checklist' => ['تطريز ياقة ذهبية ولآلئ', 'تطريز ثقيل على الصدر والأكمام', 'طبقات كثيفة على ذيل الماميان', 'ديكور زفاف 囍 ظاهر', 'المقاسات S–2XL'],
        'wash_title' => 'العناية',
        'wash_lines' => ['غسل يدوي منفصل؛ بلا مبيض.', 'تجفيف معلق بعيدًا عن الشمس القاسية.'],
        'info_brand' => 'Huazhaoji',
        'info_name' => 'هونغران',
        'info_color' => 'قرمزي',
        'info_style' => 'أسلوب مينغ',
        'info_size' => 'S–2XL',
        'info_fabric' => 'قماش مختار',
        'info_parts' => 'قميص ياقة، كتف سحابي، تنورة ماميان',
        'info_title' => 'لمحة',
        'info_basics' => 'أساسي',
        'info_comfort' => 'الملمس',
        'label_brand' => 'العلامة',
        'label_name' => 'الاسم',
        'label_color' => 'اللون',
        'label_style' => 'الأسلوب',
        'label_size' => 'المقاس',
        'label_fabric' => 'القماش',
        'label_parts' => 'الأجزاء',
        'size_title' => 'دليل المقاسات',
        'size_body' => 'المقاسات S–2XL. اختر حسب الصدر والطول؛ القياس اليدوي قد يختلف 1–3 سم.',
        'original_title' => 'حرفة أصلية',
        'original_body' => 'القص والزخارف أصلية لهواظاوجي—أكرم الحرفة.',
        'close_caption' => 'هونغران · زفاف مينغ',
        'alt_hero' => 'هونغران · الطقم',
        'alt_look' => 'هونغران · مرتدى',
        'alt_macro' => 'هونغران · تفصيل',
        'alt_scene' => 'هونغران · زينة',
        'alt_close' => 'هونغران · ختام',
        'c_thick' => 'السماكة',
        'c_thick_opts' => ['رفيع', 'متوسط', 'سميك'],
        'c_thick_sel' => 'متوسط',
        'c_fit' => 'القصة',
        'c_fit_opts' => ['ضيق', 'عادي', 'واسع'],
        'c_fit_sel' => 'عادي',
        'c_soft' => 'الملمس',
        'c_soft_opts' => ['أنعم', 'متوسط', 'أصلب'],
        'c_soft_sel' => 'متوسط',
        'c_stretch' => 'المرونة',
        'c_stretch_opts' => ['لا', 'خفيف', 'عالي'],
        'c_stretch_sel' => 'لا',
    ],
    'bn_BD' => [
        'intro_title' => 'হংরান',
        'intro_body' => 'মিং শৈলীর বিবাহ সেট: দাঁড়ানো কলার এমব্রয়ডারি টপ, স্তরীকৃত ইউনজিয়ান ও লাল মামিয়ান স্কার্ট।',
        'inspire_title' => 'ডিজাইনের উৎস',
        'inspire_lines' => ['পোশাকে লাল; তোমার সাথে দূরে।', 'তারায় চাঁদে হাজার মাইল।'],
        'inspire_note' => 'নাম «হংরান»—কাট ও উৎসবের ভাব, বাজারের বুলি নয়।',
        'collar_title' => 'Stand collar & yunjian',
        'collar_body' => 'Gold stitches on the stand collar stay readable; the yunjian layers with pearl strands that sway lightly and lengthen the neck and shoulder line.',
        'macro_title' => 'Close looking',
        'macro_label' => 'Heavy butterfly-peony embroidery',
        'macro_body' => 'Peonies, butterflies, and gold-edged borders on the placket and sleeves; neat stitches with clear layers up close.',
        'macro2_label' => 'Fabric & pattern',
        'macro2_body' => 'Saturated red ground with raised embroidery; original cut and butterfly-peony motifs—without wholesale caption boards.',
        'scene_title' => 'Double-Happiness bridal set',
        'scene_body' => 'Vanity seating with a floor lamp marked 囍 and red roses makes the wedding mood unmistakable.',
        'look_title' => 'Full look',
        'look_body' => 'Veil, crown, wide sleeves, and mamian glow together; dense hem embroidery moves with the pleats; garment length stays readable.',
        'sit_title' => 'Yunjian & hem',
        'sit_body' => 'Seated, the mamian hem fans out in ringed embroidered bands; white-gold yunjian layers lift the crimson.',
        'quiet_line' => 'Red on the robe; joy under the lamp.',
        'checklist_title' => 'Worth noting',
        'checklist' => ['Gold stand-collar stitch and pearl yunjian', 'Heavy butterfly-peony work on placket and sleeves', 'Dense mamian hem layers', 'Bridal Double-Happiness set visible', 'Sizes S–2XL'],
        'wash_title' => 'Care',
        'wash_lines' => ['Hand wash separately; no bleach.', 'Hang dry, avoid hard sun; low heat with a press cloth.'],
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Hongran',
        'info_color' => 'Crimson',
        'info_style' => 'Ming style',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Selected fabric (as shown)',
        'info_parts' => 'Stand-collar top, yunjian, mamian skirt',
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
        'size_body' => 'Available in S, M, L, XL, and 2XL. Choose by bust and height; hand measure may vary by 1–3 cm. Confirm against the garment you receive.',
        'original_title' => 'Original craft',
        'original_body' => 'Cut and motifs are Huazhaoji originals—please honor the craft.',
        'close_caption' => 'Hongran · Ming bridal',
        'alt_hero' => 'Hongran · set',
        'alt_look' => 'Hongran · worn',
        'alt_macro' => 'Hongran · detail',
        'alt_scene' => 'Hongran · vanity',
        'alt_close' => 'Hongran · close',
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
    ],
    'hi_IN' => [
        'intro_title' => 'होंगरान',
        'intro_body' => 'मिंग शैली का वैवाहिक सेट: स्टैंड कॉलर कढ़ाई टॉप, परतदार यूनजियान और लाल मामियान स्कर्ट।',
        'inspire_title' => 'डिज़ाइन स्रोत',
        'inspire_lines' => ['वस्त्र पर लाल; तुम्हारे संग दूर।', 'तारों-चाँद के नीचे हज़ार मील।'],
        'inspire_note' => 'नाम «होंगरान»—कट और उत्सव भाव; बाज़ार की भाषा नहीं।',
        'collar_title' => 'Stand collar & yunjian',
        'collar_body' => 'Gold stitches on the stand collar stay readable; the yunjian layers with pearl strands that sway lightly and lengthen the neck and shoulder line.',
        'macro_title' => 'Close looking',
        'macro_label' => 'Heavy butterfly-peony embroidery',
        'macro_body' => 'Peonies, butterflies, and gold-edged borders on the placket and sleeves; neat stitches with clear layers up close.',
        'macro2_label' => 'Fabric & pattern',
        'macro2_body' => 'Saturated red ground with raised embroidery; original cut and butterfly-peony motifs—without wholesale caption boards.',
        'scene_title' => 'Double-Happiness bridal set',
        'scene_body' => 'Vanity seating with a floor lamp marked 囍 and red roses makes the wedding mood unmistakable.',
        'look_title' => 'Full look',
        'look_body' => 'Veil, crown, wide sleeves, and mamian glow together; dense hem embroidery moves with the pleats; garment length stays readable.',
        'sit_title' => 'Yunjian & hem',
        'sit_body' => 'Seated, the mamian hem fans out in ringed embroidered bands; white-gold yunjian layers lift the crimson.',
        'quiet_line' => 'Red on the robe; joy under the lamp.',
        'checklist_title' => 'Worth noting',
        'checklist' => ['Gold stand-collar stitch and pearl yunjian', 'Heavy butterfly-peony work on placket and sleeves', 'Dense mamian hem layers', 'Bridal Double-Happiness set visible', 'Sizes S–2XL'],
        'wash_title' => 'Care',
        'wash_lines' => ['Hand wash separately; no bleach.', 'Hang dry, avoid hard sun; low heat with a press cloth.'],
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Hongran',
        'info_color' => 'Crimson',
        'info_style' => 'Ming style',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Selected fabric (as shown)',
        'info_parts' => 'Stand-collar top, yunjian, mamian skirt',
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
        'size_body' => 'Available in S, M, L, XL, and 2XL. Choose by bust and height; hand measure may vary by 1–3 cm. Confirm against the garment you receive.',
        'original_title' => 'Original craft',
        'original_body' => 'Cut and motifs are Huazhaoji originals—please honor the craft.',
        'close_caption' => 'Hongran · Ming bridal',
        'alt_hero' => 'Hongran · set',
        'alt_look' => 'Hongran · worn',
        'alt_macro' => 'Hongran · detail',
        'alt_scene' => 'Hongran · vanity',
        'alt_close' => 'Hongran · close',
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
    ],
    'ur_PK' => [
        'intro_title' => 'ہونگران',
        'intro_body' => 'منگ طرز کا دولہا/دلہن سیٹ: کھڑی کالر کڑھائی ٹاپ، تہہ دار یونجیان اور سرخ مامیان اسکرٹ۔',
        'inspire_title' => 'ڈیزائن کا منبع',
        'inspire_lines' => ['جامے پر سرخ؛ تمہارے ساتھ دور۔', 'ستاروں اور چاند کے نیچے ہزار میل۔'],
        'inspire_note' => 'نام «ہونگران»—کٹ اور تہوار کا جذبہ، بازاری زبان نہیں۔',
        'collar_title' => 'Stand collar & yunjian',
        'collar_body' => 'Gold stitches on the stand collar stay readable; the yunjian layers with pearl strands that sway lightly and lengthen the neck and shoulder line.',
        'macro_title' => 'Close looking',
        'macro_label' => 'Heavy butterfly-peony embroidery',
        'macro_body' => 'Peonies, butterflies, and gold-edged borders on the placket and sleeves; neat stitches with clear layers up close.',
        'macro2_label' => 'Fabric & pattern',
        'macro2_body' => 'Saturated red ground with raised embroidery; original cut and butterfly-peony motifs—without wholesale caption boards.',
        'scene_title' => 'Double-Happiness bridal set',
        'scene_body' => 'Vanity seating with a floor lamp marked 囍 and red roses makes the wedding mood unmistakable.',
        'look_title' => 'Full look',
        'look_body' => 'Veil, crown, wide sleeves, and mamian glow together; dense hem embroidery moves with the pleats; garment length stays readable.',
        'sit_title' => 'Yunjian & hem',
        'sit_body' => 'Seated, the mamian hem fans out in ringed embroidered bands; white-gold yunjian layers lift the crimson.',
        'quiet_line' => 'Red on the robe; joy under the lamp.',
        'checklist_title' => 'Worth noting',
        'checklist' => ['Gold stand-collar stitch and pearl yunjian', 'Heavy butterfly-peony work on placket and sleeves', 'Dense mamian hem layers', 'Bridal Double-Happiness set visible', 'Sizes S–2XL'],
        'wash_title' => 'Care',
        'wash_lines' => ['Hand wash separately; no bleach.', 'Hang dry, avoid hard sun; low heat with a press cloth.'],
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Hongran',
        'info_color' => 'Crimson',
        'info_style' => 'Ming style',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Selected fabric (as shown)',
        'info_parts' => 'Stand-collar top, yunjian, mamian skirt',
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
        'size_body' => 'Available in S, M, L, XL, and 2XL. Choose by bust and height; hand measure may vary by 1–3 cm. Confirm against the garment you receive.',
        'original_title' => 'Original craft',
        'original_body' => 'Cut and motifs are Huazhaoji originals—please honor the craft.',
        'close_caption' => 'Hongran · Ming bridal',
        'alt_hero' => 'Hongran · set',
        'alt_look' => 'Hongran · worn',
        'alt_macro' => 'Hongran · detail',
        'alt_scene' => 'Hongran · vanity',
        'alt_close' => 'Hongran · close',
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
    ],
];


// True-translate leftovers for locales built from en skeleton
$localeOverrides = [
    'pt_BR' => [
        'collar_title' => 'Gola alta e yunjian',
        'collar_body' => 'Pontos dourados legíveis na gola; yunjian em camadas com pérolas alonga pescoço e ombros.',
        'macro_title' => 'De perto',
        'macro_label' => 'Bordado pesado peônia-borboleta',
        'macro_body' => 'Peônias, borboletas e bordas douradas no peito e mangas; pontos limpos.',
        'macro2_label' => 'Tecido e padrão',
        'macro2_body' => 'Vermelho saturado com bordado em relevo; corte original sem cartazes de atacado.',
        'scene_title' => 'Cena nupcial 囍',
        'scene_body' => 'Penteadeira com luminária 囍 e rosas vermelhas deixa claro o clima de casamento.',
        'look_title' => 'Silhueta completa',
        'look_body' => 'Véu, coroa, mangas amplas e mamian brilham juntos; a barra bordada segue as pregas.',
        'sit_title' => 'Yunjian e barra',
        'sit_body' => 'Sentada, a barra mamian abre-se em faixas bordadas; o yunjian branco-dourado eleva o carmim.',
        'quiet_line' => 'Vermelho na roupa; alegria sob a lâmpada.',
        'checklist_title' => 'Vale lembrar',
        'checklist' => ['Gola dourada e yunjian de pérolas', 'Bordado pesado no peito e mangas', 'Camadas densas na barra mamian', 'Cenário nupcial 囍 visível', 'Tamanhos S–2XL'],
        'wash_title' => 'Cuidados',
        'wash_lines' => ['Lavar à mão separado; sem alvejante.', 'Secar pendurado, sem sol forte; ferro baixo com pano.'],
        'info_title' => 'Em resumo',
        'info_basics' => 'Básico',
        'info_comfort' => 'Toque',
        'label_brand' => 'Marca',
        'label_name' => 'Nome',
        'label_color' => 'Cor',
        'label_style' => 'Estilo',
        'label_size' => 'Tamanho',
        'label_fabric' => 'Tecido',
        'label_parts' => 'Peças',
        'info_color' => 'Carmim',
        'info_style' => 'Estilo Ming',
        'info_fabric' => 'Tecido selecionado (como na foto)',
        'info_parts' => 'Top gola alta, yunjian, saia mamian',
        'size_title' => 'Guia de tamanhos',
        'size_body' => 'Disponível em S, M, L, XL e 2XL. Escolha por busto e altura; medida manual pode variar 1–3 cm.',
        'original_title' => 'Ofício original',
        'original_body' => 'Corte e motivos são originais Huazhaoji — honre o ofício.',
        'close_caption' => 'Hongran · nupcial Ming',
        'alt_hero' => 'Hongran · conjunto',
        'alt_look' => 'Hongran · vestido',
        'alt_macro' => 'Hongran · detalhe',
        'alt_scene' => 'Hongran · penteadeira',
        'alt_close' => 'Hongran · fechamento',
        'c_thick' => 'Espessura',
        'c_thick_opts' => ['Fino', 'Médio', 'Grosso'],
        'c_thick_sel' => 'Médio',
        'c_fit' => 'Caimento',
        'c_fit_opts' => ['Ajustado', 'Regular', 'Folgado'],
        'c_fit_sel' => 'Regular',
        'c_soft' => 'Toque',
        'c_soft_opts' => ['Mais macio', 'Médio', 'Mais firme'],
        'c_soft_sel' => 'Médio',
        'c_stretch' => 'Elasticidade',
        'c_stretch_opts' => ['Nenhuma', 'Leve', 'Alta'],
        'c_stretch_sel' => 'Nenhuma',
    ],
    'id_ID' => [
        'collar_title' => 'Kerah tinggi & yunjian',
        'collar_body' => 'Sulaman emas terbaca di kerah; yunjian berlapis dengan mutiara memanjangkan leher dan bahu.',
        'macro_title' => 'Dari dekat',
        'macro_label' => 'Sulaman peoni-kupu berat',
        'macro_body' => 'Peoni, kupu-kupu, dan tepi emas di dada serta lengan; jahitan rapi.',
        'macro2_label' => 'Kain & motif',
        'macro2_body' => 'Merah pekat dengan sulaman timbul; potongan orisinal tanpa papan grosir.',
        'scene_title' => 'Adegan pengantin 囍',
        'scene_body' => 'Meja rias dengan lampu 囍 dan mawar merah memperjelas suasana pernikahan.',
        'look_title' => 'Siluet penuh',
        'look_body' => 'Kerudung, mahkota, lengan lebar, dan mamian bersinar bersama; sulaman hem mengikuti lipatan.',
        'sit_title' => 'Yunjian & hem',
        'sit_body' => 'Duduk, hem mamian membuka dalam pita bersulam; yunjian putih-emas mengangkat merah.',
        'quiet_line' => 'Merah di jubah; sukacita di bawah lampu.',
        'checklist_title' => 'Perlu diingat',
        'checklist' => ['Kerah emas dan yunjian mutiara', 'Sulaman berat di dada dan lengan', 'Lapisan padat pada hem mamian', 'Adegan pengantin 囍 terlihat', 'Ukuran S–2XL'],
        'wash_title' => 'Perawatan',
        'wash_lines' => ['Cuci tangan terpisah; tanpa pemutih.', 'Keringkan digantung, hindari matahari keras; setrika rendah dengan kain.'],
        'info_title' => 'Sekilas',
        'info_basics' => 'Dasar',
        'info_comfort' => 'Rasa tangan',
        'label_brand' => 'Merek',
        'label_name' => 'Nama',
        'label_color' => 'Warna',
        'label_style' => 'Gaya',
        'label_size' => 'Ukuran',
        'label_fabric' => 'Kain',
        'label_parts' => 'Bagian',
        'info_color' => 'Merah tua',
        'info_style' => 'Gaya Ming',
        'info_fabric' => 'Kain pilihan (seperti di foto)',
        'info_parts' => 'Atasan kerah tinggi, yunjian, rok mamian',
        'size_title' => 'Panduan ukuran',
        'size_body' => 'Tersedia S, M, L, XL, dan 2XL. Pilih menurut lingkar dada dan tinggi; ukur tangan bisa beda 1–3 cm.',
        'original_title' => 'Kerajinan orisinal',
        'original_body' => 'Potongan dan motif milik Huazhaoji — hormati kerajinan.',
        'close_caption' => 'Hongran · pengantin Ming',
        'alt_hero' => 'Hongran · set',
        'alt_look' => 'Hongran · dikenakan',
        'alt_macro' => 'Hongran · detail',
        'alt_scene' => 'Hongran · meja rias',
        'alt_close' => 'Hongran · penutup',
        'c_thick' => 'Ketebalan',
        'c_thick_opts' => ['Tipis', 'Sedang', 'Tebal'],
        'c_thick_sel' => 'Sedang',
        'c_fit' => 'Potongan',
        'c_fit_opts' => ['Ketat', 'Reguler', 'Longgar'],
        'c_fit_sel' => 'Reguler',
        'c_soft' => 'Rasa',
        'c_soft_opts' => ['Lebih lembut', 'Sedang', 'Lebih kaku'],
        'c_soft_sel' => 'Sedang',
        'c_stretch' => 'Elastisitas',
        'c_stretch_opts' => ['Tidak', 'Sedikit', 'Tinggi'],
        'c_stretch_sel' => 'Tidak',
    ],
    'bn_BD' => [
        'collar_title' => 'দাঁড়ানো কলার ও ইউনজিয়ান',
        'collar_body' => 'কলারে সোনালি সেলাই স্পষ্ট; ইউনজিয়ান ও মুক্তো গলা-কাঁধ লম্বা করে।',
        'macro_title' => 'কাছ থেকে',
        'macro_label' => 'ভারী প্রজাপতি-পদ্ম সূচিকর্ম',
        'macro_body' => 'বুক ও হাতায় পদ্ম, প্রজাপতি ও সোনালি কিনারা।',
        'macro2_label' => 'কাপড় ও নকশা',
        'macro2_body' => 'ঘন লাল ও উঁচু সূচিকর্ম; আসল কাট, পাইকারি বোর্ড নয়।',
        'scene_title' => 'বিবাহ দৃশ্য 囍',
        'scene_body' => 'মেকআপ টেবিল, 囍 বাতি ও লাল গোলাপ বিয়ের মেজাজ স্পষ্ট করে।',
        'look_title' => 'পূর্ণ সাজ',
        'look_body' => 'ওড়না, মুকুট, চওড়া হাতা ও মামিয়ান একসাথে; হেমের সূচিকর্ম ভাঁজ অনুসরণ করে।',
        'sit_title' => 'ইউনজিয়ান ও হেম',
        'sit_body' => 'বসে মামিয়ান হেম খুলে যায় সূচিকর্ম ফিতেয়।',
        'quiet_line' => 'পোশাকে লাল; বাতির নিচে আনন্দ।',
        'checklist_title' => 'মনে রাখার মতো',
        'checklist' => ['সোনালি কলার ও মুক্তো ইউনজিয়ান', 'বুকে-হাতায় ভারী সূচিকর্ম', 'মামিয়ান হেমে ঘন স্তর', '囍 বিবাহ দৃশ্য দৃশ্যমান', 'সাইজ S–2XL'],
        'wash_title' => 'যত্ন',
        'wash_lines' => ['আলাদা হাত ধোয়া; ব্লিচ নয়।', 'ঝুলিয়ে শুকান, তীব্র রোদ এড়ান।'],
        'info_title' => 'এক নজরে',
        'info_basics' => 'মৌলিক',
        'info_comfort' => 'স্পর্শ',
        'label_brand' => 'ব্র্যান্ড',
        'label_name' => 'নাম',
        'label_color' => 'রঙ',
        'label_style' => 'শৈলী',
        'label_size' => 'সাইজ',
        'label_fabric' => 'কাপড়',
        'label_parts' => 'অংশ',
        'info_color' => 'গাঢ় লাল',
        'info_style' => 'মিং শৈলী',
        'info_fabric' => 'নির্বাচিত কাপড়',
        'info_parts' => 'দাঁড়ানো কলার টপ, ইউনজিয়ান, মামিয়ান স্কার্ট',
        'size_title' => 'সাইজ নির্দেশিকা',
        'size_body' => 'S–2XL উপলব্ধ। বুক ও উচ্চতা অনুযায়ী বেছে নিন; হাতের মাপে ১–৩ সেমি ফারাক হতে পারে।',
        'original_title' => 'মূল কারুকাজ',
        'original_body' => 'কাট ও নকশা হুয়াঝাওজির নিজস্ব—কারুকে সম্মান করুন।',
        'close_caption' => 'হংরান · মিং বিবাহ',
        'alt_hero' => 'হংরান · সেট',
        'alt_look' => 'হংরান · পরা',
        'alt_macro' => 'হংরান · বিস্তারিত',
        'alt_scene' => 'হংরান · ড্রেসিং',
        'alt_close' => 'হংরান · শেষ',
        'c_thick' => 'পুরুত্ব',
        'c_thick_opts' => ['পাতলা', 'মাঝারি', 'মোটা'],
        'c_thick_sel' => 'মাঝারি',
        'c_fit' => 'ফিট',
        'c_fit_opts' => ['স্লিম', 'নিয়মিত', 'ঢিলা'],
        'c_fit_sel' => 'নিয়মিত',
        'c_soft' => 'স্পর্শ',
        'c_soft_opts' => ['নরম', 'মাঝারি', 'শক্ত'],
        'c_soft_sel' => 'মাঝারি',
        'c_stretch' => 'স্থিতিস্থাপকতা',
        'c_stretch_opts' => ['নেই', 'হালকা', 'বেশি'],
        'c_stretch_sel' => 'নেই',
    ],
    'hi_IN' => [
        'collar_title' => 'स्टैंड कॉलर और यूनजियान',
        'collar_body' => 'कॉलर पर सुनहरी कढ़ाई स्पष्ट; यूनजियान और मोती गर्दन-कंधे लंबी करें।',
        'macro_title' => 'नज़दीक से',
        'macro_label' => 'भारी तितली-फूल कढ़ाई',
        'macro_body' => 'सीने और आस्तीन पर फूल, तितली और सुनहरी किनारी।',
        'macro2_label' => 'कपड़ा और पैटर्न',
        'macro2_body' => 'गहरा लाल और उभरी कढ़ाई; मूल कट, थोक बोर्ड नहीं।',
        'scene_title' => 'विवाह दृश्य 囍',
        'scene_body' => 'ड्रेसिंग टेबल, 囍 लैंप और लाल गुलाब विवाह भाव स्पष्ट करते हैं।',
        'look_title' => 'पूरा लुक',
        'look_body' => 'घूंघट, मुकुट, चौड़ी आस्तीन और मामियान साथ चमकें; हेम कढ़ाई प्लीट के साथ।',
        'sit_title' => 'यूनजियान और हेम',
        'sit_body' => 'बैठकर मामियान हेम कढ़ाई पट्टियों में खुलता है।',
        'quiet_line' => 'वस्त्र पर लाल; दीपक के नीचे खुशी।',
        'checklist_title' => 'याद रखने योग्य',
        'checklist' => ['सुनहरा कॉलर और मोती यूनजियान', 'सीने-आस्तीन पर भारी कढ़ाई', 'मामियान हेम पर घनी परतें', '囍 विवाह दृश्य दिखता है', 'साइज़ S–2XL'],
        'wash_title' => 'देखभाल',
        'wash_lines' => ['अलग से हाथ धोएँ; ब्लीच नहीं।', 'लटकाकर सुखाएँ, तेज़ धूप से बचाएँ।'],
        'info_title' => 'एक नज़र में',
        'info_basics' => 'मूल',
        'info_comfort' => 'स्पर्श',
        'label_brand' => 'ब्रांड',
        'label_name' => 'नाम',
        'label_color' => 'रंग',
        'label_style' => 'शैली',
        'label_size' => 'साइज़',
        'label_fabric' => 'कपड़ा',
        'label_parts' => 'भाग',
        'info_color' => 'गहरा लाल',
        'info_style' => 'मिंग शैली',
        'info_fabric' => 'चयनित कपड़ा',
        'info_parts' => 'स्टैंड कॉलर टॉप, यूनजियान, मामियान स्कर्ट',
        'size_title' => 'साइज़ मार्गदर्शिका',
        'size_body' => 'S–2XL उपलब्ध। छाती और ऊँचाई से चुनें; हाथ माप में १–३ सेमी अंतर हो सकता है।',
        'original_title' => 'मूल शिल्प',
        'original_body' => 'कट और रूप हुआझाओजी के मूल हैं—शिल्प का सम्मान करें।',
        'close_caption' => 'होंगरान · मिंग वैवाहिक',
        'alt_hero' => 'होंगरान · सेट',
        'alt_look' => 'होंगरान · पहना',
        'alt_macro' => 'होंगरान · विवरण',
        'alt_scene' => 'होंगरान · ड्रेसिंग',
        'alt_close' => 'होंगरान · समापन',
        'c_thick' => 'मोटाई',
        'c_thick_opts' => ['पतला', 'मध्यम', 'मोटा'],
        'c_thick_sel' => 'मध्यम',
        'c_fit' => 'फिट',
        'c_fit_opts' => ['स्लिम', 'नियमित', 'ढीला'],
        'c_fit_sel' => 'नियमित',
        'c_soft' => 'स्पर्श',
        'c_soft_opts' => ['नरम', 'मध्यम', 'सख्त'],
        'c_soft_sel' => 'मध्यम',
        'c_stretch' => 'लचीलापन',
        'c_stretch_opts' => ['नहीं', 'हल्का', 'अधिक'],
        'c_stretch_sel' => 'नहीं',
    ],
    'ur_PK' => [
        'collar_title' => 'کھڑی کالر اور یونجیان',
        'collar_body' => 'کالر پر سونے کی کڑھائی واضح؛ یونجیان اور موتی گردن کندھے لمبے کریں۔',
        'macro_title' => 'قریب سے',
        'macro_label' => 'بھاری تتلی پھول کڑھائی',
        'macro_body' => 'سینے اور آستین پر پھول، تتلی اور سنہری کنارے۔',
        'macro2_label' => 'کپڑا اور نقش',
        'macro2_body' => 'گہرا سرخ اور ابھری کڑھائی؛ اصل کٹ، تھوک بورڈ نہیں۔',
        'scene_title' => 'شادی کا منظر 囍',
        'scene_body' => 'ڈریسنگ ٹیبل، 囍 لیمپ اور سرخ گلاب شادی کا موڈ ظاہر کرتے ہیں۔',
        'look_title' => 'مکمل نظر',
        'look_body' => 'گھونگھٹ، تاج، چوڑی آستین اور مامیان ساتھ چمکیں؛ ہیم کڑھائی پلیٹس کے ساتھ۔',
        'sit_title' => 'یونجیان اور ہیم',
        'sit_body' => 'بیٹھ کر مامیان ہیم کڑھائی پٹیوں میں کھلتا ہے۔',
        'quiet_line' => 'جامے پر سرخ؛ لیمپ کے نیچے خوشی۔',
        'checklist_title' => 'یاد رکھنے کے قابل',
        'checklist' => ['سنہری کالر اور موتی یونجیان', 'سینے آستین پر بھاری کڑھائی', 'مامیان ہیم پر گھنی تہیں', '囍 شادی منظر نظر آتا ہے', 'سائز S–2XL'],
        'wash_title' => 'نگہداشت',
        'wash_lines' => ['علیحدہ ہاتھ دھوئیں؛ بلیچ نہیں۔', 'لٹکا کر خشک کریں، تیز دھوپ سے بچائیں۔'],
        'info_title' => 'ایک نظر میں',
        'info_basics' => 'بنیادی',
        'info_comfort' => 'لمس',
        'label_brand' => 'برانڈ',
        'label_name' => 'نام',
        'label_color' => 'رنگ',
        'label_style' => 'طرز',
        'label_size' => 'سائز',
        'label_fabric' => 'کپڑا',
        'label_parts' => 'حصے',
        'info_color' => 'گہرا سرخ',
        'info_style' => 'منگ طرز',
        'info_fabric' => 'منتخب کپڑا',
        'info_parts' => 'کھڑی کالر ٹاپ، یونجیان، مامیان اسکرٹ',
        'size_title' => 'سائز رہنما',
        'size_body' => 'S–2XL دستیاب۔ سینہ اور قد سے چنیں؛ ہاتھ کی پیمائش میں ۱–۳ سینٹی میٹر فرق ہو سکتا ہے۔',
        'original_title' => 'اصل دستکاری',
        'original_body' => 'کٹ اور نقوش ہواژاوجی کے اصل ہیں—دستکاری کا احترام کریں۔',
        'close_caption' => 'ہونگران · منگ شادی',
        'alt_hero' => 'ہونگران · سیٹ',
        'alt_look' => 'ہونگران · پہنا',
        'alt_macro' => 'ہونگران · تفصیل',
        'alt_scene' => 'ہونگران · ڈریسنگ',
        'alt_close' => 'ہونگران · اختتام',
        'c_thick' => 'موٹائی',
        'c_thick_opts' => ['پتلا', 'درمیانہ', 'موٹا'],
        'c_thick_sel' => 'درمیانہ',
        'c_fit' => 'فٹ',
        'c_fit_opts' => ['سلِم', 'عام', 'ڈھیلا'],
        'c_fit_sel' => 'عام',
        'c_soft' => 'لمس',
        'c_soft_opts' => ['نرم', 'درمیانہ', 'سخت'],
        'c_soft_sel' => 'درمیانہ',
        'c_stretch' => 'کھینچاؤ',
        'c_stretch_opts' => ['نہیں', 'ہلکا', 'زیادہ'],
        'c_stretch_sel' => 'نہیں',
    ],
];
foreach ($localeOverrides as $loc => $over) {
    if (!isset($copy[$loc])) {
        continue;
    }
    $copy[$loc] = array_replace($copy[$loc], $over);
}


$assemble = static function (array $t) use ($A, $img, $feature, $figureStack, $h): string {
    $intro = '<div class="weline-detail-prose weline-detail-prose--lead"><h3>' . $h((string)$t['intro_title']) . '</h3><p>'
        . $h((string)$t['intro_body']) . '</p></div>';

    $hero = $figureStack([
        $img($A['hero'], (string)$t['alt_hero']),
    ], 'weline-detail-figure-stack--fullbleed weline-detail-orient--squareish');

    $inspire = '<div class="weline-detail-prose weline-detail-prose--verse"><h3>' . $h((string)$t['inspire_title']) . '</h3>';
    foreach ((array)$t['inspire_lines'] as $line) {
        $inspire .= '<p>' . $h((string)$line) . '</p>';
    }
    $inspire .= '<p class="weline-detail-feature__note">' . $h((string)$t['inspire_note']) . '</p></div>';

    $pairLooks = $figureStack([
        $img($A['look02'], (string)$t['alt_look'] . ' 1'),
        $img($A['look03'], (string)$t['alt_look'] . ' 2'),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--squareish');

    $collar = $figureStack([
        $img($A['half'], (string)$t['alt_look'] . ' 3'),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['collar_title']) . '</h3><p>'
        . $h((string)$t['collar_body']) . '</p></div>';

    $macro = $figureStack([
        $img($A['macro'], (string)$t['alt_macro'] . ' 1'),
        $img($A['detail08'], (string)$t['alt_macro'] . ' 2'),
    ], 'weline-detail-figure-stack--caption')
        . '<div class="weline-detail-prose weline-detail-prose--macro"><h3>' . $h((string)$t['macro_title']) . '</h3>'
        . '<h4>' . $h((string)$t['macro_label']) . '</h4><p>' . $h((string)$t['macro_body']) . '</p>'
        . '<h4>' . $h((string)$t['macro2_label']) . '</h4><p>' . $h((string)$t['macro2_body']) . '</p></div>';

    $scene = $feature(
        $img($A['vanity'], (string)$t['alt_scene']),
        '<h3>' . $h((string)$t['scene_title']) . '</h3><p>' . $h((string)$t['scene_body']) . '</p>',
        false,
    );

    $quiet = '<div class="weline-detail-quiet-spacer" aria-hidden="true"></div>';

    $check = '<div class="weline-detail-prose weline-detail-prose--checklist"><h3>' . $h((string)$t['checklist_title']) . '</h3><ul>';
    foreach ((array)$t['checklist'] as $item) {
        $check .= '<li>' . $h((string)$item) . '</li>';
    }
    $check .= '</ul></div>';

    $lookPair = $figureStack([
        $img($A['veil'], (string)$t['alt_look'] . ' 4'),
        $img($A['look06'], (string)$t['alt_look'] . ' 5'),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['look_title']) . '</h3><p>'
        . $h((string)$t['look_body']) . '</p></div>';

    $stack09 = $figureStack([
        $img($A['look09'], (string)$t['alt_look'] . ' 6'),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait');

    $sitPair = $figureStack([
        $img($A['look10'], (string)$t['alt_look'] . ' 7'),
        $img($A['sit'], (string)$t['alt_look'] . ' 8'),
    ], 'weline-detail-figure-stack--caption')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['sit_title']) . '</h3><p>'
        . $h((string)$t['sit_body']) . '</p></div>';

    $quietLine = '<div class="weline-detail-prose weline-detail-prose--quiet"><p>' . $h((string)$t['quiet_line']) . '</p></div>';

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

    $close = $figureStack([
        $img($A['close'], (string)$t['alt_close']),
        $img($A['portrait'], (string)$t['alt_hero'] . ' 2'),
    ], 'weline-detail-figure-stack--fullbleed')
        . '<div class="weline-detail-prose weline-detail-prose--quiet"><p class="weline-detail-feature__note">'
        . $h((string)$t['close_caption']) . '</p></div>';

    // unused assets kept available: look04/look05/full03 for future — include look04/05 in a quiet pair to avoid orphan gallery
    $extraPair = $figureStack([
        $img($A['look04'], (string)$t['alt_look'] . ' 9'),
        $img($A['look05'], (string)$t['alt_look'] . ' 10'),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--squareish');

    return '<div data-weline-product-description="1688" data-weds="xq">'
        . '<!--weds:xq--><span data-weds="xq" hidden aria-hidden="true"></span>'
        . $intro . $hero . $inspire . $pairLooks . $collar . $macro . $scene
        . $quiet . $check . $lookPair . $stack09 . $sitPair . $quietLine
        . $extraPair . $wash . $info . $size . $original . $close
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

$enLeakMarkers = ['Design wellspring', 'Original craft', 'Worth noting', 'At a glance', 'Close looking', 'Size guide'];
$banned = ['一件代发', '混批', '厂家直销', '旺旺', '货源', '阿里巴巴', '淘宝', '天猫', '拼多多', 'dropship', 'Dropship'];

$writes = [];
foreach ($localePlan as $locale => $baseKey) {
    if (!isset($copy[$baseKey])) {
        fwrite(STDERR, "Missing locale pack: {$baseKey}\n");
        exit(2);
    }
    $html = $assemble($copy[$baseKey]);
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
    if (str_contains($html, 'a099d656-98ff-43ca-aa80-0513418ba15b')) {
        fwrite(STDERR, "Banned detail-02 asset in {$locale}\n");
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
        'fullbleed' => substr_count($html, 'weline-detail-figure-stack--fullbleed'),
        'weds' => str_contains($html, 'data-weds="xq"') ? 1 : 0,
    ];
}

foreach ($writes as $w) {
    echo sprintf(
        "%s\t%d\tfeature=%d\timgs=%d\tfullbleed=%d\tweds=%d\n",
        $w['locale'] === '' ? '(empty)' : $w['locale'],
        $w['len'],
        $w['features'],
        $w['imgs'],
        $w['fullbleed'],
        $w['weds'],
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
    'detail_locale_true_translate_188',
    ['product_ids' => [$productId]],
);
ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class)
    ->clearForCatalogChange('detail_locale_true_translate_188');

echo 'Applied description-only writes: ' . count($writes) . " locales.\n";
