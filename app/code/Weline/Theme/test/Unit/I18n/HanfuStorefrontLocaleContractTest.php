<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

final class HanfuStorefrontLocaleContractTest extends TestCase
{
    /** @var array<string, string> */
    private const ENGLISH_TRANSLATIONS = [
        '云裳汉服 · Hanfu Atelier' => 'Yunshang Hanfu · Hanfu Atelier',
        '云裳汉服 · Hanfu Atelier | 水墨汉服商城首页' => 'Yunshang Hanfu · Hanfu Atelier | Ink-Wash Hanfu Boutique',
        '云裳汉服水墨中国风独立站，精选明制、宋制、唐制汉服、马面裙与传统配饰，服务日常、节庆与礼仪场景。' => 'Discover Ming, Song, and Tang dynasty Hanfu, mamian skirts, and traditional accessories for everyday wear, festivals, and ceremonies.',
        '女士汉服' => "Women's Hanfu",
        '按形制探索适合日常、节庆与礼仪场景的女士汉服' => 'Explore women\'s Hanfu by historical style for everyday wear, festivals, and ceremonies.',
        '明制汉服' => 'Ming Dynasty Hanfu',
        '端庄利落的立领、马面裙与披风层次' => 'Structured stand collars, mamian skirts, and elegant outer layers.',
        '马面裙' => 'Mamian Skirts',
        '立领长衫' => 'Stand-Collar Long Robes',
        '披风与比甲' => 'Pifeng & Bijia Layers',
        '宋制汉服' => 'Song Dynasty Hanfu',
        '清雅轻盈的褙子、百迭裙与宋裤搭配' => 'Graceful beizi jackets, baidie skirts, and Song-style trousers.',
        '褙子' => 'Beizi Jackets',
        '百迭裙' => 'Baidie Skirts',
        '宋裤' => 'Song-Style Trousers',
        '唐制汉服' => 'Tang Dynasty Hanfu',
        '明快华丽的齐胸襦裙、坦领与圆领款式' => 'Vibrant qixiong ruqun, tanling necklines, and round-collar robes.',
        '齐胸襦裙' => 'Qixiong Ruqun',
        '坦领' => 'Tanling Hanfu',
        '圆领袍' => 'Round-Collar Robes',
        '晋制汉服' => 'Jin Dynasty Hanfu',
        '飘逸交领、大袖衫与裙装组合' => 'Flowing cross-collar ruqun, wide-sleeved jackets, and layered skirts.',
        '交领襦裙' => 'Cross-Collar Ruqun',
        '大袖衫' => 'Wide-Sleeved Jackets',
        '杂裾' => 'Zaju Robes',
        '男士汉服' => "Men's Hanfu",
        '传统形制与现代日常穿着兼顾的男士系列' => 'Traditional silhouettes refined for contemporary everyday wear.',
        '节庆、礼仪与出行皆宜' => 'Made for festivals, ceremonies, and travel.',
        '道袍与直裰' => 'Daopao & Zhiduo Robes',
        '从容雅正的传统衣冠' => 'Effortless, dignified traditional dress.',
        '曳撒与贴里' => 'Yesa & Tieli Robes',
        '明制风格与利落剪裁' => 'Clean tailoring with a distinct Ming character.',
        '日常汉元素' => 'Everyday Han-Inspired Wear',
        '适合通勤与日常搭配' => 'Easy pieces for workdays and daily styling.',
        '儿童汉服' => "Children's Hanfu",
        '舒适、安全并适合成长活动的儿童传统服饰' => 'Comfortable, child-safe traditional clothing made for movement and growth.',
        '女童汉服' => "Girls' Hanfu",
        '男童汉服' => "Boys' Hanfu",
        '亲子系列' => 'Family Matching',
        '节庆礼服' => 'Festival Attire',
        '汉服配饰' => 'Hanfu Accessories',
        '以发饰、鞋履与随身雅物完成整套造型' => 'Complete the look with hair ornaments, footwear, and elegant carry pieces.',
        '发簪与发冠' => 'Hairpins & Crowns',
        '腰带与禁步' => 'Belts & Jinbu Charms',
        '绣花鞋履' => 'Embroidered Shoes',
        '团扇与包袋' => 'Round Fans & Bags',
        '按场景选购' => 'Shop by Occasion',
        '按重要时刻与穿着需求快速找到合适的一套' => 'Find the right ensemble for each moment and dress code.',
        '婚礼与礼服' => 'Wedding & Ceremonial',
        '节庆与雅集' => 'Festivals & Gatherings',
        '日常通勤' => 'Everyday & Work',
        '旅拍与演出' => 'Travel Shoots & Performance',
        '品牌与文化' => 'Our Brand & Culture',
        '认识云裳的审美、工艺与汉服穿着知识' => "Discover Yunshang's aesthetic, craftsmanship, and guides to wearing Hanfu.",
        '品牌故事' => 'Our Story',
        '汉服文化指南' => 'Hanfu Culture Guide',
        '新手购物帮助' => 'First-Time Buyer Guide',
        '云裳志' => 'Our Atelier',
        '定制与合作' => 'Bespoke & Partnerships',
        '云裳国际页脚' => 'Yunshang International Footer',
        '云裳汉服独立站全局页脚：品牌、服务、法律、社交与语言货币；标准四列保留业务扩展槽。独占底部区域。' => 'Global footer for the Yunshang Hanfu storefront, covering brand, service, legal, social, language, and currency links. Four standard columns preserve business extension slots and exclusively own the footer area.',
        '联系我们页面布局' => 'Contact Us Page Layout',
        '保留所有权利' => 'All rights reserved.',
    ];

