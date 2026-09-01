<?php

declare(strict_types=1);

/**
 * One-shot seed: Hanfu catalog tree（专营汉服站：女装/男装等直接作为顶级，不包一层「汉服」）.
 *
 * Usage: php app/code/Weline/Product/data/seed-hanfu-categories.php
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\ProductCategoryAdminService;
use Weline\Product\Service\ProductCategoryAttributeService;

require dirname(__DIR__, 4) . '/bootstrap.php';

const WEBSITE_ID = 0;
const MEDIA_BASE = '/pub/media/catalog/hanfu/r2/categories';

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
 * Tree nodes: code, translations[locale][name|summary|description], children.
 *
 * @return list<array<string, mixed>>
 */
function hanfuTree(): array
{
    $t = static function (
        string $zhName,
        string $zhSummary,
        string $zhDesc,
        string $enName,
        string $enSummary,
        string $enDesc,
        array $extra = [],
    ): array {
        $base = [
            'zh_Hans_CN' => ['name' => $zhName, 'summary' => $zhSummary, 'description' => $zhDesc],
            'en_US' => ['name' => $enName, 'summary' => $enSummary, 'description' => $enDesc],
        ];

        return $base + $extra;
    };

    // Extra locales translated from Chinese/English meaning (storefront-ready, not machine-garbled placeholders).
    $x = static function (
        string $hi,
        string $es,
        string $ar,
        string $fr,
        string $bn,
        string $pt,
        string $id,
        string $ur,
        string $hiS,
        string $esS,
        string $arS,
        string $frS,
        string $bnS,
        string $ptS,
        string $idS,
        string $urS,
        string $hiD,
        string $esD,
        string $arD,
        string $frD,
        string $bnD,
        string $ptD,
        string $idD,
        string $urD,
    ): array {
        return [
            'hi_IN' => ['name' => $hi, 'summary' => $hiS, 'description' => $hiD],
            'es_ES' => ['name' => $es, 'summary' => $esS, 'description' => $esD],
            'ar_SA' => ['name' => $ar, 'summary' => $arS, 'description' => $arD],
            'fr_FR' => ['name' => $fr, 'summary' => $frS, 'description' => $frD],
            'bn_BD' => ['name' => $bn, 'summary' => $bnS, 'description' => $bnD],
            'pt_BR' => ['name' => $pt, 'summary' => $ptS, 'description' => $ptD],
            'id_ID' => ['name' => $id, 'summary' => $idS, 'description' => $idD],
            'ur_PK' => ['name' => $ur, 'summary' => $urS, 'description' => $urD],
        ];
    };

    // 专营汉服：顶层直接是女装/男装/童装/配饰/套装，首页菜单不会只剩一个「汉服」。
    return [
            [
                'code' => 'women',
                'i18n' => $t(
                    '女装',
                    '襦裙、袄裙、马面与外搭，女款汉服主力。',
                    '女装按经典形制划分：襦裙、袄裙、马面裙、曲裾深衣、褙子比甲与女袍服，适合日常到礼仪多种场合。',
                    'Women',
                    'Ruqun, aoqun, mamian and outer layers for women.',
                    'Women’s Hanfu by classic forms: ruqun, aoqun, mamian skirts, quju/shenyi, beizi/bijia and robes for daily wear to formal occasions.',
                    $x('महिला', 'Mujer', 'نساء', 'Femme', 'নারী', 'Mulheres', 'Wanita', 'خواتین',
                        'महिला हानफू रूप', 'Formas Hanfu para mujer', 'أشكال هانفو للنساء', 'Formes Hanfu femme', 'নারী হানফু আকৃতি', 'Formas Hanfu femininas', 'Bentuk Hanfu wanita', 'خواتین ہانفو شکلیں',
                        'रुचुन, आओचुन, मामियन और बाहरी परतें।', 'Ruqun, aoqun, mamian y capas exteriores.', 'روتشون وأوتشون وماميان وطبقات خارجية.', 'Ruqun, aoqun, mamian et couches extérieures.', 'রুকুন, আওকুন, মামিয়ান ও বাইরের স্তর।', 'Ruqun, aoqun, mamian e camadas externas.', 'Ruqun, aoqun, mamian, dan lapisan luar.', 'رقن، آؤقن، مامیان اور بیرونی تہیں۔'),
                ),
                'children' => [
                    [
                        'code' => 'ruqun',
                        'i18n' => $t('襦裙', '上衣下裳，汉服女装最经典结构。', '襦裙分齐胸、齐腰、交领、对襟等穿法，是汉服女装入门与日常的核心品类。', 'Ruqun', 'Top-and-skirt classic for women.', 'Ruqun covers qixiong, qiyao, cross-collar and open-front styles—the core everyday women’s form.', $x('रुक़ुन', 'Ruqun', 'روتشون', 'Ruqun', 'রুকুন', 'Ruqun', 'Ruqun', 'رقن', 'क्लासिक स्कर्ट सेट', 'Conjunto clásico falda', 'طقم تنورة كلاسيكي', 'Ensemble jupe classique', 'ক্লাসিক স্কার্ট সেট', 'Conjunto clássico saia', 'Set rok klasik', 'کلاسک اسکرٹ سیٹ', 'चीश्योंग, चीयाओ, क्रॉस-कॉलर व ओपन-फ्रंट।', 'Qixiong, qiyao, cuello cruzado y delantero abierto.', 'تشيشيونغ وتشيياو وطوق متقاطع ومفتوح.', 'Qixiong, qiyao, col croisé et devant ouvert.', 'চিশিয়ং, চিয়াও, ক্রস-কলার ও ওপেন-ফ্রন্ট।', 'Qixiong, qiyao, gola cruzada e frente aberta.', 'Qixiong, qiyao, kerah silang, dan depan terbuka.', 'چیشیونگ، چیاؤ، کراس کالر اور اوپن فرنٹ۔')),
                        'children' => [
                            node('qixiong', '齐胸襦裙', '高腰齐胸，唐风华美日常。', '裙腰提到胸口线，线条修长，适合写真、出游与唐风穿搭。', 'Qixiong Ruqun', 'High-waist Tang-style look.', 'Skirt tied at chest level for a tall silhouette—great for Tang-inspired daily wear and photos.', 'चीश्योंग रुक़ुन', 'Ruqun Qixiong', 'روتشون تشيشيونغ', 'Ruqun Qixiong', 'চিশিয়ং রুকুন', 'Ruqun Qixiong', 'Ruqun Qixiong', 'چیشیونگ رقن'),
                            node('qiyao', '齐腰襦裙', '裙腰在腰际，灵动好活动。', '齐腰更利行走与通勤，是日常襦裙的实用选择。', 'Qiyao Ruqun', 'Waist-level everyday ruqun.', 'Waist-tied ruqun for easy movement and daily commuting.', 'चीयाओ रुक़ुन', 'Ruqun Qiyao', 'روتشون تشيياو', 'Ruqun Qiyao', 'চিয়াও রুকুন', 'Ruqun Qiyao', 'Ruqun Qiyao', 'چیاؤ رقن'),
                            node('jiaoling', '交领襦裙', '交领右衽，形制感强。', '大襟交叠呈 Y 形领口，强调华夏右衽传统，适合偏正统审美。', 'Cross-collar Ruqun', 'Classic overlapping collar.', 'Y-shaped right-over-left collar that highlights traditional Han structure.', 'क्रॉस-कॉलर रुक़ुन', 'Ruqun cuello cruzado', 'روتشون طوق متقاطع', 'Ruqun col croisé', 'ক্রস-কলার রুকুন', 'Ruqun gola cruzada', 'Ruqun kerah silang', 'کراس کالر رقن'),
                            node('duijin', '对襟襦裙', '对襟开合，清爽利落。', '前襟相对、左右对称，穿脱方便，适合轻便日常与叠穿。', 'Open-front Ruqun', 'Symmetrical open front.', 'Mirrored front opening for easy wear and layering.', 'ओपन-फ्रंट रुक़ुन', 'Ruqun delantero abierto', 'روتشون أمام مفتوح', 'Ruqun devant ouvert', 'ওপেন-ফ্রন্ট রুকুন', 'Ruqun frente aberta', 'Ruqun depan terbuka', 'اوپن فرنٹ رقن'),
                        ],
                    ],
                    node('aoqun', '袄裙', '短袄配裙，明制日常主力。', '上衣偏短便于活动，下配褶裙或马面，是明制女装高频款式。', 'Aoqun', 'Short jacket with skirt—Ming daily staple.', 'Shorter jacket over a skirt or mamian: the go-to Ming-inspired women’s daily set.', 'आओचुन', 'Aoqun', 'أوتشون', 'Aoqun', 'আওকুন', 'Aoqun', 'Aoqun', 'آؤقن'),
                    node('mamian', '马面裙', '前后光面、两侧打褶，造型辨识度高。', '可单裙搭配现代化上衣，也可成套明制穿搭，是近年汉服与新中式爆款。', 'Mamian Skirt', 'Flat panels with side pleats—highly recognizable.', 'Wear alone with modern tops or as a full Ming set; a bestseller in Hanfu and neo-Chinese fashion.', 'मामियन स्कर्ट', 'Falda mamian', 'تنورة ماميان', 'Jupe mamian', 'মামিয়ান স্কার্ট', 'Saia mamian', 'Rok mamian', 'مامیان اسکرٹ'),
                    node('quju', '曲裾 / 深衣', '绕襟深衣，秦汉风礼服感。', '曲裾深衣线条缠绕流畅，适合仪式、展演与强调古风形制的场合。', 'Quju / Shenyi', 'Wrapped deep-robe Qin-Han aura.', 'Flowing wrapped robes for ceremony, performance and classic period looks.', 'क़ुजू / शेनयी', 'Quju / Shenyi', 'تشوجو / شينيي', 'Quju / Shenyi', 'কুজু / শেনই', 'Quju / Shenyi', 'Quju / Shenyi', 'کوجو / شینیی'),
                    node('beizi', '褙子 / 比甲 / 半臂', '外搭层，轻松完成层次。', '褙子、比甲、半臂用于罩在襦裙或袄裙外，调节季节与仪式感。', 'Beizi / Bijia / Banbi', 'Outer layers for depth.', 'Beizi, bijia and banbi worn over ruqun or aoqun to add seasons and formality.', 'बेइज़ी / बिजिया / बानबी', 'Beizi / Bijia / Banbi', 'بيزي / بيجيا / بانبي', 'Beizi / Bijia / Banbi', 'বেইজি / বিজিয়া / বানবি', 'Beizi / Bijia / Banbi', 'Beizi / Bijia / Banbi', 'بیزی / بیجیا / بانبی'),
                    node('women-robe', '女袍服', '圆领等女款袍服。', '女款圆领袍与袍服形制，端庄大气，适合礼仪与正式场合。', 'Women Robes', 'Round-collar and other women’s robes.', 'Women’s round-collar robes for formal and ceremonial looks.', 'महिला गाउन', 'Túnicas mujer', 'عباءات نسائية', 'Robes femme', 'নারী পোশাক', 'Túnicas femininas', 'Jubah wanita', 'خواتین عبائیں'),
                ],
            ],
            [
                'code' => 'men',
                'i18n' => $t(
                    '男装',
                    '圆领袍、直裰道袍与襕衫等男款形制。',
                    '男装以袍服与褙子为主：圆领袍、直裰道袍、襕衫、男褙子短褐，覆盖士人常服到正式场合。',
                    'Men',
                    'Round-collar robes, zhishen/daopao and lanshan.',
                    'Men’s Hanfu centered on robes and outerwear: yuanlingpao, zhishen/daopao, lanshan and beizi for scholarly daily to formal wear.',
                    $x('पुरुष', 'Hombre', 'رجال', 'Homme', 'পুরুষ', 'Homens', 'Pria', 'مرد',
                        'पुरुष हानफू रूप', 'Formas Hanfu para hombre', 'أشكال هانفو للرجال', 'Formes Hanfu homme', 'পুরুষ হানফু আকৃতি', 'Formas Hanfu masculinas', 'Bentuk Hanfu pria', 'مردوں کی ہانفو شکلیں',
                        'युआन लिंग पाओ, झीशेन/दाओपाओ, लानशान।', 'Yuanlingpao, zhishen/daopao y lanshan.', 'يوانلينغباو وتشيشن/داوباو ولانشان.', 'Yuanlingpao, zhishen/daopao et lanshan.', 'ইউয়ানলিংপাও, ঝিশেন/দাওপাও ও লানশান।', 'Yuanlingpao, zhishen/daopao e lanshan.', 'Yuanlingpao, zhishen/daopao, dan lanshan.', 'یوان لنگ پاؤ، ژیشن/داؤپاؤ اور لانشان۔'),
                ),
                'children' => [
                    node('yuanling', '圆领袍', '盘领圆领，男装经典。', '圆领袍线条简洁端正，常用于常服、礼仪与情侣/团队统一造型。', 'Round-collar Robe', 'Classic men’s round collar.', 'Clean silhouette for daily, formal and coordinated couple/group looks.', 'गोल कॉलर गाउन', 'Túnica cuello redondo', 'عباءة طوق دائري', 'Robe col rond', 'গোল কলার পোশাক', 'Túnica gola redonda', 'Jubah kerah bulat', 'گول کالر عباءہ'),
                    node('zhishen', '直裰 / 道袍', '直身交领，文人气与仪式感。', '直裰、道袍适合士人风常服与雅集，也是男装形制的重要代表。', 'Zhishen / Daopao', 'Straight scholar robes.', 'Zhishen and daopao for scholarly daily wear and gatherings.', 'झीशेन / दाओपाओ', 'Zhishen / Daopao', 'تشيشن / داوباو', 'Zhishen / Daopao', 'ঝিশেন / দাওপাও', 'Zhishen / Daopao', 'Zhishen / Daopao', 'ژیشن / داؤپاؤ'),
                    node('lanshan', '襕衫', '下摆横襕，士人常服感。', '襕衫以膝下横襕为识记点，适合学院风与正式常服场合。', 'Lanshan', 'Robe with lower horizontal band.', 'Recognizable knee-band robe for scholarly and formal daily wear.', 'लानशान', 'Lanshan', 'لانشان', 'Lanshan', 'লানশান', 'Lanshan', 'Lanshan', 'لانشان'),
                    node('men-beizi', '男褙子 / 短褐', '外搭与劳动便装。', '男褙子用于罩袍外增加层次；短褐偏便服，适合活动与日常。', 'Men Beizi / Duanhe', 'Outer beizi and workaday short coats.', 'Beizi layers over robes; duanhe for active everyday wear.', 'पुरुष बेइज़ी / दुआनहे', 'Beizi / Duanhe hombre', 'بيزي / دوانه للرجال', 'Beizi / Duanhe homme', 'পুরুষ বেইজি / দুয়ানহে', 'Beizi / Duanhe masculino', 'Beizi / Duanhe pria', 'مرد بیزی / دوانہے'),
                ],
            ],
            [
                'code' => 'kids',
                'i18n' => $t(
                    '童装',
                    '儿童可穿的缩小形制与亲子款。',
                    '童装按女童、男童划分，选择更利活动、穿脱方便的汉服形制。',
                    'Kids',
                    'Child-sized forms and family matching looks.',
                    'Kids Hanfu split by girls and boys with easy-to-wear, movement-friendly cuts.',
                    $x(
                        'बच्चे', 'Niños', 'أطفال', 'Enfants', 'শিশু', 'Crianças', 'Anak', 'بچے',
                        'बच्चों के हानफू', 'Hanfu infantil', 'هانفو للأطفال', 'Hanfu enfants', 'শিশু হানফু', 'Hanfu infantil', 'Hanfu anak', 'بچوں کا ہانفو',
                        'लड़कियाँ व लड़के—आसान पहनावा।', 'Niñas y niños de fácil uso.', 'بنات وأولاد بقصّات مريحة.', 'Filles et garçons faciles à porter.', 'মেয়ে ও ছেলে—সহজে পরার মতো।', 'Meninas e meninos fáceis de vestir.', 'Perempuan dan laki-laki mudah dipakai.', 'لڑکیاں اور لڑکے—آسان پہننے کے لیے۔',
                    ),
                ),
                'children' => [
                    node('girls', '女童', '女童襦裙与袄裙等。', '女童款强调柔美与活动空间，常见襦裙、小袄裙与亲子同款。', 'Girls', 'Girls’ ruqun and aoqun.', 'Soft silhouettes with room to move—ruqun, mini aoqun and matching family sets.', 'लड़कियाँ', 'Niñas', 'بنات', 'Filles', 'মেয়ে', 'Meninas', 'Anak perempuan', 'لڑکیاں'),
                    node('boys', '男童', '男童袍服与短褐等。', '男童款以圆领、短褐等为主，兼顾正式感与玩耍便利。', 'Boys', 'Boys’ robes and short coats.', 'Round-collar and short coats balancing formality and play.', 'लड़के', 'Niños', 'أولاد', 'Garçons', 'ছেলে', 'Meninos', 'Anak laki-laki', 'لڑکے'),
                ],
            ],
            [
                'code' => 'accessories',
                'i18n' => $t(
                    '配饰',
                    '头面、鞋履、腰佩与披帛斗篷。',
                    '配饰完善整套汉服气质：头饰发冠、鞋履、腰饰佩饰、巾帽披帛。',
                    'Accessories',
                    'Hairpieces, shoes, belts and wraps.',
                    'Finish the look with hair crowns, footwear, belts/pendants, and wraps or capes.',
                    $x(
                        'सहायक वस्तुएँ', 'Accesorios', 'إكسسوارات', 'Accessoires', 'আনুষঙ্গিক', 'Acessórios', 'Aksesoris', 'لوازمات',
                        'हानफू सहायक', 'Complementos Hanfu', 'إكسسوارات هانفو', 'Accessoires Hanfu', 'হানফু আনুষঙ্গিক', 'Acessórios Hanfu', 'Aksesoris Hanfu', 'ہانفو لوازمات',
                        'बाल, जूते, कमरबंद और शॉल।', 'Pelo, calzado, cinturones y capas.', 'شعر وحذاء وأحزمة وأوشحة.', 'Cheveux, chaussures, ceintures et capes.', 'চুল, জুতা, বেল্ট ও ওড়না।', 'Cabelo, calçados, cintos e capas.', 'Rambut, sepatu, ikat pinggang, dan selendang.', 'بال، جوتے، بیلٹ اور اوڑھنی۔',
                    ),
                ),
                'children' => [
                    node('hair', '头饰发冠', '簪、冠、发带、抹额。', '头面决定成片气质，覆盖发簪、发冠、发带与抹额等。', 'Hair & Crowns', 'Pins, crowns, ribbons and forehead bands.', 'Hairpins, guan crowns, ribbons and mofe to finish the look.', 'केश आभूषण', 'Adornos cabello', 'زينة الشعر', 'Coiffes', 'চুলের অলংকার', 'Adornos de cabelo', 'Hiasan rambut', 'بال زیورات'),
                    node('shoes', '鞋履', '弓鞋、布靴与绣花鞋等。', '搭配形制选择鞋履，日常布鞋到礼仪绣花鞋均有覆盖。', 'Footwear', 'Bow shoes, cloth boots and embroidered shoes.', 'From daily cloth shoes to ceremonial embroidered pairs.', 'जूते', 'Calzado', 'أحذية', 'Chaussures', 'জুতা', 'Calçados', 'Sepatu', 'جوتے'),
                    node('waist', '腰饰佩饰', '腰带、玉佩、香囊。', '腰间配饰完成细节：革带、丝绦、玉佩、香囊等。', 'Belts & Pendants', 'Sashes, jade pendants and pouches.', 'Belts, silk cords, jade pendants and scent pouches.', 'कमर व पेन्डेंट', 'Cinturones y colgantes', 'أحزمة ومعلقات', 'Ceintures et pendentifs', 'বেল্ট ও পেনড্যান্ট', 'Cintos e pingentes', 'Ikat pinggang & liontin', 'بیلٹ اور لاکٹ'),
                    node('wrap', '巾帽披帛', '披帛、斗篷、扇袋等。', '披帛、斗篷、巾帽与扇袋用于季节保暖与仪式点缀。', 'Wraps & Capes', 'Pibo, cloaks, caps and fan bags.', 'Scarves, cloaks, caps and fan bags for season and ceremony.', 'शॉल व टोपी', 'Mantos y capas', 'أوشحة ومعاطف', 'Écharpes et capes', 'ওড়না ও কেপ', 'Xales e capas', 'Selendang & jubah', 'اوڑھنی اور کیپ'),
                ],
            ],
            [
                'code' => 'sets',
                'i18n' => $t(
                    '套装专区',
                    '一次买齐上下与外搭。',
                    '按场景打包的整套汉服：日常常服、婚礼婚服、节令主题，减少单品搭配成本。',
                    'Complete Sets',
                    'Full looks in one set.',
                    'Scene-ready Hanfu bundles—daily, wedding and festival—so you buy a complete outfit at once.',
                    $x(
                        'सेट', 'Conjuntos', 'مجموعات', 'Ensembles', 'সেট', 'Conjuntos', 'Set lengkap', 'سیٹ',
                        'पूर्ण हानफू सेट', 'Conjuntos Hanfu', 'مجموعات هانفو', 'Ensembles Hanfu', 'সম্পূর্ণ হানফু সেট', 'Conjuntos Hanfu', 'Set Hanfu lengkap', 'مکمل ہانفو سیٹ',
                        'दैनिक, विवाह व उत्सव।', 'Diario, boda y fiesta.', 'يومي وزفاف ومناسبات.', 'Quotidien, mariage et fête.', 'দৈনন্দিন, বিয়ে ও উৎসব।', 'Diário, casamento e festa.', 'Harian, pernikahan, dan festival.', 'روزمرہ، شادی اور تہوار۔',
                    ),
                ),
                'children' => [
                    node('daily', '日常常服套装', '通勤出游一次齐。', '以舒适面料与利落形制为主，适合学习、工作与周末出游。', 'Daily Sets', 'Ready for commute and outings.', 'Comfort-first fabrics and clean forms for study, work and weekends.', 'दैनिक सेट', 'Conjuntos diarios', 'مجموعات يومية', 'Ensembles quotidiens', 'দৈনন্দিন সেট', 'Conjuntos diários', 'Set harian', 'روزمرہ سیٹ'),
                    node('wedding', '婚礼嫁衣 / 婚服', '大喜成礼的正装套系。', '婚礼与订婚场景的成套婚服，强调仪式色彩与完整配件建议。', 'Wedding Sets', 'Ceremonial marriage looks.', 'Complete bridal and groom sets with ceremonial colors and accessory guidance.', 'विवाह सेट', 'Conjuntos de boda', 'مجموعات زفاف', 'Ensembles de mariage', 'বিয়ের সেট', 'Conjuntos de casamento', 'Set pernikahan', 'شادی سیٹ'),
                    node('festival', '节令主题套装', '花朝、中秋等到季节令。', '围绕传统节令与主题联名的限定套装，适合打卡与活动出片。', 'Festival Sets', 'Seasonal and festival themes.', 'Limited sets for traditional festivals and themed events.', 'उत्सव सेट', 'Conjuntos festivos', 'مجموعات احتفالية', 'Ensembles de fête', 'উৎসব সেট', 'Conjuntos festivos', 'Set festival', 'تہوار سیٹ'),
                ],
            ],
    ];
}

