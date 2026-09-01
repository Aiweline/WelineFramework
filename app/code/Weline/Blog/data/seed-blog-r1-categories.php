<?php

declare(strict_types=1);

/**
 * One-shot: purge website-0 blog posts/categories, seed SEO architecture R1.
 *
 * Blog space is flat (no parent_id); R1 hierarchy is expressed via slug prefixes
 * and sort_order grouping (100s = pillar 1, 200s = pillar 2, …).
 *
 * Usage: php app/code/Weline/Blog/data/seed-blog-r1-categories.php
 */

use Weline\Blog\Model\Category;
use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogCategoryAdminService;
use Weline\Blog\Service\BlogCategoryAttributeService;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 4) . '/bootstrap.php';

const WEBSITE_ID = 0;
const MEDIA_BASE = '/media/blog/hanfu/categories';

/** @var list<string> */
const LOCALES = [
    'zh_Hans_CN',
    'en_US',
    'hi_IN',
    'es_ES',
    'ar_SA',
    'fr_FR',
    'bn_BD',
    'pt_BR',
    'id_ID',
    'ur_PK',
];

/**
 * @return list<array{code:string,sort:int,i18n:array<string,array{name:string,summary:string,description:string}>}>
 */
function r1Categories(): array
{
    $row = static function (
        string $zhName,
        string $zhSummary,
        string $zhDesc,
        string $enName,
        string $enSummary,
        string $enDesc,
        string $hi,
        string $es,
        string $ar,
        string $fr,
        string $bn,
        string $pt,
        string $id,
        string $ur,
    ): array {
        $enPack = ['name' => $enName, 'summary' => $enSummary, 'description' => $enDesc];

        return [
            'zh_Hans_CN' => ['name' => $zhName, 'summary' => $zhSummary, 'description' => $zhDesc],
            'en_US' => $enPack,
            'hi_IN' => ['name' => $hi, 'summary' => $enSummary, 'description' => $enDesc],
            'es_ES' => ['name' => $es, 'summary' => $enSummary, 'description' => $enDesc],
            'ar_SA' => ['name' => $ar, 'summary' => $enSummary, 'description' => $enDesc],
            'fr_FR' => ['name' => $fr, 'summary' => $enSummary, 'description' => $enDesc],
            'bn_BD' => ['name' => $bn, 'summary' => $enSummary, 'description' => $enDesc],
            'pt_BR' => ['name' => $pt, 'summary' => $enSummary, 'description' => $enDesc],
            'id_ID' => ['name' => $id, 'summary' => $enSummary, 'description' => $enDesc],
            'ur_PK' => ['name' => $ur, 'summary' => $enSummary, 'description' => $enDesc],
        ];
    };

    return [
        [
            'code' => 'hanfu-guide',
            'sort' => 100,
            'i18n' => $row(
                '汉服百科',
                '形制、朝代、面料与礼仪的权威入门。',
                '系统介绍汉服形制、朝代演变、面料工艺与礼仪场合，建立可检索的主题权威，服务海外与国内读者。',
                'Hanfu Guide',
                'Authoritative intro to styles, dynasties, fabrics and etiquette.',
                'A structured hub covering Hanfu silhouettes, dynastic evolution, fabrics/craft and dress occasions for global readers.',
                'हानफू गाइड', 'Guía Hanfu', 'دليل الهانفو', 'Guide Hanfu', 'হানফু গাইড', 'Guia Hanfu', 'Panduan Hanfu', 'ہانفو گائیڈ',
            ),
        ],
        [
            'code' => 'hanfu-styles',
            'sort' => 110,
            'i18n' => $row(
                '形制入门',
                '襦裙、马面、圆领袍等形制速查。',
                '用清晰对照说明主流形制特征，帮助读者区分正统汉服与影楼装、古风裙。',
                'Styles & Silhouettes',
                'Quick map of ruqun, mamian, yuanling and more.',
                'Clear comparisons of major Hanfu forms so shoppers can tell authentic silhouettes from costume dresses.',
                'शैली व सिल्हूट', 'Estilos y siluetas', 'الأنماط والقَطَع', 'Styles et silhouettes', 'স্টাইল ও সিলুয়েট', 'Estilos e silhuetas', 'Gaya & siluet', 'اسٹائل اور سلویٹ',
            ),
        ],
        [
            'code' => 'hanfu-dynasties',
            'sort' => 120,
            'i18n' => $row(
                '朝代演变',
                '唐、宋、明等时期衣冠演变一览。',
                '按朝代梳理汉服审美与结构变化，并说明新中式与正统形制的边界。',
                'Dynasties',
                'How Hanfu evolved across Tang, Song, Ming and more.',
                'Trace silhouette and aesthetic shifts by dynasty, plus where New Chinese Style diverges from classical forms.',
                'राजवंश', 'Dinastías', 'السلالات', 'Dynasties', 'রাজবংশ', 'Dinastias', 'Dinasti', 'سلطنتیں',
            ),
        ],
        [
            'code' => 'hanfu-fabrics',
            'sort' => 130,
            'i18n' => $row(
                '面料与工艺',
                '提花、刺绣、染色与绿色制造。',
                '讲解常用面料与工艺，并介绍手工结合绿色机械制造的品质标准。',
                'Fabrics & Craft',
                'Weaves, embroidery, dyeing and responsible making.',
                'Explain common fabrics and finishes, plus how handmade steps combine with green mechanical production.',
                'कपड़ा व शिल्प', 'Telas y oficio', 'الأقمشة والحِرَف', 'Tissus et savoir-faire', 'কাপড় ও কারুশিল্প', 'Tecidos e ofício', 'Kain & kerajinan', 'کپڑا اور دستکاری',
            ),
        ],
        [
            'code' => 'hanfu-occasions',
            'sort' => 140,
            'i18n' => $row(
                '礼仪与场合',
                '日常、婚礼、节令着装礼仪。',
                '按场景给出着装建议，连接礼仪文化与可购买的成套方案。',
                'Occasions & Etiquette',
                'Daily, wedding and festival dress codes.',
                'Occasion-based guidance that links etiquette with ready-to-wear sets shoppers can buy.',
                'अवसर व शिष्टाचार', 'Ocaciones y etiqueta', 'المناسبات والآداب', 'Occasions et étiquette', 'অনুষ্ঠান ও শিষ্টাচার', 'Ocasiões e etiqueta', 'Acara & etiket', 'مواقع اور آداب',
            ),
        ],
        [
            'code' => 'styling',
            'sort' => 200,
            'i18n' => $row(
                '穿搭与造型',
                '日常到婚礼的实用穿搭指南。',
                '从通勤、节令婚礼到妆发配饰与尺码保养，提供可落地的造型内容并导流商品类目。',
                'Styling',
                'Practical looks from daily wear to weddings.',
                'Actionable styling for commute, festivals/weddings, beauty & accessories, plus size and care tips linked to catalog.',
                'स्टाइलिंग', 'Estilismo', 'التنسيق', 'Styling', 'স্টাইলিং', 'Styling', 'Styling', 'اسٹائلنگ',
            ),
        ],
        [
            'code' => 'styling-daily',
            'sort' => 210,
            'i18n' => $row(
                '日常与通勤',
                '可上班、可出街的国风穿搭。',
                '面向海外与都市场景，给出轻量、可叠穿的日常汉服/新中式搭配。',
                'Daily & Commute',
                'Wearable Chinese-style looks for everyday life.',
                'Lightweight layering ideas for urban and overseas daily wear.',
                'दैनिक व आवागमन', 'Diario y commute', 'اليومي والتنقل', 'Quotidien et trajet', 'দৈনন্দিন ও যাতায়াত', 'Dia a dia e commute', 'Harian & commute', 'روزمرہ اور آمدورفت',
            ),
        ],
        [
            'code' => 'styling-wedding-festival',
            'sort' => 220,
            'i18n' => $row(
                '婚礼与节令',
                '婚服、节庆主题套装穿搭。',
                '覆盖中式婚礼与传统节令场景的搭配与礼仪要点。',
                'Wedding & Festival',
                'Bridal and seasonal festival styling.',
                'Looks and etiquette notes for Chinese weddings and traditional festival outfits.',
                'विवाह व उत्सव', 'Boda y fiestas', 'الزفاف والمهرجانات', 'Mariage et fêtes', 'বিবাহ ও উৎসব', 'Casamento e festas', 'Pernikahan & festival', 'شادی اور تہوار',
            ),
        ],
        [
            'code' => 'styling-beauty-accessories',
            'sort' => 230,
            'i18n' => $row(
                '妆发与配饰',
                '发冠、腰饰、鞋履与妆造。',
                '讲解妆发基础与配饰搭配，连接到头饰、腰饰、鞋履等商品分类。',
                'Beauty & Accessories',
                'Hair, makeup and accessory pairings.',
                'Foundational beauty looks plus accessory pairing that maps to hair, waist and shoe categories.',
                'सौंदर्य व सहायक', 'Belleza y accesorios', 'التجميل والإكسسوارات', 'Beauté et accessoires', 'সৌন্দর্য ও আনুষঙ্গিক', 'Beleza e acessórios', 'Kecantikan & aksesoris', 'حسن اور لوازمات',
            ),
        ],
        [
            'code' => 'styling-size-care',
            'sort' => 240,
            'i18n' => $row(
                '尺码与保养',
                '平铺数据、试穿与洗涤保养。',
                '教读者按平铺尺寸选码，并给出面料保养与收纳建议，降低退货风险。',
                'Size & Care',
                'Flat measurements, fit and garment care.',
                'How to choose size from flat specs and keep fabrics looking new—reducing returns.',
                'साइज़ व देखभाल', 'Talla y cuidado', 'المقاس والعناية', 'Taille et entretien', 'সাইজ ও যত্ন', 'Tamanho e cuidado', 'Ukuran & perawatan', 'سائز اور دیکھ بھال',
            ),
        ],
        [
            'code' => 'buying-guides',
            'sort' => 300,
            'i18n' => $row(
                '购买指南',
                '避雷、选购与工厂直销价值。',
                '面向转化的购买决策内容：新手检查清单、按形制选购，以及源头工厂直销的性价比逻辑。',
                'Buying Guides',
                'Checklists, style picking and factory-direct value.',
                'Conversion-focused guides: beginner checklists, choose-by-style, and why factory-direct pricing wins.',
                'खरीद गाइड', 'Guías de compra', 'أدلة الشراء', 'Guides d’achat', 'কেনার গাইড', 'Guias de compra', 'Panduan belanja', 'خرید گائیڈز',
            ),
        ],
        [
            'code' => 'buying-beginner',
            'sort' => 310,
            'i18n' => $row(
                '新手避雷',
                '平铺图、形制与面料检查清单。',
                '汇总国内同袍社区共识的避雷要点，帮助海外买家一次买对。',
                'Beginner Checklist',
                'Flat-lay, silhouette and fabric checks.',
                'A practical anti-pitfall checklist based on community consensus for first-time buyers.',
                'शुरुआती चेकलिस्ट', 'Lista para principiantes', 'قائمة المبتدئين', 'Checklist débutant', 'নবাগত চেকলিস্ট', 'Checklist iniciante', 'Checklist pemula', 'ابتدائی چیک لسٹ',
            ),
        ],
        [
            'code' => 'buying-choose-by-style',
            'sort' => 320,
            'i18n' => $row(
                '按形制选购',
                '首套马面/襦裙怎么选。',
                '按目标场合与身材建议形制，并内链到对应商品分类。',
                'Choose by Style',
                'How to pick your first mamian or ruqun.',
                'Match occasions and body shape to silhouettes, with internal links to matching catalog categories.',
                'शैली से चुनें', 'Elegir por estilo', 'اختيار حسب النمط', 'Choisir par style', 'স্টাইল অনুযায়ী বেছে নিন', 'Escolher por estilo', 'Pilih menurut gaya', 'اسٹائل سے منتخب کریں',
            ),
        ],
        [
            'code' => 'buying-factory-direct',
            'sort' => 330,
            'i18n' => $row(
                '工厂直销价值',
                '源头工厂 vs 中间商定价。',
                '说明阿玛云源头工厂、质量保障与物美价廉的成本结构，支撑转化信任。',
                'Factory-Direct Value',
                'Source factory vs middleman pricing.',
                'Explain Amayun’s factory-direct model, quality control and value-for-money cost structure.',
                'फ़ैक्टरी डायरेक्ट मूल्य', 'Valor fábrica directa', 'قيمة البيع من المصنع', 'Valeur vente usine', 'ফ্যাক্টরি ডাইরেক্ট মূল্য', 'Valor fábrica direta', 'Nilai pabrik langsung', 'فیکٹری ڈائریکٹ قدر',
            ),
        ],
        [
            'code' => 'buy-compare',
            'sort' => 400,
            'i18n' => $row(
                '渠道与竞品对比',
                '平台、独立站与我们的对比矩阵。',
                '系统对比跨境综合平台与海外汉服垂直独立站，并给出何时选择阿玛云工厂直销的决策框架。',
                'Where to Buy & Compare',
                'Marketplaces, DTC stores and Amayun compared.',
                'Structured comparisons of global marketplaces and Hanfu DTC sites, plus a decision frame for choosing Amayun.',
                'खरीदें व तुलना', 'Dónde comprar y comparar', 'أين تشتري وتقارن', 'Où acheter et comparer', 'কোথায় কিনবেন ও তুলনা', 'Onde comprar e comparar', 'Di mana beli & bandingkan', 'کہاں خریدیں اور موازنہ',
            ),
        ],
        [
            'code' => 'compare-marketplaces',
            'sort' => 410,
            'i18n' => $row(
                '跨境综合平台',
                'Amazon、TikTok Shop、速卖通等十大通道。',
                '逐一对比 Amazon、TikTok Shop、AliExpress、SHEIN、TEMU、YesStyle、Etsy、eBay、Shopee、Lazada 的流量、价格带、正统度与售后。',
                'Marketplaces',
                'Amazon, TikTok Shop, AliExpress and more.',
                'Side-by-side reviews of Amazon, TikTok Shop, AliExpress, SHEIN, TEMU, YesStyle, Etsy, eBay, Shopee and Lazada.',
                'मार्केटप्लेस', 'Marketplaces', 'الأسواق', 'Marketplaces', 'মার্কেটপ্লেস', 'Marketplaces', 'Marketplace', 'مارکیٹ پلیسز',
            ),
        ],
        [
            'code' => 'compare-vertical-stores',
            'sort' => 420,
            'i18n' => $row(
                '海外垂直独立站',
                'NewMoonDance、Nüwa 等前十独立站。',
                '对比海外仍在经营的头部汉服/新中式独立站定位、客群与定价，突出工厂直销差异。',
                'Vertical Stores',
                'Top overseas Hanfu DTC sites compared.',
                'Compare leading live Hanfu/New Chinese Style DTC brands on positioning, audience and price vs factory-direct.',
                'वर्टिकल स्टोर', 'Tiendas verticales', 'متاجر متخصصة', 'Boutiques verticales', 'ভার্টিক্যাল স্টোর', 'Lojas verticais', 'Toko vertikal', 'ورٹیکل اسٹورز',
            ),
        ],
        [
            'code' => 'compare-why-amayun',
            'sort' => 430,
            'i18n' => $row(
                '为什么选我们',
                '阿玛云 vs 平台 / 独立站。',
                '用清单说明阿玛云科技（2024）源头工厂、性价比与质量保障相对平台与独立站的优势。',
                'Why Amayun',
                'Amayun vs platforms and DTC brands.',
                'Checklist of Amayun Technology (est. 2024) advantages: source factory, value and quality vs marketplaces and DTC.',
                'अमायुन क्यों', 'Por qué Amayun', 'لماذا أمايون', 'Pourquoi Amayun', 'কেন Amayun', 'Por que Amayun', 'Mengapa Amayun', 'امایون کیوں',
            ),
        ],
        [
            'code' => 'brand-factory',
            'sort' => 500,
            'i18n' => $row(
                '品牌与工厂',
                '公司故事、产地伙伴与制造方式。',
                '展示阿玛云科技背景、原产地走访与合作工厂、手工结合绿色机械制造的信任证据。',
                'Brand & Factory',
                'Company story, partners and manufacturing.',
                'Trust content on Amayun’s story, origin visits/partners, and handmade + green mechanical production.',
                'ब्रांड व फ़ैक्टरी', 'Marca y fábrica', 'العلامة والمصنع', 'Marque et usine', 'ব্র্যান্ড ও ফ্যাক্টরি', 'Marca e fábrica', 'Brand & pabrik', 'برانڈ اور فیکٹری',
            ),
        ],
        [
            'code' => 'brand-about',
            'sort' => 510,
            'i18n' => $row(
                '公司故事',
                '阿玛云科技 · 2024 · 物美价廉。',
                '介绍阿玛云科技有限公司注册于 2024 年的宗旨：为客户提供物美价廉的汉服与国风产品。',
                'About Amayun',
                'Amayun Technology · 2024 · quality at fair price.',
                'Amayun Technology Co., Ltd. (est. 2024) exists to deliver quality Hanfu and Chinese-style goods at fair prices.',
                'अमायुन के बारे में', 'Sobre Amayun', 'عن أمايون', 'À propos d’Amayun', 'Amayun সম্পর্কে', 'Sobre a Amayun', 'Tentang Amayun', 'امایون کے بارے میں',
            ),
        ],
        [
            'code' => 'brand-partners',
            'sort' => 520,
            'i18n' => $row(
                '产地与合作伙伴',
                '原产地走访与工厂实景。',
                '记录从原产地逐户确认的优质货源、1688 合作厂家与生产车间图片。',
                'Partners & Origins',
                'Origin visits and factory partners.',
                'Documented origin visits, 1688 manufacturing partners and workshop imagery.',
                'पार्टनर व मूल', 'Socios y orígenes', 'الشركاء والمنشأ', 'Partenaires et origines', 'পার্টনার ও উৎস', 'Parceiros e origens', 'Mitra & asal', 'شراکت دار اور ماخذ',
            ),
        ],
        [
            'code' => 'brand-manufacturing',
            'sort' => 530,
            'i18n' => $row(
                '制造方式',
                '手工结合绿色机械制造。',
                '说明关键环节与绿色机械产线如何共同保障版型稳定与交付效率。',
                'Manufacturing',
                'Handmade steps with green mechanical production.',
                'How craft finishing and responsible mechanical lines keep fit consistency and delivery reliability.',
                'विनिर्माण', 'Fabricación', 'التصنيع', 'Fabrication', 'উৎপাদন', 'Fabricação', 'Manufaktur', 'مینوفیکچرنگ',
            ),
        ],
        [
            'code' => 'world-ethnic',
            'sort' => 600,
            'i18n' => $row(
                '全球民族服饰',
                '世界传统服饰精品科普。',
                '以精品长文介绍各国民族服饰，并与汉服文化对照，扩展主题权威。',
                'World Ethnic Dress',
                'Premium guides to traditional dress worldwide.',
                'In-depth features on world ethnic costumes with thoughtful Hanfu comparisons for topical authority.',
                'विश्व लोक पोशाक', 'Trajes étnicos del mundo', 'الأزياء العرقية العالمية', 'Costumes ethniques du monde', 'বিশ্ব জাতিগত পোশাক', 'Trajes étnicos do mundo', 'Busana etnik dunia', 'عالمی نسلی ملبوسات',
            ),
        ],
        [
            'code' => 'ethnic-east-asia',
            'sort' => 610,
            'i18n' => $row(
                '东亚邻邦',
                '和服、韩服、越服等对照。',
                '介绍东亚邻邦传统服饰，并厘清与汉服的文化边界。',
                'East Asia',
                'Kimono, hanbok, áo dài and neighbors.',
                'East Asian traditional dress with clear cultural boundaries versus Hanfu.',
                'पूर्वी एशिया', 'Asia Oriental', 'شرق آسيا', 'Asie de l’Est', 'পূর্ব এশিয়া', 'Ásia Oriental', 'Asia Timur', 'مشرقی ایشیا',
            ),
        ],
        [
            'code' => 'ethnic-south-asia',
            'sort' => 620,
            'i18n' => $row(
                '东南亚与南亚',
                '纱丽、纱笼等传统服饰导览。',
                '覆盖东南亚与南亚代表性民族服饰的形制、场合与审美。',
                'SE & South Asia',
                'Sari, sarong and regional traditions.',
                'Guides to representative dress traditions across Southeast and South Asia.',
                'दक्षिण-पूर्व व दक्षिण एशिया', 'Asia SE y del Sur', 'جنوب شرق وجنوب آسيا', 'Asie du SE et du Sud', 'দক্ষিণ-পূর্ব ও দক্ষিণ এশিয়া', 'Ásia SE e do Sul', 'Asia Tenggara & Selatan', 'جنوب مشرقی اور جنوبی ایشیا',
            ),
        ],
        [
            'code' => 'ethnic-mena-africa',
            'sort' => 630,
            'i18n' => $row(
                '中东与非洲',
                '长袍、蜡染等服饰文化。',
                '介绍中东北非与非洲代表性服饰文化与穿着场景。',
                'MENA & Africa',
                'Robes, wax print and regional dress cultures.',
                'Featured dress cultures across the Middle East, North Africa and Africa.',
                'मेना व अफ्रीका', 'MENA y África', 'الشرق الأوسط وأفريقيا', 'MENA et Afrique', 'মেনা ও আফ্রিকা', 'MENA e África', 'MENA & Afrika', 'مینا اور افریقہ',
            ),
        ],
        [
            'code' => 'ethnic-euro-folk',
            'sort' => 640,
            'i18n' => $row(
                '欧美民俗服饰',
                '欧洲民俗与美洲传统服饰。',
                '梳理欧美民俗服饰线索，丰富全球服饰对照矩阵。',
                'Euro-American Folk',
                'European folk and American traditional dress.',
                'Survey of European folk costume and related American traditional dress threads.',
                'यूरो-अमेरिकी लोक', 'Folclore euroamericano', 'الفلكلور الأوروبي-الأمريكي', 'Folklore euro-américain', 'ইউরো-আমেরিকান লোক', 'Folclore euro-americano', 'Folk Euro-Amerika', 'یورپی-امریکی لوک',
            ),
        ],
    ];
}

