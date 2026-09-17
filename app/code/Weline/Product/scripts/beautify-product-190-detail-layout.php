<?php

declare(strict_types=1);

/**
 * #190 慕卿 · 禁抠图 / 禁 blur-fill：HD 竖幅 + 按图排版（v7）
 *
 * 竖图单列 stack/fullbleed；macro 亦竖排满幅。
 * 禁止 feature_lr 糊边凑横；无真 outpaint 则竖排 HD。
 *
 * php app/code/Weline/Product/scripts/replace-190-classfix-anti-blurfill.php
 * php app/code/Weline/Product/scripts/beautify-product-190-detail-layout.php --apply
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
$productId = 190;

// 竖幅 3:4 HD（1200×1600）；macro 亦竖排满幅，禁 landscape 糊边/色条
$A = [
    'hero' => '67b0e52a-0b0b-4936-b232-d3630a62bfd4', // g01 paper 3:4
    'stack_back' => '71ac856b-a770-4431-9abf-a425914b05d3', // g02 paper 3:4 背影
    'stack_mid' => 'c9d3efe4-c7ba-4d22-b1f6-4332111ee8b1', // g03 paper 3:4
    'stack' => 'cc7e295d-e376-4cd2-bf5f-18bb97181b13', // g05 paper 3:4
    'stack_b' => 'c1272d10-a36c-4957-968d-73690dd5175f', // g04 paper 3:4
    'macro_hem' => 'd05e6db3-7465-483b-8080-226315baaff2', // detail-03 竖幅特写（抗糊边）
    'macro_sleeve' => '2e811866-1c15-42a5-bca2-6e801ea9d93a', // detail-04 竖幅特写（抗糊边）
    'macro_back' => 'f1d6b71c-6ac6-4502-b030-178ca56498d9', // detail-06-back-macro
    'flat_black' => 'e1008607-3741-4f19-a5ed-716390d159b5',
    'flat_red' => '9c26b17a-0ae1-4f88-80cc-7c05830a60be',
    'close' => 'c1272d10-a36c-4957-968d-73690dd5175f', // g04 paper close
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
    // §3.1‑E：竖屏全身单列；禁止同棚全身 triptych。pair 仅用于异质特写/款式平铺。
    $rows = '';
    $n = count($imgs);
    $forceSolo = str_contains($mod, 'orient--portrait') || str_contains($mod, 'fullbleed') || str_contains($mod, 'caption');
    if ($forceSolo || $n === 1) {
        foreach ($imgs as $one) {
            $rows .= '<div class="weline-detail-figure-row weline-detail-figure-row--solo">'
                . '<div class="weline-detail-figure">' . $one . '</div>'
                . '</div>';
        }
    } elseif ($n === 2) {
        $rows .= '<div class="weline-detail-figure-row weline-detail-figure-row--pair">'
            . '<div class="weline-detail-figure">' . $imgs[0] . '</div>'
            . '<div class="weline-detail-figure">' . $imgs[1] . '</div>'
            . '</div>';
    } else {
        // 特写 macro：纵向叠单列，不用货盘三联墙
        foreach ($imgs as $one) {
            $rows .= '<div class="weline-detail-figure-row weline-detail-figure-row--solo">'
                . '<div class="weline-detail-figure">' . $one . '</div>'
                . '</div>';
        }
    }
    $cls = 'weline-detail-figure-stack' . ($mod !== '' ? ' ' . $mod : '');

    return '<div class="' . $h($cls) . '">' . $rows . '</div>';
};

/** @var array<string, array<string, mixed>> $copy */
$copy = [
    'zh_Hans_CN' => [
        'intro_title' => '慕卿',
        'intro_body' => '晋制交领广袖：朱墨相衬的大袖衫与齐腰襦裙。薄纱外袍轻透，裙幅破色见莲——男女同款，端庄可出入日常。',
        'inspire_title' => '题眼',
        'inspire_lines' => ['慕卿之名，取君子之慕。', '朱墨为骨，莲纹为心。'],
        'inspire_note' => '以「慕卿」点题——交领广袖与裙幅莲纹相承；非平台货盘说辞，仅为形制与纹样之注。',
        'stack_title' => '背影莲蝶',
        'stack_body' => '外衫背部垂下白莲与蝶影，线条舒展；转身时薄纱浮动，层次分明而不喧哗。',
        'macro_title' => '细处可辨',
        'collar_label' => '交领叠合',
        'collar_body' => '交领深浅交叠，内里朱红托出颈线，外衫玄色收住气场。',
        'sleeve_label' => '广袖薄纱',
        'sleeve_body' => '广袖半透，抬臂见层；袖缘细纹与裾边莲意相呼。',
        'hem_macro_label' => '裙幅莲纹',
        'hem_macro_body' => '黑裙幅铺白粉莲与卷草，红白破色条分明，褶影随步起伏。',
        'collar_title' => '交领广袖',
        'collar_feature_body' => '晋制交领叠合利落，广袖展而不臃。朱红中衣与玄纱外袍一明一敛，腰间束带定住层次——宜站、宜行、宜对坐。',
        'collar_feature_body2' => '近看领缘层次分明，袖幅半透却不散形；抬臂、落袖都见晋制气度，而非堆砌的宽大。',
        'triptych_note' => '朱墨之间，气韵不同',
        'quiet_line' => '慕卿在衣，不在喧哗。',
        'checklist_title' => '衣袂可记',
        'checklist' => ['晋制交领广袖大袖衫', '朱墨撞色破裙与白条', '裾边莲纹层次清晰', '薄纱外袍层次可辨', '男女同款形制'],
        'hem_title' => '裾边生莲',
        'hem_body' => '裙幅黑底生莲，粉白花瓣与青绿茎脉清晰可辨；破色红条托出华贵，却不抢交领主线。',
        'hem_body2' => '行走时褶影开合，莲纹时隐时现——细节留给近观，远观只见朱墨清朗。',
        'info_brand' => '花朝记',
        'info_name' => '慕卿',
        'info_color' => '朱红 · 玄黑 · 牙白',
        'info_style' => '晋制',
        'info_size' => 'S–XL',
        'info_fabric' => '精选面料（如图）',
        'info_parts' => '大袖衫、齐腰襦裙',
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
        'chart_note' => '单位：厘米。手工测量或有一至三厘米出入。特殊身形可咨询客服混码建议。',
        't_fit' => '参考',
        'h_size' => '尺码',
        'h_bust' => '胸围',
        'h_height' => '建议身高',
        'h_weight' => '建议体重（斤）',
        'original_title' => '原创心迹',
        'original_body' => '本款形制与纹样为花朝记原创设计，敬请珍惜衣冠、尊重匠心。',
        'close_caption' => '慕卿 · 晋制日常',
        'alt_hero' => '慕卿 · 套装',
        'alt_look' => '慕卿 · 着装',
        'alt_macro' => '慕卿 · 细部',
        'alt_hem' => '慕卿 · 裾边',
        'alt_close' => '慕卿 · 合影',
        'alt_flat' => '慕卿 · 款式图',
        'c_soft' => '柔软',
        'c_soft_opts' => ['偏软', '适中', '偏硬'],
        'c_soft_sel' => '适中',
        'c_thick' => '厚度',
        'c_thick_opts' => ['薄', '适中', '厚'],
        'c_thick_sel' => '薄',
        'c_stretch' => '弹性',
        'c_stretch_opts' => ['无弹', '微弹', '高弹'],
        'c_stretch_sel' => '无弹',
    ],
    'en_US' => [
        'intro_title' => 'Muqing',
        'intro_body' => 'A Jin-style cross-collar wide-sleeve set: madder red against charcoal, sheer outer robe, and a paneled skirt with lotus at the hem—shared cut for him and her, poised for daily wear.',
        'inspire_title' => 'Title motif',
        'inspire_lines' => ['Muqing names a quiet admiration.', 'Vermilion and ink for bone; lotus for heart.'],
        'inspire_note' => 'Named for “Muqing”—cross-collar sleeves and lotus panels answer each other; not marketplace pitch, only a note on cut and pattern.',
        'stack_title' => 'Lotus at the back',
        'stack_body' => 'White lotus and butterfly lines fall down the outer robe; sheer layers shift as you turn—clear hierarchy without noise.',
        'macro_title' => 'Close looking',
        'collar_label' => 'Cross collar',
        'collar_body' => 'Overlapping collars stack light and dark; madder under charcoal lengthens the neckline.',
        'sleeve_label' => 'Sheer wide sleeves',
        'sleeve_body' => 'Wide sleeves stay translucent; cuff lines echo the lotus hem.',
        'hem_macro_label' => 'Lotus panels',
        'hem_macro_body' => 'Black panels carry pink-white lotus and scrolling stems; red-white stripes keep the rhythm clear.',
        'collar_title' => 'Cross-collar wide sleeves',
        'collar_feature_body' => 'Jin cross-collar sits clean; wide sleeves open without bulk. Madder inner and charcoal sheer outer—one bright, one reserved—held by the sash. Good for standing, walking, or sitting face to face.',
        'collar_feature_body2' => 'Collar layers stay readable; sheer sleeves keep shape when raised or dropped—Jin poise, not empty volume.',
        'triptych_note' => 'Moods between vermilion and ink',
        'quiet_line' => 'Muqing lives in the cloth, not in noise.',
        'checklist_title' => 'Worth noting',
        'checklist' => ['Jin cross-collar wide-sleeve robe', 'Vermilion–ink paneled skirt', 'Clear lotus layering at the hem', 'Readable sheer outer layers', 'Shared cut for men and women'],
        'hem_title' => 'Lotus at the hem',
        'hem_body' => 'Black panels bloom with lotus; pink-white petals and green stems stay legible; red stripes lift richness without stealing the collar line.',
        'hem_body2' => 'As you walk, pleats open and close—lotus appears and fades. Detail for close looking; from afar only vermilion and ink stay clear.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Muqing',
        'info_color' => 'Madder · Charcoal · Ivory',
        'info_style' => 'Jin style',
        'info_size' => 'S–XL',
        'info_fabric' => 'Selected fabric (as shown)',
        'info_parts' => 'Wide-sleeve robe, waist-high skirt',
        'info_title' => 'At a glance',
        'info_basics' => 'Basics',
        'info_comfort' => 'Hand feel',
        'label_brand' => 'Brand',
        'label_name' => 'Name',
        'label_color' => 'Color',
        'label_style' => 'Style',
        'label_size' => 'Size',
        'label_fabric' => 'Fabric',
        'label_parts' => 'Pieces',
        'chart_title' => 'Size reference',
        'chart_note' => 'Centimeters. Hand measure may vary by 1–3 cm. Mixed sizes available for special builds via support.',
        't_fit' => 'Fit',
        'h_size' => 'Size',
        'h_bust' => 'Bust',
        'h_height' => 'Suggested height',
        'h_weight' => 'Suggested weight (jin)',
        'original_title' => 'Original craft',
        'original_body' => 'Cut and motifs are Huazhaoji originals—please respect the craft.',
        'close_caption' => 'Muqing · Jin daily',
        'alt_hero' => 'Muqing · set',
        'alt_look' => 'Muqing · worn',
        'alt_macro' => 'Muqing · detail',
        'alt_hem' => 'Muqing · hem',
        'alt_close' => 'Muqing · close',
        'alt_flat' => 'Muqing · flat',
        'c_soft' => 'Softness',
        'c_soft_opts' => ['Softer', 'Medium', 'Firmer'],
        'c_soft_sel' => 'Medium',
        'c_thick' => 'Thickness',
        'c_thick_opts' => ['Thin', 'Medium', 'Thick'],
        'c_thick_sel' => 'Thin',
        'c_stretch' => 'Stretch',
        'c_stretch_opts' => ['None', 'Slight', 'High'],
        'c_stretch_sel' => 'None',
    ],
    'es_ES' => [
        'intro_title' => 'Muqing',
        'intro_body' => 'Conjunto jin de cuello cruzado y mangas anchas: rojo madder frente a carbón, túnica exterior translúcida y falda a paneles con lotos—corte compartido, elegante para el día a día.',
        'inspire_title' => 'Motivo del nombre',
        'inspire_lines' => ['Muqing nombra una admiración serena.', 'Bermejo y tinta como hueso; loto como corazón.'],
        'inspire_note' => 'Llamado «Muqing»: el cuello cruzado y los lotos de la falda se responden; no es discurso de marketplace.',
        'stack_title' => 'Loto en la espalda',
        'stack_body' => 'Lotos blancos y líneas de mariposa caen por la espalda; las capas translúcidas se mueven al girar.',
        'macro_title' => 'De cerca',
        'collar_label' => 'Cuello cruzado',
        'collar_body' => 'Los cuellos se superponen en claro y oscuro; el madder bajo el carbón alarga el cuello.',
        'sleeve_label' => 'Mangas anchas',
        'sleeve_body' => 'Mangas translúcidas; el borde del puño responde al loto del bajo.',
        'hem_macro_label' => 'Paneles de loto',
        'hem_macro_body' => 'Paneles negros con lotos rosa-blanco; franjas rojo-blanco marcan el ritmo.',
        'collar_title' => 'Cuello cruzado y mangas anchas',
        'collar_feature_body' => 'El cuello jin queda limpio; las mangas se abren sin volumen. Interior madder y exterior de gasa carbón—uno claro, otro contenido—sujetos por la faja.',
        'collar_feature_body2' => 'Las capas del cuello se leen claras; las mangas translúcidas mantienen forma al alzar o bajar—porte jin, no volumen vacío.',
        'triptych_note' => 'Matices entre bermejo y tinta',
        'quiet_line' => 'Muqing vive en la tela, no en el ruido.',
        'checklist_title' => 'Para recordar',
        'checklist' => ['Túnica jin de cuello cruzado y mangas anchas', 'Falda a paneles bermejo-tinta', 'Lotos legibles en el bajo', 'Capas translúcidas visibles', 'Corte compartido'],
        'hem_title' => 'Loto en el bajo',
        'hem_body' => 'Los paneles negros florecen; pétalos rosa-blanco y tallos verdes se leen claro; las franjas rojas aportan riqueza sin quitar el cuello.',
        'hem_body2' => 'Al caminar, los pliegues abren y cierran—el loto aparece y se esconde. Detalle de cerca; de lejos solo quedan bermejo y tinta.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Muqing',
        'info_color' => 'Madder · Carbón · Marfil',
        'info_style' => 'Estilo Jin',
        'info_size' => 'S–XL',
        'info_fabric' => 'Tejido seleccionado (como en foto)',
        'info_parts' => 'Túnica de mangas anchas, falda a la cintura',
        'info_title' => 'De un vistazo',
        'info_basics' => 'Básico',
        'info_comfort' => 'Tacto',
        'label_brand' => 'Marca',
        'label_name' => 'Nombre',
        'label_color' => 'Color',
        'label_style' => 'Estilo',
        'label_size' => 'Talla',
        'label_fabric' => 'Tejido',
        'label_parts' => 'Piezas',
        'chart_title' => 'Referencia de talla',
        'chart_note' => 'Centímetros. La medida manual puede variar 1–3 cm.',
        't_fit' => 'Ajuste',
        'h_size' => 'Talla',
        'h_bust' => 'Busto',
        'h_height' => 'Altura sugerida',
        'h_weight' => 'Peso sugerido (jin)',
        'original_title' => 'Oficio original',
        'original_body' => 'Corte y motivos son originales de Huazhaoji—respeten el oficio.',
        'close_caption' => 'Muqing · Jin cotidiano',
        'alt_hero' => 'Muqing · conjunto',
        'alt_look' => 'Muqing · puesto',
        'alt_macro' => 'Muqing · detalle',
        'alt_hem' => 'Muqing · bajo',
        'alt_close' => 'Muqing · cierre',
        'alt_flat' => 'Muqing · plano',
        'c_soft' => 'Suavidad',
        'c_soft_opts' => ['Más suave', 'Medio', 'Más firme'],
        'c_soft_sel' => 'Medio',
        'c_thick' => 'Grosor',
        'c_thick_opts' => ['Fino', 'Medio', 'Grueso'],
        'c_thick_sel' => 'Fino',
        'c_stretch' => 'Elasticidad',
        'c_stretch_opts' => ['Ninguna', 'Ligera', 'Alta'],
        'c_stretch_sel' => 'Ninguna',
    ],
    'fr_FR' => [
        'intro_title' => 'Muqing',
        'intro_body' => 'Ensemble jin à col croisé et manches larges : rouge madder contre charbon, robe extérieure légère et jupe à panneaux ornée de lotus—coupe partagée, digne au quotidien.',
        'inspire_title' => 'Motif du titre',
        'inspire_lines' => ['Muqing nomme une admiration discrète.', 'Vermillon et encre pour l’os ; lotus pour le cœur.'],
        'inspire_note' => 'Nommé « Muqing » : le col croisé et les lotus de jupe se répondent ; pas un discours de plateforme.',
        'stack_title' => 'Lotus dans le dos',
        'stack_body' => 'Lotus blancs et lignes de papillon descendent dans le dos ; les voiles bougent en tournant.',
        'macro_title' => 'De près',
        'collar_label' => 'Col croisé',
        'collar_body' => 'Les cols se superposent clair et sombre ; le madder sous le charbon allonge la ligne du cou.',
        'sleeve_label' => 'Manches larges',
        'sleeve_body' => 'Manches translucides ; le bord répond au lotus de l’ourlet.',
        'hem_macro_label' => 'Panneaux de lotus',
        'hem_macro_body' => 'Panneaux noirs aux lotus rose-blanc ; bandes rouge-blanc rythment la jupe.',
        'collar_title' => 'Col croisé et manches larges',
        'collar_feature_body' => 'Le col jin reste net ; les manches s’ouvrent sans volume. Intérieur madder et voile charbon—l’un clair, l’autre retenu—tenus par la ceinture.',
        'collar_feature_body2' => 'Les couches du col restent lisibles ; les manches translucides gardent la forme—allure jin, pas de volume vide.',
        'triptych_note' => 'Nuances entre vermillon et encre',
        'quiet_line' => 'Muqing vit dans le tissu, non dans le bruit.',
        'checklist_title' => 'À retenir',
        'checklist' => ['Robe jin à col croisé et manches larges', 'Jupe à panneaux vermillon-encre', 'Lotus lisibles à l’ourlet', 'Couches translucides visibles', 'Coupe partagée'],
        'hem_title' => 'Lotus à l’ourlet',
        'hem_body' => 'Les panneaux noirs fleurissent ; pétales rose-blanc et tiges vertes restent lisibles ; les bandes rouges enrichissent sans voler le col.',
        'hem_body2' => 'En marchant, les plis s’ouvrent et se ferment—le lotus apparaît puis s’efface. Le détail est de près ; de loin seuls restent vermillon et encre.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Muqing',
        'info_color' => 'Madder · Charbon · Ivoire',
        'info_style' => 'Style Jin',
        'info_size' => 'S–XL',
        'info_fabric' => 'Tissu sélectionné (comme sur photo)',
        'info_parts' => 'Robe à manches larges, jupe à la taille',
        'info_title' => 'En un coup d’œil',
        'info_basics' => 'Base',
        'info_comfort' => 'Toucher',
        'label_brand' => 'Marque',
        'label_name' => 'Nom',
        'label_color' => 'Couleur',
        'label_style' => 'Style',
        'label_size' => 'Taille',
        'label_fabric' => 'Tissu',
        'label_parts' => 'Pièces',
        'chart_title' => 'Référence de taille',
        'chart_note' => 'Centimètres. Mesure manuelle : écart possible de 1–3 cm.',
        't_fit' => 'Coupe',
        'h_size' => 'Taille',
        'h_bust' => 'Poitrine',
        'h_height' => 'Taille suggérée',
        'h_weight' => 'Poids suggéré (jin)',
        'original_title' => 'Création originale',
        'original_body' => 'Coupe et motifs sont originaux Huazhaoji—respectez l’artisanat.',
        'close_caption' => 'Muqing · Jin au quotidien',
        'alt_hero' => 'Muqing · ensemble',
        'alt_look' => 'Muqing · porté',
        'alt_macro' => 'Muqing · détail',
        'alt_hem' => 'Muqing · ourlet',
        'alt_close' => 'Muqing · clôture',
        'alt_flat' => 'Muqing · plan',
        'c_soft' => 'Souplesse',
        'c_soft_opts' => ['Plus souple', 'Moyen', 'Plus ferme'],
        'c_soft_sel' => 'Moyen',
        'c_thick' => 'Épaisseur',
        'c_thick_opts' => ['Fin', 'Moyen', 'Épais'],
        'c_thick_sel' => 'Fin',
        'c_stretch' => 'Élasticité',
        'c_stretch_opts' => ['Aucune', 'Légère', 'Forte'],
        'c_stretch_sel' => 'Aucune',
    ],
    'pt_BR' => [
        'intro_title' => 'Muqing',
        'intro_body' => 'Conjunto jin de gola cruzada e mangas largas: vermelho madder contra carvão, manto externo translúcido e saia em painéis com lótus—corte compartilhado, elegante no dia a dia.',
        'inspire_title' => 'Motivo do nome',
        'inspire_lines' => ['Muqing nomeia uma admiração serena.', 'Vermelho e tinta como osso; lótus como coração.'],
        'inspire_note' => 'Chamado «Muqing»: a gola cruzada e os lótus da saia se respondem; não é discurso de marketplace.',
        'stack_title' => 'Lótus nas costas',
        'stack_body' => 'Lótus brancos e linhas de borboleta descem pelas costas; as camadas translúcidas se movem ao girar.',
        'macro_title' => 'De perto',
        'collar_label' => 'Gola cruzada',
        'collar_body' => 'As golas se sobrepõem em claro e escuro; o madder sob o carvão alonga o pescoço.',
        'sleeve_label' => 'Mangas largas',
        'sleeve_body' => 'Mangas translúcidas; a borda ecoa o lótus da barra.',
        'hem_macro_label' => 'Painéis de lótus',
        'hem_macro_body' => 'Painéis pretos com lótus rosa-branco; faixas vermelho-branco marcam o ritmo.',
        'collar_title' => 'Gola cruzada e mangas largas',
        'collar_feature_body' => 'A gola jin fica limpa; as mangas abrem sem volume. Interior madder e gaze carvão—um claro, outro contido—presos pelo cinto.',
        'collar_feature_body2' => 'As camadas da gola permanecem legíveis; mangas translúcidas mantêm forma—porte jin, sem volume vazio.',
        'triptych_note' => 'Tons entre vermelho e tinta',
        'quiet_line' => 'Muqing vive no tecido, não no barulho.',
        'checklist_title' => 'Vale notar',
        'checklist' => ['Manto jin de gola cruzada e mangas largas', 'Saia em painéis vermelho-tinta', 'Lótus legíveis na barra', 'Camadas translúcidas visíveis', 'Corte compartilhado'],
        'hem_title' => 'Lótus na barra',
        'hem_body' => 'Os painéis pretos florescem; pétalas rosa-branco e hastes verdes permanecem legíveis; as faixas vermelhas enriquecem sem roubar a gola.',
        'hem_body2' => 'Ao andar, as pregas abrem e fecham—o lótus aparece e some. Detalhe de perto; de longe só ficam vermelho e tinta.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Muqing',
        'info_color' => 'Madder · Carvão · Marfim',
        'info_style' => 'Estilo Jin',
        'info_size' => 'S–XL',
        'info_fabric' => 'Tecido selecionado (como na foto)',
        'info_parts' => 'Manto de mangas largas, saia na cintura',
        'info_title' => 'Em um olhar',
        'info_basics' => 'Básico',
        'info_comfort' => 'Toque',
        'label_brand' => 'Marca',
        'label_name' => 'Nome',
        'label_color' => 'Cor',
        'label_style' => 'Estilo',
        'label_size' => 'Tamanho',
        'label_fabric' => 'Tecido',
        'label_parts' => 'Peças',
        'chart_title' => 'Referência de tamanho',
        'chart_note' => 'Centímetros. Medida manual pode variar 1–3 cm.',
        't_fit' => 'Ajuste',
        'h_size' => 'Tamanho',
        'h_bust' => 'Busto',
        'h_height' => 'Altura sugerida',
        'h_weight' => 'Peso sugerido (jin)',
        'original_title' => 'Ofício original',
        'original_body' => 'Corte e motivos são originais Huazhaoji—respeitem o ofício.',
        'close_caption' => 'Muqing · Jin cotidiano',
        'alt_hero' => 'Muqing · conjunto',
        'alt_look' => 'Muqing · vestido',
        'alt_macro' => 'Muqing · detalhe',
        'alt_hem' => 'Muqing · barra',
        'alt_close' => 'Muqing · fechamento',
        'alt_flat' => 'Muqing · plano',
        'c_soft' => 'Maciez',
        'c_soft_opts' => ['Mais macio', 'Médio', 'Mais firme'],
        'c_soft_sel' => 'Médio',
        'c_thick' => 'Espessura',
        'c_thick_opts' => ['Fino', 'Médio', 'Grosso'],
        'c_thick_sel' => 'Fino',
        'c_stretch' => 'Elasticidade',
        'c_stretch_opts' => ['Nenhuma', 'Leve', 'Alta'],
        'c_stretch_sel' => 'Nenhuma',
    ],
    'id_ID' => [
        'intro_title' => 'Muqing',
        'intro_body' => 'Set gaya Jin kerah silang lengan lebar: merah madder berlawanan arang, jubah luar tipis, dan rok berpanel dengan teratai—potongan bersama, anggun untuk sehari-hari.',
        'inspire_title' => 'Motif nama',
        'inspire_lines' => ['Muqing menamai kekaguman yang tenang.', 'Merah dan tinta sebagai tulang; teratai sebagai hati.'],
        'inspire_note' => 'Dinamai «Muqing»: kerah silang dan teratai rok saling menjawab; bukan bahasa marketplace.',
        'stack_title' => 'Teratai di punggung',
        'stack_body' => 'Teratai putih dan garis kupu-kupu turun di punggung; lapisan tipis bergerak saat berbalik.',
        'macro_title' => 'Dari dekat',
        'collar_label' => 'Kerah silang',
        'collar_body' => 'Kerah bertumpuk terang-gelap; madder di bawah arang memanjangkan leher.',
        'sleeve_label' => 'Lengan lebar tipis',
        'sleeve_body' => 'Lengan tembus pandang; tepi manset menjawab teratai di hem.',
        'hem_macro_label' => 'Panel teratai',
        'hem_macro_body' => 'Panel hitam membawa teratai merah muda-putih; garis merah-putih menjaga irama.',
        'collar_title' => 'Kerah silang lengan lebar',
        'collar_feature_body' => 'Kerah Jin rapi; lengan lebar terbuka tanpa menggembung. Dalam madder dan luar tipis arang—satu terang, satu tertahan—diikat selempang.',
        'collar_feature_body2' => 'Lapisan kerah tetap terbaca; lengan tipis menjaga bentuk saat diangkat atau diturunkan—wibawa Jin, bukan volume kosong.',
        'triptych_note' => 'Suasana antara merah dan tinta',
        'quiet_line' => 'Muqing hidup di kain, bukan di keramaian.',
        'checklist_title' => 'Perlu diingat',
        'checklist' => ['Jubah Jin kerah silang lengan lebar', 'Rok panel merah-tinta', 'Teratai jelas di hem', 'Lapisan tipis terbaca', 'Potongan bersama pria-wanita'],
        'hem_title' => 'Teratai di hem',
        'hem_body' => 'Panel hitam berbunga; kelopak merah muda-putih dan batang hijau tetap terbaca; garis merah menambah kemewahan tanpa mencuri kerah.',
        'hem_body2' => 'Saat berjalan, lipatan membuka-menutup—teratai muncul lalu menghilang. Detail untuk dekat; dari jauh hanya merah dan tinta yang jelas.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Muqing',
        'info_color' => 'Madder · Arang · Gading',
        'info_style' => 'Gaya Jin',
        'info_size' => 'S–XL',
        'info_fabric' => 'Kain pilihan (seperti di foto)',
        'info_parts' => 'Jubah lengan lebar, rok pinggang',
        'info_title' => 'Sekilas',
        'info_basics' => 'Dasar',
        'info_comfort' => 'Rasa sentuh',
        'label_brand' => 'Merek',
        'label_name' => 'Nama',
        'label_color' => 'Warna',
        'label_style' => 'Gaya',
        'label_size' => 'Ukuran',
        'label_fabric' => 'Kain',
        'label_parts' => 'Bagian',
        'chart_title' => 'Referensi ukuran',
        'chart_note' => 'Sentimeter. Pengukuran tangan bisa berbeda 1–3 cm.',
        't_fit' => 'Pas',
        'h_size' => 'Ukuran',
        'h_bust' => 'Lingkar dada',
        'h_height' => 'Tinggi disarankan',
        'h_weight' => 'Berat disarankan (jin)',
        'original_title' => 'Kerajinan asli',
        'original_body' => 'Potongan dan motif asli Huazhaoji—hormati kerajinannya.',
        'close_caption' => 'Muqing · Jin sehari-hari',
        'alt_hero' => 'Muqing · set',
        'alt_look' => 'Muqing · dipakai',
        'alt_macro' => 'Muqing · detail',
        'alt_hem' => 'Muqing · hem',
        'alt_close' => 'Muqing · penutup',
        'alt_flat' => 'Muqing · datar',
        'c_soft' => 'Kelembutan',
        'c_soft_opts' => ['Lebih lembut', 'Sedang', 'Lebih kaku'],
        'c_soft_sel' => 'Sedang',
        'c_thick' => 'Ketebalan',
        'c_thick_opts' => ['Tipis', 'Sedang', 'Tebal'],
        'c_thick_sel' => 'Tipis',
        'c_stretch' => 'Elastisitas',
        'c_stretch_opts' => ['Tidak ada', 'Sedikit', 'Tinggi'],
        'c_stretch_sel' => 'Tidak ada',
    ],
    'ar_SA' => [
        'intro_title' => 'موتشينغ',
        'intro_body' => 'طقم جين بياقة متقاطعة وأكمام واسعة: أحمر مادّر مقابل فحم، رداء خارجي شفاف وتنورة بألواح ولوتس عند الذيل—قصّة مشتركة، رصينة لليومي.',
        'inspire_title' => 'معنى الاسم',
        'inspire_lines' => ['موتشينغ يسمّي إعجابًا هادئًا.', 'قرمزي وحبر للعظم؛ لوتس للقلب.'],
        'inspire_note' => 'سُمّي «موتشينغ»—الياقة المتقاطعة ولوتس التنورة يتجاوبان؛ ليست لغة منصات البيع.',
        'stack_title' => 'لوتس في الظهر',
        'stack_body' => 'لوتس أبيض وخطوط فراشة تسقط على الظهر؛ الطبقات الشفافة تتحرك عند الالتفات.',
        'macro_title' => 'عن قرب',
        'collar_label' => 'ياقة متقاطعة',
        'collar_body' => 'الياقات تتراكب فاتحًا وداكنًا؛ المادّر تحت الفحم يطيل خط الرقبة.',
        'sleeve_label' => 'أكمام واسعة شفافة',
        'sleeve_body' => 'الأكمام شفافة؛ حافة الكم تردّ على لوتس الذيل.',
        'hem_macro_label' => 'ألواح اللوتس',
        'hem_macro_body' => 'ألواح سوداء تحمل لوتسًا ورديًا أبيض؛ خطوط حمراء بيضاء تضبط الإيقاع.',
        'collar_title' => 'ياقة متقاطعة وأكمام واسعة',
        'collar_feature_body' => 'ياقة الجين نظيفة؛ الأكمام الواسعة تنفتح بلا انتفاخ. داخل مادّر وخارج فحم شفاف—واحد ساطع وآخر متحفّظ—يثبّتهما الحزام.',
        'collar_feature_body2' => 'طبقات الياقة مقروءة؛ الأكمام الشفافة تحفظ الشكل عند الرفع أو الإسقاط—وقار جين لا حجم فارغ.',
        'triptych_note' => 'أمزجة بين القرمزي والحبر',
        'quiet_line' => 'موتشينغ يعيش في القماش لا في الضجيج.',
        'checklist_title' => 'جدير بالذكر',
        'checklist' => ['رداء جين بياقة متقاطعة وأكمام واسعة', 'تنورة ألواح قرمزي-حبر', 'لوتس واضح عند الذيل', 'طبقات شفافة مقروءة', 'قصّة مشتركة للرجل والمرأة'],
        'hem_title' => 'لوتس عند الذيل',
        'hem_body' => 'الألواح السوداء تزهر؛ بتلات وردية بيضاء وسيقان خضراء واضحة؛ الخطوط الحمراء تضيف فخامة دون سرقة خط الياقة.',
        'hem_body2' => 'عند المشي تنفتح الطيات وتنغلق—يظهر اللوتس ثم يختفي. التفصيل للقريب؛ ومن بعيد يبقى القرمزي والحبر.',
        'info_brand' => 'هواجاوجي',
        'info_name' => 'موتشينغ',
        'info_color' => 'مادّر · فحم · عاجي',
        'info_style' => 'أسلوب جين',
        'info_size' => 'S–XL',
        'info_fabric' => 'قماش مختار (كما في الصورة)',
        'info_parts' => 'رداء أكمام واسعة، تنورة عند الخصر',
        'info_title' => 'بنظرة واحدة',
        'info_basics' => 'أساسي',
        'info_comfort' => 'الملمس',
        'label_brand' => 'العلامة',
        'label_name' => 'الاسم',
        'label_color' => 'اللون',
        'label_style' => 'الأسلوب',
        'label_size' => 'المقاس',
        'label_fabric' => 'القماش',
        'label_parts' => 'القطع',
        'chart_title' => 'مرجع المقاس',
        'chart_note' => 'بالسنتيمتر. القياس اليدوي قد يختلف ١–٣ سم.',
        't_fit' => 'الملاءمة',
        'h_size' => 'المقاس',
        'h_bust' => 'الصدر',
        'h_height' => 'الطول المقترح',
        'h_weight' => 'الوزن المقترح (جين)',
        'original_title' => 'حرفة أصلية',
        'original_body' => 'القصّة والزخارف أصل هواجاوجي—يرجى احترام الحرفة.',
        'close_caption' => 'موتشينغ · جين يومي',
        'alt_hero' => 'موتشينغ · طقم',
        'alt_look' => 'موتشينغ · مرتدى',
        'alt_macro' => 'موتشينغ · تفصيل',
        'alt_hem' => 'موتشينغ · ذيل',
        'alt_close' => 'موتشينغ · ختام',
        'alt_flat' => 'موتشينغ · مسطح',
        'c_soft' => 'النعومة',
        'c_soft_opts' => ['أنعم', 'متوسط', 'أصلب'],
        'c_soft_sel' => 'متوسط',
        'c_thick' => 'السماكة',
        'c_thick_opts' => ['رفيع', 'متوسط', 'سميك'],
        'c_thick_sel' => 'رفيع',
        'c_stretch' => 'المرونة',
        'c_stretch_opts' => ['بلا', 'خفيف', 'عالي'],
        'c_stretch_sel' => 'بلا',
    ],
    'bn_BD' => [
        'intro_title' => 'মুচিং',
        'intro_body' => 'জিন শৈলীর ক্রস-কলার প্রশস্ত হাতার সেট: ম্যাডার লাল ও কয়লার বৈপরীত্য, স্বচ্ছ বাইরের চাদর এবং পদ্মখচিত প্যানেল স্কার্ট—পুরুষ-নারী একই কাট, দৈনন্দিন শালীনতায়।',
        'inspire_title' => 'নামের মোটিফ',
        'inspire_lines' => ['মুচিং নামে শান্ত প্রশংসা।', 'লাল ও কালি হাড়; পদ্ম হৃদয়।'],
        'inspire_note' => '«মুচিং» নামে—ক্রস-কলার ও স্কার্টের পদ্ম একে অপরের উত্তর; মার্কেটপ্লেস ভাষা নয়।',
        'stack_title' => 'পিঠে পদ্ম',
        'stack_body' => 'সাদা পদ্ম ও প্রজাপতির রেখা পিঠে নেমে আসে; ঘুরলে স্বচ্ছ স্তর নড়ে।',
        'macro_title' => 'কাছ থেকে',
        'collar_label' => 'ক্রস কলার',
        'collar_body' => 'কলার আলো-অন্ধকারে স্তরিত; কয়লার নিচে ম্যাডার ঘাড় লম্বা করে।',
        'sleeve_label' => 'প্রশস্ত স্বচ্ছ হাতা',
        'sleeve_body' => 'হাতা স্বচ্ছ; কাফের ধার হেমের পদ্মের সঙ্গে মেলে।',
        'hem_macro_label' => 'পদ্ম প্যানেল',
        'hem_macro_body' => 'কালো প্যানেলে গোলাপি-সাদা পদ্ম; লাল-সাদা ডোরা ছন্দ রাখে।',
        'collar_title' => 'ক্রস-কলার প্রশস্ত হাতা',
        'collar_feature_body' => 'জিন কলার পরিষ্কার; প্রশস্ত হাতা ফোলা ছাড়াই খোলে। ভিতরে ম্যাডার, বাইরে কয়লা স্বচ্ছ—এক উজ্জ্বল, এক সংযত—কোমরবন্ধে ধরা।',
        'collar_feature_body2' => 'কলারের স্তর পঠনযোগ্য থাকে; স্বচ্ছ হাতা তোলা বা নামানোতে আকৃতি রাখে—জিন ভঙ্গিমা, খালি প্রসার নয়।',
        'triptych_note' => 'লাল ও কালির মাঝে মেজাজ',
        'quiet_line' => 'মুচিং কাপড়ে থাকে, শোরগোলে নয়।',
        'checklist_title' => 'মনে রাখার মতো',
        'checklist' => ['জিন ক্রস-কলার প্রশস্ত হাতার চাদর', 'লাল-কালি প্যানেল স্কার্ট', 'হেমে স্পষ্ট পদ্ম', 'পঠনযোগ্য স্বচ্ছ স্তর', 'পুরুষ-নারী একই কাট'],
        'hem_title' => 'হেমে পদ্ম',
        'hem_body' => 'কালো প্যানেল ফোটে; গোলাপি-সাদা পাপড়ি ও সবুজ কাণ্ড স্পষ্ট; লাল ডোরা জাঁক বাড়ায় কলার না কেড়ে।',
        'hem_body2' => 'হাঁটলে ভাঁজ খোলে-বন্ধ হয়—পদ্ম দেখা যায় আবার হারায়। কাছের বিস্তারিত; দূরে শুধু লাল ও কালি স্পষ্ট।',
        'info_brand' => 'হুয়াঝাওজি',
        'info_name' => 'মুচিং',
        'info_color' => 'ম্যাডার · কয়লা · আইভরি',
        'info_style' => 'জিন শৈলী',
        'info_size' => 'S–XL',
        'info_fabric' => 'নির্বাচিত কাপড় (ছবি অনুযায়ী)',
        'info_parts' => 'প্রশস্ত হাতার চাদর, কোমর স্কার্ট',
        'info_title' => 'এক নজরে',
        'info_basics' => 'মৌলিক',
        'info_comfort' => 'স্পর্শানুভূতি',
        'label_brand' => 'ব্র্যান্ড',
        'label_name' => 'নাম',
        'label_color' => 'রঙ',
        'label_style' => 'শৈলী',
        'label_size' => 'সাইজ',
        'label_fabric' => 'কাপড়',
        'label_parts' => 'অংশ',
        'chart_title' => 'সাইজ রেফারেন্স',
        'chart_note' => 'সেন্টিমিটার। হাতে মাপে ১–৩ সেমি পার্থক্য হতে পারে।',
        't_fit' => 'ফিট',
        'h_size' => 'সাইজ',
        'h_bust' => 'বুক',
        'h_height' => 'প্রস্তাবিত উচ্চতা',
        'h_weight' => 'প্রস্তাবিত ওজন (jin)',
        'original_title' => 'মূল কারুকাজ',
        'original_body' => 'কাট ও মোটিফ হুয়াঝাওজির মূল—কারুকাজকে সম্মান করুন।',
        'close_caption' => 'মুচিং · জিন দৈনন্দিন',
        'alt_hero' => 'মুচিং · সেট',
        'alt_look' => 'মুচিং · পরা',
        'alt_macro' => 'মুচিং · বিস্তারিত',
        'alt_hem' => 'মুচিং · হেম',
        'alt_close' => 'মুচিং · সমাপ্তি',
        'alt_flat' => 'মুচিং · ফ্ল্যাট',
        'c_soft' => 'নরমতা',
        'c_soft_opts' => ['আরও নরম', 'মাঝারি', 'আরও শক্ত'],
        'c_soft_sel' => 'মাঝারি',
        'c_thick' => 'পুরুত্ব',
        'c_thick_opts' => ['পাতলা', 'মাঝারি', 'মোটা'],
        'c_thick_sel' => 'পাতলা',
        'c_stretch' => 'স্থিতিস্থাপকতা',
        'c_stretch_opts' => ['নেই', 'হালকা', 'উচ্চ'],
        'c_stretch_sel' => 'নেই',
    ],
    'hi_IN' => [
        'intro_title' => 'मूचिंग',
        'intro_body' => 'जिन शैली क्रॉस-कॉलर चौड़ी आस्तीन सेट: मैडर लाल बनाम चारकोल, पारदर्शी बाहरी चादर और कमल वाले पैनल स्कर्ट—पुरुष-महिला एक कट, रोज़ के लिए शालीन।',
        'inspire_title' => 'नाम का भाव',
        'inspire_lines' => ['मूचिंग शांत प्रशंसा का नाम है।', 'लाल और स्याही हड्डी; कमल हृदय।'],
        'inspire_note' => '«मूचिंग» नाम से—क्रॉस-कॉलर और स्कर्ट के कमल एक-दूसरे का उत्तर हैं; मार्केटप्लेस भाषा नहीं।',
        'stack_title' => 'पीठ पर कमल',
        'stack_body' => 'सफेद कमल और तितली रेखाएँ पीठ पर उतरती हैं; मुड़ने पर पारदर्शी परतें हिलती हैं।',
        'macro_title' => 'पास से',
        'collar_label' => 'क्रॉस कॉलर',
        'collar_body' => 'कॉलर हल्के-गहरे में परतदार; चारकोल के नीचे मैडर गर्दन लंबी करता है।',
        'sleeve_label' => 'चौड़ी पारदर्शी आस्तीन',
        'sleeve_body' => 'आस्तीन पारदर्शी; कफ किनारा हेम के कमल से मेल खाता है।',
        'hem_macro_label' => 'कमल पैनल',
        'hem_macro_body' => 'काले पैनल पर गुलाबी-सफेद कमल; लाल-सफेद धारियाँ लय रखती हैं।',
        'collar_title' => 'क्रॉस-कॉलर चौड़ी आस्तीन',
        'collar_feature_body' => 'जिन कॉलर साफ़; चौड़ी आस्तीन बिना फूलन खोली जाती है। अंदर मैडर, बाहर चारकोल पारदर्शी—एक उज्ज्वल, एक संयमित—पट्टे से बँधा।',
        'collar_feature_body2' => 'कॉलर की परतें पढ़ी जाती हैं; पारदर्शी आस्तीन उठाते-गिराते आकार रखती हैं—जिन मुद्रा, खाली विस्तार नहीं।',
        'triptych_note' => 'लाल और स्याही के बीच मूड',
        'quiet_line' => 'मूचिंग कपड़े में रहता है, शोर में नहीं।',
        'checklist_title' => 'याद रखने योग्य',
        'checklist' => ['जिन क्रॉस-कॉलर चौड़ी आस्तीन चादर', 'लाल-स्याही पैनल स्कर्ट', 'हेम पर स्पष्ट कमल', 'पढ़ने योग्य पारदर्शी परतें', 'पुरुष-महिला एक कट'],
        'hem_title' => 'हेम पर कमल',
        'hem_body' => 'काले पैनल खिलते हैं; गुलाबी-सफेद पंखुड़ियाँ और हरी डंठल साफ़; लाल धारियाँ वैभव बढ़ाती हैं बिना कॉलर चुराए।',
        'hem_body2' => 'चलते हुए प्लीट खुलते-बंधते हैं—कमल दिखता और छिपता है। पास से विवरण; दूर से केवल लाल और स्याही साफ़।',
        'info_brand' => 'हुआझाओजी',
        'info_name' => 'मूचिंग',
        'info_color' => 'मैडर · चारकोल · आइवरी',
        'info_style' => 'जिन शैली',
        'info_size' => 'S–XL',
        'info_fabric' => 'चयनित कपड़ा (जैसा चित्र में)',
        'info_parts' => 'चौड़ी आस्तीन चादर, कमर स्कर्ट',
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
        't_fit' => 'फिट',
        'h_size' => 'साइज़',
        'h_bust' => 'छाती',
        'h_height' => 'सुझाई ऊँचाई',
        'h_weight' => 'सुझाया वज़न (jin)',
        'original_title' => 'मूल शिल्प',
        'original_body' => 'कट और रूप हुआझाओजी के मूल हैं—शिल्प का सम्मान करें।',
        'close_caption' => 'मूचिंग · जिन दैनिक',
        'alt_hero' => 'मूचिंग · सेट',
        'alt_look' => 'मूचिंग · पहना',
        'alt_macro' => 'मूचिंग · विवरण',
        'alt_hem' => 'मूचिंग · हेम',
        'alt_close' => 'मूचिंग · समापन',
        'alt_flat' => 'मूचिंग · फ्लैट',
        'c_soft' => 'कोमलता',
        'c_soft_opts' => ['अधिक कोमल', 'मध्यम', 'अधिक सख्त'],
        'c_soft_sel' => 'मध्यम',
        'c_thick' => 'मोटाई',
        'c_thick_opts' => ['पतला', 'मध्यम', 'मोटा'],
        'c_thick_sel' => 'पतला',
        'c_stretch' => 'लचीलापन',
        'c_stretch_opts' => ['नहीं', 'हल्का', 'अधिक'],
        'c_stretch_sel' => 'नहीं',
    ],
    'ur_PK' => [
        'intro_title' => 'موچنگ',
        'intro_body' => 'جین طرز کراس کالر چوڑی آستین سیٹ: میڈر سرخ بمقابلہ چارکول، بیرونی شفاف چادر اور کنول والے پینل اسکرٹ—مرد و زن ایک کٹ، روزمرہ کے لیے شائستہ۔',
        'inspire_title' => 'نام کا مفہوم',
        'inspire_lines' => ['موچنگ خاموش تحسین کا نام ہے۔', 'سرخ اور سیاہی ہڈی؛ کنول دل۔'],
        'inspire_note' => '«موچنگ» نام سے—کراس کالر اور اسکرٹ کے کنول ایک دوسرے کا جواب ہیں؛ مارکیٹ پلیس زبان نہیں۔',
        'stack_title' => 'پیٹھ پر کنول',
        'stack_body' => 'سفید کنول اور تتلی کی لکیریں پیٹھ پر اترتیں؛ مڑنے پر شفاف تہیں ہلتی ہیں۔',
        'macro_title' => 'قریب سے',
        'collar_label' => 'کراس کالر',
        'collar_body' => 'کالر ہلکے گہرے میں تہ دار؛ چارکول کے نیچے میڈر گردن لمبی کرتا ہے۔',
        'sleeve_label' => 'چوڑی شفاف آستین',
        'sleeve_body' => 'آستین شفاف؛ کف کنارہ ہیم کے کنول سے میل کھاتا ہے۔',
        'hem_macro_label' => 'کنول پینل',
        'hem_macro_body' => 'کالے پینل پر گلابی سفید کنول؛ سرخ سفید دھاریاں تال رکھتی ہیں۔',
        'collar_title' => 'کراس کالر چوڑی آستین',
        'collar_feature_body' => 'جین کالر صاف؛ چوڑی آستین بغیر پھولے کھلتی ہے۔ اندر میڈر، باہر چارکول شفاف—ایک روشن، ایک متوازن—پٹی سے بندھا۔',
        'collar_feature_body2' => 'کالر کی تہیں پڑھنے کے قابل؛ شفاف آستین اٹھاتے گراتے شکل رکھتی ہیں—جین وقار، خالی پھیلاؤ نہیں۔',
        'triptych_note' => 'سرخ اور سیاہی کے درمیان موڈ',
        'quiet_line' => 'موچنگ کپڑے میں رہتا ہے، شور میں نہیں۔',
        'checklist_title' => 'یاد رکھنے کے قابل',
        'checklist' => ['جین کراس کالر چوڑی آستین چادر', 'سرخ سیاہی پینل اسکرٹ', 'ہیم پر واضح کنول', 'پڑھنے کے قابل شفاف تہیں', 'مرد و زن ایک کٹ'],
        'hem_title' => 'ہیم پر کنول',
        'hem_body' => 'کالے پینل کھلتے ہیں؛ گلابی سفید پنکھڑیاں اور سبز ڈنٹھل واضح؛ سرخ دھاریاں شان بڑھاتی ہیں بغیر کالر چرانے۔',
        'hem_body2' => 'چلتے ہوئے پلیٹ کھلتے بند ہوتے ہیں—کنول دکھتا اور چھپتا ہے۔ قریب سے تفصیل؛ دور سے صرف سرخ اور سیاہی واضح۔',
        'info_brand' => 'ہواژاوجی',
        'info_name' => 'موچنگ',
        'info_color' => 'میڈر · چارکول · آئیوری',
        'info_style' => 'جین طرز',
        'info_size' => 'S–XL',
        'info_fabric' => 'منتخب کپڑا (جیسا تصویر میں)',
        'info_parts' => 'چوڑی آستین چادر، کمر اسکرٹ',
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
        't_fit' => 'فٹ',
        'h_size' => 'سائز',
        'h_bust' => 'سینہ',
        'h_height' => 'مجوزہ قد',
        'h_weight' => 'مجوزہ وزن (jin)',
        'original_title' => 'اصل دستکاری',
        'original_body' => 'کٹ اور نقوش ہواژاوجی کے اصل ہیں—دستکاری کا احترام کریں۔',
        'close_caption' => 'موچنگ · جین روزمرہ',
        'alt_hero' => 'موچنگ · سیٹ',
        'alt_look' => 'موچنگ · پہنا',
        'alt_macro' => 'موچنگ · تفصیل',
        'alt_hem' => 'موچنگ · ہیم',
        'alt_close' => 'موچنگ · اختتام',
        'alt_flat' => 'موچنگ · فلیٹ',
        'c_soft' => 'نرمی',
        'c_soft_opts' => ['زیادہ نرم', 'درمیانہ', 'زیادہ سخت'],
        'c_soft_sel' => 'درمیانہ',
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
    /** @var list<string> $checklist */
    $checklist = $t['checklist'];

    // fullbleed_hero · portrait paper · 单列
    $hero = $figureStack([
        $img($A['hero'], (string)$t['alt_hero'], 1500, 2000),
    ], 'weline-detail-figure-stack--fullbleed weline-detail-orient--portrait');

    $intro = '<div class="weline-detail-prose weline-detail-prose--lead"><h3>' . $h((string)$t['intro_title']) . '</h3><p>'
        . $h((string)$t['intro_body']) . '</p></div>';

    $inspire = '<div class="weline-detail-prose weline-detail-prose--verse"><h3>' . $h((string)$t['inspire_title']) . '</h3>';
    foreach ($lines as $line) {
        $inspire .= '<p>' . $h($line) . '</p>';
    }
    $inspire .= '<p class="weline-detail-feature__note">' . $h((string)$t['inspire_note']) . '</p></div>';

    // stack_caption · portrait 单列上图下文（背影莲蝶）
    $stackBack = $figureStack([
        $img($A['stack_back'], (string)$t['alt_look'] . ' 1', 1500, 2000),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['stack_title']) . '</h3><p>'
        . $h((string)$t['stack_body']) . '</p></div>';

    // stack_caption · portrait 单列
    $stack = $figureStack([
        $img($A['stack'], (string)$t['alt_look'] . ' 2', 1500, 2000),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['collar_title']) . '</h3><p>'
        . $h((string)$t['collar_feature_body']) . '</p></div>';

    // fullbleed 单列辅图
    $editorialBand = $figureStack([
        $img($A['stack_mid'], (string)$t['alt_look'] . ' 3', 1500, 2000),
    ], 'weline-detail-figure-stack--fullbleed weline-detail-orient--portrait');

    // macro：裁死后竖排（禁灰条凑横）
    $macro = $figureStack([
        $img($A['macro_sleeve'], (string)$t['alt_macro'] . ' 1', 1500, 2000),
        $img($A['macro_hem'], (string)$t['alt_macro'] . ' 2', 1500, 2000),
        $img($A['macro_back'], (string)$t['alt_macro'] . ' 3', 1400, 1260),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait')
        . '<div class="weline-detail-prose weline-detail-prose--macro"><h3>' . $h((string)$t['macro_title']) . '</h3>'
        . '<h4>' . $h((string)$t['collar_label']) . '</h4><p>' . $h((string)$t['collar_body']) . '</p>'
        . '<h4>' . $h((string)$t['sleeve_label']) . '</h4><p>' . $h((string)$t['sleeve_body']) . '</p>'
        . '<h4>' . $h((string)$t['hem_macro_label']) . '</h4><p>' . $h((string)$t['hem_macro_body']) . '</p></div>';

    // 原 feature_lr 改为竖排 HD 大图（禁糊边缩主体）
    $lookFeature = $figureStack([
        $img($A['stack_back'], (string)$t['alt_look'] . ' 4', 1500, 2000),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['collar_title']) . '</h3><p>'
        . $h((string)$t['collar_feature_body2']) . '</p></div>';

    $quiet = '<div class="weline-detail-prose weline-detail-prose--quiet"><p>' . $h((string)$t['quiet_line']) . '</p></div>';

    $check = '<div class="weline-detail-prose weline-detail-prose--checklist"><h3>' . $h((string)$t['checklist_title']) . '</h3><ul>';
    foreach ($checklist as $item) {
        $check .= '<li>' . $h($item) . '</li>';
    }
    $check .= '</ul></div>';

    $hem = $figureStack([
        $img($A['stack_b'], (string)$t['alt_hem'], 1500, 2000),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['hem_title']) . '</h3><p>'
        . $h((string)$t['hem_body']) . '</p><p>' . $h((string)$t['hem_body2']) . '</p></div>';

    $flats = $figureStack([
        $img($A['flat_black'], (string)$t['alt_flat'] . ' 1', 1080, 1080),
        $img($A['flat_red'], (string)$t['alt_flat'] . ' 2', 1080, 1080),
    ], 'weline-detail-orient--squareish');

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
                'title' => (string)$t['t_fit'],
                'headers' => [(string)$t['h_size'], (string)$t['h_bust'], (string)$t['h_height'], (string)$t['h_weight']],
                'rows' => [
                    ['S', '≤85', '155–160', '85–100'],
                    ['M', '≤88', '160–168', '95–110'],
                    ['L', '≤90', '168–175', '105–125'],
                    ['XL', '≤95', '172–180', '125–140'],
                ],
            ],
        ],
        (string)$t['chart_title'],
        (string)$t['chart_note'],
    );

    $original = '<div class="weline-detail-prose"><h3>' . $h((string)$t['original_title']) . '</h3><p>'
        . $h((string)$t['original_body']) . '</p></div>';

    $close = $figureStack([
        $img($A['close'], (string)$t['alt_close'], 1500, 2000),
    ], 'weline-detail-figure-stack--fullbleed weline-detail-orient--portrait')
        . '<div class="weline-detail-prose weline-detail-prose--quiet"><p class="weline-detail-feature__note">'
        . $h((string)$t['close_caption']) . '</p></div>';

    return '<div data-weline-product-description="1688" data-weline-detail-suite="hd-vertical-v9-denoise" data-weline-type-balance="190" data-weline-aspect="by-image" data-weline-frame="hd-vertical-no-blurfill">'
        . $hero . $intro . $inspire . $stackBack . $stack . $editorialBand . $macro
        . $lookFeature . $quiet . $check . $hem . $flats
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

$enLeakMarkers = ['Design wellspring', 'Original craft', 'At a glance', 'Hand feel', 'Close looking', 'Worth noting', 'Title motif'];

$bakedBan = [
    '7e74708b-a5eb-4f48-ba33-d869638f1ade', // detail-09 三方授权字
    'f826dcc8-f899-4852-a831-8f2e928652d1', // detail-10 尺码字板
    '4d1d648a-b65b-469d-a885-4e909992ce29', // detail-07 未抹角标
    '28b4aae5-f113-41f1-b0ae-910e1e2d950f', // detail-08 未抹角标
];

$writes = [];
foreach ($localePlan as $locale => $baseKey) {
    if (!isset($copy[$baseKey])) {
        fwrite(STDERR, "Missing locale pack: {$baseKey}\n");
        exit(2);
    }
    $html = $assemble($copy[$baseKey]);
    $banned = ['一件代发', '混批', '厂家直销', '旺旺', '货源', '阿里巴巴', '淘宝', '天猫', '拼多多', 'dropship', 'Dropship', '上家', '设计灵感', '小宁悦', '三续授权', '结发太太', '混码发货'];
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
    $featureCount = preg_match_all('/class="weline-detail-feature(?:\s[^"]*)?"/', $html) ?: 0;
    if ($featureCount > 0) {
        fwrite(STDERR, "feature_lr forbidden without true landscape (got {$featureCount}) in {$locale}\n");
        exit(2);
    }
    if (str_contains($html, 'data-weline-pad=') || str_contains($html, 'content-extend') || str_contains($html, 'paper-3x2') || str_contains($html, 'blur-3x2') || str_contains($html, 'blur-fill')) {
        fwrite(STDERR, "forbidden blur-fill / content-extend pad marker in {$locale}\n");
        exit(2);
    }
    if (str_contains($html, 'paper-solid') || str_contains($html, '#f7f4ef')) {
        fwrite(STDERR, "forbidden paper-solid pad marker in {$locale}\n");
        exit(2);
    }
    if (str_contains($html, 'weline-detail-figure-row--triptych')) {
        fwrite(STDERR, "triptych forbidden in {$locale}\n");
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
        'stack_caption' => substr_count($html, 'weline-detail-figure-stack--caption'),
        'solo' => substr_count($html, 'weline-detail-figure-row--solo'),
    ];
}

foreach ($writes as $w) {
    echo sprintf(
        "%s\t%d\tfeature=%d\timgs=%d\ttriptych=%d\tfullbleed=%d\tstack_caption=%d\tsolo=%d\n",
        $w['locale'] === '' ? '(empty)' : $w['locale'],
        $w['len'],
        $w['features'],
        $w['imgs'],
        $w['triptych'],
        $w['fullbleed'],
        $w['stack_caption'],
        $w['solo'],
    );
}

if (!$apply) {
    echo "Dry-run. Pass --apply to write description only.\n";
    echo "Prototype sequence (+orientation): fullbleed_hero[portrait] → editorial → stack_caption[portrait]×2 → fullbleed[portrait] → macro[portrait solo-stack anti-blurfill] → stack look → quiet → checklist → hem[portrait] → pair_flats → spec → size → editorial → fullbleed_close[portrait]\n";
    echo "扩图：禁 blur-fill；仅 HD 竖排或真 outpaint\n";
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
    'detail_suite_variety_190',
    ['product_ids' => [$productId]],
);
ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class)
    ->clearForCatalogChange('detail_suite_variety_190');

echo "Applied description-only writes: " . count($writes) . " locales.\n";
