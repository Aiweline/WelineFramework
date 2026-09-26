# 商城前端功能清单与 PayPal 购物流程测试用例（本地验收）

> 版本：v6 · 生成日期：2026-09-25 · 更新：2026-09-26（场景 C「取消 → 继续支付」全链路跑通 + 3 项代码修复（4 个文件） + runner 反「假通过」加固 + 计数校正）
> 扫描对象：本机 WLS 运行中的店面（长安汉服 / changanhanfu）
> 用途：全量清点商城**前端**功能点，并作为 PayPal 端到端购物流程的验收依据
> **实测结论速览**：场景 A「游客 · PayPal 沙箱 · 全链路下单」**18/18 全通过**（连续 3 轮独立跑）；场景 C「支付取消 → 继续支付」**C1~C7 全通过** —— **两条用户入口各跑一遍**（游客取消落地页 CTA、登录顾客账户订单 CTA），全程真实：真实点 PayPal 取消 → 直读 DB 核验恢复态 → 续付提交 → **真跳 PayPal 网关** → 批准 → 回跳成功页 → 订单 `paid` → **重复回跳幂等**。`fake_card` 冒烟 4/4 通过。合计 **202/247 通过、0 失败、45 未通过**（未通过项为未执行的场景 B/E 及订单生命周期、交互式认证类条目）。详见 §6。
> **v6 计数变更（本轮，2026-09-26）**：只做「未通过 → 通过」且**有直接证据**的上修 —— 场景 C 的 **C6/C7** 由未通过改为通过（流程 **+2**）。功能点维持 **170** 不变（本轮未取得**新增**功能点的直接证据；`PY-11` 幂等虽获得更强的 C7 证据，但此前已计通过，不重复加计）。合计 **200 → 202 通过、47 → 45 未通过**，**失败仍为 0**。详见 §6.1。
> **v4 计数校正（历史）**：v3 曾记「203/247」，其中把 14 条支付类功能点计为通过；v4 逐条复核后**只有 4 条具备直接证据**（`PY-05` / `PY-08` / `PY-11` / `PY-15`），故总数下修为 **193**。此前的差额属**无证据计数**，非新增缺陷。详见 §6.1 脚注。

---

## 0. 测试环境与前置条件

| 项目 | 值 | 来源 |
|---|---|---|
| 站点 | 长安汉服（Chang'an Hanfu · Hanfu Atelier） | 首页 `<title>` |
| 运行时 | WLS `default` 实例，Master PID **63441**（2026-09-26 全量重启后，Workers 4 个 PID 均晚于 Master；历史：2026-09-25 16:04 那次为 34635，重启前为 64244，其下曾挂着 PID 31457/31799 两个**早于 Master 启动**的泄漏 Worker） | `php bin/w server:status` |
| 监听 | `127.0.0.1:9555`（Worker 4，Session 26277，Memory 26278） | 同上 |
| **交付 Host（主链）** | `https://p05113ef3.test.weline.com:9555` | `/etc/hosts` + `.cursor/rules/local-browser-urls.mdc` |
| 活动主题 | `hanfu` | `app/design/Weline/hanfu/`（1451 文件） |
| 数据库 | PostgreSQL `mig_clone_productcurrent20260810_20260810022347_e07e`，前缀 `w_` | `app/etc/env.php` |
| 默认语言 | `zh_Hans_CN` | `env.php` |
| 基础货币 | `CNY` | `w_system_config.base_currency` |
| 支付方式 | `fake_card`（enabled=1, is_default=1）、`paypal`（enabled=1, sandbox） | `w_system_config payment/method/*` |
| PayPal 沙箱 | Client ID `ASmwWnmY…`、Secret 已配置（`app/etc/payment/paypal.platform.sandbox.php`） | 同上 |
| **PayPal 沙箱买家账号** | `sb-4sxrp30216572@personal.example.com` / `weline@…`（**仓库内已有**，非外部依赖） | `Payment/doc/开发/team/payment-shell-compliance-fix/channel/payment-browser-verify.md` |
| **PayPal 端到端 runner** | `app/code/Weline/Checkout/test/e2e/frontend/sandbox-checkout-manual-runner.js` | 本轮已修 3 处缺陷（§6.4） |
| **续付 runner（场景 C，已达标）** | `app/code/Weline/Checkout/test/e2e/frontend/sandbox-continue-pay-real-storefront-runner.js` | 本轮重写为**真实通路**并加**反「假通过」门禁**；`CPAY_MODE=guest\|account` **两条入口均跑到 C7 通过**（§6.2④ / §6.4 缺陷 4） |
| **代码改动生效方式** | 必须**全量** `server:stop` + `server:start`；滚动 reload 会失败并泄漏 Worker | §6.5 / R10 |
| E2E 基建 | Playwright，代理入口 `https://localhost:3999`，94 个 frontend spec | `tests/e2e/` |

### 关键前置动作

1. **本机验收必须走交付 Host**，禁止把 `*.weline.test` 当主验收 Host。
2. **WLS 攻击防护**：`curl` 直连会被 `server.request.attack_guard` 拦成 403，必须带浏览器 UA / Accept 头；浏览器访问无此问题。
3. **验收浏览器需禁缓存 + 抹自动化标志**（`navigator.webdriver`），否则支付/验证码链路结果不可信。
4. 支付成功链路依赖 `payment/method/paypal/return_url` 指向当前 Host —— 当前值为 `https://p05113ef3.weline.test:9555/payment/frontend/callback…`，**与交付 Host 不一致**，属于待确认项（见 §5 风险）。

---

## 1. 前端功能地图（信息架构）

### 1.1 页面清单（已实测 HTTP 状态）

| 分区 | 路径 | 状态 |
|---|---|---|
| 首页 | `/` | 200 |
| 全量商品 | `/products` | 200 |
| 分类 | `/category/{slug}`（women / men / kids / sets / accessories / hanfu + 二级） | 200 |
| 商品详情 | `/product/{slug}`、`/product/{id}` | 200（id → 301 跳 slug） |
| 搜索 | `/search` | 200 |
| 热销 / 新品 | `/best-sellers`、`/new-arrivals` | 200 |
| 购物车 | `/cart` | 200 |
| 结算 | `/checkout` | 200 |
| 对比 | `/compare` | 200 |
| 促销 | `/promotion/deals`、`/promotion/{slug}`（如 weekend） | 200 |
| 账户 | `/customer/account/{login,register,index,forgot-password,set-password,social-login}` | 200（`index` 游客 302 → 登录） |
| 联系 | `/contact` | 200 |
| 询盘 | `/inquiry/suppliers` | 200 |
| 联盟 | `/affiliate` | 302 |
| 订阅 | `/newsletter/subscribe` | 302 |
| 内容 | `/about`、`/faq`、`/blog`、`/blog/{slug}`、`/blog/category/{slug}` | 200 |
| 政策 | `/policy/{privacy,cookie,accessibility,disclaimer,refund,shipping,term-condition}`、`/terms` | 200（`/privacy` → 301） |
| 指南 | `/guide/{payment,shipping,returns,social-login}` | 200 |
| SEO / 机器可读 | `/sitemap.xml`、`/llms.txt`、`/geo-feed.xml` | 200 |
| 多语言 | `/{locale}`（zh_Hans_CN、en_GB、de_DE、fr_FR、ja… 共 30+ 语言入口） | 200 |

### 1.2 交互式功能模块（前端 JS 模块）

| 模块 | JS 能力 |
|---|---|
| `Weline_Cart` | 加购 / 改数量 / 移除 / 游客续期 / 优惠券事件 / `remove_from_cart` 像素 / PDP 悬浮代理加购条 |
| `Weline_Product` | 相关商品、推荐商品、猜你喜欢、经常一起购买(FBT)、PDP 杂志楼层滚轮入场、变体图切换、列表分页窗口 |
| `Weline_Checkout` | 结账生命周期事件、快捷支付回头确认页（摘要/缺口/确认收款） |
| `Weline_Payment` | 支付生命周期事件、PDP 快捷智能支付、PayPal JS SDK（Google Pay / Apple Pay funding 按钮） |
| `Weline_Customer` | 顶栏账户 chrome、登录面板、找回密码、用户中心、退出、社媒快捷登录 |
| `Weline_Wishlist` | 心愿单列表页、顶栏收藏角标水合 |
| `Weline_Compare` | 对比页、商品卡对比/快速查看/对比栏 |
| `Weline_Review` | 商品/博客通用评论部件（含分页） |
| `Weline_Shipping` | 结账收货地址、账户地址维护（v3）、运费计算器、地区联动 |
| `Weline_Marketing` | 结账/迷你购物车优惠券部件 |
| `Weline_Newsletter` | 邮件订阅表单（BinQuery / 弹窗 cookie） |
| `Weline_RecentlyViewed` | 最近浏览轮播/网格 |
| `Weline_Currency` | 货币切换器 |
| `Weline_Order` | 迷你购物车订单留言 |

---

## 2. 功能点细则（按模块）

> 每条 = 一个可独立验证的功能点。括号内为对应实现锚点。

### 2.1 全局框架 / 顶栏 Header（`hanfu/frontend/partials/header/storefront-shell.phtml`）

| # | 功能点 | 验收要点 |
|---|---|---|
| H-01 | 品牌 Logo / 文字字标 | 有配置用配置素材，无配置回落批准字标 |
| H-02 | 主导航菜单 | 支持多级 children、hover 展开（`data-w-open-on="hover"`） |
| H-03 | 分类侧边栏手风琴树 | `data-sidebar-category-tree="1"` / `data-sidebar-accordion="categories"` |
| H-04 | 头部搜索框 | `data-w-header-search-loader="true"` → 加载 `headerSearch` 模块，含热搜词 |
| H-05 | 账户菜单（游客态） | 显示登录/注册入口 |
| H-06 | 账户菜单（登录态） | `data-account-menu-auth="signed-in"`，含「我的订单」「设置」「退出」 |
| H-07 | 心愿单图标 + 角标 | `wishlistHeader` 水合，游客空角标 |
| H-08 | 购物车图标 + 角标 | `minicarticon` + `minicartextras` |
| H-09 | 对比入口 | 跳 `/compare`，`compareShopper` 维护对比栏 |
| H-10 | 语言切换器 | `data-locale-tool="language"`，30+ 语言 |
| H-11 | 货币切换器 | `data-locale-tool="currency"`，`currency` 模块 |
| H-12 | 移动端头部 | `data-header-mobile="amazon"` 抽屉式 |
| H-13 | 跳转到主内容（无障碍） | `href="#main-content"` |
| H-14 | 公告/促销横幅 | `show_banner_with_children` 控制 |
| H-15 | 客服入口 | `data-weline-customer-service` |

### 2.2 首页 Homepage（`hanfu/frontend/layouts/homepage/default.phtml`）

| # | 功能点 | 验收要点 |
|---|---|---|
| HP-01 | 主视觉 Banner | 轮播/静态，含 CTA |
| HP-02 | 分类入口网格 | 链接到 `/category/*` |
| HP-03 | 商品陈列（新品/热销/推荐） | 复用统一商品卡 |
| HP-04 | 商品卡交互 | 加购 / 收藏 / 对比 / 快速查看 |
| HP-05 | 博客/内容引流块 | 链接 `/blog/*` |
| HP-06 | 邮件订阅块 | `newslettersubscribe` |
| HP-07 | 店铺音乐 | `data-testid="store-music-*"`（播放/暂停/播放列表/音量/波形） |
| HP-08 | 图片兜底 | `storefrontimagefallback` |
| HP-09 | 访客追踪像素 | `data-weline-pixel-bootstrap` / `pixel-eager` |

### 2.3 商品目录 `/products`（`Product/Controller/Frontend/Catalog.php`）

| # | 功能点 | 验收要点 |
|---|---|---|
| CT-01 | 商品网格/列表 | 分页窗口（`storefront-listing-pager-window`） |
| CT-02 | 排序 | 价格/上新/热销 |
| CT-03 | 筛选（属性/EAV） | 多选、URL 可回放（`storefront-batch-url-http`） |
| CT-04 | 分页 | 上一页/下一页/页码，滚动保持 |
| CT-05 | 空结果态 | 友好提示 + 清筛选 |
| CT-06 | 商品卡三件套 | 加购、收藏、对比 |

### 2.4 分类页 `/category/{slug}`（`Product/Controller/Frontend/Category.php`）