    /** @var list<string> */
    private const ARABIC_LAUNCH_SOURCES = [
        '云裳汉服 · Hanfu Atelier',
        '云裳汉服 · Hanfu Atelier | 水墨汉服商城首页',
        '云裳汉服水墨中国风独立站，精选明制、宋制、唐制汉服、马面裙与传统配饰，服务日常、节庆与礼仪场景。',
        '女士汉服',
        '按形制探索适合日常、节庆与礼仪场景的女士汉服',
        '明制汉服',
        '端庄利落的立领、马面裙与披风层次',
        '马面裙',
        '立领长衫',
        '披风与比甲',
        '宋制汉服',
        '清雅轻盈的褙子、百迭裙与宋裤搭配',
        '褙子',
        '百迭裙',
        '宋裤',
        '唐制汉服',
        '明快华丽的齐胸襦裙、坦领与圆领款式',
        '齐胸襦裙',
        '坦领',
        '圆领袍',
        '晋制汉服',
        '飘逸交领、大袖衫与裙装组合',
        '交领襦裙',
        '大袖衫',
        '杂裾',
        '男士汉服',
        '传统形制与现代日常穿着兼顾的男士系列',
        '节庆、礼仪与出行皆宜',
        '道袍与直裰',
        '从容雅正的传统衣冠',
        '曳撒与贴里',
        '明制风格与利落剪裁',
        '日常汉元素',
        '适合通勤与日常搭配',
        '儿童汉服',
        '舒适、安全并适合成长活动的儿童传统服饰',
        '女童汉服',
        '男童汉服',
        '亲子系列',
        '节庆礼服',
        '汉服配饰',
        '以发饰、鞋履与随身雅物完成整套造型',
        '发簪与发冠',
        '腰带与禁步',
        '绣花鞋履',
        '团扇与包袋',
        '按场景选购',
        '按重要时刻与穿着需求快速找到合适的一套',
        '婚礼与礼服',
        '节庆与雅集',
        '日常通勤',
        '旅拍与演出',
        '品牌与文化',
        '认识云裳的审美、工艺与汉服穿着知识',
        '品牌故事',
        '汉服文化指南',
        '新手购物帮助',
        '云裳志',
        '定制与合作',
        '云裳国际页脚',
        '云裳汉服独立站全局页脚：品牌、服务、法律、社交与语言货币；标准四列保留业务扩展槽。独占底部区域。',
        '联系我们页面布局',
        '保留所有权利',
        '桃园清梦 · 明制花鸟套装',
        '烟粉花鸟，上衣与马面裙可选',
        '桃园清梦米白粉色明制上衣与马面裙套装',
        '神龙吟 · 妆花马面裙',
        '黑红妆花，马面裙与飞机袖可选',
        '神龙吟黑红妆花明制马面裙造型',
        '醉梦夕风 · 仙鹤织金',
        '仙鹤织金，单裙与套装可选',
        '醉梦夕风深色仙鹤织金马面裙造型',
        '浏览系列',
        '按汉服品类选购',
        '从准确形制开始选择',
        '女装',
        '男装',
        '童装',
        '配饰',
        '套装与场景',
        '女装汉服分类图，展示女性汉服的裙装层次',
        '男装汉服分类图，展示男性袍衫轮廓',
        '童装汉服分类图，展示儿童尺度汉服',
        '汉服配饰分类图，展示头饰、佩饰与鞋履',
        '汉服套装分类图，展示完整上下装搭配',
        '织艺谱系 · Textile Heritage',
        '云锦',
        '宋锦',
        '蜀锦',
        '苏绣',
        '妆花',
        '花罗',
        '合作品牌',
        '跳转到主要内容',
        '欢迎光临！满 ¥299 包邮 · 新客首单立减',
        '帮助中心',
        '订单跟踪',
        '配送至',
        '全部',
        'all',
        '热搜',
        '收藏',
        '我的收藏',
        '登录',
        '注册',
        '退货',
        '与我的订单',
        '购物车',
        '博客',
        '今日特价',
        '客户服务',
        '浏览',
        '首页',
        '所有分类',
        '全部分类',
        '查看全部',
        '全部商品',
        '浏览全部商品',
        '热销榜',
        '新品上架',
        '新品上市',
        '我的账户',
        '我的订单',
        '设置',
        '搜索汉服、形制与配饰...',
        '展开搜索',
        '我的',
        '我的菜单',
        '账户及心愿单',
        '账户',
        '账户设置',
        '退出登录',
        '语言',
        '语言选项',
        '切换语言',
        '货币',
        '货币选项',
        '切换货币',
        '关闭菜单',
        '加入购物车',
        '立即选购',
        '立即抢购',
        '热销产品',
        '最受欢迎的商品',
        '安全支付',
        '无忧退款',
        '免费配送',
        '免运费',
        '全天候客服',
        '回到顶部',
        '返回顶部',
        '关于我们',
        '新闻中心',
        '供应商合作',
        '我要推广',
        '支付与账户',
        '活动中',
        '货币与汇率',
        '配送说明',
        '退换政策',
        '联系客服',
        '使用条件',
        '隐私声明',
        'Cookie 政策',
        'Cookie政策',
        '广告偏好',
        '站点偏好',
        '法律与政策',
        '热门入口',
        '账户与服务',
        '商品对比',
        '推荐产品',
        '为您精选的优质商品',
        '新品',
        '促销',
        '限时特惠！全场满300减50',
        'Pinterest',
        'TikTok',
        'Douyin',
        '抖音',
        '上一张',
        '下一张',
        '第%d张',
        '上一组',
        '下一组',
    ];

