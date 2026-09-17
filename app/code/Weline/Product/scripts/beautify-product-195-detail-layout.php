<?php

declare(strict_types=1);

/**
 * Redo product #195 玉人歌 description per ecommerce-detail-processing:
 * 古风 · 仅详情 · 抹烤字图 · 左右/双列 · 启用 locale 各语真译（禁 EN 兜底）
 *
 * php app/code/Weline/Product/scripts/beautify-product-195-detail-layout.php --apply
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
$productId = 195;

/**
 * 分流：01–05 = 净实拍入图；06「日常效果」烤字 → 删图抽文案
 */
$A = [
    'look01' => '4b080a14-dda5-49cd-a802-702e280e3dd0',
    'look02' => 'd91c78ee-4cae-40ae-848f-25a50e39aa64',
    'look03' => '4e85da80-c9dc-4841-805b-35274fd2f362',
    'collage' => 'd4d15832-da9c-492d-b63e-5102d5ddcd70',
    'look05' => 'e9a31c1d-90be-4b12-84fc-ba85f2ba4e32',
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
        'intro_title' => '玉人歌',
        'intro_body' => '唐制齐胸襦裙套装：外大袖、内大袖、裙子、披帛与肩带。杏橙轻纱绣花，广袖随步生风，气韵清朗。',
        'inspire_title' => '设计心源',
        'inspire_lines' => [
            '青山隐隐水迢迢，秋尽江南草未凋。',
            '二十四桥明月夜，玉人何处教吹箫。',
        ],
        'inspire_note' => '取杜牧《寄扬州韩绰判官》句意，点题「玉人」清韵——非平台货盘说辞，仅为形制与纹样之题眼。',
        'original_title' => '原创心迹',
        'original_body' => '本款形制与刺绣为花朝记原创设计，敬请珍惜衣冠、尊重匠心。',
        'highlight_title' => '衣袂要点',
        'emb_label' => '刺绣',
        'emb_body' => '杏橙与素白花枝铺于轻纱，针脚细润，近观层次分明。',
        'sleeve_label' => '广袖',
        'sleeve_body' => '外大袖宽纾透亮，袖缘绣花随光浮动；内大袖衬出层叠。',
        'skirt_label' => '齐胸裙裳',
        'skirt_body' => '齐胸高束，裙色由浅入深，裾影轻盈。',
        'hezi_label' => '诃子',
        'hezi_body' => '胸口绣板稳妥平整，丝绦束结，望之端丽。',
        'daily_title' => '日常着装',
        'daily_body' => '日常穿着可见形制与刺绣层次；轻纱透光，走动时袖裾自然生风。',
        'info_brand' => '花朝记',
        'info_name' => '玉人歌',
        'info_color' => '如图（杏橙·素白）',
        'info_style' => '唐制',
        'info_size' => 'S–2XL',
        'info_fabric' => '涤纶（聚酯纤维）',
        'info_parts' => '外大袖、内大袖、裙子、披帛、肩带',
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
        'alt_look' => '玉人歌 · 着装',
        'alt_collage' => '玉人歌 · 形制剪影',
        'c_thick' => '厚度',
        'c_thick_opts' => ['薄', '适中', '厚'],
        'c_thick_sel' => '薄',
        'c_stretch' => '弹性',
        'c_stretch_opts' => ['无弹', '微弹', '高弹'],
        'c_stretch_sel' => '无弹',
    ],
    'en_US' => [
        'intro_title' => 'Jade Beauty Song',
        'intro_body' => 'A Tang-style chest-high set: outer large sleeves, inner large sleeves, skirt, pibo sash, and straps. Apricot gauze with floral embroidery—wide sleeves move with the step.',
        'inspire_title' => 'Design wellspring',
        'inspire_lines' => [
            'Green hills fade; waters stretch far; autumn ends, yet Jiangnan grass is still green.',
            'On a moonlit night by Twenty-Four Bridges—where does the jade beauty teach the flute?',
        ],
        'inspire_note' => 'Drawn from Du Mu’s lines—an emblem for the robe’s grace, not marketplace copy.',
        'original_title' => 'Original craft',
        'original_body' => 'Cut and embroidery are original to Huazhaoji. Please honor the craft.',
        'highlight_title' => 'On the garment',
        'emb_label' => 'Embroidery',
        'emb_body' => 'Apricot and ivory blossoms on sheer gauze—fine stitches, clear layers up close.',
        'sleeve_label' => 'Wide sleeves',
        'sleeve_body' => 'The outer robe is airy and broad; cuff florals catch the light; the inner sleeve adds depth.',
        'skirt_label' => 'Chest-high skirt',
        'skirt_body' => 'Tied high at the chest; soft gradient from pale to warm; a light hem.',
        'hezi_label' => 'Hezi panel',
        'hezi_body' => 'A steady embroidered chest panel with silk ties—upright and refined.',
        'daily_title' => 'Everyday wear',
        'daily_body' => 'In daily wear, silhouette and stitchwork stay clear; gauze glows, sleeves and hem move with the step.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Jade Beauty Song',
        'info_color' => 'As shown (apricot · ivory)',
        'info_style' => 'Tang style',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Polyester',
        'info_parts' => 'Outer large sleeves, inner large sleeves, skirt, pibo, straps',
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
        'size_body' => 'Available in S, M, L, XL, and 2XL. Follow the page size picker; hand measure may vary slightly.',
        'alt_look' => 'Jade Beauty Song · worn',
        'alt_collage' => 'Jade Beauty Song · silhouette',
        'c_thick' => 'Thickness',
        'c_thick_opts' => ['Light', 'Medium', 'Heavy'],
        'c_thick_sel' => 'Light',
        'c_stretch' => 'Stretch',
        'c_stretch_opts' => ['None', 'Slight', 'High'],
        'c_stretch_sel' => 'None',
    ],
    'es_ES' => [
        'intro_title' => 'Canción de Jade',
        'intro_body' => 'Conjunto Tang de falda alta al pecho: mangas exteriores amplias, mangas interiores, falda, pibo y tirantes. Gasa albaricoque con bordado floral; las mangas acompañan el paso.',
        'inspire_title' => 'Manantial del diseño',
        'inspire_lines' => [
            'Colinas verdes se diluyen; el agua se alarga; acaba el otoño y la hierba de Jiangnan aún verdace.',
            'Noche de luna en los Veinticuatro Puentes: ¿dónde enseña la flauta la belleza de jade?',
        ],
        'inspire_note' => 'Inspirado en versos de Du Mu—emblema de gracia, no lenguaje de mercado.',
        'original_title' => 'Oficio original',
        'original_body' => 'Corte y bordado son originales de Huazhaoji. Honrad la labor.',
        'highlight_title' => 'En la prenda',
        'emb_label' => 'Bordado',
        'emb_body' => 'Flores albaricoque e ivory sobre gasa fina—puntadas claras, capas nítidas de cerca.',
        'sleeve_label' => 'Mangas amplias',
        'sleeve_body' => 'La túnica exterior es aireada; las flores del puño captan la luz; la manga interior da profundidad.',
        'skirt_label' => 'Falda alta al pecho',
        'skirt_body' => 'Anudada alta; degradado suave de claro a cálido; bajo ligero.',
        'hezi_label' => 'Panel hezi',
        'hezi_body' => 'Panel bordado estable en el pecho con lazos de seda—erguida y refinada.',
        'daily_title' => 'Uso cotidiano',
        'daily_body' => 'En el día a día se ven silueta y bordado; la gasa brilla y mangas y bajo se mueven al caminar.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Canción de Jade',
        'info_color' => 'Como en la foto (albaricoque · ivory)',
        'info_style' => 'Estilo Tang',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Poliéster',
        'info_parts' => 'Manga exterior amplia, manga interior, falda, pibo, tirantes',
        'info_title' => 'De un vistazo',
        'info_basics' => 'Básicos',
        'info_comfort' => 'Tacto',
        'label_brand' => 'Marca',
        'label_name' => 'Nombre',
        'label_color' => 'Color',
        'label_style' => 'Estilo',
        'label_size' => 'Tallas',
        'label_fabric' => 'Tela',
        'label_parts' => 'Piezas',
        'size_title' => 'Nota de talla',
        'size_body' => 'Disponible en S, M, L, XL y 2XL. Seguid el selector de la página; la medida a mano puede variar un poco.',
        'alt_look' => 'Canción de Jade · puesta',
        'alt_collage' => 'Canción de Jade · silueta',
        'c_thick' => 'Grosor',
        'c_thick_opts' => ['Fina', 'Media', 'Gruesa'],
        'c_thick_sel' => 'Fina',
        'c_stretch' => 'Elasticidad',
        'c_stretch_opts' => ['Nula', 'Ligera', 'Alta'],
        'c_stretch_sel' => 'Nula',
    ],
    'fr_FR' => [
        'intro_title' => 'Chant de Jade',
        'intro_body' => 'Ensemble Tang à jupe haute poitrine : grandes manches externes, manches internes, jupe, pibo et bretelles. Gaze abricot brodée de fleurs—les manches suivent le pas.',
        'inspire_title' => 'Source du dessin',
        'inspire_lines' => [
            'Collines vertes s’estompent ; l’eau s’étire ; l’automne finit, l’herbe du Jiangnan reste verte.',
            'Nuit de lune aux Vingt-Quatre Ponts—où la beauté de jade enseigne-t-elle la flûte ?',
        ],
        'inspire_note' => 'Inspiré de vers de Du Mu—emblème de grâce, non jargon de plateforme.',
        'original_title' => 'Savoir-faire original',
        'original_body' => 'Coupe et broderie sont originales Huazhaoji. Honorez le métier.',
        'highlight_title' => 'Sur le vêtement',
        'emb_label' => 'Broderie',
        'emb_body' => 'Fleurs abricot et ivoire sur gaze fine—points nets, couches lisibles de près.',
        'sleeve_label' => 'Grandes manches',
        'sleeve_body' => 'La robe externe est aérée ; les fleurs du poignet captent la lumière ; la manche interne donne de la profondeur.',
        'skirt_label' => 'Jupe haute poitrine',
        'skirt_body' => 'Nouée haut ; dégradé doux du pâle au chaud ; ourlet léger.',
        'hezi_label' => 'Panneau hezi',
        'hezi_body' => 'Panneau brodé stable à la poitrine, liens de soie—droite et raffinée.',
        'daily_title' => 'Port quotidien',
        'daily_body' => 'Au quotidien, silhouette et broderie restent claires ; la gaze luit, manches et ourlet bougent au pas.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Chant de Jade',
        'info_color' => 'Comme sur la photo (abricot · ivoire)',
        'info_style' => 'Style Tang',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Polyester',
        'info_parts' => 'Grandes manches externes, manches internes, jupe, pibo, bretelles',
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
        'size_body' => 'Disponible en S, M, L, XL et 2XL. Suivez le sélecteur de la page ; la mesure à la main peut varier légèrement.',
        'alt_look' => 'Chant de Jade · porté',
        'alt_collage' => 'Chant de Jade · silhouette',
        'c_thick' => 'Épaisseur',
        'c_thick_opts' => ['Fine', 'Moyenne', 'Épaisse'],
        'c_thick_sel' => 'Fine',
        'c_stretch' => 'Élasticité',
        'c_stretch_opts' => ['Nulle', 'Légère', 'Forte'],
        'c_stretch_sel' => 'Nulle',
    ],
    'pt_BR' => [
        'intro_title' => 'Canção de Jade',
        'intro_body' => 'Conjunto Tang de saia alta no peito: mangas exteriores amplas, mangas interiores, saia, pibo e alças. Gaze damasco com bordado floral—as mangas acompanham o passo.',
        'inspire_title' => 'Nascente do desenho',
        'inspire_lines' => [
            'Colinas verdes esmaecem; a água se alonga; o outono acaba e a erva de Jiangnan ainda verdeja.',
            'Noite de lua nas Vinte e Quatro Pontes—onde a bela de jade ensina a flauta?',
        ],
        'inspire_note' => 'Inspirado em versos de Du Mu—emblema de graça, não jargão de plataforma.',
        'original_title' => 'Ofício original',
        'original_body' => 'Corte e bordado são originais da Huazhaoji. Honre o ofício.',
        'highlight_title' => 'Na peça',
        'emb_label' => 'Bordado',
        'emb_body' => 'Flores damasco e marfim sobre gaze fina—pontos nítidos, camadas claras de perto.',
        'sleeve_label' => 'Mangas amplas',
        'sleeve_body' => 'A túnica externa é arejada; as flores do punho captam a luz; a manga interna dá profundidade.',
        'skirt_label' => 'Saia alta no peito',
        'skirt_body' => 'Amarrada alta; degradê suave do claro ao quente; barra leve.',
        'hezi_label' => 'Painel hezi',
        'hezi_body' => 'Painel bordado estável no peito com laços de seda—ereta e refinada.',
        'daily_title' => 'Uso cotidiano',
        'daily_body' => 'No dia a dia veem-se silhueta e bordado; a gaze brilha, mangas e barra movem-se ao caminhar.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Canção de Jade',
        'info_color' => 'Como na foto (damasco · marfim)',
        'info_style' => 'Estilo Tang',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Poliéster',
        'info_parts' => 'Manga exterior ampla, manga interior, saia, pibo, alças',
        'info_title' => 'De relance',
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
        'size_body' => 'Disponível em S, M, L, XL e 2XL. Siga o seletor da página; a medida manual pode variar um pouco.',
        'alt_look' => 'Canção de Jade · vestida',
        'alt_collage' => 'Canção de Jade · silhueta',
        'c_thick' => 'Espessura',
        'c_thick_opts' => ['Fina', 'Média', 'Grossa'],
        'c_thick_sel' => 'Fina',
        'c_stretch' => 'Elasticidade',
        'c_stretch_opts' => ['Nenhuma', 'Leve', 'Alta'],
        'c_stretch_sel' => 'Nenhuma',
    ],
    'id_ID' => [
        'intro_title' => 'Lagu Giok',
        'intro_body' => 'Set gaya Tang rok dada tinggi: lengan luar lebar, lengan dalam, rok, pibo, dan tali bahu. Kasa apricot bersulam bunga—lengan ikut langkah.',
        'inspire_title' => 'Mata air desain',
        'inspire_lines' => [
            'Bukit hijau memudar; air menjulur; musim gugur berakhir, rumput Jiangnan masih hijau.',
            'Malam bulan di Dua Puluh Empat Jembatan—di mana si jelita giok mengajar seruling?',
        ],
        'inspire_note' => 'Diambil dari bait Du Mu—lambang keanggunan, bukan bahasa pasar.',
        'original_title' => 'Kriya asli',
        'original_body' => 'Potongan dan sulaman asli Huazhaoji. Hormati kriya.',
        'highlight_title' => 'Pada pakaian',
        'emb_label' => 'Sulaman',
        'emb_body' => 'Bunga apricot dan gading pada kasa tipis—jahitan rapi, lapisan jelas dari dekat.',
        'sleeve_label' => 'Lengan lebar',
        'sleeve_body' => 'Jubah luar ringan dan lebar; bunga manset menangkap cahaya; lengan dalam menambah kedalaman.',
        'skirt_label' => 'Rok dada tinggi',
        'skirt_body' => 'Diikat tinggi; gradasi lembut dari pucat ke hangat; hem ringan.',
        'hezi_label' => 'Panel hezi',
        'hezi_body' => 'Panel sulam di dada dengan ikat sutra—tegak dan halus.',
        'daily_title' => 'Pemakaian sehari-hari',
        'daily_body' => 'Dalam pemakaian harian, siluet dan sulaman tetap jelas; kasa berkilau, lengan dan hem bergerak saat berjalan.',
        'info_brand' => 'Huazhaoji',
        'info_name' => 'Lagu Giok',
        'info_color' => 'Sesuai gambar (apricot · gading)',
        'info_style' => 'Gaya Tang',
        'info_size' => 'S–2XL',
        'info_fabric' => 'Poliester',
        'info_parts' => 'Lengan luar lebar, lengan dalam, rok, pibo, tali bahu',
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
        'size_body' => 'Tersedia S, M, L, XL, dan 2XL. Ikuti pemilih ukuran di halaman; ukuran tangan bisa sedikit berbeda.',
        'alt_look' => 'Lagu Giok · dikenakan',
        'alt_collage' => 'Lagu Giok · siluet',
        'c_thick' => 'Ketebalan',
        'c_thick_opts' => ['Tipis', 'Sedang', 'Tebal'],
        'c_thick_sel' => 'Tipis',
        'c_stretch' => 'Elastisitas',
        'c_stretch_opts' => ['Tidak', 'Sedikit', 'Tinggi'],
        'c_stretch_sel' => 'Tidak',
    ],
    'ar_SA' => [
        'intro_title' => 'أغنية اليشم',
        'intro_body' => 'طقم تانغ ب mag تنورة مرتفعة الصدر: أكمام خارجية واسعة، أكمام داخلية، تنورة، بيبو وأشرطة كتف. شاش مشمشي بتطريز زهري—الأكمام تتحرك مع الخطوة.',
        'inspire_title' => 'منبع التصميم',
        'inspire_lines' => [
            'تتلاشى التلال الخضراء ويمتد الماء؛ ينتهي الخريف وعشب جيانغنان لا يزال أخضر.',
            'ليلة قمر عند جسور الأربعة والعشرين—أين تعلّم حسناء اليشم الناي؟',
        ],
        'inspire_note' => 'مستوحى من أبيات دو مو—رمز للأناقة لا لغة الأسواق.',
        'original_title' => 'حرفة أصلية',
        'original_body' => 'القصّ والتطريز أصليان من هواجاوجي. أكرموا الحرفة.',
        'highlight_title' => 'على الثوب',
        'emb_label' => 'التطريز',
        'emb_body' => 'أزهار مشمشية وعاجية على شاش رقيق—غرز دقيقة وطبقات واضحة عن قرب.',
        'sleeve_label' => 'الأكمام الواسعة',
        'sleeve_body' => 'الرداء الخارجي خفيف وواسع؛ زهور الكُمّ تلتقط الضوء؛ الكم الداخلي يضيف عمقًا.',
        'skirt_label' => 'تنورة مرتفعة الصدر',
        'skirt_body' => 'مربوطة عاليًا؛ تدرّج ناعم من الفاتح إلى الدافئ؛ حاشية خفيفة.',
        'hezi_label' => 'لوحة هيزي',
        'hezi_body' => 'لوحة مطرّزة ثابتة على الصدر مع أربطة حرير—قويمة وراقية.',
        'daily_title' => 'الارتداء اليومي',
        'daily_body' => 'في الارتداء اليومي تبدو الهيئة والتطريز بوضوح؛ يشعّ الشاش وتتحرك الأكمام والحاشية مع الخطوة.',
        'info_brand' => 'هواجاوجي',
        'info_name' => 'أغنية اليشم',
        'info_color' => 'كما في الصورة (مشمشي · عاجي)',
        'info_style' => 'أسلوب تانغ',
        'info_size' => 'S–2XL',
        'info_fabric' => 'بوليستر',
        'info_parts' => 'أكمام خارجية واسعة، أكمام داخلية، تنورة، بيبو، أشرطة',
        'info_title' => 'لمحة سريعة',
        'info_basics' => 'أساسيات',
        'info_comfort' => 'الملمس',
        'label_brand' => 'العلامة',
        'label_name' => 'الاسم',
        'label_color' => 'اللون',
        'label_style' => 'الطراز',
        'label_size' => 'المقاسات',
        'label_fabric' => 'القماش',
        'label_parts' => 'الأجزاء',
        'size_title' => 'ملاحظة المقاس',
        'size_body' => 'متوفر S وM وL وXL و2XL. اتبعوا منتقي المقاس في الصفحة؛ القياس اليدوي قد يختلف قليلًا.',
        'alt_look' => 'أغنية اليشم · مرتداة',
        'alt_collage' => 'أغنية اليشم · هيئة',
        'c_thick' => 'السماكة',
        'c_thick_opts' => ['خفيف', 'متوسط', 'سميك'],
        'c_thick_sel' => 'خفيف',
        'c_stretch' => 'المرونة',
        'c_stretch_opts' => ['لا', 'خفيف', 'عالٍ'],
        'c_stretch_sel' => 'لا',
    ],
    'bn_BD' => [
        'intro_title' => 'জেড সৌন্দর্য গান',
        'intro_body' => 'তাং শৈলীর বুক-উঁচু সেট: বাইরের প্রশস্ত হাতা, ভেতরের হাতা, স্কার্ট, পিবো ও কাঁধের ফিতা। এপ্রিকট জালিতে ফুলের সূচিকর্ম—হাতা পায়ের সঙ্গে নড়ে।',
        'inspire_title' => 'নকশার উৎস',
        'inspire_lines' => [
            'সবুজ পাহাড় মিলিয়ে যায়; জল বিস্তৃত হয়; শরৎ শেষ, জিয়াংনানের ঘাস এখনও সবুজ।',
            'চব্বিশ সেতুতে চাঁদের রাত—জেড সুন্দরী কোথায় বাঁশি শেখায়?',
        ],
        'inspire_note' => 'দু মু-এর পংক্তি থেকে—করুণা ও শোভার প্রতীক, বাজারের ভাষা নয়।',
        'original_title' => 'মূল কারুশিল্প',
        'original_body' => 'কাট ও সূচিকর্ম হুয়াঝাওজির মূল। কারুশিল্পকে সম্মান করুন।',
        'highlight_title' => 'পোশাকে',
        'emb_label' => 'সূচিকর্ম',
        'emb_body' => 'পাতলা জালিতে এপ্রিকট ও আইভরি ফুল—সূক্ষ্ম সেলাই, কাছ থেকে স্তর স্পষ্ট।',
        'sleeve_label' => 'প্রশস্ত হাতা',
        'sleeve_body' => 'বাইরের চাদর হালকা ও প্রশস্ত; কফের ফুল আলো ধরে; ভেতরের হাতা গভীরতা দেয়।',
        'skirt_label' => 'বুক-উঁচু স্কার্ট',
        'skirt_body' => 'উঁচুতে বাঁধা; ফিকে থেকে উষ্ণে নরম গ্রেডিয়েন্ট; হালকা হেম।',
        'hezi_label' => 'হেজি প্যানেল',
        'hezi_body' => 'বুকে স্থির সূচিকর্ম প্যানেল রেশমি ফিতায়—সোজা ও মার্জিত।',
        'daily_title' => 'দৈনন্দিন পরা',
        'daily_body' => 'দৈনন্দিন পরায় সিলুয়েট ও সূচিকর্ম স্পষ্ট; জালি জ্বলে, হাতা ও হেম পায়ের সঙ্গে নড়ে।',
        'info_brand' => 'হুয়াঝাওজি',
        'info_name' => 'জেড সৌন্দর্য গান',
        'info_color' => 'ছবির মতো (এপ্রিকট · আইভরি)',
        'info_style' => 'তাং শৈলী',
        'info_size' => 'S–2XL',
        'info_fabric' => 'পলিয়েস্টার',
        'info_parts' => 'বাইরের প্রশস্ত হাতা, ভেতরের হাতা, স্কার্ট, পিবো, ফিতা',
        'info_title' => 'এক নজরে',
        'info_basics' => 'মূল',
        'info_comfort' => 'স্পর্শানুভূতি',
        'label_brand' => 'ব্র্যান্ড',
        'label_name' => 'নাম',
        'label_color' => 'রঙ',
        'label_style' => 'শৈলী',
        'label_size' => 'সাইজ',
        'label_fabric' => 'কাপড়',
        'label_parts' => 'অংশ',
        'size_title' => 'সাইজ নোট',
        'size_body' => 'S, M, L, XL ও 2XL পাওয়া যায়। পৃষ্ঠার সাইজ নির্বাচক অনুসরণ করুন; হাতের মাপে সামান্য ফারাক হতে পারে।',
        'alt_look' => 'জেড সৌন্দর্য গান · পরা',
        'alt_collage' => 'জেড সৌন্দর্য গান · আকৃতি',
        'c_thick' => 'পুরুত্ব',
        'c_thick_opts' => ['পাতলা', 'মাঝারি', 'মোটা'],
        'c_thick_sel' => 'পাতলা',
        'c_stretch' => 'স্থিতিস্থাপকতা',
        'c_stretch_opts' => ['নেই', 'সামান্য', 'বেশি'],
        'c_stretch_sel' => 'নেই',
    ],
    'hi_IN' => [
        'intro_title' => 'जेड सौंदर्य गीत',
        'intro_body' => 'तांग शैली की छाती-ऊँची सेट: बाहरी चौड़ी आस्तीन, भीतरी आस्तीन, स्कर्ट, पीबो और कंधे की पट्टियाँ। एप्रिकॉट जाली पर फूलों की कढ़ाई—आस्तीन कदम के साथ चलती हैं।',
        'inspire_title' => 'डिज़ाइन स्रोत',
        'inspire_lines' => [
            'हरे पहाड़ धुँधलाते हैं; जल दूर तक फैलता है; शरद समाप्त, जियांगनान की घास अभी हरी है।',
            'चौबीस पुलों पर चाँदनी रात—जेड सुंदरी बाँसुरी कहाँ सिखाती है?',
        ],
        'inspire_note' => 'दू मू की पंक्तियों से—शोभा का प्रतीक, बाज़ार की भाषा नहीं।',
        'original_title' => 'मूल शिल्प',
        'original_body' => 'कट और कढ़ाई हुआझाओजी की मूल हैं। शिल्प का सम्मान करें।',
        'highlight_title' => 'वस्त्र पर',
        'emb_label' => 'कढ़ाई',
        'emb_body' => 'पतली जाली पर एप्रिकॉट और आइवरी फूल—बारीक टाँके, पास से परतें स्पष्ट।',
        'sleeve_label' => 'चौड़ी आस्तीन',
        'sleeve_body' => 'बाहरी चोगा हल्का और चौड़ा; कफ के फूल प्रकाश पकड़ते हैं; भीतरी आस्तीन गहराई देती है।',
        'skirt_label' => 'छाती-ऊँची स्कर्ट',
        'skirt_body' => 'ऊँची बँधी; हल्के से गुनगुने तक मृदु ग्रेडिएंट; हल्की हेम।',
        'hezi_label' => 'हेज़ी पैनल',
        'hezi_body' => 'सीने पर स्थिर कढ़ाई पैनल रेशमी डोरियों से—सीधी और परिष्कृत।',
        'daily_title' => 'दैनिक पहनना',
        'daily_body' => 'दैनिक पहनने में सिल्हूट और कढ़ाई स्पष्ट रहती है; जाली चमकती है, आस्तीन और हेम कदम के साथ हिलती हैं।',
        'info_brand' => 'हुआझाओजी',
        'info_name' => 'जेड सौंदर्य गीत',
        'info_color' => 'जैसा चित्र में (एप्रिकॉट · आइवरी)',
        'info_style' => 'तांग शैली',
        'info_size' => 'S–2XL',
        'info_fabric' => 'पॉलिएस्टर',
        'info_parts' => 'बाहरी चौड़ी आस्तीन, भीतरी आस्तीन, स्कर्ट, पीबो, पट्टियाँ',
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
        'size_title' => 'साइज़ नोट',
        'size_body' => 'S, M, L, XL और 2XL उपलब्ध। पृष्ठ के साइज़ चयनकर्ता का पालन करें; हाथ से माप में थोड़ा अंतर हो सकता है।',
        'alt_look' => 'जेड सौंदर्य गीत · पहना',
        'alt_collage' => 'जेड सौंदर्य गीत · आकार',
        'c_thick' => 'मोटाई',
        'c_thick_opts' => ['पतला', 'मध्यम', 'मोटा'],
        'c_thick_sel' => 'पतला',
        'c_stretch' => 'खींचाव',
        'c_stretch_opts' => ['नहीं', 'हल्का', 'अधिक'],
        'c_stretch_sel' => 'नहीं',
    ],
    'ur_PK' => [
        'intro_title' => 'یشم حسن گیت',
        'intro_body' => 'تانگ طرز سینے تک سیٹ: بیرونی چوڑی آستینیں، اندرونی آستینیں، اسکرٹ، پیبو اور کندھے کی پٹیاں۔ ایپری کاٹ جالی پر پھولوں کی کڑھائی—آستینیں قدم کے ساتھ چلتی ہیں۔',
        'inspire_title' => 'ڈیزائن کا چشمہ',
        'inspire_lines' => [
            'سبز پہاڑ مدھم ہوتے ہیں؛ پانی دور تک پھیلتا ہے؛ خزاں ختم، جیانگنان کی گھاس ابھی سبز ہے۔',
            'چوبیس پلوں پر چاندنی رات—یشم حسن بانسری کہاں سکھاتی ہے؟',
        ],
        'inspire_note' => 'دو مو کے اشعار سے—نفاست کی علامت، بازاری زبان نہیں۔',
        'original_title' => 'اصل دستکاری',
        'original_body' => 'کٹ اور کڑھائی ہواژاوجی کے اصل ہیں۔ دستکاری کا احترام کریں۔',
        'highlight_title' => 'لباس پر',
        'emb_label' => 'کڑھائی',
        'emb_body' => 'پتلی جالی پر ایپری کاٹ اور آئیوری پھول—باریک ٹانکے، قریب سے تہیں واضح۔',
        'sleeve_label' => 'چوڑی آستینیں',
        'sleeve_body' => 'بیرونی چوغہ ہلکا اور چوڑا؛ کف کے پھول روشنی پکڑتے ہیں؛ اندرونی آستین گہرائی دیتی ہے۔',
        'skirt_label' => 'سینے تک اسکرٹ',
        'skirt_body' => 'اونچی بندھی؛ ہلکے سے گرم تک نرم گریڈینٹ؛ ہلکی ہیم۔',
        'hezi_label' => 'ہیزی پینل',
        'hezi_body' => 'سینے پر مستحکم کڑھائی پینل ریشمی ڈوریوں سے—سیدھی اور مہذب۔',
        'daily_title' => 'روزانہ پہناوا',
        'daily_body' => 'روزانہ پہنے میں شکل اور کڑھائی واضح رہتی ہے؛ جالی چمکتی ہے، آستینیں اور ہیم قدم کے ساتھ حرکت کرتی ہیں۔',
        'info_brand' => 'ہواژاوجی',
        'info_name' => 'یشم حسن گیت',
        'info_color' => 'جیسا تصویر میں (ایپری کاٹ · آئیوری)',
        'info_style' => 'تانگ طرز',
        'info_size' => 'S–2XL',
        'info_fabric' => 'پالی ایسٹر',
        'info_parts' => 'بیرونی چوڑی آستین، اندرونی آستین، اسکرٹ، پیبو، پٹیاں',
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
        'size_body' => 'S، M، L، XL اور 2XL دستیاب۔ صفحے کے سائز منتخب کنندہ کی پیروی کریں؛ ہاتھ کی پیمائش میں تھوڑا فرق ہو سکتا ہے۔',
        'alt_look' => 'یشم حسن گیت · پہنا',
        'alt_collage' => 'یشم حسن گیت · شکل',
        'c_thick' => 'موٹائی',
        'c_thick_opts' => ['پتلا', 'درمیانہ', 'موٹا'],
        'c_thick_sel' => 'پتلا',
        'c_stretch' => 'کھینچاؤ',
        'c_stretch_opts' => ['نہیں', 'ہلکا', 'زیادہ'],
        'c_stretch_sel' => 'نہیں',
    ],
];

