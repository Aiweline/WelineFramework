<?php

declare(strict_types=1);

/**
 * Expert editorial facts for the 48 non-ethnic Hanfu R2 topics.
 *
 * These records are intentionally evergreen. Marketplace/store entries define
 * a verification method, not a live ranking, availability, price or shipping claim.
 *
 * @return array<string,array<string,string>>
 */
$profiles = json_decode(<<<'JSON'
{
  "tiktok-shop-hanfu-guide": {
    "subject_zh": "TikTok Shop 汉服渠道",
    "subject_en": "TikTok Shop Hanfu listings",
    "definition_zh": "短视频与直播适合观察上身动态，却不能替代商品页里的形制、面料和售后证据。",
    "definition_en": "Short video and livestreams can show movement, but they do not replace written evidence for garment form, fiber content, sizing and after-sales terms.",
    "evidence_zh": "逐项对照主播展示、详情页平铺图、领型与裙门细节、尺码表单位以及卖家主体；同一要点在三个位置一致才有参考价值。",
    "evidence_en": "Cross-check the host demonstration, flat-lay images, collar and skirt-panel details, measurement units and seller identity; a claim becomes useful only when those sources agree.",
    "risk_zh": "高频剪辑会隐藏透光、错位系带和活动受限，倒计时也容易把“先核对”变成“先付款”。",
    "risk_en": "Fast edits can hide transparency, misplaced ties and restricted movement, while countdown selling can turn verification into an afterthought.",
    "practice_zh": "先截取完整商品信息与退换条件，再用一件可验证的基础款小单测试，而不是按热视频热度判断形制。",
    "practice_en": "Save the complete listing and return terms, then test the seller with one verifiable basic garment instead of treating viral reach as evidence of authenticity."
  },
  "aliexpress-hanfu-europe-sea": {
    "subject_zh": "AliExpress 欧洲与东南亚汉服采购",
    "subject_en": "AliExpress Hanfu buying for Europe and Southeast Asia",
    "definition_zh": "跨境渠道的核心不是店铺排序，而是商品证据、目的地税费、物流节点和可执行退货地址能否同时成立。",
    "definition_en": "The core cross-border question is not store ranking, but whether product evidence, destination charges, tracking milestones and a workable return address all align.",
    "evidence_zh": "核对厘米制成衣尺寸、纤维含量、套装包含件数、发货国与预计清关责任，并保存不同语言页面的同款 SKU。",
    "evidence_en": "Verify finished-garment measurements in centimetres, fiber content, included pieces, dispatch country and customs responsibility, and preserve the same SKU across language versions.",
    "risk_zh": "“亚洲尺码”不是可执行数据，包税或免邮也不等于退货成本可控；平台页面还可能随目的地切换。",
    "risk_en": "“Asian sizing” is not actionable data, and tax-included or free shipping does not make returns inexpensive; platform pages can also change by destination.",
    "practice_zh": "把到手总价、可追踪运输、当地退货费用和活动日期放进同一表格，再决定是否适合婚礼等有截止日的场合。",
    "practice_en": "Place landed cost, trackable transit, local return expense and the event deadline in one worksheet before buying for a wedding or another fixed-date occasion."
  },
  "shein-new-chinese-style-hanfu": {
    "subject_zh": "SHEIN 新中式与汉服商品",
    "subject_en": "SHEIN new-Chinese-style and Hanfu products",
    "definition_zh": "“新中式”是当代审美标签，不自动等于具备交领右衽、马面裙门或圆领袍等可识别结构的汉服。",
    "definition_en": "“New Chinese style” is a contemporary fashion label; it does not automatically indicate identifiable Hanfu construction such as cross-collar closure, mamian panels or a yuanling robe.",
    "evidence_zh": "先看结构线与闭合方式，再看标题关键词；平铺正反面、内侧系带和下装展开图比氛围模特图更能判断品类。",
    "evidence_en": "Read seam lines and closure first, then the title keywords; front/back flat lays, internal ties and an opened skirt reveal more than atmospheric model shots.",
    "risk_zh": "快时尚页面常把盘扣、刺绣或立领当作“中国风”信号，若据此当作复原形制服装购买，预期很容易错位。",
    "risk_en": "Fast-fashion listings often use frog buttons, embroidery or stand collars as generic Chinese-style signals, creating a mismatch if the buyer expects a historically structured garment.",
    "practice_zh": "把它按“当代新中式、汉元素、可识别汉服形制”三档标注，适合日常的混搭款与适合礼仪的形制款分别挑。",
    "practice_en": "Label items as contemporary new Chinese style, Han-inspired fashion or identifiable Hanfu form, then choose everyday hybrids separately from form-specific ceremonial wear."
  },
  "temu-hanfu-accessories-guide": {
    "subject_zh": "Temu 汉服配饰",
    "subject_en": "Temu Hanfu accessories",
    "definition_zh": "发簪、禁步、腰佩和鞋履属于不同承重与使用系统，不能只因同为古风配色就视为一套。",
    "definition_en": "Hairpins, waist pendants, jinbu ornaments and footwear belong to different load and use systems; matching color alone does not make them a coherent set.",
    "evidence_zh": "核对材质、总长、单件重量、针脚或焊点、夹持方式与接触皮肤的边缘，并确认图片展示的是实际件数。",
    "evidence_en": "Check material, total length, per-piece weight, stitching or solder points, fastening method, skin-contact edges and the actual quantity shown.",
    "risk_zh": "头饰过重、流苏勾纱、腰佩响片锐边和鞋底无防滑信息，都会把静态好看变成穿着风险。",
    "risk_en": "Heavy headwear, snagging tassels, sharp pendant edges and shoes without outsole information can turn a good still image into a wearing hazard.",
    "practice_zh": "先按发型固定点、服装领型和活动场景做减法；不确定时选一件主饰，再用低存在感小件补层次。",
    "practice_en": "Edit by hairstyle anchor points, garment neckline and activity level; when uncertain, choose one focal ornament and add only low-profile supporting pieces."
  },
  "hanfu-vertical-stores-top10-overview": {
    "subject_zh": "汉服垂直店铺渠道评估",
    "subject_en": "specialist Hanfu store evaluation",
    "definition_zh": "垂直店铺名单只能作为调研入口，不能被包装成永久排名或“仍在营业”的静态保证。",
    "definition_en": "A specialist-store list is a research starting point, not a permanent ranking or a static guarantee that every seller remains active.",
    "evidence_zh": "以店铺主体、最近上新、可联系售后、形制命名一致性、尺码与面料字段完整度建立同一评分表。",
    "evidence_en": "Use one scorecard for seller identity, recent activity, reachable support, consistent form naming, measurement quality and fiber disclosure.",
    "risk_zh": "品牌故事、粉丝量和精修图容易压过履约证据；跨站转载的旧库存页面也可能造成误判。",
    "risk_en": "Brand stories, follower counts and polished imagery can overshadow fulfillment evidence, while mirrored legacy pages may misrepresent current inventory.",
    "practice_zh": "下单当天重新核验域名、支付主体、退换地址和交期，并把结果写成带日期的渠道记录，而不是绝对推荐。",
    "practice_en": "Re-verify the domain, payment entity, return address and lead time on the purchase date, and record the result as dated due diligence rather than an absolute recommendation."
  },
  "newmoondance-hanfu-review": {
    "subject_zh": "NewMoonDance 汉服渠道",
    "subject_en": "NewMoonDance Hanfu",
    "definition_zh": "对单一店铺的评估应落在可核查页面与订单条件上，而不是用品牌印象代替商品级判断。",
    "definition_en": "A single-store assessment must rest on verifiable pages and order terms rather than substituting brand impression for item-level judgment.",
    "evidence_zh": "抽查不同价格带商品的领型、闭合、套装清单、厘米尺寸与纤维字段，并确认客服答复能对应具体 SKU。",
    "evidence_en": "Sample items across price bands for collar, closure, included pieces, centimetre measurements and fiber fields, and require support answers to reference a specific SKU.",
    "risk_zh": "同店不同供应批次可能使用不同版型与面料，不能把一件样衣体验推广到整站。",
    "risk_en": "Different production batches within one shop may use different blocks and fabrics, so one sample cannot represent the entire catalogue.",
    "practice_zh": "先选结构简单、尺码证据完整的一件做验证单，收货后记录实测与页面差异，再决定是否复购复杂礼服。",
    "practice_en": "Start with one structurally simple, well-documented item, record received measurements against the listing, then decide whether to order complex formalwear."
  },
  "nuwa-hanfu-review": {
    "subject_zh": "Nuwa Hanfu 渠道",
    "subject_en": "Nuwa Hanfu",
    "definition_zh": "店名或东方神话意象不构成形制证明，判断仍要回到服装结构与商品披露。",
    "definition_en": "A mythic or Chinese-themed brand identity is not evidence of garment form; the decision still returns to construction and disclosure.",
    "evidence_zh": "检查交领是否右衽、圆领是否形成完整领圈、马面裙是否能看到裙门与褶群，并核对面料写的是纤维还是织法。",
    "evidence_en": "Check right-over-left cross collars, a complete yuanling neckline, visible mamian panels and pleat groups, and distinguish fiber composition from weave names.",
    "risk_zh": "只给正面造型照、混用 cosplay 与汉服标签、缺少成衣尺寸，会让分类和合身度都无法验证。",
    "risk_en": "Front-only styling photos, mixed cosplay/Hanfu labels and missing finished measurements make both classification and fit unverifiable.",
    "practice_zh": "为每个候选款写下“形制、纤维、成衣尺寸、售后”四项证据，任何一项为空都先询问再付款。",
    "practice_en": "For every candidate, compare form, fiber, finished measurements and after-sales terms, and ask before paying whenever one of them is missing."
  },
  "hanfu-story-review": {
    "subject_zh": "Hanfu Story 渠道",
    "subject_en": "Hanfu Story",
    "definition_zh": "内容型品牌擅长讲故事，但购买评估必须把文化叙事与商品规格分成两层阅读。",
    "definition_en": "A story-led retailer may offer rich cultural framing, but purchase evaluation must separate narrative from product specifications.",
    "evidence_zh": "文化说明要能对应具体领型、穿着顺序或纹样出处；商品层则要有实拍、成衣尺寸、纤维和包含件数。",
    "evidence_en": "Cultural notes should map to a specific collar, dressing sequence or motif source, while the product layer needs real photos, measurements, fiber and included pieces.",
    "risk_zh": "宽泛朝代标签若没有结构证据，容易把现代改良款误读为复原款；故事不能弥补退换条件缺失。",
    "risk_en": "Broad dynasty labels without structural evidence can recast a modern adaptation as reconstruction, and storytelling cannot compensate for missing return terms.",
    "practice_zh": "把叙事内容当作阅读线索，把规格和售后当作购买门槛；两者都清楚才适合用于文化活动。",
    "practice_en": "Treat narrative as a reading guide and specifications/returns as purchase gates; both must be clear before using the garment in cultural programming."
  },
  "newhanfu-store-review": {
    "subject_zh": "NewHanfu Store 渠道",
    "subject_en": "NewHanfu Store",
    "definition_zh": "站点名称中的 Hanfu 不是全站商品自动合格证，仍需逐款判断传统形制、现代改良或表演服。",
    "definition_en": "Hanfu in a store name is not a catalogue-wide certification; each item still needs classification as form-specific Hanfu, modern adaptation or stagewear.",
    "evidence_zh": "用领型、衣身裁片、下装结构和系带位置识别形制，再核对商品文字是否与图片一致。",
    "evidence_en": "Identify form through neckline, body panels, lower-garment construction and tie placement, then compare those observations with the listing text.",
    "risk_zh": "聚合型站点可能同时收录不同供应商，图像风格统一不代表版型、质检和售后一致。",
    "risk_en": "An aggregator may carry multiple suppliers; consistent art direction does not guarantee consistent pattern blocks, quality control or support.",
    "practice_zh": "按 SKU 保存证据，不按站点统一打分；优先选择给出正反平铺、尺码方法与可追踪售后的款式。",
    "practice_en": "Save evidence by SKU rather than assigning one store-wide score, prioritizing items with front/back flat lays, measurement method and traceable support."
  },
  "fashion-hanfu-review": {
    "subject_zh": "Fashion Hanfu 渠道",
    "subject_en": "Fashion Hanfu",
    "definition_zh": "“fashion”导向常强调当代穿搭，适合与考据形制分开评价，避免用同一标准压平不同用途。",
    "definition_en": "A fashion-led assortment often prioritizes contemporary styling and should be evaluated separately from reconstruction-oriented garments.",
    "evidence_zh": "先判断页面承诺的是日常改良、汉元素还是具体形制，再核对剪裁、面料与活动量是否支持该用途。",
    "risk_zh": "轮廓借鉴与历史复原若不标明边界，消费者容易在礼仪、舞台和通勤场景中选错。",
    "risk_en": "When silhouette inspiration and historical reconstruction are not distinguished, buyers can choose incorrectly for ritual, stage and commuting contexts.",
    "practice_zh": "给商品加上“形制准确度”和“日常可穿度”两条独立评分，不让好穿等同于考据，也不让考据等同于舒适。",
    "practice_en": "Score form accuracy and everyday wearability independently; wearability is not proof of reconstruction, and reconstruction does not guarantee comfort.",
    "evidence_en": "First identify whether the listing promises everyday adaptation, Han-inspired fashion or a named form, then verify cut, fiber and mobility for that use."
  },
  "intervene-new-chinese-designer": {
    "subject_zh": "Intervene 新中式设计",
    "subject_en": "Intervene new-Chinese-style design",
    "definition_zh": "设计师新中式可借用盘扣、斜襟、织锦或留白，但不应因此被自动命名为某一汉服形制。",
    "definition_en": "Designer new-Chinese-style pieces may use frog fastenings, diagonal fronts, brocade or restrained palettes without becoming a named Hanfu form.",
    "evidence_zh": "阅读设计语言时分开记录廓形来源、闭合结构、面料与装饰；只有结构证据支持时才使用襦裙、褙子等术语。",
    "evidence_en": "Record silhouette reference, closure, fabric and decoration separately, using terms such as ruqun or beizi only when construction supports them.",
    "risk_zh": "把所有东方元素都归入汉服，会同时误读当代设计与传统服装，也让消费者对场合产生错误预期。",
    "risk_en": "Calling every East Asian design cue Hanfu misreads both contemporary design and traditional dress, while creating false occasion expectations.",
    "practice_zh": "日常搭配可关注比例和材质协调；文化说明则明确写“新中式/汉元素”，避免借历史权威包装当代款。",
    "practice_en": "For daily styling, focus on proportion and material harmony; for cultural description, label it clearly as new Chinese style or Han-inspired rather than borrowing historical authority."
  },
  "dawn-x-dare-han-element-buyer": {
    "subject_zh": "Dawn x Dare 汉元素商品",
    "subject_en": "Dawn x Dare Han-inspired products",
    "definition_zh": "汉元素是当代设计范畴，重点在元素如何转译，不需要假装成完整历史形制。",
    "definition_en": "Han-inspired fashion is a contemporary design category; the question is how elements are translated, not whether the result imitates a complete historical form.",
    "evidence_zh": "检查被引用的是领型、纹样、色彩还是面料，并观察它与现代版型、拉链或口袋的连接是否合理。",
    "evidence_en": "Identify whether the reference lies in neckline, motif, color or textile, and assess how it connects to modern patterning, zips or pockets.",
    "risk_zh": "模糊的朝代故事和过度“国风”标签会遮住实际材质、耐用度与尺码信息。",
    "risk_en": "Vague dynasty stories and excessive guofeng labeling can obscure actual material, durability and measurement information.",
    "practice_zh": "按现代服装标准判断合身、车缝和洗护，再用准确术语说明汉元素来源，适合通勤但不冒充礼制服。",
    "practice_en": "Judge fit, sewing and care as modern apparel, then describe the Han-inspired source accurately; it may suit commuting without posing as ceremonial dress."
  },
  "doresuwe-costume-hanfu-formal": {
    "subject_zh": "Doresuwe 礼服、表演服与汉服页面",
    "subject_en": "Doresuwe formalwear, costume and Hanfu listings",
    "definition_zh": "礼服、舞台服与汉服可以共享视觉语言，却在结构、耐用度和场合要求上不同。",
    "definition_en": "Formalwear, stage costume and Hanfu can share visual language while differing in construction, durability and occasion requirements.",
    "evidence_zh": "确认商品类别、闭合方式、里料、裙摆层数、实际包含件数与成衣尺寸，不以标题中的 costume 或 Hanfu 单词直接定性。",
    "evidence_en": "Confirm listing category, closure, lining, skirt layers, included pieces and finished measurements rather than classifying from the words costume or Hanfu alone.",
    "risk_zh": "舞台灯光照片会放大闪度并隐藏薄料，礼服尺码逻辑也未必适合多层汉服穿着。",
    "risk_en": "Stage-lit photography can exaggerate shine and hide thin fabric, while formalwear sizing logic may not accommodate layered Hanfu dressing.",
    "practice_zh": "表演用途先测试动作与灯光，礼仪用途先核对形制与面料；两种需求都要预留修改和到货时间。",
    "practice_en": "For performance, test movement and lighting; for ritual use, verify form and fiber, allowing alteration and delivery time in both cases."
  },
  "east-meets-dress-chinese-wedding": {
    "subject_zh": "East Meets Dress 中式婚礼服",
    "subject_en": "East Meets Dress Chinese wedding attire",
    "definition_zh": "中式婚礼可容纳汉服、旗袍、裙褂与当代混合礼服，专业选择首先要明确服装类别与仪式语境。",
    "definition_en": "A Chinese wedding may include Hanfu, qipao, qungua and contemporary hybrids; professional selection begins by naming the category and ritual context.",
    "evidence_zh": "记录仪式环节、站坐动作、摄影光线、配偶服装与家族期待，再核对服装结构、面料和交付修改政策。",
    "evidence_en": "Record ceremony stages, sitting/standing movement, photography light, partner attire and family expectations, then verify construction, fabric and alteration policy.",
    "risk_zh": "把所有红色中式礼服都称汉服会混淆传统；仅看棚拍也会忽视敬茶、行走和长时间坐席的活动量。",
    "risk_en": "Calling every red Chinese wedding garment Hanfu blurs traditions, while studio images omit tea ceremony, walking and prolonged sitting.",
    "practice_zh": "先确定仪式服装语言，再选主服与换装；明确标注“汉服婚服、旗袍或当代中式”，比追求混合式考据更诚实。",
    "practice_en": "Choose the ceremony’s clothing language before selecting primary and change looks; clearly label Hanfu wedding wear, qipao or contemporary Chinese design."
  },
  "soulsfen-oriental-retro-huafu": {
    "subject_zh": "Soulsfen 东方复古华服",
    "subject_en": "Soulsfen oriental-retro dress",
    "definition_zh": "“东方复古”是宽泛审美，可能跨越汉元素、新中式与其他亚洲服装，不能替代具体类别。",
    "definition_en": "“Oriental retro” is a broad aesthetic that may span Han-inspired, new Chinese and other Asian dress; it cannot replace a precise category.",
    "evidence_zh": "从领口、闭合、袖型、下装结构与配饰来源逐层识别，再核对品牌自己的命名是否克制。",
    "evidence_en": "Identify neckline, closure, sleeve, lower-garment structure and accessory origin in layers, then assess whether the brand’s naming is appropriately restrained.",
    "risk_zh": "混搭摄影容易把不同文化的元素压成单一“东方感”，既影响专业性，也可能造成文化误标。",
    "risk_en": "Mixed styling can flatten different cultures into one “Eastern” look, reducing accuracy and risking cultural mislabeling.",
    "practice_zh": "把审美灵感与服装身份分开书写；穿搭可以混合，产品说明与文化教育必须把来源说清楚。",
    "practice_en": "Write aesthetic inspiration separately from garment identity; styling may mix, but product copy and cultural education should name sources clearly."
  },
  "what-is-hanfu-complete-guide": {
    "subject_zh": "汉服基础定义",
    "subject_en": "the definition of Hanfu",
    "definition_zh": "汉服不是单一朝代的制服，而是汉族传统服饰体系中可由交领右衽、上衣下裳、袍服等结构读取的一组历史形制。",
    "definition_en": "Hanfu is not one dynasty’s uniform but a family of historical Han dress forms readable through structures such as right-over-left cross collars, upper-and-lower garments and robes.",
    "evidence_zh": "判断时依次看领型、襟向、衣身裁片、袖型、腰线、下装结构与穿着顺序，纹样和发饰只能作为辅助。",
    "evidence_en": "Read neckline, lapel direction, body panels, sleeves, waistline, lower-garment construction and dressing sequence; motifs and hair accessories are secondary.",
    "risk_zh": "用古风、国风或影视造型替代结构判断，会把汉元素、影楼装和其他民族服饰混入同一概念。",
    "risk_en": "Substituting gufeng aesthetics or screen costume for structural reading collapses Han-inspired fashion, studio costume and other ethnic dress into one label.",
    "practice_zh": "入门先掌握一套可观察术语，再比较实物、博物馆藏品与可靠复原说明，而不是背朝代标签。",
    "practice_en": "Begin with observable construction terms, then compare garments with museum objects and documented reconstructions rather than memorizing dynasty labels."
  },
  "hanfu-styles-ruqun-mamian-yuanling": {
    "subject_zh": "襦裙、马面裙与圆领袍分类",
    "subject_en": "ruqun, mamian and yuanling classification",
    "definition_zh": "襦裙描述上衣下裙组合，马面裙描述带裙门与褶群的下装结构，圆领袍则以完整圆领和袍身为核心，三者不是同层级同部位的词。",
    "definition_en": "Ruqun names an upper-and-skirt ensemble, mamian names a panel-and-pleat skirt construction, and yuanling names a round-collar robe; they are not equivalent terms at one anatomical level.",
    "evidence_zh": "用“套装结构—单件结构—领型与袍身”分层看图，尤其要求马面裙展开图与圆领完整领圈。",
    "evidence_en": "Read images by ensemble structure, individual garment construction, then neckline and robe body, requiring an opened mamian view and a complete yuanling collar.",
    "risk_zh": "只按模特姿势或朝代滤镜分类，最常出现圆领文章配交领图、马面裙文章只露上衣的错配。",
    "risk_en": "Classifying by pose or dynasty filter produces the common mismatch of cross-collar images in yuanling articles or mamian articles that show only the top.",
    "practice_zh": "给每件商品写“它是什么、证据在哪里、没有展示什么”三行注释，缺少关键视角就不要下专业结论。",
    "practice_en": "Annotate every item with what it is, where the evidence appears and what is not shown; withhold a professional conclusion when a critical view is missing."
  },
  "hanfu-through-dynasties-tang-song-ming": {
    "subject_zh": "唐、宋、明服饰线索",
    "subject_en": "Tang, Song and Ming dress cues",
    "definition_zh": "朝代风格是多时段、多阶层和多地区材料的概括，不能用一条从宽大到清雅再到端庄的线性故事代替。",
    "definition_en": "Dynasty style summarizes many periods, classes and regions; it cannot be reduced to a linear story from opulent to restrained to formal.",
    "evidence_zh": "把考古、传世图像、制度文本和实物服装分开标注证据等级，并说明现代复原在哪些环节做了推定。",
    "evidence_en": "Separate archaeological finds, transmitted images, institutional texts and surviving garments by evidence level, noting where modern reconstruction makes an inference.",
    "risk_zh": "影楼配色、现代面料和跨朝代配件若被当作历史证据，会制造看似完整却无法追溯的套装。",
    "risk_en": "Treating studio palettes, modern textiles and cross-period accessories as historical evidence creates a coherent-looking but untraceable ensemble.",
    "practice_zh": "学习时围绕一个可验证结构纵向比较，不急于用整套造型代表朝代；穿着时诚实标注复原、参考或改良。",
    "practice_en": "Compare one verifiable structure over time rather than letting one styled ensemble represent a dynasty; label dress as reconstruction, reference or adaptation."
  },
  "hanfu-fabrics-embroidery-green-manufacturing": {
    "subject_zh": "汉服面料、刺绣与绿色制造",
    "subject_en": "Hanfu fabrics, embroidery and greener manufacturing",
    "definition_zh": "纤维、织物组织、染整与装饰工艺是四个层次；“真丝感”“织金感”或“手工感”都不是成分证明。",
    "definition_en": "Fiber, weave structure, finishing and decoration are four different layers; “silk feel,” “gold-woven look” and “handmade feel” are not composition evidence.",
    "evidence_zh": "要求成分百分比、织法名称的适用位置、刺绣正反面、里衬与边缘处理，并把环保声明对应到可核查材料或流程。",
    "evidence_en": "Request composition percentages, where a named weave is used, embroidery front/back, lining and edge finishing, and tie sustainability claims to verifiable material or process evidence.",
    "risk_zh": "把机绣说成手绣、把聚酯织锦说成丝绸、把少包装说成整体绿色制造，都会误导价格与护理判断。",
    "risk_en": "Calling machine embroidery handwork, polyester brocade silk, or reduced packaging a fully green process misleads price and care decisions.",
    "practice_zh": "先按用途选择耐磨、垂坠与透气，再记录纤维和工艺证据；可持续比较要包含使用次数、维修和寿命。",
    "practice_en": "Choose abrasion, drape and breathability for the use, document fiber and process, and include wear count, repairability and lifespan in sustainability comparisons."
  },
  "hanfu-occasions-daily-wedding-festival": {
    "subject_zh": "汉服日常、婚礼与节令场合",
    "subject_en": "daily, wedding and festival Hanfu",
    "definition_zh": "场合不是用华丽程度排序，而是由动作、时长、礼仪角色、天气与摄影需求共同决定服装层级。",
    "definition_en": "Occasion is not a scale of ornament; movement, duration, ritual role, weather and photography together determine the clothing level.",
    "evidence_zh": "记录走坐、交通、如厕、室内外温差、是否担任主礼以及需要佩戴的配件，再匹配版型和层数。",
    "evidence_en": "Record walking, sitting, transport, restroom access, indoor/outdoor temperature, ceremonial role and required accessories before matching form and layers.",
    "risk_zh": "把婚服当全天活动服、把写真造型当通勤服、把大型头饰带进拥挤场所，都会造成安全与舒适问题。",
    "risk_en": "Using wedding dress for an all-day event, a studio look for commuting, or large headwear in crowds creates safety and comfort problems.",
    "practice_zh": "先选满足活动的基础层，再增加可拆卸礼仪层；为重要场合安排完整试穿和备用固定件。",
    "practice_en": "Choose a movement-capable base first, add removable ceremonial layers, and schedule a full dress rehearsal with spare fasteners for important events."
  },
  "amayun-technology-company-story": {
    "subject_zh": "Amayun Technology 企业故事",
    "subject_en": "the Amayun Technology company story",
    "definition_zh": "企业叙事应把可核实的时间、团队职责、产品方法与仍属愿景的部分分开，不用宏大措辞代替证据。",
    "definition_en": "A company story should separate verifiable dates, team responsibilities and product methods from aspirations instead of replacing evidence with grand claims.",
    "evidence_zh": "对外内容标注资料来源、发生时间、参与角色和可复查记录；产品页面只引用能落到 SKU 或流程文件的事实。",
    "evidence_en": "Public copy should identify source, date, participating role and retrievable record, while product pages use only facts traceable to a SKU or process document.",
    "risk_zh": "“科技赋能传统”“行业领先”等无范围表述会迅速失去可信度，也容易与实际供应链能力不匹配。",
    "risk_en": "Unbounded claims such as “technology empowers tradition” or “industry-leading” lose credibility and may overstate supply-chain capability.",
    "practice_zh": "用问题、方法、已完成证据、下一步四段写故事；对于合作、产地和工艺声明保留可审计附件。",
    "practice_en": "Write the story as problem, method, completed evidence and next step, retaining auditable support for partnership, origin and craft claims."
  },
  "amayun-origin-visits-factory-partners": {
    "subject_zh": "Amayun 产地走访与工厂合作",
    "subject_en": "Amayun origin visits and factory partnerships",
    "definition_zh": "一次走访能证明当日观察，不自动证明长期产能、全部订单来源或持续合规。",
    "definition_en": "One visit proves what was observed that day; it does not automatically prove long-term capacity, origin for every order or continuing compliance.",
    "evidence_zh": "记录地点、日期、被观察工序、样品批次、受访角色与不能进入的环节，并把照片对应到具体说明。",
    "evidence_en": "Record place, date, observed process, sample batch, interview role and inaccessible stages, matching each photo to a specific statement.",
    "risk_zh": "把合作方样板间当成整条供应链、把拍摄授权当成产品授权，都会放大事实范围。",
    "risk_en": "Treating a partner showroom as the entire supply chain, or photo permission as product authorization, expands claims beyond the evidence.",
    "practice_zh": "对每项产地与合作声明设定证据有效期，更新时重新核验；不能确认的环节明确写“未核实”。",
    "practice_en": "Assign an evidence date to every origin and partnership claim, re-verify on update, and label unconfirmed stages explicitly."
  },
  "amayun-handmade-green-mechanical-production": {
    "subject_zh": "Amayun 手工、绿色与机械生产说明",
    "subject_en": "Amayun handwork, sustainability and machine production",
    "definition_zh": "手工与机械不是价值高低的二元对立，专业说明要指出哪一道工序由谁、用什么设备完成。",
    "definition_en": "Hand and machine work are not a simple value hierarchy; professional disclosure names which stage is performed by whom and with what equipment.",
    "evidence_zh": "把裁剪、缝制、绣花、整烫、质检与包装拆开记录，并为节材、能源、废料与耐用声明提供量化边界。",
    "evidence_en": "Document cutting, sewing, embroidery, pressing, inspection and packaging separately, placing measurable boundaries around material, energy, waste and durability claims.",
    "risk_zh": "用“纯手工”覆盖机绣或工业缝制、用“环保”覆盖单一包装变化，会误导价格和责任判断。",
    "risk_en": "Using “fully handmade” over machine embroidery or industrial sewing, or “eco-friendly” for one packaging change, misleads both value and responsibility.",
    "practice_zh": "采用工序表和证据日期；消费者端说明应简短但可追溯，内部保留供应商与批次记录。",
    "practice_en": "Use a process matrix and evidence dates; customer copy can be concise but traceable, while internal records retain supplier and batch detail."
  },
  "hanfu-daily-commute-styling": {
    "subject_zh": "汉服日常通勤穿搭",
    "subject_en": "daily-commute Hanfu styling",
    "definition_zh": "通勤汉服首先是动作系统：步幅、坐姿、楼梯、背包和外套叠穿必须比照片层次更早考虑。",
    "definition_en": "Commuter Hanfu is first a movement system: stride, sitting, stairs, bags and outerwear layering come before visual complexity.",
    "evidence_zh": "试穿时记录袖口是否碰桌、裙长是否踩踏、系带是否被包带挤压、坐下后腰围余量与鞋底抓地。",
    "evidence_en": "During fitting, record sleeve contact with desks, hem clearance, tie pressure under bag straps, seated waist ease and outsole grip.",
    "risk_zh": "过长广袖、松散禁步、无口袋又携带大包，以及只按净体尺寸购买，都会降低可重复穿着率。",
    "risk_en": "Overlong sleeves, loose pendants, pocketless outfits with large bags and body-only sizing all reduce repeat wear.",
    "practice_zh": "优先齐腰或马面等可控下装与轻外搭，配件只留一个焦点；连续坐走二十分钟后再判断合身。",
    "practice_en": "Prioritize controllable qiyao or mamian bottoms and a light outer layer, keep one accessory focal point, and judge fit after twenty minutes of sitting and walking."
  },
  "hanfu-wedding-festival-styling": {
    "subject_zh": "汉服婚礼与节庆穿搭",
    "subject_en": "Hanfu wedding and festival styling",
    "definition_zh": "婚礼强调角色与礼序，节庆强调移动与公共环境；同一套华服不一定能同时满足两者。",
    "definition_en": "Weddings emphasize role and ritual sequence, while festivals emphasize movement and public space; one ornate look may not satisfy both.",
    "evidence_zh": "列出仪式动作、换装节点、摄影色温、天气、场地规则与配件固定方式，再决定层数和拖地长度。",
    "evidence_en": "List ritual movements, outfit changes, photography color temperature, weather, venue rules and accessory anchoring before choosing layers and train length.",
    "risk_zh": "未经完整试穿的云肩、凤冠、长披帛或拖裙可能与安全通道、座椅和麦克风冲突。",
    "risk_en": "Untested cloud collars, crowns, long pibo or trains can conflict with exits, chairs and microphones.",
    "practice_zh": "婚礼做全流程彩排，节庆保留可收纳层与轻量头饰；两者都准备备用系带和不破坏面料的固定件。",
    "practice_en": "Rehearse the full wedding sequence; for festivals, retain packable layers and light headwear, with spare ties and non-damaging fasteners for both."
  },
  "hanfu-hair-makeup-accessories-guide": {
    "subject_zh": "汉服发型、妆容与配饰",
    "subject_en": "Hanfu hair, makeup and accessories",
    "definition_zh": "发型与配饰的任务是支持领型、头肩比例和场合，不是把所有古风物件同时堆上身。",
    "definition_en": "Hair and accessories should support neckline, head-to-shoulder proportion and occasion rather than stacking every gufeng object at once.",
    "evidence_zh": "从发量与固定点、簪钗重量、耳饰摆幅、领口留白、妆面光泽和活动时间逐项测试。",
    "evidence_en": "Test hair volume and anchor points, ornament weight, earring swing, neckline breathing room, makeup sheen and wear duration.",
    "risk_zh": "尖锐簪脚、重冠、流苏勾纱、肤色与摄影灯不匹配，都可能让静态造型在现场失效。",
    "risk_en": "Sharp pins, heavy crowns, snagging tassels and makeup mismatched to camera light can make a still look fail in use.",
    "practice_zh": "先完成服装和发型轮廓，再加一件主饰与少量呼应；重要场合做摇头、低头和拥抱测试。",
    "practice_en": "Complete garment and hair silhouette first, then add one focal ornament and small echoes; test turning, bowing and hugging for important events."
  },
  "hanfu-size-chart-care-guide": {
    "subject_zh": "汉服尺码与护理",
    "subject_en": "Hanfu sizing and care",
    "definition_zh": "净体尺寸、成衣尺寸与穿着余量必须分开；护理则只依据纤维、装饰和卖家标签，不能按“汉服”统一处理。",
    "definition_en": "Body measurements, finished-garment measurements and wearing ease must be separate; care follows fiber, decoration and maker label rather than one universal Hanfu rule.",
    "evidence_zh": "至少核对肩宽、胸围、腰围、衣长、通袖或袖长、裙长及测量方法，并记录是否需要内搭。",
    "evidence_en": "Verify shoulder, chest, waist, garment length, sleeve span or sleeve length, skirt length and measurement method, including planned underlayers.",
    "risk_zh": "只按身高体重推荐、混用平铺半围与整圈围度、未知工艺直接机洗，都会造成不可逆问题。",
    "risk_en": "Height/weight-only recommendations, mixing half and full circumference, and machine-washing unknown decoration can cause irreversible problems.",
    "practice_zh": "用现有合身衣服对照成衣尺寸；首次护理先读标签并做隐蔽处测试，复杂织金、刺绣或装饰件优先咨询专业清洁。",
    "practice_en": "Compare finished measurements with a garment that already fits; read the label and spot-test first, seeking specialist cleaning for complex brocade, embroidery or applied ornament."
  },
  "hanfu-buying-guides-hub": {
    "subject_zh": "汉服购买指南体系",
    "subject_en": "the Hanfu buying-guide hub",
    "definition_zh": "购买指南应把形制、场合、尺码、面料、渠道与售后串成决策路径，而不是堆叠店铺链接。",
    "definition_en": "A buying hub should connect form, occasion, sizing, material, channel and after-sales into a decision path rather than accumulate store links.",
    "evidence_zh": "每个分支都给出输入问题、最低证据、停止条件和下一篇深读入口，让读者知道何时不能下结论。",
    "evidence_en": "Each branch should state the input question, minimum evidence, stop condition and next deep-dive, including when no conclusion is justified.",
    "risk_zh": "只按预算或热度跳转会忽略形制错配、活动量与跨境退货，信息多反而增加误购。",
    "risk_en": "Routing only by budget or popularity ignores form mismatch, movement and cross-border returns, turning more information into more mis-purchases.",
    "practice_zh": "先完成用途与身体尺寸，再进入具体形制和渠道；任何指南都在下单当天重新核对可变信息。",
    "practice_en": "Complete use case and body measurements before selecting form and channel, and re-check changing information on the day of purchase."
  },
  "hanfu-beginner-buyer-checklist": {
    "subject_zh": "汉服新手购买清单",
    "subject_en": "the beginner Hanfu buying checklist",
    "definition_zh": "第一套的目标是建立正确观察与穿着反馈，不是一次买齐所有形制、发型和配饰。",
    "definition_en": "The first outfit should build accurate observation and wearing feedback, not complete every form, hairstyle and accessory at once.",
    "evidence_zh": "准备身体与成衣对照尺寸、场合、预算、可接受面料、关键结构图和退换期限六项信息。",
    "evidence_en": "Prepare body and reference-garment measurements, occasion, budget, acceptable fibers, critical construction images and return deadline.",
    "risk_zh": "套装件数不清、尺码只看身高体重、配饰先于服装购买，是新手最常见的三类成本。",
    "risk_en": "Unclear set contents, height/weight-only sizing and buying accessories before the garment are three common beginner costs.",
    "practice_zh": "先选颜色和结构清楚、活动量容易测试的基础款；收货当天平铺实测并完成坐走抬手检查。",
    "practice_en": "Choose a clearly structured, easy-to-test basic garment, measure it flat on arrival and complete sitting, walking and arm-raising checks."
  },
  "choose-first-mamian-or-ruqun": {
    "subject_zh": "第一条马面裙或第一套襦裙",
    "subject_en": "choosing a first mamian skirt or ruqun",
    "definition_zh": "马面裙是可与现代上衣搭配的单件下装，襦裙是上衣下裙的组合；选择取决于学习目标与生活场景。",
    "definition_en": "A mamian skirt is a separable lower garment compatible with modern tops, while ruqun is an upper-and-skirt ensemble; the choice depends on learning goal and daily context.",
    "evidence_zh": "比较腰部固定、裙门或裙头结构、上衣闭合、所需内搭、步幅和现有衣橱兼容度。",
    "evidence_en": "Compare waist fastening, mamian panels or skirt head, upper closure, required underlayers, stride and compatibility with the existing wardrobe.",
    "risk_zh": "把所有褶裙称马面、或只买襦裙下装却期待完整形制，都会导致分类与搭配错误。",
    "risk_en": "Calling every pleated skirt mamian, or buying only a ruqun skirt while expecting a complete ensemble, creates classification and styling errors.",
    "practice_zh": "想提高复穿率可从结构清楚的马面裙开始；想学习完整穿着顺序则选套装信息完整的襦裙。",
    "practice_en": "For repeat wear, start with a clearly constructed mamian; for learning a complete dressing sequence, choose a ruqun with fully documented set contents."
  },
  "amayun-factory-direct-value-explained": {
    "subject_zh": "Amayun 工厂直达价值",
    "subject_en": "Amayun factory-direct value",
    "definition_zh": "工厂直达只描述链路长度，不能自动证明最低价、最好质量或某一工艺来源。",
    "definition_en": "Factory-direct describes channel length; it does not automatically prove the lowest price, best quality or a particular craft origin.",
    "evidence_zh": "价值说明应列出规格、批次、质检节点、售后责任与价格包含项，并区分自有生产、合作生产和采购。",
    "evidence_en": "A value claim should disclose specification, batch, inspection points, after-sales responsibility and price inclusions, distinguishing owned production, partner production and sourcing.",
    "risk_zh": "省去中间环节的口号若没有履约和质检证据，只会把渠道故事当作产品质量证明。",
    "risk_en": "A “cutting out the middleman” slogan without fulfillment and inspection evidence turns a channel story into an unsupported quality claim.",
    "practice_zh": "比较同规格到手总价与可执行售后，而非只比挂牌价；所有直达声明保留日期和责任主体。",
    "practice_en": "Compare landed cost and workable after-sales for the same specification rather than list price alone, dating every direct-channel claim and naming the responsible entity."
  },
  "world-ethnic-dress-and-hanfu": {
    "subject_zh": "世界民族服饰与汉服比较",
    "subject_en": "world ethnic dress and Hanfu comparison",
    "definition_zh": "跨文化比较的目标是理解不同结构、材料与礼仪系统，不是寻找谁更古老或把相似轮廓归为同源。",
    "definition_en": "Cross-cultural comparison should explain different systems of construction, material and etiquette, not rank antiquity or infer shared origin from similar silhouettes.",
    "evidence_zh": "在同一维度比较领型、闭合、层次、纤维、制作与场合，并为每一种传统使用其自身术语和地区来源。",
    "evidence_en": "Compare neckline, closure, layers, fibers, making and occasion on the same dimensions, using each tradition’s own terms and regional sources.",
    "risk_zh": "只凭照片颜色或长袍轮廓类比，会忽略宗教、性别、身份与殖民历史，也容易把他者服饰汉服化。",
    "risk_en": "Comparing by color or robe outline alone ignores religion, gender, status and colonial history, while recasting other traditions through a Hanfu lens.",
    "practice_zh": "先完成单一传统的内部理解，再做有限维度对照；展陈与电商文案明确文化归属和当代语境。",
    "practice_en": "Understand each tradition internally before making a limited comparison, and state cultural ownership and contemporary context in exhibits or commerce."
  },
  "kimono-hanbok-aodai-vs-hanfu": {
    "subject_zh": "和服、韩服、奥黛与汉服比较",
    "subject_en": "kimono, hanbok, áo dài and Hanfu comparison",
    "definition_zh": "四者分别属于不同历史与国家/族群语境，局部交叠或文化交流不等于可以互换命名。",
    "definition_en": "These traditions belong to different historical and national or ethnic contexts; exchange and visual overlap do not make their names interchangeable.",
    "evidence_zh": "分别观察和服直线裁片与腰带、韩服短衣与高腰裙、奥黛长衫与裤装、汉服领襟与上衣下裳系统。",
    "evidence_en": "Observe kimono straight panels and obi, hanbok jeogori and high-waist skirt, áo dài tunic and trousers, and Hanfu lapel and upper/lower systems.",
    "risk_zh": "用“亚洲古装”统称或只按领口辨认，会抹平内部变化并导致配图错置。",
    "risk_en": "Grouping them as “Asian costume” or identifying by neckline alone erases internal variation and misplaces images.",
    "practice_zh": "比较时用中性结构词，介绍时先说自身名称、地区与场合；混搭造型也要标注来源。",
    "practice_en": "Use neutral construction terms in comparison, introduce each by its own name, region and occasion, and label sources even in mixed styling."
  },
  "sari-sarong-southeast-south-asia-dress": {
    "subject_zh": "纱丽、纱笼与南亚东南亚服饰",
    "subject_en": "sari, sarong and South/Southeast Asian dress",
    "definition_zh": "纱丽是具有多种地区穿法的未裁剪裹布体系，纱笼则是跨地区筒状或裹系下装，不能以“长裙”概括。",
    "definition_en": "The sari is an unstitched drape with many regional styles, while sarong names tubular or wrapped lower garments across regions; neither is adequately described as a long skirt.",
    "evidence_zh": "记录布幅、缠裹方向、内搭、固定方式、地区名称与场合，再与汉服的裁片和系带结构做有限比较。",
    "evidence_en": "Record cloth dimensions, drape direction, underlayers, fastening, regional name and occasion before making a limited comparison with Hanfu panels and ties.",
    "risk_zh": "把不同国家的裹布服饰视为同一热带风格，会忽略宗教礼仪、性别习惯与殖民后的变化。",
    "risk_en": "Treating wrapped dress from different countries as one tropical style ignores religious etiquette, gender practice and post-colonial change.",
    "practice_zh": "尊重当地名称和穿法来源；借用或销售时提供完整套件说明，不用汉服术语替代当地术语。",
    "practice_en": "Respect local names and dressing sources; when borrowing or selling, explain the complete ensemble and do not replace local terms with Hanfu vocabulary."
  },
  "mena-africa-traditional-dress-guide": {
    "subject_zh": "中东、北非与撒哈拉以南传统服饰",
    "subject_en": "traditional dress across MENA and sub-Saharan Africa",
    "definition_zh": "“中东服饰”与“非洲服饰”都是过宽标签，区域内包含多种宗教、民族、城市与游牧传统。",
    "definition_en": "“Middle Eastern dress” and “African dress” are overly broad labels spanning many religious, ethnic, urban and nomadic traditions.",
    "evidence_zh": "至少标注国家/族群、服装自称、材料、闭合与使用场合，再讨论长袍、头巾或织纹的结构差异。",
    "evidence_en": "At minimum identify country or people, self-name, material, closure and occasion before discussing robes, head coverings or woven patterns.",
    "risk_zh": "用沙漠、部落或异域等视觉词替代来源，会复制刻板印象；相似长袍也可能有完全不同礼仪。",
    "risk_en": "Replacing provenance with words such as desert, tribal or exotic reproduces stereotypes, while similar robes may carry entirely different etiquette.",
    "practice_zh": "优先使用博物馆、当地文化机构与制作者资料；展示和销售时避免把宗教服饰当作随意造型道具。",
    "practice_en": "Prioritize museums, local cultural institutions and makers, and avoid treating religious dress as an interchangeable styling prop."
  },
  "european-folk-american-traditional-dress": {
    "subject_zh": "欧洲民俗服装与美洲传统服饰",
    "subject_en": "European folk and American traditional dress",
    "definition_zh": "欧洲“民族服装”和美洲“传统服饰”都经历国家建构、移民、殖民与复兴，不能视为静止古装。",
    "definition_en": "European folk dress and traditional dress of the Americas have been shaped by nation-building, migration, colonization and revival; they are not static costume.",
    "evidence_zh": "区分地区日常服、节庆复兴服、舞台统一服与原住民礼服，并记录材料、制作社群和当代使用者。",
    "evidence_en": "Distinguish regional daily wear, festival revival, standardized stage costume and Indigenous ceremonial dress, recording material, maker community and present-day users.",
    "risk_zh": "只用国家名称或“波西米亚/西部”标签会抹去具体社群，也可能把受保护图案商业化。",
    "risk_en": "Country-only or “bohemian/western” labels erase communities and may commercialize protected designs.",
    "practice_zh": "比较时聚焦结构和复兴机制，不复制神圣符号；产品文案说明灵感而非冒充社群正统。",
    "practice_en": "Compare construction and revival mechanisms, do not copy sacred symbols, and describe inspiration without claiming community authenticity."
  },
  "where-to-buy-hanfu-top-marketplaces": {
    "subject_zh": "汉服购买渠道总览",
    "subject_en": "Hanfu marketplace selection",
    "definition_zh": "平台没有脱离卖家与商品的统一质量，渠道总览的价值在于匹配证据能力、地区和风险承受。",
    "definition_en": "A platform has no seller-independent quality; a marketplace overview is useful only for matching evidence quality, region and risk tolerance.",
    "evidence_zh": "比较卖家实名、图片原创度、尺码字段、纤维披露、支付保护、跨境追踪与退货路径，不以平台知名度排序。",
    "evidence_en": "Compare seller identity, image originality, measurements, fiber disclosure, payment protection, cross-border tracking and return path rather than platform fame.",
    "risk_zh": "榜单会随时间和地区失效，低价与高销量也无法证明形制、面料或履约。",
    "risk_en": "Rankings age quickly and vary by region; low price and high volume do not prove form, fiber or fulfillment.",
    "practice_zh": "先按所在地和场合筛出可执行渠道，再在同一证据表里比较具体 SKU；下单当天复核页面。",
    "practice_en": "Filter for workable channels by location and occasion, compare specific SKUs in one evidence table, and re-check the listing on purchase day."
  },
  "amazon-hanfu-buying-guide": {
    "subject_zh": "Amazon 汉服购买",
    "subject_en": "Amazon Hanfu buying",
    "definition_zh": "Amazon 的配送便利与汉服专业度是两条独立轴；Prime 或高星级不能替代形制与规格判断。",
    "definition_en": "Amazon delivery convenience and Hanfu expertise are independent axes; Prime and star ratings do not replace form and specification review.",
    "evidence_zh": "核对销售方与发货方、变体是否共用评论、成衣厘米尺寸、纤维、套装件数、退货窗口和目的地仓。",
    "evidence_en": "Verify seller and fulfiller, whether variants share reviews, finished centimetre measurements, fiber, set contents, return window and destination warehouse.",
    "risk_zh": "变体合并会让评论图片对应错款，自动翻译会误写领型与面料，次日达也不代表礼服质量稳定。",
    "risk_en": "Variant merging can attach review photos to the wrong item, automatic translation can distort collar and fiber terms, and fast delivery does not stabilize formalwear quality.",
    "practice_zh": "只把平台物流当作一项便利；对婚礼、马面裙或圆领袍仍要求关键结构图，并预留退换时间。",
    "practice_en": "Treat platform logistics as one convenience only; for weddings, mamian or yuanling still require critical construction views and return time."
  },
  "hanfu-styling-complete-guide": {
    "subject_zh": "汉服完整穿搭路径",
    "subject_en": "a complete Hanfu styling path",
    "definition_zh": "一套汉服穿得舒服、看着利落，关键在领口贴服、腰线稳定、裙长合适，配饰反而可以最后再选。",
    "definition_en": "Comfortable Hanfu starts with a collar that sits well, a secure waist and a hem that works with your shoes.",
    "evidence_zh": "马面裙最值得先整理的，是前后平整裙门与两侧褶裥的关系。",
    "evidence_en": "For a mamian skirt, pay attention to the relationship between the flat front and back panels and the pleated areas at the sides.",
    "risk_zh": "轻薄不一定凉快，层数、里料和织物密度都会影响体感。",
    "risk_en": "A lightweight-looking garment is not necessarily cool: lining, weave density and the number of layers also matter.",
    "practice_zh": "最后拍正面、侧面和背面三张自然站立照，检查领口是否偏斜、腰带是否卷起、前后下摆是否意外高低不一。",
    "practice_en": "Finish with front, side and back photographs in a natural stance."
  },
  "hanfu-menswear-guide": {
    "subject_zh": "汉服男装入门",
    "subject_en": "beginner Hanfu menswear",
    "definition_zh": "男装不等于把女装换成深色，圆领袍、直身/直裰、褙子等有各自领型、衣身与场合逻辑。",
    "definition_en": "Menswear is not women’s Hanfu recolored dark; yuanling robes, zhishen/zhiduo and men’s beizi have distinct necklines, bodies and occasion logic.",
    "evidence_zh": "看完整领圈或交领、侧衩与摆量、通袖、腰带位置、内搭露出和袍长，并要求正背面图。",
    "evidence_en": "Read complete round or cross collar, side vents and hem volume, sleeve span, belt position, visible underlayer and robe length, requiring front and back views.",
    "risk_zh": "影楼武侠服、毕业袍感圆领或现代长衫若只靠色彩，会被误标为汉服男装。",
    "risk_en": "Studio wuxia costume, graduation-like round robes or modern long gowns can be mislabeled as Hanfu menswear when color replaces construction evidence.",
    "practice_zh": "第一套先按活动量选直身类或圆领袍，配色保持两到三层；以成衣胸围、通袖和衣长判断。",
    "practice_en": "For a first set, choose a zhishen-type robe or yuanling by movement needs, keep two or three color layers, and fit by finished chest, sleeve span and length."
  },
  "mens-yuanling-robe-checklist": {
    "subject_zh": "男装圆领袍校核",
    "subject_en": "men’s yuanling robe verification",
    "definition_zh": "圆领袍的识别核心是环绕颈部的完整圆领与袍身闭合关系，不是立领、交领或现代圆领 T 恤式开口。",
    "definition_en": "A yuanling robe is identified by a complete round collar integrated with the robe closure, not a stand collar, cross collar or T-shirt-like opening.",
    "evidence_zh": "要求领部近照、正背平铺、腋下与侧衩、腰带前后、通袖与袍长测量；领圈不完整就不能专业定类。",
    "evidence_en": "Require collar close-up, front/back flat lays, underarm and side vents, belt front/back, sleeve span and robe length; an incomplete collar view cannot support classification.",
    "risk_zh": "只展示腰带以上、用披风遮领或让模特侧身，会把交领袍和舞台服误配到圆领文章。",
    "risk_en": "Waist-up photos, capes covering the neckline or side poses can place cross-collar robes and stagewear in a yuanling article.",
    "practice_zh": "试穿时检查领圈贴合、抬臂牵扯、腰带是否压住闭合点与坐下后的袍摆；配图必须露出领圈证据。",
    "practice_en": "During fitting, check collar seating, arm-raise pull, whether the belt compresses the closure and how the hem sits; editorial images must show the collar evidence."
  },
  "china-56-ethnic-dress-hub": {
    "subject_zh": "中国 56 个民族服饰导览",
    "subject_en": "China’s 56 ethnic dress hub",
    "definition_zh": "导览的任务是建立入口与边界：每个民族内部都有地区、性别、年龄、日常与礼仪差异，不存在一张图代表全部。",
    "definition_en": "A hub should create entry points and boundaries: every people contains regional, gender, age, daily and ceremonial variation, and no single image represents all.",
    "evidence_zh": "每篇固定记录民族自称/通用名、地区、可观察轮廓、材料工艺、场合与纹样，并说明资料时间与来源限制。",
    "evidence_en": "Each article records the group name, region, observable silhouette, materials/craft, occasion and motifs, while stating date and source limits.",
    "risk_zh": "把少数民族服饰泛称汉服、用节庆盛装代表日常、跨民族复用配图，都是必须阻断的编辑错误。",
    "risk_en": "Calling minority dress Hanfu, presenting festival regalia as daily wear, or reusing images across peoples are editorial errors that must be blocked.",
    "practice_zh": "先从地区和服装结构进入，再阅读工艺与场合；遇到社区自述与旧分类不同，优先保留差异并标注。",
    "practice_en": "Enter through region and construction, then read craft and occasion; when community self-description differs from legacy classification, preserve and label the difference."
  },
  "why-amayun-factory-direct-hanfu": {
    "subject_zh": "Amayun 工厂直达汉服说明",
    "subject_en": "Amayun factory-direct Hanfu",
    "definition_zh": "工厂直达描述供应链关系，不自动证明形制正确、最低价格或每道工序都在同一地点完成。",
    "definition_en": "Factory-direct describes a supply relationship; it does not automatically prove correct form, lowest price or that every process occurs at one site.",
    "evidence_zh": "把设计确认、版型、面辅料采购、裁剪、缝制、装饰、质检和发货逐环节标注责任主体与批次证据。",
    "evidence_en": "Map design approval, pattern, sourcing, cutting, sewing, decoration, inspection and dispatch to responsible entities and batch evidence.",
    "risk_zh": "用车间照片覆盖所有 SKU、把合作厂说成自有厂、把渠道缩短说成质量保证，都会扩大事实范围。",
    "risk_en": "Using workshop photos for every SKU, calling a partner facility owned, or treating a shorter channel as a quality guarantee all overstate the evidence.",
    "practice_zh": "产品页只写能对应具体货号的事实，并公开规格、质检与售后责任；其余内容明确标为流程介绍或待核实。",
    "practice_en": "Product pages should state only SKU-linked facts and disclose specification, inspection and after-sales responsibility; label the rest as process context or unverified."
  },
  "yesstyle-hanfu-asia-fashion-gateway": {
    "subject_zh": "YesStyle 亚洲时尚渠道中的汉服",
    "subject_en": "Hanfu in the YesStyle Asian-fashion marketplace",
    "definition_zh": "多品类亚洲时尚平台可以帮助发现款式，但平台分类不等于服装文化身份或形制审核。",
    "definition_en": "A multi-category Asian-fashion marketplace can aid discovery, but its taxonomy is not cultural identification or form verification.",
    "evidence_zh": "核对实际卖家、品牌、领型与下装结构、尺码单位、纤维、套装件数和目的地退货条款。",
    "evidence_en": "Verify actual seller, brand, neckline and lower-garment construction, measurement units, fiber, included pieces and destination return terms.",
    "risk_zh": "韩服、和服、新中式、舞台服与汉服若共享标签或关键词，搜索结果会造成跨文化错配。",
    "risk_en": "Shared tags across hanbok, kimono, new-Chinese style, stage costume and Hanfu can produce cross-cultural mismatches.",
    "practice_zh": "把平台当作检索入口，逐件重新分类；用于礼仪或教学的款式必须有结构视角和可追踪来源。",
    "practice_en": "Treat the platform as a search gateway and reclassify each item; ritual or teaching use requires construction views and traceable provenance."
  },
  "etsy-hanfu-handmade-custom": {
    "subject_zh": "Etsy 汉服手工与定制",
    "subject_en": "Etsy handmade and custom Hanfu",
    "definition_zh": "手工、按单制作与尺寸定制是不同承诺，必须明确哪些工序和哪些尺寸真正可调整。",
    "definition_en": "Handmade, made-to-order and made-to-measure are different promises; the listing must state which processes and measurements are actually customized.",
    "evidence_zh": "核对制作者身份、原始作品图、打版与绣花方式、面料来源、量体表、修改轮次、交期和不可退条件。",
    "evidence_en": "Verify maker identity, original work images, patterning and embroidery method, fabric source, measurement form, revision rounds, lead time and non-return conditions.",
    "risk_zh": "代发商品冒充手作、盗图、模糊“定制”以及跨境修改沟通不足，是高价订单的主要风险。",
    "risk_en": "Dropshipped goods presented as handmade, stolen imagery, vague customization and weak cross-border alteration communication are major high-value risks.",
    "practice_zh": "要求关键节点确认图和书面尺寸，先做样布或低风险单；婚礼订单把试穿、修改和缓冲期写进时间线。",
    "practice_en": "Require written measurements and milestone approval images, begin with a swatch or low-risk order, and build fitting, alterations and buffer into wedding timelines."
  },
  "ebay-hanfu-secondhand-cosplay": {
    "subject_zh": "eBay 二手汉服与 cosplay 区分",
    "subject_en": "eBay second-hand Hanfu and cosplay",
    "definition_zh": "二手汉服、舞台服与 cosplay 可能出现在同一搜索页，判断要同时处理服装身份和物品状态。",
    "definition_en": "Second-hand Hanfu, stagewear and cosplay can share one results page, so evaluation must address both garment identity and condition.",
    "evidence_zh": "要求领型与结构全景、洗标或面料说明、瑕疵近照、改动记录、原品牌/货号、实际尺寸与退货条件。",
    "evidence_en": "Request full construction views, care or fiber label, flaw close-ups, alteration history, original brand/SKU, actual measurements and return terms.",
    "risk_zh": "租赁淘汰服、缺件套装、不可逆改短和以角色名代替形制名，容易让低价变成修复成本。",
    "risk_en": "Retired rental costume, incomplete sets, irreversible shortening and character names replacing form names can turn a low price into restoration cost.",
    "practice_zh": "按“形制是否成立—状态是否可用—尺寸是否可改”三关筛选；无法确认卫生与材质的贴身件不购买。",
    "practice_en": "Use three gates—valid form, usable condition and alterable fit—and avoid close-to-skin pieces when hygiene or material cannot be verified."
  },
  "shopee-hanfu-southeast-asia": {
    "subject_zh": "Shopee 东南亚汉服购买",
    "subject_en": "Shopee Hanfu buying in Southeast Asia",
    "definition_zh": "地区站点的物流与付款便利不能替代商品级形制、面料和卖家履约核验。",
    "definition_en": "Regional logistics and payment convenience do not replace item-level verification of form, fiber and seller fulfillment.",
    "evidence_zh": "按当地站点核对卖家所在地、厘米尺寸、炎热潮湿环境下的面料层数、套装件数、配送追踪与本地退货路径。",
    "evidence_en": "On the local site, verify seller location, centimetre measurements, fabric layers for heat and humidity, included pieces, tracked delivery and local returns.",
    "risk_zh": "同图多店、自动翻译混淆领型、预售期隐藏在变体中，以及高温下不透气，都会影响真实体验。",
    "risk_en": "One image used by many sellers, mistranslated collar terms, lead times hidden in variants and poor breathability in heat all affect real use.",
    "practice_zh": "优先有原创细节图与本地评价实拍的具体 SKU；节庆订单按最迟可退日倒排，而不是只看预计到货日。",
    "practice_en": "Prioritize a specific SKU with original detail photos and local buyer images, and plan festival orders backward from the last return date rather than only estimated arrival."
  },
  "lazada-new-chinese-style-sea": {
    "subject_zh": "Lazada 东南亚新中式与汉服",
    "subject_en": "Lazada new-Chinese-style and Hanfu listings in Southeast Asia",
    "definition_zh": "新中式、汉元素和汉服在平台搜索中常重叠，专业购买必须先做结构分类再看风格。",
    "definition_en": "New Chinese style, Han-inspired fashion and Hanfu often overlap in platform search; professional buying classifies construction before aesthetic.",
    "evidence_zh": "观察领襟、闭合、衣身裁片与下装，核对卖家、面料、成衣尺寸、套装清单和目的地售后。",
    "evidence_en": "Observe neckline, closure, body panels and lower garment, then verify seller, fiber, finished measurements, set contents and destination after-sales.",
    "risk_zh": "盘扣、刺绣或立领常被笼统标成 Hanfu，跨境自动翻译还会放大类别错误。",
    "risk_en": "Frog buttons, embroidery or stand collars are often broadly labeled Hanfu, and cross-border automatic translation amplifies category errors.",
    "practice_zh": "日常通勤可选明确标注的新中式改良款；文化活动则只选关键结构图完整、术语一致的汉服形制。",
    "practice_en": "For commuting, choose clearly labeled contemporary adaptations; for cultural events, require complete key construction views and consistent Hanfu terminology."
  }
}
JSON, true, 512, JSON_THROW_ON_ERROR);