    /** @var array<string, string> */
    private const ARABIC_RUNTIME_TRANSLATIONS = [
        '搜索' => 'بحث',
        '搜索类型' => 'نوع البحث',
        'NEW' => 'جديد',
        'SALE' => 'تخفيض',
        '加入收藏' => 'أضف إلى المفضلة',
        '加入对比' => 'أضف إلى المقارنة',
        '快速查看' => 'عرض سريع',
        '关闭' => 'إغلاق',
        '排名' => 'الترتيب',
        '商品' => 'منتج',
        'Product' => 'منتج',
        '登录 / 注册' => 'تسجيل الدخول / إنشاء حساب',
        '立即购买' => 'اشترِ الآن',
        '全部%{1}' => 'كل %{1}',
        '搜索商品...' => 'ابحث عن المنتجات...',
        '云裳 Hanfu Atelier' => 'يونشانغ · مشغل الهانفو',
        '您好' => 'مرحبًا',
        '您好, %{1}' => 'مرحبًا، %{1}',
        '地址管理' => 'إدارة العناوين',
        '欢迎回来' => 'مرحبًا بعودتك',
        '快捷导航' => 'تنقل سريع',
        '打开菜单' => 'فتح القائمة',
        '更多' => 'المزيد',
        '店铺通知' => 'إشعار المتجر',
        '分类导航' => 'تصفح الفئات',
        '更多分类' => 'مزيد من الفئات',
        '打开所有类别菜单' => 'فتح قائمة جميع الفئات',
        'All' => 'الكل',
        'Search' => 'بحث',
        'Search type' => 'نوع البحث',
        'Favorites' => 'المفضلة',
        'My Favorites' => 'مفضلتي',
        'Register' => 'إنشاء حساب',
        'All Categories' => 'جميع الفئات',
        'Open all categories menu' => 'فتح قائمة جميع الفئات',
        'Hanfu' => 'هانفو',
        'Add to Favorites' => 'أضف إلى المفضلة',
        'Add to Compare' => 'أضف إلى المقارنة',
        'Quick View' => 'عرض سريع',
        'Buy Now' => 'اشترِ الآن',
        'Close' => 'إغلاق',
        'About Us' => 'من نحن',
        '活动' => 'العروض',
        'Active' => 'العروض',
        'Cookie Policy' => 'سياسة ملفات تعريف الارتباط',
        'Style' => 'الطراز',
        'Occasion' => 'المناسبة',
        'Material' => 'الخامة',
        'Spec products' => 'منتجات بمواصفات',
        'Ming' => 'مينغ',
        'Tang' => 'تانغ',
        'Song' => 'سونغ',
        'Daily' => 'يومي',
        'Wedding' => 'زفاف',
        'Festival' => 'احتفالات',
        'Restoration' => 'إحياء تاريخي',
        'Polyester' => 'بوليستر',
        'Silk' => 'حرير',
        'Zhijin' => 'نسيج ذهبي',
        'Chiffon' => 'شيفون',
        'Browse ming products and accessories' => 'تصفّح منتجات وإكسسوارات طراز مينغ',
        'Browse tang products and accessories' => 'تصفّح منتجات وإكسسوارات طراز تانغ',
        'Browse song products and accessories' => 'تصفّح منتجات وإكسسوارات طراز سونغ',
        'Browse daily products and accessories' => 'تصفّح منتجات وإكسسوارات الاستخدام اليومي',
        'Browse wedding products and accessories' => 'تصفّح منتجات وإكسسوارات الزفاف',
        'Browse festival products and accessories' => 'تصفّح منتجات وإكسسوارات الاحتفالات',
        'Browse restoration products and accessories' => 'تصفّح منتجات وإكسسوارات الإحياء التاريخي',
        'Browse polyester products and accessories' => 'تصفّح منتجات وإكسسوارات البوليستر',
        'Browse silk products and accessories' => 'تصفّح منتجات وإكسسوارات الحرير',
        'Browse zhijin products and accessories' => 'تصفّح منتجات وإكسسوارات النسيج الذهبي',
        'Browse chiffon products and accessories' => 'تصفّح منتجات وإكسسوارات الشيفون',
    ];