| # | 功能点 | 验收要点 |
|---|---|---|
| CG-01 | 分类头部（名称/描述/图） | 主题模板 `category/index.phtml` |
| CG-02 | 子分类导航 | 二级分类可达 |
| CG-03 | 面包屑 | 与 URL 层级一致 |
| CG-04 | 同 CT-01~06 的列表能力 | — |

### 2.5 商品详情 PDP `/product/{slug}`（`Product/Controller/Frontend/Detail.php`）

实测该页功能标记密度最高（reviews 139 / gallery 78 / express 64 / compare 40 / wishlist 29 / buy-now 28 / paypal 17）。

| # | 功能点 | 锚点 |
|---|---|---|
| PD-01 | 图片画廊（主图+缩略图） | `gallery` |
| PD-02 | 变体轴选择（颜色/尺码…） | `data-variant-axis` / `data-variant-value` / `data-variant-label` |
| PD-03 | 变体交互式切换 | `data-variant-interactive` |
| PD-04 | 变体 URL 联动（可分享） | `data-variant-live-url` |
| PD-05 | 变体图随选切换 | `hanfu-variant-image-switch` |
| PD-06 | 变体可用性实时校验 | `/weline_product/frontend/api/variant-availability` |
| PD-07 | 数量选择器 | `data-qty` |
| PD-08 | 加入购物车 | `add-to-cart` / 采购面板 `/weline_product/frontend/api/purchase-panel` |
| PD-09 | 立即购买（Buy Now） | `buy-now` → 直达结算 |
| PD-10 | 悬浮加购条 | `productstickypurchase`（主加购滚出视野后出现） |
| PD-11 | 收藏（心愿单） | `wishlist` |
| PD-12 | 加入对比 | `compare` |
| PD-13 | 快捷智能支付（Express） | `productexpresspay` + `product-express-pay.js` |
| PD-14 | PayPal 钱包按钮（Google/Apple Pay funding） | `paypal-wallet-buttons.js` |
| PD-15 | 商品评价（列表+分页+提交） | `productreviews` |
| PD-16 | 相关商品 | `relatedproducts` |
| PD-17 | 推荐商品 | `recommendedproducts` |
| PD-18 | 猜你喜欢 | `youmaylike` |
| PD-19 | 经常一起购买 FBT | `crossSell` |
| PD-20 | 最近浏览 | `recentlyviewed` |
| PD-21 | 杂志楼层滚轮入场动效 | `productdetailreveal` |
| PD-22 | 报价请求（B2B/询价） | `/weline_product/frontend/api/quote-request` |
| PD-23 | 帮我付 / 选品分享 | `helppayshare` |
| PD-24 | 联盟分享 | `affiliateproductshare` |
| PD-25 | 订阅（到货/降价通知） | `newslettersubscribe` |
| PD-26 | 数字商品下载入口 | `/product-download/{uuid}` |
| PD-27 | SEO 结构化数据 | `data-weline-seo-inspector` |
| PD-28 | 面包屑 | — |

### 2.6 搜索 `/search`（`Search/Controller/Frontend/Index.php`）

| # | 功能点 | 验收要点 |
|---|---|---|
| SE-01 | 关键词搜索 | 结果页 + 命中高亮 |
| SE-02 | 搜索建议/自动补全 | 头部搜索模块 |
| SE-03 | 热搜词 | `HeaderCommerceData::resolveHotWords` |
| SE-04 | 筛选 + 排序 + 分页 | 同目录页 |
| SE-05 | 无结果降级 | `plan-p3c03-degraded-search` |
| SE-06 | 搜索慢查询日志（后台侧） | 非前端 |

### 2.7 购物车 `/cart` + 迷你购物车（`Cart/`）

实测：`data-cart-state="empty|loading|ready|error"`、`data-qty-increase/decrease`、`data-cart-checkout`、`weline:cart-updated`、`weline:cart-type-changed`。

| # | 功能点 | 验收要点 |
|---|---|---|
| CA-01 | 购物车列表 | 图/名/规格/单价 |
| CA-02 | 数量增减 / 手输 | `data-qty-increase` / `data-qty-decrease` |
| CA-03 | 移除商品 | 像素 `remove_from_cart` |
| CA-04 | 小计 / 合计实时更新 | `data-cart-goods-subtotal` / `data-cart-grand-total` |
| CA-05 | 优惠券输入 | 购物车优惠券部件 |
| CA-06 | 优惠明细展示 | `data-cart-discount-breakdown` / `discount-lines` |
| CA-07 | 订单留言 | `data-cart-summary-extras` |
| CA-08 | 帮我付 | 购物车帮我付 |
| CA-09 | B2B 批发信用 | 购物车批发信用 |
| CA-10 | 购物车类型切换（零售/批发 TOC） | `data-cart-type` / `weline:cart-type-changed` |
| CA-11 | 去结算 | `data-cart-checkout` |
| CA-12 | 空车态 | `data-cart-page-state="empty"` + 去逛逛 |
| CA-13 | 加载 / 错误 / 重试 | `loading` / `error` / `data-cart-retry` |
| CA-14 | 迷你购物车（头部悬浮） | `mini-cart/default.phtml`，含订单留言 |
| CA-15 | 游客登录后合并购物车 | `plan-p2e02-guest-login-merge` |
| CA-16 | 跨标签同步 | storage 事件 |

### 2.8 结算 `/checkout`（真实控制器 **`Checkout/Controller/Index.php`**，SSR-slim；见 §6.7）

实测视图状态机：`loading | empty | ready | mismatch | recovery | continue_pay`。

| # | 功能点 | 验收要点 |
|---|---|---|
| CK-01 | 结算页视图状态机 | 6 态切换正确 |
| CK-02 | 收货地址（新增/选择/编辑） | `#checkout-shipping-address` + `checkout-shipping-address.js` |
| CK-03 | 地址不完整提示 | `data-address-incomplete="1"` |
| CK-04 | 配送方式选择 | `data-checkout-method-empty="shipping_method"` |
| CK-05 | 运费计算 | `/shipping/frontend/shipping-service/calculate-fee` |
| CK-06 | 支付方式选择 | `data-checkout-method-empty="payment_method"` |
| CK-07 | 快捷支付（Express） | `#checkout-express-payment` + `weline:checkout:express-pay` |
| CK-08 | 优惠券 | 结账优惠券部件 |
| CK-09 | 订单留言 | `#checkout-summary-note` |
| CK-10 | 金额明细 | subtotal / discount / tax / shipping / grand-total / deposit / credit / cod-fee / incentive |
| CK-11 | 税金身份 | `#checkout-tax-identity` |
| CK-12 | 帮我付 | `#checkout-summary-help-pay` |
| CK-13 | 提交订单 | `/checkout/create-order` → `weline:checkout:order-created` |
| CK-14 | 发起支付 | `/checkout/process-payment` |
| CK-15 | 支付恢复（失败后重试） | `data-payment-retry` / `data-payment-recovery-*` |
| CK-16 | 继续支付 | `data-checkout-view="continue_pay"` |
| CK-17 | 结算类型切换（零售/批发） | `data-testid="checkout-type-switch"` |
| CK-18 | 多仓拆单 | `checkout-multi-warehouse-split` |
| CK-19 | 地址/货币变更后重新报价 | `Weline_Checkout-currency-address-quote` |
| CK-20 | 已登录用户地址自动带出 | — |

### 2.9 支付 Payment（PayPal Sandbox）

Provider：`PayPalProvider`（code `paypal`，API v2.0）、`FakeProvider`（`fake_card`，默认）、`StripeProvider`（未启用）。

| # | 功能点 | 验收要点 |
|---|---|---|
| PY-01 | 支付方式列表展示 | 结账页 `data-payment-methods`，图标/名称/介绍 |
| PY-02 | 支付方式介绍展开 | `data-payment-intro-toggle` |
| PY-03 | PayPal 下单（create order） | `/payment/frontend/checkout/create` |
| PY-04 | PayPal 授权跳转 | 跳 sandbox.paypal.com 审批 |
| PY-05 | PayPal 回跳 | `/payment/frontend/callback/browser-return-entry` |
| PY-06 | PayPal 取消回跳 | `/payment/frontend/callback/browser-cancel-entry` |
| PY-07 | PayPal 服务端通知（Webhook） | `/payment/frontend/callback/notify` + `verifyCallback` |
| PY-08 | 支付成功后副作用 | `PaymentEffectOutboxProcessor`（发票/履约/库存） |
| PY-09 | 支付失败态 | `checkout/failure` |
| PY-10 | 支付成功态 | `checkout/success` |
| PY-11 | 幂等（重复回调不重复扣/不重复建单） | 幂等键 |
| PY-12 | fake_card 支付（本地快速通道） | `/payment/frontend/checkout/fake` |
| PY-13 | 支付激励/立减 | `data-payment-incentive-amount` |
| PY-14 | 退款 | `PaymentRefundFacade`（后台触发，前端看订单退款态） |
| PY-15 | CSP 允许 PayPal 域 | `cspDirectives()` script/frame-src |

### 2.10 订单与支付结果页（`Checkout/Controller/Frontend/Order.php`）

| # | 功能点 | 验收要点 |
|---|---|---|
| OR-01 | 下单成功页 | 订单号、金额、下一步指引 |
| OR-02 | 支付失败页 | 失败原因 + 重试入口 |
| OR-03 | 游客下单后转注册 | `widget-checkout-success-guest-convert-0` |
| OR-04 | 订单详情（账户内） | `/checkout/frontend/order/view` |
| OR-05 | 订单列表（账户内） | `/checkout/frontend/order/list` |
| OR-06 | 取消订单 | `/checkout/frontend/order/cancel` |
| OR-07 | 继续支付（订单内） | `account-continue-pay` |
| OR-08 | 物流跟踪查询 | `/shipping/frontend/tracking/query` |
| OR-09 | 退款进度（顾客侧） | `plan-refund02-pending-customer-view` |

### 2.11 客户账户 `/customer/account/*`

**认证类**

| # | 功能点 | 路径 |
|---|---|---|
| AC-01 | 登录（邮箱/密码） | `customer/account/login` |
| AC-02 | 注册 | `customer/account/register` |
| AC-03 | 忘记密码 → 重置邮件 | `customer/account/forgot-password` |
| AC-04 | 设置/重置密码 | `customer/account/set-password` |
| AC-05 | 退出登录 | `customer/account/logout` |
| AC-06 | 人机验证挑战 | `customer/account/challenge` |
| AC-07 | 社媒登录（Google/Facebook） | `customer/account/social-login/{start,callback,choose}` |
| AC-08 | 社媒快速登录（顶栏浮条） | `account-social-quick.js` |
| AC-09 | 社媒绑定/解绑已有账号 | `social-login/{bind-existing,create-new,unbind}` |
| AC-10 | 两步验证（2FA） | `two-factor-auth/frontend/{setup,verify,accounts}` |
| AC-11 | 登录会话管理 | 账户侧边栏 `SessionManager` |

**用户中心 `/customer/account/index`（侧边栏分组）**

| # | 分组 | 功能点 |
|---|---|---|
| AC-12 | 个人资料 | 账户信息查看/更新、头像 |
| AC-13 | 订单（commerce） | 订单列表/详情/继续支付/取消 |
| AC-14 | 心愿单（commerce） | 收藏列表管理 |
| AC-15 | 订阅（commerce） | 订阅管理 |
| AC-16 | 联盟（commerce） | 推广/佣金 |
| AC-17 | 企业身份（commerce） | B2B 身份申请/状态 |
| AC-18 | 资产（commerce） | 储值余额 / 积分 / WCoin 明细 |
| AC-19 | 下载（commerce） | 数字商品下载 |
| AC-20 | 地址（addresses） | 收货/发货地址簿增删改 |
| AC-21 | 连接（connections） | 邮箱账户、Multipass |
| AC-22 | 安全（security） | 登录会话、两步验证 |
| AC-23 | 侧边栏内容懒加载 | `get-sidebar-content` |

### 2.12 心愿单 / 对比

| # | 功能点 | 验收要点 |
|---|---|---|
| WL-01 | 商品卡收藏按钮（游客 Cookie） | `wishlist` 模块 |
| WL-02 | 顶栏收藏角标水合 | `wishlistHeader` |
| WL-03 | 心愿单列表页 | `wishlist/index.phtml` |
| WL-04 | 从心愿单加购/移除 | — |
| CP-01 | 商品卡「加入对比」 | `compareShopper` |
| CP-02 | 对比栏（底部悬浮） | 已选列表 |
| CP-03 | 对比页 `/compare` | 属性并排对比、移除、加购 |
| CP-04 | 快速查看（Quick View） | 商品卡动作 |

### 2.13 评价 Review

