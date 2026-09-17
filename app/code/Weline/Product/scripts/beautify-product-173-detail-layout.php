<?php

declare(strict_types=1);

/**
 * #173 扶摇 — ecommerce-detail-suite
 * 主图+规格+详情 · 古风 · 裁白后补回 target_ar≈0.75 · 多样版式（非纯竖叠）
 *
 * 卖点：齐胸一片式破裙 / 圆纹印花 / 薄纱广袖与披帛 / 橙·绿双色
 * 原型：editorial → fullbleed → editorial → pair → editorial → stack_caption →
 *       editorial → pair → quiet → stack_caption → stack → quiet → checklist → spec → size
 * feature_lr=0（竖/方图一律 stack）
 *
 * php app/code/Weline/Product/scripts/beautify-product-173-detail-layout.php --apply
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
$productId = 173;

$A = [
    'hero' => ['id' => '7cf04cce-d15a-4a73-9176-4660684b49e9', 'w' => 1200, 'h' => 1200],
    'look02' => ['id' => '10ce4b69-0986-42db-8fb6-113f89f7212f', 'w' => 1200, 'h' => 1200],
    'look03' => ['id' => 'cfb453f3-63e2-463d-a87e-b0edd4eeb320', 'w' => 1200, 'h' => 1200],
    'greenLook' => ['id' => 'a887a60d-d5f6-4f74-ae65-8e60de1a5659', 'w' => 1200, 'h' => 1200],
    'look05' => ['id' => 'cf034ec6-e12a-4832-812e-26512a301b3b', 'w' => 1200, 'h' => 1200],
    'orange' => ['id' => '5d66c1f0-edd2-4464-b194-e362a9f227b1', 'w' => 1200, 'h' => 1200],
    'green' => ['id' => '785b6afc-7d8d-4ec6-a222-73fcbc45b07b', 'w' => 1200, 'h' => 1200],
    'mid' => ['id' => '2f821fe5-5982-4528-a555-d914f4b2adde', 'w' => 544, 'h' => 725],
    'greenMid' => ['id' => '6874303d-f470-43da-b84e-b7a31802a8dc', 'w' => 576, 'h' => 576],
    'fan' => ['id' => 'facbd6b1-3fc4-47e7-827a-dcb5a0c6310f', 'w' => 436, 'h' => 581],
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
        'intro_title' => '扶摇',
        'intro_body' => '唐制一片式齐胸破裙：薄纱广袖外衫与齐胸裙幅相叠，橙、绿两色可选。圆纹印花随步铺展，披帛与丝绦轻垂。',
        'inspire_title' => '设计心源',
        'inspire_lines' => ['扶摇直上九万里，衣袂生风。', '齐胸束结，破裙分幅，唐风一脉。'],
        'inspire_note' => '取「扶摇」为题眼——仅为形制与气韵之点题，非平台货盘说辞。',
        'highlight_title' => '衣袂要点',
        'skirt_label' => '齐胸一片式破裙',
        'skirt_body' => '裙幅高束于胸际，分幅垂落，走动时褶影分明，衣长可读。',
        'print_label' => '圆纹印花',
        'print_body' => '裙面散落圆形纹样，近观层次清楚，远望华贵不杂。',
        'sleeve_label' => '薄纱广袖与披帛',
        'sleeve_body' => '外衫透薄，广袖与披帛叠坠；举手可见纱质垂感。',
        'colors_title' => '双色气韵',
        'colors_body' => '橙色全套温润如杏；绿色全套沉静带芥黄与烟蓝裙幅——同一形制，两种风骨。',
        'mid_title' => '中景细读',
        'mid_body' => '胸际丝绦结法、裙幅分色与袖袂层次，中景即可辨认。',
        'green_title' => '绿色全套',
        'green_body' => '芥黄交领配烟蓝分幅裙，橙绦点睛；同形异色，另见清朗。',
        'fan_title' => '团扇点缀',
        'fan_body' => '白底净裁中，团扇绣面与裙幅圆纹相映；仅作造型点题。',
        'original_title' => '原创心迹',
        'original_body' => '本款为汉唐华韵原创形制与纹样，敬请珍惜衣冠、尊重匠心。',
        'wash_title' => '护衣小笺',
        'wash_lines' => ['建议手洗，分色洗涤，不可漂白。', '悬挂晾干，避暴晒；低温熨烫，垫布为佳。'],
        'info_brand' => '汉唐华韵',
        'info_name' => '扶摇',
        'info_color' => '橙色全套 · 绿色全套',
        'info_style' => '唐制',
        'info_size' => 'S–XL',
        'info_fabric' => '涤纶（聚酯纤维）/ 雪纺',
        'info_parts' => '一片式齐胸破裙（含薄纱外衫层次）',
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
        'size_title' => '尺码说明',
        'size_body' => '可选 S、M、L、XL。请以页面尺码选择为准；手工测量衣身或有轻微出入。',
        'alt_hero' => '扶摇 · 着装',
        'alt_look' => '扶摇 · 着装',
        'alt_orange' => '扶摇 · 橙色全套',
        'alt_green' => '扶摇 · 绿色全套',
        'alt_mid' => '扶摇 · 中景',
        'alt_fan' => '扶摇 · 团扇',
        'c_thick' => '厚度',
        'c_thick_opts' => ['薄', '适中', '厚'],
        'c_thick_sel' => '薄',
        'c_fit' => '版型',
        'c_fit_opts' => ['紧身', '修身', '适中', '宽松'],
        'c_fit_sel' => '适中',
        'c_soft' => '柔软',
        'c_soft_opts' => ['适中', '柔软', '微硬', '硬'],
        'c_soft_sel' => '柔软',
        'c_stretch' => '弹性',
        'c_stretch_opts' => ['无弹', '微弹', '高弹'],
        'c_stretch_sel' => '无弹',
    ],
    'en_US' => [
        'intro_title' => 'Fuyao',
        'intro_body' => 'A Tang-style one-piece chest-high po skirt: sheer wide sleeves over a high-tied skirt. Choose apricot orange or green set—circular prints open as you walk, with stole and ribbons falling light.',
        'inspire_title' => 'Design wellspring',
        'inspire_lines' => ['Rise on the wind for miles—sleeves catch the air.', 'Chest-high sash, paneled skirt: one Tang line.'],
        'inspire_note' => '“Fuyao” names the spirit of cut and drape—not marketplace copy.',
        'highlight_title' => 'On the garment',
        'skirt_label' => 'Chest-high one-piece po skirt',
        'skirt_body' => 'Tied high at the chest, panels fall clean; pleats and length stay readable in motion.',
        'print_label' => 'Circular print',
        'print_body' => 'Round motifs scatter across the skirt—clear up close, rich from afar.',
        'sleeve_label' => 'Sheer sleeves and stole',
        'sleeve_body' => 'A light outer robe; wide sleeves and stole layer soft when you lift an arm.',
        'colors_title' => 'Two moods',
        'colors_body' => 'Orange set warm as apricot; green set calm with mustard and smoky blue panels—one cut, two temperaments.',
        'mid_title' => 'Mid view',
        'mid_body' => 'Chest ties, panel colors, and sleeve layers read clearly at mid distance.',
        'green_title' => 'Green set',
        'green_body' => 'Mustard cross-collar with smoky blue panels and orange ties—same cut, cooler air.',
        'fan_title' => 'Round fan',
        'fan_body' => 'On a clean white ground, embroidered fan and skirt print echo each other—as styling only.',
        'original_title' => 'Original craft',
        'original_body' => 'Cut and print are original to Hantang Huayun. Please honor the craft.',
        'wash_title' => 'Care notes',
        'wash_lines' => ['Hand wash preferred; separate colors; no bleach.', 'Hang dry; avoid harsh sun; low iron with a cloth.'],
        'info_brand' => 'Hantang Huayun',
        'info_name' => 'Fuyao',
        'info_color' => 'Orange set · Green set',
        'info_style' => 'Tang style',
        'info_size' => 'S–XL',
        'info_fabric' => 'Polyester / chiffon',
        'info_parts' => 'One-piece chest-high po skirt (with sheer outer layer)',
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
        'size_title' => 'Size note',
        'size_body' => 'S, M, L, and XL. Follow the on-page size selector; hand measure may vary slightly.',
        'alt_hero' => 'Fuyao · worn',
        'alt_look' => 'Fuyao · worn',
        'alt_orange' => 'Fuyao · orange set',
        'alt_green' => 'Fuyao · green set',
        'alt_mid' => 'Fuyao · mid view',
        'alt_fan' => 'Fuyao · fan',
        'c_thick' => 'Thickness',
        'c_thick_opts' => ['Thin', 'Medium', 'Thick'],
        'c_thick_sel' => 'Thin',
        'c_fit' => 'Fit',
        'c_fit_opts' => ['Tight', 'Slim', 'Regular', 'Loose'],
        'c_fit_sel' => 'Regular',
        'c_soft' => 'Hand',
        'c_soft_opts' => ['Medium', 'Soft', 'Firm', 'Hard'],
        'c_soft_sel' => 'Soft',
        'c_stretch' => 'Stretch',
        'c_stretch_opts' => ['None', 'Slight', 'High'],
        'c_stretch_sel' => 'None',
    ],
    'es_ES' => [
        'intro_title' => 'Fuyao',
        'intro_body' => 'Falda po pecho alto de una pieza estilo Tang: mangas amplias de gasa sobre falda alta. Elige naranja albaricoque o juego verde—estampados circulares al caminar, estola y cintas ligeras.',
        'inspire_title' => 'Fuente del diseño',
        'inspire_lines' => ['Sube con el viento leguas—las mangas atrapan el aire.', 'Faja al pecho, paneles: una línea Tang.'],
        'inspire_note' => '“Fuyao” nombra el espíritu del corte—no jerga de plataforma.',
        'highlight_title' => 'En la prenda',
        'skirt_label' => 'Falda po pecho alto de una pieza',
        'skirt_body' => 'Atada alto al pecho; los paneles caen limpios; pliegues y largo se leen en movimiento.',
        'print_label' => 'Estampado circular',
        'print_body' => 'Motivos redondos en la falda—nítidos de cerca, ricos de lejos.',
        'sleeve_label' => 'Mangas de gasa y estola',
        'sleeve_body' => 'Túnica ligera; mangas amplias y estola se superponen al alzar el brazo.',
        'colors_title' => 'Dos temperamentos',
        'colors_body' => 'Naranja cálido como albaricoque; verde sereno con mostaza y paneles azul humo—un corte, dos aires.',
        'mid_title' => 'Vista media',
        'mid_body' => 'Nudos del pecho, colores de panel y capas de manga se leen a media distancia.',
        'green_title' => 'Juego verde',
        'green_body' => 'Cuello cruzado mostaza con paneles azul humo y cintas naranja—mismo corte, aire más fresco.',
        'fan_title' => 'Abanico redondo',
        'fan_body' => 'Sobre fondo blanco, el abanico bordado y el estampado se responden—solo estilismo.',
        'original_title' => 'Oficio original',
        'original_body' => 'Corte y estampado son originales de Hantang Huayun. Honrad el oficio.',
        'wash_title' => 'Cuidado',
        'wash_lines' => ['Lavado a mano; separar colores; no blanquear.', 'Secar colgado; evitar sol fuerte; plancha baja con paño.'],
        'info_brand' => 'Hantang Huayun',
        'info_name' => 'Fuyao',
        'info_color' => 'Juego naranja · Juego verde',
        'info_style' => 'Estilo Tang',
        'info_size' => 'S–XL',
        'info_fabric' => 'Poliéster / gasa',
        'info_parts' => 'Falda po pecho alto de una pieza (con capa exterior de gasa)',
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
        'size_title' => 'Nota de talla',
        'size_body' => 'S, M, L y XL. Seguid el selector de la página; la medida a mano puede variar un poco.',
        'alt_hero' => 'Fuyao · puesta',
        'alt_look' => 'Fuyao · puesta',
        'alt_orange' => 'Fuyao · juego naranja',
        'alt_green' => 'Fuyao · juego verde',
        'alt_mid' => 'Fuyao · vista media',
        'alt_fan' => 'Fuyao · abanico',
        'c_thick' => 'Grosor',
        'c_thick_opts' => ['Fino', 'Medio', 'Grueso'],
        'c_thick_sel' => 'Fino',
        'c_fit' => 'Corte',
        'c_fit_opts' => ['Ajustado', 'Slim', 'Regular', 'Holgado'],
        'c_fit_sel' => 'Regular',
        'c_soft' => 'Tacto',
        'c_soft_opts' => ['Medio', 'Suave', 'Firme', 'Duro'],
        'c_soft_sel' => 'Suave',
        'c_stretch' => 'Elasticidad',
        'c_stretch_opts' => ['Ninguna', 'Ligera', 'Alta'],
        'c_stretch_sel' => 'Ninguna',
    ],
    'fr_FR' => [
        'intro_title' => 'Fuyao',
        'intro_body' => 'Jupe po poitrine haute d’une pièce style Tang : larges manches en mousseline sur jupe haute. Orange abricot ou ensemble vert—motifs circulaires en marchant, étole et rubans légers.',
        'inspire_title' => 'Source du dessin',
        'inspire_lines' => ['S’élève au vent sur des lieues—les manches prennent l’air.', 'Ceinture haute, pans : une ligne Tang.'],
        'inspire_note' => '« Fuyao » nomme l’esprit de la coupe—pas un jargon de plateforme.',
        'highlight_title' => 'Sur le vêtement',
        'skirt_label' => 'Jupe po poitrine haute d’une pièce',
        'skirt_body' => 'Nouée haut à la poitrine ; les pans tombent nets ; plis et longueur se lisent en mouvement.',
        'print_label' => 'Motifs circulaires',
        'print_body' => 'Motifs ronds sur la jupe—nets de près, riches de loin.',
        'sleeve_label' => 'Manches de mousseline et étole',
        'sleeve_body' => 'Robe légère ; larges manches et étole se superposent au lever du bras.',
        'colors_title' => 'Deux tempéraments',
        'colors_body' => 'Orange chaud comme l’abricot ; vert calme avec moutarde et pans bleu fumé—une coupe, deux airs.',
        'mid_title' => 'Vue moyenne',
        'mid_body' => 'Nœuds de poitrine, couleurs des pans et couches de manches se lisent à mi-distance.',
        'green_title' => 'Ensemble vert',
        'green_body' => 'Col croisé moutarde, pans bleu fumé et rubans orange—même coupe, air plus frais.',
        'fan_title' => 'Éventail rond',
        'fan_body' => 'Sur fond blanc, éventail brodé et motifs se répondent—simple stylisme.',
        'original_title' => 'Savoir-faire original',
        'original_body' => 'Coupe et motifs sont originaux de Hantang Huayun. Honorez le métier.',
        'wash_title' => 'Entretien',
        'wash_lines' => ['Lavage à la main ; séparer les couleurs ; pas d’eau de Javel.', 'Séchage suspendu ; éviter le soleil fort ; fer doux avec un linge.'],
        'info_brand' => 'Hantang Huayun',
        'info_name' => 'Fuyao',
        'info_color' => 'Ensemble orange · Ensemble vert',
        'info_style' => 'Style Tang',
        'info_size' => 'S–XL',
        'info_fabric' => 'Polyester / mousseline',
        'info_parts' => 'Jupe po poitrine haute d’une pièce (avec couche extérieure en mousseline)',
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
        'size_title' => 'Note de taille',
        'size_body' => 'S, M, L et XL. Suivez le sélecteur de la page ; la mesure à la main peut légèrement varier.',
        'alt_hero' => 'Fuyao · portée',
        'alt_look' => 'Fuyao · portée',
        'alt_orange' => 'Fuyao · ensemble orange',
        'alt_green' => 'Fuyao · ensemble vert',
        'alt_mid' => 'Fuyao · vue moyenne',
        'alt_fan' => 'Fuyao · éventail',
        'c_thick' => 'Épaisseur',
        'c_thick_opts' => ['Fin', 'Moyen', 'Épais'],
        'c_thick_sel' => 'Fin',
        'c_fit' => 'Coupe',
        'c_fit_opts' => ['Serré', 'Slim', 'Régulier', 'Large'],
        'c_fit_sel' => 'Régulier',
        'c_soft' => 'Main',
        'c_soft_opts' => ['Moyen', 'Doux', 'Ferme', 'Dur'],
        'c_soft_sel' => 'Doux',
        'c_stretch' => 'Élasticité',
        'c_stretch_opts' => ['Aucune', 'Légère', 'Forte'],
        'c_stretch_sel' => 'Aucune',
    ],
    'pt_BR' => [
        'intro_title' => 'Fuyao',
        'intro_body' => 'Saia po peito alto de uma peça estilo Tang: mangas amplas de chiffon sobre saia alta. Laranja damasco ou conjunto verde—estampas circulares ao andar, echarpe e fitas leves.',
        'inspire_title' => 'Fonte do desenho',
        'inspire_lines' => ['Sobe com o vento por léguas—as mangas pegam o ar.', 'Faixa no peito, painéis: uma linha Tang.'],
        'inspire_note' => '“Fuyao” nomeia o espírito do corte—não jargão de plataforma.',
        'highlight_title' => 'Na peça',
        'skirt_label' => 'Saia po peito alto de uma peça',
        'skirt_body' => 'Amarrada alto no peito; painéis caem limpos; pregas e comprimento legíveis em movimento.',
        'print_label' => 'Estampa circular',
        'print_body' => 'Motivos redondos na saia—nítidos de perto, ricos de longe.',
        'sleeve_label' => 'Mangas de chiffon e echarpe',
        'sleeve_body' => 'Túnica leve; mangas amplas e echarpe se sobrepõem ao erguer o braço.',
        'colors_title' => 'Dois temperamentos',
        'colors_body' => 'Laranja quente como damasco; verde sereno com mostarda e painéis azul-fumaça—um corte, dois ares.',
        'mid_title' => 'Vista média',
        'mid_body' => 'Nós do peito, cores dos painéis e camadas de manga legíveis à média distância.',
        'green_title' => 'Conjunto verde',
        'green_body' => 'Gola cruzada mostarda com painéis azul-fumaça e fitas laranja—mesmo corte, ar mais fresco.',
        'fan_title' => 'Leque redondo',
        'fan_body' => 'Em fundo branco, leque bordado e estampa se respondem—apenas estilo.',
        'original_title' => 'Ofício original',
        'original_body' => 'Corte e estampa são originais da Hantang Huayun. Honrem o ofício.',
        'wash_title' => 'Cuidados',
        'wash_lines' => ['Lavar à mão; separar cores; sem alvejante.', 'Secar pendurado; evitar sol forte; ferro baixo com pano.'],
        'info_brand' => 'Hantang Huayun',
        'info_name' => 'Fuyao',
        'info_color' => 'Conjunto laranja · Conjunto verde',
        'info_style' => 'Estilo Tang',
        'info_size' => 'S–XL',
        'info_fabric' => 'Poliéster / chiffon',
        'info_parts' => 'Saia po peito alto de uma peça (com camada externa de chiffon)',
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
        'size_title' => 'Nota de tamanho',
        'size_body' => 'S, M, L e XL. Siga o seletor da página; medida à mão pode variar um pouco.',
        'alt_hero' => 'Fuyao · vestida',
        'alt_look' => 'Fuyao · vestida',
        'alt_orange' => 'Fuyao · conjunto laranja',
        'alt_green' => 'Fuyao · conjunto verde',
        'alt_mid' => 'Fuyao · vista média',
        'alt_fan' => 'Fuyao · leque',
        'c_thick' => 'Espessura',
        'c_thick_opts' => ['Fina', 'Média', 'Grossa'],
        'c_thick_sel' => 'Fina',
        'c_fit' => 'Caimento',
        'c_fit_opts' => ['Apertado', 'Slim', 'Regular', 'Largo'],
        'c_fit_sel' => 'Regular',
        'c_soft' => 'Toque',
        'c_soft_opts' => ['Médio', 'Macio', 'Firme', 'Duro'],
        'c_soft_sel' => 'Macio',
        'c_stretch' => 'Elasticidade',
        'c_stretch_opts' => ['Nenhuma', 'Leve', 'Alta'],
        'c_stretch_sel' => 'Nenhuma',
    ],
    'id_ID' => [
        'intro_title' => 'Fuyao',
        'intro_body' => 'Rok po dada tinggi satu potong gaya Tang: lengan lebar sifon di atas rok tinggi. Pilih oranye aprikot atau set hijau—motif bundar saat melangkah, selendang dan pita ringan.',
        'inspire_title' => 'Sumber desain',
        'inspire_lines' => ['Naik bersama angin bermil-mil—lengan menangkap udara.', 'Ikat dada, panel: satu garis Tang.'],
        'inspire_note' => '“Fuyao” menamai roh potongan—bukan jargon platform.',
        'highlight_title' => 'Pada busana',
        'skirt_label' => 'Rok po dada tinggi satu potong',
        'skirt_body' => 'Diikat tinggi di dada; panel jatuh rapi; lipit dan panjang terbaca saat bergerak.',
        'print_label' => 'Motif bundar',
        'print_body' => 'Motif bulat di rok—jelas dekat, kaya dari jauh.',
        'sleeve_label' => 'Lengan sifon dan selendang',
        'sleeve_body' => 'Jubah ringan; lengan lebar dan selendang bertumpuk saat lengan diangkat.',
        'colors_title' => 'Dua temperamen',
        'colors_body' => 'Oranye hangat seperti aprikot; hijau tenang dengan mustard dan panel biru asap—satu potongan, dua suasana.',
        'mid_title' => 'Tampak tengah',
        'mid_body' => 'Simpul dada, warna panel, dan lapisan lengan terbaca di jarak sedang.',
        'green_title' => 'Set hijau',
        'green_body' => 'Kerah silang mustard dengan panel biru asap dan pita oranye—potongan sama, udara lebih sejuk.',
        'fan_title' => 'Kipas bundar',
        'fan_body' => 'Di latar putih, kipas sulam dan motif saling menjawab—hanya gaya.',
        'original_title' => 'Kerajinan orisinal',
        'original_body' => 'Potongan dan motif orisinal Hantang Huayun. Hormati kerajinan.',
        'wash_title' => 'Perawatan',
        'wash_lines' => ['Cuci tangan; pisahkan warna; tanpa pemutih.', 'Keringkan digantung; hindari matahari terik; setrika rendah dengan kain.'],
        'info_brand' => 'Hantang Huayun',
        'info_name' => 'Fuyao',
        'info_color' => 'Set oranye · Set hijau',
        'info_style' => 'Gaya Tang',
        'info_size' => 'S–XL',
        'info_fabric' => 'Poliester / sifon',
        'info_parts' => 'Rok po dada tinggi satu potong (dengan lapisan luar sifon)',
        'info_title' => 'Sekilas',
        'info_basics' => 'Dasar',
        'info_comfort' => 'Sentuhan',
        'label_brand' => 'Merek',
        'label_name' => 'Nama',
        'label_color' => 'Warna',
        'label_style' => 'Gaya',
        'label_size' => 'Ukuran',
        'label_fabric' => 'Kain',
        'label_parts' => 'Bagian',
        'size_title' => 'Catatan ukuran',
        'size_body' => 'S, M, L, dan XL. Ikuti pemilih ukuran di halaman; ukur tangan mungkin sedikit berbeda.',
        'alt_hero' => 'Fuyao · dikenakan',
        'alt_look' => 'Fuyao · dikenakan',
        'alt_orange' => 'Fuyao · set oranye',
        'alt_green' => 'Fuyao · set hijau',
        'alt_mid' => 'Fuyao · tampak tengah',
        'alt_fan' => 'Fuyao · kipas',
        'c_thick' => 'Ketebalan',
        'c_thick_opts' => ['Tipis', 'Sedang', 'Tebal'],
        'c_thick_sel' => 'Tipis',
        'c_fit' => 'Potongan',
        'c_fit_opts' => ['Ketat', 'Slim', 'Reguler', 'Longgar'],
        'c_fit_sel' => 'Reguler',
        'c_soft' => 'Sentuhan',
        'c_soft_opts' => ['Sedang', 'Lembut', 'Kokoh', 'Keras'],
        'c_soft_sel' => 'Lembut',
        'c_stretch' => 'Elastisitas',
        'c_stretch_opts' => ['Tidak', 'Sedikit', 'Tinggi'],
        'c_stretch_sel' => 'Tidak',
    ],
    'ar_SA' => [
        'intro_title' => 'فوياو',
        'intro_body' => 'تنورة بو مرتفعة الصدر قطعة واحدة أسلوب تانغ: أكمام واسعة شفافة فوق تنورة عالية. اختر البرتقالي المشمشي أو طقم أخضر—زخارف دائرية مع الخطوة، ووشاح وأشرطة خفيفة.',
        'inspire_title' => 'منبع التصميم',
        'inspire_lines' => ['تصعد مع الريح أميالاً—الأكمام تمسك الهواء.', 'رباط عند الصدر وألواح: خط تانغ واحد.'],
        'inspire_note' => '«فوياو» تسمي روح القصّ—لا لغة المنصات.',
        'highlight_title' => 'على الثوب',
        'skirt_label' => 'تنورة بو مرتفعة الصدر قطعة واحدة',
        'skirt_body' => 'تُربط عالياً عند الصدر؛ الألواح تسقط نظيفة؛ الطيات والطول يُقرآن أثناء الحركة.',
        'print_label' => 'طباعة دائرية',
        'print_body' => 'زخارف مستديرة على التنورة—واضحة من قريب وغنية من بعيد.',
        'sleeve_label' => 'أكمام شفافة ووشاح',
        'sleeve_body' => 'رداء خفيف؛ أكمام واسعة ووشاح يتراكبان عند رفع الذراع.',
        'colors_title' => 'مزاجان',
        'colors_body' => 'برتقالي دافئ كالمشمش؛ أخضر هادئ مع خردل وألواح زرقاء دخانية—قصّ واحد وهواءان.',
        'mid_title' => 'منظر متوسط',
        'mid_body' => 'عقد الصدر وألوان الألواح وطبقات الأكمام تُقرأ من مسافة متوسطة.',
        'green_title' => 'الطقم الأخضر',
        'green_body' => 'ياقة متصالبة خردلية مع ألواح زرقاء دخانية وأشرطة برتقالية—نفس القصّ وهواء أبرد.',
        'fan_title' => 'مروحة مستديرة',
        'fan_body' => 'على خلفية بيضاء، المروحة المطرّزة والطباعة تتناغمان—للتزيين فقط.',
        'original_title' => 'حرفة أصلية',
        'original_body' => 'القصّ والطباعة أصليان لهانتانغ هوايون. يُرجى احترام الحرفة.',
        'wash_title' => 'العناية',
        'wash_lines' => ['غسل يدوي؛ فصل الألوان؛ بلا مبيّض.', 'تجفيف معلّق؛ تجنّب شمس قوية؛ كيّ خفيف مع قماش.'],
        'info_brand' => 'هانتانغ هوايون',
        'info_name' => 'فوياو',
        'info_color' => 'طقم برتقالي · طقم أخضر',
        'info_style' => 'أسلوب تانغ',
        'info_size' => 'S–XL',
        'info_fabric' => 'بوليستر / شيفون',
        'info_parts' => 'تنورة بو مرتفعة الصدر قطعة واحدة (مع طبقة خارجية شفافة)',
        'info_title' => 'بنظرة',
        'info_basics' => 'أساسيات',
        'info_comfort' => 'الملمس',
        'label_brand' => 'العلامة',
        'label_name' => 'الاسم',
        'label_color' => 'الألوان',
        'label_style' => 'الأسلوب',
        'label_size' => 'المقاسات',
        'label_fabric' => 'القماش',
        'label_parts' => 'القطع',
        'size_title' => 'ملاحظة المقاس',
        'size_body' => 'S وM وL وXL. اتبع اختيار المقاس في الصفحة؛ القياس اليدوي قد يختلف قليلاً.',
        'alt_hero' => 'فوياو · مرتداة',
        'alt_look' => 'فوياو · مرتداة',
        'alt_orange' => 'فوياو · طقم برتقالي',
        'alt_green' => 'فوياو · طقم أخضر',
        'alt_mid' => 'فوياو · منظر متوسط',
        'alt_fan' => 'فوياو · مروحة',
        'c_thick' => 'السماكة',
        'c_thick_opts' => ['رفيع', 'متوسط', 'سميك'],
        'c_thick_sel' => 'رفيع',
        'c_fit' => 'القصة',
        'c_fit_opts' => ['ضيق', 'نحيف', 'عادي', 'واسع'],
        'c_fit_sel' => 'عادي',
        'c_soft' => 'الملمس',
        'c_soft_opts' => ['متوسط', 'ناعم', 'متين', 'قاس'],
        'c_soft_sel' => 'ناعم',
        'c_stretch' => 'المرونة',
        'c_stretch_opts' => ['لا', 'خفيف', 'عالٍ'],
        'c_stretch_sel' => 'لا',
    ],
    'bn_BD' => [
        'intro_title' => 'ফুয়াও',
        'intro_body' => 'তাং শৈলীর এক-টুকরো বুক-উঁচু পো স্কার্ট: পাতলা চওড়া হাতা উঁচু স্কার্টের ওপর। এপ্রিকট কমলা বা সবুজ সেট বেছে নিন—হাঁটলে গোল ছাপা খোলে, ওড়না ও ফিতা হালকা।',
        'inspire_title' => 'ডিজাইনের উৎস',
        'inspire_lines' => ['হাওয়ায় উঠে যায় বহু দূর—হাতা বাতাস ধরে।', 'বুকে বাঁধন, প্যানেল: এক তাং রেখা।'],
        'inspire_note' => '“ফুয়াও” কাটের আত্মা নাম করে—মার্কেটপ্লেস ভাষা নয়।',
        'highlight_title' => 'পোশাকে',
        'skirt_label' => 'বুক-উঁচু এক-টুকরো পো স্কার্ট',
        'skirt_body' => 'বুকে উঁচুতে বাঁধা; প্যানেল পরিষ্কার ঝরে; চলাফেরায় ভাঁজ ও দৈর্ঘ্য পঠনযোগ্য।',
        'print_label' => 'গোল ছাপা',
        'print_body' => 'স্কার্টে গোল মোটিফ—কাছে স্পষ্ট, দূরে সমৃদ্ধ।',
        'sleeve_label' => 'পাতলা হাতা ও ওড়না',
        'sleeve_body' => 'হালকা উপরি পোশাক; হাত তুললে চওড়া হাতা ও ওড়না স্তরে পড়ে।',
        'colors_title' => 'দুই মেজাজ',
        'colors_body' => 'কমলা এপ্রিকটের মতো উষ্ণ; সবুজ শান্ত সরিষা ও ধোঁয়াটে নীল প্যানেলসহ—এক কাট, দুই আবহ।',
        'mid_title' => 'মধ্য দৃশ্য',
        'mid_body' => 'বুকের গিঁট, প্যানেলের রং ও হাতার স্তর মাঝ দূরত্বেই পঠনযোগ্য।',
        'green_title' => 'সবুজ সেট',
        'green_body' => 'সরিষা ক্রস কলার, ধোঁয়াটে নীল প্যানেল ও কমলা ফিতা—একই কাট, ঠান্ডা আবহ।',
        'fan_title' => 'গোল পাখা',
        'fan_body' => 'সাদা পটভূমিতে সূচিকর্ম পাখা ও ছাপা একে অপরকে সাড়া দেয়—শুধু স্টাইলিং।',
        'original_title' => 'মূল কারিগরি',
        'original_body' => 'কাট ও ছাপা হানতাং হুয়ায়ুনের মৌলিক। কারিগরিকে সম্মান করুন।',
        'wash_title' => 'যত্ন',
        'wash_lines' => ['হাতে ধোয়া ভালো; রং আলাদা; ব্লিচ নয়।', 'ঝুলিয়ে শুকান; তীব্র রোদ এড়ান; কাপড় দিয়ে কম তাপে ইস্ত্রি।'],
        'info_brand' => 'হানতাং হুয়ায়ুন',
        'info_name' => 'ফুয়াও',
        'info_color' => 'কমলা সেট · সবুজ সেট',
        'info_style' => 'তাং শৈলী',
        'info_size' => 'S–XL',
        'info_fabric' => 'পলিয়েস্টার / শিফন',
        'info_parts' => 'এক-টুকরো বুক-উঁচু পো স্কার্ট (পাতলা উপরি স্তরসহ)',
        'info_title' => 'এক নজরে',
        'info_basics' => 'মূল',
        'info_comfort' => 'স্পর্শ',
        'label_brand' => 'ব্র্যান্ড',
        'label_name' => 'নাম',
        'label_color' => 'রঙ',
        'label_style' => 'শৈলী',
        'label_size' => 'সাইজ',
        'label_fabric' => 'কাপড়',
        'label_parts' => 'অংশ',
        'size_title' => 'সাইজ নোট',
        'size_body' => 'S, M, L ও XL। পৃষ্ঠার সাইজ নির্বাচক অনুসরণ করুন; হাতে মাপা সামান্য ভিন্ন হতে পারে।',
        'alt_hero' => 'ফুয়াও · পরা',
        'alt_look' => 'ফুয়াও · পরা',
        'alt_orange' => 'ফুয়াও · কমলা সেট',
        'alt_green' => 'ফুয়াও · সবুজ সেট',
        'alt_mid' => 'ফুয়াও · মধ্য দৃশ্য',
        'alt_fan' => 'ফুয়াও · পাখা',
        'c_thick' => 'পুরুত্ব',
        'c_thick_opts' => ['পাতলা', 'মাঝারি', 'মোটা'],
        'c_thick_sel' => 'পাতলা',
        'c_fit' => 'ফিট',
        'c_fit_opts' => ['টাইট', 'স্লিম', 'নিয়মিত', 'ঢিলা'],
        'c_fit_sel' => 'নিয়মিত',
        'c_soft' => 'স্পর্শ',
        'c_soft_opts' => ['মাঝারি', 'নরম', 'দৃঢ়', 'শক্ত'],
        'c_soft_sel' => 'নরম',
        'c_stretch' => 'স্থিতিস্থাপকতা',
        'c_stretch_opts' => ['নেই', 'হালকা', 'উচ্চ'],
        'c_stretch_sel' => 'নেই',
    ],
    'hi_IN' => [
        'intro_title' => 'फ़ुयाओ',
        'intro_body' => 'तांग शैली की एक-टुकड़ा छाती-ऊँची पो स्कर्ट: पतली चौड़ी आस्तीन ऊँची स्कर्ट पर। खुबानी नारंगी या हरा सेट चुनें—चलते हुए गोल प्रिंट खुलते हैं, दुपट्टा और फीते हल्के।',
        'inspire_title' => 'डिज़ाइन स्रोत',
        'inspire_lines' => ['हवा के साथ मीलों ऊपर—आस्तीन हवा पकड़ती है।', 'छाती पर पट्टी, पैनल: एक तांग रेखा।'],
        'inspire_note' => '“फ़ुयाओ” कट की आत्मा नाम है—बाज़ार की भाषा नहीं।',
        'highlight_title' => 'वस्त्र पर',
        'skirt_label' => 'छाती-ऊँची एक-टुकड़ा पो स्कर्ट',
        'skirt_body' => 'छाती पर ऊँची बँधी; पैनल साफ़ गिरते हैं; चलते हुए प्लीट और लंबाई पढ़ी जा सकती है।',
        'print_label' => 'गोल प्रिंट',
        'print_body' => 'स्कर्ट पर गोल रूप—पास से स्पष्ट, दूर से समृद्ध।',
        'sleeve_label' => 'पतली आस्तीन और दुपट्टा',
        'sleeve_body' => 'हल्की ऊपरी पोशाक; बाँह उठाने पर चौड़ी आस्तीन और दुपट्टा परत बनते हैं।',
        'colors_title' => 'दो स्वभाव',
        'colors_body' => 'नारंगी खुबानी-सा गर्म; हरा शांत सरसों और धुँआ नीला पैनल—एक कट, दो भाव।',
        'mid_title' => 'मध्य दृश्य',
        'mid_body' => 'छाती की गाँठें, पैनल रंग और आस्तीन परतें मध्य दूरी पर स्पष्ट।',
        'green_title' => 'हरा सेट',
        'green_body' => 'सरसों क्रॉस कॉलर, धुँआ नीला पैनल और नारंगी फीते—वही कट, ठंडा भाव।',
        'fan_title' => 'गोल पंखा',
        'fan_body' => 'सफ़ेद पृष्ठ पर कढ़ाई पंखा और प्रिंट एक-दूसरे से मेल खाते हैं—केवल स्टाइल।',
        'original_title' => 'मूल शिल्प',
        'original_body' => 'कट और प्रिंट हानतांग हुआयुन के मूल हैं। शिल्प का सम्मान करें।',
        'wash_title' => 'देखभाल',
        'wash_lines' => ['हाथ से धोएँ; रंग अलग; ब्लीच नहीं।', 'टाँगकर सुखाएँ; तेज़ धूप से बचें; कपड़े के साथ हल्का इस्तरी।'],
        'info_brand' => 'हानतांग हुआयुन',
        'info_name' => 'फ़ुयाओ',
        'info_color' => 'नारंगी सेट · हरा सेट',
        'info_style' => 'तांग शैली',
        'info_size' => 'S–XL',
        'info_fabric' => 'पॉलिएस्टर / शिफॉन',
        'info_parts' => 'एक-टुकड़ा छाती-ऊँची पो स्कर्ट (पतली ऊपरी परत सहित)',
        'info_title' => 'एक दृष्टि में',
        'info_basics' => 'मूल',
        'info_comfort' => 'स्पर्श',
        'label_brand' => 'ब्रांड',
        'label_name' => 'नाम',
        'label_color' => 'रंग',
        'label_style' => 'शैली',
        'label_size' => 'साइज़',
        'label_fabric' => 'कपड़ा',
        'label_parts' => 'भाग',
        'size_title' => 'साइज़ नोट',
        'size_body' => 'S, M, L और XL। पृष्ठ के साइज़ चयन का पालन करें; हाथ माप थोड़ा भिन्न हो सकता है।',
        'alt_hero' => 'फ़ुयाओ · पहना',
        'alt_look' => 'फ़ुयाओ · पहना',
        'alt_orange' => 'फ़ुयाओ · नारंगी सेट',
        'alt_green' => 'फ़ुयाओ · हरा सेट',
        'alt_mid' => 'फ़ुयाओ · मध्य दृश्य',
        'alt_fan' => 'फ़ुयाओ · पंखा',
        'c_thick' => 'मोटाई',
        'c_thick_opts' => ['पतला', 'मध्यम', 'मोटा'],
        'c_thick_sel' => 'पतला',
        'c_fit' => 'फ़िट',
        'c_fit_opts' => ['टाइट', 'स्लिम', 'नियमित', 'ढीला'],
        'c_fit_sel' => 'नियमित',
        'c_soft' => 'स्पर्श',
        'c_soft_opts' => ['मध्यम', 'नरम', 'दृढ़', 'कठोर'],
        'c_soft_sel' => 'नरम',
        'c_stretch' => 'खिंचाव',
        'c_stretch_opts' => ['नहीं', 'हल्का', 'अधिक'],
        'c_stretch_sel' => 'नहीं',
    ],
    'ur_PK' => [
        'intro_title' => 'فویاو',
        'intro_body' => 'تانگ طرز ایک ٹکڑا سینے تک پو اسکرٹ: پتلی چوڑی آستینیں اونچی اسکرٹ پر۔ خوبانی نارنجی یا سبز سیٹ چنیں—چلتے ہوئے گول پرنٹ کھلتے ہیں، دوپٹہ اور فیتے ہلکے۔',
        'inspire_title' => 'ڈیزائن کا ماخذ',
        'inspire_lines' => ['ہوا کے ساتھ میل دور اوپر—آستینیں ہوا پکڑتی ہیں۔', 'سینے پر پٹی، پینل: ایک تانگ لکیر۔'],
        'inspire_note' => '“فویاو” کٹ کی روح کا نام ہے—مارکیٹ پلیس زبان نہیں۔',
        'highlight_title' => 'لباس پر',
        'skirt_label' => 'سینے تک ایک ٹکڑا پو اسکرٹ',
        'skirt_body' => 'سینے پر اونچی بندھی؛ پینل صاف گرتے ہیں؛ چلتے ہوئے پلیٹ اور لمبائی پڑھی جا سکتی ہے۔',
        'print_label' => 'گول پرنٹ',
        'print_body' => 'اسکرٹ پر گول نقوش—قریب سے واضح، دور سے بھرپور۔',
        'sleeve_label' => 'پتلی آستینیں اور دوپٹہ',
        'sleeve_body' => 'ہلکا اوپری لباس؛ بازو اٹھانے پر چوڑی آستینیں اور دوپٹہ تہیں بناتے ہیں۔',
        'colors_title' => 'دو مزاج',
        'colors_body' => 'نارنجی خوبانی سا گرم؛ سبز پرسکون سرسوں اور دھواں نیلا پینل—ایک کٹ، دو کیفیت۔',
        'mid_title' => 'درمیانی منظر',
        'mid_body' => 'سینے کی گرہیں، پینل رنگ اور آستین کی تہیں درمیانی فاصلے پر واضح۔',
        'green_title' => 'سبز سیٹ',
        'green_body' => 'سرسوں کراس کالر، دھواں نیلا پینل اور نارنجی فیتے—وہی کٹ، ٹھنڈی کیفیت۔',
        'fan_title' => 'گول پنکھہ',
        'fan_body' => 'سفید پس منظر پر کڑھائی والا پنکھہ اور پرنٹ ایک دوسرے سے میل کھاتے ہیں—صرف اسٹائل۔',
        'original_title' => 'اصل دستکاری',
        'original_body' => 'کٹ اور پرنٹ ہان تانگ ہوایون کے اصل ہیں۔ دستکاری کا احترام کریں۔',
        'wash_title' => 'دیکھ بھال',
        'wash_lines' => ['ہاتھ سے دھوئیں؛ رنگ الگ؛ بلیچ نہیں۔', 'لٹکا کر خشک کریں؛ تیز دھوپ سے بچیں؛ کپڑے کے ساتھ ہلکی استری۔'],
        'info_brand' => 'ہان تانگ ہوایون',
        'info_name' => 'فویاو',
        'info_color' => 'نارنجی سیٹ · سبز سیٹ',
        'info_style' => 'تانگ طرز',
        'info_size' => 'S–XL',
        'info_fabric' => 'پالئیےسٹر / شفون',
        'info_parts' => 'ایک ٹکڑا سینے تک پو اسکرٹ (پتلی اوپری تہہ سمیت)',
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
        'size_title' => 'سائز نوٹ',
        'size_body' => 'S، M، L اور XL۔ صفحے کے سائز انتخاب کی پیروی کریں؛ ہاتھ پیمائش تھوڑی مختلف ہو سکتی ہے۔',
        'alt_hero' => 'فویاو · پہنا',
        'alt_look' => 'فویاو · پہنا',
        'alt_orange' => 'فویاو · نارنجی سیٹ',
        'alt_green' => 'فویاو · سبز سیٹ',
        'alt_mid' => 'فویاو · درمیانی منظر',
        'alt_fan' => 'فویاو · پنکھہ',
        'c_thick' => 'موٹائی',
        'c_thick_opts' => ['پتلا', 'درمیانہ', 'موٹا'],
        'c_thick_sel' => 'پتلا',
        'c_fit' => 'فٹ',
        'c_fit_opts' => ['تنگ', 'سلِم', 'عام', 'ڈھیلا'],
        'c_fit_sel' => 'عام',
        'c_soft' => 'لمس',
        'c_soft_opts' => ['درمیانہ', 'نرم', 'مضبوط', 'سخت'],
        'c_soft_sel' => 'نرم',
        'c_stretch' => 'کھینچاؤ',
        'c_stretch_opts' => ['نہیں', 'ہلکا', 'زیادہ'],
        'c_stretch_sel' => 'نہیں',
    ],
];

$assemble = static function (array $t) use ($A, $img, $figureStack, $h): string {
    // 原型：editorial → fullbleed → editorial → pair → editorial → stack_caption →
    //       editorial → pair → quiet → stack_caption → stack → quiet → checklist → spec → size
    // feature_lr=0；竖/方图一律 stack/fullbleed
    $intro = '<div class="weline-detail-prose"><h3>' . $h((string)$t['intro_title']) . '</h3><p>'
        . $h((string)$t['intro_body']) . '</p></div>';

    $hero = $figureStack([
        $img($A['hero']['id'], (string)$t['alt_hero'], $A['hero']['w'], $A['hero']['h']),
    ], 'weline-detail-figure-stack--fullbleed weline-detail-orient--portrait');

    $inspire = '<div class="weline-detail-prose"><h3>' . $h((string)$t['inspire_title']) . '</h3>';
    foreach ((array)$t['inspire_lines'] as $line) {
        $inspire .= '<p>' . $h((string)$line) . '</p>';
    }
    $inspire .= '<p class="weline-detail-feature__note">' . $h((string)$t['inspire_note']) . '</p></div>';

    $lookPair = $figureStack([
        $img($A['look02']['id'], (string)$t['alt_look'] . ' 1', $A['look02']['w'], $A['look02']['h']),
        $img($A['look03']['id'], (string)$t['alt_look'] . ' 2', $A['look03']['w'], $A['look03']['h']),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait');

    $highlight = '<div class="weline-detail-prose"><h3>' . $h((string)$t['highlight_title']) . '</h3>'
        . '<h4>' . $h((string)$t['skirt_label']) . '</h4><p>' . $h((string)$t['skirt_body']) . '</p>'
        . '<h4>' . $h((string)$t['print_label']) . '</h4><p>' . $h((string)$t['print_body']) . '</p>'
        . '<h4>' . $h((string)$t['sleeve_label']) . '</h4><p>' . $h((string)$t['sleeve_body']) . '</p></div>';

    $midStack = $figureStack([
        $img($A['mid']['id'], (string)$t['alt_mid'], $A['mid']['w'], $A['mid']['h']),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['mid_title']) . '</h3><p>'
        . $h((string)$t['mid_body']) . '</p></div>';

    $colors = '<div class="weline-detail-prose"><h3>' . $h((string)$t['colors_title']) . '</h3><p>'
        . $h((string)$t['colors_body']) . '</p></div>'
        . $figureStack([
            $img($A['orange']['id'], (string)$t['alt_orange'], $A['orange']['w'], $A['orange']['h']),
            $img($A['green']['id'], (string)$t['alt_green'], $A['green']['w'], $A['green']['h']),
        ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait');

    $quiet = '<div class="weline-detail-quiet-spacer" aria-hidden="true"></div>';

    $greenStack = $figureStack([
        $img($A['greenMid']['id'], (string)$t['alt_green'] . ' mid', $A['greenMid']['w'], $A['greenMid']['h']),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--squareish')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['green_title']) . '</h3><p>'
        . $h((string)$t['green_body']) . '</p></div>';

    $greenLook = $figureStack([
        $img($A['greenLook']['id'], (string)$t['alt_green'] . ' 2', $A['greenLook']['w'], $A['greenLook']['h']),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait');

    $fan = $figureStack([
        $img($A['fan']['id'], (string)$t['alt_fan'], $A['fan']['w'], $A['fan']['h']),
        $img($A['look05']['id'], (string)$t['alt_look'] . ' 3', $A['look05']['w'], $A['look05']['h']),
    ], 'weline-detail-figure-stack--caption weline-detail-orient--portrait')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['fan_title']) . '</h3><p>'
        . $h((string)$t['fan_body']) . '</p></div>';

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

    $size = '<div class="weline-detail-prose"><h3>' . $h((string)$t['size_title']) . '</h3><p>'
        . $h((string)$t['size_body']) . '</p></div>';

    return '<div data-weline-product-description="1688" data-weds="xq">'
        . '<!--weds:xq--><span data-weds="xq" hidden aria-hidden="true"></span>'
        . $intro . $hero . $inspire . $lookPair . $highlight . $midStack
        . $quiet . $colors . $quiet . $greenStack . $greenLook . $fan
        . $original . $wash . $info . $size
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
    'detail_locale_true_translate_173',
    ['product_ids' => [$productId]],
);
ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class)
    ->clearForCatalogChange('detail_locale_true_translate_173');

echo "Applied description-only writes: " . count($writes) . " locales.\n";
