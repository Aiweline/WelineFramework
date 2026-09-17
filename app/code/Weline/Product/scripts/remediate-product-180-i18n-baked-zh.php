<?php

declare(strict_types=1);

/**
 * 春不晚 #180：删中文烤字图（产品信息/立体展示/设计解析），按源图修正信息面板与尺码表，启用语字段级真译。
 *
 * php app/code/Weline/Product/scripts/remediate-product-180-i18n-baked-zh.php --dry-run
 * php app/code/Weline/Product/scripts/remediate-product-180-i18n-baked-zh.php --apply
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
$productId = 180;

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

/** baked Chinese assets still embedded in description HTML */
$assetProductInfo = '1a5f5a91-f288-4389-8046-4a92ea416017'; // detail-07 产品信息
$assetDesignBoard = '8b7dcd7b-aff7-43b5-b316-1fd6ef07e55b'; // detail-04 设计解析
$assetStereoBoard = 'f6a7c8ac-8c89-4877-a19a-db81584f3ac9'; // detail-06 立体展示

/**
 * Source values from detail-07 / detail-01 / detail-08 (OCR + vision).
 * Comfort scales match the baked panel: 微弹 / 宽松 / 轻薄.
 */
$packs = [
    'zh_Hans_CN' => [
        'info_title' => '产品信息',
        'info_basics' => '基本信息',
        'info_comfort' => '舒适度信息',
        'label_brand' => '品牌',
        'label_name' => '名称',
        'label_size' => '尺码',
        'label_color' => '颜色',
        'label_fabric' => '面料',
        'label_style' => '制式',
        'label_parts' => '部件',
        'info_brand' => '汉仪天下',
        'info_name' => '春不晚',
        'info_size' => 'XS–XL',
        'info_color' => '照片色',
        'info_fabric' => '舒适面料',
        'info_style' => '唐制',
        'info_parts' => '齐胸襦裙、诃子、大袖衫',
        'c_stretch' => '弹性',
        'c_stretch_opts' => ['适中', '微弹', '弹力', '高弹'],
        'c_stretch_sel' => '微弹',
        'c_fit' => '版型',
        'c_fit_opts' => ['紧身', '修身', '适中', '宽松'],
        'c_fit_sel' => '宽松',
        'c_thick' => '厚度',
        'c_thick_opts' => ['轻薄', '适中', '偏厚', '加厚'],
        'c_thick_sel' => '轻薄',
        'skirt_title' => '下裙尺码信息',
        'robe_title' => '破袖款大袖衫',
        'chart_note' => '单位：厘米（cm）。手工测量可能存在 1–3 cm 误差。',
        'h_size' => '尺码',
        'h_skirt_len' => '裙长（含裙头）',
        'h_waist' => '裙腰长',
        'h_height' => '建议身高',
        'h_robe_len' => '衣长',
        'h_bust' => '胸围',
        'design_heading' => '设计解析',
        'stereo_heading' => '立体展示',
    ],
    'en_US' => [
        'info_title' => 'Product information',
        'info_basics' => 'Basics',
        'info_comfort' => 'Comfort & fit',
        'label_brand' => 'Brand',
        'label_name' => 'Name',
        'label_size' => 'Size',
        'label_color' => 'Color',
        'label_fabric' => 'Fabric',
        'label_style' => 'Style',
        'label_parts' => 'Parts',
        'info_brand' => 'Hanyi Tianxia',
        'info_name' => 'Chun Bu Wan (Spring Not Late)',
        'info_size' => 'XS–XL',
        'info_color' => 'As shown',
        'info_fabric' => 'Comfortable fabric',
        'info_style' => 'Tang-style',
        'info_parts' => 'High-waist ruqun, hezi band, wide-sleeve robe',
        'c_stretch' => 'Elasticity',
        'c_stretch_opts' => ['Moderate', 'Slight stretch', 'Stretchy', 'High stretch'],
        'c_stretch_sel' => 'Slight stretch',
        'c_fit' => 'Fit',
        'c_fit_opts' => ['Tight', 'Slim', 'Regular', 'Relaxed'],
        'c_fit_sel' => 'Relaxed',
        'c_thick' => 'Thickness',
        'c_thick_opts' => ['Lightweight', 'Moderate', 'Slightly thick', 'Extra thick'],
        'c_thick_sel' => 'Lightweight',
        'skirt_title' => 'Skirt size chart',
        'robe_title' => 'Split-sleeve wide robe',
        'chart_note' => 'Unit: centimeters (cm). Hand measurements may vary by 1–3 cm.',
        'h_size' => 'Size',
        'h_skirt_len' => 'Skirt length (incl. waistband)',
        'h_waist' => 'Waistband length',
        'h_height' => 'Suggested height',
        'h_robe_len' => 'Garment length',
        'h_bust' => 'Bust',
        'design_heading' => 'Design notes',
        'stereo_heading' => 'Multi-angle view',
    ],
    'es_ES' => [
        'info_title' => 'Información del producto',
        'info_basics' => 'Básicos',
        'info_comfort' => 'Comodidad y ajuste',
        'label_brand' => 'Marca',
        'label_name' => 'Nombre',
        'label_size' => 'Talla',
        'label_color' => 'Color',
        'label_fabric' => 'Tejido',
        'label_style' => 'Estilo',
        'label_parts' => 'Piezas',
        'info_brand' => 'Hanyi Tianxia',
        'info_name' => 'Chun Bu Wan (La primavera no llega tarde)',
        'info_size' => 'XS–XL',
        'info_color' => 'Como en la foto',
        'info_fabric' => 'Tejido cómodo',
        'info_style' => 'Estilo Tang',
        'info_parts' => 'Ruqun de cintura alta, banda hezi, túnica de mangas anchas',
        'c_stretch' => 'Elasticidad',
        'c_stretch_opts' => ['Moderada', 'Ligera', 'Elástica', 'Alta'],
        'c_stretch_sel' => 'Ligera',
        'c_fit' => 'Corte',
        'c_fit_opts' => ['Ajustado', 'Entallado', 'Regular', 'Holgado'],
        'c_fit_sel' => 'Holgado',
        'c_thick' => 'Grosor',
        'c_thick_opts' => ['Ligero', 'Moderado', 'Algo grueso', 'Extra grueso'],
        'c_thick_sel' => 'Ligero',
        'skirt_title' => 'Guía de tallas de falda',
        'robe_title' => 'Túnica de mangas anchas (manga partida)',
        'chart_note' => 'Unidad: centímetros (cm). La medida manual puede variar 1–3 cm.',
        'h_size' => 'Talla',
        'h_skirt_len' => 'Largo de falda (con cintura)',
        'h_waist' => 'Largo de cintura',
        'h_height' => 'Altura sugerida',
        'h_robe_len' => 'Largo de prenda',
        'h_bust' => 'Busto',
        'design_heading' => 'Análisis del diseño',
        'stereo_heading' => 'Vista en varios ángulos',
    ],
    'fr_FR' => [
        'info_title' => 'Informations produit',
        'info_basics' => 'Essentiels',
        'info_comfort' => 'Confort et coupe',
        'label_brand' => 'Marque',
        'label_name' => 'Nom',
        'label_size' => 'Taille',
        'label_color' => 'Couleur',
        'label_fabric' => 'Tissu',
        'label_style' => 'Style',
        'label_parts' => 'Pièces',
        'info_brand' => 'Hanyi Tianxia',
        'info_name' => 'Chun Bu Wan (Le printemps n’est pas tard)',
        'info_size' => 'XS–XL',
        'info_color' => 'Comme sur la photo',
        'info_fabric' => 'Tissu confortable',
        'info_style' => 'Style Tang',
        'info_parts' => 'Ruqun taille haute, bande hezi, robe à manches larges',
        'c_stretch' => 'Élasticité',
        'c_stretch_opts' => ['Modérée', 'Légère', 'Élastique', 'Élevée'],
        'c_stretch_sel' => 'Légère',
        'c_fit' => 'Coupe',
        'c_fit_opts' => ['Serrée', 'Ajustée', 'Régulière', 'Ample'],
        'c_fit_sel' => 'Ample',
        'c_thick' => 'Épaisseur',
        'c_thick_opts' => ['Légère', 'Modérée', 'Un peu épaisse', 'Extra épaisse'],
        'c_thick_sel' => 'Légère',
        'skirt_title' => 'Guide des tailles — jupe',
        'robe_title' => 'Robe à manches larges (manche fendue)',
        'chart_note' => 'Unité : centimètres (cm). Mesure manuelle : écart possible de 1–3 cm.',
        'h_size' => 'Taille',
        'h_skirt_len' => 'Longueur jupe (avec ceinture)',
        'h_waist' => 'Longueur ceinture',
        'h_height' => 'Taille suggérée',
        'h_robe_len' => 'Longueur vêtement',
        'h_bust' => 'Tour de poitrine',
        'design_heading' => 'Lecture du design',
        'stereo_heading' => 'Vue multi-angles',
    ],
    'pt_BR' => [
        'info_title' => 'Informações do produto',
        'info_basics' => 'Básicos',
        'info_comfort' => 'Conforto e caimento',
        'label_brand' => 'Marca',
        'label_name' => 'Nome',
        'label_size' => 'Tamanho',
        'label_color' => 'Cor',
        'label_fabric' => 'Tecido',
        'label_style' => 'Estilo',
        'label_parts' => 'Peças',
        'info_brand' => 'Hanyi Tianxia',
        'info_name' => 'Chun Bu Wan (A primavera não atrasa)',
        'info_size' => 'XS–XL',
        'info_color' => 'Como na foto',
        'info_fabric' => 'Tecido confortável',
        'info_style' => 'Estilo Tang',
        'info_parts' => 'Ruqun cintura alta, faixa hezi, túnica de mangas largas',
        'c_stretch' => 'Elasticidade',
        'c_stretch_opts' => ['Moderada', 'Leve', 'Elástica', 'Alta'],
        'c_stretch_sel' => 'Leve',
        'c_fit' => 'Caimento',
        'c_fit_opts' => ['Apertado', 'Ajustado', 'Regular', 'Folgado'],
        'c_fit_sel' => 'Folgado',
        'c_thick' => 'Espessura',
        'c_thick_opts' => ['Leve', 'Moderada', 'Um pouco grossa', 'Extra grossa'],
        'c_thick_sel' => 'Leve',
        'skirt_title' => 'Tabela de medidas — saia',
        'robe_title' => 'Túnica de mangas largas (manga partida)',
        'chart_note' => 'Unidade: centímetros (cm). Medida manual pode variar 1–3 cm.',
        'h_size' => 'Tamanho',
        'h_skirt_len' => 'Comprimento da saia (com cós)',
        'h_waist' => 'Comprimento do cós',
        'h_height' => 'Altura sugerida',
        'h_robe_len' => 'Comprimento da peça',
        'h_bust' => 'Busto',
        'design_heading' => 'Análise do design',
        'stereo_heading' => 'Vista em vários ângulos',
    ],
    'id_ID' => [
        'info_title' => 'Informasi produk',
        'info_basics' => 'Dasar',
        'info_comfort' => 'Kenyamanan & potongan',
        'label_brand' => 'Merek',
        'label_name' => 'Nama',
        'label_size' => 'Ukuran',
        'label_color' => 'Warna',
        'label_fabric' => 'Bahan',
        'label_style' => 'Gaya',
        'label_parts' => 'Bagian',
        'info_brand' => 'Hanyi Tianxia',
        'info_name' => 'Chun Bu Wan (Musim semi tak terlambat)',
        'info_size' => 'XS–XL',
        'info_color' => 'Sesuai foto',
        'info_fabric' => 'Kain nyaman',
        'info_style' => 'Gaya Tang',
        'info_parts' => 'Ruqun pinggang tinggi, pita hezi, jubah lengan lebar',
        'c_stretch' => 'Elastisitas',
        'c_stretch_opts' => ['Sedang', 'Sedikit elastis', 'Elastis', 'Sangat elastis'],
        'c_stretch_sel' => 'Sedikit elastis',
        'c_fit' => 'Potongan',
        'c_fit_opts' => ['Ketat', 'Slim', 'Sedang', 'Longgar'],
        'c_fit_sel' => 'Longgar',
        'c_thick' => 'Ketebalan',
        'c_thick_opts' => ['Tipis ringan', 'Sedang', 'Agak tebal', 'Lebih tebal'],
        'c_thick_sel' => 'Tipis ringan',
        'skirt_title' => 'Tabel ukuran rok',
        'robe_title' => 'Jubah lengan lebar (lengan belah)',
        'chart_note' => 'Satuan: sentimeter (cm). Pengukuran tangan bisa berbeda 1–3 cm.',
        'h_size' => 'Ukuran',
        'h_skirt_len' => 'Panjang rok (termasuk pinggang)',
        'h_waist' => 'Panjang pinggang',
        'h_height' => 'Tinggi disarankan',
        'h_robe_len' => 'Panjang baju',
        'h_bust' => 'Lingkar dada',
        'design_heading' => 'Analisis desain',
        'stereo_heading' => 'Tampilan multi-sudut',
    ],
    'ar_SA' => [
        'info_title' => 'معلومات المنتج',
        'info_basics' => 'أساسيات',
        'info_comfort' => 'الراحة والقصة',
        'label_brand' => 'العلامة',
        'label_name' => 'الاسم',
        'label_size' => 'المقاس',
        'label_color' => 'اللون',
        'label_fabric' => 'القماش',
        'label_style' => 'النمط',
        'label_parts' => 'الأجزاء',
        'info_brand' => 'هاني تيانشيا',
        'info_name' => 'تشون بو وان (الربيع ليس متأخرًا)',
        'info_size' => 'XS–XL',
        'info_color' => 'كما في الصورة',
        'info_fabric' => 'قماش مريح',
        'info_style' => 'طراز تانغ',
        'info_parts' => 'روتشون خصر عالٍ، شريط هيـزي، عباءة أكمام واسعة',
        'c_stretch' => 'المرونة',
        'c_stretch_opts' => ['متوسطة', 'قليلة', 'مرنة', 'عالية'],
        'c_stretch_sel' => 'قليلة',
        'c_fit' => 'القصة',
        'c_fit_opts' => ['ضيقة', 'مخصّرة', 'عادية', 'فضفاضة'],
        'c_fit_sel' => 'فضفاضة',
        'c_thick' => 'السماكة',
        'c_thick_opts' => ['خفيفة', 'متوسطة', 'أسمك قليلًا', 'سميكة جدًا'],
        'c_thick_sel' => 'خفيفة',
        'skirt_title' => 'جدول مقاسات التنورة',
        'robe_title' => 'عباءة الأكمام الواسعة (كم مشقوق)',
        'chart_note' => 'الوحدة: سنتيمتر (سم). القياس اليدوي قد يختلف بمقدار 1–3 سم.',
        'h_size' => 'المقاس',
        'h_skirt_len' => 'طول التنورة (مع الحزام)',
        'h_waist' => 'طول الحزام',
        'h_height' => 'الطول المقترح',
        'h_robe_len' => 'طول الثوب',
        'h_bust' => 'محيط الصدر',
        'design_heading' => 'تحليل التصميم',
        'stereo_heading' => 'عرض متعدد الزوايا',
    ],
    'bn_BD' => [
        'info_title' => 'পণ্যের তথ্য',
        'info_basics' => 'মূল তথ্য',
        'info_comfort' => 'আরাম ও ফিট',
        'label_brand' => 'ব্র্যান্ড',
        'label_name' => 'নাম',
        'label_size' => 'সাইজ',
        'label_color' => 'রঙ',
        'label_fabric' => 'কাপড়',
        'label_style' => 'শৈলী',
        'label_parts' => 'অংশ',
        'info_brand' => 'হানই তিয়ানশিয়া',
        'info_name' => 'চুন বু ওয়ান (বসন্ত দেরি নয়)',
        'info_size' => 'XS–XL',
        'info_color' => 'ছবি অনুসারে',
        'info_fabric' => 'আরামদায়ক কাপড়',
        'info_style' => 'তাং শৈলী',
        'info_parts' => 'উচ্চ কোমর রুচুন, হেজি ব্যান্ড, প্রশস্ত হাতার পোশাক',
        'c_stretch' => 'ইলাস্টিসিটি',
        'c_stretch_opts' => ['মাঝারি', 'সামান্য', 'ইলাস্টিক', 'উচ্চ'],
        'c_stretch_sel' => 'সামান্য',
        'c_fit' => 'ফিট',
        'c_fit_opts' => ['টাইট', 'স্লিম', 'মাঝারি', 'আলগা'],
        'c_fit_sel' => 'আলগা',
        'c_thick' => 'পুরুত্ব',
        'c_thick_opts' => ['হালকা পাতলা', 'মাঝারি', 'একটু মোটা', 'অতিরিক্ত মোটা'],
        'c_thick_sel' => 'হালকা পাতলা',
        'skirt_title' => 'স্কার্ট সাইজ চার্ট',
        'robe_title' => 'প্রশস্ত হাতার পোশাক (বিভক্ত হাতা)',
        'chart_note' => 'একক: সেন্টিমিটার (সেমি)। হাতে মাপলে ১–৩ সেমি ফারাক হতে পারে।',
        'h_size' => 'সাইজ',
        'h_skirt_len' => 'স্কার্টের দৈর্ঘ্য (কোমরসহ)',
        'h_waist' => 'কোমরবন্ধের দৈর্ঘ্য',
        'h_height' => 'প্রস্তাবিত উচ্চতা',
        'h_robe_len' => 'পোশাকের দৈর্ঘ্য',
        'h_bust' => 'বুকের মাপ',
        'design_heading' => 'ডিজাইন বিশ্লেষণ',
        'stereo_heading' => 'বহু-কোণ দৃশ্য',
    ],
    'hi_IN' => [
        'info_title' => 'उत्पाद जानकारी',
        'info_basics' => 'मूल बातें',
        'info_comfort' => 'आराम और फिट',
        'label_brand' => 'ब्रांड',
        'label_name' => 'नाम',
        'label_size' => 'साइज़',
        'label_color' => 'रंग',
        'label_fabric' => 'फ़ैब्रिक',
        'label_style' => 'शैली',
        'label_parts' => 'भाग',
        'info_brand' => 'हानयी तियानश्या',
        'info_name' => 'चुन बु वान (वसंत देर नहीं)',
        'info_size' => 'XS–XL',
        'info_color' => 'फ़ोटो के अनुसार',
        'info_fabric' => 'आरामदायक फ़ैब्रिक',
        'info_style' => 'तांग शैली',
        'info_parts' => 'ऊँची कमर रुक़ुन, हेज़ी बैंड, चौड़ी आस्तीन वाला चोग़ा',
        'c_stretch' => 'लचीलापन',
        'c_stretch_opts' => ['मध्यम', 'हल्का', 'स्ट्रेच', 'अधिक'],
        'c_stretch_sel' => 'हल्का',
        'c_fit' => 'फ़िट',
        'c_fit_opts' => ['टाइट', 'स्लिम', 'रेगुलर', 'ढीला'],
        'c_fit_sel' => 'ढीला',
        'c_thick' => 'मोटाई',
        'c_thick_opts' => ['हल्का पतला', 'मध्यम', 'थोड़ा मोटा', 'अतिरिक्त मोटा'],
        'c_thick_sel' => 'हल्का पतला',
        'skirt_title' => 'स्कर्ट साइज़ चार्ट',
        'robe_title' => 'चौड़ी आस्तीन वाला चोग़ा (स्प्लिट स्लीव)',
        'chart_note' => 'इकाई: सेंटीमीटर (सेमी)। हाथ से माप में 1–3 सेमी अंतर हो सकता है।',
        'h_size' => 'साइज़',
        'h_skirt_len' => 'स्कर्ट लंबाई (कमर सहित)',
        'h_waist' => 'कमरपट्टी लंबाई',
        'h_height' => 'सुझाई ऊँचाई',
        'h_robe_len' => 'कपड़े की लंबाई',
        'h_bust' => 'छाती',
        'design_heading' => 'डिज़ाइन विश्लेषण',
        'stereo_heading' => 'बहु-कोण दृश्य',
    ],
    'ur_PK' => [
        'info_title' => 'مصنوعات کی معلومات',
        'info_basics' => 'بنیادی معلومات',
        'info_comfort' => 'آرام اور فٹ',
        'label_brand' => 'برانڈ',
        'label_name' => 'نام',
        'label_size' => 'سائز',
        'label_color' => 'رنگ',
        'label_fabric' => 'کپڑا',
        'label_style' => 'طرز',
        'label_parts' => 'حصے',
        'info_brand' => 'ہانی تیآنشیا',
        'info_name' => 'چن بو وان (بہار دیر نہیں)',
        'info_size' => 'XS–XL',
        'info_color' => 'تصویر کے مطابق',
        'info_fabric' => 'آرام دہ کپڑا',
        'info_style' => 'تانگ طرز',
        'info_parts' => 'اونچی کمر روقن، ہیزی پٹی، چوڑی آستین والا چوغہ',
        'c_stretch' => 'لچک',
        'c_stretch_opts' => ['درمیانہ', 'ہلکی', 'اسٹریچ', 'زیادہ'],
        'c_stretch_sel' => 'ہلکی',
        'c_fit' => 'فٹ',
        'c_fit_opts' => ['تنگ', 'سلیم', 'درمیانہ', 'ڈھیلا'],
        'c_fit_sel' => 'ڈھیلا',
        'c_thick' => 'موٹائی',
        'c_thick_opts' => ['ہلکا پتلا', 'درمیانہ', 'کمی موٹا', 'اضافی موٹا'],
        'c_thick_sel' => 'ہلکا پتلا',
        'skirt_title' => 'اسکرٹ سائز چارٹ',
        'robe_title' => 'چوڑی آستین والا چوغہ (اسپلٹ آستین)',
        'chart_note' => 'اکائی: سینٹی میٹر (سینٹی میٹر)۔ دستی پیمائش میں 1–3 سینٹی میٹر فرق ہو سکتا ہے۔',
        'h_size' => 'سائز',
        'h_skirt_len' => 'اسکرٹ کی لمبائی (کمر سمیت)',
        'h_waist' => 'کمر بند کی لمبائی',
        'h_height' => 'تجویز کردہ قد',
        'h_robe_len' => 'کپڑے کی لمبائی',
        'h_bust' => 'سینہ',
        'design_heading' => 'ڈیزائن تجزیہ',
        'stereo_heading' => 'کئی زاویوں سے منظر',
    ],
];

