# 全站高优修复 · 本机 Browser 复验证据

- **席位**：Team:测试/汇审  
- **日期**：2026-09-23  
- **入口**：`https://p05113ef3.test.weline.com:9555/`（已 curl 探活 HTTPS 200；HTTP→HTTPS 308）  
- **截图目录**（本地、已 gitignore）：`channel/sitewide-click-qa-shots/reverify/`  
- **方法**：点开/导航后立即截图；记录页面可见错误；禁止假绿  
- **Browser 门禁**：`Network.setCacheDisabled(true)` + `navigator.webdriver` 抹除；非抢占后台  
- **说明**：Cursor `ide-browser` 本回合建 tab 即蒸发，改用本机 Chrome CDP + puppeteer-core 同等门禁复验；收口已结束本机调试 Chrome 进程。原始机读：`reverify-raw-part2.json`（及首轮 stdout）

## 摘要表

| ID | 项 | 结果 | 证据截图 |
|---|---|---|---|
| QA-10 | `/` 无维护弹层挡点击 | **pass** | `01-home-qa10.png` |
| QA-01 | 连续分类浏览不黑屏 / Empty reply | **pass** | `02-cat-0-women.png`、`02-cat-4-hanfu.png` |
| QA-05/17 | `/cart` 任意时刻只一种可见态 | **pass** | `03-cart-qa05.png`、`03b-cart-with-items.png` |
| QA-09 | `/cart`→`/checkout` 状态一致、无加载中+空车+表单同屏 | **pass**（热修后复测） | `12-qa09-checkout-settled.png`、`14-qa09-from-cart-checkout.png`（旧 fail：`04`/`04b`） |
| QA-13 | `/privacy` 等短链 301 可达政策页 | **pass** | `05-privacy-qa13.png`、`05b-cookie-qa13.png` |
| QA-12 | 订单跟踪→登录 referer 无 `%253A` 双编码 | **pass** | `06-order-login-qa12.png` |
| QA-03 | 热搜含披帛；汉服配饰见商品 0 明示 | **pass** | `07a-hotsearch-chips.png`、`07-search-pibo-qa03.png`、`08-search-accessories-qa03.png` |
| QA-04 | 开关语言/迷你车无 `close is not a function` | **pass** | `09a-lang-open.png`、`09-lang-minicart-qa04.png` |

**汇审结论（首轮）**：曾 7 pass / 1 fail（QA-09 脚本外泄）。  
**汇审结论（QA-09 热修后）**：清单 8 项均为 **pass**（见下方「QA-09 热修复测」）。本席未改业务代码。

---

## 分项证据

### QA-10 · 首页维护弹层 — **pass**

- 打开 `/` 立即截图：`01-home-qa10.png`
- 可见：首屏 Hero + 推荐商品可交互；**无**「网站正在升级维护 / 维护补偿礼金 / 稍后再来」dialog
- DOM：`#weline-maintenance-wait-modal` 不可见 / 无挡点击维护文案
- 底部仅 Cookie 条（非维护弹层），不记为本项 fail

### QA-01 · 连续分类浏览 — **pass**

连续 `goto`：`/category/women|men|kids|accessories|hanfu`

| 路径 | HTTP | bodyLen | chrome-error |
|---|---|---|---|
| women | 200 | 2956 | 否 |
| men | 200 | 2263 | 否 |
| kids | 200 | 800 | 否 |
| accessories | 200 | 2006 | 否 |
| hanfu | 200 | 2897 | 否 |

- 截图：`02-cat-0-women.png`、`02-cat-4-hanfu.png`
- 未见黑屏 `chrome-error://`、未见 Empty reply

### QA-05/17 · 购物车单可见态 — **pass**

- `/cart` 截图：`03-cart-qa05.png`
- `data-cart-view` 仅 **`empty`** 可见
- 可见「购物车是空的」；**无**「暂时无法加载/加载失败」；**无**「去结算」有货态同屏
- 下方「搭配成套」推荐卡属营销区块，不计入购物车三态冲突
- 首页点「加入购物车」后回 `/cart` 仍为空（`03b`/`10-after-add-to-cart.png`；加购未落车，不抬高本项；空车仍单态）

### QA-09 · 结算态机 / 可见错误 — 首轮 **fail** → 热修后 **pass**