    /** @var list<string> */
    private const RUNTIME_ALIAS_KEYS = [
        'All',
        'Search type',
        'Favorites',
        'My Favorites',
        'Register',
        'All Categories',
        'Open all categories menu',
        'Hanfu',
        'Add to Favorites',
        'Add to Compare',
        'Quick View',
        'Buy Now',
        'Close',
        'About Us',
        'Active',
        'Cookie Policy',
        'Style',
        'Occasion',
        'Material',
        'Spec products',
        'Ming',
        'Tang',
        'Song',
        'Daily',
        'Wedding',
        'Festival',
        'Restoration',
        'Polyester',
        'Silk',
        'Zhijin',
        'Chiffon',
        'Browse ming products and accessories',
        'Browse tang products and accessories',
        'Browse song products and accessories',
        'Browse daily products and accessories',
        'Browse wedding products and accessories',
        'Browse festival products and accessories',
        'Browse restoration products and accessories',
        'Browse polyester products and accessories',
        'Browse silk products and accessories',
        'Browse zhijin products and accessories',
        'Browse chiffon products and accessories',
    ];

    /** @var array<string, string> */
    private const CHECKOUT_ARABIC_PUBLIC_SURFACE = [
        '立即购买' => 'اشترِ الآن',
        '正在前往结账...' => 'جارٍ الانتقال إلى الدفع...',
        '结账准备失败' => 'تعذر تجهيز عملية الدفع',
        'Buy Now' => 'اشترِ الآن',
    ];