| # | 功能点 | 验收要点 |
|---|---|---|
| RV-01 | 商品评价列表 + 分页 | `productreviews` |
| RV-02 | 评分星级汇总 | — |
| RV-03 | 提交评价（含图片） | 登录后 |
| RV-04 | 博客评论（同一部件复用） | `Weline_Review` |

### 2.14 促销 / 优惠券

| # | 功能点 | 验收要点 |
|---|---|---|
| PR-01 | 促销落地页 `/promotion/{slug}` | `promotion/page` |
| PR-02 | 特价场 `/promotion/deals` | — |
| PR-03 | 主题化促销页 | `Promotion/Controller/Sale.php` |
| PR-04 | 优惠券应用（购物车/结账） | `checkout-coupon.js` |
| PR-05 | 折扣行展示 | `data-cart-discount-lines` |
| PR-06 | 购物车进度条（满减提示） | `Weline_Marketing-cart-progress` |
| PR-07 | 营销活动页 | `/marketing/frontend/campaigns` |

### 2.15 配送 / 地址

| # | 功能点 | 验收要点 |
|---|---|---|
| SH-01 | 结账收货地址部件 | `checkout-shipping-address.js` |
| SH-02 | 账户地址簿（v3） | `account-address-v3.js` |
| SH-03 | 国家/省/市/区联动 | `shipping-location.js` + `/shipping/frontend/region` |
| SH-04 | 运费计算 | `shipping-price-calculator.js` |
| SH-05 | 配送方式/时效 | `/shipping/frontend/delivery` |
| SH-06 | 物流跟踪 | `/shipping/frontend/tracking` |
| SH-07 | 地址用途标签（收货/发货） | `delivery-address-purpose-tags` |
| SH-08 | 禁运规则提示 | 后台 `SystemEmbargo` 前端表现 |

### 2.16 内容页

| # | 功能点 | 路径 |
|---|---|---|
| CN-01 | 关于我们 | `/about` |
| CN-02 | FAQ 中心 | `/faq` + `/faq/view` |
| CN-03 | 博客列表 | `/blog` |
| CN-04 | 博客详情 | `/blog/{slug}` |
| CN-05 | 博客分类 | `/blog/category/{slug}` |
| CN-06 | 博客 RSS | `/blog/frontend/rss` |
| CN-07 | CMS 单页 | `/cms/frontend/page` |
| CN-08 | 政策页（7 类） | `/policy/*` |
| CN-09 | 指南页（支付/配送/退货/社媒登录） | `/guide/*` |
| CN-10 | 联系我们（表单） | `/contact` |
| CN-11 | 询盘/供应商 | `/inquiry/suppliers` |
| CN-12 | 联盟推广页 | `/affiliate` |
| CN-13 | 邮件订阅 | `/newsletter/subscribe` |
| CN-14 | 站点地图（HTML + XML） | `/sitemap.xml` |

### 2.17 合规与辅助

| # | 功能点 | 验收要点 |
|---|---|---|
| CM-01 | Cookie 同意横幅 | `data-weline-consent-banner` + `consent/{accept,status,withdraw}` |
| CM-02 | 人机验证（Captcha） | `captcha/frontend/{challenge,verify}` |
| CM-03 | 在线客服（聊天 + 绑定验证） | `customerservice/frontend/{chat,bind}` |
| CM-04 | 店铺音乐播放器 | `store-music-*` |
| CM-05 | 图片懒加载 + 兜底 | `storefrontimagefallback` |
| CM-06 | 访客行为追踪像素 | `visitor/tracking/*` |
| CM-07 | 多语言切换 | 30+ locale |
| CM-08 | 多货币切换 | `currency` |
| CM-09 | 维护模式页 | `maintenance/frontend/dev-preview` |
| CM-10 | 404 页 | `hanfu/frontend/layouts/not_found/default.phtml` |
| CM-11 | SEO meta / 结构化数据 | `data-weline-seo-inspector`、`seo/protocol/{robots,sitemap}` |
| CM-12 | AI 前台（若启用） | `ai/frontend/{index,chat,center}` |

---

## 3. 端到端购物流程测试用例（PayPal）

### 场景 A：游客 · PayPal 沙箱 · 全链路下单（主用例）

| 步 | 操作 | 预期 |
|---|---|---|
| A1 | 打开 `https://p05113ef3.test.weline.com:9555/` | 200，首页完整渲染，顶栏游客态 |
| A2 | 进入 `/products`，选一个商品 | 列表正常，商品卡完整 |
| A3 | 打开 PDP，选变体（颜色/尺码），设数量 | 变体可用性校验通过，价格/库存刷新 |
| A4 | 点「加入购物车」 | 顶栏角标 +1，出现加购成功提示；`weline:cart:updated` |
| A5 | 打开 `/cart` | 商品行、数量、小计、合计正确 |
| A6 | 改数量 → 移除 → 再加回 | 金额实时更新，无脏数据 |
| A7 | 点「去结算」 | 进入 `/checkout`，视图态 `ready` |
| A8 | 填收货地址（国家/省/市/区/详细地址） | 地址保存成功，`data-address-incomplete` 消失 |
| A9 | 选配送方式 | 运费出现在明细，`grand-total` 更新 |
| A10 | 选支付方式 = PayPal | 出现 PayPal 按钮/跳转入口 |
| A11 | 提交订单 | `/checkout/create-order` 成功，生成订单号 |
| A12 | 发起支付 | `/checkout/process-payment` → 跳 PayPal sandbox 审批页 |
| A13 | 用 PayPal 沙箱买家账号登录并批准 | 审批成功 |
| A14 | 回跳 `browser-return-entry` | 回到站点，进入成功页 |
| A15 | 校验成功页 | 订单号、金额、订单状态 = 已支付 |
| A16 | 校验后台/DB | `w_order` 有单、`w_payment_*` 有交易与回调、金额守恒 |
| A17 | 校验副作用 | 库存扣减、发票/履约 outbox 已消费 |
| A18 | 重复回调 | 幂等：不重复扣款、不重复建单 |

### 场景 B：登录用户 · PayPal

B1 注册 → B2 邮箱验证/设密码 → B3 登录 → B4 复用 A2~A15 → B5 在 `/customer/account/index` 看到订单 → B6 订单详情/继续支付入口正常。

### 场景 C：支付取消 → 继续支付

C1 走到 A12 → C2 在 PayPal 页点取消 → C3 回跳 `browser-cancel-entry` → C4 落 `checkout/failure` 或订单「待支付」→ C5 在账户订单点「继续支付」→ C6 成功支付 → C7 订单状态正确流转。

> **本轮已实测的真实通路（2026-09-26）**：
> - 取消入口：PayPal 审批页真实的 **「Cancel and return to Test Store」**（未登录为 `a#cancelLink` 带 href；**登录后同文案但 `href="#"`**、class `CancelLink_cancelLink_*`，必须按文案/类名点，不能只认 `#cancelLink`）。
> - 回跳落地：`/payment/frontend/callback/{method_code}?outcome=cancel` → `Callback::browserReturnEntry()` → `dispatchCancel()` → `PaymentBrowserCancelDispatcher` → `PaymentBrowserReturnLandingOrchestrator::decideCancel()`，最终落在
>   `/checkout/success?source=payment_return&…&outcome=cancel&cancel_state=done&transaction_no=PAY…`（标题 `Payment cancelled`，`[data-payment-outcome="cancel"]`）。
> - **C5 有两个用户可见入口，本轮两条都跑通了**：① 取消落地页上的 `[data-continue-pay]` CTA；② `/customer/account/index#orders` 账户订单里的 `[data-testid="account-order-continue-pay"]`。两者 href 都带完整 `#payment-recovery?quote_token=…&idempotency_key=…&payment_method=…&order_uuid=…&outcome=failed&recoverable=1` 契约，点击后进入 `data-checkout-view="continue_pay"` 并渲染续付 chrome。
> - **C6/C7 已跑通（本轮，2026-09-26）**：上一轮此处被一个**真实缺陷**挡住（`continue_pay` 视图与普通 `/checkout` 都**没有渲染收货地址部件**，`checkout-shipping-address` 槽为空 → `formAddress()` 全空 → `submitCheckoutPayment()` **静默 `return {cancelled:true, reason:'shipping_fields'}`**，不跳转、不报错、不落消息）。**该缺陷本轮已定位并修复**（根因见 §6.7 / R12），修复后两条入口均走到 C7：
>   - C6：续付视图**显式选中真实网关** `paypal`（反「假通过」门禁，见 §6.4 缺陷 5）→ 提交 → 经 `outcome=pending` 恢复态点 `[data-payment-retry]` 真实 CTA → **浏览器 URL host 变为 `www.sandbox.paypal.com`**（不再被 `redirect_url` 的 URL 编码串误判）→ 沙箱买家批准。
>   - C7：回跳 `/checkout/success?source=payment_return&…&transaction_no=PAY…` → 直读 DB 核验 `paid/paid`、`transaction_count=2`、`success_transaction_count=1`（首次 `failed` + 续付 `success`），**重复回跳计数零变化**（幂等成立）。

### 场景 D：fake_card 快速通道（本地冒烟）

D1 同 A1~A10，支付方式选 `fake_card` → D2 提交 → D3 立即成功（无外部跳转）→ D4 校验订单与副作用。

### 场景 E：边界与异常

| 编号 | 用例 | 预期 |
|---|---|---|
| E1 | 空购物车访问 `/checkout` | 引导回购物车，不报错 |
| E2 | 未选配送/支付方式直接提交 | 校验拦截 + 明确提示 |
| E3 | 地址不完整提交 | 拦截 + 定位到缺失字段 |
| E4 | 商品下架/无库存时结算 | 明确提示，不产生脏单 |
| E5 | 优惠券无效/过期 | 明确错误，金额不异常 |
| E6 | 游客加购 → 登录 | 购物车合并，不丢商品 |
| E7 | 多标签同时改购物车 | 状态同步 |
| E8 | 断网/接口 5xx 时加购 | 降级提示 + 重试 |
| E9 | 支付成功但回跳失败 | 以 Webhook 为准，订单仍为已支付 |
| E10 | 金额小数/多货币 | 按货币精度展示，无浮点误差 |

---

## 4. 已有自动化覆盖（可直接复跑，避免重复造轮子）

`tests/e2e/` 已有 94 个 frontend spec，与本清单直接相关的：

| 领域 | 现有 spec |
|---|---|
| 购物车 | `Cart/plan-p2e01-03-cart-boundaries`、`plan-p2e02-guest-login-merge`、`plan-p2a06-price-cleared` |
| 结算 | `Checkout/Weline_Checkout-r43-storefront`、`checkout-continue-pay-real-pathway`、`checkout-success-actions`、`checkout-payment-cancel-continue-pay-plan-suite`、`checkout-multi-warehouse-split`、`Weline_Checkout-currency-address-quote`、`Weline_Checkout-express-review` |
| 商品 | `Product/sticky-purchase-dock`、`hanfu-variant-image-switch`、`best-sellers-unified-card`、`storefront-listing-pager-window`、`storefront-batch-url-http`、`plan-p2c-render-scene` |
| 账户 | `Customer/Weline_Customer-anonymous-ssr-interactive-auth`、`plan-account-layout`、`Order/account-continue-pay`、`plan-browser02-orders` |
| 支付 | `Payment/paypal-google-apple-pay-plan-suite`（后台）、`B2B/hang-balance-payment-panel` |
| 其他 | `HelpPay/*`、`Marketing/cart-progress`、`Newsletter/*`、`Shipping/*`、`Search/plan-p3c03-degraded-search`、`Promotion/*`、`Faq/*`、`Consent/Weline_Consent-accept` |

运行方式（见 `tests/e2e/README.md`）：`cd tests/e2e && node collect-tests.js`，Playwright 经代理 `https://localhost:3999` 访问真实运行时。

**缺口**：`Payment` 模块**没有 frontend 的 PayPal 端到端 spec**（现有为 backend）。场景 A/B/C 目前无自动化覆盖，属本次重点补齐对象。

---

## 5. 风险与待确认项

