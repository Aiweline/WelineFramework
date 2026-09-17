<?php

declare(strict_types=1);

/**
 * Redo product #194 十二花神·黑山茶 description per ecommerce-detail-processing:
 * 古风 · 仅详情 · 美学排版 · 抹三方 · 启用 locale 各语真译（禁 EN 兜底）
 *
 * 美学母题：卷名主声 / 段题次声 / 正文 50–65ch / 诗注最弱；
 * 主图密、心源疏、衣袂奇偶左右对调、形制表收束——墨色山茶杂志卷轴。
 *
 * php app/code/Weline/Product/scripts/beautify-product-194-detail-layout.php --apply
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
$productId = 194;

/**
 * 分流：
 * detail-01/02 + gallery 01/06 = 净实拍着装 → 主视觉/辅图
 * detail-03 = 净实拍诃子裙 → 衣袂左右
 * detail-04 = 净实拍绣花外衫 → 衣袂绣花
 * detail-05 = 净实拍墨纱大袖 → 衣袂广袖
 * 图内无烤字/无三方角标；目录名含 1688 仅存储路径，正文用 asset://
 */
$A = [
    'hero' => 'd110aa0a-7410-4724-8fb9-0040b0eeb631', // detail-01
    'look_back' => '8faccb01-497d-4b05-b3d2-57533956aaa3', // detail-02
    'hezi' => '7c5841b4-28e4-44b2-bf0b-ca4b229b85e0', // detail-03
    'emb' => 'c58fd08a-b0a4-4689-9ced-6cbd48abcaba', // detail-04
    'black' => '96783b5b-08d1-4ab4-9651-1ed582d9d62d', // detail-05
    'look_g01' => '393193c8-e4f1-4524-a5cd-898e59176410', // gallery 01
    'look_g06' => 'ebc37a73-d59c-4422-85fa-937763b74ef4', // gallery 06
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
        'intro_title' => '十二花神·黑山茶',
        'intro_body' => '唐制齐胸套装：大袖衫、里衣、裙子与肩带。墨色雪纺间紫雾山茶绣，广袖与褶影如夜色生香。',
        'inspire_title' => '设计心源',
        'inspire_lines' => [
            '墨云深处见山茶，一萼寒香带露华。',
            '紫雾轻笼广袖影，十二花神入唐家。',
        ],
        'inspire_note' => '以「黑山茶」为题眼——冷艳而静，绣花与墨纱相映；非平台货盘说辞，仅为形制与纹样之点题。',
        'original_title' => '原创心迹',
        'original_body' => '本款形制与刺绣为花朝记原创设计，敬请珍惜衣冠、尊重匠心。',
        'highlight_title' => '衣袂要点',
        'hezi_label' => '诃子',
        'hezi_body' => '齐胸绣板铺陈紫白山茶，珠缘细润，丝绦束结端丽。',
        'skirt_label' => '裙裳',
        'skirt_body' => '百褶自玄入烟紫，裾影轻盈，走动时墨晕浮动。',
        'emb_label' => '刺绣外衫',
        'emb_body' => '淡紫轻纱上重工山茶与蝶翅，背中与袖肩对称铺陈，针脚分明。',
        'sleeve_label' => '墨纱大袖',
        'sleeve_body' => '玄色透纱广袖，暗纹山茶隐于重叠；袖缘与门襟线脚干净。',
        'close_title' => '近观',
        'close_body' => '绣面层次、珠缘与纱质垂坠近处可辨；留白处见墨色气韵。',
        'info_brand' => '花朝记',
        'info_name' => '十二花神·黑山茶',
        'info_color' => '如图（玄墨·紫雾）',
        'info_style' => '唐制',
        'info_size' => 'S–2XL',
        'info_fabric' => '涤纶（聚酯纤维）、雪纺',
        'info_parts' => '大袖衫、里衣、裙子、肩带',
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
        'alt_hero' => '黑山茶 · 着装',
        'alt_look' => '黑山茶 · 着装',
        'alt_hezi' => '黑山茶 · 诃子与裙',
        'alt_emb' => '黑山茶 · 刺绣外衫',
        'alt_sleeve' => '黑山茶 · 墨纱大袖',
        'c_thick' => '厚度',
        'c_thick_opts' => ['薄', '适中', '厚'],
        'c_thick_sel' => '薄',
        'c_stretch' => '弹性',
        'c_stretch_opts' => ['无弹', '微弹', '高弹'],
        'c_stretch_sel' => '无弹',
    ],
    'en_US' => [
        'intro_title' => 'Twelve Flower Deities · Black Camellia',
        'intro_body' => 'A Tang-style chest-high set: large-sleeve robe, inner layer, skirt, and straps. Dark chiffon with purple camellia embroidery—sleeves and pleats move like night fragrance.',
        'inspire_title' => 'Design wellspring',
        'inspire_lines' => [
            'In ink-cloud depths a camellia shows; one cold bud holds dew.',
            'Purple mist veils wide sleeves—Twelve Deities enter Tang attire.',
        ],
        'inspire_note' => 'Named for Black Camellia—cool, quiet embroidery against ink gauze; an emblem for cut and motif, not marketplace copy.',
        'original_title' => 'Original craft',
        'original_body' => 'Cut and embroidery are original to Huazhaoji. Please honor the craft.',
        'highlight_title' => 'On the garment',
        'hezi_label' => 'Chest band',
        'hezi_body' => 'Chest-high embroidery of purple-white camellias, fine pearl edge, tidy sash knot.',
        'skirt_label' => 'Skirt',
        'skirt_body' => 'Pleats shift from ink to smoky purple; the hem stays light, mist floating as you walk.',
        'emb_label' => 'Embroidered outer robe',
        'emb_body' => 'Lavender sheer with dense camellias and butterflies—symmetrical on back and sleeves, clear stitches.',
        'sleeve_label' => 'Ink-gauze sleeves',
        'sleeve_body' => 'Sheer black wide sleeves; tonal camellias hide in the layers; clean cuff and front edges.',
        'close_title' => 'Close looking',
        'close_body' => 'Embroidery depth, pearl edge, and soft fall read up close; open ground keeps the ink mood.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Black Camellia',
        'info_color' => 'As shown (ink · violet mist)',
        'info_style' => 'Tang style',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Polyester, chiffon',
        'info_parts' => 'Large-sleeve robe, inner layer, skirt, straps',
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
        'size_body' => 'S, M, L, XL, and 2XL. Follow the on-page size selector; hand measure may vary slightly.',
        'alt_hero' => 'Black Camellia · worn',
        'alt_look' => 'Black Camellia · worn',
        'alt_hezi' => 'Black Camellia · chest and skirt',
        'alt_emb' => 'Black Camellia · embroidered robe',
        'alt_sleeve' => 'Black Camellia · ink-gauze sleeves',
        'c_thick' => 'Thickness',
        'c_thick_opts' => ['Thin', 'Medium', 'Thick'],
        'c_thick_sel' => 'Thin',
        'c_stretch' => 'Stretch',
        'c_stretch_opts' => ['None', 'Slight', 'High'],
        'c_stretch_sel' => 'None',
    ],
    'ar_SA' => [
        'intro_title' => 'آلهة الزهور الاثنتا عشرة · الكاميليا السوداء',
        'intro_body' => 'طقم تانغ مرتفع الصدر: رداء بأكمام واسعة، طبقة داخلية، تنورة وأشرطة. شيفون داكن بتطريز كاميليا بنفسجية—الأكمام والطيات تتحرك كعطر الليل.',
        'inspire_title' => 'منبع التصميم',
        'inspire_lines' => [
            'في أعماق سحب الحبر تظهر كاميليا؛ برعم بارد يحمل الندى.',
            'ضباب بنفسجي يغشى الأكمام الواسعة—تدخل الآلهات الاثنتا عشرة زي التانغ.',
        ],
        'inspire_note' => 'باسم الكاميليا السوداء—تطريز هادئ على شاش حبري؛ رمز للقص والنقش لا لغة السوق.',
        'original_title' => 'حرفة أصلية',
        'original_body' => 'القص والتطريز أصليان لـ هواجاوجي. يُرجى احترام الحرفة.',
        'highlight_title' => 'على الثوب',
        'hezi_label' => 'شريط الصدر',
        'hezi_body' => 'تطريز مرتفع الصدر بكاميليا بنفسجية وبيضاء، حافة لؤلؤ دقيقة، عقدة وشاح مرتبة.',
        'skirt_label' => 'التنورة',
        'skirt_body' => 'طيات تنتقل من الحبر إلى بنفسجي دخاني؛ الذيل خفيف والضباب يتحرك مع الخطوة.',
        'emb_label' => 'الرداء المطرز',
        'emb_body' => 'شاش خزامي بكثافة كاميليا وفراشات—متماثل على الظهر والأكمام، غرز واضحة.',
        'sleeve_label' => 'أكمام الشاش الحبري',
        'sleeve_body' => 'أكمام سوداء شفافة واسعة؛ كاميليا نغمية تختفي في الطبقات؛ حواف نظيفة.',
        'close_title' => 'عن قرب',
        'close_body' => 'عمق التطريز وحافة اللؤلؤ وتدلي القماش واضحة عن قرب؛ الفراغ يحفظ مزاج الحبر.',
        'info_brand' => 'هواجاوجي',
        'info_name' => 'الكاميليا السوداء',
        'info_color' => 'كما في الصورة (حبر · ضباب بنفسجي)',
        'info_style' => 'أسلوب تانغ',
        'info_size' => 'S–2XL',
        'info_fabric' => 'بوليستر، شيفون',
        'info_parts' => 'رداء بأكمام واسعة، طبقة داخلية، تنورة، أشرطة',
        'info_title' => 'لمحة سريعة',
        'info_basics' => 'أساسيات',
        'info_comfort' => 'ملمس اليد',
        'label_brand' => 'العلامة',
        'label_name' => 'الاسم',
        'label_color' => 'اللون',
        'label_style' => 'الأسلوب',
        'label_size' => 'المقاسات',
        'label_fabric' => 'القماش',
        'label_parts' => 'القطع',
        'size_title' => 'ملاحظة المقاس',
        'size_body' => 'S و M و L و XL و 2XL. اتبع محدد المقاس في الصفحة؛ القياس اليدوي قد يختلف قليلاً.',
        'alt_hero' => 'الكاميليا السوداء · مرتداة',
        'alt_look' => 'الكاميليا السوداء · مرتداة',
        'alt_hezi' => 'الكاميليا السوداء · صدر وتنورة',
        'alt_emb' => 'الكاميليا السوداء · رداء مطرز',
        'alt_sleeve' => 'الكاميليا السوداء · أكمام شاش',
        'c_thick' => 'السماكة',
        'c_thick_opts' => ['رفيع', 'متوسط', 'سميك'],
        'c_thick_sel' => 'رفيع',
        'c_stretch' => 'المرونة',
        'c_stretch_opts' => ['لا', 'خفيف', 'عالٍ'],
        'c_stretch_sel' => 'لا',
    ],
    'es_ES' => [
        'intro_title' => 'Doce deidades florales · Camelia negra',
        'intro_body' => 'Conjunto tang de pecho alto: túnica de mangas amplias, capa interior, falda y tirantes. Gasa oscura con bordado de camelias púrpura—mangas y pliegues se mueven como fragancia nocturna.',
        'inspire_title' => 'Manantial del diseño',
        'inspire_lines' => [
            'En nubes de tinta asoma una camelia; un capullo frío guarda el rocío.',
            'Niebla púrpura vela mangas amplias—las doce deidades entran al traje tang.',
        ],
        'inspire_note' => 'Bajo el nombre Camelia negra—bordado sereno sobre gasa de tinta; emblema de corte y motivo, no discurso de mercado.',
        'original_title' => 'Oficio original',
        'original_body' => 'Corte y bordado son originales de Huazhaoji. Honren el oficio.',
        'highlight_title' => 'En la prenda',
        'hezi_label' => 'Banda del pecho',
        'hezi_body' => 'Bordado alto de camelias púrpura y blancas, borde de perlas fino, nudo de cinta limpio.',
        'skirt_label' => 'Falda',
        'skirt_body' => 'Pliegues del tinta al púrpura ahumado; el bajo es ligero y la bruma flota al caminar.',
        'emb_label' => 'Túnica bordada',
        'emb_body' => 'Gasa lavanda con camelias y mariposas densas—simétricas en espalda y mangas, puntadas claras.',
        'sleeve_label' => 'Mangas de gasa tinta',
        'sleeve_body' => 'Mangas negras transparentes y amplias; camelias tonales se ocultan en capas; bordes limpios.',
        'close_title' => 'De cerca',
        'close_body' => 'Profundidad del bordado, borde de perlas y caída suave se leen de cerca; el vacío guarda el ánimo de tinta.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Camelia negra',
        'info_color' => 'Como en la imagen (tinta · niebla violeta)',
        'info_style' => 'Estilo tang',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Poliéster, gasa',
        'info_parts' => 'Túnica de mangas amplias, capa interior, falda, tirantes',
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
        'size_title' => 'Nota de talla',
        'size_body' => 'S, M, L, XL y 2XL. Siga el selector de la página; la medida a mano puede variar ligeramente.',
        'alt_hero' => 'Camelia negra · puesta',
        'alt_look' => 'Camelia negra · puesta',
        'alt_hezi' => 'Camelia negra · pecho y falda',
        'alt_emb' => 'Camelia negra · túnica bordada',
        'alt_sleeve' => 'Camelia negra · mangas de gasa',
        'c_thick' => 'Grosor',
        'c_thick_opts' => ['Fino', 'Medio', 'Grueso'],
        'c_thick_sel' => 'Fino',
        'c_stretch' => 'Elasticidad',
        'c_stretch_opts' => ['Nula', 'Ligera', 'Alta'],
        'c_stretch_sel' => 'Nula',
    ],
    'fr_FR' => [
        'intro_title' => 'Douze déesses florales · Camélia noir',
        'intro_body' => 'Ensemble tang à poitrine haute : robe à larges manches, couche intérieure, jupe et bretelles. Mousseline sombre brodée de camélias violets—manches et plis bougent comme un parfum de nuit.',
        'inspire_title' => 'Source du dessin',
        'inspire_lines' => [
            'Au fond des nuages d’encre paraît un camélia ; un bouton froid porte la rosée.',
            'Brume violette voile les larges manches—les douze déesses entrent dans l’habit tang.',
        ],
        'inspire_note' => 'Sous le nom Camélia noir—broderie calme sur gaze d’encre ; emblème de coupe et motif, non discours marchand.',
        'original_title' => 'Savoir-faire original',
        'original_body' => 'Coupe et broderie sont originales de Huazhaoji. Honorez le métier.',
        'highlight_title' => 'Sur le vêtement',
        'hezi_label' => 'Bande de poitrine',
        'hezi_body' => 'Broderie haute de camélias violet-blanc, liseré de perles fin, nœud de ruban net.',
        'skirt_label' => 'Jupe',
        'skirt_body' => 'Plis de l’encre au violet fumé ; l’ourlet reste léger, la brume flotte au pas.',
        'emb_label' => 'Robe brodée',
        'emb_body' => 'Gaze lavande dense de camélias et papillons—symétrique dos et manches, points nets.',
        'sleeve_label' => 'Manches de gaze d’encre',
        'sleeve_body' => 'Larges manches noires transparentes ; camélias tonales cachées dans les couches ; bords nets.',
        'close_title' => 'De près',
        'close_body' => 'Profondeur de broderie, liseré de perles et chute douce se lisent de près ; le vide garde l’humeur d’encre.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Camélia noir',
        'info_color' => 'Comme sur la photo (encre · brume violette)',
        'info_style' => 'Style tang',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Polyester, mousseline',
        'info_parts' => 'Robe à larges manches, couche intérieure, jupe, bretelles',
        'info_title' => 'En un coup d’œil',
        'info_basics' => 'Bases',
        'info_comfort' => 'Toucher',
        'label_brand' => 'Marque',
        'label_name' => 'Nom',
        'label_color' => 'Couleur',
        'label_style' => 'Style',
        'label_size' => 'Tailles',
        'label_fabric' => 'Tissu',
        'label_parts' => 'Pièces',
        'size_title' => 'Note de taille',
        'size_body' => 'S, M, L, XL et 2XL. Suivez le sélecteur de la page ; la mesure à la main peut varier un peu.',
        'alt_hero' => 'Camélia noir · porté',
        'alt_look' => 'Camélia noir · porté',
        'alt_hezi' => 'Camélia noir · poitrine et jupe',
        'alt_emb' => 'Camélia noir · robe brodée',
        'alt_sleeve' => 'Camélia noir · manches de gaze',
        'c_thick' => 'Épaisseur',
        'c_thick_opts' => ['Fin', 'Moyen', 'Épais'],
        'c_thick_sel' => 'Fin',
        'c_stretch' => 'Élasticité',
        'c_stretch_opts' => ['Nulle', 'Légère', 'Forte'],
        'c_stretch_sel' => 'Nulle',
    ],
    'pt_BR' => [
        'intro_title' => 'Doze deusas florais · Camélia negra',
        'intro_body' => 'Conjunto tang de peito alto: manto de mangas amplas, camada interna, saia e alças. Chiffon escuro com bordado de camélias roxas—mangas e pregas movem-se como fragrância noturna.',
        'inspire_title' => 'Nascente do desenho',
        'inspire_lines' => [
            'Nas nuvens de tinta surge uma camélia; um botão frio guarda o orvalho.',
            'Névoa púrpura vela mangas amplas—as doze deusas entram no traje tang.',
        ],
        'inspire_note' => 'Sob o nome Camélia negra—bordado sereno sobre gaze de tinta; emblema de corte e motivo, não discurso de mercado.',
        'original_title' => 'Ofício original',
        'original_body' => 'Corte e bordado são originais da Huazhaoji. Honrem o ofício.',
        'highlight_title' => 'Na peça',
        'hezi_label' => 'Faixa do peito',
        'hezi_body' => 'Bordado alto de camélias roxo-brancas, borda de pérolas fina, nó de fita limpo.',
        'skirt_label' => 'Saia',
        'skirt_body' => 'Pregas da tinta ao roxo esfumaçado; a barra é leve e a névoa flutua ao caminhar.',
        'emb_label' => 'Manto bordado',
        'emb_body' => 'Gaze lavanda densa de camélias e borboletas—simétrica nas costas e mangas, pontos claros.',
        'sleeve_label' => 'Mangas de gaze tinta',
        'sleeve_body' => 'Mangas pretas transparentes e amplas; camélias tonais se escondem nas camadas; bordas limpas.',
        'close_title' => 'De perto',
        'close_body' => 'Profundidade do bordado, borda de pérolas e queda suave se leem de perto; o vazio guarda o ânimo de tinta.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Camélia negra',
        'info_color' => 'Como na imagem (tinta · névoa violeta)',
        'info_style' => 'Estilo tang',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Poliéster, chiffon',
        'info_parts' => 'Manto de mangas amplas, camada interna, saia, alças',
        'info_title' => 'Em um olhar',
        'info_basics' => 'Básicos',
        'info_comfort' => 'Toque',
        'label_brand' => 'Marca',
        'label_name' => 'Nome',
        'label_color' => 'Cor',
        'label_style' => 'Estilo',
        'label_size' => 'Tamanhos',
        'label_fabric' => 'Tecido',
        'label_parts' => 'Peças',
        'size_title' => 'Nota de tamanho',
        'size_body' => 'S, M, L, XL e 2XL. Siga o seletor da página; a medida à mão pode variar um pouco.',
        'alt_hero' => 'Camélia negra · vestida',
        'alt_look' => 'Camélia negra · vestida',
        'alt_hezi' => 'Camélia negra · peito e saia',
        'alt_emb' => 'Camélia negra · manto bordado',
        'alt_sleeve' => 'Camélia negra · mangas de gaze',
        'c_thick' => 'Espessura',
        'c_thick_opts' => ['Fino', 'Médio', 'Grosso'],
        'c_thick_sel' => 'Fino',
        'c_stretch' => 'Elasticidade',
        'c_stretch_opts' => ['Nenhuma', 'Leve', 'Alta'],
        'c_stretch_sel' => 'Nenhuma',
    ],
    'id_ID' => [
        'intro_title' => 'Dua Belas Dewi Bunga · Kamelia Hitam',
        'intro_body' => 'Set tang dada tinggi: jubah lengan lebar, lapisan dalam, rok, dan tali. Sifon gelap dengan sulaman kamelia ungu—lengan dan lipit bergerak seperti wangi malam.',
        'inspire_title' => 'Sumber rancangan',
        'inspire_lines' => [
            'Di kedalaman awan tinta tampak kamelia; kuncup dingin menyimpan embun.',
            'Kabut ungu menyelimuti lengan lebar—dua belas dewi masuk busana tang.',
        ],
        'inspire_note' => 'Bernama Kamelia Hitam—sulaman tenang di kasa tinta; lambang potongan dan motif, bukan bahasa pasar.',
        'original_title' => 'Kriya asli',
        'original_body' => 'Potongan dan sulaman asli Huazhaoji. Hormati kriya.',
        'highlight_title' => 'Pada busana',
        'hezi_label' => 'Pita dada',
        'hezi_body' => 'Sulaman dada tinggi kamelia ungu-putih, tepi mutiara halus, simpul pita rapi.',
        'skirt_label' => 'Rok',
        'skirt_body' => 'Lipit dari tinta ke ungu asap; hem ringan, kabut mengambang saat melangkah.',
        'emb_label' => 'Jubah bersulam',
        'emb_body' => 'Kasa lavender padat kamelia dan kupu-kupu—simetris di punggung dan lengan, jahitan jelas.',
        'sleeve_label' => 'Lengan kasa tinta',
        'sleeve_body' => 'Lengan hitam transparan lebar; kamelia tonal tersembunyi di lapisan; tepi bersih.',
        'close_title' => 'Dari dekat',
        'close_body' => 'Kedalaman sulaman, tepi mutiara, dan jatuh lembut terbaca dekat; ruang kosong menjaga suasana tinta.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Kamelia Hitam',
        'info_color' => 'Seperti gambar (tinta · kabut ungu)',
        'info_style' => 'Gaya tang',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Poliester, sifon',
        'info_parts' => 'Jubah lengan lebar, lapisan dalam, rok, tali',
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
        'size_title' => 'Catatan ukuran',
        'size_body' => 'S, M, L, XL, dan 2XL. Ikuti pemilih ukuran di halaman; ukur tangan bisa sedikit berbeda.',
        'alt_hero' => 'Kamelia Hitam · dikenakan',
        'alt_look' => 'Kamelia Hitam · dikenakan',
        'alt_hezi' => 'Kamelia Hitam · dada dan rok',
        'alt_emb' => 'Kamelia Hitam · jubah bersulam',
        'alt_sleeve' => 'Kamelia Hitam · lengan kasa',
        'c_thick' => 'Ketebalan',
        'c_thick_opts' => ['Tipis', 'Sedang', 'Tebal'],
        'c_thick_sel' => 'Tipis',
        'c_stretch' => 'Elastisitas',
        'c_stretch_opts' => ['Tidak', 'Sedikit', 'Tinggi'],
        'c_stretch_sel' => 'Tidak',
    ],
    'hi_IN' => [
        'intro_title' => 'बारह पुष्प देवी · काली कैमेलिया',
        'intro_body' => 'छाती-ऊँचा तांग सेट: चौड़ी आस्तीन का चोगा, भीतरी परत, स्कर्ट और पट्टियाँ। गहरे शिफॉन पर बैंगनी कैमेलिया कढ़ाई—आस्तीन और प्लीट रात की सुगंध-सी चलती हैं।',
        'inspire_title' => 'डिज़ाइन का स्रोत',
        'inspire_lines' => [
            'स्याही के बादलों में कैमेलिया दिखे; ठंडी कली ओस रखे।',
            'बैंगनी धुंध चौड़ी आस्तीनों पर—बारह देवी तांग वस्त्र में आएँ।',
        ],
        'inspire_note' => 'काली कैमेलिया नाम से—स्याही की जाली पर शांत कढ़ाई; कट और रूप का चिह्न, बाज़ार की भाषा नहीं।',
        'original_title' => 'मूल शिल्प',
        'original_body' => 'कट और कढ़ाई हुआझाओजी की मूल हैं। शिल्प का सम्मान करें।',
        'highlight_title' => 'वस्त्र पर',
        'hezi_label' => 'छाती पट्टी',
        'hezi_body' => 'ऊँची छाती पर बैंगनी-सफ़ेद कैमेलिया कढ़ाई, महीन मोती किनारा, साफ़ फीता गाँठ।',
        'skirt_label' => 'स्कर्ट',
        'skirt_body' => 'प्लीट स्याही से धुएँ-से बैंगनी तक; हेम हल्का, चलते धुंध तैरती है।',
        'emb_label' => 'कढ़ाई वाला चोगा',
        'emb_body' => 'लैवेंडर जाली पर घनी कैमेलिया और तितलियाँ—पीठ और आस्तीन पर सममित, स्पष्ट टाँके।',
        'sleeve_label' => 'स्याही-जाली आस्तीन',
        'sleeve_body' => 'काली पारदर्शी चौड़ी आस्तीन; टोनल कैमेलिया परतों में छिपी; किनारे साफ़।',
        'close_title' => 'पास से',
        'close_body' => 'कढ़ाई की गहराई, मोती किनारा और नरम लटकन पास से दिखे; खाली जगह स्याही भाव रखे।',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'काली कैमेलिया',
        'info_color' => 'जैसा चित्र में (स्याही · बैंगनी धुंध)',
        'info_style' => 'तांग शैली',
        'info_size' => 'S–2XL',
        'info_fabric' => 'पॉलिएस्टर, शिफॉन',
        'info_parts' => 'चौड़ी आस्तीन चोगा, भीतरी परत, स्कर्ट, पट्टियाँ',
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
        'size_title' => 'साइज़ नोट',
        'size_body' => 'S, M, L, XL और 2XL। पृष्ठ के साइज़ चयन का पालन करें; हाथ माप थोड़ा भिन्न हो सकता है।',
        'alt_hero' => 'काली कैमेलिया · पहना',
        'alt_look' => 'काली कैमेलिया · पहना',
        'alt_hezi' => 'काली कैमेलिया · छाती और स्कर्ट',
        'alt_emb' => 'काली कैमेलिया · कढ़ाई चोगा',
        'alt_sleeve' => 'काली कैमेलिया · जाली आस्तीन',
        'c_thick' => 'मोटाई',
        'c_thick_opts' => ['पतला', 'मध्यम', 'मोटा'],
        'c_thick_sel' => 'पतला',
        'c_stretch' => 'खिंचाव',
        'c_stretch_opts' => ['नहीं', 'हल्का', 'अधिक'],
        'c_stretch_sel' => 'नहीं',
    ],
    'bn_BD' => [
        'intro_title' => 'বারো ফুলদেবী · কালো ক্যামেলিয়া',
        'intro_body' => 'বুক-উঁচু তাং সেট: চওড়া হাতার পোশাক, ভিতরের স্তর, স্কার্ট ও স্ট্র্যাপ। গাঢ় শিফনে বেগুনি ক্যামেলিয়া সূচিকর্ম—হাতা ও প্লিট রাতের সুগন্ধের মতো চলে।',
        'inspire_title' => 'নকশার উৎস',
        'inspire_lines' => [
            'কালি মেঘের গভীরে ক্যামেলিয়া দেখা যায়; শীতল কুঁড়ি শিশির ধরে।',
            'বেগুনি কুয়াশা চওড়া হাতায়—বারো দেবী তাং পোশাকে আসে।',
        ],
        'inspire_note' => 'কালো ক্যামেলিয়া নামে—কালি জালিতে শান্ত সূচিকর্ম; কাট ও মোটিফের প্রতীক, বাজারের ভাষা নয়।',
        'original_title' => 'মূল শিল্প',
        'original_body' => 'কাট ও সূচিকর্ম হুয়াঝাওজির মূল। শিল্পকে সম্মান করুন।',
        'highlight_title' => 'পোশাকে',
        'hezi_label' => 'বুকবন্ধনী',
        'hezi_body' => 'উঁচু বুকে বেগুনি-সাদা ক্যামেলিয়া সূচিকর্ম, সূক্ষ্ম মুক্তো প্রান্ত, পরিপাটি ফিতার গিঁট।',
        'skirt_label' => 'স্কার্ট',
        'skirt_body' => 'প্লিট কালি থেকে ধোঁয়াটে বেগুনি; হেম হালকা, হাঁটলে কুয়াশা ভাসে।',
        'emb_label' => 'সূচিকর্ম পোশাক',
        'emb_body' => 'ল্যাভেন্ডার জালিতে ঘন ক্যামেলিয়া ও প্রজাপতি—পিঠ ও হাতায় প্রতিসম, স্পষ্ট সেলাই।',
        'sleeve_label' => 'কালি-জালি হাতা',
        'sleeve_body' => 'কালো স্বচ্ছ চওড়া হাতা; টোনাল ক্যামেলিয়া স্তরে লুকানো; প্রান্ত পরিষ্কার।',
        'close_title' => 'কাছ থেকে',
        'close_body' => 'সূচিকর্মের গভীরতা, মুক্তো প্রান্ত ও নরম ঝোল কাছ থেকে পড়া যায়; ফাঁকা জায়গা কালির ভাব রাখে।',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'কালো ক্যামেলিয়া',
        'info_color' => 'ছবির মতো (কালি · বেগুনি কুয়াশা)',
        'info_style' => 'তাং শৈলী',
        'info_size' => 'S–2XL',
        'info_fabric' => 'পলিয়েস্টার, শিফন',
        'info_parts' => 'চওড়া হাতার পোশাক, ভিতরের স্তর, স্কার্ট, স্ট্র্যাপ',
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
        'size_title' => 'মাপের নোট',
        'size_body' => 'S, M, L, XL ও 2XL। পৃষ্ঠার মাপ নির্বাচক অনুসরণ করুন; হাতে মাপে সামান্য ফারাক হতে পারে।',
        'alt_hero' => 'কালো ক্যামেলিয়া · পরা',
        'alt_look' => 'কালো ক্যামেলিয়া · পরা',
        'alt_hezi' => 'কালো ক্যামেলিয়া · বুক ও স্কার্ট',
        'alt_emb' => 'কালো ক্যামেলিয়া · সূচিকর্ম পোশাক',
        'alt_sleeve' => 'কালো ক্যামেলিয়া · জালি হাতা',
        'c_thick' => 'পুরুত্ব',
        'c_thick_opts' => ['পাতলা', 'মাঝারি', 'মোটা'],
        'c_thick_sel' => 'পাতলা',
        'c_stretch' => 'ইলাস্টিসিটি',
        'c_stretch_opts' => ['নেই', 'হালকা', 'বেশি'],
        'c_stretch_sel' => 'নেই',
    ],
    'ur_PK' => [
        'intro_title' => 'بارہ پھول دیویاں · کالا کیمیلیا',
        'intro_body' => 'سینے اونچا تانگ سیٹ: چوڑی آستین کا چوغہ، اندرونی تہ، اسکرٹ اور پٹیاں۔ گہرے شفون پر جامنی کیمیلیا کڑھائی—آستینیں اور پلیٹ رات کی خوشبو سی چلتی ہیں۔',
        'inspire_title' => 'ڈیزائن کا چشمہ',
        'inspire_lines' => [
            'سیاہی کے بادلوں میں کیمیلیا دکھے؛ ٹھنڈی کلی اوس رکھے۔',
            'جامنی دھند چوڑی آستینوں پر—بارہ دیویاں تانگ لباس میں آئیں۔',
        ],
        'inspire_note' => 'کالا کیمیلیا نام سے—سیاہی کی جالی پر خاموش کڑھائی؛ کٹ اور نقش کی علامت، بازاری زبان نہیں۔',
        'original_title' => 'اصل دستکاری',
        'original_body' => 'کٹ اور کڑھائی ہواژاوجی کی اصل ہیں۔ دستکاری کا احترام کریں۔',
        'highlight_title' => 'لباس پر',
        'hezi_label' => 'سینے کی پٹی',
        'hezi_body' => 'اونچے سینے پر جامنی-سفید کیمیلیا کڑھائی، باریک موتی کنارہ، صاف فیتے کی گرہ۔',
        'skirt_label' => 'اسکرٹ',
        'skirt_body' => 'پلیٹ سیاہی سے دھوئیں جیسی جامنی تک؛ ہیم ہلکا، چلتے دھند تیرتی ہے۔',
        'emb_label' => 'کڑھائی والا چوغہ',
        'emb_body' => 'لیونڈر جالی پر گھنے کیمیلیا اور تتلیاں—پیٹھ اور آستینوں پر متوازن، واضح ٹانکے۔',
        'sleeve_label' => 'سیاہی-جالی آستینیں',
        'sleeve_body' => 'کالی شفاف چوڑی آستینیں؛ ٹونل کیمیلیا تہوں میں چھپی؛ کنارے صاف۔',
        'close_title' => 'قریب سے',
        'close_body' => 'کڑھائی کی گہرائی، موتی کنارہ اور نرم لٹک قریب سے پڑھے؛ خالی جگہ سیاہی کا مزاج رکھے۔',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'کالا کیمیلیا',
        'info_color' => 'جیسا تصویر میں (سیاہی · جامنی دھند)',
        'info_style' => 'تانگ طرز',
        'info_size' => 'S–2XL',
        'info_fabric' => 'پالی ایسٹر، شفون',
        'info_parts' => 'چوڑی آستین چوغہ، اندرونی تہ، اسکرٹ، پٹیاں',
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
        'size_title' => 'سائز نوٹ',
        'size_body' => 'S، M، L، XL اور 2XL۔ صفحے کے سائز منتخب کنندہ کی پیروی کریں؛ ہاتھ کی پیمائش میں تھوڑا فرق ہو سکتا ہے۔',
        'alt_hero' => 'کالا کیمیلیا · پہنا',
        'alt_look' => 'کالا کیمیلیا · پہنا',
        'alt_hezi' => 'کالا کیمیلیا · سینہ اور اسکرٹ',
        'alt_emb' => 'کالا کیمیلیا · کڑھائی چوغہ',
        'alt_sleeve' => 'کالا کیمیلیا · جالی آستینیں',
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

    $intro = '<div class="weline-detail-prose weline-detail-prose--lead"><h3>' . $h((string)$t['intro_title']) . '</h3><p>'
        . $h((string)$t['intro_body']) . '</p></div>';

    $hero = $figureStack([
        $img($A['hero'], (string)$t['alt_hero'], 1280, 1280),
        $img($A['look_back'], (string)$t['alt_look'] . ' 2', 1200, 1200),
    ]);

    $inspire = '<div class="weline-detail-prose weline-detail-prose--verse"><h3>' . $h((string)$t['inspire_title']) . '</h3>';
    foreach ($lines as $line) {
        $inspire .= '<p>' . $h($line) . '</p>';
    }
    $inspire .= '<p class="weline-detail-feature__note">' . $h((string)$t['inspire_note']) . '</p></div>';

    $original = '<div class="weline-detail-prose"><h3>' . $h((string)$t['original_title']) . '</h3><p>'
        . $h((string)$t['original_body']) . '</p></div>';

    $looks = $figureStack([
        $img($A['look_g06'], (string)$t['alt_look'] . ' 3', 480, 640),
        $img($A['look_g01'], (string)$t['alt_look'] . ' 4', 799, 1066),
    ]);

    $hezi = $feature(
        $img($A['hezi'], (string)$t['alt_hezi'], 1179, 1179),
        '<h3>' . $h((string)$t['highlight_title']) . '</h3>'
        . '<h4>' . $h((string)$t['hezi_label']) . '</h4><p>' . $h((string)$t['hezi_body']) . '</p>'
        . '<h4>' . $h((string)$t['skirt_label']) . '</h4><p>' . $h((string)$t['skirt_body']) . '</p>',
        false,
    );

    $emb = $feature(
        $img($A['emb'], (string)$t['alt_emb'], 1179, 1179),
        '<h3>' . $h((string)$t['emb_label']) . '</h3><p>' . $h((string)$t['emb_body']) . '</p>',
        true,
    );

    $sleeve = $feature(
        $img($A['black'], (string)$t['alt_sleeve'], 1179, 1179),
        '<h3>' . $h((string)$t['sleeve_label']) . '</h3><p>' . $h((string)$t['sleeve_body']) . '</p>'
        . '<h4>' . $h((string)$t['close_title']) . '</h4><p>' . $h((string)$t['close_body']) . '</p>',
        false,
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

    $size = '<div class="weline-detail-prose"><h3>' . $h((string)$t['size_title']) . '</h3><p>'
        . $h((string)$t['size_body']) . '</p></div>';

    return '<div data-weline-product-description="1688">'
        . $intro . $hero . $inspire . $original . $looks
        . $hezi . $emb . $sleeve . $info . $size
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

$enLeakMarkers = [
    'Design wellspring',
    'Original craft',
    'On the garment',
    'At a glance',
    'Black Camellia',
    'Twelve Flower Deities',
    'Hand feel',
    'Size note',
];

$writes = [];
foreach ($localePlan as $locale => $baseKey) {
    if (!isset($copy[$baseKey])) {
        fwrite(STDERR, "Missing locale pack: {$baseKey}\n");
        exit(2);
    }
    $html = $assemble($copy[$baseKey]);
    $banned = ['一件代发', '混批', '厂家直销', '旺旺', '货源', '阿里巴巴', '淘宝', '天猫', '拼多多', 'dropship', 'Dropship', '日常效果', '手机拍摄效果'];
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
        'lead' => substr_count($html, 'weline-detail-prose--lead'),
        'verse' => substr_count($html, 'weline-detail-prose--verse'),
    ];
}

foreach ($writes as $w) {
    echo sprintf(
        "%s\t%d\tfeature=%d\timgs=%d\tlead=%d\tverse=%d\n",
        $w['locale'] === '' ? '(empty)' : $w['locale'],
        $w['len'],
        $w['features'],
        $w['imgs'],
        $w['lead'],
        $w['verse'],
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
    'detail_locale_true_translate_194',
    ['product_ids' => [$productId]],
);
ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class)
    ->clearForCatalogChange('detail_locale_true_translate_194');

echo "Applied description-only writes: " . count($writes) . " locales.\n";