// Fix accidental Latin in ar intro (typo "ب mag")
$copy['ar_SA']['intro_body'] = 'طقم تانغ بتنورة مرتفعة الصدر: أكمام خارجية واسعة، أكمام داخلية، تنورة، بيبو وأشرطة كتف. شاش مشمشي بتطريز زهري—الأكمام تتحرك مع الخطوة.';

$assemble = static function (array $t) use ($A, $img, $feature, $figureStack, $h): string {
    /** @var list<string> $lines */
    $lines = $t['inspire_lines'];

    $intro = '<div class="weline-detail-prose"><h3>' . $h((string)$t['intro_title']) . '</h3><p>'
        . $h((string)$t['intro_body']) . '</p></div>';

    $hero = $figureStack([
        $img($A['look01'], (string)$t['alt_look'] . ' 1', 900, 1200),
        $img($A['look02'], (string)$t['alt_look'] . ' 2', 900, 1200),
    ]);

    $inspire = '<div class="weline-detail-prose"><h3>' . $h((string)$t['inspire_title']) . '</h3>';
    foreach ($lines as $line) {
        $inspire .= '<p>' . $h($line) . '</p>';
    }
    $inspire .= '<p class="weline-detail-feature__note">' . $h((string)$t['inspire_note']) . '</p></div>';

    $original = '<div class="weline-detail-prose"><h3>' . $h((string)$t['original_title']) . '</h3><p>'
        . $h((string)$t['original_body']) . '</p></div>';

    $looks = $figureStack([
        $img($A['look03'], (string)$t['alt_look'] . ' 3', 968, 1498),
        $img($A['look05'], (string)$t['alt_look'] . ' 4', 1080, 1080),
    ]);

    $highlight = $feature(
        $img($A['collage'], (string)$t['alt_collage'], 620, 1498),
        '<h3>' . $h((string)$t['highlight_title']) . '</h3>'
        . '<h4>' . $h((string)$t['emb_label']) . '</h4><p>' . $h((string)$t['emb_body']) . '</p>'
        . '<h4>' . $h((string)$t['sleeve_label']) . '</h4><p>' . $h((string)$t['sleeve_body']) . '</p>',
        false,
    );

    $skirt = $feature(
        $img($A['look01'], (string)$t['alt_look'] . ' 5', 900, 1200),
        '<h3>' . $h((string)$t['skirt_label']) . '</h3><p>' . $h((string)$t['skirt_body']) . '</p>'
        . '<h4>' . $h((string)$t['hezi_label']) . '</h4><p>' . $h((string)$t['hezi_body']) . '</p>',
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

    $size = '<div class="weline-detail-prose"><h3>' . $h((string)$t['size_title']) . '</h3><p>'
        . $h((string)$t['size_body']) . '</p></div>';

    $daily = '<div class="weline-detail-prose"><h3>' . $h((string)$t['daily_title']) . '</h3><p>'
        . $h((string)$t['daily_body']) . '</p></div>';

    return '<div data-weline-product-description="1688">'
        . $intro . $hero . $inspire . $original . $looks
        . $highlight . $skirt . $info . $size . $daily
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

$enLeakMarkers = ['Design wellspring', 'Original craft', 'On the garment', 'At a glance', 'Jade Beauty Song'];

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
    // deleted baked-text asset must not appear
    if (str_contains($html, 'ca7fc577-422b-4327-b08b-d28dd7f6fbad')) {
        fwrite(STDERR, "Baked-text asset leaked in {$locale}\n");
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
    'detail_locale_true_translate_195',
    ['product_ids' => [$productId]],
);

echo "Applied description-only writes: " . count($writes) . " locales.\n";
