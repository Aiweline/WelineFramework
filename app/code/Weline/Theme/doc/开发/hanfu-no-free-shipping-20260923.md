# 汉服站取消包邮文案及购物车前端兜底

2026-09-23；用户授权不包邮。主题席施工/合规复审，notify_pm:true，@项目经理。

## 范围

先 default_theme/frontend：Theme 配送政策、trust-badges、promo-banner、mini-cart-icon.js；后 design_theme/frontend：Hanfu promo-banner 与 Shipping guide。FAQ 中英 hub_2 种子及现存实体保持一致。只改本波源文案及已有JS函数，保护磁盘既有脏改；无CSS、主题壳、required注入、支付、费率改动。

- Promo默认改“运费以结算页为准”；trust free-shipping旧key保留兼容，显示“跨境配送 / 运费以结算页为准”。
- 两配送页第3节改“运费”，3.2明确“本站订单不提供包邮，运费以结算页为准。”；policy3.1只移除原句活动规则，不新增计价依据。
- Mini-cart只在后台 free_shipping_progress.enabled===true 时展示进度；禁用或缺失不再伪造USD49门槛。其他网站实际启用免邮的API进度保留。
- FAQ site/site/hub_2：44 en_US、54 zh_Hans_CN通过FaqService::save写准确答案，保留ID及身份/问题/scope/排序/状态。另38种语言实体需要真实译文，未以英文替换。

## 证据

`/tmp/hanfu-free-progress-test.cjs`运行源码函数：修改前disabled高额、missing高额均hidden=false而失败；修改后两项hidden=true通过；真实enabled=true前后均显示通过。此为针对性函数测试，不代表真实购物车端到端验收。

5个phtml及FaqSeedCopyCatalog.php的php-l通过；mini-cart-icon.js的node语法检查通过。FAQ独立连接读回两条答案准确，11个身份与业务字段与备份完全一致。

词条交接 `/tmp/hanfu-no-free-copy-terms.json`；源替换映射 `/tmp/hanfu-no-free-copy-changes.json`；40个原FAQ记录 `/tmp/hanfu-no-free-faq-before.json`。復用键英文遵循现有标准：International delivery / Shipping calculated at checkout。

编译矩阵核对：本波没有Meta CSS变量、JS模块登记或UI bundle变更；theme:disk:compile与resource:compile均非本次所需。theme:upgrade仅搬design主题静态目录，本次默认模块JS仍需真实静态路由检查，不用无关全量编译冒充验证。

## 未完成

真实9555 `/zh_Hans_CN/guide/shipping`首次curl在2.06秒报HTTP2 framing错误（HTTP000），随后HTTP1.1有限重试在25秒连接超时（HTTP000），未获得页面正文。浏览器验收由root顾问负责；不能据源修改/函数测试宣称购物车端到端完成。Cart后端硬编码规则由Shipping技术席处理，不在本席范围。中英词典和其他locale真实FAQ尚待翻译席接力；保存过的运营部件参数可能覆盖默认值，真实首页需确认无旧包邮内容。
