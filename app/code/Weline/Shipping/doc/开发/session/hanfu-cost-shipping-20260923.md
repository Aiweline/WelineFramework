---
slug: hanfu-cost-shipping-20260923
module: Weline_Shipping
mode: team
wave: delivered
status: closed
updated: 2026-09-23
last_checked_by: 项目经理
---

# 中国始发跨境运费调整

用户明确：全部可销售国家，已有禁运保持排除；从中国发货；实际承运成本加50%，不包邮。电商顾问root发起并负责运营验收。不能以已有零售运价或网上泛价冒充实际成本。

## 当前计划

| plan_id | 负责人 | 状态 | 验收条件 |
|---|---|---|---|
| cost-source | Shipping技术席 runtime_diagnosis | closed | 官方公开价和本期燃油来源/计费范围已核，非实际协议成本 |
| shipping-price | Shipping技术席 | closed | 默认站数据库及seed完成，真实Facade价与来源一致，完整浏览器结账另列待办 |
| seed-repeat | Shipping技术席 | closed | 同ID同价同metadata，保护字段不变，重复不加价 |
| embargo-seed-cleanup | Shipping技术席 | closed | KP可售seed移除、原报价归档、DB与重seed不恢复已验；11旧保留已被新需求覆盖 |
| merchant-embargo-11 | Shipping技术席 | closed | 11商家停运seed/DB完成，18总规则、11逐一拒运/US正常、重seed不恢复，root签收 |
| shipping-setup-lifecycle | Shipping技术席 | closed | 官方自动模块新装、29→30及同版重复通过，失败不推进版本；主项目30已生效，root独立签收 |
| no-free-copy | 主题席 hanfu_no_free_shipping | awaiting_test（前波整体上线待办） | 本波代码/中英文已落，浏览器UI未验不在本次DB签收内 |
| translations | 翻译席 crossborder_policy_research | 本次中英完成；旧多语待办独立 | 6词英文发布完成，38语言FAQ未冒称完成 |
| acceptance | root电商顾问 + PM | closed（本次DB/seed范围） | 真实Facade及独立数据库证据，issuer_acceptance/ops_acceptance=pass |

## 冻结用例与契约

- UC1：真实有成本的中国始发目的地与重量报价，结账运费=成本×1.5，经现有货币舍入一次；重复请求不叠加倍率。
- UC2：已禁运目的地仍不可选择可用配送；不得将地址国家库或覆盖匹配表当作可实际履约证明。
- UC3：普通/高额购物车都不触发商家免邮规则；PDP、购物车明确运费另计，结账可算时显示确价。
- UC4：若实际承运成本缺失，不编报价；记录精确缺失数据并先完成不依赖成本的去包邮文案。
- Shipping席拥有费率/禁运调查与正式服务修改；主题席拥有模板可见文案及FAQ实体，翻译席拥有CSV和词典。不得并行改相同文件。
- 现有品牌、税费准确说明、主题壳/required注入和工作区脏改保留。无新视觉结构或CSS改造。

## 已知运行风险

上波公司SystemConfig官方保存高CPU持PG事务阻塞词典，已安全取消回滚；记录见 Theme/doc/开发/hanfu-crossborder-policy-20260923.md。本波先查官方Shipping路径，禁止盲重试长保存或直接SQL写入。9555为本地测试URL，非生产故障证据。

## 顾问约束

配送费用可含商家加价，前台称配送费，不宣称承运商原价/实报实销。下单前披露配送费用或计算方式；内部倍率无需展示给消费者。UK：https://www.gov.uk/online-and-distance-selling-for-businesses/online-selling；EU：https://europa.eu/youreurope/citizens/consumers/shopping/shipping-delivery/index_en.htm 。

## 未完成与通知

全部计划未关闭；等待成本来源调查及包邮源定位。当前没有真实原成本/新价实例，不填虚构数值。root已收到组队与约束通知；顾问验收pending。

### 初步实证与调度

Shipping席确认YANWEN active但运行Provider的enabled/environment为null，user_id/api_token/default_city_id缺；当前local WLS_STD和SEED_TPL为CNY/kg手工零售模板（固定9.90、Americas weight_rate14仅为现有模板例子），不能当成本×1.5。尚未取得真实承运成本；root已向用户异步索取实际物流商/报价来源。暂不新增Yanwen专属倍率、不写费率。