$skirtRows = [
    ['XS', '116', '108', '150–155'],
    ['S', '118', '110', '155–160'],
    ['M', '120', '112', '160–165'],
    ['L', '124', '114', '165–170'],
    ['XL', '128', '116', '170–175'],
];
$robeRows = [
    ['XS', '116', '108', '150–155'],
    ['S', '118', '110', '155–160'],
    ['M', '120', '114', '160–165'],
    ['L', '124', '118', '165–170'],
    ['XL', '126', '122', '170–175'],
];

$buildInfo = static function (array $t) use ($h): string {
    return DetailDescriptionTextifier::buildProductInfoPanelZh(
        [
            (string)$t['label_brand'] => (string)$t['info_brand'],
            (string)$t['label_name'] => (string)$t['info_name'],
            (string)$t['label_size'] => (string)$t['info_size'],
            (string)$t['label_color'] => (string)$t['info_color'],
            (string)$t['label_fabric'] => (string)$t['info_fabric'],
            (string)$t['label_style'] => (string)$t['info_style'],
            (string)$t['label_parts'] => (string)$t['info_parts'],
        ],
        [
            ['label' => (string)$t['c_stretch'], 'options' => (array)$t['c_stretch_opts'], 'selected' => (string)$t['c_stretch_sel']],
            ['label' => (string)$t['c_fit'], 'options' => (array)$t['c_fit_opts'], 'selected' => (string)$t['c_fit_sel']],
            ['label' => (string)$t['c_thick'], 'options' => (array)$t['c_thick_opts'], 'selected' => (string)$t['c_thick_sel']],
        ],
        (string)$t['info_title'],
        (string)$t['info_basics'],
        (string)$t['info_comfort'],
    );
};

