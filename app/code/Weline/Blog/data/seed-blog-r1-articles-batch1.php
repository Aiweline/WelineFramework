<?php

declare(strict_types=1);

/**
 * Seed R1 batch-1 premium posts: marketplace overview + Top5, vertical overview + Top5, Why Amayun.
 * Creates zh_Hans_CN + en_US rows (English slugs use -en suffix; website slug is unique).
 *
 * Usage: php app/code/Weline/Blog/data/seed-blog-r1-articles-batch1.php
 */

use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogCategoryAdminService;
use Weline\Blog\Service\BlogPostAdminService;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 4) . '/bootstrap.php';

const WEBSITE_ID = 0;
const COVER_BASE = '/media/blog/hanfu/articles/covers';
const AUTHOR = 'Amayun Editorial';

/**
 * @return array<string,int> slug => category_id
 */
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

function ctaAmayun(string $locale): string
{
    if ($locale === 'en_US') {
        return p('Amayun Technology (est. 2024) sources Hanfu from verified origin factories—factory-direct pricing, inspected quality, and handmade finishing with green mechanical production. Use this guide to decide when a marketplace, a DTC boutique, or factory-direct is the better fit.');
    }

    return p('阿玛云科技有限公司（注册于 2024）从原产地逐户确认优质货源，源头工厂直销，兼顾性价比与质量；生产采用手工结合绿色机械制造。用本指南判断：何时走平台、何时选独立站、何时更适合工厂直销。');
}

/**
 * @return list<array<string,mixed>>
 */