翻译席只读交付后释放并发槽，真实主题席已启动。核心问题含mini-cart后台enabled=false仍fallback49，须尊重明确关闭且保留其他网站原有免邮功能。新增UC覆盖不同目的地重量、换币一次、超49仍收费与重复执行不叠加；缺成本只沿用既有无法报价行为，不新增业务门禁。

本波MCP prepare/resolve均报MCP_RUNTIME_STALE；官方ensure本地ready且未要求app-server重启，但host调用仍旧代次。已依AGENTS fallback读AI硬规则索引，不假称本波MCP准备成功，不重启共享服务。

默认站补证：CN始发地址id1 active/default；生效禁运AQ、BV、GS、HM、TF、UM、KP，inactive的NZ/CA旧例不作禁运。唯一活动免邮rule2 SEED_FREE_49挂service3 SEED_LANE_DOMESTIC，批准ShippingConfigurationAdminService::setFreeShippingRuleActive(2,false)官方一次关闭与备份读回。所有现有禁运保持。

用户随后明确成本来源用燕文；root查官方公开表，技术席提能力方案。未获得账号协议价，不能把公开价冒充账号实结价。

新增plan `cart-progress-source`：Cart FreeShippingProgressService硬编码USD49/true，不读Shipping规则，禁用规则本身不足。Shipping技术席负责最小真实规则读取方案与实现，主题席只修JS不作49兜底，两者契约enabled=false/无真实progress则隐藏，真实可用免邮规则时保留功能。此项in_progress，issuer_acceptance pending。

官方禁用rule2已成功0.22秒，独立读回is_active 1→0、仅updated_at伴随变化；备份 `/tmp/hanfu-free-rule2-before.json`。主题5模板/JS及中英FAQ种子已修，FAQ实体44/54官方保存成功且11个保留字段读回一致。其他38语言FAQ仍待真实译文，不能标全站多语完成。

mini-cart前端最小行为红绿：旧disabled或缺进度在高额购物车仍显示，修后隐藏；显式enabled=true仍显示。PHP语法通过。Cart后端仍在处理，完整运行验收未过。此波不新增报价引擎/后台Query接口；当前summary缺目的地不应展示未经适用匹配的国内免邮规则，优先复用现有报价证据。

### PM交付检查

主题席施工/合规复审已交，证据文件 `Theme/doc/开发/hanfu-no-free-shipping-20260923.md` 存在，源修改映射及FAQ备份可回查，未改冻结意图；no-free-copy进入awaiting_test，未关闭。翻译席恢复，仅本波6词CSV与enUS/enGB词典发布，38其它语言FAQ尚未完成。

真实ShippingFacade服务调用（非浏览器下单）：CN始发id1、1kg、100CNY大于49，到CN14000分、US7300分且free_reason无；KP无匹配服务。仅验证现有本地零售模板不包邮，绝不是燕文成本或×1.5成功。证据 `/tmp/hanfu-shipping-quote-read.json`。

root顾问已收到运行验收入口 `/guide/shipping`、`/policy/shipping`、`/faq`（9555），等待issuer/ops acceptance。主题席HTTP探针失败，当前不以源码成功替代真实页面通过。

### 本波收口状态（未完成上线）

Cart最小后端已交：删除无依据49进度，当前缺目的地/匹配证据的summary返回enabled=false；不改真实配送规则、不扩API/报价机制。2 tests / 16 assertions通过；正式 `w_query('cart','summary')` site0/store0/channel0自然完成exit0、success=true，空车顶层及data.free_shipping_progress.enabled=false，证据 `/tmp/hanfu-cart-progress-read.json`。只代表该真实空车查询，不代替带商品购物车/结账浏览器验收。

一次9555健康检查仅见master PID96504未见worker，HTTPS连接失败curl28/HTTP000；root真实Chrome亦ERR_CONNECTION_TIMED_OUT。未重启或停止共享服务，探针均结束。验收外部阻塞保留，issuer/ops acceptance未pass。

本波6词CSV已精确补齐，官方enUS/enGB发布回执待翻译席；停止扩大调查/测试及38语任务。成本×1.5尚未实现，等待用户燕文登录或官方实际报价来源。已有CN模板例子叠加多个地区seed附加费，不能视为可信客户运价；此波未修模板定价，不据其宣称可上线。

### 用户更新：允许其他承运商官方公开报价

