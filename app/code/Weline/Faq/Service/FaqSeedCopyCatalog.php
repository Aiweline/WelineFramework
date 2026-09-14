<?php

declare(strict_types=1);

namespace Weline\Faq\Service;

/**
 * Explicit FAQ seed copy for template packs + site hub.
 * Source of truth for DB seeds; do not rely on CLI __() locale.
 *
 * @phpstan-type FaqRow array{faq_key:string,question:string,answer:string}
 * @phpstan-type HubRow array{q:string,a:string}
 */
final class FaqSeedCopyCatalog
{
    /**
     * Locales with maintained seed copy (must cover default-website languages).
     *
     * @return list<string>
     */
    public static function maintainedLocales(): array
    {
        return [
            FaqTemplateSeedService::LOCALE_ZH,
            FaqTemplateSeedService::LOCALE_EN,
            'ar_SA',
            'bn_BD',
            'es_ES',
            'fr_FR',
            'hi_IN',
            'id_ID',
            'pt_BR',
            'ur_PK',
        ];
    }

    /**
     * @return array<string, list<FaqRow>> locale => rows
     */
    public static function templatePack(string $pack): array
    {
        $pack = FaqTemplatePacks::normalize($pack);
        $all = self::templateDefinitions();

        return $all[$pack] ?? [];
    }

    /**
     * @return list<HubRow>
     */
    public static function hubForLocale(string $localeCode): array
    {
        $localeCode = trim($localeCode);
        $all = self::hubDefinitions();
        if (isset($all[$localeCode])) {
            return $all[$localeCode];
        }
        if ($localeCode === '' || str_starts_with(strtolower($localeCode), 'zh')) {
            return $all[FaqTemplateSeedService::LOCALE_ZH] ?? [];
        }
        if (str_starts_with(strtolower($localeCode), 'en')) {
            return $all[FaqTemplateSeedService::LOCALE_EN] ?? [];
        }

        return $all[FaqTemplateSeedService::LOCALE_EN] ?? [];
    }