$buildCharts = static function (array $t) use ($skirtRows, $robeRows): string {
    $skirt = DetailDescriptionTextifier::buildMeasurementSizeChartZh(
        [[
            'title' => (string)$t['skirt_title'],
            'headers' => [(string)$t['h_size'], (string)$t['h_skirt_len'], (string)$t['h_waist'], (string)$t['h_height']],
            'rows' => $skirtRows,
        ]],
        (string)$t['skirt_title'],
        (string)$t['chart_note'],
    );
    $robe = DetailDescriptionTextifier::buildMeasurementSizeChartZh(
        [[
            'title' => (string)$t['robe_title'],
            'headers' => [(string)$t['h_size'], (string)$t['h_robe_len'], (string)$t['h_bust'], (string)$t['h_height']],
            'rows' => $robeRows,
        ]],
        (string)$t['robe_title'],
        (string)$t['chart_note'],
    );

    return $skirt . $robe;
};

$stripEmptyFigures = static function (string $html): string {
    $html = preg_replace('#<div class="weline-detail-figure"\s*>\s*</div>#i', '', $html) ?? $html;
    // pair with one remaining figure → keep stack
    $html = preg_replace(
        '#<div class="weline-detail-figure-row weline-detail-figure-row--pair">\s*(<div class="weline-detail-figure">.*?</div>)\s*</div>#is',
        '<div class="weline-detail-figure-row">$1</div>',
        $html
    ) ?? $html;
    $html = preg_replace('#<div class="weline-detail-figure-row[^"]*"\s*>\s*</div>#i', '', $html) ?? $html;
    $html = preg_replace('#<(p|div|span)\b[^>]*>\s*</\1>#i', '', $html) ?? $html;

    return $html;
};

