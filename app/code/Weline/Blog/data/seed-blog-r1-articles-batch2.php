<?php

declare(strict_types=1);

/**
 * Seed R1 batch-2: remaining 5 marketplaces + 5 vertical DTC reviews (zh + en).
 *
 * Usage: php app/code/Weline/Blog/data/seed-blog-r1-articles-batch2.php
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

function ctaAmayun(string $locale): string
{
    if ($locale === 'en_US') {
        return p('Amayun Technology (est. 2024) sources Hanfu from verified origin factories—factory-direct pricing, inspected quality, and handmade finishing with green mechanical production. Use this review to decide when DTC/marketplace convenience beats factory-direct value.');
    }

    return p('阿玛云科技有限公司（注册于 2024）从原产地确认优质货源，源头工厂直销，手工结合绿色机械制造。用本评测判断：何时为方便付溢价，何时更适合工厂直销。');
}

/** @return list<array<string,mixed>> */
function articles(): array
{
    return [
        [
            'category' => 'compare-marketplaces',
            'cover' => 'yesstyle',
            'slug' => 'yesstyle-hanfu-asia-fashion-gateway',
            'keywords' => 'YesStyle hanfu,YesStyle Chinese clothing,Asia fashion hanfu',
            'zh' => [
                'title' => 'YesStyle 汉服频道：不懂中文也能买的亚洲潮流入口',
                'excerpt' => 'YesStyle 是全球最大亚洲潮流跨境分销网之一，适合英语买家找汉服与国风单品。品牌混杂——附选店与工厂直销对照。',
                'content' => h2('平台定位')
                    . p('YesStyle 把亚洲时尚品牌汇到一个英文结账体验里，是许多「不懂中文但想买汉服」的海外用户第一站。汉服与新中式、JK、美妆常同屏出现。')
                    . h2('优势')
                    . ul(['英文界面与支付习惯友好', '亚洲品牌聚合，发现路径短', '退换与客服话术相对标准化'])
                    . h2('风险')
                    . ul(['店铺与品牌混杂，形制正确性需自查平铺图', '零售加价常见，同规格工厂价往往更低', '「汉服」标签下可能混入古风裙/影楼装'])
                    . h2('何时选阿玛云')
                    . p('已在 YesStyle 锁定形制与配色后，用工厂直销核对面料与到手价；要可追溯车间与产地故事时，阿玛云更直接。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'YesStyle Hanfu: Asia Fashion Gateway for Non-Chinese Shoppers',
                'excerpt' => 'YesStyle aggregates Asian fashion for English checkout. Handy discovery—mixed brands mean you must verify silhouettes. Compare with factory-direct.',
                'content' => h2('Positioning')
                    . p('An English-friendly Asia fashion marketplace where Hanfu sits beside New Chinese Style, beauty and street brands—often the first stop for non-Chinese readers.')
                    . h2('Strengths')
                    . ul(['Familiar English UI and payments', 'Fast multi-brand discovery', 'Relatively standardized support language'])
                    . h2('Risks')
                    . ul(['Mixed sellers—check flat-lays for real forms', 'Retail markup vs origin factory quotes', '“Hanfu” tags can include costume dresses'])
                    . h2('When Amayun wins')
                    . p('After you lock silhouette and palette on YesStyle, cross-check fabric and landed cost factory-direct. For workshop-traceable supply, Amayun is more direct.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-marketplaces',
            'cover' => 'etsy',
            'slug' => 'etsy-hanfu-handmade-custom',
            'keywords' => 'Etsy hanfu,handmade hanfu,custom mamian Etsy',
            'zh' => [
                'title' => 'Etsy 汉服：手作与高级定制同好站怎么下单',
                'excerpt' => 'Etsy 聚集海外汉服手艺人与复古服饰卖家，适合定制叙事；工期与溢价是代价。附与工厂直销的分工建议。',
                'content' => h2('谁适合 Etsy')
                    . p('想要「一人一作」故事、小众刺绣或量身改版的买家。搜索 hanfu、mamian、ruqun 能看到大量独立匠人店铺。')
                    . h2('优势')
                    . ul(['手作与定制叙事强，差异化高', '卖家沟通细致，改码改长常见', '适合婚礼焦点单品、收藏向器物'])
                    . h2('风险')
                    . ul(['工期长，旺季更明显', '溢价含创意与时间成本', '退换政策因店而异，需读 Listing'])
                    . h2('组合买法')
                    . p('仪式焦点可走 Etsy 定制；日常可穿的基础形制交给阿玛云工厂直销，预算与交付更平衡。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Etsy Hanfu: Handmade & Custom—How to Order Smart',
                'excerpt' => 'Etsy hosts overseas makers and vintage sellers. Great for custom stories—budget time and premium. Pair with factory-direct basics.',
                'content' => h2('Best for')
                    . p('Shoppers who want maker stories, niche embroidery or made-to-measure tweaks. Searches for hanfu/mamian/ruqun surface many indie ateliers.')
                    . h2('Strengths')
                    . ul(['Strong handmade/custom narrative', 'Detailed seller chats and alterations', 'Ideal for bridal heroes and collectibles'])
                    . h2('Risks')
                    . ul(['Long lead times, worse in peak seasons', 'Creative time priced into tags', 'Return policies vary by shop'])
                    . h2('Bundle with Amayun')
                    . p('Keep ceremony heroes on Etsy; move everyday foundational forms to Amayun factory-direct for healthier budgets and steadier delivery.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-marketplaces',
            'cover' => 'ebay',
            'slug' => 'ebay-hanfu-secondhand-cosplay',
            'keywords' => 'eBay hanfu,secondhand hanfu,cosplay costume hanfu',
            'zh' => [
                'title' => 'eBay 汉服：二手绝版与 Cos 古装淘货指南',
                'excerpt' => '老牌综合电商适合淘二手、绝版与古装道具。成色与来源是最大风险——附查验清单与工厂新品对照。',
                'content' => h2('平台角色')
                    . p('eBay 更像「跳蚤市场 + 全球卖家」，汉服相关常与 Cosplay、舞台装、二手衣混排。适合猎奇与补货，不适合无鉴别能力的首购。')
                    . h2('可以淘')
                    . ul(['成色清晰、多角度实拍的二手成衣', '绝版配色、停产配饰', '明确标注 costume/stage 的表演服（按预期购买）'])
                    . h2('务必警惕')
                    . ul(['仅一张网图、拒绝补拍的高价链接', '「真丝」「正统」却无平铺结构', '国际运费+关税后远超新品工厂价'])
                    . h2('新品路径')
                    . p('要稳定版型与可退换新品，优先源头工厂直销；把 eBay 留给二手与绝版任务。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'eBay Hanfu: Secondhand, Rare Finds & Costume Hunting',
                'excerpt' => 'A classic marketplace for used, discontinued and costume pieces. Condition and provenance are the risks—checklist vs factory-new.',
                'content' => h2('Role')
                    . p('eBay mixes thrift, global sellers and costume props. Fine for hunting—not ideal as a first buy without inspection skills.')
                    . h2('Safer hunts')
                    . ul(['Used garments with multi-angle real photos', 'Discontinued colors/accessories', 'Explicitly labeled stage/costume pieces bought as such'])
                    . h2('Red flags')
                    . ul(['One stock photo and no extra shots', '“Silk/authentic” with no flat structure', 'Shipping+duty exceeds factory-new quotes'])
                    . h2('New-garment path')
                    . p('For stable patterns and returnable new pieces, prefer Amayun factory-direct; keep eBay for secondhand/rare jobs.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-marketplaces',
            'cover' => 'shopee',
            'slug' => 'shopee-hanfu-southeast-asia',
            'keywords' => 'Shopee hanfu,Singapore Malaysia hanfu,SEA hanfu shop',
            'zh' => [
                'title' => 'Shopee 汉服：新加坡与马来西亚分站动销怎么买',
                'excerpt' => '虾皮是东南亚流量第一梯队电商，新马分站汉服动销高。本地物流友好，形制质量分层——附选店与工厂对照。',
                'content' => h2('区域优势')
                    . p('Shopee 在新加坡、马来西亚等市场渗透深，本地仓与活动价常让「先收到货」体验优于跨洲长尾物流。')
                    . h2('优势')
                    . ul(['分站活动与优惠券密集', '本地/区域物流时效更可读', '国风与新中式日常款供给足'])
                    . h2('风险')
                    . ul(['店铺质量两极，需看评价图与退货率', '标题党与盗图仍常见', '跨分站政策、尺码习惯不同'])
                    . h2('阿玛云建议')
                    . p('身在新马、要极速尝鲜可用 Shopee；要统一质检与产地透明的复购衣身，对比阿玛云工厂直销。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Shopee Hanfu in SG & MY: How Local Velocity Helps',
                'excerpt' => 'Shopee leads SEA traffic; SG/MY markets move Hanfu fast. Local logistics help—quality varies by shop. Compare factory-direct.',
                'content' => h2('Regional edge')
                    . p('Deep penetration in Singapore and Malaysia means local warehouses and campaign pricing often beat slow cross-continent shipping.')
                    . h2('Strengths')
                    . ul(['Frequent vouchers and flash deals', 'More readable regional ETA', 'Plenty of daily New Chinese Style supply'])
                    . h2('Risks')
                    . ul(['Shop quality polarizes—read photo reviews', 'Stolen images and hype titles remain', 'Policies/sizing habits differ by market'])
                    . h2('Amayun tip')
                    . p('Use Shopee for ultra-fast local trials in SG/MY; for repurchase garments with origin transparency, compare Amayun factory-direct.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-marketplaces',
            'cover' => 'lazada',
            'slug' => 'lazada-new-chinese-style-sea',
            'keywords' => 'Lazada hanfu,New Chinese Style SEA,Lazada guofeng',
            'zh' => [
                'title' => 'Lazada 新中式汉服：泛华人商圈的出海通路',
                'excerpt' => '来赞达（阿里系）服务东南亚泛华人消费，新中式改良款走量明显。适合日常国风，考据党需额外验形制。',
                'content' => h2('通路画像')
                    . p('Lazada 连接产业带与东南亚零售，汉元素连衣裙、改良马面等「能出门」的款式更多，正统交领体系相对少。')
                    . h2('适合')
                    . ul(['东南亚本地或留学人群的日常国风', '活动价入手的入门套装', '与本地支付、分期习惯对齐的买家'])
                    . h2('不适合')
                    . ul(['强考据、要文物级结构的买家', '需要完整英文形制科普再决策的用户（内容偏弱）', '只追绝对最低全球价而不在意时效者（应比价多站）'])
                    . h2('对照工厂直销')
                    . p('看中 Lazada 的廓形后，用阿玛云核对同形制面料与工艺；要「物美价廉 + 产地可见」时工厂直销更清晰。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Lazada New Chinese Style: SEA Diaspora Pathway',
                'excerpt' => 'Ali-backed Lazada serves SEA Chinese-speaking shoppers with wearable New Chinese Style. Good daily guofeng—purists must verify structure.',
                'content' => h2('Channel snapshot')
                    . p('Industrial-belt supply into SEA retail: more wearable Han-element dresses and modernized mamian than strict cross-collar systems.')
                    . h2('Good fit')
                    . ul(['Local/SEA diaspora daily guofeng', 'Campaign-priced starter sets', 'Shoppers aligned with local pay/installments'])
                    . h2('Poor fit')
                    . ul(['Archaeology-grade structure seekers', 'Buyers who need deep English education first', 'Global lowest-price hunters ignoring ETA'])
                    . h2('Vs Amayun')
                    . p('After you like a Lazada silhouette, validate fabric/craft with Amayun. Factory-direct stays clearer for fair price plus visible origin.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-vertical-stores',
            'cover' => 'intervene',
            'slug' => 'intervene-new-chinese-designer',
            'keywords' => 'Intervene Design,intervenedesign.com,New Chinese Style designer',
            'zh' => [
                'title' => 'Intervene 评测：新中式设计师通勤华服站',
                'excerpt' => '海外活跃的新中式/汉元素高端独立站，主打日常通勤高阶中国风。适合设计溢价人群；基础形制可工厂直销互补。',
                'content' => h2('定位')
                    . p('Intervene 把中国传统元素翻译成现代通勤语言，强调设计师品牌感而非形制百科。')
                    . h2('亮点')
                    . ul(['通勤可穿的高阶国风剪裁', '视觉与品牌叙事完整', '适合商务/城市日常东方感'])
                    . h2('代价')
                    . ul(['设计与品牌溢价明显', 'SKU 深度服务「设计系列」而非全形制目录', '与产业带基础款价格带不同'])
                    . h2('与阿玛云')
                    . p('设计师款作形象单品；日常襦裙/马面等基础形制交给源头工厂，衣柜结构更健康。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Intervene Review: New Chinese Designer Commute Wear',
                'excerpt' => 'A live high-end New Chinese / Han-element DTC focused on elevated daily wear. Pay for design—pair basics with factory-direct.',
                'content' => h2('Positioning')
                    . p('Translates Chinese motifs into modern commute language—brand design first, not silhouette encyclopedia.')
                    . h2('Highlights')
                    . ul(['Elevated everyday guofeng cuts', 'Cohesive visual brand story', 'Strong for urban/office Eastern looks'])
                    . h2('Trade-offs')
                    . ul(['Clear design/brand premium', 'Assortment serves collections, not full form catalogs', 'Different price band than industrial basics'])
                    . h2('With Amayun')
                    . p('Keep designer heroes for statement looks; fill foundational ruqun/mamian via Amayun factory-direct.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-vertical-stores',
            'cover' => 'dawnxdare',
            'slug' => 'dawn-x-dare-han-element-buyer',
            'keywords' => 'Dawn x Dare,dawnxdare.com,mamian modern,Chinese embroidery fashion',
            'zh' => [
                'title' => 'Dawn × Dare 评测：买手站里的汉元素与刺绣线',
                'excerpt' => '小众高阶时尚买手站，因引入传统刺绣与马面改良单品在欧美出圈。适合潮流买手；正统套装另寻工厂。',
                'content' => h2('它卖的是什么')
                    . p('不是汉服百科商城，而是把东方刺绣、改良马面等单品放进当代买手语境，服务时尚编辑感顾客。')
                    . h2('优势')
                    . ul(['买手审美强，单品「出片」', '刺绣与面料故事有时尚表达', '适合与西式单品混搭'])
                    . h2('局限')
                    . ul(['完整形制体系覆盖有限', '高阶定价', '文化解释深度不如社区型站点'])
                    . h2('互补')
                    . p('买手单品点睛；要齐胸襦裙、圆领袍等系统形制，回到阿玛云工厂直销目录。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Dawn × Dare Review: Buyer Boutique Han-Element Line',
                'excerpt' => 'A niche elevated fashion boutique known for embroidery and modernized mamian drops. Great for editorial looks—not a full Hanfu catalog.',
                'content' => h2('What you buy')
                    . p('Not an encyclopedia store—Eastern embroidery and modernized mamian placed in contemporary buyer language.')
                    . h2('Strengths')
                    . ul(['Strong editorial taste and “camera-ready” pieces', 'Fashion-forward fabric/embroidery stories', 'Easy to mix with Western wardrobe'])
                    . h2('Limits')
                    . ul(['Limited full-form coverage', 'Premium pricing', 'Less cultural depth than community sites'])
                    . h2('Complement')
                    . p('Use boutique pieces as accents; build classical systems (ruqun, yuanling) via Amayun factory-direct.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-vertical-stores',
            'cover' => 'doresuwe',
            'slug' => 'doresuwe-costume-hanfu-formal',
            'keywords' => 'Doresuwe,doresuwe.com,hanfu costume,qipao formal dress',
            'zh' => [
                'title' => 'Doresuwe 评测：礼服站里的古装与汉服品类',
                'excerpt' => '欧美规模化礼服独立站，古装/汉服/旗袍常在销售前列。适合仪式礼服预期；形制党需严格看结构图。',
                'content' => h2('定位')
                    . p('主战场是礼服与仪式造型，「中国传统」是其中品类而非唯一使命，预期应按礼服站管理。')
                    . h2('优势')
                    . ul(['礼服场景 SKU 与尺码选择多', '对欧美仪式审美熟悉', '一站配齐礼服向配件的概率高'])
                    . h2('风险')
                    . ul(['Costume 与正统汉服边界模糊', '内容教育弱于社区/垂直汉服站', '价格含礼服零售模型'])
                    . h2('建议')
                    . p('要舞台/派对戏剧效果可选；要交领右衽可复购衣身，转向工厂直销形制线。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Doresuwe Review: Formalwear Site with Costume & Hanfu',
                'excerpt' => 'A large formal/costume DTC where Chinese traditional and qipao lines often rank. Manage expectations as formalwear—not a Hanfu school.',
                'content' => h2('Positioning')
                    . p('Formal and ceremonial looks first; Chinese traditional is a category, not the whole mission.')
                    . h2('Strengths')
                    . ul(['Broad formal SKUs and size runs', 'Familiar with Western ceremony taste', 'Higher chance of one-cart formal accessories'])
                    . h2('Risks')
                    . ul(['Blurry line between costume and classical Hanfu', 'Weaker education than Hanfu community sites', 'Formal retail pricing model'])
                    . h2('Advice')
                    . p('Choose for stage/party drama; for repurchase cross-collar systems, move to Amayun factory-direct forms.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-vertical-stores',
            'cover' => 'eastmeetsdress',
            'slug' => 'east-meets-dress-chinese-wedding',
            'keywords' => 'East Meets Dress,eastmeetsdress.com,Chinese wedding hanfu,qipao bridal',
            'zh' => [
                'title' => 'East Meets Dress 评测：中式婚礼华服定制站',
                'excerpt' => '海外华人与跨国婚姻家庭中知名的婚礼向独立站，汉服/旗袍定制叙事强。适合婚礼预算；日常款工厂直销更划算。',
                'content' => h2('客群')
                    . p('中式婚礼、敬茶、晚宴等仪式场景，强调「能留下照片的华服」与定制服务体验。')
                    . h2('优势')
                    . ul(['婚礼场景内容完整', '定制沟通流程成熟', '跨文化家庭语境友好'])
                    . h2('局限')
                    . ul(['婚礼溢价', '非婚礼日常衣橱不是长项', '交期需按婚礼日历提前锁'])
                    . h2('预算拆分')
                    . p('婚礼主服可优先定制站；伴娘/亲友或日常国风用阿玛云工厂直销压成本。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'East Meets Dress Review: Chinese Wedding Couture DTC',
                'excerpt' => 'A well-known bridal DTC for diaspora and intercultural families. Strong hanfu/qipao wedding narrative—daily wear is cheaper factory-direct.',
                'content' => h2('Audience')
                    . p('Chinese weddings, tea ceremonies and formal dinners—photo-ready Huafu with custom service.')
                    . h2('Strengths')
                    . ul(['Complete bridal storytelling', 'Mature custom consultation flow', 'Friendly intercultural family context'])
                    . h2('Limits')
                    . ul(['Wedding premium pricing', 'Not optimized for everyday wardrobe', 'Lock dates early on the wedding calendar'])
                    . h2('Budget split')
                    . p('Prioritize couture DTC for the ceremonial hero; outfit bridal party/daily guofeng via Amayun factory-direct.')
                    . ctaAmayun('en_US'),
            ],
        ],
        [
            'category' => 'compare-vertical-stores',
            'cover' => 'soulsfen',
            'slug' => 'soulsfen-oriental-retro-huafu',
            'keywords' => 'Soulsfen,soulsfen.com,oriental retro,Han element coat',
            'zh' => [
                'title' => 'Soulsfen 评测：东方复古华服与古风配饰站',
                'excerpt' => '面向海外售卖中式复古、汉元素长衫与古风配饰的活跃站点。适合氛围向购买；系统形制仍看工厂目录。',
                'content' => h2('风格')
                    . p('复古华服、改良长衫与配饰组合，强调东方氛围与可穿性，而非朝代形制教材。')
                    . h2('优势')
                    . ul(['复古审美统一', '配饰与外搭选择便于成套', '对「国风日常」友好'])
                    . h2('注意')
                    . ul(['汉元素 ≠ 正统汉服，下单前看结构', '价格含品牌零售', '深度科普弱于社区站'])
                    . h2('阿玛云对照')
                    . p('氛围单品可在此类站灵感采购；要可复购的形制衣身与产地透明，选择阿玛云工厂直销。')
                    . ctaAmayun('zh_Hans_CN'),
            ],
            'en' => [
                'title' => 'Soulsfen Review: Oriental Retro Huafu & Accessories',
                'excerpt' => 'An active overseas store for retro Chinese coats, Han-element pieces and accessories. Mood-first shopping—classical systems still favor factories.',
                'content' => h2('Style')
                    . p('Retro Huafu, modernized long coats and accessory sets—atmosphere and wearability over dynasty textbooks.')
                    . h2('Strengths')
                    . ul(['Cohesive retro aesthetic', 'Easy outerwear/accessory bundling', 'Friendly to everyday guofeng looks'])
                    . h2('Watch')
                    . ul(['Han-element ≠ classical Hanfu—check structure', 'Brand retail in the price', 'Lighter education than community sites'])
                    . h2('Vs Amayun')
                    . p('Shop mood pieces for inspiration; fulfill repurchase silhouettes with origin-visible Amayun factory-direct.')
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
    $packs = [
        'zh_Hans_CN' => [$article['slug'], $article['zh']],
        'en_US' => [$article['slug'] . '-en', $article['en']],
    ];
    foreach ($packs as $locale => [$slug, $pack]) {
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
        $id = (int)($row[Post::schema_fields_ID] ?? $row['post_id'] ?? 0);
        echo "+ #{$id} [{$locale}] {$slug}\n";
        ++$created;
    }
}

echo "created={$created} skipped={$skipped}\n";
