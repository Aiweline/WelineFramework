# contracts · video-carousel（对齐冻结 · 测试主持）

- slug: `video-carousel`
- 冻结时间: 2026-09-21T22:30:00+08:00
- 主持席: 测试
- 权威规格: `app/code/Weline/Theme/doc/开发/spec/video-carousel.md`
- 探查: `meetings/立项-领域探查.md`
- 可执行 UC: `meetings/对齐冻结.md`（UC-1…UC-4）
- 状态: **frozen**（改验收意图须回对齐冻结会；禁止施工私改）

> 对接即验收契约：每条边写清交付物形状、消费方、就绪条件、对应 UC。  
> 已拍板约束（Q1–Q7 / EARS）**勿改验收意图**。

---

## 0. 全局约定

| 项 | 冻结值 |
|---|---|
| 基址 URL（字面） | `https://p05113ef3.test.weline.com:9555/` |
| 槽 | `#homepage-videos` |
| 默认嵌件 | `type=video` + `name=video-carousel`（保留 `video-player` 可独立嵌） |
| 部件根选择器 | `[data-site-block="video-carousel"]` |
| 浮层 | 仅 `Weline.UI.dialog` / `[data-w-component="dialog"]` / `w-dialog` |
| 商品卡 | `<w:product:card>` → 前台选择器优先 `[data-testid="weline-product-card"]` |
| CTA 文案源串 | `查看关联商品`（简中源；i18n 中英 CSV） |
| 禁止 | `default_injections` JSON；新建 Video 模块；原生 `alert`/`confirm`；模板内原生 `fetch` 打业务 Controller |

---

## 1. Widget · ArrayType（项内 product_picker）

| 字段 | 内容 |
|---|---|
| 交付席 | **后端**（Widget 模块轨）± 前端（编辑器 Params 序列化） |
| 消费席 | Theme（ParamSchema 项字段）、主题编辑器 |
| 输入 | 项字段 `type=product_picker`；值协议与顶层 `ProductPickerType` 对齐（逗号分隔 product id） |
| 输出 | `ArrayType::renderItemField`（或等价委托已注册 ParamType）产出可点选的选品 UI；hidden/JSON 数组项正确回填 `product_ids` |
| 就绪条件 | 项内不再退化为纯文本框；主题编辑器数组路径优先消费 ArrayType HTML（fallback 不得再退化） |
| 对应 UC / 测 | 编辑器合同测（非店面主路径）；支撑 UC-3/UC-4 造数 |
| 谁交付谁 | Widget → Theme 编辑器与部件配置面 |

---

## 2. Theme · ParamSchema + 部件模板 + Resolver/CSP

| 字段 | 内容 |
|---|---|
| 交付席 | **主题**（layout/Token/部件落点）+ **后端**（Theme Helper/CSP/ParamSchema PHP） |
| 消费席 | 前端（轮播 JS）、测试、店面访客 |
| 输入 | 探查缺口：`video_carousel_items` schema；`VideoEmbedResolver` + `ThemeVideoEmbedCsp` 扩 bilibili；`widget.php` 登记 `video-carousel` |
| 输出 | |
| · ParamSchema | `app/code/Weline/Theme/Ui/ParamSchema/video_carousel_items.php`（`base_type=array`，`sortable`，项含 `video_type`/`video_url`/`embed_code`/`poster`/`author`/`description|summary`/`product_ids=product_picker`） |
| · 部件 | `widgets/video/video-carousel/default.phtml`：根 `data-site-block="video-carousel"`；项内嵌入走同一 Resolver；空态诚实 |
| · CSP/Resolver | bilibili 信任主机与解析与 sanitize 白名单三处同步 |
| · 登记 | `widget.php` 路径或 template+params；`type=video`，`code=video-carousel` |
| 就绪条件 | `widget:refresh` / schema 扫描后可在编辑器配置；信任嵌入不输出危险脚本 |
| 对应 UC | UC-1、UC-2；契约测 bilibili/CSP |
| 谁交付谁 | Theme 后端/主题 → 前端/测试 |

---

## 3. Theme · homepage 双 layout 默认嵌件

| 字段 | 内容 |
|---|---|
| 交付席 | **主题**（layout） |
| 消费席 | 测试（UC-1）、契约测 |
| 输入 | 两端现 `name="video-player"` Hook else |
| 输出 | |
| · `app/code/Weline/Theme/view/theme/frontend/layouts/homepage/default.phtml` | `accept` 含 `video-carousel`；默认 `<w:widget type="video" name="video-carousel" …>` |
| · `app/design/Weline/hanfu/frontend/layouts/homepage/default.phtml` | 同上，禁止 default/hanfu 漂移 |
| 就绪条件 | 源码字面含 `name="video-carousel"`；`ThemeHanfuHomepageDefaultsContractTest` 等断言已改 |
| 对应 UC | UC-1 |
| 谁交付谁 | 主题 → 测试 |

---

## 4. Product · Catalog 按 ID 取卡

