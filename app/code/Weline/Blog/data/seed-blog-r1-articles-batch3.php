<?php

declare(strict_types=1);

/**
 * Seed R1 batch-3: Hanfu encyclopedia pillars + Amayun brand/factory trust posts (zh + en).
 *
 * Usage: php app/code/Weline/Blog/data/seed-blog-r1-articles-batch3.php
 */

use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogCategoryAdminService;
use Weline\Blog\Service\BlogPostAdminService;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 4) . '/bootstrap.php';

const WEBSITE_ID = 0;
const COVER_BASE = '/media/blog/hanfu/articles/covers';
const AUTHOR = 'Amayun Editorial';

/** @return array<string,int> */
function categoryIdMap(): array
{
    $admin = ObjectManager::getInstance(BlogCategoryAdminService::class);
    $map = [];
    foreach ($admin->tree(WEBSITE_ID, 'zh_Hans_CN') as $node) {
        $slug = trim((string)($node['code'] ?? ''));
        $id = (int)($node['category_id'] ?? 0);
        if ($slug !== '' && $id > 0) {
            $map[$slug] = $id;
        }
    }

    return $map;
}

function h2(string $t): string
{
    return '<h2>' . htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>';
}

function p(string $t): string
{
    return '<p>' . htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
}

function ul(array $items): string
{
    $lis = '';
    foreach ($items as $item) {
        $lis .= '<li>' . htmlspecialchars((string)$item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
    }

    return '<ul>' . $lis . '</ul>';
}

function table(array $headers, array $rows): string
{
    $html = '<table><thead><tr>';
    foreach ($headers as $h) {
        $html .= '<th>' . htmlspecialchars((string)$h, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . htmlspecialchars((string)$cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
        }
        $html .= '</tr>';
    }

    return $html . '</tbody></table>';
}

function ctaShop(string $locale): string
{
    if ($locale === 'en_US') {
        return p('Ready to shop by silhouette? Browse Amayun’s factory-direct Hanfu catalog—verified origin supply, fair pricing, and quality checks built for real wear.');
    }

    return p('想按形制选购？浏览阿玛云源头工厂直销目录——产地可追溯、性价比清晰、按可穿服装标准质检。');
}

/** @return list<array<string,mixed>> */
function articles(): array
{
    return [
        [
            'category' => 'hanfu-guide',
            'cover' => 'guide-overview',
            'slug' => 'what-is-hanfu-complete-guide',
            'keywords' => 'what is hanfu,hanfu guide,Chinese traditional clothing,汉服百科',
            'zh' => [
                'title' => '什么是汉服？2026 完整入门百科（形制·朝代·场合）',
                'excerpt' => '用一篇搞清汉服定义、与影楼装差异、主流形制地图，以及如何连接到购买与工厂直销。',
                'content' => h2('一句话定义')
                    . p('汉服是汉民族传统服饰体系：以交领右衽、平直裁剪、系带闭合等结构特征为线索，并有历代文物与图像可对照——它不是泛指一切「看起来很古风」的裙子。')
                    . h2('先分清三件事')
                    . ul([
                        '汉服：有形制与礼仪语境的传统服饰系统。',
                        '新中式 / 汉元素：现代时装借用东方符号，结构可大幅改良。',
                        '影楼装 / 古装剧服：服务拍摄与表演，常与正统结构不一致。',
                    ])
                    . h2('本站百科怎么读')
                    . ul([
                        '形制入门：襦裙、马面、圆领袍等一眼辨。',
                        '朝代演变：唐、宋、明审美与结构变化。',
                        '面料与工艺：提花、刺绣与绿色制造。',
                        '礼仪与场合：日常、婚礼、节令怎么穿。',
                    ])
                    . h2('和购买的关系')
                    . p('先懂形制，再下单，能避开标题党。阿玛云把百科与工厂直销目录对齐：学完可直接按女装/男装/配饰类目选购。')
                    . ctaShop('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'What Is Hanfu? A Complete 2026 Beginner Encyclopedia',
                'excerpt' => 'Definition, vs costume dresses, a map of major silhouettes, and how to connect learning with factory-direct shopping.',
                'content' => h2('One-line definition')
                    . p('Hanfu is the traditional dress system of the Han people—cross-collar right lapel, flat cutting, and sash closures—with archaeological and pictorial references. It is not every “ancient-looking” skirt.')
                    . h2('Separate three ideas')
                    . ul([
                        'Hanfu: structured traditional system with etiquette context.',
                        'New Chinese / Han-element: modern fashion borrowing motifs.',
                        'Studio/costume dresses: built for photos and stage, often not classical structure.',
                    ])
                    . h2('How to read this hub')
                    . ul([
                        'Styles: ruqun, mamian, yuanling at a glance.',
                        'Dynasties: Tang–Song–Ming shifts.',
                        'Fabrics & craft: weaves, embroidery, responsible making.',
                        'Occasions: daily, wedding, festivals.',
                    ])
                    . h2('Link to shopping')
                    . p('Learn the silhouette first, then buy—Amayun aligns guides with factory-direct catalog paths for women, men and accessories.')
                    . ctaShop('en_US'),
            ],
        ],
        [
            'category' => 'hanfu-styles',
            'cover' => 'styles-intro',
            'slug' => 'hanfu-styles-ruqun-mamian-yuanling',
            'keywords' => 'ruqun,mamian skirt,yuanlingpao,hanfu types,汉服形制',
            'zh' => [
                'title' => '汉服形制速查：襦裙、马面裙、圆领袍一眼辨',
                'excerpt' => '新手最常用的形制对照表：上下分裁还是袍服、腰线高低、领型差异，并链接到可购类目。',
                'content' => h2('为什么从形制开始')
                    . p('平台标题常写「汉服连衣裙」。形制才是可检索、可对比、可质检的单位——买错形制比买错颜色更难补救。')
                    . h2('高频形制对照')
                    . table(
                        ['形制', '结构要点', '常见场景', '选购提示'],
                        [
                            ['齐胸/齐腰襦裙', '上衣+下裙，腰线位置不同', '日常、出游、拍照', '看平铺腰头与裙褶'],
                            ['袄裙', '加厚短袄+裙', '秋冬、礼仪感日常', '注意领型与袖型'],
                            ['马面裙', '两片式结构，前后光面', '明制风、通勤国风', '看马面宽与褶距'],
                            ['曲裾/深衣', '绕襟或上下连属感强', '礼仪、展演', '结构复杂，认准平铺'],
                            ['圆领袍', '圆领、袍服感', '男装常服/礼服向', '看通袖与下摆'],
                            ['褙子/比甲', '外搭开衫式', '叠穿、季节过渡', '长度与内搭匹配'],
                        ],
                    )
                    . h2('避雷一句')
                    . p('没有平铺结构图、只有一张网红摆拍的，先别下单。阿玛云商品按形制挂类目，方便对照本文选购。')
                    . ctaShop('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Hanfu Styles Cheat Sheet: Ruqun, Mamian, Yuanling',
                'excerpt' => 'A beginner table of the most shopped silhouettes—waistline, collar and construction—mapped to buyable categories.',
                'content' => h2('Start with form, not buzzwords')
                    . p('Listings say “Hanfu dress.” Silhouette is what you can compare, measure and QC—wrong form is harder to fix than wrong color.')
                    . h2('High-frequency map')
                    . table(
                        ['Form', 'Structure', 'Occasions', 'Buy tip'],
                        [
                            ['Qi-xiong / Qi-yao ruqun', 'Top + skirt, different waist heights', 'Daily, travel, photos', 'Check waistband & pleats flat'],
                            ['Aoqun', 'Padded short jacket + skirt', 'Cooler weather, dressy daily', 'Collar & sleeve type'],
                            ['Mamian skirt', 'Two-panel construction, flat faces', 'Ming vibe, commute guofeng', 'Panel width & pleat spacing'],
                            ['Quju / deep-robe feel', 'Wrapped or continuous cut', 'Ceremony, display', 'Demand clear flat structure'],
                            ['Yuanlingpao', 'Round collar robe', 'Men’s regular/formal lean', 'Sleeve span & hem'],
                            ['Beizi / bijia', 'Open outer layer', 'Layering seasons', 'Length vs inner layers'],
                        ],
                    )
                    . h2('One red flag')
                    . p('No flat construction photos—pause. Amayun catalogs by silhouette so this cheat sheet maps to real shelves.')
                    . ctaShop('en_US'),
            ],
        ],
        [
            'category' => 'hanfu-dynasties',
            'cover' => 'dynasties',
            'slug' => 'hanfu-through-dynasties-tang-song-ming',
            'keywords' => 'Tang hanfu,Song hanfu,Ming hanfu,dynasty clothing,汉服朝代',
            'zh' => [
                'title' => '汉服朝代演变：唐、宋、明审美与结构怎么变',
                'excerpt' => '用可购物的语言梳理唐华丽、宋清雅、明挺括——并说明新中式与正统形制的边界。',
                'content' => h2('为什么要懂朝代')
                    . p('「唐风」「明制」是买家高频搜索词，也是版型与配色预期的来源。搞清时代气质，沟通工厂与避雷更快。')
                    . h2('三段速写')
                    . ul([
                        '唐：色彩饱和、线条舒展，齐胸衫裙与大袖常被当代复现（注意命名与结构细节）。',
                        '宋：线条更收敛，褙子、对襟与清雅配色适合日常通勤向。',
                        '明：袄裙、马面、立领等挺括结构，当代国风出镜率很高。',
                    ])
                    . h2('新中式放哪里')
                    . p('新中式是当代设计语言，可借鉴明制廓形或刺绣，但不必声称「复原某墓」。买的时候问清楚：要考据结构，还是要时尚可穿。')
                    . h2('选购提示')
                    . p('先定时代气质，再定形制 SKU。阿玛云目录按形制组织，朝代作为内容标签帮助你描述需求。')
                    . ctaShop('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Hanfu Across Dynasties: Tang, Song, Ming in Shopper Language',
                'excerpt' => 'Tang brilliance, Song restraint, Ming structure—plus where New Chinese Style sits versus classical forms.',
                'content' => h2('Why dynasties matter')
                    . p('“Tang style” and “Ming cut” are high-intent search phrases and set expectations for pattern and color.')
                    . h2('Three sketches')
                    . ul([
                        'Tang: saturated color, open lines; chest-high sets and wide sleeves often revived (check naming vs structure).',
                        'Song: quieter lines; beizi and soft palettes fit commute-friendly looks.',
                        'Ming: aoqun, mamian, standing collars—high modern guofeng visibility.',
                    ])
                    . h2('Where New Chinese Style fits')
                    . p('It is a contemporary design language. It may borrow Ming volumes or embroidery without claiming tomb-accurate reconstruction. Decide: archaeology or wearable fashion.')
                    . h2('Buying tip')
                    . p('Pick era mood first, then silhouette SKU. Amayun organizes by form; dynasty language helps you brief the look.')
                    . ctaShop('en_US'),
            ],
        ],
        [
            'category' => 'hanfu-fabrics',
            'cover' => 'fabrics-craft',
            'slug' => 'hanfu-fabrics-embroidery-green-manufacturing',
            'keywords' => 'hanfu fabric,embroidery,jacquard,green manufacturing,汉服面料',
            'zh' => [
                'title' => '汉服面料与工艺：提花、刺绣与绿色机械制造',
                'excerpt' => '读懂成分、克重、刺绣与质检标准，并了解阿玛云「手工 + 绿色机械」如何兼顾温度与稳定性。',
                'content' => h2('面料怎么读标签')
                    . ul([
                        '成分：棉、麻、丝、化纤混纺决定透气、垂感与护理。',
                        '克重/厚度：影响季节与是否起皱。',
                        '织造：平纹、提花、妆花等决定纹理成本。',
                    ])
                    . h2('工艺看点')
                    . ul([
                        '刺绣：手绣与机绣在细腻度、工时与价格上不同，描述应诚实。',
                        '染色：色牢度与洗后掉色风险要在护理说明中可见。',
                        '版型缝制：袖笼、领口、裙褶均匀度决定「上身是否廉价」。',
                    ])
                    . h2('阿玛云怎么做')
                    . p('关键装饰与手感环节保留手工温度；裁剪、锁边、稳定工序结合绿色机械产线，提升一致性与交付。目标不是一次性快时尚，而是可复购的服装品质。')
                    . ctaShop('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Hanfu Fabrics & Craft: Jacquard, Embroidery, Green Making',
                'excerpt' => 'How to read fiber, weight and embroidery claims—and how Amayun pairs handmade finishing with green mechanical production.',
                'content' => h2('Reading labels')
                    . ul([
                        'Fiber: cotton, linen, silk, blends drive breathability and care.',
                        'Weight/hand: seasonality and wrinkle behavior.',
                        'Weave: plain vs jacquard changes texture and cost.',
                    ])
                    . h2('Craft checkpoints')
                    . ul([
                        'Embroidery: hand vs machine differs in detail, time and price—claims should be honest.',
                        'Dyeing: colorfastness belongs in care notes.',
                        'Construction: armhole, collar and pleat evenness decide “cheap” vs “garment”.',
                    ])
                    . h2('How Amayun builds')
                    . p('Handmade where touch and decoration matter; green mechanical lines for cutting, finishing consistency and delivery. Built for repurchase—not disposable fashion.')
                    . ctaShop('en_US'),
            ],
        ],
        [
            'category' => 'hanfu-occasions',
            'cover' => 'occasions',
            'slug' => 'hanfu-occasions-daily-wedding-festival',
            'keywords' => 'hanfu wedding,festival hanfu,daily hanfu,汉服礼仪',
            'zh' => [
                'title' => '汉服礼仪与场合：日常、婚礼、节令怎么穿',
                'excerpt' => '按场景给出可执行的着装建议，并连接到套装与配饰类目，避免「衣服对了场合错了」。',
                'content' => h2('日常通勤')
                    . p('优先宋明清雅或改良可叠穿：褙子、短袄、马面搭配现代鞋履时注意整体协调；面料选不易皱、好打理的。')
                    . h2('婚礼与仪式')
                    . p('婚服、敬茶、晚宴对色彩与完整度要求更高：主服可定制叙事，亲友向可用工厂直销形制套装控制预算。')
                    . h2('节令与出游')
                    . p('春节、中秋、花朝等节点适合主题配色与成套配饰；注意场地行走与天气，裙长与披帛安全性优先。')
                    . h2('礼仪提醒')
                    . p('尊重场合比堆砌元素重要。不确定时选结构清楚、配色克制的一套，比夸张网红款更稳妥。')
                    . ctaShop('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Hanfu Occasions: Daily, Wedding & Festival Dressing',
                'excerpt' => 'Practical occasion guides linked to sets and accessories—so the silhouette matches the moment.',
                'content' => h2('Daily commute')
                    . p('Favor Song/Ming quiet lines or layerable pieces; choose fabrics that travel well. Balance mamian/beizi with modern shoes carefully.')
                    . h2('Weddings & ceremonies')
                    . p('Color and completeness matter more. Heroes can be couture; bridal party and guests can use factory-direct sets to control budget.')
                    . h2('Festivals & outings')
                    . p('Seasonal palettes and accessory sets shine—prioritize walking safety, hem length and weather.')
                    . h2('Etiquette note')
                    . p('Respect the occasion over stacking motifs. A clear structure and restrained palette usually beats viral excess.')
                    . ctaShop('en_US'),
            ],
        ],
        [
            'category' => 'brand-about',
            'cover' => 'about-amayun',
            'slug' => 'amayun-technology-company-story',
            'keywords' => 'Amayun Technology,阿玛云科技,factory direct hanfu brand story',
            'zh' => [
                'title' => '阿玛云科技公司故事：2024 起步，为物美价廉而来',
                'excerpt' => '阿玛云科技有限公司注册于 2024 年。宗旨是为全球客户提供高性价比汉服与国风产品——源头工厂、质量可检、价格诚实。',
                'content' => h2('我们是谁')
                    . p('阿玛云科技有限公司（Amayun Technology）注册于 2024 年。团队相信：好的汉服不该只活在溢价叙事里，也可以以工厂直销的方式，让更多人穿得起、穿得对。')
                    . h2('宗旨')
                    . ul([
                        '物美价廉：同规格追求更合理的到手价。',
                        '质量有保障：按服装而非一次性快时尚标准质检。',
                        '信息透明：产地、工艺与形制说明可读。',
                    ])
                    . h2('我们如何选货')
                    . p('从原产地逐户走访确认优质货源，与可靠厂家建立合作，再以直销模式触达客户。汉服是我们验证过的核心品类，同时也会扩展全球民族服饰的内容与精选供给。')
                    . h2('你能在站内找到什么')
                    . p('形制清楚的商品目录、竞品对比与百科内容、以及品牌与工厂故事。先懂再买，是我们希望的体验。')
                    . ctaShop('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Amayun Technology Story: Started 2024 for Fair-Price Quality',
                'excerpt' => 'Amayun Technology Co., Ltd. registered in 2024 to deliver value Hanfu worldwide—factory-direct, inspectable quality, honest pricing.',
                'content' => h2('Who we are')
                    . p('Amayun Technology Co., Ltd. (est. 2024). We believe good Hanfu should not live only behind premium markups—factory-direct can make correct forms more accessible.')
                    . h2('Mandate')
                    . ul([
                        'Fair price at comparable specs.',
                        'Garment-grade QC—not disposable fashion logic.',
                        'Readable origin, craft and silhouette information.',
                    ])
                    . h2('How we source')
                    . p('Origin visits, verified factory partners, then direct-to-customer sales. Hanfu is our proven core; we also publish world ethnic dress education alongside curated supply.')
                    . h2('What you find here')
                    . p('Silhouette-clear catalog, competitor comparisons, encyclopedia guides, and factory stories—learn first, then buy.')
                    . ctaShop('en_US'),
            ],
        ],
        [
            'category' => 'brand-partners',
            'cover' => 'partners-origins',
            'slug' => 'amayun-origin-visits-factory-partners',
            'keywords' => 'hanfu factory,1688 partners,origin visit,曹县汉服货源',
            'zh' => [
                'title' => '产地走访与合作伙伴：我们如何确认优质货源',
                'excerpt' => '原产地逐户确认、1688 合作厂家与车间实景——把「源头」说清楚，而不是只放一张网图。',
                'content' => h2('为什么要走访')
                    . p('汉服供应链分层严重：同图可能来自完全不同的车缝与面料批次。走访是为了看见车间、沟通质检与交期，而不是只看链接价格。')
                    . h2('合作原则')
                    . ul([
                        '形制与版型可复核，能量产稳定。',
                        '面料与工艺描述可验证，拒绝虚假「真丝」话术。',
                        '愿意提供车间与过程可见性，接受抽检。',
                    ])
                    . h2('1688 与产业带')
                    . p('产业带与 1688 是重要信息面，但不是唯一标准。我们把线上资料与实地走访交叉验证，再决定哪些伙伴进入直销货盘。')
                    . h2('对客户的承诺')
                    . p('持续公开可展示的合作与车间内容，让「工厂直销」有证据，而不只是口号。')
                    . ctaShop('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Origin Visits & Partners: How We Verify Supply',
                'excerpt' => 'Door-to-door origin checks, 1688 partners and workshop visibility—so “factory-direct” is evidence, not a slogan.',
                'content' => h2('Why visit')
                    . p('Hanfu supply is layered: the same photo can hide different sewing and fabric lots. Visits verify workshops, QC and lead times—not only link prices.')
                    . h2('Partner rules')
                    . ul([
                        'Silhouette/patterns can be audited and produced stably.',
                        'Fabric/craft claims are verifiable—no fake “silk” talk.',
                        'Workshop visibility and sampling accepted.',
                    ])
                    . h2('1688 & industrial belts')
                    . p('Marketplaces are useful signals, not the only standard. We cross-check online data with on-site visits before a partner enters the direct catalog.')
                    . h2('Promise to shoppers')
                    . p('Keep publishing showable partner and workshop evidence so factory-direct stays accountable.')
                    . ctaShop('en_US'),
            ],
        ],
        [
            'category' => 'brand-manufacturing',
            'cover' => 'manufacturing',
            'slug' => 'amayun-handmade-green-mechanical-production',
            'keywords' => 'handmade hanfu,green manufacturing,factory production,绿色制造',
            'zh' => [
                'title' => '手工结合绿色机械制造：阿玛云如何保证品质与交付',
                'excerpt' => '关键工序保留手工温度，产线环节用绿色机械提升一致性——解释我们为什么既不是纯手作溢价，也不是粗制快反。',
                'content' => h2('两种极端都不好')
                    . ul([
                        '纯营销「全手工」却无产能：交期崩、价格虚高。',
                        '纯快反无质检：形制漂、起球快、难复购。',
                    ])
                    . h2('我们的组合')
                    . p('在刺绣收尾、细节整烫、关键缝制等影响手感与观感的环节强调手工；在裁剪、锁边、稳定缝制与检验流转中使用高效、更清洁的机械产线，控制批次差异。')
                    . h2('绿色意味着什么')
                    . p('优先可核对的材料与更少浪费的流程，持续减少不必要的过度包装与返工。具体指标会随伙伴产线公开而完善。')
                    . h2('对你意味着什么')
                    . p('更稳的版型、更可读的交期、更诚实的价格结构——这就是工厂直销该有的体验。')
                    . ctaShop('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Handmade + Green Mechanical Production at Amayun',
                'excerpt' => 'Craft where hand-feel matters; green mechanical lines for consistency—neither pure handmade hype nor rough fast fashion.',
                'content' => h2('Two extremes fail')
                    . ul([
                        '“All handmade” marketing without capacity: late and overpriced.',
                        'Unchecked fast fashion: drifting forms, quick pilling, no repurchase.',
                    ])
                    . h2('Our mix')
                    . p('Handmade emphasis on embroidery finishing, pressing and key seams that decide hand-feel; efficient cleaner mechanical lines for cutting, overlocking, stable sewing and inspection flow.')
                    . h2('What “green” means here')
                    . p('Prefer verifiable materials and less-waste processes; keep cutting unnecessary packaging and rework. Metrics improve as partner lines open more data.')
                    . h2('What you get')
                    . p('Stabler patterns, readable lead times, honest price structure—the factory-direct experience as it should be.')
                    . ctaShop('en_US'),
            ],
        ],
    ];
}

function slugExists(string $slug): bool
{
    $model = ObjectManager::getInstance(Post::class);
    $row = $model->clearData()->reset()
        ->where(Post::schema_fields_WEBSITE_ID, WEBSITE_ID)
        ->where(Post::schema_fields_SLUG, $slug)
        ->find()
        ->fetchArray();

    return is_array($row) && (int)($row[Post::schema_fields_ID] ?? 0) > 0;
}

$categories = categoryIdMap();
foreach (articles() as $article) {
    if (!isset($categories[$article['category']])) {
        fwrite(STDERR, 'Missing category: ' . $article['category'] . PHP_EOL);
        exit(1);
    }
}

$posts = ObjectManager::getInstance(BlogPostAdminService::class);
$created = 0;
$skipped = 0;

foreach (articles() as $article) {
    $categoryId = $categories[$article['category']];
    $cover = COVER_BASE . '/' . $article['cover'] . '.svg';
    foreach ([
        'zh_Hans_CN' => [$article['slug'], $article['zh']],
        'en_US' => [$article['slug'] . '-en', $article['en']],
    ] as $locale => [$slug, $pack]) {
        if (slugExists($slug)) {
            echo "= skip {$slug}\n";
            ++$skipped;
            continue;
        }
        $row = $posts->save([
            'website_id' => WEBSITE_ID,
            'locale' => $locale,
            'slug' => $slug,
            'title' => $pack['title'],
            'excerpt' => $pack['excerpt'],
            'content' => $pack['content'],
            'cover_image' => $cover,
            'author' => AUTHOR,
            'keywords' => $article['keywords'],
            'category_id' => $categoryId,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int)($row[Post::schema_fields_ID] ?? 0);
        echo "+ #{$id} [{$locale}] {$slug} → {$article['category']}\n";
        ++$created;
    }
}

echo "created={$created} skipped={$skipped}\n";