| 编号 | 风险 | 影响 | 建议 |
|---|---|---|---|
| R1 | `payment/method/paypal/return_url` 指向 `p05113ef3.weline.test`，而交付 Host 为 `p05113ef3.test.weline.com` | 回跳 Host 不一致，可能导致回调落错 Host / 会话丢失 | 将 return/cancel URL 对齐交付 Host 后再跑场景 A · **实测：去程与回程均正常** —— 跳转 URL 由 PayPal API 返回，回程落在 `/payment/handoff/?checkout_session_code=pcs-…` 再转 `/checkout/success`，**未依赖 `return_url` 的 host**。仍建议对齐以免日后切到 live 时踩坑。 |
| R2 | `w_weline_payment_method_config` 表为空，但 `w_system_config` 有支付配置 | 两套配置源并存，可能读到空配置 | 确认运行时以哪套为准 · **实测：运行时以 `w_system_config` 为准，`fake_card`/`paypal` 均可正常下单，无读空现象。** |
| R3 | PayPal 沙箱 OAuth token 连接于 2026-08-30，距今近 1 个月 | token 可能已过期，需 refresh | 先跑后台「测试连接」确认凭据有效 · **实测：凭据有效** —— 连续 2 轮完整 PayPal 下单（approve token 正常签发、订单转为 `paid`）。 |
| R4 | 支付沙箱需 PayPal 买家账号（sandbox buyer） | 无账号无法完成 A13 | 需用户提供/确认沙箱买家账号 · **已解决：账号就在仓库内**（`payment-browser-verify.md`）。**场景 A 已 18/18 全通过**。 |
| R5 | WLS 攻击防护会拦非浏览器请求 | 脚本化验证需带浏览器头 | 已确认；自动化走 Playwright 不受影响 |
| R6 | 前端 PayPal 流程无自动化 spec | 回归无保障 | 现有 `sandbox-checkout-manual-runner.js`（手动 runner，已修好）与 `sandbox-continue-pay-real-storefront-runner.js`（**本轮已跑到场景 C 的 C1~C7 全通过**）。**建议**固化为正式 spec，纳入回归（§6.6） |
| R7 | **【已修复】** 已发布店面**业务插槽全空**（购物车/结算区渲染为空，`fill()` 从不执行） | 首屏 200 但结算页无内容，任何下单链路都走不通 | **根因**：`ThemeLayoutEntitySlotFiller::rememberPublishedPageEntityLocation()` 在共享缓存（L2 `weline_theme_layout_entity_published_projection`，TTL 3600s）回读时，`ThemeVersionIdentity` / `EntityRenderBinding` 对象被降级为 **camelCase 公开属性数组**（非 `toArray()` 的 snake_case），`instanceof` 判空 → `$out` 缺 `binding` → `is_file('')` 为假 → `return null` → 触发零运行时填充门禁 `SlotBoundaryMarkers::strip()` 清空插槽。**故"缓存未命中可渲染、命中必空"**。 | **已修复并全量重启验证**：回读时归一化 camelCase/snake_case 两种形态并重建 `ThemeVersionIdentity`。验证见 §6.2①。 |
| R8 | **【已修复·已验证】** `w_weline_checkout_session` 出现 `state=submitted + error_code=freeze_failed + 「购物车为空，无法结账」` | 一度被判定为「结账失败 / 二次冻结」 | **结论：不是结账失败，是事后脏写。** 5/5 条 `freeze_failed` 会话都能按 `idempotency_key` 对上一条**更早创建**的 `w_weline_checkout_group` —— 订单每次都真的建成了。机制：`submitV2` 已把 session 置 `submitted` 并消费购物车 → 事后一个带同 `quote_token` 的 `freezeQuote` 到达 → 购物车确已空 → 抛「购物车为空，无法结账」→ `recordNamedFault()` 无条件写回该 session。**已修**：`CheckoutSessionFaultRecorder::recordCode()` 对 `submitted` 会话不再覆写错误快照。**已全量重启后验证**：跑完一轮完整 PayPal 下单，`submitted+freeze_failed` 计数 6 → **6（零新增）**，而 order/txn/intent/group/reservation 各 **+1**。详见 §6.3。 |
| R9 | **【已修】** 网关异常被静默吞掉：PayPal 失败只回笼统 `checkout_payment_failed`，无任何日志 | 事后无法定位真实原因 | `CheckoutQueryProvider::payCreatedOrders()` 的 `catch (\Throwable)` 原样返回、不落日志。**已修**：现用 `w_log('error', …)` 记录 `method / orders / 异常类 / message / file:line`，日志失败不影响原异常路径。 |
| R10 | **【运维发现·根因已修正】** 长跑 Worker 会让 PayPal 支付静默失败（`checkout_payment_failed`，订单已建但无跳转）；且**代码改动不重启就不生效** | 支付链路间歇不可用，且表现为「无错误信息的失败」；改了类却不生效会误导排查 | 全量重启 WLS **前**连续 2 次失败、**后**连续 2 次成功；同一订单用 CLI 直调 `pay()` 可正常拿 `redirect_url` → 问题在运行中的 worker 进程状态。**⭐ 根因修正（本轮，取代旧「OAuth token 过期」推测）**：worker 从**带 `HTTPS_PROXY` 的 shell** 启动，而 `PayPalApiClient` 只设 `CURLOPT_TIMEOUT`、未显式关代理，libcurl **隐式读取 `HTTPS_PROXY`** → 请求被送去本地代理。日志铁证：`Failed to connect to api-m.sandbox.paypal.com port 443 **via 127.0.0.1** after 0 ms`。**故「重启就好」的真因是把代理变量清掉了，不是 token。** **本轮补充**：重启前 Master PID 64244 之下仍挂着 PID **31457/31799** 两个**早于 Master 启动**的泄漏 Worker —— 滚动 reload/restart 既失败又会漏 Worker，故**类改动后必须全量 `server:stop` + `server:start`，且必须清代理变量**。**⭐ 本轮已按建议落码**：`PayPalApiClient` 新增 `resolveOutboundProxy()` 并显式 `curl_setopt($ch, CURLOPT_PROXY, …)` —— 仅当**渠道配置**里显式写了 `http_proxy`/`proxy` 才使用，否则**显式置空**，从根上切断 libcurl 对 `HTTPS_PROXY`/`ALL_PROXY` 环境变量的隐式继承（`php -l` 通过）。详见 §6.5。 |
| R12 | **【已修复·已验证】** 结账页**未渲染收货地址部件**（`checkout-shipping-address` 槽为空，SSR 与 DOM 均无；该部件按 `default_injections` 且 `required=true` 注入，属 R7 同一家族） | ① 任何依赖「结账页地址表单」的 UI 提交路径**静默失败**：`formAddress()` 空 → `submitCheckoutPayment()` 在 `continue_pay` 分支前 `return {cancelled:true, reason:'shipping_fields'}`，**不跳转、不报错**；② 真实用户无法在结账页填写/确认收货地址；③ 场景 C 的 C6/C7 无法完成 | **⭐ 根因（本轮定死）**：`/checkout` 由 **`Weline\Checkout\Controller\Index`** 渲染，走 **SSR-slim**（`template()`/`fetchHtml`，**不经过 `LayoutSlotRenderer`**，其 docblock 明写 *"SSR is a client shell only … Address / shipping / payment hydrate via QueryBin after first paint"*），唯一后处理钩子是 **`StorefrontSsrChromeHealer::ensure()`** —— 而它原先**只从磁盘拼 chrome、从不补页面槽**，于是声明为 required 的**页面槽**以空壳交付。**修复**：在 `ensure()` 里、chrome 修复之前补跑一次 `ThemeLayoutEntitySlotFiller::fillRequiredDefaultsOnShell()`（页面类型取自控制器写入的 `layout_type`，解析不到则跳过、不猜测）；并在 `LayoutSlotRenderer` 的零补槽分支同样补跑（覆盖 `/`、`/products` 等走渲染器的页面）。**验证**：`overlay_plan` 变为 `theme_id 4 / page_type checkout / declarations 51 / plan 27`，`plan_slots` 含 `checkout-shipping-address`；SSR 里 `data-form-id="checkout-shipping-address-editor"` 由 **0 → 1**，地址槽内层由 **16 → 20,009** 字符；浏览器 DOM 复验 `slot_inner_len 16 → 31,955`、部件根节点/JS API/地区级联均存在；**场景 C 的 C6/C7 随之跑通**（§6.2④）。另把 `submitCheckoutPayment()` 的静默 `return` 改为**显式提示**（通知区为空时兜底 `setMessage` + 聚焦首个非法字段）。详见 §6.7。 |
| R11 | **【自动化脆弱点·已加固 runner】** 全量重启后**首个**请求要付冷启动成本（冷 FPC / 冷 layout-entity 缓存 / 冷 opcache） | 固定超时的自动化会在健康的店面上误报失败 | 本轮首次跑场景 A 即报 `payment_method_not_selectable:paypal:got=(none)`：结算页支付单选是**报价结算后由 JS 渲染**的，冷启动下 4 秒预算不够。**已加固 runner**：改为「等支付容器真正带选项」+ 24×700ms 预算（§6.4 缺陷 3）。**注意**：这是**自动化脆弱点，不是店面缺陷** —— 热缓存下同一命令立即全绿。 |

---

## 6. 实测执行结果（2026-09-25）

### 6.1 执行记录表

| 范围 | 用例数 | 通过 | 失败 | 未通过 | 备注 |
|---|---:|---:|---:|---:|---|
| **A 游客 PayPal 全链路** | 18 | **18** | 0 | 0 | **全部通过** —— 含 A13 买家登录批准、A14 回跳、A15 成功页、A16 DB 守恒、A17 副作用、A18 幂等。本轮在全量重启后**再跑一次仍 18/18**（累计连续 3 轮独立成功） |
| **B 登录用户 PayPal** | 6 | 0 | 0 | 6 | **本轮未按场景 B 重跑**（本轮范围严格限定为场景 C 的 7 项，故不改 B 的计数）。**但需记录：**场景 C 的**登录态分支（`CPAY_MODE=account`）本轮已完整跑到 C7**，因此 B 的多数环节**已有直接证据** —— B3（顾客登录成功、顶栏切登录态）、B5（`/customer/account/index#orders` 确实列出该订单）、B6（「继续支付」入口链接与状态正确、点击后进入续付视图）、以及**登录态下的全链路下单到成功页**均已观察到。仍缺 B1（注册）/B2（邮箱验证/设密码）。**建议下一轮单独按场景 B 的 6 条逐条判定并按证据上修**（本表暂不改 B 的计数，避免「顺带观察」被当成完整验收） |
| **C 取消后继续支付** | 7 | **7** | 0 | 0 | **C1~C7 全部通过，且两条入口各跑一遍**：真实点 PayPal 取消 → 回跳 `outcome=cancel` → **直读 DB** 核验 `pending/pending`、无成功交易、`payment_cancel_source=browser_cancel` → 两条「继续支付」CTA 均采纳 → 续付**显式选中真实网关 `paypal`** → 提交 → **真跳 `www.sandbox.paypal.com`** → 沙箱买家批准 → 回跳成功页 → DB `paid/paid`、`transaction_count=2`、`success_transaction_count=1` → 重复回跳**幂等**。详见 §6.2④ |
| **D fake_card 冒烟** | 4 | **4** | 0 | 0 | D1~D4 全通过：一次跑通 `/checkout/success?order_uuid=…` |
| **E 边界与异常** | 10 | 3 | 0 | 7 | E1（空车进结算有引导）、E2/E3（校验拦截）已观察到；其余未系统化执行 |
| **功能点核验（§2）** | **202** | **170** | 0 | **32** | 170 条**有证据**通过；32 条为**订单生命周期 / 交互式认证 / 未执行场景**条目 |
| **合计** | **247** | **202** | **0** | **45** | **零失败** |