$removeAssetImg = static function (string $html, string $assetId) use ($stripEmptyFigures): string {
    $next = DetailDescriptionTextifier::replaceAssetImageWithHtml($html, $assetId, '');
    if ($next === $html) {
        // fallback: strip any img tag for the asset
        $pattern = '#<img\b[^>]*\bsrc=(["\'])asset://' . preg_quote($assetId, '#') . '\1[^>]*/?>#i';
        $next = preg_replace($pattern, '', $html) ?? $html;
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
        // replace existing chart block(s) after product-info
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
$defaultLang = $websiteModel ? (string)$websiteModel->getDefaultLanguage() : 'zh_Hans_CN';
if ($defaultLang === '') {
    $defaultLang = 'zh_Hans_CN';
}

$localePlan = ['' => $defaultLang];
foreach ($enabledLocales as $code) {
    $localePlan[$code] = $code;
}

echo "ENABLED LOCALES (+empty):\n";
foreach ($localePlan as $loc => $base) {
    echo '  [' . ($loc === '' ? '(empty)' : $loc) . "] => {$base}\n";
}

$zhLeak = ['产品信息', '汉仪天下', '照片色', '舒适面料', '尺码参考表', '设计解析', '立体展示', '侧面', '正面', '背面', '下裙尺码', '破袖款'];
$enLeak = ['Full and mid shots', 'Near views show', 'Design wellspring'];

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
        // fallback to zh long description
        $st->execute([(string)$productId, 'zh_Hans_CN']);
        $html = (string)$st->fetchColumn();
    }
    if ($html === '') {
        fwrite(STDERR, "no description for locale {$locale}\n");
        exit(2);
    }

    $beforeHadInfoImg = str_contains($html, 'asset://' . $assetProductInfo);
    $html = $removeAssetImg($html, $assetProductInfo);
    $html = $removeAssetImg($html, $assetDesignBoard);
    $html = $removeAssetImg($html, $assetStereoBoard);

    // replace caption boards with translated section headings (once each)
    $designHeading = DetailDescriptionTextifier::buildSectionHeading((string)$t['design_heading'], 'design-notes');
    $stereoHeading = DetailDescriptionTextifier::buildSectionHeading((string)$t['stereo_heading'], 'multi-angle');
    if (!str_contains($html, 'data-weline-detail-text="multi-angle"')) {
        $html = preg_replace(
            '#(<div class="weline-detail-figure-stack[^"]*"[^>]*>.*?</div>)#is',
            '$1' . $stereoHeading,
            $html,
            1
        ) ?? ($html . $stereoHeading);
        if (!str_contains($html, 'data-weline-detail-text="multi-angle"')) {
            $html .= $stereoHeading;
        }
    }
    if (!str_contains($html, 'data-weline-detail-text="design-notes"')) {
        if (preg_match('#<div class="weline-detail-text weline-detail-text--product-info"#i', $html)) {
            $html = preg_replace(
                '#(<div class="weline-detail-text weline-detail-text--product-info")#i',
                $designHeading . '$1',
                $html,
                1
            ) ?? ($html . $designHeading);
        } else {
            $html .= $designHeading;
        }
    }

    $infoHtml = $buildInfo($t);
    $chartsHtml = $buildCharts($t);
    $html = $replaceProductInfoBlock($html, $infoHtml);
    $html = $ensureCharts($html, $chartsHtml);

    // gates
    if ($baseKey !== 'zh_Hans_CN') {
        foreach ($zhLeak as $marker) {
            // allow brand transliteration pages? no — Chinese chars of these markers must not remain as body text
            if (str_contains(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $marker)) {
                fwrite(STDERR, "ZH leak {$locale}: {$marker}\n");
                exit(3);
            }
        }
        // residual baked assets
        foreach ([$assetProductInfo, $assetDesignBoard, $assetStereoBoard] as $aid) {
            if (str_contains($html, 'asset://' . $aid)) {
                fwrite(STDERR, "baked asset still present {$locale}: {$aid}\n");
                exit(3);
            }
        }
    }
    if ($baseKey !== 'en_US') {
        foreach ($enLeak as $marker) {
            if (str_contains($html, $marker)) {
                fwrite(STDERR, "EN leak {$locale}: {$marker}\n");
                exit(3);
            }
        }
    }

    $writes[] = [
        'locale' => $locale,
        'base' => $baseKey,
        'len' => strlen($html),
        'had_info_img' => $beforeHadInfoImg,
        'html' => $html,
    ];
    echo sprintf(
        "plan %s base=%s len=%d had_info_img=%s charts=%s\n",
        $locale === '' ? '(empty)' : $locale,
        $baseKey,
        strlen($html),
        $beforeHadInfoImg ? 'Y' : 'N',
        str_contains($html, 'weline-detail-text--size-chart') ? 'Y' : 'N'
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
    'product_180_i18n_baked_zh',
    ['product_ids' => [$productId]],
);
ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class)
    ->clearForCatalogChange('product_180_i18n_baked_zh');

echo "Applied " . count($writes) . " locale descriptions for product {$productId}.\n";
