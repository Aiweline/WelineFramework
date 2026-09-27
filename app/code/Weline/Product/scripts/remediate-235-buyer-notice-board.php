<?php

declare(strict_types=1);

/**
 * §3.2‑A buyer_notice_board：#235 detail-05 购前须知烤字板 → HTML。
 *
 * php app/code/Weline/Product/scripts/remediate-235-buyer-notice-board.php --dry-run
 * php app/code/Weline/Product/scripts/remediate-235-buyer-notice-board.php --apply
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Repository\AttributeValueRepository;
use Weline\Product\Service\DetailDescriptionTextifier;
use Weline\Product\Service\ProductStorefrontCacheInvalidator;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;

require dirname(__DIR__, 5) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'dry-run']);
$apply = isset($options['apply']) && !isset($options['dry-run']);

const PRODUCT_ID = 235;
const NOTICE_ASSET = 'e0dc864d-7cd9-45a0-a004-d7ec92087be6';

/** @var array<string, array{title:string,sections:list<array{title:string,body:string}>,closing:string,highlights:list<string>}> $packs */
$packs = [
    'zh_Hans_CN' => [
        'title' => '购前须知',
        'sections' => [
            [
                'title' => '面料',
                'body' => '汉服用料较大，无法做到完美请知悉。提花/纯色面料偶有小点/勾丝/线头等无法避免。以上问题店铺已提前告知，完美主义慎下单。',
            ],
            [
                'title' => '对花',
                'body' => '提花/纯色面料常规裁剪，不对花。大货每一件面料纹理会有区别，不影响使用。以上已提前告知，对花有要求请谨慎下单。',
            ],
            [
                'title' => '洗涤',
                'body' => '汉服没有常服料耐用，请小心呵护。面料请温柔手洗，勿长时间浸泡和高温暴晒。',
            ],
            [
                'title' => '绣花',
                'body' => '本店绣花均为机器绣花，机器绣花无法做到完美，会有跳针/漏针/线头/针孔等各种无法避免的问题，不影响整体穿着。以上绣花问题非质量问题，已提前告知，完美主义要求较高请勿下单。本店做不到完美，就是一般做工而已，已明确提前告知，辛苦您知悉。',
            ],
            [
                'title' => '静电',
                'body' => '做过抗静电处理，面料有静电非质量差。天气季节、人体皮肤干燥、摩擦力等均会影响静电。夏天穿没静电，冬/春天穿粘在一起、静电很大，属正常现象。适当喷水或抗静电喷雾，洗涤过后再试穿等，均可减少静电。',
            ],
            [
                'title' => '裙子褶子和折痕',
                'body' => '机器压褶无法做到完美，褶子间有约 1cm 误差属正常。人工制作难免轻微误差/线头/小歪斜等常规现象。裙子经包装折叠与快递运输后，收到较皱、折痕多属正常。请熨烫后垂直悬挂，裙子可恢复整洁与垂顺。',
            ],
        ],
        'closing' => '普通小店无法做到完美，各类小问题已提前告知。辛苦能接受常规问题再下单，不能接受请谨慎购买。以上描述非质量问题，本店确实做不到完美，辛苦您看一下。',
        'highlights' => [
            '试穿不喜欢均可退货',
            '辛苦退货不要为了邮费选质量问题，感恩理解',
        ],
    ],
    'en_US' => [
        'title' => 'Before you order',
        'sections' => [
            [
                'title' => 'Fabric',
                'body' => 'Hanfu uses a large amount of fabric and cannot be perfect. Jacquard or solid cloth may show tiny spots, snags, or loose threads—these are disclosed in advance. Perfectionists should order with care.',
            ],
            [
                'title' => 'Pattern match',
                'body' => 'Jacquard/solid pieces are cut normally without pattern matching. Texture varies between garments and does not affect wear. If you require matching, please order cautiously.',
            ],
            [
                'title' => 'Care',
                'body' => 'Hanfu cloth is less durable than everyday wear—handle gently. Hand wash softly; do not soak long or dry in harsh heat.',
            ],
            [
                'title' => 'Embroidery',
                'body' => 'All embroidery is machine-done and may show skipped stitches, missed stitches, loose threads, or needle holes. These are not quality defects and do not affect overall wear. High perfection standards: please do not order.',
            ],
            [
                'title' => 'Static',
                'body' => 'Anti-static treatment is applied; remaining static is not poor quality. Season, dry skin, and friction all affect static—common in winter/spring. Light misting, anti-static spray, or trying on after washing can help.',
            ],
            [
                'title' => 'Pleats & creases',
                'body' => 'Machine pleating may vary by about 1 cm. Handmade pieces can show slight errors or loose threads. Creases after packing and shipping are normal—iron and hang vertically to restore the drape.',
            ],
        ],
        'closing' => 'A small shop cannot be perfect. Please order only if you accept ordinary handmade variance. The notes above are not quality defects.',
        'highlights' => [
            'Try-on returns accepted if you simply dislike the piece',
            'Please do not mark “quality issue” only to avoid return postage—thank you',
        ],
    ],
];