> **判定口径**：`通过` = 实际观察到预期结果（或 §2 静态核验：页面可达 + 实现锚点在代码中存在且接线正确）；`未通过` = 前置场景未执行或本轮未系统化执行而无法判定；`失败` = 观察到与预期不符。本轮**零失败**。
> **⚠️ v6 计数变更（本轮，2026-09-26）**：只做「未通过 → 通过」且**有直接证据**的上修，其余一律不动：
> 1. **流程步骤 +2**：场景 C 的 **C6/C7** 由「未通过」改为「通过」——上一轮挡住它们的**真实缺陷（R12，结账页未渲染收货地址部件）已修复**，修复后**两条入口（游客取消页 CTA / 账户订单 CTA）各自完整跑到 C7**。证据（每步均**直读数据库**核验，并另用 `psql` 独立复核）：
>    - 游客：`order_uuid=55e521f9-94e9-41ee-8c30-9c905a3358b3` / `order_number=7454087406`；续付前 `pending/pending`、`success=0`；取消后交易 #142 `PAY20260926045529256889` `paypal/failed`、`payment_cancel_source=browser_cancel`；续付后新增 #143 `PAY20260926045619900181` `paypal/success`（`paid_at 2026-09-26 04:56:30`）→ 订单 **`paid/paid`**、`transaction_count=2`、`success_transaction_count=1`。
>    - 登录顾客：`order_uuid=715af231-e4d9-45aa-99fb-6a3956691822` / `order_number=8754037318` / `customer_id=58`；取消后 #144 `paypal/failed`、`browser_cancel`；续付后 #145 `PAY20260926045932368867` `paypal/success`（`04:59:43`）→ **`paid/paid`**、`2`/`1`。
>    - **两条入口的 `resume_redirect_url` 的 host 均为 `www.sandbox.paypal.com`**（真实网关），且 `local_channel_leak=[]`（未落本地测试通道）。
> 2. **功能点不加计（维持 170）**：本轮未取得**新增**功能点的直接证据 —— 被 C6/C7 解锁的能力都落在**已计通过**的条目上（`PY-03` 下单 / `PY-04` 授权跳转 / `PY-10` 成功态 / `PY-11` 幂等 / `OR-01` 成功页 / `OR-07` 续付入口）。其中 `PY-11`（幂等）本轮拿到了**更强**的 C7 证据（重复回跳后 `transaction_count` / `success_transaction_count` 零变化），但**此前已计通过，故不重复加计**。
> 3. 因此：流程 **30 → 32**（未通过 15 → 13）、合计 **200 → 202**（未通过 47 → 45）。功能点维持 **170/32**。**失败数仍为 0**。
> 4. **仍未上修的**：`PY-09`（支付失败态 `/checkout/failure`）——本轮取消仍落 `success?outcome=cancel`，**未观察到** `checkout/failure`，故维持未通过；`OR-05`（订单列表）本轮是**直接访问** `/customer/account/index#orders` 观察到订单，**未走它声明的锚点路由** `/checkout/frontend/order/list`（该路由实现为 302 重定向到账户页），故按口径维持未通过；`AC-13`（订单分组：列表/详情/**继续支付**/取消）只验到「列表 + 继续支付」，**详情/取消未验**，部分证据不足以整条上修，维持未通过。
> 5. **口径纪律**：v3→v4 已因「无证据计数」下修过一次，本轮**不重犯**——凡无直接证据者一律留在「未通过」，**不因「看起来应该没问题」而上修**。
>
> **⚠️ v5 计数变更（历史，2026-09-26）**：只做两处有直接证据的上修 —— ① **流程步骤 +5**：场景 C 的 **C1~C5** 由「未通过」改为「通过」（真实浏览器跑完「加购 → 结算 → PayPal 审批页**真实点取消** → 回跳 `outcome=cancel` → 两条「继续支付」CTA」，关键状态经 fixture **直读数据库**核验）；② **功能点 +2**：`PY-06`（PayPal 取消回跳）与 `OR-07`（继续支付（订单内））。因此功能点 **168 → 170**、流程 **25 → 30**、合计 **193 → 200**（未通过 54 → 47）。**失败数仍为 0**。当时 **C6/C7 仍为未通过**（被 §6.7 的真实缺陷挡住）。
>
> **⚠️ v4 计数校正（历史）**：v3 记「功能点 178 通过 / 合计 203」，是把 **14 条**支付类功能点计为通过；v4 逐条复核后，**只有 4 条具备直接证据**：`PY-05`（回跳）、`PY-08`（支付后副作用）、`PY-11`（幂等）、`PY-15`（CSP 允许 PayPal 域，代码锚点）。故功能点通过数 **178 → 168**、合计 **203 → 193**，未通过 **24 → 34**。差额 10 条属**无证据计数**（`PY-06`/`PY-07`/`PY-09` 与订单生命周期类），**不是**新增缺陷或回归。
> **仍未通过的 32 条功能点**集中在：`PY-07`（Webhook）、`PY-09`（失败态）、`PY-13`（支付激励）、`PY-14`（退款）、`OR-02~06`、`OR-08~09`（订单生命周期）、`AC-06~11,13~23`（交互式认证与用户中心）、`CK-17~20`（结算类型切换 / 多仓拆单 / 重新报价）。
> **仍未通过的 13 个流程步骤**集中在：场景 B 全部（6）、场景 E 第 4~10 步（7）。

### 6.2 关键证据链

**① 已发布店面业务插槽空 —— 根因定位并修复（R7）**

修复前后对比（`curl` 带浏览器 UA 访问交付 Host，统计 `data-wslot` 空插槽标记数）：

| 页面 | 修复前 | 修复后 | 业务内容 |
|---|---|---|---|
| `/` | 首屏空插槽 | HTTP 200 · 1,005,389 B · `data-wslot=0` | 40 个插槽全部填充 |
| `/products` | 空插槽 | HTTP 200 · 1,607,344 B · `data-wslot=0` | 32 个插槽全部填充 |
| `/cart` | 空插槽 | HTTP 200 · 783,322 B · `data-wslot=0` | 34 个插槽全部填充 |
| `/checkout` | 空插槽（`fill()` 从不执行） | HTTP 200 · 933,350 B · `data-wslot=0` | 37 个插槽全部填充 |

`/checkout` 业务锚点计数（R7 修复后实测）：`checkout-shipping-address=12`、`payment_method=73`、`shipping_method=40`、`Submit order=4`、`checkout-summary=7`、`grand-total=4`。
收货地址插槽 `innerHTML` 长度由 **16 字符 → 19,975 字符**。
`/cart` 为客户端水合页，SSR 输出状态容器（`data-cart-view` / `data-cart-items` / `data-cart-empty-lead` 等），商品行由 JS 拉取渲染 —— 属预期设计。

修复后回归：`/` `/products` `/cart` `/checkout` 四页 `data-wslot` **全为 0**（无残留空插槽），且全站**零失败**。

**①b 2026-09-26 复测（R12 修复后）**：四页仍全部 HTTP 200、`data-wslot=0`；`/checkout` 业务锚点与上表一致（`payment_method=73`、`shipping_method=40`、`Submit order=4`、`checkout-summary=7`、`grand-total=4`）。**本轮新增的是「required 页面槽」而非业务插槽**，故 `data-wslot` 不变，但结账页地址槽由**空壳**变为**已填充**：

| 指标 | R12 修复前 | R12 修复后（2026-09-26） |
|---|---|---|
| SSR 里 `data-form-id="checkout-shipping-address-editor"` 计数 | **0** | **1** |
| 地址槽 `checkout-shipping-address` 内层长度 | **16** 字符 | **20,009** 字符（浏览器 DOM 复验 31,955） |
| 槽内是否含部件根节点 `data-shipping-checkout-address` / CSS 资源 / 地区级联 | 否 | **是** |

> 注：字节数会随动态内容（最近浏览、货币、促销位等）浮动，故上表只保留 2026-09-25 那次的绝对值；**不变量是 `data-wslot=0` 与「地址槽已填充」**。`/checkout` 本次复测 925,576 B（此前 933,350 B），差异来自动态内容，非回归。

**② 场景 A · PayPal 沙箱全链路 —— 18/18 通过（Playwright + 沙箱买家账号）**

沙箱买家账号取自仓库既有记录（`app/code/Weline/Payment/doc/开发/team/payment-shell-compliance-fix/channel/payment-browser-verify.md`）：
`sb-4sxrp30216572@personal.example.com` / `weline@…`，可用环境变量 `PAYPAL_SANDBOX_BUYER_EMAIL` / `PAYPAL_SANDBOX_BUYER_PASSWORD` 覆盖。

运行方式（仓库既有 runner，本轮已修好两处缺陷，见 §6.4）：

```bash
FORCE_PAYMENT_METHOD=paypal PLAYWRIGHT_HEADLESS=1 \
  node app/code/Weline/Checkout/test/e2e/frontend/sandbox-checkout-manual-runner.js
```

runner 输出（`ok: true`，`error: ""`）：

```json
{
  "ok": true,
  "steps": ["pdp_loaded","cart_added","checkout_opened","payment_selected:paypal",
            "ui_ready","submit_done","paypal_opened","paypal_approved",
            "returned:…/checkout/success?source=payment_return&checkout_group_uuid=b34ccff6-…&order_uuid=fc3248cb-…&transaction_no=PAY20260925160030327599"],
  "approve_url": "https://www.sandbox.paypal.com/checkoutnow?token=4HD06976WW648022J",
  "success_url": "https://p05113ef3.test.weline.com:9555/checkout/success?source=payment_return&checkout_group_uuid=b34ccff6-3419-47ba-9084-3a1a814e8bc1&checkout_token=qt_bf060f00dc40eb7d3d9a174e&order_uuid=fc3248cb-37e8-4629-9bc2-e31d9dec3a2e&transaction_no=PAY20260925160030327599",
  "transaction_no": "PAY20260925160030327599",
  "error": ""
}
```

逐步对照（`A1~A18`）：

| 步 | 实测证据 |
|---|---|
| A1~A6 | ✅ PDP 加载、加购 `added:true`、`/checkout` 打开、视图态 `ready` |
| A7~A10 | ✅ 填地址 → 选配送 → 选支付 `payment_selected:paypal`（runner 会校验选中项确实是 paypal，选不中直接报错） |
| A11 | ✅ `submit_done`：生成 `w_weline_checkout_group` + `w_weline_order` |
| A12 | ✅ **跳转 `https://www.sandbox.paypal.com/checkoutnow?token=4HD06976WW648022J`**（第 3 轮：`token=91H49939K7722031D`） |
| A13 | ✅ `paypal_approved`：用沙箱买家账号登录并批准 |
| A14 | ✅ `returned`：回跳站点（`/payment/handoff/` → `/checkout/success`） |
| A15 | ✅ 落地 **`/checkout/success?source=payment_return&…&transaction_no=PAY20260925160030327599`** |
| A16 | ✅ DB：`w_weline_order.order_uuid=fc3248cb-…` → **`status=paid`, `payment_status=paid`**, USD 608.48；`w_weline_payment_transaction` #111 `paypal/success`；`w_weline_payment_intent` #16 `paypal/paid/60848`。**第 3 轮**：`order_uuid=3ce8e50e-…` / `order_number=9889104276` → 同样 `paid/paid` USD 608.48，txn **#112** `paypal/success` `PAY20260925160603937117`，intent **#17** `paypal/paid/60848` |
| A17 | ✅ 副作用：`w_weline_inventory_reservation` #78（offer 6462, qty 1, `state=reserved`）+ `w_weline_inventory_ledger` #11606（`reserve`, +1, strict）在订单创建同秒落库。⚠️ `w_weline_payment_outbox` 为空 —— 本路径副作用同步落库，未走 outbox |
| A18 | ✅ 幂等：把回跳入口连打 3 次（HTTP 200 ×3），`payment_transaction` / `payment_intent` / `order` / `checkout_group` **计数完全不变**（96/16/92/80 → 96/16/92/80），无重复扣款、无重复建单 |

**连续三轮独立跑均成功**（互为回归；第 3 轮在全量重启加载 R8/R9 修复后进行）：

| 轮次 | approve token | order_uuid | 交易 | 订单状态 |
|---|---|---|---|---|
| 第 1 轮 | `0B0391196B413872K` | `c0ccf06a-4cfd-43d3-8bcf-29473f7cbbf3` | #110 `paypal/success` | `paid` |
| 第 2 轮 | `4HD06976WW648022J` | `fc3248cb-37e8-4629-9bc2-e31d9dec3a2e` | #111 `paypal/success` | `paid` |
| 第 3 轮 | `91H49939K7722031D` | `3ce8e50e-0a7b-4865-98df-868d159788e7` | #112 `paypal/success` | `paid`（`order_number=9889104276`） |

第 3 轮同时用于验证 R8 修复与「重启即生效」（见 §6.3）：**同轮内 `submitted+freeze_failed` 零新增**，而 order/txn/intent/group/reservation 各 +1。

**③ 场景 D · fake_card 冒烟 —— 4/4 通过**

`FORCE_PAYMENT_METHOD=fake_card` 同链路：订单落库 → `payment.paid=true, outcome=paid` → 到达 `/checkout/success?order_uuid=…`。

**④ 场景 C · 支付取消 → 继续支付 —— C1~C7 全部通过（两条入口各跑一遍，2026-09-26）**

运行方式（重写后的 runner，**禁用 `fake_card`**；两条入口分别跑）：

