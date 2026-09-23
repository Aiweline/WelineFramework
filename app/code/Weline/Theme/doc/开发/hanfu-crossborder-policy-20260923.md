# Hanfu 跨境政策文案修订（2026-09-23）

本波仅修正配送、撤回与退款文案，不代表已上线或逐国合规认证。

## 修改前证据

- 配送政策写死现货 1–5 日、运输 7–25 日，并广泛排除清关等延误责任。
- 退款及 Hanfu 退换源将特价清仓、标准尺码颜色、试穿或缺吊牌广泛列为不退条件；退款从质检后另算 3–15 工作日。
- 以上为修改前磁盘源定点读取；保留同文件其它既有脏改。

## 范围与验证

第一波 `default_theme/frontend`：默认 policy/shipping.phtml、policy/refund.phtml。第二波 `design_theme/frontend`：Hanfu Weline_Shipping guide/returns.phtml。仅改正文源串，不改壳、样式、注入或配置。

三个模板 PHP 语法检查通过；`theme:disk:compile` 完成 4 items，输出 empty=1，不能仅据此认定发布内容已更新。随后实际读取本机 HTTPS 中文页面，配送政策新 2.1–2.3、退款新 1.2/5.2/6.2、退换指南新 1.3/1.4/5.3 均已返回。政策模板支持 meta.content 覆盖，但这三次请求显示新源串。翻译交专席；浏览器交互及取消退款流程尚待验收。

本机核验地址：

- https://p05113ef3.test.weline.com:9555/zh_Hans_CN/policy/shipping
- https://p05113ef3.test.weline.com:9555/zh_Hans_CN/policy/refund
- https://p05113ef3.test.weline.com:9555/zh_Hans_CN/guide/returns

本次精确 old/new 映射交翻译席：`/tmp/hanfu-policy-copy-changes.json`（14条）、`/tmp/hanfu-returns-copy-changes.json`（11条）。

## 尚待核实

- 现货、按单生产、量体定制比例及制作周期未获商家确认；未写固定天数或全店纯手工、定制宣称。
- 实时国家级配送匹配：website=0/store=0/channel=0，243 个默认市场均返回服务。这是配置匹配，不等于具体地址报价、支付可达或真实承运能力；未改覆盖配置。
- PDP 的无吊牌/无领标及纤维比例疑点，需供应商提供真实纤维成分、原产地、责任主体、洗护标签证据。
- 欧盟 GPSR 制造商及欧盟责任人需真实实体资料，不得编造。
- 非必要 Cookie 是否在同意前被阻止仍需运行验证；现有按钮不等于已合规。
- 首页满额包邮、固定折扣及具名评价需商业事实核实，本波未改。

## 研究依据

### 九个补充市场的初步研究

以下由政策研究席于 2026-09-23 核查并交接；不代表已确认实际目标市场、法律主体或逐国上线签字。配送配置匹配 243 个市场不能用来推定真实经营范围。商品是否个性化及各地消费者身份、管辖仍需确认，不将欧盟定制例外套用于所有国家。