/** Assign an editorial responsibility before rendering; these are not marketing labels. */
foreach ($profiles as $slug => &$profile) {
    $role = match (true) {
        str_contains($slug, 'tiktok') || str_contains($slug, 'aliexpress') || str_contains($slug, 'shopee') || str_contains($slug, 'lazada') || str_contains($slug, 'temu') => 'platform_due_diligence',
        str_contains($slug, 'new-chinese') || str_contains($slug, 'shein') || str_contains($slug, 'designer') => 'new_chinese_boundary',
        str_contains($slug, 'yuanling') || str_contains($slug, 'ruqun') || str_contains($slug, 'mamian') || str_contains($slug, 'what-is-hanfu') || str_contains($slug, 'dynasties') || str_contains($slug, 'styles') => 'garment_form_history',
        str_contains($slug, 'fabric') || str_contains($slug, 'sizing') || str_contains($slug, 'care') => 'fabric_craft_sizing_care',
        str_contains($slug, 'styling') || str_contains($slug, 'wedding') || str_contains($slug, 'occasion') => 'occasion_styling',
        str_contains($slug, 'factory') || str_contains($slug, 'brand') || str_contains($slug, 'story') => 'brand_factory_claim_audit',
        str_contains($slug, 'compare') || str_contains($slug, 'global') || str_contains($slug, 'traditional-clothing') || str_contains($slug, 'world-ethnic') || str_contains($slug, 'kimono') || str_contains($slug, 'sari') || str_contains($slug, 'mena-africa') || str_contains($slug, 'european-folk') => 'global_traditional_clothing_comparison',
        str_contains($slug, 'buy') || str_contains($slug, 'review') || str_contains($slug, 'store') || str_contains($slug, 'ebay') => 'purchase_decision',
        default => 'china_56_ethnic_dress_hub',
    };
    $profile['editorial_role'] = $role;
    $profile['evidence_keys'] = match ($role) {
        'garment_form_history' => ['palace-ming-yuanling', 'cns-mamian-skirt', 'met-chinese-textiles'],
        'fabric_craft_sizing_care' => ['unesco-sericulture-silk', 'unesco-nanjing-yunjin', 'met-chinese-textiles'],
        'china_56_ethnic_dress_hub' => ['unesco-li-textile', 'met-chinese-textiles'],
        'platform_due_diligence', 'purchase_decision', 'brand_factory_claim_audit' => ['consumer-listing-verification', 'retailer-claim-boundary'],
        default => ['met-chinese-textiles', 'unesco-sericulture-silk'],
    };
}
unset($profile);