用户随后询问公开报价并明确不限燕文。当前不再等待燕文登录；root核对2026顺丰国际/FedEx/DHL官方服装非文件价表及燃油，Shipping技术席仅评估已有国家分区、0.5kg梯度、计费重、币种、附加费和成本×1.5映射能力。尚未获得完整可核算基数前不写费率，不扩通用定价引擎。保持已有7禁运与不包邮。

翻译追加终态：原 `/tmp/hanfu-no-free-publish.jsonl` 已有en_US、en_GB各自published=true回执（各6词，upsert5），未重跑。真实页面仍待服务恢复验收；其它38语FAQ仍不标完成。

root核实顺丰官方公开4页2026价表（标快2026-01-03/特惠2026-01-20）：https://www.sf-international.com/cms/cms-service/admin/file/get/d47b68236d174adea90e2ba383d072001488926341242949632.pdf 。非文件1kg特惠JP237/US372/GB363 CNY，对应仅基础×1.5为355.50/558/544.50 CNY，未含燃油偏远，绝非本站已配置最终运价。国家按EE/SE/GE和区分别映射；max实重/长宽高除5000，低于20kg向上0.5kg、至少20kg向上1kg。

只读适配初判：现有国家区/weight_table/CNY可存，但逐商品材积计算不等于包裹计费重，无该分段向上取整，半开区间边界需准确映射；换汇舍入在附加费前，燃油未限定carrier/service/基数，无最终成本×1.5字段。不能直接抄价表称完整符合。按root本轮界限停止评估，不写不完整价格，不扩框架；pricing计划保持未完成，等待后续完整成本核算与对应最小实现范围。

## 最终实施授权波：公开标快取高价并同步种子

用户现明确授权比较同目的国/同计费重量可用普通包裹标快公开价取最高×1.5，写当前默认站数据库并同步标快seed。前段只读限制被本次明确实施授权替代；仍不允许猜缺失数据。

分工：root电商顾问负责SF与燃油依据/业务审阅；研究席crossborder_policy_research仅提取FedEx普通International Priority和DHL Express Worldwide官方表；技术席runtime_diagnosis负责重量边界、现有seed/官方幂等写入及必要最小代码；PM负责契约/证据/验收，避免同文件并行。

### 本波冻结接受条件

- UC-price-max：仅普通包裹标快，同目的国家与同承运计费重可用报价取max，×1.5一次；不混文件、特惠、定时溢价或示例价，不相加承运报价。
- UC-weight：真实重量与材积依据可追踪，精确0.5/1kg档边界与向上规则；抽核0.5、0.501、1、19.999、20kg及实际适用范围，不能半开区间错档。
- UC-source：每个导入价可追溯来源URL/日期/服务/国家区/重量档；缺报价不造0。燃油/偏远基数按真实条件，不能继承旧多个地区seed附加费误叠加。
- UC-scope：仅默认站标快；7禁运不变、rule2保持关闭、其他站/非本轮服务不被误改。
- UC-repeat：同源输入重复执行值不变、不会再次×1.5，不覆盖人工禁运；写前备份、官方保存后独立读回；seed与DB相符。
- UC-runtime：当前系统真实quote国家/重量/CNY及展示换币一致，真实购物车→结账金额可核对；若服务不可达如实记录，不以合成测试替代。

技术席确认迄今未写任何运价，之前仅关闭免邮rule2。当前任务进入方案+实施，成本数据待研究席和root核对，pricing与acceptance仍open。

### 方案冻结与首份真实源数据

实现按所有承运商阶梯断点并集离线比较，分别按自身计费档获得费用后max×1.5输出CNY销售档，保留每个候选及胜出来源；运行只给新模板上界包含语义，不建结账多承运商引擎。最后表档不得无限延伸。新增默认站seed分支绕过原clearConflictingMarketEmbargoes及直接落库fallback，官方保存失败必须报告；写前后逐行比对全部active禁运。现有WLS_STD经济小包估价不进比较集合。

root已核SF源 `/tmp/hanfu-public-rates/sf-standard-source.json`：74国SE/GE+、39个0.5–19.5kg档、7个20kg以上perkg档；2026-01-03原价有效。燃油快照2026-09-21至27，SE43.75%、GE+44.50%，来源 https://www.sf-express.com/chn/sc/support-more/international_fuel_surcharge_introduction 。原文件保留基础价/服务/国家区/hash/除数及进位，不能覆写源为销售价。

