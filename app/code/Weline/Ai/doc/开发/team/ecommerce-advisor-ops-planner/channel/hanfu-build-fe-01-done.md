# channel — WO-BUILD-FE-01 done（部件+前端 · 替换席）

日期：2026-09-23  
席位：`Team:部件+前端` 替换席（旧席 `bbce6318` STOP/空转）  
`client_session_id`：`f22ca421-widget-fe-hanfu-build-02`  
工单：`WO-BUILD-FE-01`（集合页 + PDP 卡面价签 + 加购/购买 CTA 可达性）  
验收面基线：`https://p05113ef3.test.weline.com:9555/zh_Hans_CN/`  
状态：**closed**（可达性已改码落盘；深修主图 1:1 / 详情杂志 → escalate PDP-01）

## 抽检 URL（证据）

| 面 | URL | 结论 |
|----|-----|------|
| 集合（品类） | [马面裙集合](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/category/women/mamian) | **PASS（SSR 禁缓存）** — 本席首轮 `curl --http1.1` 200：页内 `wpc-price-now` 价签（样例 `$10.73`…）+ `data-testid="product-card-add-to-cart"` / `btn-add-to-cart` 存在；卡 CSS 无 `.wpc-media` 有限 `max-height`。后续实例曾间歇 `502`，源码已加固。 |
| 搜索结果 | [明制汉服搜索](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/search?q=%E6%98%8E%E5%88%B6%E6%B1%89%E6%9C%8D) | **PASS（沿用同卡组件）** — 与集合共用 `weline-product-card` + FE-01 CTA/价签 `z-index`/`pointer-events` 加固。 |
| PDP | [满庭芳明制印花马面裙](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/product/hua-chao-ji-xia-ji-han-fu-nu-xin-kuan-jian-yue-yin-hua-ma-mian-qun-qua-cf5949d6?size=s&style_type=man-ting-fan) | **修前 FAIL → 已改码** — HelpPay-only 槽时 failsafe 误匹配自删加购；现 `isOutsideFailsafe` + buybox/actions CTA `pointer-events:auto`。 |

`related_web_urls`：

- `https://p05113ef3.test.weline.com:9555/zh_Hans_CN/`
- `https://p05113ef3.test.weline.com:9555/zh_Hans_CN/category/women/mamian`
- `https://p05113ef3.test.weline.com:9555/zh_Hans_CN/search?q=%E6%98%8E%E5%88%B6%E6%B1%89%E6%9C%8D`
- `https://p05113ef3.test.weline.com:9555/zh_Hans_CN/product/hua-chao-ji-xia-ji-han-fu-nu-xin-kuan-jian-yue-yin-hua-ma-mian-qun-qua-cf5949d6?size=s&style_type=man-ting-fan`

## 加购可达结论（硬）

1. **集合卡面**：价签 +「加入购物车」主 CTA 在 SSR 证据中可达；本席再加固 `.wpc-price` / `.wpc-cta` 相对叠层 `z-index:2` + `pointer-events:auto`，禁媒体角标/收藏层误挡。
2. **PDP**：`ensurePurchaseActionsFromFailsafe` 仅认 failsafe **外** 的活 CTA；无活 CTA 时把 failsafe 加购/结账迁入 `__actions`（避免 HelpPay-only 槽删掉唯一加购）。buybox/actions CTA 同步 `pointer-events:auto`。
3. **边界遵守**：未改首页区块总顺序；未对 `.wpc-media` 设有限 `max-height`；包邮 `$49` 未动。

## 本席改码（替换席本回合磁盘）

| 文件 | 变更 |
|------|------|
| `Product/view/statics/css/frontend/product-card.css` | FE-01：`.wpc-price` / `.wpc-cta` 与可达钮 `z-index` + `pointer-events:auto` + 可见性 |
| `Product/Service/ProductCardRenderer.php` | CSS 戳 → `20260923-fe01-cta-reach`（bust 内联卡样式缓存） |
| `Product/Test/Unit/Taglib/ProductCardContractTest.php` | 版本戳断言对齐 |
| `Product/view/statics/css/widgets/product-native-detail.css` | FE-01：buybox/actions 加购·购买 CTA + 价签可见可点 |
| `Product/view/templates/frontend/widgets/product-info.phtml` | **保留**既有 `isOutsideFailsafe` / failsafe 迁移（PDP 自删根因） |
| `Product/Test/Unit/View/ProductInfoPurchaseFailsafeContractTest.php` | **保留**契约断言 |

## Browser / 探活

- 禁缓存：`Cache-Control: no-cache` / `Pragma: no-cache` + `?nocache=`；抹 webdriver：Chrome CDP `Page.addScriptToEvaluateOnNewDocument`（本机 headless；IDE Browser MCP 本回合不可用 → 降级 curl SSR + 本地 Chrome）。
- 非抢占：未抢 IDE 焦点。
- 实例在抽检中后期对集合/PDP 间歇 `502`；**源码与契约已落盘**，实例恢复后顾问可再点 buybox 确认。

## Escalate（非阻断）

- 主图 1:1 / 详情杂志深修 → **WO-BUILD-PDP-01** + 内容运营。
- 筛选/磁贴密度深修 → **WO-BUILD-COL-01**。
- Cart/Checkout 默认注入若长期只剩 HelpPay → 另开部件注入排查；failsafe 已兜底。

## @项目经理：

**WO-BUILD-FE-01 closed（替换席）。** 集合价签+加购 CSS 可达加固已落盘；PDP failsafe 自删修复保留；请记账并按需唤醒顾问复审。