$profiles['hanfu-styling-complete-guide']['evidence_keys'] = ['cns-mamian-skirt', 'met-skirt-open-view'];

/**
 * Pure role contract used by the generator and unit tests; marketplace roles
 * deliberately describe current-state evidence rather than museum authority.
 * @param array<string,string> $context
 * @return array{framing:string,headers:list<string>,rows:list<list<string>>,diagnostic:list<string>,checklist:list<string>,evidence_boundary:string,conclusion:string}
 */
function hanfuR3CoreRoleBody(string $role, bool $en, array $context): array
{
    $subject = trim((string)($context['subject'] ?? ''));
    $definition = trim((string)($context['definition'] ?? ''));
    $evidence = trim((string)($context['evidence'] ?? ''));
    $risk = trim((string)($context['risk'] ?? ''));
    $practice = trim((string)($context['practice'] ?? ''));
    if (in_array('', [$subject, $definition, $evidence, $risk, $practice], true)) {
        throw new InvalidArgumentException('Incomplete core role context.');
    }

    $specs = $en ? [
        'platform_due_diligence' => [
            'framing' => 'Treat ' . $subject . ' as a dated platform audit: seller, exact variant, destination, checkout total, delivery promise, and return route are separate facts.',
            'headers' => ['Listing checkpoint', 'Evidence to capture', 'Stop rule'],
            'rows' => [
                ['Seller and fulfiller', 'Legal/store identity and destination shown for ' . $subject, 'Identity or destination terms change between listing and checkout'],
                ['Exact SKU and variant', 'Timestamped structure images, specification fields, and this requirement: ' . $evidence, 'Reviews or measurements belong to another colour, size, or bundle'],
                ['Delivery and return', 'Landed cost, dispatch promise, carrier handoff, return address, and deadline', 'No workable return route before the risk occurs: ' . $risk],
            ],
            'diagnostic' => ['Open the listing, cart, and return page side by side; record every field that changes with destination.', 'A badge, rating, or delivery estimate is not evidence for garment form, fibre, measurements, or set contents.'],
            'checklist' => ['Name the seller and fulfiller for the exact variant.', 'Save front, back, closure, and lower-garment views.', 'Record finished centimetre measurements and fibre wording verbatim.', 'Calculate tax, shipping, return postage, and deadline.', 'Apply this stop rule before payment: ' . $practice],
            'evidence_boundary' => 'Only dated, SKU-level records can support a platform conclusion about ' . $subject . '; museum or heritage sources cannot verify a seller, stock state, delivery promise, or return policy.',
            'conclusion' => 'Publish a platform recommendation only with a date, destination, exact variant, and explicit re-check instruction.',
        ],
        'new_chinese_boundary' => [
            'framing' => 'Read ' . $subject . ' on two axes: the visible modern design and the historical clothing terms it references; resemblance does not make those labels interchangeable.',
            'headers' => ['Design layer', 'Visible construction evidence', 'Honest label'],
            'rows' => [
                ['Silhouette and styling', 'Record hem, waist, sleeve, layer, and intended movement', 'Atmosphere is used as proof of historical form'],
                ['Collar and closure', $evidence, 'A standing collar, zip, or modern one-piece is relabelled as a historical Hanfu form'],
                ['Reference and adaptation', 'Name the specific borrowed element and the modern pattern or material choice', 'The copy claims reconstruction without an object or documented pattern source'],
            ],
            'diagnostic' => ['Describe the garment in neutral construction terms before using Hanfu, guofeng, or new-Chinese-style labels.', 'Keep inspiration, adaptation, reconstruction, and stage costume as four different editorial claims.'],
            'checklist' => ['Identify the actual collar and closure.', 'State whether upper and lower parts are separate.', 'Name the modern pattern, zip, dart, or fabric when visible.', 'Attribute a historical reference only to a bounded source.', 'Use the practical label required here: ' . $practice],
            'evidence_boundary' => 'Historical sources may explain the referenced element in ' . $subject . ', but they do not convert a modern garment into a reconstruction or certify a retail claim.',
            'conclusion' => 'A useful boundary statement tells the reader what is historical reference, what is contemporary design, and what remains unverified.',
        ],
        'garment_form_history' => [
            'framing' => 'Start ' . $subject . ' from garment construction and an attributable object, image, or text; dynasty mood and modern styling come after the form is established.',
            'headers' => ['Form question', 'Object or diagram evidence', 'Reconstruction limit'],
            'rows' => [
                ['Collar and opening', 'Complete neckline, overlap direction, closure points, and body panels', 'The key opening is cropped or replaced by a modern collar'],
                ['Upper/lower relation', $evidence, 'A single skirt, top, or accessory is presented as a complete system'],
                ['Date and reconstruction', 'Collection number, excavation or publication context, date range, and stated reconstruction choices', 'One late or ceremonial object is made universal for an era'],
            ],
            'diagnostic' => ['Draw the visible pieces and fastening sequence for ' . $subject . ' before assigning a period name.', 'Separate surviving-object evidence, transmitted imagery, institutional text, and modern reconstruction; they answer different questions.'],
            'checklist' => ['Retain full front and back views.', 'Mark neckline and closure direction.', 'Identify separate garments and dressing order.', 'Cite an object, museum record, or bounded publication.', 'State this reconstruction limit: ' . $risk],
            'evidence_boundary' => 'A historical source supports only its documented object, period, status, and construction claim; it is not proof for every modern garment called ' . $subject . '.',
            'conclusion' => 'Conclude with the form that the evidence supports, the views still missing, and the modern choices that are reconstructions rather than surviving facts.',
        ],
        'fabric_craft_sizing_care' => [
            'framing' => 'For ' . $subject . ', separate fibre, yarn, weave, finish, embroidery or appliqué, finished measurements, and care instructions before making a quality claim.',
            'headers' => ['Material layer', 'Test or measurement', 'Care and claim risk'],
            'rows' => [
                ['Fibre and weave', 'Composition label plus close front/back views and this evidence: ' . $evidence, 'A visual sheen is treated as fibre certification'],
                ['Fit and construction', 'Finished garment measurements, method, seam allowance, lining, and planned underlayers', 'Only body height/weight or a size letter is supplied'],
                ['Surface work and care', 'Reverse embroidery, loose threads, dye-transfer test, maker care label, and component-specific storage', '“Handmade” or “dry-clean only” is repeated without process detail'],
            ],
            'diagnostic' => ['Test the weakest material or attached component first; a garment is not safely washable merely because one fibre is.', 'Compare measurements with a garment that already fits and test sitting, walking, and arm lift.'],
            'checklist' => ['Copy the fibre percentages exactly.', 'Record weave, lining, embroidery, and metal separately.', 'Measure the finished garment flat with a stated method.', 'Ask for the reverse and seam close-ups.', 'Follow this material-specific practice: ' . $practice],
            'evidence_boundary' => 'Museum and heritage sources can explain a named technique in ' . $subject . '; only the maker or laboratory record can certify the fibre and making method of the item being sold.',
            'conclusion' => 'The final care and fit advice must be component-specific, measurement-based, and conditional on the actual label and construction.',
        ],
        'occasion_styling' => [
            'framing' => 'Build ' . $subject . ' from occasion, movement, weather, duration, and dressing sequence; colour and accessories refine an already coherent garment base.',
            'headers' => ['Wear sequence', 'Movement rehearsal', 'Adjustment or stop'],
            'rows' => [
                ['Base garments', 'Correct inner layer, collar/closure, upper-lower relation, waist position, and hem', 'An accessory hides or contradicts the garment structure'],
                ['Action and duration', 'Sit, walk, climb, lift arms, and repeat the real event movement', 'Hem, sleeve, belt, or headwear cannot remain secure'],
                ['Weather and social setting', 'Temperature, rain plan, footwear surface, photography, and local dress guidance', 'Comfort, modesty, safety, or host guidance cannot be met'],
            ],
            'diagnostic' => ['Photograph front, side, and back after the base layer is fitted; correct structure before adding a focal ornament.', 'Rehearse the longest or most demanding movement for the actual event, not only a static mirror pose.'],
            'checklist' => ['Confirm garment form and dressing order.', 'Set waist and hem before hair or jewellery.', 'Secure one focal accessory without covering construction.', 'Test footwear, sleeves, seating, and weather.', 'Make the practical adjustment specified here: ' . $practice],
            'evidence_boundary' => 'Historical evidence may guide a form in ' . $subject . ', while the present occasion, wearer, organiser, and safety conditions determine the final styling decision.',
            'conclusion' => 'A finished look is acceptable only after structure, movement, comfort, safety, and context have all been checked.',
        ],
        'purchase_decision' => [
            'framing' => 'Turn ' . $subject . ' into a purchase gate: intended use, body and reference-garment measurements, budget, deadline, evidence quality, and return route decide the answer.',
            'headers' => ['Decision gate', 'Comparable evidence', 'Walk-away rule'],
            'rows' => [
                ['Use and non-negotiables', 'Occasion, movement, deadline, fibre limits, and required garment form', 'The listing cannot meet a non-negotiable need'],
                ['Exact item comparison', 'Same set contents, construction, measurements, fibre, shipping, tax, and return cost', 'Price is compared across unlike bundles or missing specifications'],
                ['Arrival test', 'Flat measurements, contents, defects, movement, colour transfer, and documented seller answer', 'Tags must be removed before fit or condition can be checked'],
            ],
            'diagnostic' => ['Write the walk-away conditions for ' . $subject . ' before opening marketplaces.', 'A lower price is not comparable when return exposure, missing pieces, material, or construction differs.'],
            'checklist' => ['Define occasion, deadline, and budget ceiling.', 'Record body and reference-garment measurements.', 'Require the structure described here: ' . $evidence, 'Compare landed cost and executable returns.', 'Stop or proceed using this rule: ' . $practice],
            'evidence_boundary' => 'The recommendation for ' . $subject . ' is valid only for the dated SKU, seller, destination, measurements, and conditions actually checked.',
            'conclusion' => 'Choose only when every non-negotiable has evidence; otherwise record the missing fact and stop.',
        ],
        'brand_factory_claim_audit' => [
            'framing' => 'Audit ' . $subject . ' by splitting brand story, company identity, product specification, manufacturing step, quality-control record, and after-sales responsibility.',
            'headers' => ['Public claim', 'Primary record required', 'Unverified status'],
            'rows' => [
                ['Company or factory identity', 'Current legal entity, address, responsibility, and dated relationship to the product', 'A workshop photograph or founder story is the only link'],
                ['Material or craft claim', 'Batch/SKU specification, supplier or process record, inspection point, and exception handling', 'The claim cannot be tied to this item or batch'],
                ['Price or direct-channel claim', 'Comparable specification, included services, landed cost, and after-sales owner', '“Factory direct” is used as automatic proof of value or quality'],
            ],
            'diagnostic' => ['Convert every adjective about ' . $subject . ' into a claim with a responsible party, date, product scope, and inspectable record.', 'Mark self-reported evidence, third-party evidence, and unresolved statements separately.'],
            'checklist' => ['Name the legal and trading entities.', 'Tie each claim to a product or batch.', 'Record the claimed production step and inspector.', 'Separate owned, partner, and sourced production.', 'Publish this unresolved risk plainly: ' . $risk],
            'evidence_boundary' => 'Company materials are primary evidence for what the company said about ' . $subject . ', not independent proof that the claim is true; museum sources cannot validate a factory.',
            'conclusion' => 'Publish a dated claim matrix showing supported, self-reported, contradicted, and still-unverified statements.',
        ],
        'global_traditional_clothing_comparison' => [
            'framing' => 'Compare ' . $subject . ' only after each tradition is described in its own terminology, locality, date, garment system, material practice, and use context.',
            'headers' => ['Local term and source', 'Comparable construction dimension', 'Non-comparable context'],
            'rows' => [
                ['Garment system', 'Each tradition’s own term plus full views of pieces, closure, layering, and dressing order', 'Visual resemblance is treated as shared identity or origin'],
                ['Material and making', 'Fibre, weave, dye, surface technique, maker/place, and object date for each side', 'A single Chinese textile source is used to explain another tradition'],
                ['Social use', 'Who wears it, where, when, and under whose guidance', 'Religion, gender, colonial history, or living protocol is reduced to style'],
            ],
            'diagnostic' => ['Build separate evidence cards before placing traditions side by side.', 'Use neutral dimensions such as panel, wrap, closure, layer, fibre, and occasion; return to local names in the conclusion.'],
            'checklist' => ['Name every tradition in its own terms.', 'Use a source from each community or collection.', 'Compare the same structural dimension.', 'State exchange without assuming common origin.', 'Apply this boundary to the comparison: ' . $practice],
            'evidence_boundary' => 'A Hanfu or Chinese-textile source supports only the Chinese side of ' . $subject . '; every other tradition needs its own attributable source and context.',
            'conclusion' => 'A respectful comparison explains bounded similarities and differences without ranking age, authenticity, or cultural value.',
        ],
        'china_56_ethnic_dress_hub' => [
            'framing' => 'Organise ' . $subject . ' as 56 entry points to living, locally varied dress records, not as 56 timeless costumes or extensions of Hanfu.',
            'headers' => ['Community record', 'Dress-system clue', 'Photo and editorial boundary'],
            'rows' => [
                ['Name and locality', 'Community self-name where available, region, date, source, and local variation', 'An administrative label is treated as one uniform wardrobe'],
                ['Garment and material', 'Pieces, fastening, wearing order, fibre/technique, maker, and documented use', 'Colour, motif, or accessory alone is used to identify a group'],
                ['Image and permission', 'Creator, licence/consent, crop note, visible facts, and separately attributed interpretation', 'An illustration is cited as field evidence or dress is relabelled Hanfu'],
            ],
            'diagnostic' => ['For ' . $subject . ', distinguish what the image shows from what a community or collection source says.', 'Treat county, branch, age, gender, faith, season, occasion, and contemporary change as part of the record, not noise.'],
            'checklist' => ['Start with the named community and region.', 'Describe structure before motif.', 'Separate fibre, technique, and ornament.', 'State what the photograph cannot establish.', 'Follow this editorial practice: ' . $practice],
            'evidence_boundary' => 'National overview sources are orientation only; local and object-level claims in ' . $subject . ' require a dated community, museum, maker, or field record, and none grants image rights automatically.',
            'conclusion' => 'The hub should lead readers to attributable local records and explicitly prevent any ethnic dress photograph from being automatically classified as Hanfu.',
        ],
    ] : [
        'platform_due_diligence' => [
            'framing' => '把“' . $subject . '”当作带日期的平台核验：卖家、具体变体、目的地、结算总价、交付承诺和退货路径必须分别记录。',
            'headers' => ['商品页核验点', '应保存的证据', '停止条件'],
            'rows' => [['卖家与履约方', '对应“' . $subject . '”及目的地的主体信息', '商品页与结算页主体或目的地条款变化'], ['具体 SKU 与变体', '带时间的结构图、规格字段及“' . $evidence . '”', '评论或尺寸属于其他颜色、尺码或套装'], ['配送与退货', '到手总价、发货承诺、承运节点、退货地址和期限', '在“' . $risk . '”发生前没有可执行退路']],
            'diagnostic' => ['并排打开商品页、购物车与退货条款，记录所有随目的地变化的字段。', '平台徽标、评分或时效预估不能证明形制、纤维、成衣尺寸与套装件数。'],
            'checklist' => ['确认具体变体的卖家和履约方。', '保存正面、背面、闭合与下装结构图。', '逐字记录厘米成衣尺寸与纤维字段。', '计算税费、运费、退货邮费与最后期限。', '付款前执行该停止规则：' . $practice],
            'evidence_boundary' => '只有带日期的 SKU 级记录可以支持“' . $subject . '”的平台结论；博物馆或非遗资料不能核验卖家、库存、时效与退货政策。',
            'conclusion' => '平台建议必须同时写明日期、目的地、具体变体和下单日重新核验指令。',
        ],
        'new_chinese_boundary' => [
            'framing' => '从现代设计与历史服装术语两条轴阅读“' . $subject . '”；外观相似不等于名称可以互换。',
            'headers' => ['设计层', '可见结构证据', '诚实标签'],
            'rows' => [['轮廓与造型', '记录衣长、腰线、袖型、层次与活动方式', '把氛围当作历史形制证据'], ['领型与闭合', $evidence, '把立领、拉链或现代连衣式样改称历史汉服形制'], ['借鉴与改良', '写明具体借鉴元素及现代纸样或材料', '没有实物或版型来源却声称复原']],
            'diagnostic' => ['使用汉服、国风或新中式标签前，先用中性结构词描述服装。', '把灵感借鉴、现代改良、历史复原和舞台服分成四类声明。'],
            'checklist' => ['确认实际领型与闭合。', '说明上下装是否分体。', '写出现代纸样、拉链、省道或材料。', '历史借鉴只对应有边界的资料。', '采用该实践标签：' . $practice],
            'evidence_boundary' => '历史资料可以解释“' . $subject . '”借鉴的元素，但不能把现代服装自动变成复原品，也不能认证零售声明。',
            'conclusion' => '有效边界说明应分别写出历史参考、当代设计和仍未核实的部分。',
        ],
        'garment_form_history' => [
            'framing' => '“' . $subject . '”应从服装结构和可署名实物、图像或文本开始，朝代氛围与现代造型必须排在形制确认之后。',
            'headers' => ['形制问题', '实物或结构图证据', '复原限度'],
            'rows' => [['领型与开合', '完整领口、衽向、闭合点与衣身裁片', '关键开合被裁掉或替换为现代领型'], ['上下装关系', $evidence, '把单独裙、上衣或配饰当作完整系统'], ['年代与复原', '藏品号、出土或出版语境、日期范围及复原取舍', '用一件晚期或礼仪实物代表整个时代']],
            'diagnostic' => ['先画出“' . $subject . '”可见衣片与穿着顺序，再写年代名称。', '把传世实物、图像、制度文本与现代复原分别标级，它们回答不同问题。'],
            'checklist' => ['保留完整正背面。', '标记领型与闭合方向。', '识别分体衣物与穿着顺序。', '引用藏品、博物馆记录或有边界出版物。', '写明该复原限度：' . $risk],
            'evidence_boundary' => '历史来源只支持其记录的实物、时期、身份和结构结论，不能证明所有名为“' . $subject . '”的现代商品。',
            'conclusion' => '结论应写明证据支持的形制、仍缺少的视角，以及哪些现代处理属于复原推定。',
        ],
        'fabric_craft_sizing_care' => [
            'framing' => '判断“' . $subject . '”时，把纤维、纱线、织法、后整理、刺绣或贴饰、成衣尺寸和护理说明逐项分开。',
            'headers' => ['材料层', '测试或测量', '护理与声明风险'],
            'rows' => [['纤维与织法', '成分标签、正反近照及“' . $evidence . '”', '用视觉光泽代替纤维证明'], ['合身与结构', '成衣尺寸、测量方法、缝份、里料和计划内搭', '只提供身高体重或尺码字母'], ['表面工艺与护理', '绣背、线头、移色测试、制作者护理标签及分部件收纳', '没有流程细节却复述“纯手工”或统一干洗']],
            'diagnostic' => ['先测试最脆弱的材料或附加部件；一项纤维可水洗不代表整件安全。', '用已经合身的衣服对照成衣尺寸，并测试坐、走与抬手。'],
            'checklist' => ['逐字抄录纤维百分比。', '分开记录织法、里料、刺绣与金属。', '用明确方法平铺测量成衣。', '索取反面与接缝近照。', '执行该材料实践：' . $practice],
            'evidence_boundary' => '博物馆与非遗资料可以解释“' . $subject . '”中的具名技艺；只有制作者或检测记录能认证在售物品的纤维和制作方法。',
            'conclusion' => '最终护理与合身建议必须按部件、按尺寸，并以实物标签和结构为条件。',
        ],
        'occasion_styling' => [
            'framing' => '“' . $subject . '”应从场合、动作、天气、时长与穿着顺序开始；色彩和配饰只修正已经成立的服装基础。',
            'headers' => ['穿着顺序', '动作排练', '调整或停止'],
            'rows' => [['基础衣物', '内层、领襟、上下装关系、腰线与裙长正确', '配饰遮挡或违背服装结构'], ['动作与时长', '按真实活动测试坐、走、上下台阶与抬手', '裙摆、袖、腰带或头饰无法保持安全'], ['天气与社会场景', '温度、雨备、鞋底、拍摄与主办方着装指引', '舒适、端庄、安全或地方指引无法满足']],
            'diagnostic' => ['基础层合身后拍正、侧、背三面，先修正结构再加主饰。', '按真实活动中最久或最难的动作排练，不能只看镜前静态姿势。'],
            'checklist' => ['确认形制与穿着顺序。', '先定腰线和裙长再做发饰。', '一件主饰不得遮住结构。', '测试鞋履、袖口、坐姿和天气。', '执行该现场调整：' . $practice],
            'evidence_boundary' => '历史证据可以指导“' . $subject . '”的形制；当下场合、穿着者、主办方和安全条件决定最终造型。',
            'conclusion' => '只有结构、动作、舒适、安全与场合全部通过，整套造型才算完成。',
        ],
        'purchase_decision' => [
            'framing' => '把“' . $subject . '”变成购买闸门：用途、身体与参考衣尺寸、预算、期限、证据质量和退货路径共同决定答案。',
            'headers' => ['决策闸门', '可比证据', '放弃规则'],
            'rows' => [['用途与不可妥协项', '场合、活动量、期限、纤维限制与必需形制', '商品页不能满足任一不可妥协项'], ['具体商品比较', '同件数、结构、尺寸、纤维、物流、税费与退货成本', '不同套装或缺规格商品只比价格'], ['收货测试', '平铺尺寸、件数、瑕疵、活动量、移色及卖家答复', '必须拆吊牌才能检查合身或状态']],
            'diagnostic' => ['打开平台前先写下“' . $subject . '”的放弃条件。', '当退货风险、包含件数、材料或结构不同，低价不构成可比。'],
            'checklist' => ['确定场合、期限和预算上限。', '记录身体与参考衣尺寸。', '要求该结构证据：' . $evidence, '比较到手总价和可执行退货。', '按该规则停止或继续：' . $practice],
            'evidence_boundary' => '“' . $subject . '”的推荐只对实际核验的日期、SKU、卖家、目的地、尺寸与条件有效。',
            'conclusion' => '只有全部不可妥协项都有证据才购买，否则记录缺口并停止。',
        ],
        'brand_factory_claim_audit' => [
            'framing' => '审计“' . $subject . '”时，把品牌故事、公司主体、商品规格、制造步骤、质检记录与售后责任拆开。',
            'headers' => ['公开声明', '所需原始记录', '未核实状态'],
            'rows' => [['公司或工厂主体', '当前法律主体、地址、责任及与商品的带日期关系', '只有车间照片或创始人故事建立联系'], ['材料或工艺声明', '批次/SKU 规格、供应或流程记录、质检点与异常处理', '声明无法对应当前物品或批次'], ['价格或直达声明', '同规格、包含服务、到手成本与售后责任人', '用“工厂直达”自动证明价值或质量']],
            'diagnostic' => ['把“' . $subject . '”的每个形容词改写为有责任主体、日期、商品范围和可核记录的声明。', '把企业自述、第三方证据和未解决陈述分别标注。'],
            'checklist' => ['写明法律主体与交易主体。', '每项声明对应商品或批次。', '记录声称的生产步骤和检验者。', '区分自有、合作和采购生产。', '公开写出该未核风险：' . $risk],
            'evidence_boundary' => '企业材料只能证明企业对“' . $subject . '”说过什么，不是声明真实的独立证明；博物馆资料也不能验证工厂。',
            'conclusion' => '发布带日期的声明矩阵，分别标出已支持、企业自述、相互矛盾和仍未核实。',
        ],
        'global_traditional_clothing_comparison' => [
            'framing' => '比较“' . $subject . '”前，先用各传统自身术语、地区、年代、服装系统、材料工艺和使用语境分别描述。',
            'headers' => ['地方术语与来源', '可比结构维度', '不可合并语境'],
            'rows' => [['服装系统', '各自名称及衣片、闭合、层次与穿着顺序全景', '把外观相似当作同一身份或同源'], ['材料与制作', '双方各自的纤维、织法、染色、表面工艺、制作者/地点与日期', '用一条中国纺织来源解释其他传统'], ['社会使用', '谁在何时何地、依据谁的指引穿着', '把宗教、性别、殖民历史或活态礼俗压成风格']],
            'diagnostic' => ['并置之前，先为每一种传统整理各自有出处的说明。', '比较裁片、围裹、闭合、层次、纤维与场合等中性维度，结论回到地方名称。'],
            'checklist' => ['用自身术语命名每种传统。', '每一方都使用本社区或藏品来源。', '只比较同一结构维度。', '说明交流但不假定同源。', '执行该比较边界：' . $practice],
            'evidence_boundary' => '汉服或中国纺织来源只支持“' . $subject . '”中的中国一侧；其他传统必须有自己的可署名来源与语境。',
            'conclusion' => '尊重的比较只解释有限相似与差异，不排列年代、真伪或文化价值。',
        ],
        'china_56_ethnic_dress_hub' => [
            'framing' => '把“' . $subject . '”组织成进入活态地方服饰记录的 56 个入口，而不是 56 套永恒制服或汉服分支。',
            'headers' => ['社区记录', '服装系统线索', '照片与编辑边界'],
            'rows' => [['名称与地域', '优先社区自称、地区、日期、来源和地方差异', '把行政民族名称写成一套统一衣橱'], ['服装与材料', '衣片、系结、穿着顺序、纤维/工艺、制作者和有记录用途', '只凭颜色、纹样或配饰判断族属'], ['影像与授权', '创作者、许可/同意、裁切、可见事实及单独署名解释', '把说明图当田野证据或把民族服饰改称汉服']],
            'diagnostic' => ['讨论“' . $subject . '”时，分开画面可见内容与社区或藏品来源提供的解释。', '把县域、支系、年龄、性别、信仰、季节、场合和当代变化视为记录的一部分。'],
            'checklist' => ['从具体社区与地区开始。', '先描述结构再谈纹样。', '分开纤维、工艺与饰物。', '明确照片不能证明什么。', '执行该编辑实践：' . $practice],
            'evidence_boundary' => '全国概况只用于导览；“' . $subject . '”的地方或实物结论需要带日期的社区、博物馆、制作者或田野记录，且任何资料都不会自动授予图片权利。',
            'conclusion' => '导览应把读者带向可署名地方资料，并明确阻止把民族服饰照片自动归为汉服。',
        ],
    ];

    return $specs[$role] ?? throw new InvalidArgumentException('Unknown core editorial role: ' . $role);
}

