<?php

declare(strict_types=1);

/**
 * #402 莲溪：详情仍嵌 detail-04「产品信息」中文烤图（asset bb24af89…）。
 * 删图 → 语义 product-info + 尺码表 + 洗护清单；默认站全部启用语真译。
 *
 * php app/code/Weline/Product/scripts/remediate-product-402-info-chart-i18n.php --dry-run
 * php app/code/Weline/Product/scripts/remediate-product-402-info-chart-i18n.php --apply
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
$productId = 402;

/** detail-04 产品信息烤图（左键值/尺码/洗护 + 右模特） */
$assetInfoChart = 'bb24af89-8dea-47b4-80a1-ff157ffbd095';

$sizeRows = [
    ['S', '152–160', '≤88', '≤45'],
    ['M', '158–166', '85–95', '≤55'],
    ['L', '166–170', '90–100', '≤60'],
    ['XL', '168–175', '98–108', '≤70'],
];

/**
 * Locale packs for info chart textification.
 * Numbers / S·M·L·XL / cm·KG stay as in source chart.
 *
 * @var array<string, array<string, mixed>>
 */
$packs = [
    'zh_Hans_CN' => [
        'info_title' => '产品信息',
        'info_basics' => '基本信息',
        'info_comfort' => '舒适度信息',
        'label_name' => '名称',
        'label_style' => '制式',
        'label_color' => '颜色',
        'label_parts' => '部件',
        'info_name' => '莲溪',
        'info_style' => '齐腰汉服',
        'info_color' => '绿色、红色',
        'info_parts' => '外衫、里衣、齐腰裙',
        'c_hand' => '软硬',
        'c_hand_opts' => ['偏硬', '微硬', '适中', '微软', '柔软'],
        'c_hand_sel' => '适中',
        'c_stretch' => '弹性',
        'c_stretch_opts' => ['无弹', '微弹', '适中', '弹力', '超弹'],
        'c_stretch_sel' => '无弹',
        'c_thick' => '厚度',
        'c_thick_opts' => ['薄款', '微薄', '适中', '微厚', '厚款'],
        'c_thick_sel' => '适中',
        'c_fit' => '版型',
        'c_fit_opts' => ['紧身', '修身', '适中', '休闲', '宽松'],
        'c_fit_sel' => '适中',
        'chart_title' => '尺码参考表',
        'chart_note' => '尺码单位：cm。手工测量，因测量方法与参照不同，1–3 cm 误差属正常。体重单位：KG。',
        'h_size' => '尺码',
        'h_height' => '适合身高',
        'h_bust' => '建议胸围',
        'h_weight' => '最大体重',
        'care_title' => '洗涤说明',
        'care_lines' => [
            '常温洗涤；忌用力揉搓、刷洗或拧绞。',
            '忌剧烈脱水；请使用中性洗涤剂。',
            '勿长时间浸泡、暴晒或漂白；悬挂阴凉晾干。',
            '建议分色洗涤。最高水温 30℃；不可漂白；可蒸汽熨烫；不可机洗；阴凉晾干。',
            '关于色差：不同显示器与拍摄光线可能导致色差，请以实物为准。',
        ],
        'size_guide_title' => '尺码参照',
        'size_guide_body' => '请按上表身高、胸围与体重区间挑选；手工测量或有一至三厘米出入。',
    ],
    'en_US' => [
        'info_title' => 'Product information',
        'info_basics' => 'Basics',
        'info_comfort' => 'Hand feel',
        'label_name' => 'Name',
        'label_style' => 'Style',
        'label_color' => 'Color',
        'label_parts' => 'Parts',
        'info_name' => 'Lianxi',
        'info_style' => 'Waist-high (qiyao) Hanfu',
        'info_color' => 'Green, red',
        'info_parts' => 'Outer robe, inner garment, waist-high skirt',
        'c_hand' => 'Hand',
        'c_hand_opts' => ['Firm', 'Slightly firm', 'Moderate', 'Slightly soft', 'Soft'],
        'c_hand_sel' => 'Moderate',
        'c_stretch' => 'Stretch',
        'c_stretch_opts' => ['None', 'Slight', 'Moderate', 'Stretchy', 'Very stretchy'],
        'c_stretch_sel' => 'None',
        'c_thick' => 'Thickness',
        'c_thick_opts' => ['Thin', 'Slightly thin', 'Moderate', 'Slightly thick', 'Thick'],
        'c_thick_sel' => 'Moderate',
        'c_fit' => 'Fit',
        'c_fit_opts' => ['Tight', 'Slim', 'Moderate', 'Casual', 'Relaxed'],
        'c_fit_sel' => 'Moderate',
        'chart_title' => 'Size reference chart',
        'chart_note' => 'Size unit: cm. Hand measurement may vary by 1–3 cm. Weight unit: KG.',
        'h_size' => 'Size',
        'h_height' => 'Suggested height',
        'h_bust' => 'Suggested bust',
        'h_weight' => 'Max weight',
        'care_title' => 'Care',
        'care_lines' => [
            'Wash at room temperature; avoid hard rubbing, brushing, or wringing.',
            'Avoid violent spin-drying; use a mild detergent.',
            'Do not soak long, bleach, or bake in harsh sun; hang dry in the shade.',
            'Wash colors separately. Max water 30°C; no bleach; steam iron OK; no machine wash; shade dry.',
            'Color note: monitors and lighting may shift color; refer to the actual garment.',
        ],
        'size_guide_title' => 'Size guide',
        'size_guide_body' => 'Choose by the height, bust, and weight ranges above; hand measure may vary 1–3 cm.',
    ],
    'es_ES' => [
        'info_title' => 'Información del producto',
        'info_basics' => 'Datos básicos',
        'info_comfort' => 'Tacto y ajuste',
        'label_name' => 'Nombre',
        'label_style' => 'Estilo',
        'label_color' => 'Color',
        'label_parts' => 'Piezas',
        'info_name' => 'Lianxi',
        'info_style' => 'Hanfu de cintura alta (qiyao)',
        'info_color' => 'Verde, rojo',
        'info_parts' => 'Túnica exterior, prenda interior, falda de cintura',
        'c_hand' => 'Tacto',
        'c_hand_opts' => ['Firme', 'Algo firme', 'Moderado', 'Algo suave', 'Suave'],
        'c_hand_sel' => 'Moderado',
        'c_stretch' => 'Elasticidad',
        'c_stretch_opts' => ['Nula', 'Ligera', 'Moderada', 'Elástica', 'Muy elástica'],
        'c_stretch_sel' => 'Nula',
        'c_thick' => 'Grosor',
        'c_thick_opts' => ['Fino', 'Algo fino', 'Moderado', 'Algo grueso', 'Grueso'],
        'c_thick_sel' => 'Moderado',
        'c_fit' => 'Corte',
        'c_fit_opts' => ['Ajustado', 'Entallado', 'Moderado', 'Casual', 'Holgado'],
        'c_fit_sel' => 'Moderado',
        'chart_title' => 'Tabla de tallas',
        'chart_note' => 'Unidad: cm. La medición manual puede variar 1–3 cm. Peso: KG.',
        'h_size' => 'Talla',
        'h_height' => 'Altura sugerida',
        'h_bust' => 'Busto sugerido',
        'h_weight' => 'Peso máx.',
        'care_title' => 'Cuidado',
        'care_lines' => [
            'Lavar a temperatura ambiente; no frotar, cepillar ni retorcer con fuerza.',
            'Evitar centrifugado violento; usar detergente neutro.',
            'No remojar mucho, blanquear ni exponer al sol intenso; secar colgado a la sombra.',
            'Lavar colores por separado. Agua máx. 30°C; no blanquear; plancha a vapor OK; no lavar a máquina; secar a la sombra.',
            'Nota de color: pantallas y luz pueden variar; prevalece la prenda real.',
        ],
        'size_guide_title' => 'Guía de tallas',
        'size_guide_body' => 'Elija según altura, busto y peso de la tabla; la medida manual puede variar 1–3 cm.',
    ],
    'fr_FR' => [
        'info_title' => 'Informations produit',
        'info_basics' => 'Essentiel',
        'info_comfort' => 'Toucher et coupe',
        'label_name' => 'Nom',
        'label_style' => 'Style',
        'label_color' => 'Couleur',
        'label_parts' => 'Pièces',
        'info_name' => 'Lianxi',
        'info_style' => 'Hanfu à taille haute (qiyao)',
        'info_color' => 'Vert, rouge',
        'info_parts' => 'Robe extérieure, sous-vêtement, jupe à taille',
        'c_hand' => 'Main',
        'c_hand_opts' => ['Ferme', 'Un peu ferme', 'Modéré', 'Un peu doux', 'Doux'],
        'c_hand_sel' => 'Modéré',
        'c_stretch' => 'Élasticité',
        'c_stretch_opts' => ['Nulle', 'Légère', 'Modérée', 'Élastique', 'Très élastique'],
        'c_stretch_sel' => 'Nulle',
        'c_thick' => 'Épaisseur',
        'c_thick_opts' => ['Fin', 'Un peu fin', 'Modéré', 'Un peu épais', 'Épais'],
        'c_thick_sel' => 'Modéré',
        'c_fit' => 'Coupe',
        'c_fit_opts' => ['Serré', 'Ajusté', 'Modéré', 'Décontracté', 'Ample'],
        'c_fit_sel' => 'Modéré',
        'chart_title' => 'Guide des tailles',
        'chart_note' => 'Unité : cm. Mesure manuelle ±1–3 cm. Poids : KG.',
        'h_size' => 'Taille',
        'h_height' => 'Taille suggérée',
        'h_bust' => 'Poitrine suggérée',
        'h_weight' => 'Poids max.',
        'care_title' => 'Entretien',
        'care_lines' => [
            'Laver à température ambiante ; éviter frottement, brossage ou essorage énergique.',
            'Éviter l’essorage violent ; utiliser un détergent neutre.',
            'Ne pas faire tremper longtemps, blanchir ni exposer au soleil fort ; sécher à l’ombre.',
            'Laver les couleurs séparément. Eau max. 30°C ; pas de javel ; fer vapeur OK ; pas de machine ; séchage à l’ombre.',
            'Écart de couleur : écrans et lumière peuvent varier ; se fier au vêtement réel.',
        ],
        'size_guide_title' => 'Repères de taille',
        'size_guide_body' => 'Choisissez selon hauteur, poitrine et poids du tableau ; mesure manuelle ±1–3 cm.',
    ],
    'pt_BR' => [
        'info_title' => 'Informações do produto',
        'info_basics' => 'Básicos',
        'info_comfort' => 'Toque e caimento',
        'label_name' => 'Nome',
        'label_style' => 'Estilo',
        'label_color' => 'Cor',
        'label_parts' => 'Peças',
        'info_name' => 'Lianxi',
        'info_style' => 'Hanfu de cintura alta (qiyao)',
        'info_color' => 'Verde, vermelho',
        'info_parts' => 'Túnica externa, peça interna, saia de cintura',
        'c_hand' => 'Toque',
        'c_hand_opts' => ['Firme', 'Um pouco firme', 'Moderado', 'Um pouco macio', 'Macio'],
        'c_hand_sel' => 'Moderado',
        'c_stretch' => 'Elasticidade',
        'c_stretch_opts' => ['Nenhuma', 'Leve', 'Moderada', 'Elástica', 'Muito elástica'],
        'c_stretch_sel' => 'Nenhuma',
        'c_thick' => 'Espessura',
        'c_thick_opts' => ['Fino', 'Um pouco fino', 'Moderado', 'Um pouco grosso', 'Grosso'],
        'c_thick_sel' => 'Moderado',
        'c_fit' => 'Caimento',
        'c_fit_opts' => ['Justo', 'Ajustado', 'Moderado', 'Casual', 'Folgado'],
        'c_fit_sel' => 'Moderado',
        'chart_title' => 'Tabela de tamanhos',
        'chart_note' => 'Unidade: cm. Medição manual pode variar 1–3 cm. Peso: KG.',
        'h_size' => 'Tamanho',
        'h_height' => 'Altura sugerida',
        'h_bust' => 'Busto sugerido',
        'h_weight' => 'Peso máx.',
        'care_title' => 'Cuidados',
        'care_lines' => [
            'Lavar em temperatura ambiente; evitar esfregar, escovar ou torcer com força.',
            'Evitar centrifugação forte; usar detergente neutro.',
            'Não deixar de molho por muito tempo, alvejar nem expor ao sol forte; secar pendurado à sombra.',
            'Lavar cores separadas. Água máx. 30°C; sem alvejante; ferro a vapor OK; sem máquina; secar à sombra.',
            'Nota de cor: monitores e luz podem variar; prevalece a peça real.',
        ],
        'size_guide_title' => 'Guia de tamanhos',
        'size_guide_body' => 'Escolha pela altura, busto e peso da tabela; medição manual pode variar 1–3 cm.',
    ],
    'id_ID' => [
        'info_title' => 'Informasi produk',
        'info_basics' => 'Dasar',
        'info_comfort' => 'Sentuhan & potongan',
        'label_name' => 'Nama',
        'label_style' => 'Gaya',
        'label_color' => 'Warna',
        'label_parts' => 'Bagian',
        'info_name' => 'Lianxi',
        'info_style' => 'Hanfu pinggang tinggi (qiyao)',
        'info_color' => 'Hijau, merah',
        'info_parts' => 'Jubah luar, dalaman, rok pinggang',
        'c_hand' => 'Sentuhan',
        'c_hand_opts' => ['Kaku', 'Agak kaku', 'Sedang', 'Agak lembut', 'Lembut'],
        'c_hand_sel' => 'Sedang',
        'c_stretch' => 'Elastisitas',
        'c_stretch_opts' => ['Tidak', 'Sedikit', 'Sedang', 'Elastis', 'Sangat elastis'],
        'c_stretch_sel' => 'Tidak',
        'c_thick' => 'Ketebalan',
        'c_thick_opts' => ['Tipis', 'Agak tipis', 'Sedang', 'Agak tebal', 'Tebal'],
        'c_thick_sel' => 'Sedang',
        'c_fit' => 'Potongan',
        'c_fit_opts' => ['Ketat', 'Slim', 'Sedang', 'Kasual', 'Longgar'],
        'c_fit_sel' => 'Sedang',
        'chart_title' => 'Tabel ukuran',
        'chart_note' => 'Satuan: cm. Pengukuran manual boleh berselisih 1–3 cm. Berat: KG.',
        'h_size' => 'Ukuran',
        'h_height' => 'Tinggi disarankan',
        'h_bust' => 'Lingkar dada',
        'h_weight' => 'Berat maks.',
        'care_title' => 'Perawatan',
        'care_lines' => [
            'Cuci suhu ruang; jangan digosok, disikat, atau diperas keras.',
            'Hindari spin kasar; gunakan detergen netral.',
            'Jangan rendam lama, bleach, atau jemur terik; jemur di tempat teduh.',
            'Cuci warna terpisah. Air maks. 30°C; tanpa bleach; setrika uap OK; tanpa mesin; jemur teduh.',
            'Catatan warna: monitor dan cahaya bisa beda; acuan adalah pakaian asli.',
        ],
        'size_guide_title' => 'Panduan ukuran',
        'size_guide_body' => 'Pilih menurut tinggi, dada, dan berat pada tabel; ukur tangan boleh ±1–3 cm.',
    ],
    'ar_SA' => [
        'info_title' => 'معلومات المنتج',
        'info_basics' => 'أساسيات',
        'info_comfort' => 'الملمس والقصة',
        'label_name' => 'الاسم',
        'label_style' => 'الطراز',
        'label_color' => 'اللون',
        'label_parts' => 'الأجزاء',
        'info_name' => 'ليانشي',
        'info_style' => 'هانفو بخصر عالٍ (تشياو)',
        'info_color' => 'أخضر، أحمر',
        'info_parts' => 'رداء خارجي، قطعة داخلية، تنورة خصر',
        'c_hand' => 'الملمس',
        'c_hand_opts' => ['قاسٍ', 'قاسٍ قليلاً', 'متوسط', 'ناعم قليلاً', 'ناعم'],
        'c_hand_sel' => 'متوسط',
        'c_stretch' => 'المرونة',
        'c_stretch_opts' => ['بدون', 'خفيفة', 'متوسطة', 'مرنة', 'مرنة جداً'],
        'c_stretch_sel' => 'بدون',
        'c_thick' => 'السماكة',
        'c_thick_opts' => ['خفيف', 'خفيف قليلاً', 'متوسط', 'سميك قليلاً', 'سميك'],
        'c_thick_sel' => 'متوسط',
        'c_fit' => 'القصة',
        'c_fit_opts' => ['ضيق', 'نحيف', 'متوسط', 'كاجوال', 'فضفاض'],
        'c_fit_sel' => 'متوسط',
        'chart_title' => 'جدول المقاسات',
        'chart_note' => 'الوحدة: سم. القياس اليدوي قد يختلف بمقدار 1–3 سم. الوزن: كغ.',
        'h_size' => 'المقاس',
        'h_height' => 'الطول المقترح',
        'h_bust' => 'محيط الصدر',
        'h_weight' => 'أقصى وزن',
        'care_title' => 'العناية',
        'care_lines' => [
            'اغسل في درجة حرارة الغرفة؛ تجنب الفرك القوي أو الفرشاة أو العصر العنيف.',
            'تجنب العصر القوي؛ استخدم منظفاً محايداً.',
            'لا تنقع طويلاً ولا تبيّض ولا تعرض للشمس الحادة؛ جفف معلقاً في الظل.',
            'اغسل الألوان منفصلة. ماء بحد أقصى 30°م؛ بدون تبييض؛ كي بالبخار مسموح؛ بدون غسالة؛ تجفيف في الظل.',
            'ملاحظة اللون: الشاشات والإضاءة قد تختلف؛ المرجع هو القطعة الفعلية.',
        ],
        'size_guide_title' => 'دليل المقاس',
        'size_guide_body' => 'اختر حسب الطول والصدر والوزن في الجدول؛ القياس اليدوي قد يختلف 1–3 سم.',
    ],
    'hi_IN' => [
        'info_title' => 'उत्पाद जानकारी',
        'info_basics' => 'मूल जानकारी',
        'info_comfort' => 'स्पर्श और फिट',
        'label_name' => 'नाम',
        'label_style' => 'शैली',
        'label_color' => 'रंग',
        'label_parts' => 'भाग',
        'info_name' => 'लियनशी',
        'info_style' => 'कमर-ऊँचा (चियाओ) हानफू',
        'info_color' => 'हरा, लाल',
        'info_parts' => 'बाहरी वस्त्र, अंदरूनी वस्त्र, कमर स्कर्ट',
        'c_hand' => 'स्पर्श',
        'c_hand_opts' => ['कठोर', 'थोड़ा कठोर', 'मध्यम', 'थोड़ा नरम', 'नरम'],
        'c_hand_sel' => 'मध्यम',
        'c_stretch' => 'खिंचाव',
        'c_stretch_opts' => ['नहीं', 'हल्का', 'मध्यम', 'लोचदार', 'अत्यधिक'],
        'c_stretch_sel' => 'नहीं',
        'c_thick' => 'मोटाई',
        'c_thick_opts' => ['पतला', 'थोड़ा पतला', 'मध्यम', 'थोड़ा मोटा', 'मोटा'],
        'c_thick_sel' => 'मध्यम',
        'c_fit' => 'फिट',
        'c_fit_opts' => ['टाइट', 'स्लिम', 'मध्यम', 'कैज़ुअल', 'ढीला'],
        'c_fit_sel' => 'मध्यम',
        'chart_title' => 'साइज़ संदर्भ तालिका',
        'chart_note' => 'इकाई: सेमी। हाथ माप में 1–3 सेमी अंतर सामान्य। वजन: केजी।',
        'h_size' => 'साइज़',
        'h_height' => 'सुझाई ऊँचाई',
        'h_bust' => 'सुझाई छाती',
        'h_weight' => 'अधिकतम वजन',
        'care_title' => 'देखभाल',
        'care_lines' => [
            'कमरे के तापमान पर धोएँ; जोर से रगड़ने, ब्रश या निचोड़ने से बचें।',
            'तेज़ स्पिन से बचें; हल्का डिटर्जेंट उपयोग करें।',
            'लंबा भिगोना, ब्लीच या तेज़ धूप न दें; छाया में लटकाकर सुखाएँ।',
            'रंग अलग धोएँ। अधिकतम पानी 30°C; ब्लीच नहीं; भाप इस्त्री ठीक; मशीन नहीं; छाया में सुखाएँ।',
            'रंग नोट: मॉनिटर और रोशनी से अंतर हो सकता है; वास्तविक वस्त्र मानें।',
        ],
        'size_guide_title' => 'साइज़ गाइड',
        'size_guide_body' => 'ऊपर की ऊँचाई, छाती और वजन सीमा से चुनें; हाथ माप ±1–3 सेमी हो सकता है।',
    ],
    'bn_BD' => [
        'info_title' => 'পণ্যের তথ্য',
        'info_basics' => 'মূল তথ্য',
        'info_comfort' => 'স্পর্শ ও ফিট',
        'label_name' => 'নাম',
        'label_style' => 'শৈলী',
        'label_color' => 'রঙ',
        'label_parts' => 'অংশ',
        'info_name' => 'লিয়ানশি',
        'info_style' => 'কোমর-উঁচু (চিয়াও) হানফু',
        'info_color' => 'সবুজ, লাল',
        'info_parts' => 'বাইরের পোশাক, ভিতরের পোশাক, কোমর স্কার্ট',
        'c_hand' => 'স্পর্শ',
        'c_hand_opts' => ['শক্ত', 'একটু শক্ত', 'মাঝারি', 'একটু নরম', 'নরম'],
        'c_hand_sel' => 'মাঝারি',
        'c_stretch' => 'ইলাস্টিসিটি',
        'c_stretch_opts' => ['নেই', 'সামান্য', 'মাঝারি', 'ইলাস্টিক', 'অত্যধিক'],
        'c_stretch_sel' => 'নেই',
        'c_thick' => 'পুরুত্ব',
        'c_thick_opts' => ['পাতলা', 'একটু পাতলা', 'মাঝারি', 'একটু মোটা', 'মোটা'],
        'c_thick_sel' => 'মাঝারি',
        'c_fit' => 'ফিট',
        'c_fit_opts' => ['টাইট', 'স্লিম', 'মাঝারি', 'ক্যাজুয়াল', 'ঢিলে'],
        'c_fit_sel' => 'মাঝারি',
        'chart_title' => 'সাইজ রেফারেন্স তালিকা',
        'chart_note' => 'একক: সেমি। হাতের মাপে ১–৩ সেমি ফারাক স্বাভাবিক। ওজন: কেজি।',
        'h_size' => 'সাইজ',
        'h_height' => 'প্রস্তাবিত উচ্চতা',
        'h_bust' => 'প্রস্তাবিত বুক',
        'h_weight' => 'সর্বোচ্চ ওজন',
        'care_title' => 'যত্ন',
        'care_lines' => [
            'ঘরের তাপমাত্রায় ধুবেন; জোরে ঘষা, ব্রাশ বা নিংড়ানো এড়াবেন।',
            'তীব্র স্পিন এড়াবেন; হালকা ডিটারজেন্ট ব্যবহার করুন।',
            'দীর্ঘ ভেজানো, ব্লিচ বা তীব্র রোদ নয়; ছায়ায় ঝুলিয়ে শুকান।',
            'রঙ আলাদা ধুবেন। সর্বোচ্চ পানি ৩০°C; ব্লিচ নয়; স্টিম ইস্ত্রি ঠিক; মেশিন নয়; ছায়ায় শুকান।',
            'রঙ নোট: মনিটর ও আলোতে পার্থক্য হতে পারে; আসল পোশাকই মানদণ্ড।',
        ],
        'size_guide_title' => 'সাইজ নির্দেশিকা',
        'size_guide_body' => 'উপরের উচ্চতা, বুক ও ওজন সীমা অনুযায়ী বেছে নিন; হাতের মাপে ১–৩ সেমি ফারাক হতে পারে।',
    ],
    'ur_PK' => [
        'info_title' => 'مصنوعات کی معلومات',
        'info_basics' => 'بنیادی معلومات',
        'info_comfort' => 'لمس اور فٹ',
        'label_name' => 'نام',
        'label_style' => 'طرز',
        'label_color' => 'رنگ',
        'label_parts' => 'حصے',
        'info_name' => 'لیانشی',
        'info_style' => 'کمر اونچا (چیاؤ) ہانفو',
        'info_color' => 'سبز، سرخ',
        'info_parts' => 'بیرونی چوغہ، اندرونی لباس، کمر اسکرٹ',
        'c_hand' => 'لمس',
        'c_hand_opts' => ['سخت', 'تھوڑا سخت', 'درمیانہ', 'تھوڑا نرم', 'نرم'],
        'c_hand_sel' => 'درمیانہ',
        'c_stretch' => 'کھینچاؤ',
        'c_stretch_opts' => ['نہیں', 'ہلکا', 'درمیانہ', 'لچکدار', 'بہت لچکدار'],
        'c_stretch_sel' => 'نہیں',
        'c_thick' => 'موٹائی',
        'c_thick_opts' => ['پتلا', 'تھوڑا پتلا', 'درمیانہ', 'تھوڑا موٹا', 'موٹا'],
        'c_thick_sel' => 'درمیانہ',
        'c_fit' => 'فٹ',
        'c_fit_opts' => ['تنگ', 'سلِم', 'درمیانہ', 'کیژوئل', 'ڈھیلا'],
        'c_fit_sel' => 'درمیانہ',
        'chart_title' => 'سائز حوالہ جدول',
        'chart_note' => 'اکائی: سینٹی میٹر۔ ہاتھ کی پیمائش میں ۱–۳ سینٹی میٹر فرق عام ہے۔ وزن: کلوگرام۔',
        'h_size' => 'سائز',
        'h_height' => 'مجوزہ قد',
        'h_bust' => 'مجوزہ سینہ',
        'h_weight' => 'زیادہ سے زیادہ وزن',
        'care_title' => 'نگہداشت',
        'care_lines' => [
            'کمرے کے درجہ حرارت پر دھوئیں؛ زور سے رگڑنے، برش یا نچوڑنے سے گریز کریں۔',
            'شدید اسپن سے بچیں؛ ہلکا ڈٹرجنٹ استعمال کریں۔',
            'طویل بھگونے، بلیچ یا تیز دھوپ سے بچیں؛ سایہ میں لٹکا کر خشک کریں۔',
            'رنگ الگ دھوئیں۔ زیادہ سے زیادہ پانی ۳۰°C؛ بلیچ نہیں؛ بھاپ استری ٹھیک؛ مشین نہیں؛ سایہ میں خشک۔',
            'رنگ نوٹ: مانیٹر اور روشنی سے فرق ہو سکتا ہے؛ اصل لباس معیار ہے۔',
        ],
        'size_guide_title' => 'سائز رہنما',
        'size_guide_body' => 'اوپر کی قد، سینہ اور وزن کی حدود سے منتخب کریں؛ ہاتھ کی پیمائش ±۱–۳ سینٹی میٹر ہو سکتی ہے۔',
    ],
];

