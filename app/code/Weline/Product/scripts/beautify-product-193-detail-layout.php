<?php

declare(strict_types=1);

/**
 * #193 桃花神 · ecommerce-detail-suite 试跑（多样美学 · 禁左右刷屏）
 *
 * 原型序列（落码锁）：
 * fullbleed_hero → editorial_prose(lead) → editorial_prose(verse) → pair_gallery
 * → stack_caption → macro_annotate → feature_lr#1 → triptych → quiet_spacer
 * → checklist_trust → feature_lr#2 → spec_panel → editorial_prose(original)
 * → fullbleed_hero(close)
 *
 * feature_lr 共 2 次，且不相邻。烤字 detail-01/02 抽文删图；无 DesignKit 不生图。
 *
 * php app/code/Weline/Product/scripts/beautify-product-193-detail-layout.php --apply
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
$productId = 193;

/**
 * 分流：
 * 净实拍入图：gallery 01–05、detail-03/04/06/07/08/09
 * 烤字抽文删图：detail-01（设计亮点/领子）、detail-02（袖口/绣花）
 * detail-05 粉边装饰框不入图
 */
$A = [
    'hero' => '1417d2fe-3119-4b6a-aefd-51fb4f2b79ad', // detail-09 院落通栏
    'look_stage' => '68b435a4-7dac-48c0-877c-594be793051c', // detail-03
    'look_runway' => '068c0b75-d07a-4891-bb63-2ac2b47d7f9f', // detail-06
    'look_mid' => '6b41bb58-ce2f-4906-86a7-7db334e56a9d', // detail-07
    'look_profile' => '75958d62-11c7-4ed2-8b59-d4c645e6ed3f', // detail-08
    'look_duo' => 'd19c117c-7b5f-4628-8aa4-77d61213691d', // detail-04
    'g02' => 'e18573cf-4ee3-41eb-8d2d-92e7a94702c3',
    'g04' => '97a790be-d8fe-4cd9-87e4-e7e3edf10df4',
    'g05' => '1e5f4698-958b-45d3-ad9c-63f6b8df5c40',
    'main' => '3dc9d4d4-33eb-4830-b43d-a110d6db56cf', // gallery 01
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
    $rowMod = $n >= 3 ? 'triptych' : ($n === 2 ? 'pair' : 'solo');
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
        'intro_title' => '桃花神',
        'intro_body' => '唐制齐胸套装：大袖衫与裙子。粉白轻纱重工桃花绣，广袖与褶影如春风过枝。',
        'inspire_title' => '设计心源',
        'inspire_lines' => [
            '桃之夭夭，灼灼其华。',
            '红袖翻霞落，珠缘点春芽。',
        ],
        'inspire_note' => '以「桃花神」为题眼——绯纱、珠缘与蝶饰相映；非平台货盘说辞，仅为形制与绣意之点题。',
        'stack_title' => '齐胸绣意',
        'stack_body' => '近观胸口桃花绣与珠缘，丝绦束结端丽，层纱透出淡粉晕染。',
        'macro_title' => '细处可辨',
        'collar_label' => '领缘',
        'collar_body' => '领缘铺珠与细绣，蝶饰点于胸前，层次分明。',
        'cuff_label' => '袖口',
        'cuff_body' => '广袖透纱，袖面桃花疏密有致，抬臂时花影浮动。',
        'emb_label' => '绣花',
        'emb_body' => '粉白花瓣与金绿枝叶相间，珠缘网饰托出绣面。',
        'sleeve_title' => '广袖纱影',
        'sleeve_body' => '大袖轻扬，绯白渐变随步生风；袖缘与门襟线脚干净。',
        'triptych_note' => '三帧着装气韵',
        'quiet_line' => '春色在衣，不在喧哗。',
        'checklist_title' => '穿着感受',
        'checklist' => [
            '轻纱层叠，走动时褶影柔软。',
            '齐胸束结稳妥，广袖便于展袖起舞。',
            '绣面与珠缘近处可辨，忌粗暴揉搓。',
        ],
        'hem_title' => '裙裾层叠',
        'hem_body' => '裙裳多层轻纱由浅入深，裾边随步起伏，如花瓣层开。',
        'original_title' => '原创心迹',
        'original_body' => '本款形制与刺绣为花朝记原创设计，敬请珍惜衣冠、尊重匠心。',
        'close_caption' => '粉白交映，唐风一袭。',
        'info_brand' => '花朝记',
        'info_name' => '桃花神',
        'info_color' => '如图（粉白绯纱）',
        'info_style' => '唐制',
        'info_size' => 'S–2XL',
        'info_fabric' => '轻纱质感（如图）',
        'info_parts' => '大袖衫、裙子',
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
        'size_body' => '可选 S、M、L、XL、2XL。请以页面尺码选择为准；手工测量衣身或有轻微出入。',
        'alt_hero' => '桃花神 · 通栏',
        'alt_look' => '桃花神 · 着装',
        'alt_mid' => '桃花神 · 齐胸细部',
        'alt_sleeve' => '桃花神 · 广袖',
        'alt_hem' => '桃花神 · 裙裾',
        'alt_close' => '桃花神 · 收束',
        'c_thick' => '厚度',
        'c_thick_opts' => ['薄', '适中', '厚'],
        'c_thick_sel' => '薄',
        'c_stretch' => '弹性',
        'c_stretch_opts' => ['无弹', '微弹', '高弹'],
        'c_stretch_sel' => '无弹',
    ],
    'en_US' => [
        'intro_title' => 'Peach Blossom Goddess',
        'intro_body' => 'A Tang-style chest-high set: large-sleeve robe and skirt. Sheer pink-and-white layers with peach blossom embroidery; sleeves and pleats move like spring wind.',
        'inspire_title' => 'Design wellspring',
        'inspire_lines' => [
            'The peach tree is young and elegant; brilliant are its flowers.',
            'Scarlet sleeves turn like dawn cloud; pearl edges mark spring buds.',
        ],
        'inspire_note' => 'Named for the Peach Blossom Goddess—sheer gauze, pearl trim, and butterfly accent. Emblem for cut and stitch, not marketplace copy.',
        'stack_title' => 'Chest embroidery',
        'stack_body' => 'Peach blossoms and pearl edges at the chest; the sash sits cleanly, soft pink showing through layered sheer.',
        'macro_title' => 'Close looking',
        'collar_label' => 'Collar',
        'collar_body' => 'Pearl-lined collar with fine stitch; a butterfly rests at the chest.',
        'cuff_label' => 'Cuffs',
        'cuff_body' => 'Wide sheer sleeves with peach blossoms spaced across the cloth—flowers shift as the arm lifts.',
        'emb_label' => 'Embroidery',
        'emb_body' => 'Pink-white petals with gold-green stems; pearl mesh frames the stitch.',
        'sleeve_title' => 'Wide sleeves',
        'sleeve_body' => 'Large sleeves lift lightly; pink-to-white gradients follow the step; cuff and front edges stay clean.',
        'triptych_note' => 'Three looks',
        'quiet_line' => 'Spring lives in the cloth, not in noise.',
        'checklist_title' => 'Hand feel',
        'checklist' => [
            'Layered sheer; soft pleats when you walk.',
            'Chest-high wrap stays secure; sleeves open for dance.',
            'Embroidery and pearls are clear up close—handle gently.',
        ],
        'hem_title' => 'Layered hem',
        'hem_body' => 'Sheer skirt layers deepen fold by fold; the hem rises and falls like opening petals.',
        'original_title' => 'Original craft',
        'original_body' => 'Cut and embroidery are original to Huazhaoji. Please honor the craft.',
        'close_caption' => 'Pink and white, one Tang-style robe.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Peach Blossom Goddess',
        'info_color' => 'As shown (pink-white sheer)',
        'info_style' => 'Tang style',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Sheer hand-feel (as shown)',
        'info_parts' => 'Large-sleeve robe, skirt',
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
        'size_title' => 'Size note',
        'size_body' => 'S, M, L, XL, 2XL. Follow the on-page size selector; hand measure may vary slightly.',
        'alt_hero' => 'Peach Blossom Goddess · hero',
        'alt_look' => 'Peach Blossom Goddess · worn',
        'alt_mid' => 'Peach Blossom Goddess · chest detail',
        'alt_sleeve' => 'Peach Blossom Goddess · sleeves',
        'alt_hem' => 'Peach Blossom Goddess · hem',
        'alt_close' => 'Peach Blossom Goddess · close',
        'c_thick' => 'Thickness',
        'c_thick_opts' => ['Thin', 'Medium', 'Thick'],
        'c_thick_sel' => 'Thin',
        'c_stretch' => 'Stretch',
        'c_stretch_opts' => ['None', 'Slight', 'High'],
        'c_stretch_sel' => 'None',
    ],
    'es_ES' => [
        'intro_title' => 'Diosa del Melocotón',
        'intro_body' => 'Conjunto tang de pecho alto: túnica de mangas anchas y falda. Gasa rosa-blanca con bordado de flores de melocotón; mangas y pliegues se mueven como viento de primavera.',
        'inspire_title' => 'Fuente del diseño',
        'inspire_lines' => [
            'El melocotonero es joven y elegante; brillan sus flores.',
            'Mangas escarlata giran como alba; perlas marcan brotes de primavera.',
        ],
        'inspire_note' => 'Titulado por la Diosa del Melocotón: gasa, perlas y mariposa. Emblema de corte y bordado, no texto de marketplace.',
        'stack_title' => 'Bordado al pecho',
        'stack_body' => 'Flores y perlas en el pecho; el lazo asienta limpio y el rosa suave se ve entre capas.',
        'macro_title' => 'De cerca',
        'collar_label' => 'Cuello',
        'collar_body' => 'Cuello con perlas y punto fino; mariposa al pecho.',
        'cuff_label' => 'Puños',
        'cuff_body' => 'Mangas anchas traslúcidas con flores espaciadas; se mueven al alzar el brazo.',
        'emb_label' => 'Bordado',
        'emb_body' => 'Pétalos rosa-blanco con tallos dorado-verde; malla de perlas enmarca la labor.',
        'sleeve_title' => 'Mangas anchas',
        'sleeve_body' => 'Mangas ligeras; degradado rosa-blanco al caminar; bordes limpios.',
        'triptych_note' => 'Tres miradas',
        'quiet_line' => 'La primavera vive en la tela, no en el ruido.',
        'checklist_title' => 'Sensación al llevar',
        'checklist' => [
            'Gasa en capas; pliegues suaves al caminar.',
            'Cierre al pecho estable; mangas abiertas para danzar.',
            'Bordado y perlas visibles de cerca—tratar con cuidado.',
        ],
        'hem_title' => 'Bajo en capas',
        'hem_body' => 'Capas de falda que se profundizan; el bajo sube y baja como pétalos.',
        'original_title' => 'Oficio original',
        'original_body' => 'Corte y bordado son originales de Huazhaoji. Honre el oficio.',
        'close_caption' => 'Rosa y blanco, una túnica tang.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Diosa del Melocotón',
        'info_color' => 'Como en la imagen (gasa rosa-blanca)',
        'info_style' => 'Estilo Tang',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Tacto de gasa (como se ve)',
        'info_parts' => 'Túnica de mangas anchas, falda',
        'info_title' => 'De un vistazo',
        'info_basics' => 'Básico',
        'info_comfort' => 'Sensación',
        'label_brand' => 'Marca',
        'label_name' => 'Nombre',
        'label_color' => 'Color',
        'label_style' => 'Estilo',
        'label_size' => 'Tallas',
        'label_fabric' => 'Tela',
        'label_parts' => 'Piezas',
        'size_title' => 'Nota de talla',
        'size_body' => 'S, M, L, XL, 2XL. Siga el selector de la página; la medida a mano puede variar.',
        'alt_hero' => 'Diosa del Melocotón · hero',
        'alt_look' => 'Diosa del Melocotón · puesta',
        'alt_mid' => 'Diosa del Melocotón · pecho',
        'alt_sleeve' => 'Diosa del Melocotón · mangas',
        'alt_hem' => 'Diosa del Melocotón · bajo',
        'alt_close' => 'Diosa del Melocotón · cierre',
        'c_thick' => 'Grosor',
        'c_thick_opts' => ['Fino', 'Medio', 'Grueso'],
        'c_thick_sel' => 'Fino',
        'c_stretch' => 'Elasticidad',
        'c_stretch_opts' => ['Ninguna', 'Leve', 'Alta'],
        'c_stretch_sel' => 'Ninguna',
    ],
    'fr_FR' => [
        'intro_title' => 'Déesse Pêcher',
        'intro_body' => 'Ensemble tang à la poitrine : robe à larges manches et jupe. Mousseline rose-blanc brodée de fleurs de pêcher ; manches et plis bougent comme un vent de printemps.',
        'inspire_title' => 'Source du dessin',
        'inspire_lines' => [
            'Le pêcher est jeune et élégant ; ses fleurs brillent.',
            'Manches écarlates comme l’aube ; perles sur les bourgeons.',
        ],
        'inspire_note' => 'Nommé Déesse Pêcher—mousseline, perles et papillon. Emblème de coupe et broderie, non texte de marketplace.',
        'stack_title' => 'Broderie poitrine',
        'stack_body' => 'Fleurs et perles à la poitrine ; le lien est net, le rose doux transparait.',
        'macro_title' => 'De près',
        'collar_label' => 'Col',
        'collar_body' => 'Col perlé et point fin ; papillon à la poitrine.',
        'cuff_label' => 'Poignets',
        'cuff_body' => 'Larges manches voile à fleurs espacées ; elles bougent au lever du bras.',
        'emb_label' => 'Broderie',
        'emb_body' => 'Pétales rose-blanc, tiges or-vert ; filet de perles.',
        'sleeve_title' => 'Larges manches',
        'sleeve_body' => 'Manches légères ; dégradé rose-blanc à la marche ; bords nets.',
        'triptych_note' => 'Trois regards',
        'quiet_line' => 'Le printemps vit dans l’étoffe, non dans le bruit.',
        'checklist_title' => 'Au porter',
        'checklist' => [
            'Voile en couches ; plis doux en marchant.',
            'Nœud poitrine stable ; manches ouvertes pour danser.',
            'Broderie et perles visibles de près—manipuler avec soin.',
        ],
        'hem_title' => 'Ourlet en couches',
        'hem_body' => 'Couches de jupe qui s’approfondissent ; l’ourlet monte et descend comme des pétales.',
        'original_title' => 'Création originale',
        'original_body' => 'Coupe et broderie originales Huazhaoji. Honorez le savoir-faire.',
        'close_caption' => 'Rose et blanc, une robe tang.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Déesse Pêcher',
        'info_color' => 'Comme sur la photo (voile rose-blanc)',
        'info_style' => 'Style Tang',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Toucher voile (comme vu)',
        'info_parts' => 'Robe à larges manches, jupe',
        'info_title' => 'En un coup d’œil',
        'info_basics' => 'Base',
        'info_comfort' => 'Sensation',
        'label_brand' => 'Marque',
        'label_name' => 'Nom',
        'label_color' => 'Couleur',
        'label_style' => 'Style',
        'label_size' => 'Tailles',
        'label_fabric' => 'Tissu',
        'label_parts' => 'Pièces',
        'size_title' => 'Note de taille',
        'size_body' => 'S, M, L, XL, 2XL. Suivez le sélecteur de la page ; mesure à la main peut varier.',
        'alt_hero' => 'Déesse Pêcher · hero',
        'alt_look' => 'Déesse Pêcher · portée',
        'alt_mid' => 'Déesse Pêcher · poitrine',
        'alt_sleeve' => 'Déesse Pêcher · manches',
        'alt_hem' => 'Déesse Pêcher · ourlet',
        'alt_close' => 'Déesse Pêcher · clôture',
        'c_thick' => 'Épaisseur',
        'c_thick_opts' => ['Fin', 'Moyen', 'Épais'],
        'c_thick_sel' => 'Fin',
        'c_stretch' => 'Élasticité',
        'c_stretch_opts' => ['Aucune', 'Légère', 'Forte'],
        'c_stretch_sel' => 'Aucune',
    ],
    'pt_BR' => [
        'intro_title' => 'Deusa do Pêssego',
        'intro_body' => 'Conjunto tang de peito alto: túnica de mangas largas e saia. Gaze rosa-branca com bordado de flor de pêssego; mangas e pregas se movem como vento de primavera.',
        'inspire_title' => 'Fonte do desenho',
        'inspire_lines' => [
            'A pereira é jovem e elegante; brilham suas flores.',
            'Mangas escarlates como o amanhecer; pérolas marcam brotos.',
        ],
        'inspire_note' => 'Nomeada Deusa do Pêssego—gaze, pérolas e borboleta. Emblema de corte e bordado, não texto de marketplace.',
        'stack_title' => 'Bordado no peito',
        'stack_body' => 'Flores e pérolas no peito; o laço assenta limpo e o rosa suave aparece entre camadas.',
        'macro_title' => 'De perto',
        'collar_label' => 'Gola',
        'collar_body' => 'Gola com pérolas e ponto fino; borboleta no peito.',
        'cuff_label' => 'Punhos',
        'cuff_body' => 'Mangas largas transparentes com flores espaçadas; movem-se ao erguer o braço.',
        'emb_label' => 'Bordado',
        'emb_body' => 'Pétalas rosa-branco com caules ouro-verde; malha de pérolas emoldura.',
        'sleeve_title' => 'Mangas largas',
        'sleeve_body' => 'Mangas leves; degradê rosa-branco ao caminhar; bordas limpas.',
        'triptych_note' => 'Três olhares',
        'quiet_line' => 'A primavera vive no tecido, não no barulho.',
        'checklist_title' => 'Ao vestir',
        'checklist' => [
            'Gaze em camadas; pregas suaves ao andar.',
            'Nó no peito estável; mangas abertas para dançar.',
            'Bordado e pérolas visíveis de perto—manuseie com cuidado.',
        ],
        'hem_title' => 'Bainha em camadas',
        'hem_body' => 'Camadas de saia que se aprofundam; a bainha sobe e desce como pétalas.',
        'original_title' => 'Ofício original',
        'original_body' => 'Corte e bordado originais da Huazhaoji. Honre o ofício.',
        'close_caption' => 'Rosa e branco, uma túnica tang.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Deusa do Pêssego',
        'info_color' => 'Como na imagem (gaze rosa-branca)',
        'info_style' => 'Estilo Tang',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Toque de gaze (como visto)',
        'info_parts' => 'Túnica de mangas largas, saia',
        'info_title' => 'Em um olhar',
        'info_basics' => 'Básico',
        'info_comfort' => 'Sensação',
        'label_brand' => 'Marca',
        'label_name' => 'Nome',
        'label_color' => 'Cor',
        'label_style' => 'Estilo',
        'label_size' => 'Tamanhos',
        'label_fabric' => 'Tecido',
        'label_parts' => 'Peças',
        'size_title' => 'Nota de tamanho',
        'size_body' => 'S, M, L, XL, 2XL. Siga o seletor da página; medida à mão pode variar.',
        'alt_hero' => 'Deusa do Pêssego · hero',
        'alt_look' => 'Deusa do Pêssego · vestida',
        'alt_mid' => 'Deusa do Pêssego · peito',
        'alt_sleeve' => 'Deusa do Pêssego · mangas',
        'alt_hem' => 'Deusa do Pêssego · bainha',
        'alt_close' => 'Deusa do Pêssego · fecho',
        'c_thick' => 'Espessura',
        'c_thick_opts' => ['Fino', 'Médio', 'Grosso'],
        'c_thick_sel' => 'Fino',
        'c_stretch' => 'Elasticidade',
        'c_stretch_opts' => ['Nenhuma', 'Leve', 'Alta'],
        'c_stretch_sel' => 'Nenhuma',
    ],
    'id_ID' => [
        'intro_title' => 'Dewi Bunga Persik',
        'intro_body' => 'Set gaya Tang setinggi dada: jubah lengan lebar dan rok. Kain tipis merah muda-putih dengan bordir bunga persik; lengan dan lipatan bergerak seperti angin musim semi.',
        'inspire_title' => 'Sumber desain',
        'inspire_lines' => [
            'Pohon persik muda dan anggun; bunganya bersinar.',
            'Lengan merah berputar seperti fajar; mutiara menandai tunas musim semi.',
        ],
        'inspire_note' => 'Dinamai Dewi Bunga Persik—kain tipis, mutiara, dan kupu-kupu. Lambang potongan dan jahitan, bukan teks marketplace.',
        'stack_title' => 'Bordir dada',
        'stack_body' => 'Bunga dan mutiara di dada; ikat rapi, merah muda lembut terlihat di antara lapisan.',
        'macro_title' => 'Dari dekat',
        'collar_label' => 'Kerah',
        'collar_body' => 'Kerah bermutiara dan jahitan halus; kupu-kupu di dada.',
        'cuff_label' => 'Manset',
        'cuff_body' => 'Lengan lebar transparan dengan bunga tersebar; bergerak saat lengan diangkat.',
        'emb_label' => 'Bordir',
        'emb_body' => 'Kelopak merah muda-putih dengan batang emas-hijau; jaring mutiara membingkai.',
        'sleeve_title' => 'Lengan lebar',
        'sleeve_body' => 'Lengan ringan; gradasi merah muda-putih saat melangkah; tepi bersih.',
        'triptych_note' => 'Tiga tampilan',
        'quiet_line' => 'Musim semi hidup di kain, bukan di keributan.',
        'checklist_title' => 'Saat dipakai',
        'checklist' => [
            'Kain tipis berlapis; lipatan lembut saat berjalan.',
            'Ikatan dada stabil; lengan terbuka untuk menari.',
            'Bordir dan mutiara jelas dari dekat—tangani dengan lembut.',
        ],
        'hem_title' => 'Hem berlapis',
        'hem_body' => 'Lapisan rok semakin dalam; hem naik turun seperti kelopak.',
        'original_title' => 'Kriya asli',
        'original_body' => 'Potongan dan bordir asli Huazhaoji. Hormati kriya.',
        'close_caption' => 'Merah muda dan putih, satu jubah Tang.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Dewi Bunga Persik',
        'info_color' => 'Seperti gambar (tipis merah muda-putih)',
        'info_style' => 'Gaya Tang',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Nuansa kain tipis (seperti terlihat)',
        'info_parts' => 'Jubah lengan lebar, rok',
        'info_title' => 'Sekilas',
        'info_basics' => 'Dasar',
        'info_comfort' => 'Rasa pakai',
        'label_brand' => 'Merek',
        'label_name' => 'Nama',
        'label_color' => 'Warna',
        'label_style' => 'Gaya',
        'label_size' => 'Ukuran',
        'label_fabric' => 'Kain',
        'label_parts' => 'Bagian',
        'size_title' => 'Catatan ukuran',
        'size_body' => 'S, M, L, XL, 2XL. Ikuti pemilih ukuran di halaman; ukur tangan bisa sedikit berbeda.',
        'alt_hero' => 'Dewi Bunga Persik · hero',
        'alt_look' => 'Dewi Bunga Persik · dikenakan',
        'alt_mid' => 'Dewi Bunga Persik · dada',
        'alt_sleeve' => 'Dewi Bunga Persik · lengan',
        'alt_hem' => 'Dewi Bunga Persik · hem',
        'alt_close' => 'Dewi Bunga Persik · penutup',
        'c_thick' => 'Ketebalan',
        'c_thick_opts' => ['Tipis', 'Sedang', 'Tebal'],
        'c_thick_sel' => 'Tipis',
        'c_stretch' => 'Elastisitas',
        'c_stretch_opts' => ['Tidak', 'Sedikit', 'Tinggi'],
        'c_stretch_sel' => 'Tidak',
    ],
    'ar_SA' => [
        'intro_title' => 'إلهة الخوخ',
        'intro_body' => 'طقم تانغ مرتفع الصدر: رداء بأكمام واسعة وتنورة. شاش وردي-أبيض بتطريز زهر الخوخ؛ الأكمام والطيات تتحرك كريح الربيع.',
        'inspire_title' => 'منبع التصميم',
        'inspire_lines' => [
            'شجرة الخوخ يافعة وأنيقة؛ أزهارها تتلألأ.',
            'أكمام قرمزية تدور كالفجر؛ لآلئ تعلّم براعم الربيع.',
        ],
        'inspire_note' => 'باسم إلهة الخوخ—شاش ولآلئ وفراشة. رمز للقص والتطريز، لا نص سوق.',
        'stack_title' => 'تطريز الصدر',
        'stack_body' => 'زهر ولآلئ على الصدر؛ الرباط نظيف والوردي الناعم يظهر بين الطبقات.',
        'macro_title' => 'من قرب',
        'collar_label' => 'الياقة',
        'collar_body' => 'ياقة بلآلئ وغرزة دقيقة؛ فراشة على الصدر.',
        'cuff_label' => 'الأساور',
        'cuff_body' => 'أكمام واسعة شفافة بزهر متباعد؛ تتحرك عند رفع الذراع.',
        'emb_label' => 'التطريز',
        'emb_body' => 'بتلات وردي-أبيض بسيقان ذهبية-خضراء؛ شبكة لآلئ تؤطر العمل.',
        'sleeve_title' => 'أكمام واسعة',
        'sleeve_body' => 'أكمام خفيفة؛ تدرّج وردي-أبيض مع الخطوة؛ حواف نظيفة.',
        'triptych_note' => 'ثلاث نظرات',
        'quiet_line' => 'الربيع يعيش في القماش لا في الضجيج.',
        'checklist_title' => 'إحساس الارتداء',
        'checklist' => [
            'شاش متعدد الطبقات؛ طيات ناعمة عند المشي.',
            'ربط الصدر ثابت؛ أكمام مفتوحة للرقص.',
            'التطريز واللآلئ واضحة من قرب—تعامل بلطف.',
        ],
        'hem_title' => 'ذيل متعدد الطبقات',
        'hem_body' => 'طبقات التنورة تتعمق طية تلو أخرى؛ الذيل يرتفع ويهبط كبتلات.',
        'original_title' => 'حرفة أصلية',
        'original_body' => 'القص والتطريز أصليان من هواژاوجي. أكرموا الحرفة.',
        'close_caption' => 'وردي وأبيض، رداء تانغ واحد.',
        'info_brand' => 'هواژاوجي',
        'info_name' => 'إلهة الخوخ',
        'info_color' => 'كما في الصورة (شاش وردي-أبيض)',
        'info_style' => 'أسلوب تانغ',
        'info_size' => 'S–2XL',
        'info_fabric' => 'ملمس شاش (كما يُرى)',
        'info_parts' => 'رداء بأكمام واسعة، تنورة',
        'info_title' => 'لمحة سريعة',
        'info_basics' => 'أساسي',
        'info_comfort' => 'الإحساس',
        'label_brand' => 'العلامة',
        'label_name' => 'الاسم',
        'label_color' => 'اللون',
        'label_style' => 'الطراز',
        'label_size' => 'المقاسات',
        'label_fabric' => 'القماش',
        'label_parts' => 'الأجزاء',
        'size_title' => 'ملاحظة المقاس',
        'size_body' => 'S وM وL وXL و2XL. اتبع محدد المقاس في الصفحة؛ القياس اليدوي قد يختلف قليلاً.',
        'alt_hero' => 'إلهة الخوخ · رئيسي',
        'alt_look' => 'إلهة الخوخ · مرتدى',
        'alt_mid' => 'إلهة الخوخ · صدر',
        'alt_sleeve' => 'إلهة الخوخ · أكمام',
        'alt_hem' => 'إلهة الخوخ · ذيل',
        'alt_close' => 'إلهة الخوخ · ختام',
        'c_thick' => 'السماكة',
        'c_thick_opts' => ['رقيق', 'متوسط', 'سميك'],
        'c_thick_sel' => 'رقيق',
        'c_stretch' => 'المرونة',
        'c_stretch_opts' => ['لا', 'خفيف', 'عالٍ'],
        'c_stretch_sel' => 'لا',
    ],
    'bn_BD' => [
        'intro_title' => 'পিচ ব্লসম দেবী',
        'intro_body' => 'তাং শৈলীর বুক-উচ্চ সেট: চওড়া হাতার জামা ও স্কার্ট। গোলাপি-সাদা পাতলা কাপড়ে পিচ ফুলের সূচিকর্ম; হাতা ও ভাঁজ বসন্তের হাওয়ার মতো নড়ে।',
        'inspire_title' => 'নকশার উৎস',
        'inspire_lines' => [
            'পিচ গাছ তরুণ ও মার্জিত; তার ফুল জ্বলে।',
            'লাল হাতা ভোরের মেঘের মতো; মুক্তা বসন্তের কুঁড়ি চিহ্নিত করে।',
        ],
        'inspire_note' => 'পিচ ব্লসম দেবী নামে—পাতলা কাপড়, মুক্তা ও প্রজাপতি। কাট ও সেলাইয়ের প্রতীক, বাজারের কপি নয়।',
        'stack_title' => 'বুকের সূচিকর্ম',
        'stack_body' => 'বুকে ফুল ও মুক্তা; ফিতা পরিষ্কার, নরম গোলাপি স্তরের মধ্যে দেখা যায়।',
        'macro_title' => 'কাছ থেকে',
        'collar_label' => 'কলার',
        'collar_body' => 'মুক্তাযুক্ত কলার ও সূক্ষ্ম সেলাই; বুকে প্রজাপতি।',
        'cuff_label' => 'কাফ',
        'cuff_body' => 'চওড়া স্বচ্ছ হাতায় ছড়ানো ফুল; হাত তুললে নড়ে।',
        'emb_label' => 'সূচিকর্ম',
        'emb_body' => 'গোলাপি-সাদা পাপড়ি ও সোনালি-সবুজ ডাঁটা; মুক্তার জাল ঘিরে রাখে।',
        'sleeve_title' => 'চওড়া হাতা',
        'sleeve_body' => 'হালকা হাতা; হাঁটলে গোলাপি-সাদা গ্রেডিয়েন্ট; কিনারা পরিষ্কার।',
        'triptych_note' => 'তিনটি দৃশ্য',
        'quiet_line' => 'বসন্ত কাপড়ে বাস করে, হট্টগোলে নয়।',
        'checklist_title' => 'পরার অনুভূতি',
        'checklist' => [
            'স্তরীকৃত পাতলা কাপড়; হাঁটলে নরম ভাঁজ।',
            'বুকের বাঁধন স্থির; নাচের জন্য হাতা খোলা।',
            'সূচিকর্ম ও মুক্তা কাছ থেকে স্পষ্ট—সাবধানে ধরুন।',
        ],
        'hem_title' => 'স্তরীকৃত হেম',
        'hem_body' => 'স্কার্টের স্তর ক্রমে গভীর হয়; হেম পাপড়ির মতো ওঠে নামে।',
        'original_title' => 'মূল কারুকাজ',
        'original_body' => 'কাট ও সূচিকর্ম হুয়াঝাওজির মূল। কারুকাজকে সম্মান করুন।',
        'close_caption' => 'গোলাপি ও সাদা, একটি তাং জামা।',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'পিচ ব্লসম দেবী',
        'info_color' => 'ছবি অনুযায়ী (গোলাপি-সাদা পাতলা)',
        'info_style' => 'তাং শৈলী',
        'info_size' => 'S–2XL',
        'info_fabric' => 'পাতলা অনুভূতি (যেমন দেখা যায়)',
        'info_parts' => 'চওড়া হাতার জামা, স্কার্ট',
        'info_title' => 'এক নজরে',
        'info_basics' => 'মৌলিক',
        'info_comfort' => 'অনুভূতি',
        'label_brand' => 'ব্র্যান্ড',
        'label_name' => 'নাম',
        'label_color' => 'রঙ',
        'label_style' => 'শৈলী',
        'label_size' => 'সাইজ',
        'label_fabric' => 'কাপড়',
        'label_parts' => 'অংশ',
        'size_title' => 'সাইজ নোট',
        'size_body' => 'S, M, L, XL, 2XL। পৃষ্ঠার সাইজ নির্বাচক অনুসরণ করুন; হাতের মাপে সামান্য ফারাক হতে পারে।',
        'alt_hero' => 'পিচ ব্লসম দেবী · মূল',
        'alt_look' => 'পিচ ব্লসম দেবী · পরা',
        'alt_mid' => 'পিচ ব্লসম দেবী · বুক',
        'alt_sleeve' => 'পিচ ব্লসম দেবী · হাতা',
        'alt_hem' => 'পিচ ব্লসম দেবী · হেম',
        'alt_close' => 'পিচ ব্লসম দেবী · সমাপ্তি',
        'c_thick' => 'পুরুত্ব',
        'c_thick_opts' => ['পাতলা', 'মাঝারি', 'মোটা'],
        'c_thick_sel' => 'পাতলা',
        'c_stretch' => 'স্থিতিস্থাপকতা',
        'c_stretch_opts' => ['নেই', 'সামান্য', 'বেশি'],
        'c_stretch_sel' => 'নেই',
    ],
    'hi_IN' => [
        'intro_title' => 'आड़ू फूल देवी',
        'intro_body' => 'तांग शैली की छाती-ऊँची सेट: चौड़ी आस्तीन की पोशाक और स्कर्ट। गुलाबी-सफ़ेद हल्के कपड़े पर आड़ू फूल की कढ़ाई; आस्तीन और प्लीट्स बसंत की हवा-सी।',
        'inspire_title' => 'डिज़ाइन स्रोत',
        'inspire_lines' => [
            'आड़ू का वृक्ष युवा और सुंदर; उसके फूल चमकते हैं।',
            'लाल आस्तीन भोर के बादल-सी; मोती बसंत की कलियाँ चिह्नित करते हैं।',
        ],
        'inspire_note' => 'आड़ू फूल देवी नाम—हल्का कपड़ा, मोती और तितली। कट और कढ़ाई का प्रतीक, बाज़ार की कॉपी नहीं।',
        'stack_title' => 'छाती की कढ़ाई',
        'stack_body' => 'छाती पर फूल और मोती; फीता साफ़, नरम गुलाबी परत में दिखता है।',
        'macro_title' => 'पास से',
        'collar_label' => 'कॉलर',
        'collar_body' => 'मोती वाला कॉलर और सूक्ष्म सिलाई; छाती पर तितली।',
        'cuff_label' => 'कफ',
        'cuff_body' => 'चौड़ी पारदर्शी आस्तीनों पर बिखरे फूल; बाँह उठाने पर हिलते हैं।',
        'emb_label' => 'कढ़ाई',
        'emb_body' => 'गुलाबी-सफ़ेद पंखुड़ियाँ और सोना-हरा तना; मोतियों का जाल घेरता है।',
        'sleeve_title' => 'चौड़ी आस्तीन',
        'sleeve_body' => 'हल्की आस्तीन; चलते समय गुलाबी-सफ़ेद ग्रेडिएंट; किनारे साफ़।',
        'triptych_note' => 'तीन नज़रें',
        'quiet_line' => 'बसंत कपड़े में बसता है, शोर में नहीं।',
        'checklist_title' => 'पहनने का अनुभव',
        'checklist' => [
            'परतदार हल्का कपड़ा; चलते समय नरम प्लीट।',
            'छाती का बाँध स्थिर; नृत्य के लिए खुली आस्तीन।',
            'कढ़ाई और मोती पास से स्पष्ट—सावधानी से संभालें।',
        ],
        'hem_title' => 'परतदार हेम',
        'hem_body' => 'स्कर्ट की परतें गहरी होती जाती हैं; हेम पंखुड़ियों-सा उठता-गिरता है।',
        'original_title' => 'मूल शिल्प',
        'original_body' => 'कट और कढ़ाई हुआझाओजी की मूल हैं। शिल्प का सम्मान करें।',
        'close_caption' => 'गुलाबी और सफ़ेद, एक तांग पोशाक।',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'आड़ू फूल देवी',
        'info_color' => 'चित्र अनुसार (गुलाबी-सफ़ेद हल्का)',
        'info_style' => 'तांग शैली',
        'info_size' => 'S–2XL',
        'info_fabric' => 'हल्का स्पर्श (जैसा दिखता है)',
        'info_parts' => 'चौड़ी आस्तीन की पोशाक, स्कर्ट',
        'info_title' => 'एक नज़र में',
        'info_basics' => 'मूल',
        'info_comfort' => 'अनुभव',
        'label_brand' => 'ब्रांड',
        'label_name' => 'नाम',
        'label_color' => 'रंग',
        'label_style' => 'शैली',
        'label_size' => 'साइज़',
        'label_fabric' => 'कपड़ा',
        'label_parts' => 'भाग',
        'size_title' => 'साइज़ नोट',
        'size_body' => 'S, M, L, XL, 2XL। पृष्ठ के साइज़ चयन का पालन करें; हाथ माप में हल्का अंतर हो सकता है।',
        'alt_hero' => 'आड़ू फूल देवी · मुख्य',
        'alt_look' => 'आड़ू फूल देवी · पहना',
        'alt_mid' => 'आड़ू फूल देवी · छाती',
        'alt_sleeve' => 'आड़ू फूल देवी · आस्तीन',
        'alt_hem' => 'आड़ू फूल देवी · हेम',
        'alt_close' => 'आड़ू फूल देवी · समापन',
        'c_thick' => 'मोटाई',
        'c_thick_opts' => ['पतला', 'मध्यम', 'मोटा'],
        'c_thick_sel' => 'पतला',
        'c_stretch' => 'लचीलापन',
        'c_stretch_opts' => ['नहीं', 'हल्का', 'अधिक'],
        'c_stretch_sel' => 'नहीं',
    ],
    'ur_PK' => [
        'intro_title' => 'آڑو پھول دیوی',
        'intro_body' => 'تانگ طرز کا سینے تک سیٹ: چوڑی آستینوں والا چوغہ اور اسکرٹ۔ گلابی-سفید پتلے کپڑے پر آڑو پھول کی کڑھائی؛ آستینیں اور پلیٹ بہار کی ہوا کی مانند۔',
        'inspire_title' => 'ڈیزائن کا منبع',
        'inspire_lines' => [
            'آڑو کا درخت جوان اور شائستہ؛ اس کے پھول چمکتے ہیں۔',
            'لال آستینیں فجر کے بادل سی؛ موتی بہار کی کلیاں نشان زد کرتے ہیں۔',
        ],
        'inspire_note' => 'آڑو پھول دیوی کے نام پر—پتلا کپڑا، موتی اور تتلی۔ کٹ اور کڑھائی کی علامت، بازاری عبارت نہیں۔',
        'stack_title' => 'سینے کی کڑھائی',
        'stack_body' => 'سینے پر پھول اور موتی؛ فیتہ صاف، نرم گلابی تہوں میں نظر آتی ہے۔',
        'macro_title' => 'قریب سے',
        'collar_label' => 'کالر',
        'collar_body' => 'موتی والا کالر اور باریک ٹانکا؛ سینے پر تتلی۔',
        'cuff_label' => 'کف',
        'cuff_body' => 'چوڑی شفاف آستینوں پر بکھرے پھول؛ بازو اٹھانے پر حرکت کرتے ہیں۔',
        'emb_label' => 'کڑھائی',
        'emb_body' => 'گلابی-سفید پنکھڑیاں اور سونے-ہرے ڈنٹھل؛ موتیوں کا جال گھیرتا ہے۔',
        'sleeve_title' => 'چوڑی آستینیں',
        'sleeve_body' => 'ہلکی آستینیں؛ چلتے وقت گلابی-سفید گریڈیئنٹ؛ کنارے صاف۔',
        'triptych_note' => 'تین نظارے',
        'quiet_line' => 'بہار کپڑے میں بسی ہے، شور میں نہیں۔',
        'checklist_title' => 'پہننے کا احساس',
        'checklist' => [
            'تہ دار پتلا کپڑا؛ چلتے وقت نرم پلیٹ۔',
            'سینے کا باندھ مضبوط؛ رقص کے لیے کھلی آستینیں۔',
            'کڑھائی اور موتی قریب سے واضح—نرمی سے ہاتھ لگائیں۔',
        ],
        'hem_title' => 'تہ دار ہیم',
        'hem_body' => 'اسکرٹ کی تہیں گہری ہوتی جاتی ہیں؛ ہیم پنکھڑیوں کی مانند اٹھتی گرتی ہے۔',
        'original_title' => 'اصل دستکاری',
        'original_body' => 'کٹ اور کڑھائی ہواژاوجی کی اصل ہیں۔ دستکاری کا احترام کریں۔',
        'close_caption' => 'گلابی اور سفید، ایک تانگ چوغہ۔',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'آڑو پھول دیوی',
        'info_color' => 'جیسا تصویر میں (گلابی-سفید پتلا)',
        'info_style' => 'تانگ طرز',
        'info_size' => 'S–2XL',
        'info_fabric' => 'پتلا احساس (جیسا دکھائی دے)',
        'info_parts' => 'چوڑی آستینوں والا چوغہ، اسکرٹ',
        'info_title' => 'ایک نظر میں',
        'info_basics' => 'بنیادی',
        'info_comfort' => 'احساس',
        'label_brand' => 'برانڈ',
        'label_name' => 'نام',
        'label_color' => 'رنگ',
        'label_style' => 'طرز',
        'label_size' => 'سائز',
        'label_fabric' => 'کپڑا',
        'label_parts' => 'حصے',
        'size_title' => 'سائز نوٹ',
        'size_body' => 'S، M، L، XL اور 2XL۔ صفحے کے سائز منتخب کنندہ کی پیروی کریں؛ ہاتھ کی پیمائش میں تھوڑا فرق ہو سکتا ہے۔',
        'alt_hero' => 'آڑو پھول دیوی · مرکزی',
        'alt_look' => 'آڑو پھول دیوی · پہنا',
        'alt_mid' => 'آڑو پھول دیوی · سینہ',
        'alt_sleeve' => 'آڑو پھول دیوی · آستینیں',
        'alt_hem' => 'آڑو پھول دیوی · ہیم',
        'alt_close' => 'آڑو پھول دیوی · اختتام',
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

    // 1 fullbleed_hero
    $hero = $figureStack([
        $img($A['hero'], (string)$t['alt_hero'], 750, 1200),
    ], 'weline-detail-figure-stack--fullbleed');

    // 2 editorial_prose lead
    $intro = '<div class="weline-detail-prose weline-detail-prose--lead"><h3>' . $h((string)$t['intro_title']) . '</h3><p>'
        . $h((string)$t['intro_body']) . '</p></div>';

    // 3 editorial_prose verse
    $inspire = '<div class="weline-detail-prose weline-detail-prose--verse"><h3>' . $h((string)$t['inspire_title']) . '</h3>';
    foreach ($lines as $line) {
        $inspire .= '<p>' . $h($line) . '</p>';
    }
    $inspire .= '<p class="weline-detail-feature__note">' . $h((string)$t['inspire_note']) . '</p></div>';

    // 4 pair_gallery
    $pair = $figureStack([
        $img($A['look_stage'], (string)$t['alt_look'] . ' 1', 750, 1182),
        $img($A['look_runway'], (string)$t['alt_look'] . ' 2', 750, 1256),
    ]);

    // 5 stack_caption
    $stack = $figureStack([
        $img($A['look_mid'], (string)$t['alt_mid'], 750, 1200),
    ], 'weline-detail-figure-stack--caption')
        . '<div class="weline-detail-prose"><h3>' . $h((string)$t['stack_title']) . '</h3><p>'
        . $h((string)$t['stack_body']) . '</p></div>';

    // 6 macro_annotate（抽自烤字 detail-01/02，图用净实拍）
    $macro = $figureStack([
        $img($A['main'], (string)$t['alt_look'] . ' 3', 1200, 1200),
    ], 'weline-detail-figure-stack--caption')
        . '<div class="weline-detail-prose weline-detail-prose--macro"><h3>' . $h((string)$t['macro_title']) . '</h3>'
        . '<h4>' . $h((string)$t['collar_label']) . '</h4><p>' . $h((string)$t['collar_body']) . '</p>'
        . '<h4>' . $h((string)$t['cuff_label']) . '</h4><p>' . $h((string)$t['cuff_body']) . '</p>'
        . '<h4>' . $h((string)$t['emb_label']) . '</h4><p>' . $h((string)$t['emb_body']) . '</p></div>';

    // 7 feature_lr #1
    $sleeve = $feature(
        $img($A['look_profile'], (string)$t['alt_sleeve'], 750, 1182),
        '<h3>' . $h((string)$t['sleeve_title']) . '</h3><p>' . $h((string)$t['sleeve_body']) . '</p>',
        false,
    );

    // 8 triptych
    $triptych = $figureStack([
        $img($A['g02'], (string)$t['alt_look'] . ' 4', 1200, 1200),
        $img($A['g04'], (string)$t['alt_look'] . ' 5', 1200, 1200),
        $img($A['g05'], (string)$t['alt_look'] . ' 6', 1200, 1200),
    ])
        . '<div class="weline-detail-prose weline-detail-prose--quiet"><p class="weline-detail-feature__note">'
        . $h((string)$t['triptych_note']) . '</p></div>';

    // 9 quiet_spacer
    $quiet = '<div class="weline-detail-prose weline-detail-prose--quiet"><p>' . $h((string)$t['quiet_line']) . '</p></div>';

    // 10 checklist_trust
    $check = '<div class="weline-detail-prose weline-detail-prose--checklist"><h3>' . $h((string)$t['checklist_title']) . '</h3><ul>';
    foreach ($checklist as $item) {
        $check .= '<li>' . $h($item) . '</li>';
    }
    $check .= '</ul></div>';

    // 11 feature_lr #2（与 #1 不相邻）
    $hem = $feature(
        $img($A['look_duo'], (string)$t['alt_hem'], 750, 1210),
        '<h3>' . $h((string)$t['hem_title']) . '</h3><p>' . $h((string)$t['hem_body']) . '</p>',
        true,
    );

    // 12 spec_panel
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
    $size = '<div class="weline-detail-prose"><h3>' . $h((string)$t['size_title']) . '</h3><p>'
        . $h((string)$t['size_body']) . '</p></div>';

    // 13 editorial_prose original
    $original = '<div class="weline-detail-prose"><h3>' . $h((string)$t['original_title']) . '</h3><p>'
        . $h((string)$t['original_body']) . '</p></div>';

    // 14 fullbleed close
    $close = $figureStack([
        $img($A['look_runway'], (string)$t['alt_close'], 750, 1256),
    ], 'weline-detail-figure-stack--fullbleed')
        . '<div class="weline-detail-prose weline-detail-prose--quiet"><p class="weline-detail-feature__note">'
        . $h((string)$t['close_caption']) . '</p></div>';

    return '<div data-weline-product-description="1688" data-weline-detail-suite="variety-v1">'
        . $hero . $intro . $inspire . $pair . $stack . $macro
        . $sleeve . $triptych . $quiet . $check . $hem
        . $info . $size . $original . $close
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

$enLeakMarkers = ['Design wellspring', 'Original craft', 'On the garment', 'At a glance', 'Hand feel', 'Close looking'];

$writes = [];
foreach ($localePlan as $locale => $baseKey) {
    if (!isset($copy[$baseKey])) {
        fwrite(STDERR, "Missing locale pack: {$baseKey}\n");
        exit(2);
    }
    $html = $assemble($copy[$baseKey]);
    $banned = ['一件代发', '混批', '厂家直销', '旺旺', '货源', '阿里巴巴', '淘宝', '天猫', '拼多多', 'dropship', 'Dropship', '设计亮点'];
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
    // 烤字图不得入正文
    foreach (['075b82e0-c89f-4b3a-b977-1c410f6ea59e', '622b7465-a185-4cad-803c-9f8560f18279'] as $baked) {
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
    // 只计左右对照块本体，勿把 feature__note / __media / __copy 算进去
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
    echo "Prototype sequence: fullbleed_hero → editorial_prose×2 → pair_gallery → stack_caption → macro_annotate → feature_lr#1 → triptych → quiet_spacer → checklist_trust → feature_lr#2 → spec_panel → editorial_prose → fullbleed_hero(close)\n";
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
    'detail_suite_variety_193',
    ['product_ids' => [$productId]],
);
ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class)
    ->clearForCatalogChange('detail_suite_variety_193');

echo "Applied description-only writes: " . count($writes) . " locales.\n";