```bash
env -u https_proxy -u HTTPS_PROXY -u http_proxy -u HTTP_PROXY -u all_proxy -u ALL_PROXY \
  CPAY_MODE=guest FORCE_PAYMENT_METHOD=paypal PLAYWRIGHT_HEADLESS=1 \
  node app/code/Weline/Checkout/test/e2e/frontend/sandbox-continue-pay-real-storefront-runner.js
# 登录态单独跑（`both` 会在同一上下文里登录失败，见下方注）
env -u https_proxy -u HTTPS_PROXY -u http_proxy -u HTTP_PROXY -u all_proxy -u ALL_PROXY \
  CPAY_MODE=account FORCE_PAYMENT_METHOD=paypal PLAYWRIGHT_HEADLESS=1 \
  node app/code/Weline/Checkout/test/e2e/frontend/sandbox-continue-pay-real-storefront-runner.js
```

runner 输出 `ok: true`、`error: ""`、**19 个步骤全绿**：`C0.customer_logged_in, C0.cart_added, C1.order_created, C1.order_unpaid_verified, C1.paypal_opened, C2.paypal_logged_in, C2.cancel_clicked, C3.cancel_landing_ok, C4.cancel_state_verified, C5.continue_pay_clicked, C5.continue_pay_adopted, C5.address_filled, C6.gateway_method_selected, C6.resume_redirected_to_gateway, C6.second_attempt_verified, C6.paypal_approved, C7.returned_success, C7.paid_verified, C7.idempotent_verified`（guest 少 `C0.customer_logged_in`）。

**游客入口（`CPAY_MODE=guest`，取消落地页 CTA）** —— `order_uuid=55e521f9-94e9-41ee-8c30-9c905a3358b3` / `order_number=7454087406`：

| 步 | 断言 | 实测证据 |
|---|---|---|
| C1 | PDP 加购 → `/checkout` → `freezeQuote` + `submitV2` 建单，且**未支付** | `quote_token=qt_1a3c756a24e765c115d51f06`；DB：`pending / pending`、`transaction_count=1`、`success_transaction_count=0`（交易 #142 `PAY20260926045529256889` `paypal/pending`）；拿到 PayPal 审批页 `…?token=08908300RK337761N` |
| C2 | 在 PayPal 审批页**真实点击**「Cancel and return to Test Store」 | `cancel_clicked_by=candidate#0`（登录后同文案为 `href="#"` + `CancelLink_cancelLink_*`，按文案/类名命中） |
| C3 | 回跳取消落地页，带 `outcome=cancel` | `/checkout/success?source=payment_return&…&outcome=cancel&cancel_state=done&transaction_no=PAY20260926045529256889`；`payment_outcome=cancel`、标题 `Payment cancelled` |
| C4 | 订单仍**未支付**、无成功交易、恢复态=浏览器取消 | DB：`pending/pending`、`success_transaction_count=0`、交易 **#142** 由 `pending` 转 `paypal/failed`（`paid_at` 空）、`payment_outcome=failed`、`payment_status_detail=cancelled`、`payment_recoverable=true`、**`payment_cancel_source=browser_cancel`**、`attempt_history_count=1` |
| C5 | 取消落地页 `[data-continue-pay]` CTA 契约正确、点击后进入续付视图 | `continue_pay_entry=guest_cancel_page_cta`；href 带完整 `#payment-recovery?quote_token=…&idempotency_key=…&payment_method=paypal&order_uuid=…&outcome=failed&recoverable=1`；采纳后 `data-checkout-view="continue_pay"`、续付 chrome 与表单可见、恢复双卡隐藏、**非空车**；`address_module_present=true`、`address_filled.valid=true`（**R12 修复后地址部件已在**） |
| C6 | **显式选中真实网关** → 提交 → 真跳网关 → 批准 | `payment_method_picked={"wanted":"paypal","selected":"paypal","options":[paypal,fake_card]}`；提交后经 `outcome=pending` 恢复态点 `[data-payment-retry]` → `resume_redirect_url=https://www.sandbox.paypal.com/checkoutnow?token=50V6742887859235J`（**host 判定为真网关**）；`resume_urls_seen` 末项即该网关 URL；`local_channel_leak=[]`；批准前 DB：`transaction_count=2`、`success=0`（新增 #143 `PAY20260926045619900181` `paypal/pending`） |
| C7 | 回跳成功页 → 订单 `paid`、**无重复扣款** | `success_url=…/checkout/success?source=payment_return&…&transaction_no=PAY20260926045619900181`；DB `paid_state`：**`order_status=paid` / `payment_status=paid`**、`transaction_count=2`、**`success_transaction_count=1`**（#143 `paypal/success`、`paid_at 2026-09-26 04:56:30`）；**重复回跳**后 `replay_state` 与 `paid_state` **完全一致**（计数零变化 ⇒ 幂等成立） |

**登录顾客入口（`CPAY_MODE=account`，账户订单 CTA）** —— `order_uuid=715af231-e4d9-45aa-99fb-6a3956691822` / `order_number=8754037318` / `customer_id=58`：

| 步 | 实测证据 |
|---|---|
| C0~C1 | 顾客登录成功（顶栏切登录态）→ 加购 → 建单；DB：`pending/pending`、`customer_id=58`（**确属登录顾客的订单**）、交易 #144 `PAY20260926045821353147` `paypal/pending`、`success=0` |
| C2~C4 | 真实点取消 → 回跳 `outcome=cancel`（标题 `Payment cancelled`）→ DB：`pending/pending`、`success=0`、#144 转 `paypal/failed`、**`payment_cancel_source=browser_cancel`**、`payment_recoverable=true`、`attempt_history_count=1` |
| C5 | `continue_pay_entry=account_orders_cta`：`/customer/account/index#orders` 上的 `[data-testid="account-order-continue-pay"][data-order-uuid="715af231-…"]`，href 契约正确（该 CTA 由 `Weline_Order` 的 `account/index/orders.phtml` 在 `continue_pay_reachable && continue_pay_url` 时渲染）；点击后进入续付视图，`address_filled.valid=true` |
| C6 | `payment_method_picked.selected=paypal`；`resume_redirect_url=https://www.sandbox.paypal.com/checkoutnow?token=7CV16638W48286156`（真网关）；`local_channel_leak=[]`；批准前 DB `transaction_count=2`、`success=0` |
| C7 | `success_url=…&transaction_no=PAY20260926045932368867`；DB `paid_state`：**`paid/paid`**、`transaction_count=2`、**`success_transaction_count=1`**（#145 `paypal/success`、`paid_at 2026-09-26 04:59:43`）；`replay_state` 与之一致（幂等） |

**独立复核（不依赖 runner）**：用 `psql` 直连 PostgreSQL 复查两单，结果与 runner 输出**逐项一致**：

```
order_uuid                           | order_number | status | payment_status | method | grand_total | currency | customer_id
55e521f9-94e9-41ee-8c30-9c905a3358b3 | 7454087406   | paid   | paid           | paypal | 608.48      | USD      | (空=游客)
715af231-e4d9-45aa-99fb-6a3956691822 | 8754037318   | paid   | paid           | paypal | 608.48      | USD      | 58
-- 交易：游客 #142 paypal/failed + #143 paypal/success；登录 #144 paypal/failed + #145 paypal/success
-- 成功交易计数均为 1 / 总交易 2 ⇒ 无重复扣款
```

> **两条入口都跑通**，且都真实经过「取消 → 未支付恢复态 → 续付 → 真实网关 → 批准 → 已支付 → 幂等」。
>
> **⚠️ 自动化注意（历史）**：`CPAY_MODE=both` 时第二段（account）在**同一浏览器上下文**里登录会失败（`C0.login`，停在 `/customer/account/login`，reCAPTCHA token 未产出）——这是**自动化脆弱点**，故登录态请单独以 `CPAY_MODE=account` 跑（本节两条证据即如此分别取得）。
>
> **⚠️ 两个「假通过」陷阱（本轮已用硬门禁堵死，见 §6.4 缺陷 5）**：① 续付视图的支付单选默认选中**列表首项**，本机首项是本地测试通道 `fake_card`（立即 `paid`、无网关跳转），而页面提交时 `selectedValue('payment_method')` **优先于**恢复契约里的 `payment_method` ⇒ 不显式切换就会「假成功」；② 停在 `outcome=pending` 恢复态时整个 URL 会带上 **URL 编码**的 `redirect_url=https%3A%2F%2Fwww.sandbox.paypal.com%2F…`，对整串做子串匹配会把「没跳转」误判成「已跳网关」。

### 6.3 R8 结案：`freeze_failed` 不是「结账失败」，是**事后脏写**

上一轮把 `w_weline_checkout_session` 里的 `state=submitted + error_code=freeze_failed + 「购物车为空，无法结账」` 判为「疑似二次冻结」。本轮逐条比对后**结论更正**：

**证据**：把 5 条 `freeze_failed` 会话与 `w_weline_checkout_group` 按 `idempotency_key` 对齐，**5/5 一一命中**，且 group 的创建时间**早于** error 时间：

| session | state | error_code | idempotency_key | group 创建 | error 记录 |
|---|---|---|---|---|---|
| 276 | submitted | freeze_failed | `checkout_ui_57d8b4db-…` | 14:40:40 | 14:40:48 |
| 278 | submitted | freeze_failed | `checkout_ui_769bc735-…` | 14:41:59 | 14:42:01 |
| 280 | submitted | freeze_failed | `checkout_ui_dd826b47-…` | 14:48:52 | 14:48:55 |
| 282 | submitted | freeze_failed | `checkout_ui_4535529f-…` | 14:51:24 | 14:51:26 |
| 284 | submitted | freeze_failed | `checkout_ui_790ac758-…` | 14:52:24 | 14:52:26 |

**机制**：订单已创建（`submitV2` 把 session 置为 `submitted`，购物车随之被消费）→ 之后又来了一个带同一 `quote_token` 的 `freezeQuote` 请求 → 此刻购物车确实已空 → `CheckoutCartSnapshotService::freeze()` 抛「购物车为空，无法结账」→ `CheckoutQueryProvider::recordNamedFault()` **无条件**把 `freeze_failed` 写回该 session。于是**已成功下单的会话在库里显示成失败**。

**结论**：`freeze_failed` 是**事后脏写（misleading bookkeeping）**，不是结账失败；订单每次都真的创建成功了。同 fingerprint 的第二条 `quoted` 会话则是 `ensureSession()` 拒绝复用 `submitted` token 而新开的，属正常行为。

**已修**（`CheckoutSessionFaultRecorder::recordCode()`）：会话已是 `submitted` 时**不再覆写错误快照** —— 已下单会话的结果已定，事后游离请求不得改写历史。

**修复验证（全量重启后，2026-09-25 16:04 起）**

前置：全量 `server:stop` + `server:start`，Master 64244 → **34635**，四个 Worker 全部换新（34827~34830），确保修复类真正被加载。

| 指标 | 重启后基线 | 跑完 1 轮完整 PayPal 下单后 | 变化 |
|---|---:|---:|---|
| `w_weline_checkout_session`（`submitted` + `freeze_failed`） | **6** | **6** | **0（零新增 ✅）** |
| `w_weline_checkout_session`（任意 `freeze_failed`） | 7 | 7 | 0 ✅ |
| `w_weline_payment_transaction` | 96 | 97 | +1 |
| `w_weline_payment_intent` | 16 | 17 | +1 |
| `w_weline_order` | 92 | 93 | +1 |
| `w_weline_checkout_group` | 80 | 81 | +1 |
| `w_weline_inventory_reservation` | 71 | 72 | +1 |

**判定**：一次成功下单恰好产生 1 组 order/group/intent/txn/reservation，而**没有任何**新的 `freeze_failed` 脏写 —— 修复生效。（历史 6 条为修复前的存量数据，未回填，属预期。）

### 6.4 本轮修复的 runner 缺陷（仓库既有工具）

`sandbox-checkout-manual-runner.js` 有三个会**误报失败**的缺陷，本轮一并修掉：