最终比较范围为普通包裹标快基础+已核燃油快照，再取最高×1.5；偏远、住宅、超限等条件费需真实地址/邮编/包裹资料，元数据明确未默认为全部已含，不新增阻断门禁。新价已含燃油，不得再叠既有scope fuel或seed示例附加费。日期快照可更新，不伪造expiry禁用策略。消费者只见最终运费，不披露内部倍率。

源表边界追加：19.5→20kg转perkg可能合法降价，不加单调性拉平；验收0.5/1/19.5/20/20.01/21kg。SF1000+的无穷perkg尾段不能当0/无价或无限固定价；不能无官方依据截70kg。DHL10kg后原表半公斤缺行的适用规则待root核实，不插值猜价。FedEx美国分州/别名在国家映射前需审清，不能当成额外国家或随取最低。

root后续核DHL p25/26官方≤30kg每0.5kg累加续重，10–20及20–30各区整kg价差分别恒定，批准按相邻整kg差÷2推导半档并逐整kg回代；审计标“按官方0.5kg续重条款推导”。>30kg的perkg30.1–70/70.1–300/300.1–3000向上1kg。

燃油事实文件 `/tmp/hanfu-public-rates/fuel-20260923.json`：本期DHL45.00%、FedEx中国51.75%（root真实官方浏览器表确认）、SF SE43.75%/GE+44.50%；全部期间2026-09-21至27，不提前用下周值。

运行验收补充：root外部官方网页可用，本地9555仍超时。因此本波继续完成用户重点的官方服务写入、真实数据库独立读回与真实ShippingFacade报价，不等UI、不重启共享服务；UI未验须最终明确。

### 数据审查返工

root及PM在最终offers抽核发现历史ISO/别名映射（DY/HV/TP/FX/SU/YU/UK）导致当前GB等候选缺失。已暂停数据定稿和DB写入，研究席须核原国家名称、当前ISO与项目Region码，保留原码审计并合并有效候选，全面检查keys；不能机械改码或丢失法国/英国最高候选。核心代码施工可继续，数据关项须修正版checksum与抽核证据。

本次国家级定价允许同国不同有效官方区（如美国FedEx邮区1/2）取高，元数据注明国家级保守定价及胜出区，不冒称精确收件州实际成本。

映射返工完成并由root独立审核pass：232当前ISO目的地/433offers保留，14源行纠正，历史无效键0、项目Region全部匹配。修正版SHA256 `53368c8a4e0b34e75582bab53d60352cefd96344d6cb4cc9275698a60af43f8c`，解除数据定稿暂停。

并行施工：核心技术runtime_diagnosis拥有compiler/计价/管理员metadata/费用隔离/三商融合与最终统一DB写入；独立后端seed席复用已完成主题任务的hanfu_no_free_shipping，仅拥有PublicTariffSeedService与DefaultShippingLaneSeedService默认站分支，不兼改主题、不写DB。新spawn达到thread上限，故实际重新派已空闲代理，PM仍不代写业务码。下一关键路径为seed集成、黄金金额比较、备份、官方写库、独立读回与重复幂等、真实quote。

融合产物已生成 `Shipping/data/public-tariff/standard-20260923.json`：232国510offers（含SF），未写DB。root与PM独立Decimal、技术compiler三方1kg黄金结果一致：US/CA1622.97、JP1167.72、DE/GB/FR1761.82、AU1402.88 CNY，成本保留小数到最终×1.5分舍入一次。审计含候选zone/postal、胜出zone、源hash/燃油期间/DHL推导说明、SF无限perkg尾段。

### 写入前范围最终澄清

写前快照 `/tmp/hanfu-public-tariff-before.json`：website0、profile1 SEED_PROFILE_GENERAL、CNY、active禁运id4–10七条。新服务PUBLIC_STD_ISO，源含KP但官方Embargo evaluateAddress跳过并返回skipped匹配证据，不改源/禁运。

root明确中国国内不属出境国际报价，最终只替换General的8条旧国际服务id4–11，保留DOMESTIC id3国内链接与原规则（其免邮rule2仍关闭）；先核其仅CN。国内原价不算本轮三商取高覆盖。前文“9条解绑”是旧候选方案，以此8条最终约束为准，seed席已同步修正。

### 真实数据库写入结果

US/GB小样官方save 0.139秒，独立进程完整metadata/brackets读回一致，1kg运行模板分别162297/176182分；未提前切全量profile。随后全量官方save 4.96秒完成231套模板/服务/国家绑定，KP命中原system禁运id10跳过。国内service3确为唯一active countryCN，General保留此链接。