    /** @var array<string, string> */
    private const PRODUCT_ARABIC_PUBLIC_SURFACE = [
        '首页' => 'الرئيسية',
        '排序' => 'الترتيب',
        '全部商品' => 'جميع أزياء الهانفو',
        '汉服商品' => 'منتجات الهانفو',
        '本站人气排行' => 'الأكثر رواجًا في متجرنا',
        '排名' => 'الترتيب',
        '商品信息暂不可用' => 'معلومات المنتج غير متاحة حاليًا.',
        '颜色' => 'اللون',
        '尺码' => 'المقاس',
        '材质' => 'الخامة',
        '有货' => 'متوفر',
        '暂时缺货' => 'غير متوفر',
        '价格' => 'السعر',
        '规格' => 'الخيارات',
        '数量' => 'الكمية',
        '加入购物车' => 'أضف إلى السلة',
        '立即结账' => 'الدفع الآن',
        '安全交易保障' => 'معاملة آمنة',
        '关于该商品' => 'حول هذا المنتج',
        '技术细节' => 'التفاصيل التقنية',
        'Customers also viewed' => 'شاهد العملاء أيضًا',
        'Website' => 'الموقع',
        '切换' => 'تبديل',
        'Hanfu' => 'هانفو',
        'Product' => 'منتج',
        'Buy Now' => 'اشترِ الآن',
    ];

    public function testArabicRuntimeCatalogTranslatesPersistedWidgetDefaultsAndEnglishAliases(): void
    {
        $ar = $this->loadLocale('ar_SA');

        foreach (self::ARABIC_RUNTIME_TRANSLATIONS as $source => $arabic) {
            self::assertArrayHasKey($source, $ar, "Missing ar_SA runtime source: {$source}");
            self::assertSame($arabic, $ar[$source], "Unexpected ar_SA runtime translation: {$source}");
            self::assertMatchesRegularExpression('/\\p{Arabic}/u', $ar[$source], "Non-Arabic ar_SA runtime translation: {$source}");
        }
    }

    public function testExplicitMenuLocaleValuesStillPassThroughWidgetI18n(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/AllMenu/MenuTreeNormalizer.php',
        );