1. **支付方式静默回退**：只 `check()` 一次 paypal 单选，而该单选在报价返回后会被 JS 重渲染，于是常常落在陈旧节点上、实际提交的是默认的 `fake_card`，但脚本仍声称在测 PayPal。→ 改为**重试直到目标单选真正生效**，并新增 `FORCE_PAYMENT_METHOD` 环境变量（默认 `paypal`）；选不中直接抛 `payment_method_not_selectable`，不再假通过。
2. **成功判定被导航竞态打断**：回跳入口 `/payment/handoff/` 会二次跳转到 `/checkout/success`，而旧守卫用 `/handoff/` 匹配就跳过了等待，随后 `page.content()` 撞上正在进行的跳转，抛 *"Unable to retrieve content because the page is navigating"* —— **一次完整成功的 PayPal 下单被报成 `ok:false`**。→ 改为始终等到 `/checkout/success`，再等页面稳定后重试读取内容。
3. **冷启动预算不足（本轮新发现）**：结算页的支付单选**不是 SSR 渲染**的，而是报价结算后由 JS 注入（重启后实测 SSR 里 `name="payment_method"` 仅 6 处、`data-payment-method-option` 为 0）。全量重启后**首个**请求要付冷 FPC / 冷 layout-entity 缓存 / 冷 opcache 的成本，原「固定 sleep 1500ms + 8×500ms≈4s」预算不够 → 直接抛 `payment_method_not_selectable:paypal:got=(none)`，**在健康的店面上误报失败**。→ 改为**先等 `[data-payment-methods]` 容器真正带上选项**（最多 90s），再固定 settle；重试预算提到 **24×700ms≈17s**。加固后热缓存下立即全绿（第 3 轮 18/18）。

**4. 续付 runner 的「假通过」（本轮已重写为真实通路 runner）**

`sandbox-continue-pay-real-storefront-runner.js` 旧版返回 `ok:true` 并给出 `continue_pay_url`，看起来是场景 C 的证据，实际**不成立**：

- 它用 `payment_method=fake_card` 走 `submitV2`，而 `fake_card` 是**本地快速通道、支付立即成功** → 订单已有成功交易；
- 它调 `checkout-continue-pay-real-pathway-fixture.php` 的 `prepare_cancel_continue`，用真实服务 `CheckoutPaymentRecoveryStateService::markBrowserCancel()` 写入「已取消」恢复态 —— 于是**恢复态说失败、真实支付却已成功**，两套状态互相矛盾；
- 实测把该 `continue_pay_url` 用真实浏览器打开：**直接 302 到 `/checkout/success`**，页面显示 *"Thank you for your order! Your order has been successfully paid for"*，`[data-checkout-view]` 节点为 **0**、`[data-payment-retry]` 为 **0** —— **根本没有落到「支付恢复 / 继续支付」视图**；
- DB 佐证：订单 `3841a6b6-0f41-4083-93d7-4659c2a96593`（`order_number=0155404970`）`status=pending / payment_status=pending`，却存在 `w_weline_payment_transaction` **#113** `fake_card/success` `PAY20260925160747199940`。

**本轮已按上面的结论重写该 runner**（不再有任何造假路径）：

| 旧版（假通过） | 新版（真实通路） |
|---|---|
| `fake_card` 立即支付成功 | **只用 `paypal`**；硬性禁止 `fake_card` 制造「支付成功」 |
| 调 fixture 伪造「已取消」恢复态 | **在 PayPal 审批页真实点击「Cancel and return to …」**，走 `browser-cancel-entry` |
| 断言自造数据（循环通过） | 新增 fixture 只读动作 `verify_order_state`，**直读数据库**核验订单/交易/恢复态/尝试历史 |
| 失败静默 | 每一步 `assertStep` 失败即抛错并带服务端现场；提交失败还带网络探针（operation + 响应体）与页面诊断 |
| 单一入口 | `CPAY_MODE=guest\|account\|both`：覆盖**取消落地页 CTA** 与**账户订单 CTA** 两条入口 |
| 无地址处理 | 提交前必须核验收货地址部件已渲染（本轮正是靠它定位到 §6.7 的缺陷） |

新版实测（`CPAY_MODE=guest` 与 `CPAY_MODE=account` 分别跑，`FORCE_PAYMENT_METHOD=paypal PLAYWRIGHT_HEADLESS=1`）：

- **C1~C7 两条入口均通过**（游客 `guest_cancel_page_cta`、登录 `account_orders_cta`），每步都有直读 DB 的证据（见 §6.2④）。
- 上一轮此处的 **C6/C7 未通过**曾被如实记录（提交后停在 `/checkout#payment-recovery?…`、无字段错误、无 JS 报错、无跳转，与 `submitCheckoutPayment()` 静默 `return {cancelled:true, reason:'shipping_fields'}` 完全吻合）；根因即 §6.7 / R12，**本轮已修复**，故 C6/C7 转为通过。

**5. 续付 runner 的另外两个「假通过」（本轮新加固，已堵死）**

修好 R12 之后，runner 才**第一次真正走到提交**，随即暴露出两个会**把失败伪装成成功**的判定缺陷。两者都不是店面问题，而是**验证方法本身不可信**：

| 缺陷 | 为什么是「假通过」 | 加固做法 |
|---|---|---|
| **① 静默落进本地测试通道** | 续付视图的支付单选由报价后 JS 注入，**默认选中列表首项**；本机首项是本地测试通道 `fake_card`（**立即 `paid`、无任何网关跳转**）。而页面提交时取 `selectedValue('payment_method')` **优先于**恢复契约里的 `payment_method` ⇒ 不显式切换就会「取消 → 续付」看起来成功、实则**根本没走 PayPal**。 | 新增 `pickGatewayPaymentMethod(page, phase)`：**必须显式选中真实网关**（`FORCE_PAYMENT_METHOD`，默认 `paypal`），选不中/选中项不符即抛 `…gateway_method_absent` / `…gateway_method_not_selected`；并在提交后新增 `local_channel_leak` 硬断言 —— 网络探针里任何 `method_code` 命中 `fake_card\|local_test\|test_card` 即**失败**。 |
| **② 子串匹配 URL 编码串** | 续付提交后可能先停在 `outcome=pending` 恢复态，此时**整个 URL** 会带上 **URL 编码**的 `redirect_url=https%3A%2F%2Fwww.sandbox.paypal.com%2Fcheckoutnow%3Ftoken%3D…`。旧断言对**整串**做 `/sandbox\.paypal\.com/` 子串匹配 ⇒ 命中这段编码文本，把**「没有跳转」误判成「已跳网关」**。 | 改为按 **URL 的 host** 精确判定（`new URL(u).host` 必须匹配 `sandbox.paypal.com`）；若停在 pending 恢复态，则**真实点击 `[data-payment-retry]` CTA**（真实用户动作）后继续轮询；并记录 `resume_urls_seen` 全程 URL 轨迹以便审计。修正后 `resume_redirect_url` 的 host 为 `www.sandbox.paypal.com`，`resume_urls_seen` 末项即网关 URL。 |

> 这两个门禁是**必须**的：它们正是「用 `fake_card` 造成功」与「看串文本就当跳转」两种造假路径的守门人。加上 §6.4 缺陷 4 里对旧版 runner 的重写，本 runner 目前**没有任何已知的假通过路径**。

### 6.5 一个运维级发现：**长跑 Worker 会让 PayPal 支付静默失败**

全量重启 WLS **之前**，PayPal 分支连续两次以 `checkout_payment_failed` 失败（订单已建、无跳转、`transactions:[]`）；**全量 `server:stop` + `server:start` 之后连续两次成功**。

- 同一订单、同一方法，用 CLI 直接调 `CheckoutOrderPaymentService::pay()` **可以正常拿到 `redirect_url`** → 说明凭据与代码本身没问题，问题在**运行中的 worker 进程状态**。
- **⭐ 根因已定位（本轮修正，取代此前「PayPal OAuth token 过期」的推测）**：worker 是从一个**带代理变量**的 shell 启动的，环境里存在 `HTTPS_PROXY=http://127.0.0.1:<port>`；而 `PayPalApiClient` 只设了 `CURLOPT_TIMEOUT`、**没有显式关闭代理**，libcurl 会**隐式读取 `HTTPS_PROXY`/`HTTP_PROXY`** → PayPal 请求被送去本地代理端口 → 失败。日志铁证（`var/log/wls/default/error-2026-09-26.log`）：
  `payCreatedOrders failed: method=paypal … RuntimeException: Failed to connect to api-m.sandbox.paypal.com port 443 via 127.0.0.1 after 0 ms`
  —— `via 127.0.0.1` 就是被代理劫持的直接证据。**故「重启就好了」的真正原因不是 token，而是重启时把代理变量清掉了。**
- **正确做法**：全量重启时**显式清掉代理变量**，否则重启也未必生效：
  ```bash
  env -u https_proxy -u HTTPS_PROXY -u http_proxy -u HTTP_PROXY -u all_proxy -u ALL_PROXY \
    php bin/w server:stop && \
  env -u https_proxy -u HTTPS_PROXY -u http_proxy -u HTTP_PROXY -u all_proxy -u ALL_PROXY \
    php bin/w server:start
  ```
  并核对 `server:status` 里所有 Worker PID 晚于 Master。**⭐ 本轮已落码（按此前建议）**：`PayPalApiClient` 新增 `resolveOutboundProxy(array $config): string` 并在 `request()` 里显式 `curl_setopt($ch, CURLOPT_PROXY, …)` —— **仅当渠道配置显式提供 `http_proxy`/`proxy` 时才使用，否则显式置空**，从根上切断 libcurl 对 `HTTPS_PROXY`/`HTTP_PROXY`/`ALL_PROXY` 环境变量的隐式继承（`php -l` 通过）。这样「重启必须清代理变量」从**硬约束**降级为**运维卫生**（仍建议清，以免其它出网调用踩同一坑）。
- 失败时**没有任何日志**：`CheckoutQueryProvider::payCreatedOrders()` 用 `catch (\Throwable)` 把网关异常整个吞掉，只回一个笼统的 `checkout_payment_failed`，事后无法定位。
- **已修**：该 catch 现在用 `w_log('error', …)` 记录 `method / orders / 异常类 / message / file:line`，且日志失败绝不影响原异常路径。
- **运维建议**：PayPal 分支出现 `checkout_payment_failed` 且无跳转时，先**全量重启 WLS** 再复测；并到日志里找 `[checkout] payCreatedOrders failed`。
- **本轮补充（Worker 泄漏）**：重启前 `server:status` 显示 Master PID **64244**，但其下仍挂着 HTTP Worker PID **31457 / 31799** —— **早于 Master 启动**，即滚动 reload/restart 失败后残留的旧 Worker（它们持有旧类，本轮的 R8/R9 修复在其上不生效）。**因此：改完 PHP 类必须全量 `server:stop` + `server:start`，并核对 `server:status` 里所有 Worker PID 都晚于 Master**，否则会在「改了却不生效」上浪费时间。

### 6.6 与「已有自动化」的关系

`tests/e2e/` 的 94 个 frontend spec **不含** PayPal 前端端到端 spec（现有为 backend）。本轮的覆盖来自仓库既有的两个**手动 runner**（非 spec）：

- `app/code/Weline/Checkout/test/e2e/frontend/sandbox-checkout-manual-runner.js` —— 场景 A（游客全链路）+ 场景 D（fake_card 冒烟）。
- `app/code/Weline/Checkout/test/e2e/frontend/sandbox-continue-pay-real-storefront-runner.js` —— **场景 C**（取消 → 继续支付），本轮已跑到 **C1~C7 全通过**（双入口），并带两道**反「假通过」硬门禁**（§6.4 缺陷 5）。

**建议**把两者固化成正式 spec（`Payment` 或 `Checkout` 的 `Test/e2e/frontend/paypal-sandbox-*`），纳入回归（对应 R6）；runner 里 `FORCE_PAYMENT_METHOD` / `CPAY_MODE` 已就绪，paypal / fake_card 两条路、guest / account 两条入口都可共用同一脚本。**注意**：固化时务必**一并带上**「必须显式选中真实网关」与「按 URL host 判网关」两道门禁，否则回归会退化成「假通过」。

### 6.7 【已修复·已验证】结账页未渲染「收货地址」部件 → 提交被**静默取消**（R12）

> 本节保留**完整定位过程**（它是本轮最有价值的一处根因分析），末尾给出**根因结论与修复验证**。

**现象**：场景 C 走到 C5 后，在续付视图点「提交」**毫无反应** —— 不跳转、不报错、不落任何提示消息，页面停在 `/checkout#payment-recovery?…`（`[data-checkout-view]="continue_pay"`、`[data-submit]` 可点、无字段错误、无 JS 异常、无失败请求）。

**定位过程（先排除自动化因素）**：