独立新进程逐231国metadata/brackets结构与种子一致、max_weight=null；六国1kg运行模板与golden一致。审计 `/tmp/hanfu-public-tariff-readback.json`，写后快照 `/tmp/hanfu-public-tariff-after.json`；种子SHA256 `5786c8a168cb7ed8c6083e769f60623cdc700f24e2a889305ad1c023e42a818f`。正在完成已约定ShippingFacade真实报价、重复seed及保护字段不变验证，未先标闭环完成。

真实ShippingFacade六国1kg与golden一致，subtotal1000CNY仍收费，无旧国际seed/重复燃油或远程示例附加；US20/20.01/20.5/21kg分别15651.50/15983.83/15983.83/16013.42。KP拒绝，CN原DOMESTIC保留（仍有既有多省示例费，不称可信运价）。禁运7全行、免邮规则6、发货地址、其他profile链接及全部原services前后完全一致。

### 始发区域补证（收口前必要修正）

root从真实快照发现ShippingAddress1实际为广东的占位仓地址；公司四川注册地址不能用来证明FedEx cno广福以外报价适用。保留该地址不改为注册地址。研究席追加官方2026广福内cns普通IP价表/zone，按用户中国始发取高意图比较两中国区域最高并记录origin-region保守计价。若cns不高需完整比较证据，若更高则重新融合并按已验证幂等入口更新seed/DB；当前地区口径尚待补全，不先声明最终运价已充分核验。

CNS已追加206国207offers，480源价比较107高/13同/360低。融合变232国717offers，231新价仍写入；56国883固定重量档上调，AF0.5kg1773.20→1775.48，六国1kg不变。metadata明确两中国始发区域保守取高，不声称实际仓成本。此阶段hash非最终：研究复核A区HK/MO免燃油条款待回执，若需修正将更新最终hash。

### 保留无新公开源国家的原可售行为

root要求本轮不减少原可售国家。真实AX/XK复现解绑后no_matched_lane；11国原有路径快照：AX/IM/SJ/XK→欧洲sid7模板8，CC/CX/IO/PN/TK→亚太sid5模板6，EH/PM→拉美sid9模板10。已批准仅country限定LEGACY_STD_ISO引用原费率保留绑定，不复挂宽旧lane，不借机改原地区分类或造价；前台名称不暴露内部LEGACY。最终目标231新公开价+11旧价保留+CN原国内配置。无新增禁运。当前核心技术席接管seed小修（原seed席已完成无并行写），完成后真实quote验恢复。

root最终直接核FedEx CNO官方PDF第2页（P1 L189–199）：脚注2是美国西部州名单，没有CNS的A区免燃油条款；L197明确燃油及其它附加费另收。因此CNO港澳候选维持51.75%，CNS按其本身A区免燃油说明处理，不能跨地区套用。解除最后燃油疑问，执行最终11国原价保留保存与验证。

## 最终签收：本次数据库与种子范围完成

本节覆盖前文的旧510offers/旧hash/待写入状态。最终源232目的地、717offers（包含中国两始发区域保守取高），种子 `app/code/Weline/Shipping/data/public-tariff/standard-20260923.json` 最终SHA256：`f2345ed1468c1eb1d0206bdffb6b5dae9c429084bff3b092267fb4a30064c2db`。

当前默认站正式数据库：231个PUBLIC新国际价、11个country-only原价保留服务、CN原国内规则。KP有源但依原禁运排除；11个未纳入新公开定价国家为AX、CC、CX、EH、IM、IO、PM、PN、SJ、TK、XK，均保留原有费率路径，不伪称本次公开价覆盖。最后save1.105秒、repeat1.008秒；相同ID/价格/metadata，无重复记录或二次×1.5。231新模板在独立进程完整读回与seed一致，CNY/max_weight=null。原有services、全部禁运7行/免邮规则6行/发货地址/其他profile links逐行不变；General保留国内且只替换8旧国际绑定。

最后真实ShippingFacade报价：US1kg1622.97 CNY只匹配PUBLIC_STD_US；AX/XK1kg68.00 CNY各只匹配country-only旧价保留服务；KP无lane拒绝。前述六国及20kg边界已通过；US美元241.82由现有FX转换一次，日元当前FX缺失返回null，没有伪补。最高原价比较包含本期燃油、排除税与需地址条件的特殊附加费用，不能宣称实际仓库全包成本。