        self::assertStringContainsString('$source = $localized;', $source);
        self::assertStringContainsString('return WidgetI18n::label($source);', $source);
        self::assertStringContainsString(
            "#/(ar_SA|en_US|zh_Hans_CN|zh_CN)(?:/|$)#",
            $source,
        );
        self::assertStringNotContainsString('return $localized;', $source);
    }

    public function testWidgetI18nPrefersTheRequestScopedLocaleInPersistentWorkers(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Helper/WidgetI18n.php',
        );

        $requestLocale = strpos($source, 'RequestContext::locale()');
        $legacyStateLocale = strpos($source, 'State::getLangLocal()');

        self::assertNotFalse($requestLocale, 'WidgetI18n must read the request-scoped locale.');
        self::assertNotFalse($legacyStateLocale, 'WidgetI18n must keep its CLI/legacy locale fallback.');
        self::assertLessThan(
            $legacyStateLocale,
            $requestLocale,
            'The request-scoped locale must win before process/global State fallbacks.',
        );
    }

    public function testThemeProcessCacheResetterClearsStorefrontHeaderHotCache(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Api/Runtime/ProcessCacheResetter.php',
        );

        self::assertGreaterThanOrEqual(
            2,
            substr_count($source, 'StorefrontScopeHotCache::resetProcessCache();'),
            'Explicit cache clear and memory cleanup must both reset storefront header fragments.',
        );
        self::assertStringContainsString(
            'return 8;',
            $source,
            'The explicit reset count must include StorefrontScopeHotCache.',
        );
    }

    public function testWidgetI18nRetriesLowercaseCatalogValuesThroughTheirTitleCaseAlias(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Helper/WidgetI18n.php',
        );

        self::assertStringContainsString('$titleCaseAlias = ucfirst($key);', $source);
        self::assertStringContainsString("preg_match('/^[a-z]/', \$key)", $source);
    }

    public function testRuntimeAliasKeysAreAlignedAcrossThemeCatalogs(): void
    {
        foreach (['zh_Hans_CN', 'en_US', 'ar_SA'] as $locale) {
            $catalog = $this->loadLocale($locale);
            foreach (self::RUNTIME_ALIAS_KEYS as $source) {
                self::assertArrayHasKey($source, $catalog, "Missing {$locale} runtime alias: {$source}");
                self::assertNotSame('', trim($catalog[$source]), "Empty {$locale} runtime alias: {$source}");
            }
        }
    }

    public function testCheckoutArabicCatalogCoversBuyNowStates(): void
    {
        $ar = $this->loadCheckoutLocale();

        foreach (self::CHECKOUT_ARABIC_PUBLIC_SURFACE as $source => $arabic) {
            self::assertArrayHasKey($source, $ar, "Missing Checkout ar_SA source: {$source}");
            self::assertSame($arabic, $ar[$source], "Unexpected Checkout ar_SA translation: {$source}");
            self::assertMatchesRegularExpression('/\\p{Arabic}/u', $ar[$source], "Non-Arabic Checkout ar_SA translation: {$source}");
        }
    }

    public function testProductArabicCatalogCoversThePublicStorefrontSurface(): void
    {
        $ar = $this->loadProductLocale();

        foreach (self::PRODUCT_ARABIC_PUBLIC_SURFACE as $source => $arabic) {
            self::assertArrayHasKey($source, $ar, "Missing Product ar_SA source: {$source}");
            self::assertSame($arabic, $ar[$source], "Unexpected Product ar_SA translation: {$source}");
            self::assertMatchesRegularExpression('/\\p{Arabic}/u', $ar[$source], "Non-Arabic Product ar_SA translation: {$source}");
        }
    }

    public function testHanfuStorefrontCopyHasAlignedLaunchReadyChineseAndEnglishTranslations(): void
    {
        $zh = $this->loadLocale('zh_Hans_CN');
        $en = $this->loadLocale('en_US');

        foreach (self::ENGLISH_TRANSLATIONS as $source => $english) {
            self::assertArrayHasKey($source, $zh, "Missing zh_Hans_CN source: {$source}");
            self::assertSame($source, $zh[$source], "Unexpected zh_Hans_CN translation: {$source}");
            self::assertArrayHasKey($source, $en, "Missing en_US source: {$source}");
            self::assertSame($english, $en[$source], "Unexpected en_US translation: {$source}");
        }
    }

    public function testArabicLaunchCatalogCoversEveryDefaultStorefrontPhrase(): void
    {
        $zh = $this->loadLocale('zh_Hans_CN');
        $en = $this->loadLocale('en_US');
        $ar = $this->loadLocale('ar_SA');

        foreach (self::ARABIC_LAUNCH_SOURCES as $source) {
            self::assertArrayHasKey($source, $zh, "Missing zh_Hans_CN source: {$source}");
            self::assertArrayHasKey($source, $en, "Missing en_US source: {$source}");
            self::assertArrayHasKey($source, $ar, "Missing ar_SA source: {$source}");
            self::assertNotSame('', trim($ar[$source]), "Empty ar_SA translation: {$source}");
            self::assertNotSame($source, $ar[$source], "Untranslated ar_SA source: {$source}");
            self::assertMatchesRegularExpression('/\\p{Arabic}/u', $ar[$source], "Non-Arabic ar_SA translation: {$source}");
        }
    }

    /** @return array<string, string> */
    private function loadLocale(string $locale): array
    {
        return $this->loadCatalog(dirname(__DIR__, 3) . '/i18n/' . $locale . '.csv');
    }

    /** @return array<string, string> */
    private function loadCheckoutLocale(): array
    {
        return $this->loadCatalog(dirname(__DIR__, 4) . '/Checkout/i18n/ar_SA.csv');
    }

    /** @return array<string, string> */
    private function loadProductLocale(): array
    {
        return $this->loadCatalog(dirname(__DIR__, 4) . '/Product/i18n/ar_SA.csv');
    }

    /** @return array<string, string> */
    private function loadCatalog(string $path): array
    {
        $handle = fopen($path, 'rb');
        self::assertIsResource($handle, "Unable to open locale catalog: {$path}");

        $catalog = [];
        while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            $source = preg_replace('/^\xEF\xBB\xBF/', '', (string)($row[0] ?? ''));
            if ($source === '') {
                continue;
            }
            $catalog[$source] = (string)($row[1] ?? '');
        }
        fclose($handle);

        return $catalog;
    }
}