// Locale fallbacks for other enabled languages (true translate, not Chinese leftover).
$packs['fr_FR'] = [
    'title' => 'Avant commande',
    'sections' => [
        ['title' => 'Tissu', 'body' => 'Le hanfu utilise beaucoup de tissu et ne peut être parfait. Jacquard/uni : petits points, accrocs ou fils possibles—annoncé à l’avance. Les perfectionnistes commandent avec prudence.'],
        ['title' => 'Raccord de motif', 'body' => 'Coupe standard sans raccord de motif. Le grain varie d’une pièce à l’autre sans affecter le port.'],
        ['title' => 'Entretien', 'body' => 'Tissu plus délicat que le prêt-à-porter. Lavage à la main doux ; pas de trempage long ni de soleil brûlant.'],
        ['title' => 'Broderie', 'body' => 'Broderie machine : points sautés, fils ou trous d’aiguille possibles—pas un défaut de qualité.'],
        ['title' => 'Électricité statique', 'body' => 'Traitement antistatique appliqué ; le reste dépend du climat et de la peau. Brumisation ou spray antistatique aident.'],
        ['title' => 'Plis et plis de transport', 'body' => 'Écart ~1 cm entre plis possible. Plis après envoi : normal—repasser et suspendre.'],
    ],
    'closing' => 'Petite boutique : imperfections ordinaires déjà annoncées. Commandez seulement si vous les acceptez.',
    'highlights' => [
        'Retour accepté si l’essayage ne plaît pas',
        'Merci de ne pas cocher « défaut qualité » seulement pour l’affranchissement retour',
    ],
];
$packs['es_ES'] = [
    'title' => 'Antes de comprar',
    'sections' => [
        ['title' => 'Tela', 'body' => 'El hanfu usa mucha tela y no puede ser perfecto. Jacquard/liso: puntos, enganches o hilos sueltos son posibles—avisado de antemano.'],
        ['title' => 'Emparejado de motivo', 'body' => 'Corte habitual sin emparejar motivos. La textura varía entre prendas.'],
        ['title' => 'Lavado', 'body' => 'Tela más delicada. Lavar a mano con suavidad; no remojar ni secar al sol fuerte.'],
        ['title' => 'Bordado', 'body' => 'Bordado a máquina: puntadas saltadas o hilos posibles—no son defecto de calidad.'],
        ['title' => 'Electricidad estática', 'body' => 'Tratamiento antiestático aplicado; el clima y la piel influyen. Spray o humedecer ayudan.'],
        ['title' => 'Pliegues y arrugas', 'body' => 'Variación ~1 cm entre pliegues. Arrugas tras el envío: normales—planchar y colgar.'],
    ],
    'closing' => 'Tienda pequeña: irregularidades habituales ya informadas. Compre solo si las acepta.',
    'highlights' => [
        'Devolución si no le gusta tras probarse',
        'No marque «problema de calidad» solo por el porte de devolución—gracias',
    ],
];
$packs['pt_BR'] = [
    'title' => 'Antes de pedir',
    'sections' => [
        ['title' => 'Tecido', 'body' => 'Hanfu usa muito tecido e não pode ser perfeito. Jacquard/liso: pontos, puxões ou linhas soltas podem ocorrer—avisado antes.'],
        ['title' => 'Alinhamento do padrão', 'body' => 'Corte padrão sem alinhar motivos. A textura varia entre peças.'],
        ['title' => 'Lavagem', 'body' => 'Tecido mais delicado. Lavar à mão com cuidado; não deixar de molho nem secar ao sol forte.'],
        ['title' => 'Bordado', 'body' => 'Bordado à máquina: pontos pulados ou linhas possíveis—não é defeito de qualidade.'],
        ['title' => 'Eletricidade estática', 'body' => 'Tratamento antiestático aplicado; clima e pele influenciam. Spray ou umedecer ajudam.'],
        ['title' => 'Pregas e vincos', 'body' => 'Variação ~1 cm entre pregas. Vincos após envio: normais—passar e pendurar.'],
    ],
    'closing' => 'Loja pequena: imperfeições comuns já informadas. Peça só se aceitar.',
    'highlights' => [
        'Devolução se não gostar após experimentar',
        'Não marque «problema de qualidade» só pelo frete de devolução—obrigado',
    ],
];
$packs['id_ID'] = [
    'title' => 'Sebelum memesan',
    'sections' => [
        ['title' => 'Kain', 'body' => 'Hanfu memakai banyak kain dan tidak bisa sempurna. Jacquard/polos: bintik, tarikan, atau benang longgar mungkin terjadi—sudah diinformasikan.'],
        ['title' => 'Penyesuaian motif', 'body' => 'Potongan biasa tanpa menyamakan motif. Tekstur berbeda antar item.'],
        ['title' => 'Pencucian', 'body' => 'Kain lebih lembut. Cuci tangan hati-hati; jangan rendam lama atau jemur panas.'],
        ['title' => 'Sulaman', 'body' => 'Sulaman mesin: jahitan loncat atau benang mungkin—bukan cacat kualitas.'],
        ['title' => 'Listrik statis', 'body' => 'Sudah anti-statis; sisa tergantung cuaca dan kulit. Semprot anti-statis membantu.'],
        ['title' => 'Lipatan & kusut', 'body' => 'Selisih ~1 cm antar lipatan normal. Kusut setelah kirim normal—setrika dan gantung.'],
    ],
    'closing' => 'Toko kecil: kekurangan biasa sudah diinformasikan. Pesan hanya jika menerima.',
    'highlights' => [
        'Boleh retur jika tidak suka setelah dicoba',
        'Jangan pilih «masalah kualitas» hanya demi ongkir retur—terima kasih',
    ],
];
$packs['hi_IN'] = [
    'title' => 'ऑर्डर से पहले',
    'sections' => [
        ['title' => 'कपड़ा', 'body' => 'हानफू में कपड़ा अधिक लगता है; पूर्णता संभव नहीं। जैक्वार्ड/सolid पर छोटे धब्बे/खिंचाव/धागे हो सकते हैं—पहले से सूचित।'],
        ['title' => 'पैटर्न मिलान', 'body' => 'सामान्य कटिंग; पैटर्न मिलाए नहीं जाते। बनावट हर पीस में अलग हो सकती है।'],
        ['title' => 'धलाई', 'body' => 'कपड़ा नाज़ुक है। हल्के हाथ से धोएँ; लंबे भिगोने या तेज़ धूप से बचाएँ।'],
        ['title' => 'कढ़ाई', 'body' => 'मशीन कढ़ाई: छूटे टांके/धागे संभव—गुणवत्ता दोष नहीं।'],
        ['title' => 'स्थैतिक बिजली', 'body' => 'एंटी-स्टैटिक उपचार किया गया; मौसम/त्वचा असर डालते हैं। स्प्रे मदद करता है।'],
        ['title' => 'प्लीट व सिलवटें', 'body' => 'प्लीट में ~1 सेमी अंतर सामान्य। शिपिंग के बाद सिलवटें सामान्य—इस्त्री कर लटकाएँ।'],
    ],
    'closing' => 'छोटी दुकान: सामान्य कमियाँ पहले बता दी गईं। स्वीकार हो तभी ऑर्डर करें।',
    'highlights' => [
        'ट्राय के बाद पसंद न आने पर वापसी संभव',
        'केवल वापसी डाक बचाने के लिए «गुणवत्ता समस्या» न चुनें—धन्यवाद',
    ],
];
$packs['ar_SA'] = [
    'title' => 'قبل الطلب',
    'sections' => [
        ['title' => 'القماش', 'body' => 'الهانفو يستخدم قماشاً كثيراً ولا يكون مثالياً. قد تظهر نقاط أو خيوط على الجاكار/السادة—مُعلن مسبقاً.'],
        ['title' => 'محاذاة النقش', 'body' => 'قص اعتيادي دون محاذاة النقوش. الملمس يختلف بين القطع.'],
        ['title' => 'الغسيل', 'body' => 'القماش أقل تحملاً. اغسل يدوياً بلطف؛ لا تنقع طويلاً ولا تعرض لشمس حارة.'],
        ['title' => 'التطريز', 'body' => 'تطريز آلي: غرز ناقصة أو خيوط ممكنة—ليست عيباً في الجودة.'],
        ['title' => 'الكهرباء الساكنة', 'body' => 'عولج مضاداً للشحن؛ المناخ والبشرة يؤثران. الرذاذ يساعد.'],
        ['title' => 'الطيات والتجاعيد', 'body' => 'فرق نحو 1 سم بين الطيات طبيعي. تجاعيد الشحن طبيعية—اكوِ وعلّق.'],
    ],
    'closing' => 'متجر صغير: عيوب عادية مُعلنة مسبقاً. اطلب فقط إن قبلتها.',
    'highlights' => [
        'يمكن الإرجاع إن لم يعجبك بعد التجربة',
        'لا تختر «مشكلة جودة» فقط لتوفير أجرة الإرجاع—شكراً',
    ],
];
$packs['bn_BD'] = [
    'title' => 'অর্ডারের আগে',
    'sections' => [
        ['title' => 'কাপড়', 'body' => 'হানফুতে কাপড় বেশি লাগে; নিখুঁত নয়। জ্যাকোয়ার্ড/সলিডে দাগ/টান/সুতা থাকতে পারে—আগেই জানানো।'],
        ['title' => 'প্যাটার্ন মিল', 'body' => 'সাধারণ কাটিং; প্যাটার্ন মিলানো হয় না। টেক্সচার পিসভেদে আলাদা।'],
        ['title' => 'ধোয়া', 'body' => 'কাপড় নাজুক। হালকা হাতে ধোবেন; দীর্ঘ ভেজানো বা তীব্র রোদ এড়ান।'],
        ['title' => 'সূচিকর্ম', 'body' => 'মেশিন সূচিকর্ম: ছুটে যাওয়া সেলাই/সুতা সম্ভব—গুণগত ত্রুটি নয়।'],
        ['title' => 'স্থির বিদ্যুৎ', 'body' => 'অ্যান্টি-স্ট্যাটিক করা হয়েছে; আবহাওয়া/ত্বক প্রভাব ফেলে। স্প্রে সাহায্য করে।'],
        ['title' => 'প্লীট ও ভাঁজ', 'body' => 'প্লীটে ~১ সেমি তারতম্য স্বাভাবিক। শিপিংয়ের পর ভাঁজ স্বাভাবিক—ইস্ত্রি করে ঝুলান।'],
    ],
    'closing' => 'ছোট দোকান: সাধারণ ত্রুটি আগেই জানানো। গ্রহণযোগ্য হলেই অর্ডার করুন।',
    'highlights' => [
        'ট্রায়ের পর পছন্দ না হলে ফেরত সম্ভব',
        'শুধু রিটার্ন পোস্ট বাঁচাতে «গুণগত সমস্যা» বেছে নেবেন না—ধন্যবাদ',
    ],
];
$packs['ur_PK'] = [
    'title' => 'آرڈر سے پہلے',
    'sections' => [
        ['title' => 'کپڑا', 'body' => 'ہانفو میں کپڑا زیادہ لگتا ہے؛ کامل نہیں ہو سکتا۔ جیکوارڈ/سادہ پر دھبے/کھنچاؤ/دھ آگے ممکن—پہلے بتایا گیا۔'],
        ['title' => 'نقشہ میل', 'body' => 'عام کٹنگ؛ نقشے نہیں ملاتے۔ بناوٹ ہر پیس میں الگ ہو سکتی ہے۔'],
        ['title' => 'دھلائی', 'body' => 'کپڑا نازک ہے۔ ہلکے ہاتھ دھوئیں؛ دیر تک بھگوئیں یا تیز دھوپ نہ دیں۔'],
        ['title' => 'کڑھائی', 'body' => 'مشین کڑھائی: چھوٹی ٹانکیں/دھ آگے ممکن—معیار کا عیب نہیں۔'],
        ['title' => 'جامد بجلی', 'body' => 'اینٹی سٹیٹک کیا گیا؛ موسم/جلد اثر ڈالتے ہیں۔ سپرے مدد کرتا ہے۔'],
        ['title' => 'پلیٹ اور شکنیں', 'body' => 'پلیٹ میں تقریباً ۱ سینٹی فرق عام۔ شپنگ کے بعد شکنیں عام—استری کر لٹکائیں۔'],
    ],
    'closing' => 'چھوٹی دکان: عام خامیاں پہلے بتا دی گئیں۔ قبول ہو تو ہی آرڈر کریں۔',
    'highlights' => [
        'ٹرائی کے بعد پسند نہ آنے پر واپسی ممکن',
        'صرف واپسی ڈاک بچانے کے لیے «معیار مسئلہ» نہ چنیں—شکریہ',
    ],
];