$buildInfo = static function (array $t): string {
    return DetailDescriptionTextifier::buildProductInfoPanelZh(
        [
            (string)$t['label_name'] => (string)$t['info_name'],
            (string)$t['label_style'] => (string)$t['info_style'],
            (string)$t['label_color'] => (string)$t['info_color'],
            (string)$t['label_parts'] => (string)$t['info_parts'],
        ],
        [
            ['label' => (string)$t['c_hand'], 'options' => (array)$t['c_hand_opts'], 'selected' => (string)$t['c_hand_sel']],
            ['label' => (string)$t['c_stretch'], 'options' => (array)$t['c_stretch_opts'], 'selected' => (string)$t['c_stretch_sel']],
            ['label' => (string)$t['c_thick'], 'options' => (array)$t['c_thick_opts'], 'selected' => (string)$t['c_thick_sel']],
            ['label' => (string)$t['c_fit'], 'options' => (array)$t['c_fit_opts'], 'selected' => (string)$t['c_fit_sel']],
        ],
        (string)$t['info_title'],
        (string)$t['info_basics'],
        (string)$t['info_comfort'],
    );
};

$buildChart = static function (array $t) use ($sizeRows): string {
    return DetailDescriptionTextifier::buildMeasurementSizeChartZh(
        [[
            'title' => (string)$t['chart_title'],
            'headers' => [
                (string)$t['h_size'],
                (string)$t['h_height'],
                (string)$t['h_bust'],
                (string)$t['h_weight'],
            ],
            'rows' => $sizeRows,
        ]],
        (string)$t['chart_title'],
        (string)$t['chart_note'],
    );
};

