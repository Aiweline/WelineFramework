<?php

declare(strict_types=1);

/**
 * #197 龙腾 — ecommerce-detail-suite
 * 仅详情 · 古风 · 竖排 HD · 抹烤字字板 · 启用 locale 各语真译
 *
 * 分流：01/12 烤字、02–05 字板、08/09/10/11 货盘拼版（白缝切脸）→ 删图；
 * 仅 06/07 + gallery 净单帧 HD 入楼层（拼版≠美学）。
 * 原型：editorial → fullbleed → stack → prose → pair colors → stack → quiet → checklist → spec
 * feature_lr=0；禁拼版/细条/文案切脸
 *
 * php app/code/Weline/Product/scripts/beautify-product-197-detail-layout.php --apply
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
$productId = 197;

$A = [
    'look06' => ['id' => '7beac2ec-8e47-4b5f-9435-cada9727fe0e', 'w' => 1600, 'h' => 1988],
    'dragon' => ['id' => '2d16cfe8-35d9-4f9e-83e7-9f825e766095', 'w' => 1600, 'h' => 1990],
    'macro' => ['id' => '087ee3a3-1d08-440d-9468-3df340a35d25', 'w' => 1600, 'h' => 1988],
    'blueLooks' => ['id' => '2814f321-0ab4-4f73-8b89-f001ca7c71b1', 'w' => 1600, 'h' => 1988],
    'colorPair' => ['id' => '077f8fbd-b8c1-4ee0-9d61-9202b4167d6a', 'w' => 1600, 'h' => 1988],
    'gDark' => ['id' => '1995fdf8-7a72-4059-9d11-64c7cc214d7f', 'w' => 1600, 'h' => 1600],
    'gBlue' => ['id' => 'bd01ee85-6c94-48d6-b7e8-cea5406f0362', 'w' => 1600, 'h' => 1600],
    'gPink' => ['id' => 'e2c5d7d6-88ea-4746-b78f-37c39152db19', 'w' => 1600, 'h' => 1600],
    'gDark2' => ['id' => '6dfb8678-1f5f-40ef-839b-baa58019b8c1', 'w' => 1600, 'h' => 1600],
];

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$img = static function (string $assetId, string $alt, int $w, int $hgt) use ($h): string {
    return '<img src="asset://' . $h($assetId) . '" alt="' . $h($alt)
        . '" loading="lazy" decoding="async" width="' . $w . '" height="' . $hgt . '">';
};

$figureStack = static function (array $imgs, string $mod = '') : string {
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

    return '<div class="' . $cls . '">' . $rows . '</div>';
};

$copy = [
    'zh_Hans_CN' => [
        'intro_title' => '龙腾',
        'intro_body' => '魏晋制齐腰交领三件套：大袖衫、中衣与齐腰裙。石青、浅蓝与粉红渐变三色可选；背身龙腾、广袖竹枝，随步生风。',
        'inspire_title' => '设计心源',
        'inspire_lines' => ['黄花笑逐臣，醉看风落帽，舞爱月留人。', '九日龙山饮，杯中有远意。'],
        'inspire_note' => '取古诗句意点题「龙腾」气度——仅为形制与纹样之题眼，非平台货盘说辞。',
        'highlight_title' => '衣袂要点',
        'dragon_label' => '背身龙腾',
        'dragon_body' => '龙纹翻涌于云水之间，近观层次分明，远望气势开张。',
        'sleeve_label' => '广袖竹枝',
        'sleeve_body' => '大袖宽纾，袖袂铺陈金竹枝叶，举手可见清影。',
        'collar_label' => '交领中衣',
        'collar_body' => '齐腰束结，交领相叠；内领菱格纹细润平整。',
        'colors_title' => '三色气韵',
        'colors_body' => '石青沉毅、浅蓝清朗、粉红渐变明艳——同一形制，三种风骨。',
        'macro_title' => '纹样近观',
        'macro_body' => '裾边云水与龙纹交织，竹枝点缀其间，印花细润可读。',
        'blue_title' => '浅蓝着装',
        'blue_body' => '浅蓝大袖配油纸伞，裾边龙纹随步起伏。',
        'original_title' => '原创心迹',
        'original_body' => '本款形制与纹样为花朝记原创设计，敬请珍惜衣冠、尊重匠心。',
        'wash_title' => '护衣小笺',
        'wash_lines' => ['建议手洗，分色洗涤，不可漂白。', '悬挂晾干，避暴晒；低温熨烫，垫布为佳。'],
        'info_brand' => '花朝记',
        'info_name' => '龙腾',
        'info_color' => '石青 · 浅蓝 · 粉红渐变',
        'info_style' => '魏晋制',
        'info_size' => 'S–2XL',
        'info_fabric' => '四面弹 / 涤纶',
        'info_parts' => '大袖衫、中衣、齐腰裙',
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
        'chart_note' => '单位：厘米；体重为市斤。手工测量或有一至三厘米出入；请以页面尺码选择为准。',
        'h_size' => '尺码',
        'h_bust' => '胸围',
        'h_height' => '建议身高',
        'h_weight' => '建议体重',
        'alt_dragon' => '龙腾 · 背身龙纹',
        'alt_look' => '龙腾 · 着装',
        'alt_macro' => '龙腾 · 纹样',
        'alt_color' => '龙腾 · 配色',
        'c_thick' => '厚度',
        'c_thick_opts' => ['薄', '适中', '厚'],
        'c_thick_sel' => '薄',
        'c_fit' => '版型',
        'c_fit_opts' => ['紧身', '修身', '适中', '宽松'],
        'c_fit_sel' => '修身',
        'c_soft' => '柔软',
        'c_soft_opts' => ['适中', '柔软', '微硬', '硬'],
        'c_soft_sel' => '柔软',
        'c_stretch' => '弹性',
        'c_stretch_opts' => ['无弹', '微弹', '高弹'],
        'c_stretch_sel' => '微弹',
    ],
    'en_US' => [
        'intro_title' => 'Dragon Rise',
        'intro_body' => 'A Wei–Jin waist-high cross-collar triad: wide-sleeve robe, inner layer, and waist skirt. Choose stone teal, light blue, or pink gradient—dragon on the back, bamboo on the sleeves.',
        'inspire_title' => 'Design wellspring',
        'inspire_lines' => ['Yellow flowers smile on the exile; wind lifts the hat; dance asks the moon to stay.', 'On the ninth day we drink at Dragon Mountain—distance in the cup.'],
        'inspire_note' => 'Verse as emblem of spirit—not marketplace copy.',
        'highlight_title' => 'On the garment',
        'dragon_label' => 'Dragon on the back',
        'dragon_body' => 'A rising dragon among clouds and waves—clear up close, bold from afar.',
        'sleeve_label' => 'Bamboo on wide sleeves',
        'sleeve_body' => 'Broad sleeves carry golden bamboo sprays that catch the light when you lift an arm.',
        'collar_label' => 'Cross collar',
        'collar_body' => 'Waist sash and layered collars; the inner lattice edge stays neat.',
        'colors_title' => 'Three moods',
        'colors_body' => 'Stone teal, light blue, pink gradient—one cut, three temperaments.',
        'macro_title' => 'Close print',
        'macro_body' => 'Cloud-water and dragon weave at the hem; bamboo accents stay readable.',
        'blue_title' => 'In light blue',
        'blue_body' => 'Light blue robe with oil-paper umbrella; the dragon hem moves with each step.',
        'original_title' => 'Original craft',
        'original_body' => 'Cut and print are Huazhaoji originals. Honor the craft.',
        'wash_title' => 'Care notes',
        'wash_lines' => ['Hand wash preferred; separate colors; no bleach.', 'Hang dry; avoid harsh sun; low iron with a cloth.'],
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Dragon Rise',
        'info_color' => 'Stone teal · light blue · pink gradient',
        'info_style' => 'Wei–Jin',
        'info_size' => 'S–2XL',
        'info_fabric' => '4-way stretch / polyester',
        'info_parts' => 'Wide-sleeve robe, inner layer, waist skirt',
        'info_title' => 'At a glance',
        'info_basics' => 'Basics',
        'info_comfort' => 'Feel',
        'label_brand' => 'Brand',
        'label_name' => 'Name',
        'label_color' => 'Colors',
        'label_style' => 'Style',
        'label_size' => 'Sizes',
        'label_fabric' => 'Fabric',
        'label_parts' => 'Pieces',
        'chart_title' => 'Size reference',
        'chart_note' => 'Centimeters; weight in jin (½ kg). Hand measure may vary 1–3 cm; follow the page size picker.',
        'h_size' => 'Size',
        'h_bust' => 'Bust',
        'h_height' => 'Height',
        'h_weight' => 'Weight (jin)',
        'alt_dragon' => 'Dragon Rise · back',
        'alt_look' => 'Dragon Rise · worn',
        'alt_macro' => 'Dragon Rise · print',
        'alt_color' => 'Dragon Rise · colors',
        'c_thick' => 'Thickness',
        'c_thick_opts' => ['Thin', 'Medium', 'Thick'],
        'c_thick_sel' => 'Thin',
        'c_fit' => 'Fit',
        'c_fit_opts' => ['Tight', 'Slim', 'Regular', 'Loose'],
        'c_fit_sel' => 'Slim',
        'c_soft' => 'Hand',
        'c_soft_opts' => ['Medium', 'Soft', 'Firm', 'Hard'],
        'c_soft_sel' => 'Soft',
        'c_stretch' => 'Stretch',
        'c_stretch_opts' => ['None', 'Slight', 'High'],
        'c_stretch_sel' => 'Slight',
    ],
    'es_ES' => [
        'intro_title' => 'Dragón Ascendente',
        'intro_body' => 'Tríada Wei–Jin de cintura alta y cuello cruzado: túnica de mangas amplias, capa interior y falda. Elige verde pétreo, azul claro o degradado rosa—dragón en la espalda, bambú en las mangas.',
        'inspire_title' => 'Fuente del diseño',
        'inspire_lines' => ['Flores amarillas sonríen al exiliado; el viento alza el sombrero; la danza pide a la luna quedarse.', 'Al noveno día bebemos en la Montaña del Dragón.'],
        'inspire_note' => 'Versos como emblema del espíritu—no jerga de plataforma.',
        'highlight_title' => 'En la prenda',
        'dragon_label' => 'Dragón en la espalda',
        'dragon_body' => 'Un dragón entre nubes y olas—nítido de cerca, imponente de lejos.',
        'sleeve_label' => 'Bambú en mangas amplias',
        'sleeve_body' => 'Las mangas anchas llevan ramas doradas de bambú que brillan al alzar el brazo.',
        'collar_label' => 'Cuello cruzado',
        'collar_body' => 'Faja a la cintura y cuellos superpuestos; el borde interior queda limpio.',
        'colors_title' => 'Tres temperamentos',
        'colors_body' => 'Verde pétreo, azul claro, degradado rosa—un corte, tres aires.',
        'macro_title' => 'Estampado de cerca',
        'macro_body' => 'Nubes, agua y dragón en el bajo; el bambú se lee con claridad.',
        'blue_title' => 'En azul claro',
        'blue_body' => 'Túnica azul clara con parasol de papel; el bajo del dragón sigue el paso.',
        'original_title' => 'Oficio original',
        'original_body' => 'Corte y estampado son originales de Huazhaoji. Honrad el oficio.',
        'wash_title' => 'Cuidado',
        'wash_lines' => ['Lavado a mano; separar colores; no blanquear.', 'Secar colgado; evitar sol fuerte; plancha baja con paño.'],
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Dragón Ascendente',
        'info_color' => 'Verde pétreo · azul claro · degradado rosa',
        'info_style' => 'Wei–Jin',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Elástico 4 vías / poliéster',
        'info_parts' => 'Túnica de mangas amplias, capa interior, falda',
        'info_title' => 'De un vistazo',
        'info_basics' => 'Básicos',
        'info_comfort' => 'Tacto',
        'label_brand' => 'Marca',
        'label_name' => 'Nombre',
        'label_color' => 'Colores',
        'label_style' => 'Estilo',
        'label_size' => 'Tallas',
        'label_fabric' => 'Tela',
        'label_parts' => 'Piezas',
        'chart_title' => 'Guía de tallas',
        'chart_note' => 'Centímetros; peso en jin (½ kg). La medida a mano puede variar 1–3 cm.',
        'h_size' => 'Talla',
        'h_bust' => 'Busto',
        'h_height' => 'Altura',
        'h_weight' => 'Peso (jin)',
        'alt_dragon' => 'Dragón Ascendente · espalda',
        'alt_look' => 'Dragón Ascendente · puesto',
        'alt_macro' => 'Dragón Ascendente · estampado',
        'alt_color' => 'Dragón Ascendente · colores',
        'c_thick' => 'Grosor',
        'c_thick_opts' => ['Fina', 'Media', 'Gruesa'],
        'c_thick_sel' => 'Fina',
        'c_fit' => 'Corte',
        'c_fit_opts' => ['Ajustado', 'Slim', 'Regular', 'Holgado'],
        'c_fit_sel' => 'Slim',
        'c_soft' => 'Tacto',
        'c_soft_opts' => ['Medio', 'Suave', 'Firme', 'Duro'],
        'c_soft_sel' => 'Suave',
        'c_stretch' => 'Elasticidad',
        'c_stretch_opts' => ['Nula', 'Ligera', 'Alta'],
        'c_stretch_sel' => 'Ligera',
    ],
    'fr_FR' => [
        'intro_title' => 'Dragon Ascendant',
        'intro_body' => 'Triade Wei–Jin à taille haute et col croisé : robe à larges manches, couche intérieure et jupe. Teal pierre, bleu clair ou dégradé rose—dragon au dos, bambou aux manches.',
        'inspire_title' => 'Source du dessin',
        'inspire_lines' => ['Fleurs jaunes sourient à l’exilé ; le vent soulève le chapeau ; la danse prie la lune de rester.', 'Au neuvième jour, on boit au Mont du Dragon.'],
        'inspire_note' => 'Vers comme emblème d’esprit—pas du jargon de plateforme.',
        'highlight_title' => 'Sur le vêtement',
        'dragon_label' => 'Dragon au dos',
        'dragon_body' => 'Un dragon parmi nuages et vagues—lisible de près, imposant de loin.',
        'sleeve_label' => 'Bambou aux larges manches',
        'sleeve_body' => 'Les larges manches portent des jets de bambou doré qui captent la lumière.',
        'collar_label' => 'Col croisé',
        'collar_body' => 'Ceinture haute et cols superposés ; le bord intérieur reste net.',
        'colors_title' => 'Trois tempéraments',
        'colors_body' => 'Teal pierre, bleu clair, dégradé rose—une coupe, trois airs.',
        'macro_title' => 'Motif de près',
        'macro_body' => 'Nuages, eau et dragon à l’ourlet ; le bambou reste lisible.',
        'blue_title' => 'En bleu clair',
        'blue_body' => 'Robe bleu clair et ombrelle de papier ; l’ourlet dragon suit le pas.',
        'original_title' => 'Savoir-faire original',
        'original_body' => 'Coupe et motif sont originaux Huazhaoji. Honorez le métier.',
        'wash_title' => 'Entretien',
        'wash_lines' => ['Lavage à la main ; séparer les couleurs ; pas d’eau de Javel.', 'Séchage à l’air ; éviter le soleil fort ; fer bas avec un tissu.'],
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Dragon Ascendant',
        'info_color' => 'Teal pierre · bleu clair · dégradé rose',
        'info_style' => 'Wei–Jin',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Stretch 4 sens / polyester',
        'info_parts' => 'Robe à larges manches, couche intérieure, jupe',
        'info_title' => 'En un coup d’œil',
        'info_basics' => 'Bases',
        'info_comfort' => 'Toucher',
        'label_brand' => 'Marque',
        'label_name' => 'Nom',
        'label_color' => 'Couleurs',
        'label_style' => 'Style',
        'label_size' => 'Tailles',
        'label_fabric' => 'Tissu',
        'label_parts' => 'Pièces',
        'chart_title' => 'Guide des tailles',
        'chart_note' => 'Centimètres ; poids en jin (½ kg). Mesure à la main ±1–3 cm.',
        'h_size' => 'Taille',
        'h_bust' => 'Poitrine',
        'h_height' => 'Taille',
        'h_weight' => 'Poids (jin)',
        'alt_dragon' => 'Dragon Ascendant · dos',
        'alt_look' => 'Dragon Ascendant · porté',
        'alt_macro' => 'Dragon Ascendant · motif',
        'alt_color' => 'Dragon Ascendant · couleurs',
        'c_thick' => 'Épaisseur',
        'c_thick_opts' => ['Fine', 'Moyenne', 'Épaisse'],
        'c_thick_sel' => 'Fine',
        'c_fit' => 'Coupe',
        'c_fit_opts' => ['Serrée', 'Slim', 'Régulière', 'Ample'],
        'c_fit_sel' => 'Slim',
        'c_soft' => 'Main',
        'c_soft_opts' => ['Moyenne', 'Douce', 'Ferme', 'Raide'],
        'c_soft_sel' => 'Douce',
        'c_stretch' => 'Élasticité',
        'c_stretch_opts' => ['Nulle', 'Légère', 'Forte'],
        'c_stretch_sel' => 'Légère',
    ],
    'pt_BR' => [
        'intro_title' => 'Dragão Ascendente',
        'intro_body' => 'Tríade Wei–Jin de cintura alta e gola cruzada: túnica de mangas amplas, camada interna e saia. Escolha teal pedra, azul claro ou degradê rosa—dragão nas costas, bambu nas mangas.',
        'inspire_title' => 'Nascente do desenho',
        'inspire_lines' => ['Flores amarelas sorriem ao exilado; o vento ergue o chapéu; a dança pede à lua que fique.', 'No nono dia bebemos no Monte do Dragão.'],
        'inspire_note' => 'Versos como emblema de espírito—não jargão de plataforma.',
        'highlight_title' => 'Na peça',
        'dragon_label' => 'Dragão nas costas',
        'dragon_body' => 'Um dragão entre nuvens e ondas—nítido de perto, imponente de longe.',
        'sleeve_label' => 'Bambu nas mangas amplas',
        'sleeve_body' => 'Mangas largas com jatos dourados de bambu que captam a luz.',
        'collar_label' => 'Gola cruzada',
        'collar_body' => 'Faixa na cintura e golas sobrepostas; a borda interna fica limpa.',
        'colors_title' => 'Três temperamentos',
        'colors_body' => 'Teal pedra, azul claro, degradê rosa—um corte, três ares.',
        'macro_title' => 'Estampa de perto',
        'macro_body' => 'Nuvens, água e dragão na barra; o bambu permanece legível.',
        'blue_title' => 'Em azul claro',
        'blue_body' => 'Túnica azul clara com sombrinha de papel; a barra do dragão segue o passo.',
        'original_title' => 'Ofício original',
        'original_body' => 'Corte e estampa são originais Huazhaoji. Honre o ofício.',
        'wash_title' => 'Cuidados',
        'wash_lines' => ['Lavar à mão; separar cores; sem alvejante.', 'Secar pendurado; evitar sol forte; ferro baixo com pano.'],
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Dragão Ascendente',
        'info_color' => 'Teal pedra · azul claro · degradê rosa',
        'info_style' => 'Wei–Jin',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Elastano 4 vias / poliéster',
        'info_parts' => 'Túnica de mangas amplas, camada interna, saia',
        'info_title' => 'De relance',
        'info_basics' => 'Básicos',
        'info_comfort' => 'Toque',
        'label_brand' => 'Marca',
        'label_name' => 'Nome',
        'label_color' => 'Cores',
        'label_style' => 'Estilo',
        'label_size' => 'Tamanhos',
        'label_fabric' => 'Tecido',
        'label_parts' => 'Peças',
        'chart_title' => 'Guia de tamanhos',
        'chart_note' => 'Centímetros; peso em jin (½ kg). Medida à mão pode variar 1–3 cm.',
        'h_size' => 'Tam.',
        'h_bust' => 'Busto',
        'h_height' => 'Altura',
        'h_weight' => 'Peso (jin)',
        'alt_dragon' => 'Dragão Ascendente · costas',
        'alt_look' => 'Dragão Ascendente · vestido',
        'alt_macro' => 'Dragão Ascendente · estampa',
        'alt_color' => 'Dragão Ascendente · cores',
        'c_thick' => 'Espessura',
        'c_thick_opts' => ['Fino', 'Médio', 'Grosso'],
        'c_thick_sel' => 'Fino',
        'c_fit' => 'Caimento',
        'c_fit_opts' => ['Apertado', 'Slim', 'Regular', 'Folgado'],
        'c_fit_sel' => 'Slim',
        'c_soft' => 'Toque',
        'c_soft_opts' => ['Médio', 'Macio', 'Firme', 'Duro'],
        'c_soft_sel' => 'Macio',
        'c_stretch' => 'Elasticidade',
        'c_stretch_opts' => ['Nenhuma', 'Leve', 'Alta'],
        'c_stretch_sel' => 'Leve',
    ],
    'id_ID' => [
        'intro_title' => 'Naga Bangkit',
        'intro_body' => 'Triad Wei–Jin pinggang tinggi kerah silang: jubah lengan lebar, lapisan dalam, dan rok. Pilih teal batu, biru muda, atau gradasi merah muda—naga di punggung, bambu di lengan.',
        'inspire_title' => 'Sumber desain',
        'inspire_lines' => ['Bunga kuning tersenyum pada pengasingan; angin mengangkat topi; tarian meminta bulan tinggal.', 'Pada hari kesembilan kami minum di Gunung Naga.'],
        'inspire_note' => 'Syair sebagai lambang semangat—bukan jargon marketplace.',
        'highlight_title' => 'Pada busana',
        'dragon_label' => 'Naga di punggung',
        'dragon_body' => 'Naga di antara awan dan ombak—jelas dari dekat, gagah dari jauh.',
        'sleeve_label' => 'Bambu di lengan lebar',
        'sleeve_body' => 'Lengan lebar membawa cabang bambu emas yang menangkap cahaya.',
        'collar_label' => 'Kerah silang',
        'collar_body' => 'Ikat pinggang dan kerah bertumpuk; tepi dalam tetap rapi.',
        'colors_title' => 'Tiga temperamen',
        'colors_body' => 'Teal batu, biru muda, gradasi merah muda—satu potongan, tiga suasana.',
        'macro_title' => 'Motif dekat',
        'macro_body' => 'Awan, air, dan naga di hem; bambu tetap terbaca.',
        'blue_title' => 'Biru muda',
        'blue_body' => 'Jubah biru muda dengan payung kertas; hem naga mengikuti langkah.',
        'original_title' => 'Kriya asli',
        'original_body' => 'Potongan dan motif asli Huazhaoji. Hormati kriya.',
        'wash_title' => 'Perawatan',
        'wash_lines' => ['Cuci tangan; pisahkan warna; tanpa pemutih.', 'Keringkan digantung; hindari matahari terik; setrika rendah dengan kain.'],
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Naga Bangkit',
        'info_color' => 'Teal batu · biru muda · gradasi merah muda',
        'info_style' => 'Wei–Jin',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Stretch 4 arah / poliester',
        'info_parts' => 'Jubah lengan lebar, lapisan dalam, rok',
        'info_title' => 'Sekilas',
        'info_basics' => 'Dasar',
        'info_comfort' => 'Rasa',
        'label_brand' => 'Merek',
        'label_name' => 'Nama',
        'label_color' => 'Warna',
        'label_style' => 'Gaya',
        'label_size' => 'Ukuran',
        'label_fabric' => 'Kain',
        'label_parts' => 'Bagian',
        'chart_title' => 'Panduan ukuran',
        'chart_note' => 'Sentimeter; berat dalam jin (½ kg). Ukur tangan bisa beda 1–3 cm.',
        'h_size' => 'Ukuran',
        'h_bust' => 'Dada',
        'h_height' => 'Tinggi',
        'h_weight' => 'Berat (jin)',
        'alt_dragon' => 'Naga Bangkit · punggung',
        'alt_look' => 'Naga Bangkit · dikenakan',
        'alt_macro' => 'Naga Bangkit · motif',
        'alt_color' => 'Naga Bangkit · warna',
        'c_thick' => 'Ketebalan',
        'c_thick_opts' => ['Tipis', 'Sedang', 'Tebal'],
        'c_thick_sel' => 'Tipis',
        'c_fit' => 'Potongan',
        'c_fit_opts' => ['Ketat', 'Slim', 'Regular', 'Longgar'],
        'c_fit_sel' => 'Slim',
        'c_soft' => 'Sentuhan',
        'c_soft_opts' => ['Sedang', 'Lembut', 'Tegas', 'Kaku'],
        'c_soft_sel' => 'Lembut',
        'c_stretch' => 'Elastisitas',
        'c_stretch_opts' => ['Tidak', 'Sedikit', 'Tinggi'],
        'c_stretch_sel' => 'Sedikit',
    ],
    'ar_SA' => [
        'intro_title' => 'صعود التنين',
        'intro_body' => 'طقم وي–جين بخصر عالٍ وياقة متقاطعة: رداء بأكمام واسعة وطبقة داخلية وتنورة. اختر فيروزيًا حجريًا أو أزرق فاتح أو تدرجًا ورديًا—تنين على الظهر وخيزران على الأكمام.',
        'inspire_title' => 'منبع التصميم',
        'inspire_lines' => ['زهور صفراء تبتسم للمنفي؛ الريح ترفع القبعة؛ الرقص يطلب من القمر البقاء.', 'في اليوم التاسع نشرب على جبل التنين.'],
        'inspire_note' => 'أبيات كرمز للروح—لا كلام سوق.',
        'highlight_title' => 'على الثوب',
        'dragon_label' => 'تنين على الظهر',
        'dragon_body' => 'تنين بين سحب وأمواج—واضح من قرب، مهيب من بعيد.',
        'sleeve_label' => 'خيزران على الأكمام الواسعة',
        'sleeve_body' => 'أكمام عريضة تحمل أغصان خيزران ذهبية تلتقط الضوء.',
        'collar_label' => 'ياقة متقاطعة',
        'collar_body' => 'حزام خصر وطبقات ياقات؛ الحافة الداخلية نظيفة.',
        'colors_title' => 'ثلاثة مزاجات',
        'colors_body' => 'فيروزي حجري، أزرق فاتح، تدرج وردي—قصّة واحدة وثلاث أرواح.',
        'macro_title' => 'نقش عن قرب',
        'macro_body' => 'سحب وماء وتنين عند الذيل؛ الخيزران يبقى مقروءًا.',
        'blue_title' => 'بالأزرق الفاتح',
        'blue_body' => 'رداء أزرق فاتح مع مظلة ورقية؛ ذيل التنين يتبع الخطوة.',
        'original_title' => 'حرفة أصلية',
        'original_body' => 'القص والنقش أصليان من هواژاوجي. احترم الحرفة.',
        'wash_title' => 'العناية',
        'wash_lines' => ['غسل يدوي؛ فصل الألوان؛ بلا مبيّض.', 'تجفيف معلّق؛ تجنب شمس قوية؛ كي خفيف مع قماش.'],
        'info_brand' => 'هواژاوجي',
        'info_name' => 'صعود التنين',
        'info_color' => 'فيروزي حجري · أزرق فاتح · تدرج وردي',
        'info_style' => 'وي–جين',
        'info_size' => 'S–2XL',
        'info_fabric' => 'مطاط رباعي / بوليستر',
        'info_parts' => 'رداء بأكمام واسعة، طبقة داخلية، تنورة',
        'info_title' => 'لمحة',
        'info_basics' => 'أساسي',
        'info_comfort' => 'الملمس',
        'label_brand' => 'العلامة',
        'label_name' => 'الاسم',
        'label_color' => 'الألوان',
        'label_style' => 'الطراز',
        'label_size' => 'المقاسات',
        'label_fabric' => 'القماش',
        'label_parts' => 'القطع',
        'chart_title' => 'مرجع المقاس',
        'chart_note' => 'سنتيمتر؛ الوزن بالجين (½ كغ). القياس اليدوي قد يختلف ١–٣ سم.',
        'h_size' => 'مقاس',
        'h_bust' => 'صدر',
        'h_height' => 'طول',
        'h_weight' => 'وزن (جين)',
        'alt_dragon' => 'صعود التنين · ظهر',
        'alt_look' => 'صعود التنين · مرتدى',
        'alt_macro' => 'صعود التنين · نقش',
        'alt_color' => 'صعود التنين · ألوان',
        'c_thick' => 'السماكة',
        'c_thick_opts' => ['رفيع', 'متوسط', 'سميك'],
        'c_thick_sel' => 'رفيع',
        'c_fit' => 'القصة',
        'c_fit_opts' => ['ضيق', 'نحيف', 'عادي', 'واسع'],
        'c_fit_sel' => 'نحيف',
        'c_soft' => 'الملمس',
        'c_soft_opts' => ['متوسط', 'ناعم', 'متماسك', 'قاس'],
        'c_soft_sel' => 'ناعم',
        'c_stretch' => 'المرونة',
        'c_stretch_opts' => ['لا', 'خفيف', 'عالٍ'],
        'c_stretch_sel' => 'خفيف',
    ],
    'bn_BD' => [
        'intro_title' => 'ড্রাগন উত্থান',
        'intro_body' => 'ওয়েই–জিন কোমর-উঁচু ক্রস-কলার তিন অংশ: চওড়া হাতার পোশাক, ভিতরের স্তর ও স্কার্ট। পাথুরে টিল, হালকা নীল বা গোলাপি গ্রেডিয়েন্ট—পিঠে ড্রাগন, হাতায় বাঁশ।',
        'inspire_title' => 'ডিজাইনের উৎস',
        'inspire_lines' => ['হলুদ ফুল নির্বাসিতকে হাসে; বাতাস টুপি তোলে; নৃত্য চাঁদকে থাকতে বলে।', 'নবম দিনে আমরা ড্রাগন পাহাড়ে পান করি।'],
        'inspire_note' => 'ভাবের প্রতীক হিসেবে পদ্য—মার্কেটপ্লেসের ভাষা নয়।',
        'highlight_title' => 'পোশাকে',
        'dragon_label' => 'পিঠে ড্রাগন',
        'dragon_body' => 'মেঘ-ঢেউয়ের মাঝে ড্রাগন—কাছ থেকে স্পষ্ট, দূর থেকে গম্ভীর।',
        'sleeve_label' => 'চওড়া হাতায় বাঁশ',
        'sleeve_body' => 'চওড়া হাতায় সোনালি বাঁশের ডাল আলো ধরে।',
        'collar_label' => 'ক্রস কলার',
        'collar_body' => 'কোমরবন্ধ ও স্তরিত কলার; ভিতরের প্রান্ত পরিষ্কার।',
        'colors_title' => 'তিন মেজাজ',
        'colors_body' => 'পাথুরে টিল, হালকা নীল, গোলাপি গ্রেডিয়েন্ট—এক কাট, তিন ভাব।',
        'macro_title' => 'কাছের ছাপ',
        'macro_body' => 'হেমে মেঘ-জল-ড্রাগন; বাঁশ পড়া যায়।',
        'blue_title' => 'হালকা নীলে',
        'blue_body' => 'হালকা নীল পোশাক ও কাগজের ছাতা; ড্রাগন হেম পায়ে পায়ে ওঠে।',
        'original_title' => 'মূল কারুকাজ',
        'original_body' => 'কাট ও ছাপ হুয়াঝাওজির মূল। কারুকাজ সম্মান করুন।',
        'wash_title' => 'যত্ন',
        'wash_lines' => ['হাতে ধোয়া; রং আলাদা; ব্লিচ নয়।', 'ঝুলিয়ে শুকান; তীব্র রোদ এড়ান; কাপড় দিয়ে কম তাপে ইস্ত্রি।'],
        'info_brand' => 'Huazhaoji',
        'info_name' => 'ড্রাগন উত্থান',
        'info_color' => 'পাথুরে টিল · হালকা নীল · গোলাপি গ্রেডিয়েন্ট',
        'info_style' => 'ওয়েই–জিন',
        'info_size' => 'S–2XL',
        'info_fabric' => '৪-দিক স্ট্রেচ / পলিয়েস্টার',
        'info_parts' => 'চওড়া হাতার পোশাক, ভিতরের স্তর, স্কার্ট',
        'info_title' => 'এক নজরে',
        'info_basics' => 'মৌলিক',
        'info_comfort' => 'অনুভূতি',
        'label_brand' => 'ব্র্যান্ড',
        'label_name' => 'নাম',
        'label_color' => 'রং',
        'label_style' => 'শৈলী',
        'label_size' => 'সাইজ',
        'label_fabric' => 'কাপড়',
        'label_parts' => 'অংশ',
        'chart_title' => 'সাইজ নির্দেশ',
        'chart_note' => 'সেন্টিমিটার; ওজন জিনে (½ কেজি)। হাতে মাপে ১–৩ সেমি ফারাক হতে পারে।',
        'h_size' => 'সাইজ',
        'h_bust' => 'বুক',
        'h_height' => 'উচ্চতা',
        'h_weight' => 'ওজন (জিন)',
        'alt_dragon' => 'ড্রাগন উত্থান · পিঠ',
        'alt_look' => 'ড্রাগন উত্থান · পরা',
        'alt_macro' => 'ড্রাগন উত্থান · ছাপ',
        'alt_color' => 'ড্রাগন উত্থান · রং',
        'c_thick' => 'পুরুত্ব',
        'c_thick_opts' => ['পাতলা', 'মাঝারি', 'মোটা'],
        'c_thick_sel' => 'পাতলা',
        'c_fit' => 'ফিট',
        'c_fit_opts' => ['টাইট', 'স্লিম', 'নিয়মিত', 'ঢিলা'],
        'c_fit_sel' => 'স্লিম',
        'c_soft' => 'স্পর্শ',
        'c_soft_opts' => ['মাঝারি', 'নরম', 'দৃঢ়', 'শক্ত'],
        'c_soft_sel' => 'নরম',
        'c_stretch' => 'ইলাস্টিসিটি',
        'c_stretch_opts' => ['নেই', 'সামান্য', 'বেশি'],
        'c_stretch_sel' => 'সামান্য',
    ],
    'hi_IN' => [
        'intro_title' => 'ड्रैगन उदय',
        'intro_body' => 'वेई–जिन कमर-ऊँची क्रॉस-कॉलर तिकड़ी: चौड़ी आस्तीन की पोशाक, अंदर की परत और स्कर्ट। पत्थरी टील, हल्का नीला या गुलाबी ग्रेडिएंट—पीठ पर ड्रैगन, आस्तीन पर बाँस।',
        'inspire_title' => 'डिज़ाइन स्रोत',
        'inspire_lines' => ['पीले फूल निर्वासित पर मुस्कुराते हैं; हवा टोपी उठाती है; नृत्य चाँद से ठहरने को कहता है।', 'नवें दिन हम ड्रैगन पर्वत पर पीते हैं।'],
        'inspire_note' => 'भाव के प्रतीक के रूप में पद्य—बाज़ार की भाषा नहीं।',
        'highlight_title' => 'वस्त्र पर',
        'dragon_label' => 'पीठ पर ड्रैगन',
        'dragon_body' => 'बादल-लहरों में ड्रैगन—पास से स्पष्ट, दूर से गौरवशाली।',
        'sleeve_label' => 'चौड़ी आस्तीन पर बाँस',
        'sleeve_body' => 'चौड़ी आस्तीनों पर सुनहरे बाँस की डालियाँ रोशनी पकड़ती हैं।',
        'collar_label' => 'क्रॉस कॉलर',
        'collar_body' => 'कमरपट्टी और परतदार कॉलर; भीतरी किनारा साफ़।',
        'colors_title' => 'तीन स्वभाव',
        'colors_body' => 'पत्थरी टील, हल्का नीला, गुलाबी ग्रेडिएंट—एक कट, तीन अंदाज़।',
        'macro_title' => 'नज़दीकी छाप',
        'macro_body' => 'हेम पर बादल-जल-ड्रैगन; बाँस पढ़ने योग्य।',
        'blue_title' => 'हल्के नीले में',
        'blue_body' => 'हल्की नीली पोशाक और कागज़ी छाता; ड्रैगन हेम कदमों के संग।',
        'original_title' => 'मूल शिल्प',
        'original_body' => 'कट और छाप हुआझाओजी के मूल हैं। शिल्प का सम्मान करें।',
        'wash_title' => 'देखभाल',
        'wash_lines' => ['हाथ से धोएँ; रंग अलग; ब्लीच नहीं।', 'लटकाकर सुखाएँ; तेज़ धूप से बचें; कपड़े के साथ कम ताप की इस्त्री।'],
        'info_brand' => 'Huazhaoji',
        'info_name' => 'ड्रैगन उदय',
        'info_color' => 'पत्थरी टील · हल्का नीला · गुलाबी ग्रेडिएंट',
        'info_style' => 'वेई–जिन',
        'info_size' => 'S–2XL',
        'info_fabric' => '4-दिशा स्ट्रेच / पॉलिएस्टर',
        'info_parts' => 'चौड़ी आस्तीन की पोशाक, अंदर की परत, स्कर्ट',
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
        'chart_title' => 'साइज़ संदर्भ',
        'chart_note' => 'सेंटीमीटर; वजन जिन में (½ किग्रा)। हाथ माप में 1–3 सेमी अंतर हो सकता है।',
        'h_size' => 'साइज़',
        'h_bust' => 'छाती',
        'h_height' => 'ऊँचाई',
        'h_weight' => 'वजन (जिन)',
        'alt_dragon' => 'ड्रैगन उदय · पीठ',
        'alt_look' => 'ड्रैगन उदय · पहना',
        'alt_macro' => 'ड्रैगन उदय · छाप',
        'alt_color' => 'ड्रैगन उदय · रंग',
        'c_thick' => 'मोटाई',
        'c_thick_opts' => ['पतला', 'मध्यम', 'मोटा'],
        'c_thick_sel' => 'पतला',
        'c_fit' => 'फ़िट',
        'c_fit_opts' => ['टाइट', 'स्लिम', 'रेगुलर', 'ढीला'],
        'c_fit_sel' => 'स्लिम',
        'c_soft' => 'स्पर्श',
        'c_soft_opts' => ['मध्यम', 'नरम', 'दृढ़', 'कठोर'],
        'c_soft_sel' => 'नरम',
        'c_stretch' => 'लचीलापन',
        'c_stretch_opts' => ['नहीं', 'हल्का', 'अधिक'],
        'c_stretch_sel' => 'हल्का',
    ],
    'ur_PK' => [
        'intro_title' => 'ڈریگن عروج',
        'intro_body' => 'وی–جن کمر تک کراس کالر تین حصے: چوڑی آستین چوغہ، اندرونی تہ اور اسکرٹ۔ پتھری ٹیل، ہلکا نیلا یا گلابی گریڈینٹ—پیٹھ پر ڈریگن، آستین پر بانس۔',
        'inspire_title' => 'ڈیزائن کا سرچشمہ',
        'inspire_lines' => ['پیلے پھول جلاوطن پر مسکراتے ہیں؛ ہوا ٹوپی اٹھاتی ہے؛ رقص چاند سے ٹھہرنے کو کہتا ہے۔', 'نویں دن ہم ڈریگن پہاڑ پر پیتے ہیں۔'],
        'inspire_note' => 'روح کی علامت کے طور پر شعر—مارکیٹ پلیس کی زبان نہیں۔',
        'highlight_title' => 'لباس پر',
        'dragon_label' => 'پیٹھ پر ڈریگن',
        'dragon_body' => 'بادل اور لہروں میں ڈریگن—قریب سے واضح، دور سے شان دار۔',
        'sleeve_label' => 'چوڑی آستین پر بانس',
        'sleeve_body' => 'چوڑی آستینوں پر سنہری بانس کی شاخیں روشنی پکڑتی ہیں۔',
        'collar_label' => 'کراس کالر',
        'collar_body' => 'کمر بند اور تہ دار کالر؛ اندرونی کنارہ صاف۔',
        'colors_title' => 'تین مزاج',
        'colors_body' => 'پتھری ٹیل، ہلکا نیلا، گلابی گریڈینٹ—ایک کٹ، تین انداز۔',
        'macro_title' => 'قریب سے نقش',
        'macro_body' => 'ہیم پر بادل، پانی اور ڈریگن؛ بانس پڑھنے کے قابل۔',
        'blue_title' => 'ہلکے نیلے میں',
        'blue_body' => 'ہلکی نیلی پوشاک اور کاغذی چھتری؛ ڈریگن ہیم قدموں کے ساتھ۔',
        'original_title' => 'اصل دستکاری',
        'original_body' => 'کٹ اور نقش ہواژاوجی کے اصل ہیں۔ دستکاری کا احترام کریں۔',
        'wash_title' => 'دیکھ بھال',
        'wash_lines' => ['ہاتھ سے دھوئیں؛ رنگ الگ؛ بلیچ نہیں۔', 'لٹکا کر خشک کریں؛ تیز دھوپ سے بچیں؛ کپڑے کے ساتھ کم حرارت کی استری۔'],
        'info_brand' => 'Huazhaoji',
        'info_name' => 'ڈریگن عروج',
        'info_color' => 'پتھری ٹیل · ہلکا نیلا · گلابی گریڈینٹ',
        'info_style' => 'وی–جن',
        'info_size' => 'S–2XL',
        'info_fabric' => 'چار طرفہ اسٹریچ / پالی ایسٹر',
        'info_parts' => 'چوڑی آستین چوغہ، اندرونی تہ، اسکرٹ',
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
        'chart_note' => 'سینٹی میٹر؛ وزن جن میں (½ کلو)۔ ہاتھ کی پیمائش میں ۱–۳ سینٹی میٹر فرق ہو سکتا ہے۔',
        'h_size' => 'سائز',
        'h_bust' => 'سینہ',
        'h_height' => 'قد',
        'h_weight' => 'وزن (جن)',
        'alt_dragon' => 'ڈریگن عروج · پیٹھ',
        'alt_look' => 'ڈریگن عروج · پہنا',
        'alt_macro' => 'ڈریگن عروج · نقش',
        'alt_color' => 'ڈریگن عروج · رنگ',
        'c_thick' => 'موٹائی',
        'c_thick_opts' => ['پتلا', 'درمیانہ', 'موٹا'],
        'c_thick_sel' => 'پتلا',
        'c_fit' => 'فٹ',
        'c_fit_opts' => ['تنگ', 'سلِم', 'عام', 'ڈھیلا'],
        'c_fit_sel' => 'سلِم',
        'c_soft' => 'لمس',
        'c_soft_opts' => ['درمیانہ', 'نرم', 'مضبوط', 'سخت'],
        'c_soft_sel' => 'نرم',
        'c_stretch' => 'کھینچاؤ',
        'c_stretch_opts' => ['نہیں', 'ہلکا', 'زیادہ'],
        'c_stretch_sel' => 'ہلکا',
    ],
];

$assemble = static function (array $t) use ($A, $img, $figureStack, $h): string {
    $intro = '<div class="weline-detail-prose"><h3>' . $h((string)$t['intro_title']) . '</h3><p>'
        . $h((string)$t['intro_body']) . '</p></div>';

    $hero = $figureStack([
        $img($A['dragon']['id'], (string)$t['alt_dragon'], $A['dragon']['w'], $A['dragon']['h']),
    ], 'weline-detail-figure-stack--fullbleed weline-detail-orient--portrait');

    $inspire = '<div class="weline-detail-prose"><h3>' . $h((string)$t['inspire_title']) . '</h3>';
    foreach ((array)$t['inspire_lines'] as $line) {
        $inspire .= '<p>' . $h((string)$line) . '</p>';
    }
    $inspire .= '<p class="weline-detail-feature__note">' . $h((string)$t['inspire_note']) . '</p></div>';

    $looks = $figureStack([
        $img($A['gDark']['id'], (string)$t['alt_look'] . ' 1', $A['gDark']['w'], $A['gDark']['h']),
        $img($A['look06']['id'], (string)$t['alt_look'] . ' 2', $A['look06']['w'], $A['look06']['h']),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait');

    $highlight = '<div class="weline-detail-prose"><h3>' . $h((string)$t['highlight_title']) . '</h3>'
        . '<h4>' . $h((string)$t['dragon_label']) . '</h4><p>' . $h((string)$t['dragon_body']) . '</p>'
        . '<h4>' . $h((string)$t['sleeve_label']) . '</h4><p>' . $h((string)$t['sleeve_body']) . '</p>'
        . '<h4>' . $h((string)$t['collar_label']) . '</h4><p>' . $h((string)$t['collar_body']) . '</p></div>';

    // 禁拼版 macro/blueLooks/colorPair：改净单帧 gDark2 / gBlue / gPink
    $macro = $figureStack([
        $img($A['gDark2']['id'], (string)$t['alt_macro'], $A['gDark2']['w'], $A['gDark2']['h']),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['macro_title']) . '</h3><p>'
        . $h((string)$t['macro_body']) . '</p></div>';

    $colors = '<div class="weline-detail-prose"><h3>' . $h((string)$t['colors_title']) . '</h3><p>'
        . $h((string)$t['colors_body']) . '</p></div>'
        . $figureStack([
            $img($A['gBlue']['id'], (string)$t['alt_color'] . ' 1', $A['gBlue']['w'], $A['gBlue']['h']),
            $img($A['gPink']['id'], (string)$t['alt_color'] . ' 2', $A['gPink']['w'], $A['gPink']['h']),
        ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait');

    $blue = $figureStack([
        $img($A['gBlue']['id'], (string)$t['alt_look'] . ' 3', $A['gBlue']['w'], $A['gBlue']['h']),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['blue_title']) . '</h3><p>'
        . $h((string)$t['blue_body']) . '</p></div>';

    $pair = $figureStack([
        $img($A['gPink']['id'], (string)$t['alt_color'] . ' 3', $A['gPink']['w'], $A['gPink']['h']),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait');

    $original = '<div class="weline-detail-prose weline-detail-prose--quiet"><h3>' . $h((string)$t['original_title']) . '</h3><p>'
        . $h((string)$t['original_body']) . '</p></div>';

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

    $chart = DetailDescriptionTextifier::buildMeasurementSizeChartZh(
        [
            [
                'title' => (string)$t['chart_title'],
                'headers' => [(string)$t['h_size'], (string)$t['h_bust'], (string)$t['h_height'], (string)$t['h_weight']],
                'rows' => [
                    ['S', '≤85', '155–160', '80–100'],
                    ['M', '≤88', '160–168', '95–110'],
                    ['L', '≤90', '168–175', '105–125'],
                    ['XL', '≤95', '172–180', '125–140'],
                    ['2XL', '≤100', '175–185', '140–160'],
                ],
            ],
        ],
        (string)$t['chart_title'],
        (string)$t['chart_note'],
    );

    return '<div data-weline-product-description="1688">'
        . $intro . $hero . $inspire . $looks . $highlight . $macro
        . $colors . $blue . $pair . $original . $wash . $info . $chart
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

$enLeakMarkers = ['Design wellspring', 'Original craft', 'On the garment', 'At a glance'];

$writes = [];
foreach ($localePlan as $locale => $baseKey) {
    if (!isset($copy[$baseKey])) {
        fwrite(STDERR, "Missing locale pack: {$baseKey}\n");
        exit(2);
    }
    $html = $assemble($copy[$baseKey]);
    $banned = ['一件代发', '混批', '厂家直销', '旺旺', '货源', '阿里巴巴', '淘宝', '天猫', '拼多多', 'dropship', 'Dropship', '汉风唐韵'];
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
        'fullbleed' => substr_count($html, 'weline-detail-figure-stack--fullbleed'),
    ];
}

foreach ($writes as $w) {
    echo sprintf(
        "%s\t%d\tfeature=%d\timgs=%d\tfullbleed=%d\n",
        $w['locale'] === '' ? '(empty)' : $w['locale'],
        $w['len'],
        $w['features'],
        $w['imgs'],
        $w['fullbleed'],
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
    'detail_locale_true_translate_197',
    ['product_ids' => [$productId]],
);
ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class)
    ->clearForCatalogChange('detail_locale_true_translate_197');

echo "Applied description-only writes: " . count($writes) . " locales.\n";