    /**
     * @return array<string, array<string, list<FaqRow>>>
     */
    private static function templateDefinitions(): array
    {
        return [
            FaqTemplatePacks::RETAIL => [
                FaqTemplateSeedService::LOCALE_ZH => [
                    ['faq_key' => 'shipping', 'question' => '配送多久能到？', 'answer' => '国内订单一般 2–5 个工作日送达；偏远地区可能稍长。发货后可在订单页查看物流。'],
                    ['faq_key' => 'returns', 'question' => '如何退换货？', 'answer' => '签收后 7 日内可申请退换（不影响二次销售）。在订单详情提交申请，按指引寄回即可。'],
                    ['faq_key' => 'warranty', 'question' => '质保如何计算？', 'answer' => '自签收日起享质保（具体期限以商品页为准）。人为损坏、未按说明使用不在质保范围。'],
                    ['faq_key' => 'payment', 'question' => '支持哪些支付方式？', 'answer' => '支持主流银行卡、第三方支付与站内可用的钱包方式；结账页以当前可用渠道为准。'],
                ],
                FaqTemplateSeedService::LOCALE_EN => [
                    ['faq_key' => 'shipping', 'question' => 'How long does delivery take?', 'answer' => 'Domestic orders usually arrive in 2–5 business days; remote areas may take longer. Track shipping on the order page after dispatch.'],
                    ['faq_key' => 'returns', 'question' => 'How do I return or exchange an item?', 'answer' => 'You can request a return or exchange within 7 days of delivery if the item is resalable. Submit the request from the order details and follow the return instructions.'],
                    ['faq_key' => 'warranty', 'question' => 'How is the warranty calculated?', 'answer' => 'Warranty starts on the delivery date (see the product page for the exact term). Damage from misuse or failure to follow instructions is not covered.'],
                    ['faq_key' => 'payment', 'question' => 'Which payment methods are supported?', 'answer' => 'We support major cards, third-party payments, and any wallets enabled on this storefront. Available methods are shown at checkout.'],
                ],
                'ar_SA' => [
                    ['faq_key' => 'shipping', 'question' => 'كم يستغرق التوصيل؟', 'answer' => 'عادةً تصل الطلبات المحلية خلال 2–5 أيام عمل؛ وقد تستغرق المناطق النائية وقتًا أطول. يمكنك تتبع الشحنة من صفحة الطلب بعد الإرسال.'],
                    ['faq_key' => 'returns', 'question' => 'كيف أُرجع أو أستبدل منتجًا؟', 'answer' => 'يمكنك طلب الإرجاع أو الاستبدال خلال 7 أيام من الاستلام إذا كان المنتج قابلًا لإعادة البيع. قدّم الطلب من تفاصيل الطلب واتبع تعليمات الإرجاع.'],
                    ['faq_key' => 'warranty', 'question' => 'كيف تُحسب الضمان؟', 'answer' => 'يبدأ الضمان من تاريخ التسليم (راجع صفحة المنتج للمدة الدقيقة). الأضرار الناتجة عن سوء الاستخدام أو عدم اتباع التعليمات غير مشمولة.'],
                    ['faq_key' => 'payment', 'question' => 'ما طرق الدفع المدعومة؟', 'answer' => 'ندعم البطاقات الرئيسية والمدفوعات الخارجية والمحافظ المفعّلة في المتجر. تظهر الطرق المتاحة عند الدفع.'],
                ],
                'bn_BD' => [
                    ['faq_key' => 'shipping', 'question' => 'ডেলিভারি কতদিন লাগে?', 'answer' => 'দেশীয় অর্ডার সাধারণত ২–৫ কর্মদিবসে পৌঁছায়; দূরবর্তী এলাকায় আরও সময় লাগতে পারে। পাঠানোর পর অর্ডার পেজে শিপিং ট্র্যাক করুন।'],
                    ['faq_key' => 'returns', 'question' => 'কীভাবে ফেরত বা এক্সচেঞ্জ করব?', 'answer' => 'পণ্য পুনরায় বিক্রয়যোগ্য থাকলে ডেলিভারির ৭ দিনের মধ্যে ফেরত বা এক্সচেঞ্জ অনুরোধ করতে পারেন। অর্ডার বিবরণ থেকে আবেদন জমা দিন এবং নির্দেশনা অনুসরণ করুন।'],
                    ['faq_key' => 'warranty', 'question' => 'ওয়ারেন্টি কীভাবে গণনা হয়?', 'answer' => 'ওয়ারেন্টি ডেলিভারি তারিখ থেকে শুরু (সঠিক মেয়াদ পণ্য পেজে দেখুন)। অপব্যবহার বা নির্দেশনা না মানলে ক্ষতি কভার হয় না।'],
                    ['faq_key' => 'payment', 'question' => 'কোন কোন পেমেন্ট পদ্ধতি সমর্থিত?', 'answer' => 'প্রধান কার্ড, তৃতীয় পক্ষের পেমেন্ট এবং স্টোরফ্রন্টে সক্রিয় ওয়ালেট সমর্থিত। চেকআউটে উপলব্ধ পদ্ধতি দেখা যায়।'],
                ],
                'es_ES' => [
                    ['faq_key' => 'shipping', 'question' => '¿Cuánto tarda la entrega?', 'answer' => 'Los pedidos nacionales suelen llegar en 2–5 días laborables; las zonas remotas pueden tardar más. Puedes seguir el envío en la página del pedido tras el despacho.'],
                    ['faq_key' => 'returns', 'question' => '¿Cómo devuelvo o cambio un artículo?', 'answer' => 'Puedes solicitar devolución o cambio en un plazo de 7 días tras la entrega si el artículo es revendible. Envía la solicitud desde los detalles del pedido y sigue las instrucciones.'],
                    ['faq_key' => 'warranty', 'question' => '¿Cómo se calcula la garantía?', 'answer' => 'La garantía comienza en la fecha de entrega (consulta el plazo exacto en la ficha del producto). No cubre daños por mal uso o por no seguir las instrucciones.'],
                    ['faq_key' => 'payment', 'question' => '¿Qué métodos de pago se admiten?', 'answer' => 'Admitimos tarjetas principales, pagos de terceros y monederos activados en esta tienda. Los métodos disponibles se muestran al pagar.'],
                ],
                'fr_FR' => [
                    ['faq_key' => 'shipping', 'question' => 'Combien de temps faut-il pour la livraison ?', 'answer' => 'Les commandes nationales arrivent généralement en 2 à 5 jours ouvrés ; les zones éloignées peuvent prendre plus de temps. Suivez l’expédition sur la page de commande après l’envoi.'],
                    ['faq_key' => 'returns', 'question' => 'Comment retourner ou échanger un article ?', 'answer' => 'Vous pouvez demander un retour ou un échange sous 7 jours après livraison si l’article est revendable. Soumettez la demande depuis les détails de commande et suivez les instructions.'],
                    ['faq_key' => 'warranty', 'question' => 'Comment est calculée la garantie ?', 'answer' => 'La garantie commence à la date de livraison (voir la durée exacte sur la page produit). Les dommages dus à une mauvaise utilisation ou au non-respect des instructions ne sont pas couverts.'],
                    ['faq_key' => 'payment', 'question' => 'Quels moyens de paiement sont acceptés ?', 'answer' => 'Nous acceptons les principales cartes, les paiements tiers et les portefeuilles activés sur cette boutique. Les moyens disponibles s’affichent au paiement.'],
                ],
                'hi_IN' => [
                    ['faq_key' => 'shipping', 'question' => 'डिलीवरी में कितना समय लगता है?', 'answer' => 'घरेलू ऑर्डर आमतौर पर 2–5 कार्यदिवस में पहुँच जाते हैं; दूरदराज़ क्षेत्रों में अधिक समय लग सकता है। भेजने के बाद ऑर्डर पेज पर शिपिंग ट्रैक करें।'],
                    ['faq_key' => 'returns', 'question' => 'मैं आइटम कैसे वापस या एक्सचेंज करूँ?', 'answer' => 'डिलीवरी के 7 दिनों के भीतर, यदि आइटम दोबारा बिकने योग्य हो, तो रिटर्न या एक्सचेंज का अनुरोध कर सकते हैं। ऑर्डर विवरण से आवेदन जमा करें और निर्देशों का पालन करें।'],
                    ['faq_key' => 'warranty', 'question' => 'वारंटी की गणना कैसे होती है?', 'answer' => 'वारंटी डिलीवरी तिथि से शुरू होती है (सटीक अवधि उत्पाद पेज पर देखें)। दुरुपयोग या निर्देशों का पालन न करने से हुए नुकसान कवर नहीं होते।'],
                    ['faq_key' => 'payment', 'question' => 'कौन-से भुगतान तरीके समर्थित हैं?', 'answer' => 'हम प्रमुख कार्ड, थर्ड-पार्टी भुगतान और इस स्टोरफ्रंट पर सक्षम वॉलेट का समर्थन करते हैं। उपलब्ध तरीके चेकआउट पर दिखते हैं।'],
                ],
                'id_ID' => [
                    ['faq_key' => 'shipping', 'question' => 'Berapa lama pengiriman berlangsung?', 'answer' => 'Pesanan domestik biasanya tiba dalam 2–5 hari kerja; daerah terpencil mungkin lebih lama. Lacak pengiriman di halaman pesanan setelah dikirim.'],
                    ['faq_key' => 'returns', 'question' => 'Bagaimana cara mengembalikan atau menukar barang?', 'answer' => 'Anda dapat meminta retur atau penukaran dalam 7 hari setelah diterima jika barang masih dapat dijual kembali. Ajukan dari detail pesanan dan ikuti petunjuk retur.'],
                    ['faq_key' => 'warranty', 'question' => 'Bagaimana garansi dihitung?', 'answer' => 'Garansi dimulai sejak tanggal penerimaan (lihat jangka waktu pasti di halaman produk). Kerusakan karena penyalahgunaan atau tidak mengikuti petunjuk tidak ditanggung.'],
                    ['faq_key' => 'payment', 'question' => 'Metode pembayaran apa yang didukung?', 'answer' => 'Kami mendukung kartu utama, pembayaran pihak ketiga, dan dompet yang diaktifkan di toko ini. Metode yang tersedia ditampilkan saat checkout.'],
                ],
                'pt_BR' => [
                    ['faq_key' => 'shipping', 'question' => 'Quanto tempo leva a entrega?', 'answer' => 'Pedidos nacionais geralmente chegam em 2–5 dias úteis; áreas remotas podem demorar mais. Acompanhe o envio na página do pedido após o despacho.'],
                    ['faq_key' => 'returns', 'question' => 'Como devolver ou trocar um item?', 'answer' => 'Você pode solicitar devolução ou troca em até 7 dias após a entrega se o item estiver revendável. Envie o pedido pelos detalhes do pedido e siga as instruções.'],
                    ['faq_key' => 'warranty', 'question' => 'Como a garantia é calculada?', 'answer' => 'A garantia começa na data de entrega (veja o prazo exato na página do produto). Danos por mau uso ou por não seguir as instruções não são cobertos.'],
                    ['faq_key' => 'payment', 'question' => 'Quais métodos de pagamento são aceitos?', 'answer' => 'Aceitamos cartões principais, pagamentos de terceiros e carteiras ativadas nesta loja. Os métodos disponíveis aparecem no checkout.'],
                ],
                'ur_PK' => [
                    ['faq_key' => 'shipping', 'question' => 'ڈیلیوری میں کتنا وقت لگتا ہے؟', 'answer' => 'ملکی آرڈرز عام طور پر 2–5 کاروباری دنوں میں پہنچ جاتے ہیں؛ دور دراز علاقوں میں زیادہ وقت لگ سکتا ہے۔ بھیجنے کے بعد آرڈر صفحے پر شپنگ ٹریک کریں۔'],
                    ['faq_key' => 'returns', 'question' => 'آئٹم واپس یا ایکسچینج کیسے کروں؟', 'answer' => 'ڈیلیوری کے 7 دنوں کے اندر، اگر آئٹم دوبارہ فروخت کے قابل ہو تو واپسی یا تبادلے کی درخواست کر سکتے ہیں۔ آرڈر تفصیل سے درخواست جمع کروائیں اور ہدایات پر عمل کریں۔'],
                    ['faq_key' => 'warranty', 'question' => 'وارنٹی کا حساب کیسے ہوتا ہے؟', 'answer' => 'وارنٹی ڈیلیوری کی تاریخ سے شروع ہوتی ہے (درست مدت پروڈکٹ صفحے پر دیکھیں)۔ غلط استعمال یا ہدایات نہ ماننے سے ہونے والے نقصان شامل نہیں۔'],
                    ['faq_key' => 'payment', 'question' => 'کون سے ادائیگی کے طریقے دستیاب ہیں؟', 'answer' => 'ہم بڑے کارڈز، تھرڈ پارٹی ادائیگیاں اور اس اسٹور فرنٹ پر فعال والیٹس سپورٹ کرتے ہیں۔ دستیاب طریقے چیک آؤٹ پر دکھائی دیتے ہیں۔'],
                ],
            ],
            FaqTemplatePacks::CROSS_BORDER => [
                FaqTemplateSeedService::LOCALE_ZH => [
                    ['faq_key' => 'customs', 'question' => '跨境订单需要交关税吗？', 'answer' => '视目的地海关政策而定。部分线路已含税；如产生税费，以清关通知为准。'],
                    ['faq_key' => 'lead_time', 'question' => '跨境时效大概多久？', 'answer' => '一般 7–20 个工作日，受清关与航线影响。可在物流轨迹查看最新节点。'],
                    ['faq_key' => 'clearance', 'question' => '清关需要我提供资料吗？', 'answer' => '偶发需要身份信息用于清关，我们会通过订单消息联系您，请及时配合以免延误。'],
                    ['faq_key' => 'returns_xb', 'question' => '跨境退货怎么处理？', 'answer' => '跨境退货需先审核。通过后按退货地址寄回；运费与税费政策以售后说明为准。'],
                ],
                FaqTemplateSeedService::LOCALE_EN => [
                    ['faq_key' => 'customs', 'question' => 'Will I pay customs duties on cross-border orders?', 'answer' => 'It depends on destination customs rules. Some lanes are duty-included; otherwise follow the clearance notice for any fees.'],
                    ['faq_key' => 'lead_time', 'question' => 'How long does cross-border shipping take?', 'answer' => 'Usually 7–20 business days, depending on clearance and carrier routes. Check the tracking timeline for the latest status.'],
                    ['faq_key' => 'clearance', 'question' => 'Do I need to provide documents for customs clearance?', 'answer' => 'Occasionally identity details are required. We will contact you via order messages—please respond promptly to avoid delays.'],
                    ['faq_key' => 'returns_xb', 'question' => 'How do cross-border returns work?', 'answer' => 'Returns need prior approval. After approval, ship to the return address; shipping and duty policy follow the after-sales instructions.'],
                ],
                'ar_SA' => [
                    ['faq_key' => 'customs', 'question' => 'هل أدفع رسوم جمركية على الطلبات العابرة للحدود؟', 'answer' => 'يعتمد ذلك على قواعد الجمارك في الوجهة. بعض المسارات شاملة الرسوم؛ وإلا اتبع إشعار التخليص لأي رسوم.'],
                    ['faq_key' => 'lead_time', 'question' => 'كم يستغرق الشحن عبر الحدود؟', 'answer' => 'عادةً 7–20 يوم عمل حسب التخليص ومسارات الناقل. راجع جدول التتبع لأحدث الحالة.'],
                    ['faq_key' => 'clearance', 'question' => 'هل أحتاج لتقديم مستندات للتخليص الجمركي؟', 'answer' => 'أحيانًا تُطلب بيانات الهوية. سنتواصل عبر رسائل الطلب—يرجى الرد سريعًا لتجنب التأخير.'],
                    ['faq_key' => 'returns_xb', 'question' => 'كيف تعمل إرجاعات الشحن عبر الحدود؟', 'answer' => 'يتطلب الإرجاع موافقة مسبقة. بعد الموافقة، اشحن إلى عنوان الإرجاع؛ سياسة الشحن والرسوم وفق تعليمات ما بعد البيع.'],
                ],
                'bn_BD' => [
                    ['faq_key' => 'customs', 'question' => 'ক্রস-বর্ডার অর্ডারে কি শুল্ক দিতে হবে?', 'answer' => 'গন্তব্যের কাস্টমস নিয়মের উপর নির্ভর করে। কিছু রুটে শুল্ক অন্তর্ভুক্ত; অন্যথায় ক্লিয়ারেন্স নোটিশ অনুসরণ করুন।'],
                    ['faq_key' => 'lead_time', 'question' => 'ক্রস-বর্ডার শিপিং কতদিন লাগে?', 'answer' => 'সাধারণত ৭–২০ কর্মদিবস, ক্লিয়ারেন্স ও ক্যারিয়ার রুটের উপর নির্ভর করে। সর্বশেষ অবস্থা ট্র্যাকিং টাইমলাইনে দেখুন।'],
                    ['faq_key' => 'clearance', 'question' => 'কাস্টমস ক্লিয়ারেন্সের জন্য কি কাগজপত্র দিতে হবে?', 'answer' => 'মাঝে মাঝে পরিচয়ের তথ্য লাগে। আমরা অর্ডার মেসেজে যোগাযোগ করব—বিলম্ব এড়াতে দ্রুত সাড়া দিন।'],
                    ['faq_key' => 'returns_xb', 'question' => 'ক্রস-বর্ডার রিটার্ন কীভাবে হয়?', 'answer' => 'রিটার্নের আগে অনুমোদন লাগে। অনুমোদনের পর রিটার্ন ঠিকানায় পাঠান; শিপিং ও শুল্ক নীতি আফটার-সেলস নির্দেশনা অনুযায়ী।'],
                ],
                'es_ES' => [
                    ['faq_key' => 'customs', 'question' => '¿Pagaré aranceles en pedidos transfronterizos?', 'answer' => 'Depende de las normas aduaneras del destino. Algunas rutas incluyen impuestos; de lo contrario, sigue el aviso de despacho.'],
                    ['faq_key' => 'lead_time', 'question' => '¿Cuánto tarda el envío transfronterizo?', 'answer' => 'Suele ser 7–20 días laborables, según el despacho y las rutas. Consulta el seguimiento para el estado más reciente.'],
                    ['faq_key' => 'clearance', 'question' => '¿Debo aportar documentos para el despacho aduanero?', 'answer' => 'A veces se requieren datos de identidad. Te contactaremos por mensajes del pedido; responde pronto para evitar retrasos.'],
                    ['faq_key' => 'returns_xb', 'question' => '¿Cómo funcionan las devoluciones transfronterizas?', 'answer' => 'Requieren aprobación previa. Tras la aprobación, envía a la dirección de devolución; envío e impuestos siguen las instrucciones posventa.'],
                ],
                'fr_FR' => [
                    ['faq_key' => 'customs', 'question' => 'Dois-je payer des droits de douane sur les commandes transfrontalières ?', 'answer' => 'Cela dépend des règles douanières de destination. Certaines lignes sont taxes incluses ; sinon suivez l’avis de dédouanement.'],
                    ['faq_key' => 'lead_time', 'question' => 'Combien de temps prend l’expédition transfrontalière ?', 'answer' => 'Généralement 7 à 20 jours ouvrés, selon le dédouanement et les routes. Consultez le suivi pour le dernier statut.'],
                    ['faq_key' => 'clearance', 'question' => 'Dois-je fournir des documents pour le dédouanement ?', 'answer' => 'Des informations d’identité peuvent être demandées. Nous vous contactons via les messages de commande — répondez rapidement.'],
                    ['faq_key' => 'returns_xb', 'question' => 'Comment fonctionnent les retours transfrontaliers ?', 'answer' => 'Une approbation préalable est requise. Après approbation, expédiez à l’adresse de retour ; frais et taxes selon le service après-vente.'],
                ],
                'hi_IN' => [
                    ['faq_key' => 'customs', 'question' => 'क्रॉस-बॉर्डर ऑर्डर पर क्या कस्टम ड्यूटी लगेगी?', 'answer' => 'यह गंतव्य के कस्टम नियमों पर निर्भर करता है। कुछ रूट ड्यूटी-शामिल होते हैं; अन्यथा क्लीयरेंस नोटिस देखें।'],
                    ['faq_key' => 'lead_time', 'question' => 'क्रॉस-बॉर्डर शिपिंग में कितना समय लगता है?', 'answer' => 'आमतौर पर 7–20 कार्यदिवस, क्लीयरेंस और कैरियर रूट पर निर्भर। नवीनतम स्थिति ट्रैकिंग टाइमलाइन पर देखें।'],
                    ['faq_key' => 'clearance', 'question' => 'कस्टम क्लीयरेंस के लिए क्या दस्तावेज़ देने होंगे?', 'answer' => 'कभी-कभी पहचान विवरण चाहिए। हम ऑर्डर संदेश से संपर्क करेंगे—देरी से बचने के लिए शीघ्र जवाब दें।'],
                    ['faq_key' => 'returns_xb', 'question' => 'क्रॉस-बॉर्डर रिटर्न कैसे काम करता है?', 'answer' => 'पहले अनुमोदन ज़रूरी है। अनुमोदन के बाद रिटर्न पते पर भेजें; शिपिंग और ड्यूटी नीति आफ्टर-सेल्स निर्देशों के अनुसार।'],
                ],
                'id_ID' => [
                    ['faq_key' => 'customs', 'question' => 'Apakah saya membayar bea cukai untuk pesanan lintas batas?', 'answer' => 'Tergantung aturan bea cukai tujuan. Beberapa jalur sudah termasuk bea; jika tidak, ikuti pemberitahuan clearance.'],
                    ['faq_key' => 'lead_time', 'question' => 'Berapa lama pengiriman lintas batas?', 'answer' => 'Biasanya 7–20 hari kerja, tergantung clearance dan rute kurir. Cek timeline pelacakan untuk status terbaru.'],
                    ['faq_key' => 'clearance', 'question' => 'Apakah saya perlu memberikan dokumen untuk clearance?', 'answer' => 'Kadang data identitas diperlukan. Kami akan menghubungi lewat pesan pesanan—mohon segera balas agar tidak tertunda.'],
                    ['faq_key' => 'returns_xb', 'question' => 'Bagaimana proses retur lintas batas?', 'answer' => 'Retur perlu persetujuan sebelumnya. Setelah disetujui, kirim ke alamat retur; biaya kirim dan bea mengikuti petunjuk purna jual.'],
                ],
                'pt_BR' => [
                    ['faq_key' => 'customs', 'question' => 'Vou pagar taxas alfandegárias em pedidos internacionais?', 'answer' => 'Depende das regras alfandegárias do destino. Algumas rotas já incluem impostos; caso contrário, siga o aviso de desembaraço.'],
                    ['faq_key' => 'lead_time', 'question' => 'Quanto tempo leva o envio internacional?', 'answer' => 'Geralmente 7–20 dias úteis, conforme desembaraço e rotas. Veja o rastreio para o status mais recente.'],
                    ['faq_key' => 'clearance', 'question' => 'Preciso fornecer documentos para o desembaraço?', 'answer' => 'Às vezes são necessários dados de identidade. Entraremos em contato pelas mensagens do pedido — responda rápido para evitar atraso.'],
                    ['faq_key' => 'returns_xb', 'question' => 'Como funcionam as devoluções internacionais?', 'answer' => 'É necessária aprovação prévia. Após a aprovação, envie para o endereço de devolução; frete e taxas seguem as instruções de pós-venda.'],
                ],
                'ur_PK' => [
                    ['faq_key' => 'customs', 'question' => 'کراس بارڈر آرڈرز پر کیا کسٹم ڈیوٹی لگے گی؟', 'answer' => 'یہ منزل کے کسٹم قواعد پر منحصر ہے۔ کچھ راستوں میں ڈیوٹی شامل ہوتی ہے؛ ورنہ کلیئرنس نوٹس دیکھیں۔'],
                    ['faq_key' => 'lead_time', 'question' => 'کراس بارڈر شپنگ میں کتنا وقت لگتا ہے؟', 'answer' => 'عام طور پر 7–20 کاروباری دن، کلیئرنس اور کیریئر روٹ کے مطابق۔ تازہ ترین صورتحال ٹریکنگ ٹائم لائن پر دیکھیں۔'],
                    ['faq_key' => 'clearance', 'question' => 'کیا کلیئرنس کے لیے دستاویزات دینی ہوں گی؟', 'answer' => 'کبھی کبھار شناختی تفصیلات درکار ہوتی ہیں۔ ہم آرڈر میسجز سے رابطہ کریں گے—تاخیر سے بچنے کے لیے فوری جواب دیں۔'],
                    ['faq_key' => 'returns_xb', 'question' => 'کراس بارڈر واپسی کیسے ہوتی ہے؟', 'answer' => 'پہلے منظوری ضروری ہے۔ منظوری کے بعد واپسی ایڈریس پر بھیجیں؛ شپنگ اور ڈیوٹی پالیسی بعد از فروخت ہدایات کے مطابق۔'],
                ],
            ],
            FaqTemplatePacks::VIRTUAL => [
                FaqTemplateSeedService::LOCALE_ZH => [
                    ['faq_key' => 'delivery', 'question' => '虚拟商品如何发货？', 'answer' => '支付成功后通常即时或数分钟内通过站内消息/邮件交付账号、激活码或下载链接。'],
                    ['faq_key' => 'account', 'question' => '账号信息在哪里查看？', 'answer' => '请在订单详情或账户消息中查看。建议尽快修改初始密码并妥善保管。'],
                    ['faq_key' => 'non_refund', 'question' => '虚拟商品可以退款吗？', 'answer' => '一经交付通常不支持无理由退款。若无法激活等履约问题，请联系客服核实。'],
                    ['faq_key' => 'reuse', 'question' => '激活码可以重复使用吗？', 'answer' => '默认一码一用。若提示已使用，请核对订单与平台，并联系客服排查。'],
                ],
                FaqTemplateSeedService::LOCALE_EN => [
                    ['faq_key' => 'delivery', 'question' => 'How are virtual products delivered?', 'answer' => 'After payment, account details, activation codes, or download links are usually delivered instantly or within minutes via site message or email.'],
                    ['faq_key' => 'account', 'question' => 'Where can I find my account details?', 'answer' => 'Check the order details or account messages. Change the initial password promptly and keep credentials secure.'],
                    ['faq_key' => 'non_refund', 'question' => 'Can I get a refund for virtual products?', 'answer' => 'Once delivered, no-reason refunds are usually unavailable. Contact support if activation or fulfillment fails.'],
                    ['faq_key' => 'reuse', 'question' => 'Can activation codes be reused?', 'answer' => 'Codes are one-time by default. If a code shows as used, verify the order and platform, then contact support.'],
                ],
                'ar_SA' => [
                    ['faq_key' => 'delivery', 'question' => 'كيف يتم تسليم المنتجات الافتراضية؟', 'answer' => 'بعد الدفع، تُسلَّم تفاصيل الحساب أو رموز التفعيل أو روابط التنزيل فورًا أو خلال دقائق عبر رسالة الموقع أو البريد.'],
                    ['faq_key' => 'account', 'question' => 'أين أجد تفاصيل حسابي؟', 'answer' => 'راجع تفاصيل الطلب أو رسائل الحساب. غيّر كلمة المرور الأولية فورًا واحفظ بيانات الدخول بأمان.'],
                    ['faq_key' => 'non_refund', 'question' => 'هل يمكن استرداد المنتجات الافتراضية؟', 'answer' => 'بعد التسليم عادةً لا يُتاح الاسترداد دون سبب. تواصل مع الدعم إذا فشل التفعيل أو التنفيذ.'],
                    ['faq_key' => 'reuse', 'question' => 'هل يمكن إعادة استخدام رموز التفعيل؟', 'answer' => 'الرموز لمرة واحدة افتراضيًا. إذا ظهر الرمز مستخدمًا، تحقق من الطلب والمنصة ثم تواصل مع الدعم.'],
                ],
                'bn_BD' => [
                    ['faq_key' => 'delivery', 'question' => 'ভার্চুয়াল পণ্য কীভাবে ডেলিভার হয়?', 'answer' => 'পেমেন্টের পর অ্যাকাউন্ট বিবরণ, অ্যাক্টিভেশন কোড বা ডাউনলোড লিংক সাধারণত তাৎক্ষণিক বা কয়েক মিনিটের মধ্যে সাইট মেসেজ/ইমেইলে পাঠানো হয়।'],
                    ['faq_key' => 'account', 'question' => 'অ্যাকাউন্ট তথ্য কোথায় দেখব?', 'answer' => 'অর্ডার বিবরণ বা অ্যাকাউন্ট মেসেজে দেখুন। প্রাথমিক পাসওয়ার্ড দ্রুত বদলান এবং নিরাপদে রাখুন।'],
                    ['faq_key' => 'non_refund', 'question' => 'ভার্চুয়াল পণ্যের রিফান্ড পাওয়া যায় কি?', 'answer' => 'ডেলিভারির পর সাধারণত বিনা কারণে রিফান্ড হয় না। অ্যাক্টিভেশন বা সরবরাহ ব্যর্থ হলে সাপোর্টে যোগাযোগ করুন।'],
                    ['faq_key' => 'reuse', 'question' => 'অ্যাক্টিভেশন কোড কি পুনরায় ব্যবহারযোগ্য?', 'answer' => 'ডিফল্টে একবার ব্যবহার্য। ব্যবহৃত দেখালে অর্ডার ও প্ল্যাটফর্ম যাচাই করে সাপোর্টে যোগাযোগ করুন।'],
                ],
                'es_ES' => [
                    ['faq_key' => 'delivery', 'question' => '¿Cómo se entregan los productos virtuales?', 'answer' => 'Tras el pago, los datos de cuenta, códigos de activación o enlaces de descarga suelen enviarse al instante o en minutos por mensaje del sitio o correo.'],
                    ['faq_key' => 'account', 'question' => '¿Dónde veo los datos de mi cuenta?', 'answer' => 'Consulta los detalles del pedido o los mensajes de la cuenta. Cambia la contraseña inicial cuanto antes y guárdala de forma segura.'],
                    ['faq_key' => 'non_refund', 'question' => '¿Puedo obtener reembolso de productos virtuales?', 'answer' => 'Una vez entregados, normalmente no hay reembolso sin motivo. Contacta con soporte si falla la activación o la entrega.'],
                    ['faq_key' => 'reuse', 'question' => '¿Se pueden reutilizar los códigos de activación?', 'answer' => 'Por defecto son de un solo uso. Si aparece como usado, verifica el pedido y la plataforma y contacta con soporte.'],
                ],
                'fr_FR' => [
                    ['faq_key' => 'delivery', 'question' => 'Comment les produits virtuels sont-ils livrés ?', 'answer' => 'Après paiement, les identifiants, codes d’activation ou liens de téléchargement sont généralement envoyés instantanément ou en quelques minutes par message ou e-mail.'],
                    ['faq_key' => 'account', 'question' => 'Où trouver les détails de mon compte ?', 'answer' => 'Consultez les détails de commande ou les messages du compte. Changez rapidement le mot de passe initial et conservez-le en sécurité.'],
                    ['faq_key' => 'non_refund', 'question' => 'Puis-je être remboursé pour un produit virtuel ?', 'answer' => 'Une fois livré, le remboursement sans motif est généralement indisponible. Contactez le support en cas d’échec d’activation.'],
                    ['faq_key' => 'reuse', 'question' => 'Les codes d’activation sont-ils réutilisables ?', 'answer' => 'Ils sont à usage unique par défaut. S’il apparaît comme utilisé, vérifiez la commande et la plateforme, puis contactez le support.'],
                ],
                'hi_IN' => [
                    ['faq_key' => 'delivery', 'question' => 'वर्चुअल उत्पाद कैसे डिलीवर होते हैं?', 'answer' => 'भुगतान के बाद खाता विवरण, एक्टिवेशन कोड या डाउनलोड लिंक आमतौर पर तुरंत या कुछ मिनटों में साइट संदेश/ईमेल से मिलते हैं।'],
                    ['faq_key' => 'account', 'question' => 'मेरे खाते का विवरण कहाँ मिलेगा?', 'answer' => 'ऑर्डर विवरण या खाता संदेश देखें। प्रारंभिक पासवर्ड जल्द बदलें और सुरक्षित रखें।'],
                    ['faq_key' => 'non_refund', 'question' => 'क्या वर्चुअल उत्पादों पर रिफंड मिल सकता है?', 'answer' => 'डिलीवरी के बाद आमतौर पर बिना कारण रिफंड नहीं मिलता। एक्टिवेशन या पूर्ति विफल हो तो सपोर्ट से संपर्क करें।'],
                    ['faq_key' => 'reuse', 'question' => 'क्या एक्टिवेशन कोड दोबारा इस्तेमाल हो सकते हैं?', 'answer' => 'डिफ़ॉल्ट रूप से एक बार उपयोग। यदि प्रयुक्त दिखे तो ऑर्डर और प्लेटफ़ॉर्म जाँचकर सपोर्ट से संपर्क करें।'],
                ],
                'id_ID' => [
                    ['faq_key' => 'delivery', 'question' => 'Bagaimana produk virtual dikirim?', 'answer' => 'Setelah pembayaran, detail akun, kode aktivasi, atau tautan unduhan biasanya dikirim segera atau dalam beberapa menit via pesan situs/email.'],
                    ['faq_key' => 'account', 'question' => 'Di mana saya menemukan detail akun?', 'answer' => 'Periksa detail pesanan atau pesan akun. Segera ganti kata sandi awal dan simpan dengan aman.'],
                    ['faq_key' => 'non_refund', 'question' => 'Bisakah produk virtual di-refund?', 'answer' => 'Setelah dikirim, refund tanpa alasan biasanya tidak tersedia. Hubungi dukungan jika aktivasi atau pemenuhan gagal.'],
                    ['faq_key' => 'reuse', 'question' => 'Bisakah kode aktivasi digunakan ulang?', 'answer' => 'Secara default sekali pakai. Jika tertera sudah digunakan, verifikasi pesanan dan platform, lalu hubungi dukungan.'],
                ],
                'pt_BR' => [
                    ['faq_key' => 'delivery', 'question' => 'Como os produtos virtuais são entregues?', 'answer' => 'Após o pagamento, dados da conta, códigos de ativação ou links de download geralmente são enviados na hora ou em minutos por mensagem do site ou e-mail.'],
                    ['faq_key' => 'account', 'question' => 'Onde encontro os dados da minha conta?', 'answer' => 'Veja os detalhes do pedido ou as mensagens da conta. Altere a senha inicial rapidamente e guarde com segurança.'],
                    ['faq_key' => 'non_refund', 'question' => 'Posso obter reembolso de produtos virtuais?', 'answer' => 'Após a entrega, reembolsos sem motivo geralmente não estão disponíveis. Contate o suporte se a ativação falhar.'],
                    ['faq_key' => 'reuse', 'question' => 'Códigos de ativação podem ser reutilizados?', 'answer' => 'São de uso único por padrão. Se aparecer como usado, verifique o pedido e a plataforma e contate o suporte.'],
                ],
                'ur_PK' => [
                    ['faq_key' => 'delivery', 'question' => 'ورچوئل مصنوعات کیسے ڈیلیور ہوتی ہیں؟', 'answer' => 'ادائیگی کے بعد اکاؤنٹ تفصیلات، ایکٹیویشن کوڈز یا ڈاؤن لوڈ لنکس عام طور پر فوری یا چند منٹ میں سائٹ میسج/ای میل سے ملتے ہیں۔'],
                    ['faq_key' => 'account', 'question' => 'میرے اکاؤنٹ کی تفصیلات کہاں ملیں گی؟', 'answer' => 'آرڈر تفصیل یا اکاؤنٹ میسجز دیکھیں۔ ابتدائی پاس ورڈ فوراً تبدیل کریں اور محفوظ رکھیں۔'],
                    ['faq_key' => 'non_refund', 'question' => 'کیا ورچوئل مصنوعات کی رقم واپس مل سکتی ہے؟', 'answer' => 'ڈیلیوری کے بعد عام طور پر بغیر وجہ ریفنڈ دستیاب نہیں۔ ایکٹیویشن ناکام ہو تو سپورٹ سے رابطہ کریں۔'],
                    ['faq_key' => 'reuse', 'question' => 'کیا ایکٹیویشن کوڈ دوبارہ استعمال ہو سکتے ہیں؟', 'answer' => 'ڈیفالٹ ایک بار استعمال۔ اگر استعمال شدہ دکھے تو آرڈر اور پلیٹ فارم چیک کر کے سپورٹ سے رابطہ کریں۔'],
                ],
            ],
            FaqTemplatePacks::B2B => [
                FaqTemplateSeedService::LOCALE_ZH => [
                    ['faq_key' => 'moq', 'question' => '起订量是多少？', 'answer' => '不同 SKU 起订量不同，以商品页/报价单为准。批量询价可获更优阶梯价。'],
                    ['faq_key' => 'payment_terms', 'question' => '支持账期吗？', 'answer' => '认证企业客户可申请账期；额度与账期天数以商务审核结果为准。'],
                    ['faq_key' => 'contract', 'question' => '如何签合同与开票？', 'answer' => '下单后可申请合同与增值税发票。请在企业资料中维护开票信息。'],
                    ['faq_key' => 'lead_b2b', 'question' => '大货交期如何约定？', 'answer' => '交期写入报价/合同。加急需求请提前沟通产能与加急费用。'],
                ],
                FaqTemplateSeedService::LOCALE_EN => [
                    ['faq_key' => 'moq', 'question' => 'What is the minimum order quantity?', 'answer' => 'MOQ varies by SKU and is shown on the product page or quote. Bulk inquiries may unlock better tier pricing.'],
                    ['faq_key' => 'payment_terms', 'question' => 'Do you offer payment terms?', 'answer' => 'Verified business buyers can apply for terms; credit limit and days depend on commercial review.'],
                    ['faq_key' => 'contract', 'question' => 'How do contracts and invoices work?', 'answer' => 'After ordering you can request a contract and VAT invoice. Keep billing details updated in your business profile.'],
                    ['faq_key' => 'lead_b2b', 'question' => 'How are bulk lead times agreed?', 'answer' => 'Lead times are written into the quote or contract. Contact us early for rush capacity and fees.'],
                ],
                'ar_SA' => [
                    ['faq_key' => 'moq', 'question' => 'ما الحد الأدنى لكمية الطلب؟', 'answer' => 'يختلف الحد الأدنى حسب SKU ويظهر في صفحة المنتج أو العرض. الاستفسارات بالجملة قد تفتح تسعيرًا أفضل.'],
                    ['faq_key' => 'payment_terms', 'question' => 'هل تقدمون شروط دفع آجلة؟', 'answer' => 'يمكن للمشترين التجاريين المعتمدين التقديم؛ الحد والأيام وفق المراجعة التجارية.'],
                    ['faq_key' => 'contract', 'question' => 'كيف تعمل العقود والفواتير؟', 'answer' => 'بعد الطلب يمكنك طلب عقد وفاتورة ضريبة. حافظ على بيانات الفوترة محدثة في ملف الشركة.'],
                    ['faq_key' => 'lead_b2b', 'question' => 'كيف تُتفق مواعيد التسليم للكميات الكبيرة؟', 'answer' => 'تُكتب المواعيد في العرض أو العقد. تواصل مبكرًا للطلبات العاجلة والرسوم.'],
                ],
                'bn_BD' => [
                    ['faq_key' => 'moq', 'question' => 'ন্যূনতম অর্ডার পরিমাণ কত?', 'answer' => 'MOQ SKU অনুযায়ী ভিন্ন এবং পণ্য পেজ বা কোটেশনে দেখা যায়। বাল্ক অনুসন্ধানে ভালো ধাপমূল্য পাওয়া যেতে পারে।'],
                    ['faq_key' => 'payment_terms', 'question' => 'আপনি কি পেমেন্ট টার্মস দেন?', 'answer' => 'যাচাইকৃত ব্যবসায়িক ক্রেতারা আবেদন করতে পারেন; সীমা ও দিন বাণিজ্যিক পর্যালোচনার উপর নির্ভর করে।'],
                    ['faq_key' => 'contract', 'question' => 'চুক্তি ও চালান কীভাবে হয়?', 'answer' => 'অর্ডারের পর চুক্তি ও ভ্যাট চালান অনুরোধ করতে পারেন। ব্যবসায়িক প্রোফাইলে বিলিং তথ্য আপডেট রাখুন।'],
                    ['faq_key' => 'lead_b2b', 'question' => 'বাল্ক ডেলিভারি সময় কীভাবে ঠিক হয়?', 'answer' => 'লিড টাইম কোটেশন/চুক্তিতে লেখা থাকে। জরুরি চাহিদার জন্য আগেই সক্ষমতা ও ফি নিয়ে যোগাযোগ করুন।'],
                ],
                'es_ES' => [
                    ['faq_key' => 'moq', 'question' => '¿Cuál es la cantidad mínima de pedido?', 'answer' => 'El MOQ varía por SKU y figura en la ficha o el presupuesto. Las consultas al por mayor pueden desbloquear mejor precio por tramos.'],
                    ['faq_key' => 'payment_terms', 'question' => '¿Ofrecen plazos de pago?', 'answer' => 'Los compradores empresariales verificados pueden solicitarlo; el límite y los días dependen de la revisión comercial.'],
                    ['faq_key' => 'contract', 'question' => '¿Cómo funcionan contratos y facturas?', 'answer' => 'Tras pedir puedes solicitar contrato y factura IVA. Mantén actualizados los datos de facturación en el perfil empresarial.'],
                    ['faq_key' => 'lead_b2b', 'question' => '¿Cómo se acuerdan los plazos de entrega a granel?', 'answer' => 'Los plazos se escriben en el presupuesto o contrato. Contacta pronto para capacidad urgente y tarifas.'],
                ],
                'fr_FR' => [
                    ['faq_key' => 'moq', 'question' => 'Quelle est la quantité minimale de commande ?', 'answer' => 'Le MOQ varie selon le SKU et figure sur la page produit ou le devis. Les demandes en volume peuvent débloquer de meilleurs paliers.'],
                    ['faq_key' => 'payment_terms', 'question' => 'Proposez-vous des délais de paiement ?', 'answer' => 'Les acheteurs professionnels vérifiés peuvent en faire la demande ; plafond et durée selon revue commerciale.'],
                    ['faq_key' => 'contract', 'question' => 'Comment fonctionnent contrats et factures ?', 'answer' => 'Après commande, vous pouvez demander un contrat et une facture TVA. Gardez les infos de facturation à jour dans le profil entreprise.'],
                    ['faq_key' => 'lead_b2b', 'question' => 'Comment sont convenus les délais en volume ?', 'answer' => 'Les délais sont écrits dans le devis ou le contrat. Contactez-nous tôt pour capacité urgente et frais.'],
                ],
                'hi_IN' => [
                    ['faq_key' => 'moq', 'question' => 'न्यूनतम ऑर्डर मात्रा क्या है?', 'answer' => 'MOQ SKU के अनुसार अलग होता है और उत्पाद पेज/कोट पर दिखता है। थोक पूछताछ से बेहतर स्लैब मूल्य मिल सकता है।'],
                    ['faq_key' => 'payment_terms', 'question' => 'क्या आप भुगतान अवधि देते हैं?', 'answer' => 'सत्यापित व्यावसायिक खरीदार आवेदन कर सकते हैं; सीमा और दिन वाणिज्यिक समीक्षा पर निर्भर करते हैं।'],
                    ['faq_key' => 'contract', 'question' => 'अनुबंध और चालान कैसे काम करते हैं?', 'answer' => 'ऑर्डर के बाद आप अनुबंध और VAT चालान माँग सकते हैं। व्यावसायिक प्रोफ़ाइल में बिलिंग विवरण अपडेट रखें।'],
                    ['faq_key' => 'lead_b2b', 'question' => 'थोक डिलीवरी समय कैसे तय होता है?', 'answer' => 'लीड टाइम कोट/अनुबंध में लिखा जाता है। जल्दी क्षमता और शुल्क के लिए पहले संपर्क करें।'],
                ],
                'id_ID' => [
                    ['faq_key' => 'moq', 'question' => 'Berapa jumlah pesanan minimum?', 'answer' => 'MOQ berbeda per SKU dan ditampilkan di halaman produk atau penawaran. Inquiry massal dapat membuka harga berjenjang lebih baik.'],
                    ['faq_key' => 'payment_terms', 'question' => 'Apakah tersedia jangka pembayaran?', 'answer' => 'Pembeli bisnis terverifikasi dapat mengajukan; limit dan hari bergantung tinjauan komersial.'],
                    ['faq_key' => 'contract', 'question' => 'Bagaimana kontrak dan faktur bekerja?', 'answer' => 'Setelah memesan Anda dapat meminta kontrak dan faktur PPN. Jaga detail penagihan tetap mutakhir di profil bisnis.'],
                    ['faq_key' => 'lead_b2b', 'question' => 'Bagaimana lead time massal disepakati?', 'answer' => 'Lead time ditulis di penawaran/kontrak. Hubungi lebih awal untuk kapasitas mendesak dan biaya.'],
                ],
                'pt_BR' => [
                    ['faq_key' => 'moq', 'question' => 'Qual é a quantidade mínima de pedido?', 'answer' => 'O MOQ varia por SKU e aparece na página do produto ou orçamento. Consultas em volume podem liberar preços por faixa melhores.'],
                    ['faq_key' => 'payment_terms', 'question' => 'Vocês oferecem prazo de pagamento?', 'answer' => 'Compradores empresariais verificados podem solicitar; limite e dias dependem da análise comercial.'],
                    ['faq_key' => 'contract', 'question' => 'Como funcionam contratos e notas fiscais?', 'answer' => 'Após o pedido você pode solicitar contrato e nota com IVA. Mantenha os dados de faturamento atualizados no perfil empresarial.'],
                    ['faq_key' => 'lead_b2b', 'question' => 'Como os prazos de entrega em volume são acordados?', 'answer' => 'Os prazos entram no orçamento/contrato. Contate-nos cedo para capacidade urgente e taxas.'],
                ],
                'ur_PK' => [
                    ['faq_key' => 'moq', 'question' => 'کم از کم آرڈر مقدار کیا ہے؟', 'answer' => 'MOQ ہر SKU کے مطابق مختلف ہے اور پروڈکٹ صفحے/کوٹ پر دکھائی دیتا ہے۔ بلک انکوائری سے بہتر سلیب قیمت مل سکتی ہے۔'],
                    ['faq_key' => 'payment_terms', 'question' => 'کیا آپ ادائیگی کی میعاد دیتے ہیں؟', 'answer' => 'تصدیق شدہ کاروباری خریدار درخواست دے سکتے ہیں؛ حد اور دن تجارتی جائزے پر منحصر ہیں۔'],
                    ['faq_key' => 'contract', 'question' => 'معاہدے اور انوائس کیسے کام کرتے ہیں؟', 'answer' => 'آرڈر کے بعد آپ معاہدہ اور VAT انوائس مانگ سکتے ہیں۔ کاروباری پروفائل میں بلنگ تفصیلات اپ ڈیٹ رکھیں۔'],
                    ['faq_key' => 'lead_b2b', 'question' => 'بلک ڈیلیوری وقت کیسے طے ہوتا ہے؟', 'answer' => 'لیڈ ٹائم کوٹ/معاہدے میں لکھا جاتا ہے۔ فوری صلاحیت اور فیس کے لیے جلد رابطہ کریں۔'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, list<HubRow>>
     */
    private static function hubDefinitions(): array
    {
        return [
            FaqTemplateSeedService::LOCALE_ZH => [
                ['q' => '下单后多久发货？', 'a' => '现货订单通常在付款成功后 1–3 个工作日内发出（节假日顺延）；预售、定制或以商品页标注时效为准。详见「配送说明」。'],
                ['q' => '如何查询物流？', 'a' => '登录后打开「我的订单」可查看承运商与运单节点。长时间无更新时，可先排除节假日，再通过「联系客服」并提供订单号协助查询。'],
                ['q' => '运费如何计算？是否包邮？', 'a' => '运费按收货地区、重量/体积与配送服务在结账页实时计算。满足满额包邮或活动门槛时会自动减免，以结算页显示为准。'],
                ['q' => '支持哪些支付方式？', 'a' => '支持站点已开通的在线支付渠道（如 PayPal 等）。具体可用方式以结算页与「支付方式」指南为准，并可查阅各支付商的用户协议。'],
                ['q' => '如何申请退换货？', 'a' => '请先阅读「退换政策」确认期限与品类要求，再在「我的订单」提交售后并上传凭证。质量问题与个人原因的运费承担规则不同，详见政策页。'],
                ['q' => '退款多久到账？', 'a' => '退回商品质检通过后，退款一般在 3–15 个工作日内原路退回，具体到账时间以支付渠道为准。完整规则见「退款政策」。'],
                ['q' => '可以修改或取消订单吗？', 'a' => '未发货订单可在「我的订单」尝试取消或联系客服协助修改地址/备注。已发货订单无法直接取消，可按签收后的退换政策办理。'],
                ['q' => '个人信息如何保护？', 'a' => '我们仅在提供交易与服务所必需的范围内处理个人信息，详见「隐私政策」与「Cookie 政策」。您可在账户设置中管理部分偏好。'],
            ],
            FaqTemplateSeedService::LOCALE_EN => [
                ['q' => 'How soon will my order ship?', 'a' => 'In-stock orders usually ship within 1–3 business days after payment (holidays excluded). Pre-order, custom, or product-page lead times apply when stated. See Shipping Guide.'],
                ['q' => 'How do I track my shipment?', 'a' => 'Sign in and open My Orders to see the carrier and tracking events. If there is no update for a long time (excluding holidays), contact support with your order number.'],
                ['q' => 'How is shipping calculated? Do you offer free shipping?', 'a' => 'Shipping is calculated at checkout by destination, weight/volume, and service. Free-shipping or campaign thresholds are applied automatically when met.'],
                ['q' => 'Which payment methods are supported?', 'a' => 'We support the online payment methods enabled for this storefront (for example PayPal). Exact options appear at checkout and in the Payment Guide.'],
                ['q' => 'How do I request a return or exchange?', 'a' => 'Read the Returns Policy for windows and category rules, then submit after-sales from My Orders with supporting evidence. Shipping responsibility differs for quality issues vs change-of-mind.'],
                ['q' => 'When will I receive my refund?', 'a' => 'After returned items pass inspection, refunds usually take 3–15 business days via the original payment method. See the Refund Policy for full rules.'],
                ['q' => 'Can I change or cancel my order?', 'a' => 'Unshipped orders may be cancelled or updated from My Orders, or with support help. Shipped orders cannot be cancelled directly; use the returns policy after delivery.'],
                ['q' => 'How is my personal data protected?', 'a' => 'We process personal data only as needed to provide transactions and services. See the Privacy Policy and Cookie Policy. Manage some preferences in account settings.'],
            ],
            'ar_SA' => [
                ['q' => 'متى يُشحن طلبي؟', 'a' => 'طلبات المخزون تُشحن عادةً خلال 1–3 أيام عمل بعد الدفع (باستثناء العطل). أوقات الطلب المسبق أو التخصيص أو صفحة المنتج تُطبَّق عند ذكرها. راجع دليل الشحن.'],
                ['q' => 'كيف أتتبع شحنتي؟', 'a' => 'سجّل الدخول وافتح طلباتي لرؤية الناقل وأحداث التتبع. إن لم يحدث تحديث طويلًا (باستثناء العطل)، تواصل مع الدعم برقم الطلب.'],
                ['q' => 'كيف تُحسب رسوم الشحن؟ هل الشحن مجاني؟', 'a' => 'تُحسب عند الدفع حسب الوجهة والوزن/الحجم والخدمة. تُطبَّق عتبات الشحن المجاني أو الحملات تلقائيًا عند تحققها.'],
                ['q' => 'ما طرق الدفع المدعومة؟', 'a' => 'ندعم طرق الدفع عبر الإنترنت المفعّلة في المتجر (مثل PayPal). تظهر الخيارات الدقيقة عند الدفع وفي دليل الدفع.'],
                ['q' => 'كيف أطلب إرجاعًا أو استبدالًا؟', 'a' => 'اقرأ سياسة الإرجاع للمدد وقواعد الفئات، ثم قدّم ما بعد البيع من طلباتي مع الأدلة. مسؤولية الشحن تختلف بين عيوب الجودة وتغيير الرأي.'],
                ['q' => 'متى أستلم الاسترداد؟', 'a' => 'بعد اجتياز الفحص، يستغرق الاسترداد عادةً 3–15 يوم عمل عبر طريقة الدفع الأصلية. راجع سياسة الاسترداد للقواعد الكاملة.'],
                ['q' => 'هل يمكنني تعديل أو إلغاء طلبي؟', 'a' => 'الطلبات غير المشحونة يمكن إلغاؤها أو تحديثها من طلباتي أو بمساعدة الدعم. بعد الشحن لا يمكن الإلغاء مباشرة؛ استخدم سياسة الإرجاع بعد التسليم.'],
                ['q' => 'كيف تُحمى بياناتي الشخصية؟', 'a' => 'نعالج البيانات الشخصية فقط بقدر الحاجة للمعاملات والخدمات. راجع سياسة الخصوصية وسياسة ملفات التعريف. أدِر بعض التفضيلات في إعدادات الحساب.'],
            ],
            'bn_BD' => [
                ['q' => 'আমার অর্ডার কত তাড়াতাড়ি পাঠানো হবে?', 'a' => 'স্টকে থাকা অর্ডার সাধারণত পেমেন্টের পর ১–৩ কর্মদিবসে পাঠানো হয় (ছুটি বাদে)। প্রি-অর্ডার/কাস্টম/পণ্য পেজের সময় থাকলে সেটাই প্রযোজ্য। শিপিং গাইড দেখুন।'],
                ['q' => 'কীভাবে শিপমেন্ট ট্র্যাক করব?', 'a' => 'লগইন করে আমার অর্ডার খুলে ক্যারিয়ার ও ট্র্যাকিং ইভেন্ট দেখুন। দীর্ঘসময় আপডেট না থাকলে (ছুটি বাদে) অর্ডার নম্বর দিয়ে সাপোর্টে যোগাযোগ করুন।'],
                ['q' => 'শিপিং কীভাবে হিসাব হয়? ফ্রি শিপিং আছে কি?', 'a' => 'চেকআউটে গন্তব্য, ওজন/ভলিউম ও সার্ভিস অনুযায়ী হিসাব হয়। ফ্রি-শিপিং বা ক্যাম্পেইন থ্রেশহোল্ড পূরণ হলে স্বয়ংক্রিয় প্রয়োগ হয়।'],
                ['q' => 'কোন কোন পেমেন্ট পদ্ধতি সমর্থিত?', 'a' => 'এই স্টোরফ্রন্টে সক্রিয় অনলাইন পেমেন্ট (যেমন PayPal) সমর্থিত। সঠিক অপশন চেকআউট ও পেমেন্ট গাইডে দেখা যায়।'],
                ['q' => 'কীভাবে রিটার্ন বা এক্সচেঞ্জ অনুরোধ করব?', 'a' => 'রিটার্ন পলিসির সময়সীমা ও ক্যাটাগরি নিয়ম পড়ুন, তারপর আমার অর্ডার থেকে প্রমাণসহ আফটার-সেলস জমা দিন। মানগতুটি ও মতপরিবর্তনে শিপিং দায় ভিন্ন।'],
                ['q' => 'রিফান্ড কখন পাব?', 'a' => 'ফেরত পণ্য পরিদর্শন পাসের পর সাধারণত ৩–১৫ কর্মদিবসে মূল পেমেন্ট পদ্ধতিতে ফেরত। সম্পূর্ণ নিয়ম রিফান্ড পলিসিতে।'],
                ['q' => 'অর্ডার পরিবর্তন বা বাতিল করা যাবে?', 'a' => 'অপ্রেরিত অর্ডার আমার অর্ডার থেকে বাতিল/আপডেট বা সাপোর্টের সাহায্যে করা যায়। পাঠানোর পর সরাসরি বাতিল নয়; ডেলিভারির পর রিটার্ন পলিসি ব্যবহার করুন।'],
                ['q' => 'আমার ব্যক্তিগত তথ্য কীভাবে সুরক্ষিত?', 'a' => 'লেনদেন ও সেবা দিতে প্রয়োজনীয় পরিসরেই ব্যক্তিগত তথ্য প্রক্রিয়া করি। প্রাইভেসি ও কুকি পলিসি দেখুন। অ্যাকাউন্ট সেটিংসে কিছু পছন্দ নিয়ন্ত্রণ করুন।'],
            ],
            'es_ES' => [
                ['q' => '¿Cuándo se envía mi pedido?', 'a' => 'Los pedidos en stock suelen enviarse en 1–3 días laborables tras el pago (festivos excluidos). Prepedido, personalización o plazos de la ficha aplican si se indican. Ver Guía de envío.'],
                ['q' => '¿Cómo rastrea mi envío?', 'a' => 'Inicia sesión y abre Mis pedidos para ver el transportista y los eventos. Si no hay actualización durante mucho tiempo (salvo festivos), contacta con soporte con el número de pedido.'],
                ['q' => '¿Cómo se calcula el envío? ¿Hay envío gratis?', 'a' => 'Se calcula al pagar según destino, peso/volumen y servicio. Los umbrales de envío gratis o campañas se aplican automáticamente al cumplirse.'],
                ['q' => '¿Qué métodos de pago se admiten?', 'a' => 'Admitimos los métodos online activados en esta tienda (por ejemplo PayPal). Las opciones exactas aparecen al pagar y en la Guía de pago.'],
                ['q' => '¿Cómo solicito una devolución o cambio?', 'a' => 'Lee la Política de devoluciones (plazos y categorías), luego envía posventa desde Mis pedidos con pruebas. La responsabilidad del envío cambia entre defectos y cambio de opinión.'],
                ['q' => '¿Cuándo recibiré el reembolso?', 'a' => 'Tras la inspección, el reembolso suele tardar 3–15 días laborables por el método original. Ver Política de reembolso.'],
                ['q' => '¿Puedo modificar o cancelar mi pedido?', 'a' => 'Los no enviados pueden cancelarse o actualizarse desde Mis pedidos o con soporte. Tras el envío no se cancela directamente; usa la política de devoluciones tras la entrega.'],
                ['q' => '¿Cómo se protegen mis datos personales?', 'a' => 'Tratamos datos personales solo lo necesario para transacciones y servicios. Ver Política de privacidad y de cookies. Gestiona preferencias en la cuenta.'],
            ],
            'fr_FR' => [
                ['q' => 'Quand ma commande sera-t-elle expédiée ?', 'a' => 'Les commandes en stock partent généralement sous 1 à 3 jours ouvrés après paiement (jours fériés exclus). Précommande, sur-mesure ou délais indiqués sur la fiche s’appliquent. Voir le Guide d’expédition.'],
                ['q' => 'Comment suivre mon colis ?', 'a' => 'Connectez-vous et ouvrez Mes commandes pour voir le transporteur et le suivi. Sans mise à jour prolongée (hors jours fériés), contactez le support avec le numéro de commande.'],
                ['q' => 'Comment sont calculés les frais de port ? Livraison gratuite ?', 'a' => 'Calculés au paiement selon destination, poids/volume et service. Les seuils de livraison gratuite ou campagnes s’appliquent automatiquement.'],
                ['q' => 'Quels moyens de paiement sont acceptés ?', 'a' => 'Nous acceptons les moyens en ligne activés sur cette boutique (ex. PayPal). Les options exactes apparaissent au paiement et dans le Guide de paiement.'],
                ['q' => 'Comment demander un retour ou un échange ?', 'a' => 'Lisez la Politique de retours (délais et catégories), puis soumettez l’après-vente depuis Mes commandes avec preuves. La responsabilité d’expédition diffère selon défaut ou changement d’avis.'],
                ['q' => 'Quand recevrai-je mon remboursement ?', 'a' => 'Après inspection, le remboursement prend généralement 3 à 15 jours ouvrés via le moyen d’origine. Voir la Politique de remboursement.'],
                ['q' => 'Puis-je modifier ou annuler ma commande ?', 'a' => 'Les commandes non expédiées peuvent être annulées ou mises à jour depuis Mes commandes ou avec le support. Après expédition, pas d’annulation directe ; utilisez la politique de retours après livraison.'],
                ['q' => 'Comment mes données personnelles sont-elles protégées ?', 'a' => 'Nous traitons les données uniquement autant que nécessaire pour transactions et services. Voir Politique de confidentialité et Cookies. Gérez certaines préférences dans le compte.'],
            ],
            'hi_IN' => [
                ['q' => 'मेरा ऑर्डर कितनी जल्दी भेजा जाएगा?', 'a' => 'स्टॉक में उपलब्ध ऑर्डर आमतौर पर भुगतान के 1–3 कार्यदिवस में भेजे जाते हैं (छुट्टियाँ छोड़कर)। प्री-ऑर्डर/कस्टम/उत्पाद पेज का समय लागू होता है। शिपिंग गाइड देखें।'],
                ['q' => 'मैं शिपमेंट कैसे ट्रैक करूँ?', 'a' => 'साइन इन करके मेरे ऑर्डर खोलें और कैरियर तथा ट्रैकिंग इवेंट देखें। लंबे समय तक अपडेट न हो (छुट्टियाँ छोड़कर) तो ऑर्डर नंबर के साथ सपोर्ट से संपर्क करें।'],
                ['q' => 'शिपिंग कैसे गणना होती है? क्या फ्री शिपिंग है?', 'a' => 'चेकआउट पर गंतव्य, वजन/वॉल्यूम और सेवा के अनुसार गणना होती है। फ्री-शिपिंग या कैंपेन थ्रेशहोल्ड पूरे होने पर स्वतः लागू होते हैं।'],
                ['q' => 'कौन-से भुगतान तरीके समर्थित हैं?', 'a' => 'इस स्टोरफ्रंट पर सक्षम ऑनलाइन भुगतान (जैसे PayPal) समर्थित हैं। सटीक विकल्प चेकआउट और भुगतान गाइड में दिखते हैं।'],
                ['q' => 'रिटर्न या एक्सचेंज कैसे अनुरोध करूँ?', 'a' => 'रिटर्न नीति में अवधि और श्रेणी नियम पढ़ें, फिर मेरे ऑर्डर से साक्ष्य सहित आफ्टर-सेल्स जमा करें। गुणवत्ता दोष और मन-परिवर्तन में शिपिंग जिम्मेदारी अलग होती है।'],
                ['q' => 'रिफंड कब मिलेगा?', 'a' => 'वापस आइटम निरीक्षण पास करने के बाद आमतौर पर 3–15 कार्यदिवस में मूल भुगतान विधि से रिफंड। पूरी नियम रिफंड नीति में।'],
                ['q' => 'क्या मैं ऑर्डर बदल या रद्द कर सकता हूँ?', 'a' => 'न भेजे गए ऑर्डर मेरे ऑर्डर से रद्द/अपडेट या सपोर्ट सहायता से हो सकते हैं। भेजने के बाद सीधे रद्द नहीं; डिलीवरी के बाद रिटर्न नीति उपयोग करें।'],
                ['q' => 'मेरा व्यक्तिगत डेटा कैसे सुरक्षित है?', 'a' => 'हम लेन-देन और सेवाओं के लिए आवश्यक सीमा में ही व्यक्तिगत डेटा संसाधित करते हैं। गोपनीयता और कुकी नीति देखें। खाता सेटिंग में कुछ प्राथमिकताएँ प्रबंधित करें।'],
            ],
            'id_ID' => [
                ['q' => 'Kapan pesanan saya dikirim?', 'a' => 'Pesanan ready stock biasanya dikirim dalam 1–3 hari kerja setelah pembayaran (hari libur dikecualikan). Pre-order, kustom, atau lead time di halaman produk berlaku jika disebutkan. Lihat Panduan Pengiriman.'],
                ['q' => 'Bagaimana melacak pengiriman?', 'a' => 'Masuk dan buka Pesanan Saya untuk melihat kurir dan peristiwa pelacakan. Jika lama tanpa pembaruan (kecuali hari libur), hubungi dukungan dengan nomor pesanan.'],
                ['q' => 'Bagaimana ongkir dihitung? Ada gratis ongkir?', 'a' => 'Dihitung di checkout berdasarkan tujuan, berat/volume, dan layanan. Ambang gratis ongkir atau kampanye diterapkan otomatis jika terpenuhi.'],
                ['q' => 'Metode pembayaran apa yang didukung?', 'a' => 'Kami mendukung metode online yang diaktifkan di toko ini (mis. PayPal). Opsi pasti muncul di checkout dan Panduan Pembayaran.'],
                ['q' => 'Bagaimana meminta retur atau penukaran?', 'a' => 'Baca Kebijakan Retur untuk jendela dan aturan kategori, lalu kirim after-sales dari Pesanan Saya beserta bukti. Tanggung jawab ongkir berbeda untuk cacat kualitas vs berubah pikiran.'],
                ['q' => 'Kapan saya menerima refund?', 'a' => 'Setelah barang retur lolos inspeksi, refund biasanya 3–15 hari kerja via metode pembayaran asli. Lihat Kebijakan Refund.'],
                ['q' => 'Bisakah mengubah atau membatalkan pesanan?', 'a' => 'Pesanan belum dikirim dapat dibatalkan/diperbarui dari Pesanan Saya atau dengan bantuan dukungan. Setelah dikirim tidak bisa dibatalkan langsung; gunakan kebijakan retur setelah diterima.'],
                ['q' => 'Bagaimana data pribadi saya dilindungi?', 'a' => 'Kami memproses data pribadi hanya seperlunya untuk transaksi dan layanan. Lihat Kebijakan Privasi dan Cookie. Kelola beberapa preferensi di pengaturan akun.'],
            ],
            'pt_BR' => [
                ['q' => 'Quando meu pedido será enviado?', 'a' => 'Pedidos em estoque geralmente saem em 1–3 dias úteis após o pagamento (feriados excluídos). Pré-venda, personalização ou prazos da página do produto valem quando indicados. Ver Guia de envio.'],
                ['q' => 'Como rastrear minha remessa?', 'a' => 'Entre e abra Meus pedidos para ver a transportadora e os eventos. Se não houver atualização por muito tempo (exceto feriados), contate o suporte com o número do pedido.'],
                ['q' => 'Como o frete é calculado? Há frete grátis?', 'a' => 'É calculado no checkout por destino, peso/volume e serviço. Limites de frete grátis ou campanhas são aplicados automaticamente quando atingidos.'],
                ['q' => 'Quais métodos de pagamento são aceitos?', 'a' => 'Aceitamos os métodos online ativados nesta loja (por exemplo PayPal). As opções exatas aparecem no checkout e no Guia de pagamento.'],
                ['q' => 'Como solicitar devolução ou troca?', 'a' => 'Leia a Política de devoluções (prazos e categorias), depois envie pós-venda em Meus pedidos com evidências. A responsabilidade do frete muda entre defeito e desistência.'],
                ['q' => 'Quando receberei o reembolso?', 'a' => 'Após a inspeção, o reembolso costuma levar 3–15 dias úteis pelo método original. Ver Política de reembolso.'],
                ['q' => 'Posso alterar ou cancelar meu pedido?', 'a' => 'Pedidos não enviados podem ser cancelados/atualizados em Meus pedidos ou com suporte. Após o envio não há cancelamento direto; use a política de devolução após a entrega.'],
                ['q' => 'Como meus dados pessoais são protegidos?', 'a' => 'Processamos dados pessoais apenas o necessário para transações e serviços. Ver Política de privacidade e de cookies. Gerencie preferências nas configurações da conta.'],
            ],
            'ur_PK' => [
                ['q' => 'میرا آرڈر کتنی جلدی بھیجا جائے گا؟', 'a' => 'اسٹاک والے آرڈرز عام طور پر ادائیگی کے بعد 1–3 کاروباری دنوں میں بھیجے جاتے ہیں (چھٹیاں چھوڑ کر)۔ پری آرڈر/کسٹم/پروڈکٹ صفحے کا وقت لاگو ہوتا ہے۔ شپنگ گائیڈ دیکھیں۔'],
                ['q' => 'میں شپمنٹ کیسے ٹریک کروں؟', 'a' => 'سائن ان کر کے میرے آرڈرز کھولیں اور کیریئر و ٹریکنگ ایونٹس دیکھیں۔ طویل عرصہ اپ ڈیٹ نہ ہو (چھٹیاں چھوڑ کر) تو آرڈر نمبر کے ساتھ سپورٹ سے رابطہ کریں۔'],
                ['q' => 'شپنگ کا حساب کیسے ہوتا ہے؟ کیا فری شپنگ ہے؟', 'a' => 'چیک آؤٹ پر منزل، وزن/حجم اور سروس کے مطابق حساب ہوتا ہے۔ فری شپنگ یا مہم کی حد پوری ہونے پر خود کار لاگو ہوتی ہے۔'],
                ['q' => 'کون سے ادائیگی کے طریقے دستیاب ہیں؟', 'a' => 'اس اسٹور فرنٹ پر فعال آن لائن ادائیگیاں (جیسے PayPal) سپورٹ ہیں۔ درست اختیارات چیک آؤٹ اور ادائیگی گائیڈ میں ہیں۔'],
                ['q' => 'واپسی یا تبادلے کی درخواست کیسے کروں؟', 'a' => 'واپسی پالیسی میں مدت اور زمرہ قواعد پڑھیں، پھر میرے آرڈرز سے ثبوت سمیت بعد از فروخت جمع کروائیں۔ معیار کی خرابی اور رائے تبدیلی میں شپنگ ذمہ داری مختلف ہے۔'],
                ['q' => 'ریفنڈ کب ملے گا؟', 'a' => 'واپس آئٹمز معائنہ پاس کرنے کے بعد عام طور پر 3–15 کاروباری دنوں میں اصل ادائیگی طریقے سے ریفنڈ۔ مکمل قواعد ریفنڈ پالیسی میں۔'],
                ['q' => 'کیا میں آرڈر تبدیل یا منسوخ کر سکتا ہوں؟', 'a' => 'نہ بھیجے گئے آرڈرز میرے آرڈرز سے منسوخ/اپ ڈیٹ یا سپورٹ مدد سے ہو سکتے ہیں۔ بھیجنے کے بعد براہ راست منسوخ نہیں؛ ڈیلیوری کے بعد واپسی پالیسی استعمال کریں۔'],
                ['q' => 'میرا ذاتی ڈیٹا کیسے محفوظ ہے؟', 'a' => 'ہم لین دین اور خدمات کے لیے ضروری حد تک ہی ذاتی ڈیٹا پراسیس کرتے ہیں۔ پرائیویسی اور کوکی پالیسی دیکھیں۔ اکاؤنٹ سیٹنگز میں کچھ ترجیحات منظم کریں۔'],
            ],
        ];
    }
}