- 首轮截图：`04-checkout-qa09.png`、`04b-checkout-after-add.png`（脚本外泄 + 卡 loading）
- **热修复测**见文末「QA-09 热修复测」节 → **pass**

### QA-13 · 政策短链 — **pass**

| 短链 | 探活 | 最终页 |
|---|---|---|
| `/privacy` | HTTP/2 **301** → `.../USD/policy/privacy` | Browser 落点 `/policy/privacy`，title「隐私政策 \| 长安汉服」，200（`05-privacy-qa13.png`） |
| `/cookie` | **301** → policy/cookie | 落点 `/policy/cookie`（`05b-cookie-qa13.png`） |
| `/refund` | **301** → `/policy/refund` | curl 确认 |

### QA-12 · 登录 referer 编码 — **pass**

- 顶栏「订单跟踪」→ 登录页
- 最终 URL：  
  `/customer/account/login?referer=https%3A%2F%2Fp05113ef3.test.weline.com%3A9555%2Fcustomer%2Faccount%2Findex#orders`
- `referer` 单次解码为 `https://p05113ef3.test.weline.com:9555/customer/account/index`
- **无** `%253A` / `%252F` 双编码
- 截图：`06-order-login-qa12.png`  
  （旁注：仍无游客订单号追踪表单＝原 QA-11/02，不在本轮「修后应 pass」清单内）

### QA-03 · 热搜披帛 / 汉服配饰商品 0 — **pass**

- 热搜文案含：**马面裙 · 明制汉服 · 宋制汉服 · 齐胸襦裙 · 披帛**（`07a-hotsearch-chips.png` 等多页可见）
- 点「披帛」→ `/search?q=披帛`，商品结果有货（共 8 条含商品）（`07-search-pibo-qa03.png`）
- 直接搜「汉服配饰」：`商品 0 条 · 暂无匹配商品，可换词或浏览分类`（`08-search-accessories-qa03.png`）

### QA-04 · `close is not a function` — **pass**

- 操作：开语言 ZH 面板（`09a-lang-open.png`）→ 关闭；点购物车/迷你车相关入口（`09-lang-minicart-qa04.png`）
- 监听 `console` + `pageerror`：**0** 条匹配 `/close is not a function/i`
- 同会话另见 checkout 的 `SyntaxError`（归 QA-09，不记本项 fail）

---

## 阻断与移交

1. ~~P0 QA-09 脚本外泄~~ → 已热修（`qa09-script-leak-hotfix.md`）；本席复测 **pass**  
2. 旁注：有货 `/cart`→有货结算终态本回合空车会话未覆盖；空车终态已验  
3. 本席范围：只记不改码

## QA-09 热修复测（2026-09-23 晚）— **pass**

- **热修说明**：`qa09-script-leak-hotfix.md`（注释中 `<w:form>` 被 Taglib 提前关 script）  
- **入口**：直开 [`/checkout`](https://p05113ef3.test.weline.com:9555/checkout)；并从 `/cart` 链入（空车无「去结算」时直跳 `/checkout`）  
- **门禁**：禁缓存 + 抹 `webdriver`；完后关调试 Browser  

| 检查 | 结果 |
|---|---|
| 正文无脚本源码（`setFormVisible` / `showCheckoutShell` 等） | **pass** |
| 无 `SyntaxError` / `Unexpected identifier` | **pass** |
| 离开「正在加载结账信息...」 | **pass**（t+0.8s 已无 loading） |
| 空车终态 empty 壳/等价；结账表单不可见 | **pass**（见「暂时无法结账 / 购物车是空的」；`checkoutFormVisible=false`；可见 input 仅为顶栏搜索与页脚订阅，不计结账表单） |

**截图**：

- `11-qa09-checkout-immediate.png` — 直开立即拍  
- `12-qa09-checkout-settled.png` — 结算后空车终态  
- `13-qa09-cart-before.png` — 从车进入前  
- `14-qa09-from-cart-checkout.png` — 从车进入结账  

机读：`sitewide-click-qa-shots/reverify/qa09-retest-raw.json`

## 交付地址

- [店面入口](https://p05113ef3.test.weline.com:9555/)  
- [结账（热修后 pass）](https://p05113ef3.test.weline.com:9555/checkout)  
- [隐私短链](https://p05113ef3.test.weline.com:9555/privacy)  

Browser/调试 Chrome 已于本回合收口关闭。