function purgeWebsiteZero(): void
{
    $postModel = ObjectManager::getInstance(Post::class);
    $posts = $postModel->clear()->select()->fetchArray();
    $deletedPosts = 0;
    foreach (is_array($posts) ? $posts : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $websiteId = (int)($row[Post::schema_fields_WEBSITE_ID] ?? -1);
        if ($websiteId !== WEBSITE_ID && $websiteId !== 0) {
            continue;
        }
        $id = (int)($row[Post::schema_fields_ID] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $m = ObjectManager::getInstance(Post::class);
        $m->clearData()->reset()->load($id);
        if ($m->getPostId() > 0) {
            $m->delete();
            ++$deletedPosts;
            echo "- post #{$id}\n";
        }
    }

    $admin = ObjectManager::getInstance(BlogCategoryAdminService::class);
    $tree = $admin->tree(WEBSITE_ID, 'zh_Hans_CN');
    $deletedCats = 0;
    foreach ($tree as $node) {
        $id = (int)($node['category_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $admin->delete(WEBSITE_ID, $id);
        ++$deletedCats;
        echo "- category #{$id} " . ($node['code'] ?? '') . "\n";
    }

    echo "purged posts={$deletedPosts} categories={$deletedCats}\n";
}

function seed(): int
{
    $admin = ObjectManager::getInstance(BlogCategoryAdminService::class);
    $attributes = ObjectManager::getInstance(BlogCategoryAttributeService::class);
    $count = 0;

    foreach (r1Categories() as $node) {
        $code = (string)$node['code'];
        $i18n = $node['i18n'];
        $zh = $i18n['zh_Hans_CN'];
        $created = $admin->save(
            WEBSITE_ID,
            0,
            (string)$zh['name'],
            $code,
            'zh_Hans_CN',
            (int)$node['sort'],
            MEDIA_BASE . '/icons/' . $code . '.svg',
            MEDIA_BASE . '/banners/' . $code . '.svg',
            (string)$zh['summary'],
            (string)$zh['description'],
        );
        $categoryId = (int)($created['category_id'] ?? 0);
        if ($categoryId <= 0) {
            throw new RuntimeException('failed create ' . $code);
        }

        foreach (LOCALES as $locale) {
            if ($locale === 'zh_Hans_CN') {
                continue;
            }
            $pack = $i18n[$locale] ?? null;
            if (!is_array($pack)) {
                continue;
            }
            $name = trim((string)($pack['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $attributes->writeName(WEBSITE_ID, $categoryId, $name, $locale);
            $attributes->writeSummary(WEBSITE_ID, $categoryId, (string)($pack['summary'] ?? ''), $locale);
            $attributes->writeDescription(WEBSITE_ID, $categoryId, (string)($pack['description'] ?? ''), $locale);
        }

        echo "+ #{$categoryId} {$code} {$zh['name']}\n";
        ++$count;
    }

    return $count;
}

echo "=== purge ===\n";
purgeWebsiteZero();
echo "=== seed R1 ===\n";
$total = seed();
echo "seeded={$total}\n";