/**
 * Leaf/node helper with Top10 locale pack (zh/en + 8 others sharing concise meaning).
 *
 * @return array<string, mixed>
 */
function node(
    string $code,
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
    return [
        'code' => $code,
        'i18n' => [
            'zh_Hans_CN' => ['name' => $zhName, 'summary' => $zhSummary, 'description' => $zhDesc],
            'en_US' => ['name' => $enName, 'summary' => $enSummary, 'description' => $enDesc],
            'hi_IN' => ['name' => $hi, 'summary' => $enSummary, 'description' => $enDesc],
            'es_ES' => ['name' => $es, 'summary' => $enSummary, 'description' => $enDesc],
            'ar_SA' => ['name' => $ar, 'summary' => $enSummary, 'description' => $enDesc],
            'fr_FR' => ['name' => $fr, 'summary' => $enSummary, 'description' => $enDesc],
            'bn_BD' => ['name' => $bn, 'summary' => $enSummary, 'description' => $enDesc],
            'pt_BR' => ['name' => $pt, 'summary' => $enSummary, 'description' => $enDesc],
            'id_ID' => ['name' => $id, 'summary' => $enSummary, 'description' => $enDesc],
            'ur_PK' => ['name' => $ur, 'summary' => $enSummary, 'description' => $enDesc],
        ],
        'children' => [],
    ];
}