$env = include dirname(__DIR__, 5) . '/app/etc/env.php';
$db = $env['db']['master'] ?? [];
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        (string)($db['hostname'] ?? '127.0.0.1'),
        (string)($db['hostport'] ?? '3306'),
        (string)($db['database'] ?? ''),
    ),
    (string)($db['username'] ?? ''),
    (string)($db['password'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

/** @var AttributeValueRepository $attributes */
$attributes = ObjectManager::getInstance(AttributeValueRepository::class);

$localePlan = ['' => 'en_US'];
try {
    $codes = \Weline\Website\Model\WebsiteLanguage::getWebsiteLanguageCodes(0);
    if (is_array($codes) && $codes !== []) {
        foreach ($codes as $code) {
            $code = (string)$code;
            if ($code === '') {
                continue;
            }
            $localePlan[$code] = isset($packs[$code]) ? $code : 'en_US';
        }
    } else {
        foreach (array_keys($packs) as $code) {
            $localePlan[$code] = $code;
        }
    }
} catch (Throwable $e) {
    foreach (array_keys($packs) as $code) {
        $localePlan[$code] = $code;
    }
}

echo 'mode=' . ($apply ? 'apply' : 'dry-run') . " product=" . PRODUCT_ID . "\n";

foreach ($localePlan as $locale => $packKey) {
    if (!isset($packs[$packKey])) {
        $packKey = 'en_US';
    }
    $st = $pdo->prepare(
        "SELECT value_id, COALESCE(value_string, value_text) AS html
         FROM w_product_ws_0_attribute_value
         WHERE entity_type='product' AND entity_id=? AND attribute_code='description' AND locale=?
         ORDER BY CHAR_LENGTH(COALESCE(value_string, value_text)) DESC LIMIT 1"
    );
    $st->execute([(string)PRODUCT_ID, $locale]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo '  skip locale=' . ($locale === '' ? '(empty)' : $locale) . " (no row)\n";
        continue;
    }
    $html = (string)$row['html'];
    $valueId = (int)$row['value_id'];
    if (!str_contains($html, NOTICE_ASSET) && !str_contains($html, 'data-weline-detail-text="buyer-notice"')) {
        echo '  skip locale=' . ($locale === '' ? '(empty)' : $locale) . " (no notice asset)\n";
        continue;
    }

    $pack = $packs[$packKey];
    $notice = DetailDescriptionTextifier::buildBuyerNoticePanel(
        $pack['sections'],
        $pack['closing'],
        $pack['highlights'],
        $pack['title'],
    );

    // Keep 诗意旁笺 copy; drop media shell that held the notice JPG.
    $before = $html;
    if (str_contains($html, NOTICE_ASSET)) {
        // Replace poem-aside feature that wraps the notice img with: verse copy (if any) + notice panel.
        $replaced = preg_replace(
            '#<div class="weline-detail-feature weline-detail-feature--poem-aside"[^>]*>\s*'
            . '(<div class="weline-detail-feature__copy"[^>]*>.*?</div>)\s*'
            . '<div class="weline-detail-feature__media"[^>]*>\s*<img[^>]+'
            . preg_quote(NOTICE_ASSET, '#')
            . '[^>]*>\s*</div>\s*</div>#is',
            '$1' . $notice,
            $html,
            1,
            $count
        );
        if (is_string($replaced) && $count > 0) {
            $html = $replaced;
        } else {
            $html = DetailDescriptionTextifier::replaceAssetImageWithHtml($html, NOTICE_ASSET, $notice);
        }
    } else {
        // Idempotent: refresh existing buyer-notice block.
        $html = preg_replace(
            '#<div class="weline-detail-text[^"]*"[^>]*data-weline-detail-text="buyer-notice"[^>]*>.*?</div>#is',
            $notice,
            $html,
            1
        ) ?? $html;
    }

    // Drop empty media shells; strip prior duplicate notice.
    $html = preg_replace('#<div class="weline-detail-feature__media"[^>]*>\s*</div>#is', '', $html) ?? $html;
    if (substr_count($html, 'data-weline-detail-text="buyer-notice"') > 1) {
        $first = true;
        $html = preg_replace_callback(
            '#<div class="weline-detail-text[^"]*"[^>]*data-weline-detail-text="buyer-notice"[^>]*>.*?</div>#is',
            static function (array $m) use (&$first): string {
                if ($first) {
                    $first = false;
                    return $m[0];
                }
                return '';
            },
            $html
        ) ?? $html;
    }

    $still = str_contains($html, NOTICE_ASSET);
    $hasNotice = str_contains($html, 'data-weline-detail-text="buyer-notice"');
    $assets = substr_count($html, 'asset://');
    echo '  locale=' . ($locale === '' ? '(empty)' : $locale)
        . ' pack=' . $packKey
        . ' value_id=' . $valueId
        . ' changed=' . ($html !== $before ? 'yes' : 'no')
        . ' still_board=' . ($still ? 'YES' : 'no')
        . ' notice=' . ($hasNotice ? 'Y' : 'N')
        . ' assets=' . $assets
        . ' len=' . strlen($html) . "\n";
    if ($still || !$hasNotice) {
        fwrite(STDERR, "buyer notice remediation incomplete\n");
        exit(2);
    }
    if (!$apply || $html === $before) {
        continue;
    }
    $attributes->writeExplicit(0, 0, 'product', PRODUCT_ID, 'description', $locale, $html, true);
    try {
        $locCode = $locale === '' ? 'en_US' : $locale;
        $pdo->prepare('UPDATE w_weline_product_local SET description=? WHERE product_id=? AND local_code=?')
            ->execute([$html, PRODUCT_ID, $locCode]);
    } catch (Throwable $e) {
        echo '  local soft-fail: ' . $e->getMessage() . "\n";
    }
}

if ($apply) {
    try {
        ObjectManager::getInstance(ProductStorefrontCacheInvalidator::class)
            ->invalidateForProducts([PRODUCT_ID]);
    } catch (Throwable $e) {
        echo 'cache soft-fail: ' . $e->getMessage() . "\n";
    }
    try {
        ObjectManager::getInstance(StorefrontCatalogCacheCoordinator::class)
            ->clearForCatalogChange('buyer_notice_board_235');
    } catch (Throwable $e) {
        echo 'catalog soft-fail: ' . $e->getMessage() . "\n";
    }
    $fpc = dirname(__DIR__, 5) . '/var/cache/router-fpc-payloads';
    if (is_dir($fpc)) {
        $n = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fpc, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                @unlink($f->getPathname());
                $n++;
            }
        }
        echo "cleared fpc≈{$n}\n";
    }
}

echo $apply ? "DONE apply\n" : "DONE dry-run\n";