| 市场 | 初步结论 | 官方来源 |
|---|---|---|
| 加拿大 | 无全国统一普通商品无理由期，省级延迟与信息披露取消权另计 | https://ised-isde.canada.ca/site/office-consumer-affairs/en/business-practices-and-consumer-concerns/refund-and-exchange |
| 瑞士 | 一般网购没有普遍法定反悔权，仍需核对商家承诺与瑕疵权 | https://www.kmu.admin.ch/en/what-is-a-cancellation-right |
| 挪威 | 通常14日撤回，真正个人规格商品例外须符合条件 | https://www.forbrukerradet.no/forside/angrer-du-pa-et-kjop/ |
| 日本 | 默认收货8日退回规则；预先明示的退货特约可能适用，最终确认页也需展示 | https://www.no-trouble.caa.go.jp/foreignlanguage/english/mailorder/ |
| 新加坡 | Lemon Law 不保护单纯改变主意，瑕疵救济仍保留 | https://www.mti.gov.sg/resources/laws-and-regulations/general-advisory-on-amendments-to-the-consumer-protection-fair-trading-act-and-hire-purchase-act/ |
| 香港 | 未确认一般网购普遍冷静期；品质和不公平条款权仍需保护 | https://www.info.gov.hk/gia/general/202607/15/P2026071500218p.htm |
| 中国大陆 | 7日无理由及消费者定作例外，禁止擅自扩大例外 | https://www.samr.gov.cn/zw/zfxxgk/fdzdgknr/fgs/art/2023/art_26ca8fe29e184edd899fa0a7a060d935.html |
| 巴西 | CDC第49条为7日；条文无一般个性定制例外，不能直接套欧盟口径 | https://www.consumidor.gov.br/pages/conteudo/publico/102 |
| 墨西哥 | LFPC第56条交付或签约较晚日起5工作日撤销规则及消费者运保费用，须按适用交易核实 | https://www.profeco.gob.mx/juridico/pdf/l_lfpc_ultimo_libro.pdf |

### 政策完整性只读检查

本机中文 `/policy/term-condition`、`/policy/privacy` 已实际读取。所读正文及页脚仅见品牌“长安汉服”，未见可识别法律主体全称、实体地址或退货地址，全文未匹配直接邮箱。页面有帮助中心、联系我们、账户支持等指路；不据此否定站内可能存在其它联系方式，但需运营提供并验证主体和有效联系事实。

隐私页已有信息类别、用途、第三方服务类型、中国处理与 PayPal/Stripe 跨境说明、保留期限原则、查阅/更正/删除/撤回权。尚未见具体法律责任主体、可核对的完整接收方与跨境目的地/机制、直接权利联系地址；未验证数据实际流向与声明一致性。

Cookie 源已有非必要统计营销先同意的声明及浏览器管理说明，仍需验证拒绝、重开偏好及撤回同意的实际可操作入口和同意前追踪状态。`/policy/cookies` 本轮返回404；单数 `/policy/cookie` 已实际返回完整正文，管理段仅说明浏览器清理及第三方退出，未见本站拒绝或重开偏好的明确操作入口说明；不可继续交付旧复数路径。

本次只记录缺口，不修改这些政策文件，也不编造商家、退货地址或隐私主体资料。

### 公司资料补充与接入边界

用户随后提供公司目录，主线程依据营业执照及本地公司档案核实：成都阿玛云科技有限公司；注册地址：中国（四川）自由贸易试验区成都高新区观东一街666号2栋9层17号；公司联系邮箱：contact@amayum.com。来源：`/Users/weline/Documents/公司发展/资料/成都阿玛云科技有限公司-营业执照副本.jpg`。邮箱未测试收信，不冒称专用售后；注册地址不是已确认退货或营业地址。没有正式英文公司名称/地址，不编造；长安汉服品牌保持。

当前 SiteContactInfo 公用读取只有站名、简介、联系邮箱/电话/地址/服务时间，没有独立法律主体或注册地址字段；邮箱读 Backend 全局配置，联系地址读 Websites SystemConfig。未将本公司信息写进共享默认主题，也未混入联系地址或退货地址。最小接入需基于明确 Website scope 的独立法律字段，并由 terms/privacy 复用读取；本轮未扩建后台或接口。

后续已执行最小接入：现有站点联系配置增加 `website/legal/legal_name`、`website/legal/registered_address`、`website/legal/legal_contact_email`，仅 `scope=website`；SiteContactInfo 新增公共 `resolveLegalContact`，仅 terms/privacy 调用，原普通联系信息读取不变。法律信息只接受该 Website 自身配置，不回退 Global 商户；显示原中文法定名、中文注册地址与公司联系邮箱，不改长安汉服品牌。