/**
 * Reader-facing copy by editorial role. Internal verification instructions
 * stay in hanfuR3CoreRoleBody(); this layer turns the verified profile facts
 * into prose that belongs on a shop-owned magazine.
 *
 * @return array{headings:list<string>,paragraphs:list<string>}
 */
function hanfuR4CoreRoleVoice(string $role, bool $en): array
{
    $voices = $en ? [
        'platform_due_diligence' => [
            'headings' => ['Know who is actually selling', 'Read the exact garment, not the search card', 'Fit, fabric, and set contents', 'Delivery and return risk', 'Compare the real total', 'A sensible order decision'],
            'paragraphs' => [
                'A marketplace name tells you where the transaction happens, but not who made the garment or who will answer if the order is wrong. Start with the seller, fulfiller, destination, and exact colour-size bundle shown at checkout; those details can change even when the product photographs look identical.',
                'Open the detail page rather than relying on a search thumbnail. Useful photographs show the full front and back, neckline, closure, waist, lower garment, and the reverse of important decoration. Reviews help only when they clearly refer to the same variant and include enough context to identify what arrived.',
                'Size letters are not comparable across shops. Finished-garment measurements, fibre percentages, lining, included pieces, and room for underlayers matter far more than a model height alone. Compare them with a garment that already fits and rehearse the movement required by the intended occasion.',
                'The cheapest listing can become the most expensive when tax, delayed dispatch, missing pieces, return postage, or an overseas return address is added. Work backward from the event date and the final usable return day, leaving time for a full try-on rather than trusting an optimistic arrival estimate.',
                'A fair comparison uses the same garment form, number of pieces, material disclosure, measurement detail, delivery destination, and return route. If one listing omits a decisive field, treat that omission as a cost and risk instead of filling the gap with the seller’s rating.',
                'Order only when the exact variant meets the occasion, fit, material, timing, and return requirements at the same time. Save the final page and seller answer for your own reference, then repeat the time-sensitive checks on the day of payment because stock and fulfilment terms can move quickly.',
            ],
        ],
        'new_chinese_boundary' => [
            'headings' => ['Start with the garment in front of you', 'Collar and closure reveal the pattern', 'Historical reference or modern design', 'Fabric, proportion, and movement', 'Style it under an honest name', 'Choose the setting that suits it'],
            'paragraphs' => [
                'New-Chinese-style, Han-inspired fashion, and historically named Hanfu can all be beautiful, but they describe different design relationships. Begin with the visible garment—its pieces, seams, collar, closure, waist, and hem—before deciding which cultural or historical term is accurate.',
                'A stand collar, frog fastening, diagonal front, zip, dart, or one-piece dress changes how a garment is constructed and worn. None of these details is inferior; naming them plainly simply prevents a modern pattern from being mistaken for a historical form because of embroidery or a campaign setting.',
                'A designer may quote a sleeve line, textile, motif, or fastening without attempting reconstruction. A reconstruction makes a stronger claim and therefore needs a bounded object, image, pattern, or institutional source. Inspiration should remain inspiration when the surviving evidence does not support more.',
                'Modern fabrics and tailoring can improve ease, durability, or care, while also changing drape and proportion. Check whether the garment allows sitting, walking, reaching, and layering in the intended setting instead of judging only the still photograph chosen for the product page.',
                'The most convincing styling follows the garment’s actual structure. Contemporary shoes, a restrained bag, or simple hair can suit a modern adaptation better than borrowed historical accessories that conflict with the collar, waist, or fastening. An honest label gives the wearer more freedom, not less.',
                'Use historically named Hanfu when the form and occasion call for it, and use new-Chinese-style or Han-inspired fashion when modern design is the point. The useful question is not which label sounds grander, but which description helps the wearer understand what they are buying and how it will behave.',
            ],
        ],
        'garment_form_history' => [
            'headings' => ['See the complete garment system', 'Read collar, opening, and separate pieces', 'What historical sources can establish', 'Proportion, fabric, and dressing order', 'Where modern reconstruction begins', 'How to reach a careful conclusion'],
            'paragraphs' => [
                'A Hanfu form is more than a familiar outline. It is a system of separate pieces, collar and opening, fastening points, waist control, length, and dressing order. Looking at the complete system prevents a skirt, accessory, or atmospheric portrait from standing in for the whole garment.',
                'The neckline and opening are the quickest structural clues, but they must be read with the body panels and lower garment. A cropped photograph may hide the decisive feature; a front view alone can also conceal tie direction, back construction, or whether two apparent layers are actually one modern piece.',
                'Museum objects, excavated material, paintings, institutional essays, and transmitted texts answer different questions. A dated object can support its own construction and context, while an image may clarify wearing appearance without revealing every seam. No single source represents an entire dynasty or every social setting.',
                'When a modern maker turns fragmentary evidence into a wearable garment, proportion and material require judgment. Sleeve width, hem, lining, fabric weight, and underlayers affect movement as much as the named form. A good reconstruction explains these choices instead of hiding them behind a dynasty label.',
                'Modern zips, elastic, synthetic blends, simplified layers, and adjusted lengths may make everyday wear easier. They are not automatically wrong, but they should be described as adaptations. That distinction lets buyers choose between study, ceremony, photography, stage use, and ordinary daily wear with clear expectations.',
                'A careful conclusion names the form supported by visible structure, cites the source that supports the limited historical point, and identifies what remains a modern choice. This approach is slower than matching a silhouette to a mood board, but it gives the reader a result that can be checked and used.',
            ],
        ],
        'fabric_craft_sizing_care' => [
            'headings' => ['Fibre is only the first layer', 'Construction changes how cloth behaves', 'Measure the finished garment', 'Plan care by component', 'Understand craft claims', 'Choose for long-term wear'],
            'paragraphs' => [
                'Silk, cotton, linen, regenerated fibre, and polyester describe fibre content, not the whole character of a garment. Yarn, weave, density, finish, lining, embroidery, metallic thread, and applied ornament all change drape, heat, shine, durability, and price.',
                'The same-looking cloth can behave differently once cut into a full skirt, lined robe, narrow sleeve, or layered set. Examine seams, stress points, hems, fastening, embroidery reverse, and contact between rough decoration and delicate fabric. These details often explain comfort and lifespan better than a broad material name.',
                'Use finished measurements and a consistent flat-measure method. Compare bust, waist, garment length, sleeve reach, rise, and hem with an item that already fits, then add the underlayers required by the outfit. Height and weight charts are only a starting point because body proportions and preferred ease differ.',
                'Care follows the most vulnerable component, not the strongest fibre in the composition line. Dark dye, adhesive trim, metal, beadwork, brocade, and embroidery may each need different handling. Test only where appropriate, prevent snagging and colour transfer, and store heavy ornament without pulling the base cloth out of shape.',
                'Terms such as handmade, heritage craft, brocade, or embroidery should point to a visible process and responsible maker. Machine assistance does not automatically reduce quality, just as handwork does not guarantee neat construction. The useful description explains which step was done, with what material, and to what standard.',
                'A strong purchase balances appearance with movement, climate, care time, repairability, and repeat use. Ask how the garment will feel after several hours and what happens after the first cleaning, not only how it photographs on arrival. That is where material knowledge becomes practical value.',
            ],
        ],
        'occasion_styling' => [
            'headings' => ['Begin with the occasion', 'Build a sound garment base', 'Use proportion before decoration', 'Rehearse weather, fit, and movement', 'Secure accessories with restraint', 'Make the final adjustment'],
            'paragraphs' => [
                'A successful Hanfu outfit starts with what the wearer will actually do. A wedding guest, museum visit, outdoor festival, stage performance, and long train journey demand different movement, weather protection, formality, and dressing time even when the same garment looks attractive in a photograph.',
                'Confirm the inner layer, collar and opening, upper-lower relationship, waist position, skirt orientation, hem, and footwear before adding jewellery or hair ornaments. If the base is wrong, accessories only make the mismatch busier and can hide the structural detail that should remain visible.',
                'Proportion comes from the relationship among neckline, shoulder, sleeve, waist, skirt length, and visual weight. Choose one main focus and let the remaining elements support it. Repeating every motif in the hair, belt, bag, and shoes usually weakens the outfit instead of making it richer.',
                'Try the complete base layer while sitting, walking, climbing steps, lifting the arms, and repeating the longest action in the event. Check heat, rain, wind, floor surface, restroom practicality, and the time needed to dress. A mirror pose cannot reveal these pressures.',
                'Hair ornaments, belts, pendants, bags, and fans must remain secure without dragging the collar, twisting the skirt, or catching the sleeve. One well-placed main ornament is often enough. Historical inspiration is most convincing when it respects the garment form rather than covering it.',
                'Photograph the outfit from the front, side, and back after the movement test. Correct the waist, hem, collar, and balance first; only then adjust colour or ornament. The finished look should still feel comfortable and coherent after an hour, not merely for the first photograph.',
            ],
        ],
        'purchase_decision' => [
            'headings' => ['Define what the garment must do', 'Read the exact product evidence', 'Compare like with like', 'Fit and construction before price', 'Ask the seller useful questions', 'Know when to walk away'],
            'paragraphs' => [
                'Before browsing, decide the occasion, deadline, climate, activity, budget ceiling, required garment form, and any fibre or care limits. These are the non-negotiables. Without them, attractive photographs and temporary discounts can make almost any listing look suitable.',
                'A usable product page identifies the exact colour-size variant, included pieces, finished measurements, fibre content, lining, closure, and full garment views. If reviews cover another bundle or the photographs change when a variant is selected, treat the information as a lead rather than proof for your choice.',
                'Compare the same number of pieces, construction level, material disclosure, delivery destination, and return route. A cheaper set that omits an underlayer, uses a different skirt, or cannot be returned is not the same offer. Total value includes the cost of correcting what the listing leaves out.',
                'Place the measurements beside a garment that already fits and test whether the planned underlayers have room. Look for tension at ties, waist, armhole, and seat, as well as enough hem clearance for the intended shoe. These checks prevent a nominally correct size from failing in movement.',
                'Ask questions that can be answered with a number, photograph, material line, or policy: the finished measurement, reverse of embroidery, closure, included pieces, dispatch date, and return address. A vague reassurance is not equal to a specific answer tied to the selected variant.',
                'Walk away when a non-negotiable remains unknown, the deadline leaves no fitting buffer, the return route is unusable, or the seller’s answer conflicts with the page. Missing a discount is cheaper than owning a garment that cannot serve the event for which it was bought.',
            ],
        ],
        'brand_factory_claim_audit' => [
            'headings' => ['Separate the story from the garment', 'Connect claims to a specific product', 'Understand who performs each step', 'Material, measurement, and quality control', 'What factory-direct can and cannot mean', 'Judge the offer on disclosed facts'],
            'paragraphs' => [
                'A founder story, workshop portrait, or heritage statement can explain a brand’s intention, but it does not describe every product automatically. Buyers need to know which legal or trading entity stands behind the order and which facts apply to the exact SKU on the page.',
                'Material, craft, origin, and quality claims become useful when they connect to a batch, specification, process photograph, maker, or inspection point. A beautiful general video may show genuine work while still saying nothing about the colour or size currently being sold.',
                'Design, pattern making, fibre sourcing, weaving, cutting, sewing, embroidery, finishing, inspection, packing, and dispatch may happen at different sites. A transparent brand names those relationships without turning a partner factory into an owned factory or a single workshop into proof for the whole catalogue.',
                'Finished measurements, tolerances, fibre percentages, lining, seam treatment, inspection criteria, and defect handling reveal more than words such as premium. Consistency is especially important for pleats, paired motifs, tie placement, and sets whose separate pieces must align in wear.',
                'Factory-direct can describe a shorter sales route, but it does not guarantee the lowest price, historical accuracy, or superior workmanship. Compare the actual specification, service, alteration support, return responsibility, and landed cost rather than treating the phrase itself as a quality grade.',
                'The strongest offer is the one whose product facts, maker relationships, and after-sales owner remain clear when examined separately. Where a claim is still only the company’s own statement, read it as context—not as independent certification—and decide whether the remaining uncertainty matters to the purchase.',
            ],
        ],
        'global_traditional_clothing_comparison' => [
            'headings' => ['Begin with each tradition’s own name', 'Compare garment systems, not silhouettes alone', 'Materials need sources on both sides', 'Use and social context matter', 'Similarity does not prove shared origin', 'Return to local terminology'],
            'paragraphs' => [
                'Traditional dress should first be understood in the terms used by its own community, collection, or scholarship. Place, date, wearer, garment pieces, and occasion belong to the description; a global category or translated shopping keyword is too broad to carry that work.',
                'Visual comparison is most useful when it follows the same structural question on both sides: wrap direction, panel, closure, layer, waist control, sleeve, or dressing order. Comparing one garment’s construction with another garment’s colour produces a resemblance, not an explanation.',
                'Fibre, weave, dye, embroidery, surface work, and maker knowledge need attributable sources for every tradition being discussed. A Chinese textile source can illuminate the Chinese garment, but it cannot silently stand in for the history or technique of another community.',
                'Who wears a garment, in what season, for which work or ceremony, and under whose guidance can matter as much as the cut. Religion, gender, migration, trade, colonial history, revival, and contemporary fashion should not be flattened into a decorative style board.',
                'Trade and cultural exchange can produce meaningful connections, yet a similar wrap, motif, or textile does not prove common origin by itself. A responsible comparison states the limited similarity, the important difference, and the evidence that would be needed for a stronger historical claim.',
                'The conclusion should return every garment to its local name and context rather than ranking age, authenticity, or cultural value. Readers gain more from understanding how each clothing system works than from being told that one is a version of another.',
            ],
        ],
        'china_56_ethnic_dress_hub' => [
            'headings' => ['Fifty-six starting points, not fixed uniforms', 'See complete clothing systems', 'Material and technique are different questions', 'Dress changes with place and occasion', 'What a photograph cannot tell you', 'Continue with local sources'],
            'paragraphs' => [
                'China’s officially recognized ethnic categories are useful navigation points, but none represents a single timeless wardrobe. County, branch, age, gender, livelihood, faith, season, family history, and contemporary change can all shape what people wear and how a garment is named.',
                'Begin with the full relationship among upper and lower pieces, robe or wrap, trousers, outer layer, fastening, belt, footwear, and headwear. A striking colour or ornament may be important, but it cannot identify a community or explain the complete wearing system on its own.',
                'Fibre, weave, dye, embroidery, appliqué, beadwork, metal, fur, and repair are separate material questions. Naming the technique matters because similar-looking surfaces can be made in different ways, while one community may use several materials across regions and occasions.',
                'Daily work, market visits, weddings, festivals, religious participation, performance, and tourism can produce very different levels of formality. A modern adaptation or revival garment belongs to the living story too; it should not be dismissed simply because it differs from an older photograph.',
                'A photograph may show silhouette, colour, visible fastening, and some surface detail. It usually cannot prove subgroup identity, ritual rank, marital status, exact fibre, handmade production, or the meaning of every motif. Those claims need a captioned local, maker, museum, or community source.',
                'Use the hub to choose a community and region, then continue with dated local material rather than treating an overview as the final word. Ethnic dress should not be relabelled as Hanfu merely because it appears in China, and an editorial illustration should never be mistaken for field documentation.',
            ],
        ],
    ] : [
        'platform_due_diligence' => [
            'headings' => ['先弄清真正的卖家是谁', '看具体商品，不看搜索卡片', '尺码、面料与套装件数', '物流和退货才是隐形成本', '比较真正的到手条件', '什么时候值得下单'],
            'paragraphs' => [
                '平台名称只能说明交易发生在哪里，不能自动说明谁制作、谁发货、谁承担售后。同一张商品图可能被多家店铺使用，结算时的卖家、履约方、目的地和颜色尺码组合，才是这一次订单真正对应的对象。',
                '不要只看搜索页缩略图。有效的商品图应覆盖完整正背面、领型、开合、腰部、下装和关键装饰反面；买家实拍也只有在能确认同一变体、同一套装时才有参考价值，不能拿其他颜色或旧版尺寸替代。',
                '不同店铺的尺码字母没有直接可比性。应看厘米成衣尺寸、纤维比例、里料、套装件数以及给内搭留下的余量，再与一件已经合身的衣服对照；模特身高只能辅助理解，不能代替自己的比例和活动量。',
                '低价常被税费、延迟发货、缺件、跨境退货邮费或境外退货地址抵消。为节庆或婚礼购买时，应从最晚试穿和可退日期向前倒排，给换码、修改与真实穿着测试留出余量，而不是只盯预计到货日。',
                '公平比较必须同时满足形制、件数、材质披露、尺寸完整度、收货目的地和退货路径一致。如果某个商品缺少决定性信息，就把这项不确定性当作成本，而不是用店铺评分或销量替它补答案。',
                '只有具体变体同时满足场合、合身、面料、时效和售后要求时才值得下单。付款当天还要重新确认库存、发货承诺和退货条款，因为这些内容比文章和评价变化得更快。',
            ],
        ],
        'new_chinese_boundary' => [
            'headings' => ['先看眼前这件衣服', '领型与开合最能说明结构', '历史借鉴不等于历史复原', '面料、比例与活动量', '用准确名称完成搭配', '让服装回到合适场景'],
            'paragraphs' => [
                '新中式、汉元素和有明确形制名称的汉服都可以很好看，但它们描述的是不同的设计关系。判断时先看衣片、接缝、领型、开合、腰线和下摆，再决定使用哪个文化或历史名称，避免只凭刺绣和拍摄氛围分类。',
                '立领、盘扣、斜襟、拉链、省道或连衣式纸样都会改变衣服的制作和穿着方式。这些现代结构并不低一等，准确说出它们，反而能避免一件当代设计因为背景像古画就被误称为历史形制。',
                '设计师可以借用袖线、纹样、织物或闭合元素，而不必声称复原。只有提出复原时，才需要对应到有边界的实物、图像、版型或机构资料；当资料只能支持“受到启发”，就应停在这个准确程度。',
                '现代纸样与面料可能改善活动、耐穿和护理，也会改变垂坠、腰线与层次。选购时要测试坐、走、抬手和计划中的内搭，而不是只判断模特静止时的轮廓是否漂亮。',
                '最耐看的搭配会顺着真实结构走。现代改良款往往更适合简洁鞋包和克制发饰；若强行叠加与领型、腰线不相容的历史配饰，反而会遮住设计本身。准确名称给穿着者的是自由，不是限制。',
                '需要形制表达和礼仪语境时选择结构明确的汉服；强调当代剪裁和日常通勤时，就诚实使用新中式或汉元素。关键不在于哪个标签更响亮，而在于顾客能否凭名称理解自己买到什么、该怎么穿。',
            ],
        ],
        'garment_form_history' => [
            'headings' => ['先看完整的服装系统', '从领型、开合与分体关系辨认', '历史资料究竟能说明什么', '比例、面料与穿着顺序', '现代复原从哪里开始', '怎样得出稳妥结论'],
            'paragraphs' => [
                '汉服形制不是一个熟悉轮廓，而是衣片、领型、开合、系结、腰部控制、衣长和穿着顺序共同组成的系统。只有看到完整关系，才不会把一条裙、一个配饰或一张朝代氛围照误当成整套服装。',
                '领口与开合是最快的线索，但必须和衣身裁片、上下装关系一起看。被裁切的正面图可能恰好藏住关键结构，单一角度也无法说明系带方向、后背做法，或画面里的两层其实是一件现代连衣式服装。',
                '博物馆实物、出土材料、绘画、机构文章与传世文本回答的是不同问题。有年代的实物可以支持自身结构和语境，图像能够帮助理解穿着外观，却不一定展示每一道缝；任何单一来源都不能代表整个朝代和所有身份。',
                '现代制作者把不完整资料变成可穿成衣时，必然要处理袖宽、衣长、里料、面料重量和内搭等选择。好的复原会说明这些取舍，让读者区分存世信息与现代判断，而不是用一个朝代名称遮住全部细节。',
                '拉链、松紧、化纤混纺、简化层次和调整长度可能更适合日常，它们不必被否定，但应明确称为改良。这样顾客才能在研习、礼仪、拍摄、舞台和普通出行之间选择真正合适的版本。',
                '稳妥的结论要同时写清可见结构支持什么形制、哪条资料支持哪一项有限历史信息，以及哪些地方属于现代复原。它比对着氛围图猜朝代慢一些，却能让读者复查，也更能指导真实购买。',
            ],
        ],
        'fabric_craft_sizing_care' => [
            'headings' => ['纤维只是材料的第一层', '结构会改变面料表现', '用成衣尺寸判断合身', '按最脆弱部件安排护理', '看懂工艺声明', '为长期穿着做选择'],
            'paragraphs' => [
                '真丝、棉、麻、再生纤维和聚酯说的是纤维成分，不等于整件衣服的性格。纱线、织法、密度、后整理、里料、刺绣、金银线和贴饰都会改变垂坠、闷热、光泽、耐穿程度与价格。',
                '看起来相似的布，做成大摆裙、夹里袍、窄袖或多层套装后会有完全不同的表现。接缝、受力点、下摆、系结、绣背以及粗糙装饰与细薄底布的接触位置，往往比一个笼统面料名更能解释舒适度和寿命。',
                '尺码判断应使用成衣尺寸和一致的平铺测量方法。把胸围、腰围、衣长、袖展、裤裆或裙长与一件已经合身的衣服对照，再为计划内搭留量；身高体重表只能起步，不能覆盖个人比例与松量偏好。',
                '护理方式要服从整件衣服里最脆弱的部件，而不是成分表里最耐洗的纤维。深色染料、粘合装饰、金属、珠饰、织锦与刺绣可能需要分别处理，收纳时也要避免重饰长期拉扯底布。',
                '“纯手工”“非遗工艺”“织锦”或“刺绣”只有对应到可见工序和责任制作者时才有意义。机器辅助不必然降低品质，手作也不自动保证针脚和结构；有用的说明会说清哪一步如何完成、用了什么材料。',
                '好的选购要同时考虑外观、活动、气候、护理时间、可修复性与重复穿着。除了想象到货当天拍照的效果，还要问连续穿几小时是否舒服、第一次清洁后会怎样，这才是材料知识真正转化成价值的地方。',
            ],
        ],
        'occasion_styling' => [
            'headings' => ['先从场合开始', '打好服装结构基础', '先调比例，再加装饰', '把天气、合身与动作都排练一遍', '配饰要牢固，也要克制', '完成最后一次调整'],
            'paragraphs' => [
                '汉服搭配成功与否，首先取决于穿着者当天要做什么。婚礼宾客、博物馆参观、户外游园、舞台表演和长途交通，对活动量、天气、正式程度与换装时间的要求都不同，即使同一套衣服在照片里都很好看。',
                '加首饰之前，先确认内层、领襟、上下装关系、腰线、裙门方向、下摆和鞋履。如果基础层没有穿对，配饰只会让错配更忙乱，还可能遮住本该清楚呈现的形制结构。',
                '比例来自领口、肩线、袖型、腰位、裙长与视觉重量之间的关系。整套只设一个主要焦点，其余元素负责呼应；把所有纹样同时复制到发饰、腰饰、包和鞋上，通常不会更华丽，只会削弱重点。',
                '穿好基础层后，坐下、行走、上下台阶、抬手，并重复活动中持续最久的动作。同时考虑温度、雨风、地面、防滑、如厕便利和换装时间，镜前的静止姿势无法暴露这些真实压力。',
                '发饰、腰带、佩饰、包和扇子都要固定可靠，不能拖歪领口、扭转裙门或勾住袖口。一件位置准确的主饰往往已经足够；历史灵感只有顺着衣服结构，才不会变成遮盖形制的堆砌。',
                '动作测试后拍正、侧、背三面，先修正腰线、裙长、领口和平衡，再调整颜色或饰物。真正完成的造型，应在穿着一小时后依然舒适、端正、行动安全，而不只是第一张照片成立。',
            ],
        ],
        'purchase_decision' => [
            'headings' => ['先明确这件衣服必须解决什么', '看懂具体商品提供了什么', '只比较真正可比的商品', '价格之前先看合身与结构', '向卖家问可以核实的问题', '知道什么时候应该放弃'],
            'paragraphs' => [
                '打开平台前，先确定场合、期限、气候、活动量、预算上限、必须满足的形制，以及不能接受的纤维或护理方式。这些才是不可妥协项；若没有它们，漂亮图片和限时折扣会让几乎每件商品都显得合适。',
                '能帮助下单的页面，应明确具体颜色尺码、套装件数、厘米成衣尺寸、纤维、里料、闭合和完整结构图。评论若来自其他套装，或切换变体后图片与规格发生变化，就只能作为线索，不能代替当前选择的信息。',
                '比较时要统一件数、结构、材质披露、收货目的地和退货路径。少一件内搭、换了不同裙型或根本无法退货的低价套装，不是同一个报价；真实价值还包括补齐缺失信息和修正问题的成本。',
                '把页面尺寸与已经合身的衣服并排比较，并检查计划内搭是否有余量。系带、腰部、袖窿、坐围和下摆是最容易在动作中暴露问题的位置，名义上选对尺码，并不代表坐走抬手都合适。',
                '向卖家询问能用数字、照片、成分行或政策回答的问题，例如成衣尺寸、绣背、闭合、包含件数、实际发货日和退货地址。只说“放心”“标准尺码”的回复，不能等同于针对具体变体的明确答案。',
                '任一不可妥协项仍然不明、期限没有试穿余量、退货路径不可执行，或卖家答复与页面冲突时，就应放弃。错过一次折扣，远比买下一件无法完成既定场合任务的衣服便宜。',
            ],
        ],
        'brand_factory_claim_audit' => [
            'headings' => ['把品牌故事与具体商品分开', '让每项声明对应具体货号', '看懂每道工序由谁完成', '材质、尺寸与质检', '工厂直达能说明什么', '用已披露事实判断价值'],
            'paragraphs' => [
                '创始人故事、车间照片和文化理念可以说明品牌想做什么，却不能自动代表每一个商品。顾客真正需要知道的是由哪个法律或交易主体承担订单，以及页面上的材料、工艺和服务声明是否对应当前货号。',
                '材料、工艺、产地和质量只有连接到批次、规格、工序图、制作者或检验节点时，才具有购买意义。一段真实的通用车间视频，也可能完全没有说明眼前颜色和尺码是怎样生产的。',
                '设计确认、打版、面辅料采购、织造、裁剪、缝制、绣花、后整理、质检、包装与发货可以发生在不同地点。透明品牌会说清这些合作关系，不把合作厂写成自有厂，也不拿一个车间覆盖全部目录。',
                '成衣尺寸与公差、纤维比例、里料、缝份处理、检验标准和瑕疵处理，比“高端”二字更能说明稳定性。马面褶、成对纹样、系带位置和套装各件的对位尤其需要明确标准。',
                '工厂直达可以表示销售链路较短，但不能自动证明最低价、形制准确或做工更好。应比较同规格商品、包含服务、修改支持、售后责任和最终到手成本，而不是把“直达”本身当成质量等级。',
                '最可靠的商品，会在品牌故事、生产关系和售后责任被拆开查看后仍然清楚。若某项内容目前只是企业自述，就把它当作背景，而不是独立认证，再判断剩余不确定性是否会影响这次购买。',
            ],
        ],
        'global_traditional_clothing_comparison' => [
            'headings' => ['先使用各自传统的名称', '比较服装系统，不只比较轮廓', '双方材料都需要自己的来源', '使用场合与社会语境同样重要', '相似不等于同源', '最后回到地方术语'],
            'paragraphs' => [
                '传统服饰首先应放在本社区、藏品或研究所使用的名称中理解。地区、年代、穿着者、服装部件与场合都属于介绍的一部分，全球化大类和购物网站的翻译关键词太宽，无法承担准确命名。',
                '视觉比较只有沿着同一个结构问题展开才有价值，例如围裹方向、裁片、闭合、层次、腰部控制、袖型或穿着顺序。拿一方的结构与另一方的颜色相比，只能得到外观联想，不能解释服装如何成立。',
                '纤维、织法、染色、刺绣、表面工艺和制作者知识，需要为每一种传统分别找到有出处的资料。中国纺织来源可以解释中国服装，却不能悄悄替代另一个社区的历史或技艺说明。',
                '谁在什么季节、为了哪种劳动或仪式、依据谁的指引穿着，与剪裁同样重要。宗教、性别、迁徙、贸易、殖民历史、复兴和当代时尚，不应被压缩成一张只有装饰元素的灵感板。',
                '贸易和文化交流可能形成真实联系，但相似的围裹、纹样或织物本身不能证明共同起源。负责任的比较会说明相似发生在哪个有限层面、差异在哪里，以及要支持更强历史结论还缺什么。',
                '结尾应把每件服装放回自己的地方名称与语境，不排列谁更古老、谁更正宗、谁更有价值。读懂各自服装系统如何运作，比把其中一种说成另一种的版本更尊重，也更有知识含量。',
            ],
        ],
        'china_56_ethnic_dress_hub' => [
            'headings' => ['五十六个入口，不是五十六套制服', '先看完整的穿着系统', '材料与工艺是两个问题', '服饰会随地方和场合变化', '一张照片不能告诉你的事', '继续阅读地方资料'],
            'paragraphs' => [
                '中国官方民族分类可以作为浏览入口，却不代表每个民族只有一套永恒不变的衣橱。县域、支系、年龄、性别、生计、信仰、季节、家庭经历和当代变化，都会影响人们穿什么以及怎样称呼一件衣服。',
                '理解时要看上衣、下装、袍服或围裹、裤装、外层、系结、腰带、鞋履与头饰之间的完整关系。醒目的颜色和饰物可能很重要，但单靠它们既不能判断族属，也不能解释整套服装如何穿着。',
                '纤维、织法、染色、刺绣、贴饰、珠饰、金属、毛皮和修补是不同问题。说明具体工艺很重要，因为相似表面可能来自不同方法，同一社区在不同地区和场合也可能使用多种材料。',
                '日常劳动、赶集、婚礼、节庆、宗教参与、舞台表演与旅游展示会形成不同正式程度。现代改良和复兴服装同样属于活态故事，不能因为与旧照片不同，就被简单视为错误或不真实。',
                '照片可以展示轮廓、颜色、可见开合和部分表面细节，却通常不能证明支系身份、礼仪等级、婚姻状态、确切纤维、纯手工制作或每个纹样的含义，这些内容需要有说明的地方、制作者、博物馆或社区来源。',
                '总览适合帮助读者选定具体社区和地区，之后仍应继续查找带日期的地方资料。民族服饰不能因为出现在中国就改称汉服，编辑插图也不能冒充田野照片，这是浏览整个分类时最重要的边界。',
            ],
        ],
    ];

    return $voices[$role] ?? throw new InvalidArgumentException('Unknown core editorial role: ' . $role);
}