最终证据：`/tmp/hanfu-public-tariff-delivered.json`（最终数据库快照）、`/tmp/hanfu-public-tariff-delivered-quote.json`（最后真实报价）、`/tmp/hanfu-public-tariff-readback.json`（231逐国来源/售价/运行读回与hash）、`/tmp/hanfu-public-tariff-uncovered-after.json`（AX/XK恢复）、`/tmp/hanfu-public-tariff-coverage.json`（覆盖分类）。PM已独立重算种子hash并读取US/AX/KP证据。

root电商顾问已独立核实并签收：`issuer_acceptance=pass`、`ops_acceptance=pass`，仅限本次数据库与种子配置范围。该范围的cost-source、shipping-price、seed-repeat与PM复检计划closed；当前波次delivered。9555真实浏览器UI验收仍因超时未通过，保留为原站点整体上线任务待办；不能把本次配置完成升级为全站可上线声明。本次不再扩大调查或修改。

## 用户追加：移除既有禁运国家的可售种子

用户询问11个缺公开源地区是否已禁运，要求若已禁运则种子也删除。新增有界计划embargo-seed-cleanup=in_progress：以当前真实Embargo交集为准，不根据偏远或缺公开源猜测新增禁运；若11国交集非空，移除对应默认站可售绑定及seed。KP已实际禁运且仍在公开可售seed的countries集合，应移出此集合，官方原报价归档保留，禁运规则本身不删。核心技术席复用现有正式服务确认DB无可售项、重seed不会恢复，保护其他231公开价/原价保留/国内配置。此追加待技术证据与root签收，前波验收历史不覆盖新增改动。

追加施工完成：实际7禁运AQ/BV/GS/HM/TF/UM/KP与11缺源目的地交集为空，11保留未删。可售seed countries移除KP后为231国；原始KP报价完整保留仓内 `Shipping/data/public-tariff/source-audit/excluded-kp-20260923.json`，仅审计不被seed导入，禁运规则不删。最新可售种子SHA256 **`6dc808d212b2f5133845e6069edb64ae2715dd3a49c150698c7eaec31e7ad6d4`**，覆盖上节旧f2345ed1哈希。

官方重seed1.217秒后仍231PUBLIC+11原价保留+CN，无KP service/lane；独立231模板读回一致，所有既有价格/service ID/profile links/禁运/免邮/地址不变。真实Facade KP仍拒运，US1kg1622.97、AX1kg68.00 CNY保持。证据 `/tmp/hanfu-public-tariff-kp-before.json`、`/tmp/hanfu-public-tariff-kp-after.json`。PM独立检查seed无KP/231国/hash与仓内归档存在；没有新增通用过滤器或运行门禁，现有禁运机制保留。

## 最新用户授权：11个缺公开报价地区全部商家停运

此需求明确推翻上述“11原价保留”：用户决定AX、CC、CX、EH、IM、IO、PM、PN、SJ、TK、XK均不运输，加入禁运种子并修改数据库。新计划merchant-embargo-11=in_progress，原Shipping技术席负责最小实施。沿既有持久禁运seed和官方DB接口新增/启用商家停运规则，不宣称法律制裁；移除这11的LEGACY可售seed及默认General配送绑定，使重复seed不能恢复。原7禁运、231PUBLIC公开价、国内服务、免邮关闭保持，历史报价审计不删除。

验证限定：写前快照→官方保存→独立11规则读回；真实AX/XK拒绝且US正常；重复seed规则不重复/11配送不恢复，其他保护配置不变。本波root业务明确接受上述最新范围，不使用旧波FINAL冒充本波结果。

入口审查：SystemEmbargoAdminService种子固定system并purge；EmbargoAdminService仅全量replace会删除该scope其它既有行，因此不用于此次11项增量。采用既有同模块EmbargoRegion模型save模式，website0、reason_code=merchant_suspended，持久默认站11国商家停运数据接现有PublicTariffSeedService；不新建接口/表、不SQL、不修改原system禁运。

### 最新最终状态：11全部停运，旧保留决定失效

持久禁运种子 `app/code/Weline/Shipping/data/public-tariff/website0-suspended-destinations.json` 已包含准确11项AX/CC/CX/EH/IM/IO/PM/PN/SJ/TK/XK，website0、merchant_suspended，原因“商家暂停向该目的地配送”。PublicTariffSeedService移除原preserve分支并接该数据；数据库11条对应商家禁运均启用、11条LEGACY服务停用且General解绑，重seed不恢复。