function articles(): array
{
    return [
        [
            'category' => 'compare-marketplaces',
            'cover' => 'marketplaces-overview',
            'slug' => 'where-to-buy-hanfu-top-marketplaces',
            'keywords' => 'hanfu marketplace,Amazon hanfu,TikTok Shop hanfu,AliExpress hanfu,where to buy hanfu',
            'zh' => [
                'title' => '海外买汉服：十大跨境综合平台怎么选（2026 总览）',
                'excerpt' => 'Amazon、TikTok Shop、速卖通、SHEIN、TEMU、YesStyle、Etsy、eBay、Shopee、Lazada——一张表看清流量、价格带、正统度与售后，再决定是否走源头工厂直销。',
                'content' => h2('为什么先看综合平台')
                    . p('对不懂中文、或习惯本地支付与物流的海外买家，跨境综合平台仍是「第一入口」。它们流量大、测款快，但汉服形制正确性、面料说明与售后体验参差不齐。')
                    . h2('十大平台速览')
                    . table(
                        ['平台', '更适合', '价格带体感', '形制风险', '注意点'],
                        [
                            ['Amazon', '高客单定制/马面裙刚需', '中高', '中', '看卖家评分与材质说明'],
                            ['TikTok Shop', '兴趣电商种草转化', '低到中', '中高', '短视频好看≠形制正确'],
                            ['AliExpress', '欧亚走量、曹县供应链', '低到中', '中', '物流周期与尺码表'],
                            ['SHEIN', '极致低价新中式女装', '低', '高', '偏快时尚改良，非考据向'],
                            ['TEMU', '平价国风配饰下沉', '很低', '高', '配件多、成衣质量波动'],
                            ['YesStyle', '亚洲潮流代购入口', '中', '中', '品牌混杂，需认准店铺'],
                            ['Etsy', '手作/高级定制同好', '中高到高', '低到中', '工期与退换政策'],
                            ['eBay', '二手绝版、Cos 古装', '波动大', '高', '成色与来源需细查'],
                            ['Shopee', '东南亚动销', '低到中', '中', '分站政策差异'],
                            ['Lazada', '泛华人商圈新中式', '低到中', '中', '偏改良日常款'],
                        ],
                    )
                    . h2('我们怎么建议你选')
                    . ul([
                        '要「快、便宜、尝鲜」：可看 TikTok Shop / SHEIN / TEMU，但务必对照平铺图与形制特征。',
                        '要「可检索的店铺信誉 + 本地售后」：Amazon / YesStyle 更稳。',
                        '要「手作与定制叙事」：Etsy 合适，但接受工期与溢价。',
                        '要「稳定形制 + 工厂价」：优先源头工厂直销（阿玛云路径），再用平台做比价参考。',
                    ])
                    . h2('下一步')
                    . p('下文将逐一拆解 Amazon、TikTok Shop、AliExpress、SHEIN、TEMU 五个最高频入口；完整十强中其余平台会在后续批次补齐。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Where to Buy Hanfu Abroad: Top 10 Marketplaces (2026 Overview)',
                'excerpt' => 'Amazon, TikTok Shop, AliExpress, SHEIN, TEMU, YesStyle, Etsy, eBay, Shopee, Lazada—compare traffic, price bands, silhouette risk and after-sales before choosing factory-direct.',
                'content' => h2('Why marketplaces come first')
                    . p('For shoppers who prefer local checkout and shipping, global marketplaces remain the default discovery channel. Volume is high; silhouette accuracy and fabric transparency vary widely.')
                    . h2('Quick matrix')
                    . table(
                        ['Platform', 'Best for', 'Price feel', 'Silhouette risk', 'Watch-outs'],
                        [
                            ['Amazon', 'Higher-ticket / mamian needs', 'Mid-high', 'Medium', 'Seller ratings + materials'],
                            ['TikTok Shop', 'Interest-commerce conversion', 'Low-mid', 'Med-high', 'Viral look ≠ correct form'],
                            ['AliExpress', 'EU/SEA volume supply', 'Low-mid', 'Medium', 'Lead time + size charts'],
                            ['SHEIN', 'Budget New Chinese Style', 'Low', 'High', 'Fast fashion, not archaeology'],
                            ['TEMU', 'Budget guofeng accessories', 'Very low', 'High', 'Accessories > apparel QC'],
                            ['YesStyle', 'Asia fashion gateway', 'Mid', 'Medium', 'Mixed brands'],
                            ['Etsy', 'Handmade / couture peers', 'Mid-high+', 'Low-mid', 'Lead time & returns'],
                            ['eBay', 'Secondhand / costume finds', 'Variable', 'High', 'Condition & provenance'],
                            ['Shopee', 'SEA velocity', 'Low-mid', 'Medium', 'Market-specific rules'],
                            ['Lazada', 'New Chinese for SEA diaspora', 'Low-mid', 'Medium', 'Daily-wear bias'],
                        ],
                    )
                    . h2('How we recommend choosing')
                    . ul([
                        'Fast & cheap trials: TikTok Shop / SHEIN / TEMU—always check flat-lays.',
                        'Trusted storefront + local returns: Amazon / YesStyle.',
                        'Handmade storytelling: Etsy—budget for time and premium.',
                        'Stable forms + factory pricing: Amayun factory-direct, use marketplaces as price references.',
                    ])
                    . h2('Next')
                    . p('Deep dives follow for Amazon, TikTok Shop, AliExpress, SHEIN and TEMU. The remaining five platforms ship in a later batch.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-marketplaces',
            'cover' => 'amazon',
            'slug' => 'amazon-hanfu-buying-guide',
            'keywords' => 'Amazon hanfu,buy hanfu on Amazon,mamian skirt Amazon',
            'zh' => [
                'title' => 'Amazon 买汉服值得吗？高客单马面裙与正统款避坑',
                'excerpt' => '亚马逊是海外高单价定制与正统感汉服的刚需大站。本文从卖家信任、尺码、材质与退货，对比源头工厂直销何时更优。',
                'content' => h2('平台定位')
                    . p('Amazon 适合已经明确需求、愿为物流与售后付溢价的买家。搜索「hanfu」「mamian skirt」等词，能看到大量成衣与定制入口。')
                    . h2('优势')
                    . ul(['本地化支付与退货体验相对成熟', '卖家评分、问答与评论可交叉验证', '适合高客单、送礼或赶时效订单'])
                    . h2('风险')
                    . ul(['标题党与影楼装混排，形制需靠平铺图自查', '中间商加价常见，同款工厂价往往更低', '材质描述含糊时，手感与起球风险上升'])
                    . h2('对阿玛云买家的建议')
                    . p('把 Amazon 当作「规格与口碑参照」：记下你要的形制、面料关键词与尺码逻辑，再到工厂直销渠道核对同规格报价。要稳定版型与可追溯货源时，源头工厂更可控。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Is Amazon Good for Hanfu? Mamian & Formal Pieces Checklist',
                'excerpt' => 'Amazon is a go-to for higher-ticket overseas Hanfu buys. We cover trust signals, sizing, materials and when factory-direct wins.',
                'content' => h2('Positioning')
                    . p('Amazon fits shoppers who want familiar checkout/returns and will pay a premium for convenience. Searches like “hanfu” and “mamian skirt” surface ready-to-wear and made-to-order listings.')
                    . h2('Strengths')
                    . ul(['Mature local payments and returns', 'Ratings, Q&A and reviews for triangulation', 'Good for gifts and deadline-sensitive orders'])
                    . h2('Risks')
                    . ul(['Costume dresses mixed into results—verify flat-lays', 'Reseller markups vs origin factory pricing', 'Vague fabric claims → pilling/hand-feel surprises'])
                    . h2('When Amayun is better')
                    . p('Use Amazon as a reference for specs and social proof, then compare factory-direct quotes for the same silhouette and fabric story. For traceable supply and consistent patterns, origin factories are easier to control.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-marketplaces',
            'cover' => 'tiktok-shop',
            'slug' => 'tiktok-shop-hanfu-guide',
            'keywords' => 'TikTok Shop hanfu,#hanfu,interest commerce hanfu',
            'zh' => [
                'title' => 'TikTok Shop 汉服：流量第一，怎么买才不踩雷',
                'excerpt' => '海外抖音是汉服兴趣电商超级入口。播放与转化强，但视频滤镜会掩盖形制与面料问题——本文给你下单清单。',
                'content' => h2('流量逻辑')
                    . p('TikTok Shop 靠短视频种草，汉服相关话题长期高热。适合「被种草后立刻想买」的路径，不适合只看镜头美感就下单。')
                    . h2('优势')
                    . ul(['兴趣匹配强，测款与爆款速度快', '价格带偏友好，适合入门尝鲜', '达人内容可帮助学习穿搭灵感'])
                    . h2('风险')
                    . ul(['运镜与滤镜美化版型，需索要平铺与细节图', '直播话术夸大「正统」「真丝」时要有证据', '售后与物流体验因店铺而异'])
                    . h2('工厂直销怎么互补')
                    . p('把 TikTok 当灵感池：收藏形制与配色，再到有车间与产地背书的渠道确认面料与工艺。阿玛云强调源头工厂与手工+绿色机械制造，适合把「爆款灵感」落成「可复购品质」。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'TikTok Shop Hanfu: High Traffic, How Not to Get Burned',
                'excerpt' => 'TikTok Shop is a top interest-commerce channel for Hanfu. Conversion is strong—filters can hide silhouette and fabric issues. Here’s a buy checklist.',
                'content' => h2('How demand works')
                    . p('Short-form video drives discovery. Great for impulse trials; poor if you buy on glow alone.')
                    . h2('Strengths')
                    . ul(['Strong intent matching and fast trend testing', 'Friendly entry prices', 'Creator content for styling ideas'])
                    . h2('Risks')
                    . ul(['Camera angles flatter fit—request flat-lays', 'Claims like “authentic silk” need proof', 'Seller-dependent shipping/returns'])
                    . h2('Pairing with factory-direct')
                    . p('Treat TikTok as an inspiration feed, then validate fabric and craft with origin-backed sellers. Amayun focuses on factory-direct quality so viral looks become repurchase-worthy pieces.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-marketplaces',
            'cover' => 'aliexpress',
            'slug' => 'aliexpress-hanfu-europe-sea',
            'keywords' => 'AliExpress hanfu,Cao County hanfu,Hanfu Europe Southeast Asia',
            'zh' => [
                'title' => '速卖通汉服：欧洲与东南亚走量通道怎么挑店',
                'excerpt' => 'AliExpress 是曹县等产业带出海欧亚的传统大本营。价格友好，但物流与尺码是头号差评点——附选店标准。',
                'content' => h2('通道角色')
                    . p('速卖通连接国内产业带与海外零售需求，适合「要款式多、预算可控」的买家，也是许多独立站的隐性货源对照面。')
                    . h2('优势')
                    . ul(['SKU 丰富，女装形制覆盖面广', '价格带对欧洲/东南亚有竞争力', '店铺年限与订单量可辅助判断'])
                    . h2('风险')
                    . ul(['物流周期长，节庆旺季更明显', '尺码表不统一，需按平铺厘米选', '同图多店，质量分层严重'])
                    . h2('和源头工厂的关系')
                    . p('看到心仪链接，先记录面料克重、工艺与版型数据，再问工厂能否同规格直供。阿玛云走访确认货源后直销，减少多层转手。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'AliExpress Hanfu for Europe & SEA: How to Pick Sellers',
                'excerpt' => 'AliExpress remains a classic outlet for industrial-belt Hanfu into Europe and Southeast Asia. Prices look good—shipping and sizing drive complaints. Seller checklist inside.',
                'content' => h2('Role in the market')
                    . p('AliExpress bridges Chinese manufacturing clusters to overseas retail demand—great assortment, variable consistency.')
                    . h2('Strengths')
                    . ul(['Broad SKU coverage for women’s forms', 'Competitive price bands for EU/SEA', 'Store age and order volume as weak signals'])
                    . h2('Risks')
                    . ul(['Long shipping, worse in peak seasons', 'Inconsistent size charts—use cm flat specs', 'Same photos across stores, uneven QC'])
                    . h2('Factory-direct angle')
                    . p('Save fabric weight, craft notes and pattern data, then ask an origin factory for the same brief. Amayun sells verified supply direct—fewer handoffs.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-marketplaces',
            'cover' => 'shein',
            'slug' => 'shein-new-chinese-style-hanfu',
            'keywords' => 'SHEIN hanfu,New Chinese Style,cheap guofeng dress',
            'zh' => [
                'title' => 'SHEIN 新中式汉服：低价快时尚适合谁、不适合谁',
                'excerpt' => '希音擅长极致低价的现代改良新中式女装。适合派对拍照尝鲜，不适合追求考据形制与耐穿面料的买家。',
                'content' => h2('它真正卖的是什么')
                    . p('SHEIN 的优势是趋势响应与价格。多数商品是「汉元素/新中式」而非博物馆级形制复原，要按「时尚连衣裙」预期管理。')
                    . h2('适合')
                    . ul(['预算有限、先体验国风氛围', '短周期活动、拍照需求', '接受化纤手感与较短寿命'])
                    . h2('不适合')
                    . ul(['交领右衽、结构考据党', '需长期穿着、水洗后仍挺括的面料', '要清晰产地与工艺追溯'])
                    . h2('阿玛云差异')
                    . p('若你从 SHEIN 喜欢上国风，却开始在意形制与耐用，可转向源头工厂的正统形制线：价格仍强调性价比，但工艺与质检按服装而非一次性快时尚标准。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'SHEIN New Chinese Style: Who It’s For (and Not For)',
                'excerpt' => 'SHEIN wins on ultra-low-price modern guofeng dresses. Great for party photos—not for historical silhouettes or durable textiles.',
                'content' => h2('What you are really buying')
                    . p('Trend speed and price. Most pieces are New Chinese / Han-element fashion—not archaeological reconstructions.')
                    . h2('Good fit')
                    . ul(['Tight budgets and first aesthetic trials', 'Short-event photo needs', 'OK with synthetic hand-feel and shorter lifespan'])
                    . h2('Poor fit')
                    . ul(['Cross-collar structure purists', 'Long-wear fabrics that keep shape after wash', 'Traceable origin and craft stories'])
                    . h2('Amayun difference')
                    . p('If SHEIN got you into the aesthetic but you now care about form and durability, move to factory-direct classical lines—still value-priced, built like garments not disposables.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-marketplaces',
            'cover' => 'temu',
            'slug' => 'temu-hanfu-accessories-guide',
            'keywords' => 'TEMU hanfu,guofeng accessories,budget hairpin',
            'zh' => [
                'title' => 'TEMU 国风配饰与汉元素：下沉市场价格与质量边界',
                'excerpt' => '特木（拼多多海外）在平价配饰与汉元素单品上很强。成衣质量波动大——教你哪些能买、哪些建议工厂货。',
                'content' => h2('平台特长')
                    . p('全托管与极致价格，让发簪、腰饰、基础汉元素小物成为 TEMU 高频成交类。成套正统汉服则需更谨慎。')
                    . h2('可以买')
                    . ul(['低风险饰品、装饰性披帛类小物（看评价图）', '明确标注合金/塑料等材质的配件', '用于短期活动的氛围单品'])
                    . h2('建议谨慎')
                    . ul(['高价「真丝」「三层」话术却无细节图', '复杂襦裙套装仅有一张网图', '宣称正统却无平铺结构展示'])
                    . h2('组合策略')
                    . p('配饰可在 TEMU 试错，主干衣身选择源头工厂直销，整体观感与耐用性会明显更稳。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'TEMU Guofeng Accessories: Price Floor vs Quality Ceiling',
                'excerpt' => 'TEMU is strong on budget accessories and Han-element items. Apparel QC varies—what to buy, what to leave for factories.',
                'content' => h2('Where TEMU shines')
                    . p('Fully managed low prices make hairpins, waist charms and small props frequent winners. Full classical sets need more caution.')
                    . h2('Safer buys')
                    . ul(['Low-risk ornaments with real buyer photos', 'Accessories with honest material labels', 'Short-event atmosphere pieces'])
                    . h2('Be careful')
                    . ul(['“Real silk / triple layer” claims without details', 'Complex ruqun sets with one stock photo', '“Authentic” labels without flat structure shots'])
                    . h2('Bundle strategy')
                    . p('Trial accessories on TEMU; source main garments factory-direct for a stabler look and lifespan.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-vertical-stores',
            'cover' => 'vertical-overview',
            'slug' => 'hanfu-vertical-stores-top10-overview',
            'keywords' => 'hanfu DTC,NewMoonDance,Nuwa Hanfu,Hanfu Story,Fashion Hanfu',
            'zh' => [
                'title' => '海外汉服垂直独立站前十对比总览（仍在营业）',
                'excerpt' => 'NewMoonDance、Nüwa、Hanfu Story、Newhanfu Store、Fashion Hanfu 等——定位、客群与定价逻辑一张表，并对照源头工厂直销优势。',
                'content' => h2('为什么独立站仍重要')
                    . p('相对平台，垂直站通常内容更深、形制教育更完整，品牌感与客服话术更专业；代价是营销与运营成本反映在售价上。')
                    . h2('前十速览（本批深挖前五）')
                    . table(
                        ['独立站', '大致定位', '内容/社区', '价格体感'],
                        [
                            ['NewMoonDance', '形制最全天花板之一', '强', '中高'],
                            ['Nüwa Hanfu', '北美中高端+英文博客矩阵', '很强', '中高到高'],
                            ['Hanfu Story', '新澳华人市场口碑站', '中强', '中高'],
                            ['Newhanfu Store', '社区官方商城权威感', '社区极强', '中到中高'],
                            ['Fashion Hanfu', '欧美审美改良+婚礼向', '中', '中高'],
                            ['Intervene', '新中式通勤设计师', '中', '高'],
                            ['Dawn x Dare', '买手站汉元素线', '中', '高'],
                            ['Doresuwe', '礼服/古装规模站', '弱到中', '中到高'],
                            ['East Meets Dress', '中式婚礼定制', '中', '高'],
                            ['Soulsfen', '复古华服与配饰', '中', '中高'],
                        ],
                    )
                    . h2('决策框架')
                    . ul([
                        '要教育内容与社群：Nüwa / Newhanfu 路线。',
                        '要广 SKU 形制：NewMoonDance 类目录站。',
                        '要婚礼定制叙事：East Meets Dress / Fashion Hanfu 婚礼线。',
                        '要同规格更低到手价：对比阿玛云工厂直销。',
                    ])
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Top Overseas Hanfu DTC Sites Compared (Still Live)',
                'excerpt' => 'NewMoonDance, Nüwa, Hanfu Story, Newhanfu Store, Fashion Hanfu and more—positioning, community and price feel vs factory-direct.',
                'content' => h2('Why DTC still matters')
                    . p('Vertical stores usually teach silhouette better and feel more branded than marketplaces—at a marketing-cost premium.')
                    . h2('Top-10 snapshot (deep dives on first five)')
                    . table(
                        ['Store', 'Positioning', 'Content/community', 'Price feel'],
                        [
                            ['NewMoonDance', 'Broad silhouette catalog leader', 'Strong', 'Mid-high'],
                            ['Nüwa Hanfu', 'US mid-premium + English blog matrix', 'Very strong', 'Mid-high+'],
                            ['Hanfu Story', 'SG/AU Chinese-community reputation', 'Solid', 'Mid-high'],
                            ['Newhanfu Store', 'Community-official authority', 'Community-max', 'Mid to mid-high'],
                            ['Fashion Hanfu', 'Western taste + wedding lean', 'Medium', 'Mid-high'],
                            ['Intervene', 'New Chinese designer commute', 'Medium', 'High'],
                            ['Dawn x Dare', 'Buyer boutique Han-element', 'Medium', 'High'],
                            ['Doresuwe', 'Formal/costume scale', 'Low-mid', 'Mid-high'],
                            ['East Meets Dress', 'Chinese wedding couture', 'Medium', 'High'],
                            ['Soulsfen', 'Retro Huafu + accessories', 'Medium', 'Mid-high'],
                        ],
                    )
                    . h2('Decision frame')
                    . ul([
                        'Education & community: Nüwa / Newhanfu path.',
                        'Wide silhouette catalog: NewMoonDance-like stores.',
                        'Wedding narrative: East Meets Dress / Fashion Hanfu bridal.',
                        'Same specs, lower landed cost: compare Amayun factory-direct.',
                    ])
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-vertical-stores',
            'cover' => 'newmoondance',
            'slug' => 'newmoondance-hanfu-review',
            'keywords' => 'NewMoonDance,newmoondance.com,hanfu DTC',
            'zh' => [
                'title' => 'NewMoonDance 评测：海外汉服独立站的目录天花板',
                'excerpt' => '形制与配饰覆盖面广，是海外垂直站常被提及的标杆。适合「一次逛全」；对价格敏感者应用工厂价对照。',
                'content' => h2('谁适合')
                    . p('想系统浏览多朝代形制、并一站配齐配饰的海外买家。')
                    . h2('亮点')
                    . ul(['目录深度与配饰广度突出', '对形制名的英文可读性较好', '适合作为「全球有哪些形制」的对照站'])
                    . h2('代价')
                    . ul(['品牌运营成本反映在售价', '物流与关税需按目的地自算', '同规格产业带直供往往更便宜'])
                    . h2('对比阿玛云')
                    . p('NewMoonDance 赢在目录与品牌体验；阿玛云赢在源头工厂、性价比与可追溯车间故事。先用独立站锁定形制，再工厂直销下单，是常见省钱路径。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'NewMoonDance Review: Catalog Ceiling Among Hanfu DTC',
                'excerpt' => 'Broad silhouettes and accessories make it a frequent benchmark. Great for one-stop browsing—price-sensitive buyers should cross-check factory quotes.',
                'content' => h2('Best for')
                    . p('Shoppers who want many dynasty forms and accessories in one English-friendly catalog.')
                    . h2('Highlights')
                    . ul(['Catalog depth + accessory breadth', 'Readable English form names', 'Useful as a global silhouette reference'])
                    . h2('Trade-offs')
                    . ul(['Brand ops priced into tags', 'Duties/shipping vary by destination', 'Industrial-belt direct often cheaper at same brief'])
                    . h2('Vs Amayun')
                    . p('NewMoonDance wins catalog/brand experience; Amayun wins factory-direct value and traceable workshop narrative. Lock the silhouette on DTC, buy factory-direct when price matters.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-vertical-stores',
            'cover' => 'nuwa',
            'slug' => 'nuwa-hanfu-review',
            'keywords' => 'Nüwa Hanfu,nuwahanfu.com,hanfu blog',
            'zh' => [
                'title' => 'Nüwa Hanfu 评测：北美中高端与英文博客矩阵',
                'excerpt' => '美国总部、红人背景与专业英文内容是其护城河。适合愿为内容与品牌付溢价的买家。',
                'content' => h2('定位')
                    . p('Nüwa 强调原创中高端与教育型内容，博客矩阵帮助建立信任，转化路径是「先懂再买」。')
                    . h2('优势')
                    . ul(['英文内容专业，降低文化门槛', '品牌故事清晰，适合北美语境', '中高端质感预期更一致'])
                    . h2('局限')
                    . ul(['售价含内容与品牌溢价', 'SKU 未必覆盖全部形制', '对极致性价比人群不友好'])
                    . h2('阿玛云角度')
                    . p('学习 Nüwa 的内容标准，购买环节用工厂直销满足「物美价廉」宗旨——教育可以公开，货盘应对齐产地效率。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Nüwa Hanfu Review: US Mid-Premium + English Blog Matrix',
                'excerpt' => 'US base, creator roots and strong English education are the moat. Best if you will pay for content-backed brand trust.',
                'content' => h2('Positioning')
                    . p('Mid-premium originals with teach-first content. Trust is built in the blog matrix before checkout.')
                    . h2('Strengths')
                    . ul(['Professional English education', 'Clear brand story for North America', 'More consistent premium expectations'])
                    . h2('Limits')
                    . ul(['Content/brand premium in price', 'Catalog may not cover every form', 'Not for pure bargain hunters'])
                    . h2('Amayun angle')
                    . p('Match Nüwa’s education quality publicly, then fulfill with factory-direct economics—Amayun’s mandate is fair price with verified origin supply.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-vertical-stores',
            'cover' => 'hanfu-story',
            'slug' => 'hanfu-story-review',
            'keywords' => 'Hanfu Story,thehanfustory.com,Singapore Australia hanfu',
            'zh' => [
                'title' => 'Hanfu Story 评测：新加坡与澳洲市场的口碑垂直站',
                'excerpt' => '深耕新澳等华人与同好市场，口碑与服务体验常被提及。适合该区域物流友好的买家。',
                'content' => h2('市场焦点')
                    . p('相对全球铺货型平台，Hanfu Story 更像区域深耕站：懂本地节日、尺码沟通与华人社群语境。')
                    . h2('优势')
                    . ul(['区域口碑与复购叙事', '对当地物流时效预期更清晰', '客服沟通文化门槛较低'])
                    . h2('注意')
                    . ul(['全球其他地区运费/时效需另算', '价格仍含品牌零售环节', '形制广度需以当期目录为准'])
                    . h2('对照工厂直销')
                    . p('若你在新澳以外，或同一形制想压低到手价，可用阿玛云工厂直销做平行对比，同时保留对口碑站服务体验的偏好。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Hanfu Story Review: Reputation DTC for SG & Australia',
                'excerpt' => 'Strong in Singapore/Australia communities. Best when regional shipping and service context matter.',
                'content' => h2('Market focus')
                    . p('Less “ship everywhere cheap,” more regional depth—festivals, sizing chats, diaspora context.')
                    . h2('Strengths')
                    . ul(['Local reputation and repurchase stories', 'Clearer regional shipping expectations', 'Lower cultural friction in support'])
                    . h2('Watch')
                    . ul(['Other regions may pay more time/cost', 'Retail brand margin remains', 'Silhouette breadth depends on live catalog'])
                    . h2('Vs factory-direct')
                    . p('Outside SG/AU—or when landed cost matters—run an Amayun factory-direct quote in parallel while keeping service preferences in mind.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-vertical-stores',
            'cover' => 'newhanfu',
            'slug' => 'newhanfu-store-review',
            'keywords' => 'Newhanfu Store,newhanfu.com,hanfu community shop',
            'zh' => [
                'title' => 'Newhanfu Store 评测：社区官方商城的权威与边界',
                'excerpt' => '依托全球最大英文汉服社区之一，权威感强。适合先学习再购买；价格与货盘需分开评估。',
                'content' => h2('权威从哪来')
                    . p('社区百科、穿戴教程与讨论沉淀，使 Newhanfu 生态在 Google 英文检索中权重很高；商城是信任延伸，不等于工厂价。')
                    . h2('适合')
                    . ul(['需要系统英文科普后再下单', '重视社区共识与避雷讨论', '把商城当「可信货架」而非最低价'])
                    . h2('边界')
                    . ul(['社区流量价值会进入零售定价', 'SKU 策略服务社区而非产业带清仓', '大宗或定制仍可回源头谈'])
                    . h2('阿玛云互补')
                    . p('用 Newhanfu 学形制，用阿玛云落订单：知识公开、货盘直连产地，符合「物美价廉」宗旨。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Newhanfu Store Review: Community Authority—and Its Limits',
                'excerpt' => 'Backed by a leading English Hanfu community. Great for learn-then-buy; price and assortment still need a separate check.',
                'content' => h2('Where authority comes from')
                    . p('Guides, wear tutorials and forum memory rank strongly in English search. The store extends trust—it is not a factory price list.')
                    . h2('Best for')
                    . ul(['Shoppers who want education before checkout', 'Community consensus and pitfall threads', 'A trusted shelf—not the absolute lowest bid'])
                    . h2('Limits')
                    . ul(['Community value is priced into retail', 'Assortment serves community, not clearance logic', 'Bulk/custom can still go to origin factories'])
                    . h2('Amayun complement')
                    . p('Learn forms via Newhanfu; fulfill via Amayun factory-direct—open knowledge, origin-linked goods, fair price.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-vertical-stores',
            'cover' => 'fashion-hanfu',
            'slug' => 'fashion-hanfu-review',
            'keywords' => 'Fashion Hanfu,fashionhanfu.com,hanfu wedding',
            'zh' => [
                'title' => 'Fashion Hanfu 评测：欧美审美改良与婚礼国风',
                'excerpt' => '面向欧美本土审美的汉服与国风婚礼服饰。适合要「能出门的东方感」；考据党请看形制线。',
                'content' => h2('产品气质')
                    . p('更贴近西方婚礼与日常审美的改良轮廓，降低「戏服感」，提高可社交穿着概率。')
                    . h2('优势')
                    . ul(['婚礼/仪式场景内容完整', '审美语言贴近欧美顾客', '配饰组合便于一站采购'])
                    . h2('局限')
                    . ul(['改良优先时，正统结构可能让步', '溢价包含设计与品牌叙事', '与工厂基础款定位不同'])
                    . h2('如何与阿玛云搭配')
                    . p('婚礼主纱或仪式焦点可用设计师叙事站；日常可穿的形制基础款交给源头工厂直销，预算结构更健康。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Fashion Hanfu Review: Western Taste & Guofeng Bridal',
                'excerpt' => 'Hanfu and Chinese-style wedding pieces tuned for Western aesthetics. Great for wearable Eastern romance—purists should verify structure.',
                'content' => h2('Product vibe')
                    . p('Silhouettes softened for Western weddings and daily social wear—less costume, more outfit.')
                    . h2('Strengths')
                    . ul(['Strong bridal/ceremony storytelling', 'Taste language for Euro-American shoppers', 'Accessory bundles for one-cart buys'])
                    . h2('Limits')
                    . ul(['Modernization can trade off classical structure', 'Design/brand premium in price', 'Different job than factory basics'])
                    . h2('With Amayun')
                    . p('Keep designer narrative for ceremony heroes; move everyday foundational forms to factory-direct for a healthier budget mix.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-why-amayun',
            'cover' => 'why-amayun',
            'slug' => 'why-amayun-factory-direct-hanfu',
            'keywords' => 'Amayun,factory direct hanfu,阿玛云科技,source factory',
            'zh' => [
                'title' => '为什么选择阿玛云：源头工厂直销 vs 平台与独立站',
                'excerpt' => '阿玛云科技（2024）宗旨是物美价廉：原产地走访确认货源、工厂直销、手工结合绿色机械制造。对照平台与 DTC 的差异清单。',
                'content' => h2('我们是谁')
                    . p('阿玛云科技有限公司注册于 2024 年，专注为全球客户提供高性价比汉服与国风服饰。团队从原产地逐户走访确认优质货源，并与可靠厂家建立合作（含车间实景与 1688 伙伴信息透明化）。')
                    . h2('三句话优势')
                    . ul([
                        '源头工厂厂家直销：减少多层经销加价。',
                        '质量有保障：形制与工艺按服装标准质检，而非一次性快时尚逻辑。',
                        '手工 + 绿色机械制造：关键工序保留手工温度，产线环节提升稳定性与交付。',
                    ])
                    . h2('对照清单')
                    . table(
                        ['维度', '综合平台', '垂直独立站', '阿玛云'],
                        [
                            ['价格', '波动大，中间商常见', '含品牌与内容溢价', '工厂直销导向'],
                            ['形制教育', '弱', '强', '持续建设内容矩阵'],
                            ['货源透明', '弱', '中', '产地走访与车间可见'],
                            ['售后本地化', '较强（视站点）', '中', '按站点政策持续完善'],
                            ['测款速度', '极快', '中', '以验证款式稳健上架'],
                        ],
                    )
                    . h2('适合你，如果…')
                    . ul([
                        '已通过平台/独立站搞清自己要的形制',
                        '希望同规格拿到更合理的工厂价',
                        '在意质量与可追溯，而不只是最低点击价',
                    ])
                    . p('接下来我们会继续补齐其余平台与独立站评测，并连载百科、穿搭与全球民族服饰精品文，让「先懂再买」在阿玛云站内一次完成。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Why Amayun: Factory-Direct Hanfu vs Marketplaces & DTC',
                'excerpt' => 'Amayun Technology (2024) stands for fair price: origin visits, factory-direct sales, handmade finishing with green mechanical production. A clear comparison checklist.',
                'content' => h2('Who we are')
                    . p('Amayun Technology Co., Ltd. (registered 2024) focuses on value Hanfu and Chinese-style apparel worldwide. We confirm supply through origin visits and partner transparently with reliable factories (workshop imagery and 1688 partners).')
                    . h2('Three advantages')
                    . ul([
                        'Factory-direct: fewer reseller markups.',
                        'Quality first: garment QC, not disposable fashion logic.',
                        'Handmade + green mechanical production: craft where it matters, machines for consistency.',
                    ])
                    . h2('Checklist')
                    . table(
                        ['Lens', 'Marketplaces', 'Vertical DTC', 'Amayun'],
                        [
                            ['Price', 'Volatile; resellers common', 'Brand/content premium', 'Factory-direct oriented'],
                            ['Education', 'Weak', 'Strong', 'Building content matrix'],
                            ['Supply transparency', 'Weak', 'Medium', 'Origin visits + workshops'],
                            ['Local after-sales', 'Often strong', 'Medium', 'Improving by market'],
                            ['Trend speed', 'Very fast', 'Medium', 'Validated styles, steady launch'],
                        ],
                    )
                    . h2('Choose us if…')
                    . ul([
                        'You already know the silhouette you want',
                        'You want fair factory economics at comparable specs',
                        'You care about traceable quality—not only the lowest click price',
                    ])
                    . p('We will keep shipping the remaining platform/DTC reviews plus encyclopedia, styling and world ethnic dress features—so learn-then-buy can happen on Amayun.')
                    . ctaAmayun('en_US'),
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
$missing = [];
foreach (articles() as $article) {
    if (!isset($categories[$article['category']])) {
        $missing[] = $article['category'];
    }
}
if ($missing !== []) {
    fwrite(STDERR, 'Missing categories: ' . implode(', ', array_unique($missing)) . PHP_EOL);
    exit(1);
}

$posts = ObjectManager::getInstance(BlogPostAdminService::class);
$created = 0;
$skipped = 0;

foreach (articles() as $article) {
    $categoryId = $categories[$article['category']];
    $cover = COVER_BASE . '/' . $article['cover'] . '.svg';
    $packs = [
        'zh_Hans_CN' => [$article['slug'], $article['zh']],
        'en_US' => [$article['slug'] . '-en', $article['en']],
    ];
    foreach ($packs as $locale => [$slug, $pack]) {
        if (slugExists($slug)) {
            echo "= skip existing {$slug}\n";
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