$buildCare = static function (array $t): string {
    $lis = '';
    foreach ((array)$t['care_lines'] as $line) {
        $lis .= '<li>' . htmlspecialchars((string)$line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
    }

    return DetailDescriptionTextifier::sanitizeFragment(
        '<div class="weline-detail-prose weline-detail-prose--checklist" data-weline-detail-text="care">'
        . '<h3>' . htmlspecialchars((string)$t['care_title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>'
        . '<ul>' . $lis . '</ul>'
        . '</div>'
    );
};

$buildSizeGuide = static function (array $t): string {
    return DetailDescriptionTextifier::sanitizeFragment(
        '<div class="weline-detail-prose" data-weline-detail-text="size-guide">'
        . '<h3>' . htmlspecialchars((string)$t['size_guide_title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>'
        . '<p>' . htmlspecialchars((string)$t['size_guide_body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        . '</div>'
    );
};

$stripEmptyFigures = static function (string $html): string {
    $html = preg_replace('#<div class="weline-detail-figure"\s*>\s*</div>#i', '', $html) ?? $html;
    $html = preg_replace(
        '#<div class="weline-detail-feature[^"]*"[^>]*>\s*<div class="weline-detail-feature__media">\s*</div>\s*<div class="weline-detail-feature__copy">.*?</div>\s*</div>#is',
        '',
        $html
    ) ?? $html;
    $html = preg_replace('#<(p|div|span)\b[^>]*>\s*</\1>#i', '', $html) ?? $html;

    return $html;
};

$removeFeatureWithAsset = static function (string $html, string $assetId) use ($stripEmptyFigures): string {
    $pattern = '#<div class="weline-detail-feature[^"]*"[^>]*>.*?<img\b[^>]*\bsrc=(["\'])asset://'
        . preg_quote($assetId, '#')
        . '\1[^>]*/?>.*?</div>\s*</div>#is';
    $next = preg_replace($pattern, '', $html, 1, $count);
    if (!is_string($next) || $count < 1) {
        $next = DetailDescriptionTextifier::replaceAssetImageWithHtml($html, $assetId, '');
        if ($next === $html) {
            $imgPattern = '#<img\b[^>]*\bsrc=(["\'])asset://' . preg_quote($assetId, '#') . '\1[^>]*/?>#i';
            $next = preg_replace($imgPattern, '', $html) ?? $html;
        }
    }

    return $stripEmptyFigures($next);
};

$replaceProductInfoBlock = static function (string $html, string $infoHtml): string {
    $pattern = '#<div class="weline-detail-text weline-detail-text--product-info"[^>]*>.*?</div>#is';
    if (preg_match($pattern, $html) === 1) {
        return (string)preg_replace($pattern, $infoHtml, $html, 1);
    }

    return $html . $infoHtml;
};

$ensureCharts = static function (string $html, string $chartsHtml): string {
    if (str_contains($html, 'weline-detail-text--size-chart') || str_contains($html, 'data-weline-detail-text="measurement-chart"')) {
        $html = preg_replace(
            '#(<div class="weline-detail-text weline-detail-text--size-chart"[^>]*>.*?</div>\s*)+#is',
            '',
            $html
        ) ?? $html;
    }
    if (preg_match('#(<div class="weline-detail-text weline-detail-text--product-info"[^>]*>.*?</div>)#is', $html, $m, PREG_OFFSET_CAPTURE)) {
        $end = $m[0][1] + strlen($m[0][0]);

        return substr($html, 0, $end) . $chartsHtml . substr($html, $end);
    }

    return $html . $chartsHtml;
};

$replaceCareChecklist = static function (string $html, string $careHtml): string {
    $pattern = '#<div class="weline-detail-prose weline-detail-prose--checklist"[^>]*>.*?</div>#is';
    if (preg_match($pattern, $html) === 1) {
        return (string)preg_replace($pattern, $careHtml, $html, 1);
    }

    return $careHtml . $html;
};

$replaceSizeGuide = static function (string $html, string $guideHtml): string {
    // Prefer replacing existing vague size-guide / 尺码参照 prose after charts.
    $pattern = '#<div class="weline-detail-prose"(?: data-weline-detail-text="size-guide")?>(?:\s*<h3>[^<]*(?:Size guide|尺码参照|Guía|Guide des|Guia|Panduan|دليل|साइज़|সাইজ|سائز)[^<]*</h3>\s*<p>.*?</p>\s*)</div>#iu';
    if (preg_match($pattern, $html) === 1) {
        return (string)preg_replace($pattern, $guideHtml, $html, 1);
    }
    if (preg_match('#(<div class="weline-detail-text weline-detail-text--size-chart"[^>]*>.*?</div>)#is', $html, $m, PREG_OFFSET_CAPTURE)) {
        $end = $m[0][1] + strlen($m[0][0]);

        return substr($html, 0, $end) . $guideHtml . substr($html, $end);
    }

    return $html . $guideHtml;
};

/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);

$env = include dirname(__DIR__, 5) . '/app/etc/env.php';
$db = $env['db']['master'] ?? [];
$pdo = new PDO(
    sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        (string)($db['hostname'] ?? '127.0.0.1'),
        (string)($db['hostport'] ?? '5432'),
        (string)($db['database'] ?? ''),
    ),
    (string)($db['username'] ?? ''),
    (string)($db['password'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

/** @var \Weline\Websites\Model\Website $websiteModel */
$websiteModel = ObjectManager::getInstance(\Weline\Websites\Model\Website::class)->loadById($websiteId)
    ?: ObjectManager::getInstance(\Weline\Websites\Model\Website::class)->load($websiteId);
$enabledLocales = $websiteModel ? array_values(array_filter(array_map('strval', (array)$websiteModel->getLanguageCodes()))) : [];
if ($enabledLocales === []) {
    $enabledLocales = array_keys($packs);
}
$defaultLang = $websiteModel ? (string)$websiteModel->getDefaultLanguage() : 'en_US';
if ($defaultLang === '' || !isset($packs[$defaultLang])) {
    $defaultLang = 'en_US';
}

$localePlan = ['' => $defaultLang];
foreach ($enabledLocales as $code) {
    $localePlan[$code] = $code;
}

echo "ENABLED LOCALES (+empty):\n";
foreach ($localePlan as $loc => $base) {
    echo '  [' . ($loc === '' ? '(empty)' : $loc) . "] => {$base}\n";
}

$zhLeakMarkers = ['产品信息', '适合身高', '建议胸围', '最大体重', '洗涤说明', '最高水温', '不可漂白', '不可机洗', '阴凉晾干'];
$enLeakMarkers = ['Full and mid shots stack', 'Near views show stitch'];

$writes = [];
foreach ($localePlan as $locale => $baseKey) {
    if (!isset($packs[$baseKey])) {
        fwrite(STDERR, "missing pack for {$baseKey}\n");
        exit(2);
    }
    $t = $packs[$baseKey];
    $st = $pdo->prepare(
        "SELECT value_text FROM w_product_ws_0_attribute_value
         WHERE entity_id=? AND attribute_code='description' AND locale=?
         ORDER BY length(COALESCE(value_text,'')) DESC LIMIT 1"
    );
    $st->execute([(string)$productId, $locale]);
    $html = (string)$st->fetchColumn();
    if ($html === '') {
        $st->execute([(string)$productId, 'zh_Hans_CN']);
        $html = (string)$st->fetchColumn();
    }
    if ($html === '') {
        fwrite(STDERR, "no description for locale {$locale}\n");
        exit(2);
    }

    $hadChartImg = str_contains($html, 'asset://' . $assetInfoChart);
    $html = $removeFeatureWithAsset($html, $assetInfoChart);
    $html = $replaceProductInfoBlock($html, $buildInfo($t));
    $html = $ensureCharts($html, $buildChart($t));
    $html = $replaceCareChecklist($html, $buildCare($t));
    $html = $replaceSizeGuide($html, $buildSizeGuide($t));

    if (str_contains($html, 'asset://' . $assetInfoChart)) {
        fwrite(STDERR, "info chart asset still present for {$locale}\n");
        exit(3);
    }
    if ($baseKey !== 'zh_Hans_CN') {
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach ($zhLeakMarkers as $marker) {
            if (str_contains($plain, $marker)) {
                fwrite(STDERR, "ZH leak {$locale}: {$marker}\n");
                exit(3);
            }
        }
    }
    if (!in_array($baseKey, ['en_US'], true)) {
        foreach ($enLeakMarkers as $marker) {
            // EN dump in non-EN body of NEW blocks only — old English feature copy may remain elsewhere;
            // gate only on leftover English care/size stubs if they replace failed.
            if ($baseKey !== 'en_US' && str_contains($html, 'Hand wash separately; no bleach')) {
                fwrite(STDERR, "EN care stub still present {$locale}\n");
                exit(3);
            }
        }
    }
    if (!str_contains($html, 'data-weline-detail-text="measurement-chart"')
        && !str_contains($html, 'weline-detail-text--size-chart')) {
        fwrite(STDERR, "missing size chart {$locale}\n");
        exit(3);
    }
    if (!str_contains($html, 'weline-detail-text--product-info')) {
        fwrite(STDERR, "missing product-info {$locale}\n");
        exit(3);
    }

    $writes[] = [
        'locale' => $locale,
        'base' => $baseKey,
        'len' => strlen($html),
        'had_chart_img' => $hadChartImg,
        'html' => $html,
    ];
    echo sprintf(
        "plan %s base=%s len=%d had_chart_img=%s chart=%s care=%s\n",
        $locale === '' ? '(empty)' : $locale,
        $baseKey,
        strlen($html),
        $hadChartImg ? 'Y' : 'N',
        (str_contains($html, 'measurement-chart') || str_contains($html, 'size-chart')) ? 'Y' : 'N',
        str_contains($html, 'data-weline-detail-text="care"') ? 'Y' : 'N'
    );
}

if (!$apply) {
    echo "Dry-run only. Pass --apply to write.\n";
    exit(0);
}

foreach ($writes as $w) {
    $locale = (string)$w['locale'];
    $html = (string)$w['html'];
    if ($locale !== '') {
        LocalDescription::upsertQuiet($productId, $locale, [
            LocalDescription::schema_fields_DESCRIPTION => $html,
        ]);
    }
    $attributes->writeExplicit($websiteId, 0, 'product', $productId, 'description', $locale, $html, true);
    if (str_contains($html, 'data-weds') && strlen($html) > 500) {
        $pdo->prepare(
            "DELETE FROM w_product_ws_0_attribute_value
             WHERE entity_id=? AND attribute_code='description' AND store_id=0 AND locale=?
               AND length(COALESCE(value_text,'')) < 200
               AND COALESCE(value_text,'') NOT LIKE '%data-weds%'"
        )->execute([(string)$productId, $locale]);
    }
}

ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)->notifyCatalogChanged(
    $websiteId,
    'product_402_info_chart_i18n',
    ['product_ids' => [$productId]],
);
ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class)
    ->clearForCatalogChange('product_402_info_chart_i18n');

echo "Applied " . count($writes) . " locale descriptions for product {$productId}.\n";