正式seed1.422秒、重复1.353秒；active规则共18=原system7+新增website11；所有原禁运行（含inactive）逐行保持、新11 ID重复稳定。231公开价模板金额、CN原链接、免邮6、地址与其它profiles不变，历史报价审计保留。本节明确覆盖前述“231新价+11旧价保留”的旧业务决定；当前为231公开国际报价+CN国内原配置，另7系统禁运与11商家停运。

运行证据 `/tmp/hanfu-stop11-evaluate.json` 的11项全部blocked=true/merchant_suspended；`/tmp/hanfu-stop11-quote.json` 全11逐一无可用配送，US1kg仍162297分。完整快照 `/tmp/hanfu-stop11-before.json`、`after.json`、`repeat.json`（后两路径同hanfu-stop11-前缀），完整含inactive规则快照同目录all-embargo-before/after/repeat。PM独立核18规则与12项报价结果；root亦独立读取并确认本波 `issuer_acceptance=pass`、`ops_acceptance=pass`。本次最新DB/seed任务closed，不扩作整站UI上线验收。

## 新授权：修复安装/升级生命周期

用户在只读确认后明确“修好”。Shipping技术席负责最小模块内修正，PM负责范围与证据。前波DB成功不等于Setup生命周期成功：Install没有接新seed，默认站earlyreturn跳过新装依赖；Upgrade seed异常被吞，随后align49还会重新启用包邮；当前版本与setup_version均2.9.29，不会无版本迁移自动应用新代码。

冻结UC：新装自动建立必要依赖并应用231公开价、7系统禁运、11默认站商家停运、默认不包邮；现有2.9.29经正常版本迁移应用新seed；同版本重复不强制覆写人工；以后升级不重新align49。新seed失败传播为安装/升级失败。其它网站与无关人工配置保护。真实隔离数据库执行安装、既有升级与重复验证，不在共享主库做破坏性新装，不用仅调用PublicTariffSeedService或模拟结果代替完整生命周期证据。禁止大改通用setup架构。

方案冻结：Shipping模块2.9.29→2.9.30；Install建立缺失最小依赖并调用同一初始化；Upgrade依据from_setup_version仅一次迁移，去align49自动激活，关键seed异常传播。核心席实现，独立只读工程探索席复用crossborder_policy_research核隔离DB/正式模块限定setup入口，不再做报价研究。追加验收：隔离安装与升级后，当前项目也执行受支持的Shipping模块限定正常升级一次并读回，禁止主库新装/全库清理。完整Upgrade既有system种子行为需确认不会覆盖无关人工配置，不只测Publicseed。

生命周期实现进度：核心4文件已落并通过PHP语法检查；既有legacy迁移限定from<2.9.29，本次29→30走一次public初始化，from≥30不重复覆盖。system禁运初始化使用既有addRegion只补缺失canonical国家，不purge且不重新启用人工停用行。精确正式入口为 `php bin/w setup:upgrade -m Weline_Shipping --sync`，不使用强制或重装参数。

隔离工程已启动：独立完整工作根与专用PG数据库、独立env/modules/generated/var/cache，清隔离副本wls.instances，不用仅SET search_path（连接器固定public），不复用有共享副作用的现有E2E模拟。研究席正式转隔离验收工程师仅准备环境，核心席执行完整生命周期与主项目正常限定升级。当前尚未运行完整新装，不以lint代表验收。

首次正式隔离29升级已执行：独立根 `/tmp/hanfu-shipping-lifecycle-20260923/upgrade29`，独立PG库 `hanfu_shipping_lifecycle_29_20260923`，1189张基础表；无主根symlink且WLS identities已清。正式限定runner exit1，在Shipping Setup之前的Hook owner定义检查失败（Order shipping/shipments、Checkout quick-add、account地址group），日志 `/tmp/hanfu-lifecycle-upgrade29-failure.log`。setup_version仍2.9.29，未把该前置失败记作seed异常传播通过。技术席只在隔离环境核受支持catalog重建，不绕校验或改通用框架。

隔离29→30实际通过：定向恢复两份纯数据注册表generated/hooks.php与generated/framework/modules.php并重映射隔离路径后，正式CLI exit0；日志明确Install0/Upgrade1，Shipping实际Upgrade7890ms。独立环境席只读回验 `/tmp/hanfu-lifecycle-upgrade29-readback.json`：连接为专用库，version/setup_version均2.9.30，231PUBLIC模板与服务active、system7+website11禁运、6默认SEED_FREE全disabled、US1kg1622.97CNY。此为真实完整升级及独立读回，不是仅Publicseed直调。