| 字段 | 内容 |
|---|---|
| 交付席 | **后端**（Product / 查询席合规） |
| 消费席 | Theme 部件模板（SSR 预渲染）或前端（若走 BinQuery——须查询席冻结；默认倾向 SSR 进 dialog DOM，技术方案会可微调 **how** 不得砍多卡意图） |
| 输入 | `list<int> product_ids`（尊重配置顺序） |
| 输出 | `StorefrontProductWidgetCatalog::cardsByIds`（或等价公开方法）：内部 `publishedOffersForProductIds` + 既有 `mapOffer` / 评价聚合；**显式 ID 选品勿再强制 HF-* 过滤丢品** |
| 就绪条件 | 给定 ≥2 有效 ID 返回 ≥2 张与 `<w:product:card>` 兼容的 card 数据 |
| 对应 UC | UC-3、UC-4 |
| 谁交付谁 | Product → Theme 部件 |

---

## 5. 前端 · 轮播交互 + dialog 弹层 JS

| 字段 | 内容 |
|---|---|
| 交付席 | **前端** |
| 消费席 | 测试（UC-2/3/4）、UI/原型签收 |
| 输入 | 部件 DOM（多项幻灯、CTA、dialog 壳）；`Weline.UI.dialog` API |
| 输出 | |
| · 轮播 | 可观察「下一项/指示点」；切换后作者或简介或 `data-index` 变化（无强制整页刷新） |
| · CTA | 点击「查看关联商品」→ `Weline.UI.dialog.open`；关闭可断言不可见 |
| · 空关联 | CTA 隐藏 **或** dialog 诚实空态且卡数=0；无 `window.alert` |
| · IO | 若异步取品 → 仅 BinQuery / 既有 Query；禁止原生 `fetch` 直打业务 Controller |
| 就绪条件 | UC-2/3/4 选择器可点、可断言 |
| 对应 UC | UC-2、UC-3、UC-4 |
| 谁交付谁 | 前端 → 测试 / UI / 原型 |

---

## 6. 主题 · Token / 视觉 chrome

| 字段 | 内容 |
|---|---|
| 交付席 | **主题** ± **UI** |
| 消费席 | 原型签收、Browser WB-OP |
| 输入 | 既有 `w-*` / Theme Token；组件协商结论（轮播 chrome：复用既有 carousel 或轻量控件——**须** `components.md` / 协商纪要后再定，禁止单席私造平行浮层） |
| 输出 | 轮播区与 dialog 多卡网格视觉落在主题 Token；无平行 modal CSS 体系 |
| 就绪条件 | WB-OP 禁缓存验收可对照；不挡 UC 选择器 |
| 对应 UC | UC-1…UC-3（视觉签收另见 acceptance-ui） |
| 谁交付谁 | 主题/UI → 原型/测试 |

---

## 7. i18n

| 字段 | 内容 |
|---|---|
| 交付席 | **i18n** + 文案落入 Theme/前端的施工席 |
| 消费席 | 店面、测试抽检 |
| 输入 | 可译字段：区标题、作者、简介、CTA 等（ParamSchema `i18n`）；源串**简中** |
| 输出 | 模块 CSV **中英**；`php bin/w i18n:collect`；本立项未要求全语种时先中英（用户若提「翻译」再默认站全语种） |
| 就绪条件 | 前台简中可见「查看关联商品」；en_US 有非中文照抄译文 |
| 对应 UC | UC-3 CTA 文案；i18n 复审 |
| 谁交付谁 | i18n → 测试/复审 |

---

## 8. 测试 · 合同测 + e2e + Browser

| 字段 | 内容 |
|---|---|
| 交付席 | **测试** |
| 消费席 | 项目经理汇审、验收波 |
| 输入 | 已冻 UC-1…UC-4；可测面就绪信号（deps 上游 closed） |
| 输出 | |
| · 红灯骨架 | 本会后落盘（见 `meetings/对齐冻结.md` §红灯骨架） |
| · 合同/UT | homepage 默认嵌件、`video_carousel_items` schema、ArrayType+picker、Resolver bilibili、CSP |
| · e2e | `video-carousel-plan-suite` 执行 UC-1…UC-4（禁止仅壳层冒烟替代 UC-3） |
| · Browser | WB-OP 首页视频区 + dialog 多卡；`Network.setCacheDisabled` |
| 就绪条件 | 上游交付满足 deps；证据可回查（多卡可见、dialog 开关） |
| 对应 UC | UC-1…UC-4 |
| 谁交付谁 | 测试 → 汇审 |

---

## 9. 席位对接总表（谁交付谁）

```text
Widget(ArrayType+picker)
    → Theme(ParamSchema 项生效) → 编辑器造数
Theme(Resolver/CSP + 部件模板 + widget 登记)
    → 前端(JS) / 测试(UC-1 嵌入可信)
Product(Catalog cardsByIds)
    → Theme 部件(多卡数据) → 前端(dialog 展示)
主题(双 layout 默认嵌件)
    → 测试(UC-1)
前端(轮播+dialog JS)
    → 测试(UC-2/3/4) / UI·原型签收
主题(Token) + UI
    → 原型签收 / WB-OP
i18n(CSV+collect)
    → 测试抽检 / i18n 复审
测试(红灯→执行)
    → 汇审
```

---

## 10. 非本会拍板（留给技术方案 / 组件协商 · 不得砍验收意图）

1. bilibili URL 形态与 CSP 主机精确集合（须与 Resolver/sanitize 同步）。
2. 关联商品 SSR 预渲染 vs 点击后 BinQuery（查询席冻结口；UC-3 仍须多卡可见）。
3. 轮播 chrome 组件选型（原型 ∥ UI ∥ 主题协商 → `components.md`）。