/**
 * @param list<array<string, mixed>> $nodes
 */
function seedNodes(
    ProductCategoryAdminService $admin,
    ProductCategoryAttributeService $attributes,
    array $nodes,
    int $parentId,
): int {
    $count = 0;
    foreach ($nodes as $node) {
        $code = (string)$node['code'];
        $i18n = is_array($node['i18n'] ?? null) ? $node['i18n'] : [];
        $zh = $i18n['zh_Hans_CN'] ?? null;
        if (!is_array($zh) || trim((string)($zh['name'] ?? '')) === '') {
            throw new RuntimeException('missing zh name for ' . $code);
        }

        $created = $admin->save(
            WEBSITE_ID,
            0,
            $parentId,
            (string)$zh['name'],
            'active',
            $code,
            'zh_Hans_CN',
            null,
            MEDIA_BASE . '/icons/' . $code . '.webp',
            MEDIA_BASE . '/banners/' . $code . '.webp',
            (string)($zh['summary'] ?? ''),
            (string)($zh['description'] ?? ''),
        );
        $categoryId = (int)($created['category_id'] ?? 0);
        if ($categoryId <= 0) {
            throw new RuntimeException('failed create ' . $code);
        }

        foreach (LOCALES as $locale) {
            if ($locale === 'zh_Hans_CN') {
                continue;
            }
            $row = $i18n[$locale] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $attributes->writeName(WEBSITE_ID, $categoryId, $name, $locale);
            $attributes->writeSummary(WEBSITE_ID, $categoryId, (string)($row['summary'] ?? ''), $locale);
            $attributes->writeDescription(WEBSITE_ID, $categoryId, (string)($row['description'] ?? ''), $locale);
        }

        echo str_repeat('  ', substr_count($code, '-')) . "+ #{$categoryId} {$code} " . $zh['name'] . PHP_EOL;
        ++$count;
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($children !== []) {
            $count += seedNodes($admin, $attributes, $children, $categoryId);
        }
    }

    return $count;
}

$admin = ObjectManager::getInstance(ProductCategoryAdminService::class);
$attributes = ObjectManager::getInstance(ProductCategoryAttributeService::class);

$existing = $admin->tree(WEBSITE_ID, 'zh_Hans_CN');
if ($existing !== []) {
    fwrite(STDERR, "Refuse to seed: website 0 already has categories. Clear them first.\n");
    exit(1);
}

$total = seedNodes($admin, $attributes, hanfuTree(), 0);
echo "seeded={$total}\n";