/** @return list<string> */
function hanfuR4CoreReaderNotes(string $subject, string $role, string $risk, bool $en): array
{
    $roleLens = $en ? [
        'platform_due_diligence' => 'The exact listing, selected variation, destination, delivery date, and usable return route all belong to the decision.',
        'new_chinese_boundary' => 'Modern tailoring and historical reference can coexist, provided that neither is hidden behind the other.',
        'garment_form_history' => 'Collar, opening, panels, fastening, and dressing order should agree before a period name is trusted.',
        'fabric_craft_sizing_care' => 'Fibre, weave, finish, decoration, finished measurements, and care instructions answer different practical questions.',
        'occasion_styling' => 'The occasion, weather, duration, movement, and dressing sequence matter before colour and jewellery are refined.',
        'purchase_decision' => 'Fit, contents, material disclosure, timing, total cost, and after-sales terms must all suit the same planned use.',
        'brand_factory_claim_audit' => 'Brand narrative, product specification, manufacturing responsibility, quality control, and after-sales service are separate promises.',
        'global_traditional_clothing_comparison' => 'Each clothing tradition needs its own local name, source, construction, material history, and social context.',
        'china_56_ethnic_dress_hub' => 'Community, locality, generation, occasion, and contemporary change matter more than a single representative image.',
    ] : [
        'platform_due_diligence' => '具体商品、所选变体、目的地、到货时间和真正可执行的退货路径，都属于同一次判断。',
        'new_chinese_boundary' => '现代剪裁可以与历史借鉴并存，前提是两者都被准确说清，而不是互相遮盖。',
        'garment_form_history' => '领型、开合、衣片、系结和穿着顺序应当彼此吻合，之后再使用年代名称。',
        'fabric_craft_sizing_care' => '纤维、织法、后整理、装饰、成衣尺寸和护理说明，回答的是不同的实际问题。',
        'occasion_styling' => '场合、天气、时长、动作和穿着顺序先成立，颜色与首饰才有继续调整的意义。',
        'purchase_decision' => '合身、件数、材料披露、时效、到手成本和售后，必须共同服务同一个使用目的。',
        'brand_factory_claim_audit' => '品牌故事、商品规格、制造责任、质检和售后是几类不同承诺，不能混成一句宣传。',
        'global_traditional_clothing_comparison' => '每一种服饰传统都需要自己的地方名称、资料、结构、材料历史与社会语境。',
        'china_56_ethnic_dress_hub' => '社区、地方、世代、场合与当代变化，比一张所谓代表图片更重要。',
    ];
    $lens = $roleLens[$role] ?? $roleLens['purchase_decision'];

    if ($en) {
        return [
            'Before choosing ' . $subject . ', define the real occasion, the hours of wear, the expected movement, the climate, and the points that cannot be compromised. ' . $lens . ' Starting from those needs prevents an attractive photograph or a familiar label from making the decision on the reader’s behalf.',
            'Read a page about ' . $subject . ' from the whole garment toward the details: front, side, back, opening, inner layers, fastening, measurements, and included pieces. A close-up may be excellent for embroidery yet useless for judging proportion. When views disagree, the complete structure deserves more weight than the most dramatic image.',
            $subject . ' must also work away from the camera. Try the planned underlayers and shoes, then sit, walk, climb a step, raise both arms, and repeat the longest action required by the occasion. A small imbalance at the waist, collar, cuff, or hem often becomes obvious only after several minutes of movement.',
            'When a key fact is absent, treat that absence as a practical risk: it may affect fit, classification, care, delivery, or return. One limitation deserves particular attention here: ' . $risk . ' Ask for a measurement, material line, construction photograph, date, or policy instead of relying on a confident adjective.',
            'The value of ' . $subject . ' becomes clearer after imagining the second and fifth wear, not only the arrival-day photograph. Consider cleaning, storage, simple repair, compatibility with existing layers, and whether the piece can serve more than one appropriate setting. Repeated comfortable use is a stronger measure of value than decorative density alone.',
            'A sound decision about ' . $subject . ' should remain easy to explain: what the garment or offer actually is, why it suits the intended use, which limitations still matter, and what must be checked again at purchase or before dressing. If that explanation depends on guessing a hidden structure or policy, the uncertainty is still part of the choice.',
        ];
    }

    return [
        '准备选择“' . $subject . '”时，先确定真实场合、连续穿着时间、主要动作、气候和不能妥协的条件。' . $lens . '从这些需求出发，能避免一张漂亮照片或一个熟悉标签替读者完成决定。',
        '阅读“' . $subject . '”相关页面时，应从整件衣服逐步看到细节：正面、侧面、背面、开合、内层、系结、成衣尺寸和包含件数。特写可以很好地展示刺绣，却未必能说明比例；不同图片发生冲突时，完整结构比最有气氛的一张更值得相信。',
        '“' . $subject . '”还必须离开镜头也能工作。穿上计划中的内层和鞋履，依次坐下、行走、登一级台阶、抬起双臂，并重复场合里持续最久的动作。腰部、领口、袖口或下摆的一点失衡，往往要活动几分钟以后才会显现。',
        '如果缺少一项关键信息，就应把这种未知当成实际风险，因为它可能影响合身、名称、护理、交付或退货。这里尤其需要留意：“' . $risk . '”向卖家或资料方提出能够用尺寸、成分行、结构照片、日期或条款回答的问题，只有“高级”“放心”等形容词不能代替具体答案。',
        '“' . $subject . '”的价值，应放到第二次、第五次穿着中判断，而不只看刚到货的照片。清洁、收纳、简单修补、与现有内搭的兼容性，以及能否服务多个合适场景，都属于成本。能够反复舒适穿着，比单纯堆高装饰密度更有意义。',
        '对“' . $subject . '”的最后判断应当容易说明：眼前衣服或商品究竟是什么，为什么适合预定用途，仍有哪些限制，以及下单或穿着前还要重新确认什么。如果答案必须依赖猜测被遮住的结构或条款，那么这种不确定性本身仍然属于选择的一部分。',
    ];
}