fresh首轮CLI exit0但module_setup任务0，未进入Install，明确不算新装通过。隔离新模块注册时setup_version被规范为30且installing未进入收集；技术席通过明确待安装fixture与官方ModuleSetupStage→Handle::setupInstall继续验证模块Install→Upgrade完整链，此结果将与CLI自动注册未通过分开记录，不更改通用框架。

新装自动入口明确诊断：现有setup:upgrade确为新模块自动注册入口，同一CLI两次register。Framework Module Handle existing分支新建Module只复制setup_version/upgrading，丢弃首轮installing，导致真实CLI收集0个Install；此外隔离fixture只删Shipping表还残留schema检查点。辅助Install链表缺失失败不记pass。root根据实际故障批准必要单点Framework修复：保留原待安装状态/版本语义，先最小重现测试、保护已安装注册及同版跳过；清理仅隔离Shipping schema/checkpoint再完整CLI验自动Install，不扩通用架构。主项目Shipping正常升级已启动，实际Shipping阶段执行后处于末尾优化，待正式exit/回读。

自动新装修复实证：Handle重复register最小复现修前丢installing且setup_version误30，修后installing=true、setup_version0、upgrading=false（`/tmp/hanfu-lifecycle-register-before.log`、`after.log`）。官方fresh CLI真正收集Install1，创建地址后沿官方setDefault保证仅本轮新地址成为默认。故意缺seed时CLI exit1、setup_version0且installing保持，证据 `/tmp/hanfu-lifecycle-fresh-failed-version.json`；恢复后成功，再从无Shipping表/检查点/注册项的干净隔离状态正式CLI成功Install5935ms、exit0，日志 `/tmp/hanfu-lifecycle-fresh-clean-success.log`。独立业务读回进行中。

主项目正常29→30实际Shipping Upgrade3275ms已完成，完整命令仍经历既有42语言404发布与末尾framework:compile，日志 `/tmp/hanfu-lifecycle-main-upgrade.log`；未人为启动额外翻译，未把阶段成功当全命令exit。29同版本重复CLI已exit0，人工值保护等待最后读回证据。

### 生命周期最终完成与边界

本波5个精确文件：Shipping `Setup/Install.php`、`Setup/Upgrade.php`、`Service/DefaultShippingLaneSeedService.php`、`etc/module.php`（2.9.30），Framework `Module/Handle.php`（重复注册保留pending-install状态）。完整命令及文件hash清单 `/tmp/hanfu-lifecycle-acceptance.json`。隔离29升级、干净Shipping新模块自动安装、两者同版本重复与当前真实项目正常升级均正式CLI exit0；缺seed失败注入exit1且setup_version不前进。重复两次ModuleSetup任务0，不覆盖同版管理员配置。主项目正式setup本身执行了既有WLS代码重载，未额外重启共享服务。

独立只读验回完成：`/tmp/hanfu-lifecycle-fresh-readback.json`、`/tmp/hanfu-lifecycle-main-readback.json`、`/tmp/hanfu-lifecycle-upgrade29-repeat-readback.json`。fresh/main真实DB身份已核，setup_version2.9.30，231active公开模板+231服务，system7+website11禁运，6个默认包邮seed全关闭，默认始发CN/WLS_STD/General均存在有效；数据库模板经真实compiler算US1kg1622.97CNY（并非浏览器checkout验证）。29同版重复保留隔离人工服务名、人工启用49规则及website77人工inactive禁运，证明同版本不强制重seed。

范围准确：fresh验收为已有系统依赖基线中移除全部Shipping表/检查点/注册项后的正式自动新模块安装，不声称空白全系统system:install通过。当前项目真实升级已生效；静态2026公开基础价及2026-09-21至27燃油快照仍是种子数据，不自动联网刷新运价。原整体UI超时未被本波视为上线验收通过。当前实现与验证已完，待root独立签收，不新增测试/范围。

root已独立核三份回验JSON与六次正式命令、失败版本0证据，确认本波安装/升级seed修复 `issuer_acceptance=pass`、`ops_acceptance=pass`。shipping-setup-lifecycle closed，本轮结束，不扩测。隔离repeat人工49启用仅为证明同版保护的隔离数据，当前真实项目6默认免邮仍全关闭。