| 检查 | 结果 |
|---|---|
| 直接调 `resumePaymentV2`（绕过表单，用页面自身的 API client） | **完全正常**：返回 `payment.outcome="pending"`、`redirect_url=https://www.sandbox.paypal.com/checkoutnow?token=…`，并创建 `pending` 交易 → **服务端链路没问题** |
| 结论：问题在「表单提交」这一段 | 读 `checkout/index.phtml`：`submitCheckoutPayment()` 在 `continue_pay` 分支**之前**先跑 `validateCheckoutShippingFields(formAddress())`，不通过就 **`return { cancelled: true, reason: 'shipping_fields' }`** —— 这个 return **既不跳转也不设消息**，正是「点了没反应」的来源 |
| 那 `formAddress()` 为什么空？ | `formAddress()` 优先取 `WelineShippingCheckoutAddress.resolveQuoteAddress()`；而该部件**根本不存在** |
| 为什么不存在？ | `/checkout` 的「收货信息」面板里是 `<w:slot id="checkout-shipping-address">`，该部件按 `default_injections`（`layout_type=checkout`、**`required=true`**，见 `Weline/Shipping/doc/需求.md:64`）注入。**实测该槽为空** |

**实测证据（修复前，可复跑）**：

```bash
# ① SSR 里根本没有该部件的模板标记（0 = 未注入）
curl -sk --resolve p05113ef3.test.weline.com:9555:127.0.0.1 \
  -A "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) … Chrome/140.0.0.0 Safari/537.36" \
  https://p05113ef3.test.weline.com:9555/checkout \
  | grep -c 'data-form-id="checkout-shipping-address-editor"'      # → 0
# ② 浏览器里连续轮询 90s，部件始终不挂载
#    → addr_slot=false / api=false / 地址类字段数=0（document 级）
```

- **不限于续付视图**：**普通 `/checkout`（有车、`data-checkout-view="ready"`）同样没有**该部件（90s 轮询始终 `addr_slot=false`、`addr_field_count=0`），且 SSR 侧也无标记。
- 因此**任何依赖「结账页地址表单」的 UI 提交路径都会静默失败**（`continue_pay` 与普通结算都一样）。
- **场景 A 之所以 18/18 通过，是因为它不走表单**：runner 把 `shipping_address` 直接放进 `getData`/`freezeQuote` 的 API 载荷里 —— 这恰好**掩盖**了这个缺陷。
- 判定口径：这是**真实缺陷**（required 默认注入未生效，属 R7 同一家族），**不是**自动化脆弱点。

**⭐ 根因（本轮定死，取代上一轮「注入链未生效」的粗判）**

`/checkout` **不是**由 `LayoutSlotRenderer` 渲染的。它的真实控制器是 **`Weline\Checkout\Controller\Index`**，其 docblock 明写：

> *P0 reachability: SSR is a client shell only — no currentCart/getCart/summary, `template()`/`fetchHtml` skips `LayoutSlotRenderer` entity fill. Address / shipping / payment hydrate via QueryBin after first paint.*

即 `/checkout` 走 **SSR-slim** 路径（`template()` → `theme/frontend/layouts/checkout/default.phtml` → `ensurePublishedChrome()`），**根本不进 `LayoutSlotRenderer`**；其**唯一**的后处理钩子是 **`StorefrontSsrChromeHealer::ensure()`**，而它原先**只从磁盘 `chrome.rendered` 拼接 chrome、从不补页面槽**。于是：

- 声明为 **required 的「页面槽」**（如 `checkout-shipping-address`、`checkout-tax-identity`、`checkout-express-payment`）**以空壳交付**；
- 契约明确禁止「soft fallback 直渲」——见 `Weline_Checkout/test/Unit/View/CheckoutShippingAddressSlotContractTest.php`（标题即 *「结账页收货地址槽：只走 required injection，禁止 soft fallback 直渲，槽须 exclusive」*）。**所以空壳是缺陷，不是「设计」。**

**修复（两处，都在「必装永远存在」的既定架构裁决下补跑 required overlay）**

1. **`StorefrontSsrChromeHealer::ensure()`（本轮真正生效处）**：在 chrome 修复**之前**先补跑一次 required 页面槽注入 —— 新增 `fillRequiredPageDefaults()`，经 `ThemeLayoutEntitySlotFiller::fillRequiredDefaultsOnShell()` 调 `RequiredDefaultInjectionStorefrontOverlay::append()`；页面类型取自控制器写入的 `layout_type`（结账页为 `checkout`），**解析不到就跳过、不做猜测**；异常软降级。
2. **`LayoutSlotRenderer` 的零补槽分支**：在 `SlotBoundaryMarkers::strip()` **之前**同样补跑 required 注入（覆盖 `/`、`/products` 等**走渲染器**的页面），避免「`+skip_fill_solidified` 只跳过 entity fill、却把 required overlay 一并跳过」。

**修复验证（2026-09-26，全量重启后）**

| 指标 | 修复前 | 修复后 |
|---|---|---|
| 探针 `overlay_plan` | 未触发（`/checkout` 不进渲染器） | `theme_id 4 / page_type checkout / declarations 51 / **plan 27**`，`plan_slots` 含 `checkout-shipping-address` |
| SSR `data-form-id="checkout-shipping-address-editor"` | **0** | **1** |
| 地址槽内层长度 | **16** 字符 | **20,009** 字符（浏览器 DOM 复验 **31,955**） |
| 槽内部件根节点 / JS API / 地区级联 | 无 | **均在**（`data-shipping-checkout-address`、`WelineShippingCheckoutAddress.mount`、`WelineThemeAddress`） |
| 四页 `data-wslot`（空业务插槽） | 0 | **仍为 0**（无回归） |
| 场景 C 的 C6/C7 | ❌ 未通过 | ✅ **通过（两条入口各一遍）**，见 §6.2④ |

**附带修复（可观测性）**：`submitCheckoutPayment()` 的静默 `return {cancelled:true, reason:'shipping_fields'}` 改为**显式提示** —— 校验不通过时，若通知区为空则兜底 `setMessage(copy.required,'error')`，并 `focusFirstInvalidCheckoutField()` 聚焦首个非法字段（**只在通知区为空时兜底**，避免覆盖校验器写出的更具体文案）。这样即便日后校验再被触发，用户/自动化都会**看到明确反馈**，而不是「点了没反应」。

---

## 7. 结论与后续

**本轮交付**：
1. 全量**前端功能地图**（§1）+ **202 条功能点细则**（§2，均带实现锚点）。
2. **PayPal 端到端测试用例**（§3，场景 A~E 共 45 步）。
3. **场景 A · PayPal 沙箱全链路 18/18 通过**（§6.2②）—— 含买家登录批准、回跳、成功页、DB 守恒、库存副作用、幂等；**连续 3 轮独立跑均成功**，第 3 轮在全量重启加载修复后进行。
4. **场景 C · 支付取消 → 继续支付：C1~C7 全部通过**（§6.2④，**两条用户入口各跑一遍**）—— 该场景已**完全收口**：
   - 旧的续付 runner 是**假通过**（用 `fake_card` 先把订单付掉、再伪造「已取消」恢复态，`continue_pay_url` 实际 302 回成功页）；本轮**已重写**为真实通路 runner（§6.4 缺陷 4），并再补**两道反「假通过」硬门禁**（§6.4 缺陷 5：静默落本地测试通道、URL 编码串误判为网关）。
   - 完整证据链（游客 `55e521f9-…`/`7454087406`，登录 `715af231-…`/`8754037318`）：PDP 加购 → 结算建单（**未支付**）→ **在 PayPal 审批页真实点「Cancel and return to …」** → 回跳 `outcome=cancel`（标题 `Payment cancelled`）→ **直读 DB** 核验 `pending/pending`、`success_transaction_count=0`、交易 `paypal/failed`、**`payment_cancel_source=browser_cancel`** → 点**取消落地页 CTA** 与**账户订单 CTA**（两条都验）→ 进入 `continue_pay` 续付视图（**地址部件已在**）→ **显式选中真实网关 `paypal`** → 提交 → **真跳 `www.sandbox.paypal.com`** → 沙箱买家批准 → 回跳成功页 → DB **`paid/paid`**、`transaction_count=2`、`success_transaction_count=1` → **重复回跳计数零变化（幂等）**。
   - 关键状态另用 `psql` **独立复核**（不依赖 runner），与 runner 输出逐项一致。
5. **本轮代码修复（4 个文件 / 3 项，均已在全量重启后验证）**：
   - `ThemeLayoutEntitySlotFiller` —— 已发布店面业务插槽全空（R7，**最关键的修复**，此前任何下单链路都走不通）；重启后冷缓存下四页 `data-wslot` 仍全为 0。
   - `CheckoutSessionFaultRecorder::recordCode()` —— 不再把 `freeze_failed` 脏写到已 `submitted` 的会话（R8）；验证：一轮下单后 `submitted+freeze_failed` **零新增**，order/txn/intent/group/reservation 各 +1。
   - `CheckoutQueryProvider::payCreatedOrders()` —— 网关异常不再被静默吞掉，落 `w_log` 便于定位（R9）。
   - **`StorefrontSsrChromeHealer` + `LayoutSlotRenderer`** —— **本轮新增**：给「SSR-slim 页面」（`/checkout` 等，不进 `LayoutSlotRenderer`）与「零补槽分支」补跑 required 默认注入，修复结账页地址槽空壳（R12）；另 `index.phtml` 把 `submitCheckoutPayment()` 的静默 `return` 改为显式提示，`PayPalApiClient` 显式 `CURLOPT_PROXY` 消除代理环境依赖（R10）。
6. **runner 改造**：`sandbox-checkout-manual-runner.js` 修 3 处（§6.4 缺陷 1~3，并新增 `FORCE_PAYMENT_METHOD`）；`sandbox-continue-pay-real-storefront-runner.js` **整篇重写**为真实通路（禁 `fake_card`、真实取消、直读 DB 核验、双入口 `CPAY_MODE`、失败带网络探针与页面诊断）并加**两道反「假通过」硬门禁**；fixture 新增**只读**动作 `verify_order_state`。
7. **两份运维结论**（§6.5 / R10、R11）：① 长跑 Worker 会让 PayPal 静默失败，**根因已修正为「worker 继承 `HTTPS_PROXY`、libcurl 隐式走代理」**（不是 token 过期），且**类改动不重启不生效**、滚动 reload 还会泄漏早于 Master 的旧 Worker；② 全量重启后**首个**请求的冷启动成本会击穿固定超时的自动化。
8. **一份已修复缺陷**（§6.7 / R12）：结账页 `checkout-shipping-address` 槽为空 → 地址表单不渲染 → 提交静默取消。**已修并验证**（SSR 标记 `0 → 1`、槽内层 `16 → 20,009` 字符，C6/C7 随之通过）。
9. **验收看板** `doc/测试/商城前端验收看板.html`：247 条（202 功能点 + 45 流程步）已预置实测结果，可点击复核 / 导出 Markdown。
10. **计数（v6）**：场景 C **C6/C7 转为通过**（流程 **+2**）；功能点维持 **170**（本轮无**新增**功能点的直接证据）。合计 **200 → 202 通过 / 47 → 45 未通过 / 0 失败**（详见 §6.1）。

**待办（按优先级）**：
1. 把 runner 固化为正式 e2e spec，纳入回归（R6 / §6.6）——paypal 与 fake_card 两条路可共用 `FORCE_PAYMENT_METHOD`；建议把本轮新增的**两道反「假通过」门禁**一并带进 spec。
2. 补齐**场景 B（登录用户）**：本轮 `CPAY_MODE=account` 已观察到 B3/B5/B6 与「登录态全链路到成功页」，但 B1（注册）/B2（邮箱验证）未执行 —— 建议按场景 B 的 6 条逐条判定后按证据上修（注：`CPAY_MODE=both` 的第二段登录会失败，需单独跑，见 §6.2④ 注）。
3. 把「全量重启须清代理变量」写进运维步骤（代码侧已由 `CURLOPT_PROXY` 显式置空兜住，但其它出网调用仍可能踩坑）。
4. 对齐 `payment/method/paypal/return_url` 到交付 Host（R1）——当前链路不受影响，但切 live 前应处理。
5. 明确 `w_weline_payment_outbox` 为空是设计（同步落库）还是漏建 outbox（A17 备注）。
6. 补齐 `PY-07`（Webhook）/ `PY-09`（失败态）/ `PY-13`（激励）/ `PY-14`（退款）的证据；`OR-05`（订单列表）与 `AC-13`（订单分组）本轮只拿到**部分**证据（列表 + 继续支付），需补详情/取消后再整条判定。

---

*本文档由仓库扫描生成，功能点均带实现锚点，可直接对照代码复核；§6 为本机实测结果（场景 A 18/18 全通过，累计 3 轮；场景 C 于 2026-09-26 用真实通路 runner 跑通 **C1~C7**，两条入口各一遍，关键状态经 `psql` 独立复核；合计 **202/247 通过、0 失败、45 未通过**，计数口径见 §6.1 的 v6 说明）。*