/**
 * @param array<string,mixed> $profile
 * @return array{lede:string,sections:list<array{heading:string,paragraphs:list<string>,table?:array{headers:list<string>,rows:list<list<string>>},bullets?:list<string>}>}
 */
function hanfuR4CoreArticle(array $profile, string $title, string $locale, string $baseSlug): array
{
    $en = str_starts_with(strtolower(trim($locale)), 'en');
    if ($baseSlug === 'hanfu-styling-complete-guide') {
        $articles = require __DIR__ . '/hanfu-r4-styling-guide.php';
        return $articles[$en ? 'en_US' : 'zh_Hans_CN'];
    }
    $suffix = $en ? '_en' : '_zh';
    $subject = trim((string)($profile['subject' . $suffix] ?? $title));
    $definition = trim((string)($profile['definition' . $suffix] ?? ''));
    $evidence = trim((string)($profile['evidence' . $suffix] ?? ''));
    $risk = trim((string)($profile['risk' . $suffix] ?? ''));
    $practice = trim((string)($profile['practice' . $suffix] ?? ''));
    $role = trim((string)($profile['editorial_role'] ?? ''));
    if (in_array('', [$subject, $definition, $evidence, $risk, $practice, $role], true)) {
        throw new InvalidArgumentException('Incomplete reader article profile: ' . $baseSlug);
    }

    $voice = hanfuR4CoreRoleVoice($role, $en);
    $readerNotes = hanfuR4CoreReaderNotes($subject, $role, $risk, $en);
    $working = hanfuR3CoreRoleBody($role, $en, compact('subject', 'definition', 'evidence', 'risk', 'practice'));
    $headers = array_map(static function (string $header) use ($en): string {
        $replacements = $en ? [
            'Evidence to capture' => 'What the page should show',
            'Primary record required' => 'What can support it',
            'Unverified status' => 'Reason for caution',
            'Photo and editorial boundary' => 'What a photograph can show',
        ] : [
            '应保存的证据' => '页面应该提供什么',
            '所需原始记录' => '可以怎样判断',
            '未核实状态' => '需要警惕什么',
            '照片与编辑边界' => '照片能够说明什么',
        ];
        return $replacements[$header] ?? $header;
    }, $working['headers']);
    $lede = $en
        ? 'Many readers first meet ' . $subject . ' through a photograph, a product name, or an occasion they need to dress for. A useful guide looks past that first impression and connects visible construction, material, fit, movement, and context so the final choice still makes sense away from the campaign image.'
        : '很多人第一次接触“' . $subject . '”，是从一张照片、一个商品名称或一次具体穿着需求开始。真正有用的文章不能停在第一印象，而要把可见结构、材料、合身、动作和使用语境连起来，让读者离开宣传图以后仍然能够判断。';

    return [
        'lede' => $lede,
        'sections' => [
            ['heading' => $voice['headings'][0], 'paragraphs' => [$definition . ' ' . $voice['paragraphs'][0], $readerNotes[0]]],
            ['heading' => $voice['headings'][1], 'paragraphs' => [$evidence . ' ' . $voice['paragraphs'][1], $readerNotes[1]], 'table' => ['headers' => $headers, 'rows' => $working['rows']]],
            ['heading' => $voice['headings'][2], 'paragraphs' => [$voice['paragraphs'][2] . ' ' . $readerNotes[2]]],
            ['heading' => $voice['headings'][3], 'paragraphs' => [$voice['paragraphs'][3] . ' ' . $readerNotes[3]]],
            ['heading' => $voice['headings'][4], 'paragraphs' => [$practice . ' ' . $voice['paragraphs'][4], $readerNotes[4]]],
            ['heading' => $voice['headings'][5], 'paragraphs' => [$voice['paragraphs'][5] . ' ' . $working['evidence_boundary'], $readerNotes[5]]],
        ],
    ];
}

return $profiles;