官方 ScopeHierarchy 实测：Global=`default.default.default`，默认网站=`default.__website__.default`，独立其他网站测试scope=`other.default.default`。后者继承链不含默认网站。保存前3字段不存在，备份 `/tmp/hanfu-legal-config-before.json`。本地 `saveScopeConfig` 使用 typed ScopeIdentity 和 base_versions=0，仅尝试一次，未重试。

该次保存 PHP PID53502 持续高CPU超过12分钟；原生栈804个样本主要停留在 PHP 数组复制和销毁（`zend_array_dup` / `zend_array_destroy`），没有足够证据定位具体 PHP 函数，不能断言是全量配置循环。原生采样保留 `/tmp/hanfu-legal-save-sample.txt`。只读数据库与连接联合核对：该进程本地端口51246连接 PostgreSQL5432，后端53703处于 `idle in transaction`，阻塞英式英文发布后端51223的 `transactionid` 锁。官方保存链将配置写入、版本和失效/资源发布包在同一事务，独立连接始终未见三字段提交；无法仅由采样确认卡在链的哪一段。

按主线程明确指令，仅对本次自有失败进程53502发 TERM，session93043退出143；不碰共享服务及其他进程。随后独立框架读取三字段仍全空，确认未留下已提交公司值；其他网站和Global仍为空。针对性验收脚本 `/tmp/hanfu-legal-verify.php` 的默认网站精确三值检查失败（当前未保存），另外两项空值检查通过；这不是完整成功隔离验收。代码和可逆备份保留，未重试、未绕过官方保存直接写数据库。公司信息尚未在真实页面验收，不宣称已显示或可上线。当前阻塞为官方保存链高CPU且持有事务，待单独定位修复后重新执行一次官方保存与真实页面验证。

### FAQ 实体一致性修复

安全取消后翻译席确认：原英式英文发布脚本自然恢复并退出0，无重跑；en_US/en_GB新6标签均 published=true、生成词典6/6精确匹配；原等待连接查询为空，阻塞关系已结束。标签成功发布不等于公司配置保存成功。

零售模板 `shipping` FAQ id9（中文）、id25（英文）保留原 ID、问题、scope、排序及状态，通过 FaqService::save 更新为已译的制作备货与运输分开、下单前核实说明。中英 FaqSeedCopyCatalog 同答案同步修正并通过 PHP 语法检查。

Hub 的 hub_0 实际是退换问题，不能假定它仍是配送时效。id52/42 退换答案及 id57/47 退款答案已通过同一服务更新为本波已审政策中英原文（去序号），四条均有保存成功回执。其它语言 FAQ 实体仍待真实目标语言翻译，词典排队不等于实体已更新。未用英文填充其它语言。

可逆备份：`/tmp/hanfu-faq-shipping-before.json`、`/tmp/hanfu-faq-hub-before.json`；恢复须仍走 FaqService::save，不使用直接 SQL。真实浏览器 FAQ/PDP 全语言复验尚未完成。

### 已用于本轮修订的核心依据

- EU 消费者权利：https://eur-lex.europa.eu/legal-content/EN/TXT/?uri=OJ%3AJOC_2021_525_R_0001
- UK 退货退款：https://www.gov.uk/accepting-returns-and-giving-refunds
- US 发货延误：https://www.ftc.gov/business-guidance/resources/business-guide-ftcs-mail-internet-or-telephone-order-merchandise-rule
- AU 交付：https://www.accc.gov.au/business/selling-products-and-services/supplying-products-or-services-that-are-paid-for
- US 纺织标签：https://www.ftc.gov/business-guidance/resources/threading-your-way-through-labeling-requirements-under-textile-wool-acts
- EU 纤维：https://eur-lex.europa.eu/legal-content/EN/ALL/?uri=celex%3A32011R1007
- EU GPSR：https://eur-lex.europa.eu/eli/C/2025/6233/oj/eng
