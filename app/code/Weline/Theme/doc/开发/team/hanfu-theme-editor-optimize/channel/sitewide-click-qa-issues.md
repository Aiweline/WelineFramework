# hanfu 全站点击走查 · 问题记录（先记后修）

日期：2026-09-23  
入口：`https://p05113ef3.test.weline.com:9555/`  
方法：点击后立即截图；记录页面可见错误（异常页、空白、500、弹层报错、明显错版、JS site_error）  
状态：**修复中**（计划见 `sitewide-qa-fix-plan.md`；Wave 0～2 施工中）

## 问题清单

### QA-01 · 运行时入口不稳定（阻断）
- **状态**：**框架侧已修**（见 `qa01-fiber-ob-fix.md`；UT 绿；待 WLS 压力浏览复验）
- **操作**：分类跳转 / 连续浏览
- **现象**：Browser 黑屏 `chrome-error://`；curl `Connection refused` / `Empty reply` / SSL timeout；Worker 间歇死亡
- **日志**：`FiberOutputBuffer::appendToFrame(): Cannot use output buffering…`（E_ERROR，Memory 511MB）
- **严重度**：阻断

### QA-02 · 「订单跟踪」未登录进登录页（中）
- **操作**：顶栏「订单跟踪」→ 立即截图
- **现象**：顾客登录页；无游客订单号追踪表单
- **严重度**：中

### QA-03 · 热搜「汉服配饰」无商品结果（高）
- **状态**：**已修**（热词→披帛；空商品节明示 0；分类 demo「汉服配饰」→`/category/accessories`；见 `qa03-qa04-ui-search-fix.md`）
- **操作**：分类页热搜点「汉服配饰」→ 立即截图
- **现象**：搜索页「共 2 条」仅博客 + FAQ（同标题重复）；**无商品**
- **URL**：`/search?q=汉服配饰`（表观）
- **严重度**：高（核心类目词搜不到货）

### QA-04 · 前端 JS：`get(...)?.close is not a function`（高）
- **状态**：**已修**（不完整 menu 不注册；`typeof close === 'function'`；见 `qa03-qa04-ui-search-fix.md`；待 Browser site_error 清零）
- **证据**：访客像素 `site_error` / eventChains 已落库：`Uncaught TypeError: get(...)?.close is not a function`
- **现象**：用户侧可能无大红字，但控制台/行为监控持续报错（关抽屉/弹层类交互）
- **严重度**：高

### QA-05 · 购物车页同时存在「空车 / 加载失败 / 有货」状态文案（高）
- **状态**：**已修**（`data-cart-view` 互斥壳；见 `qa05-qa09-cart-checkout-fix.md`；待 Browser 深点）
- **操作**：打开 `/cart` → 立即截图 + a11y
- **可见文案并存**：
  - 「购物车是空的」
  - 「暂时无法加载购物车」/「购物车加载失败，请重试。」
  - 同时展示商品「长乐公主」、小计 $20.11、「去结算」
- **严重度**：高（用户可见错误文案 + 状态机混乱）

### QA-06 · products 布局编译：`Undefined variable $content`（中）
- **状态**：**框架侧已修**（`parseVarExpression`→getData 语义；`COMPILER_GENERATION` 已 bump；见 `qa06-qa09-form-taglib-fix.md`）
- **证据**：worker 日志大量 E_WARNING  
  `.../layouts/products/.../com_default.phtml:204`
- **严重度**：中（可能造成列表页残缺/抖动，加重 QA-01）

### QA-07 · 购物车规格文案与主图不一致（低）
- **现象**：行内规格「红色【长乐公主】红色全套」，缩略图偏蓝灰
- **严重度**：低（内容/SKU 图文不一致）

### QA-08 · 可疑路径 `/1` 返回 404（低）
- **操作**：HTTP 探活 `/1`
- **现象**：`404 · 抱歉，找不到您要的页面`
- **严重度**：低（需查首页/部件是否链出）

### QA-09 · 结算页可见「加载中」+「购物车为空」（阻断/高）
- **状态**：**复验 pass**（热修后无脚本外泄；空车终态表单不可见；见 `sitewide-qa-reverify.md` / `qa09-script-leak-hotfix.md`）
- **操作**：购物车点「去结算」→ 立即截图
- **URL**：`/checkout`（从 `/cart` 进入）
- **可见**：
  - 顶条：「正在加载结账信息...」
  - 订单摘要：「购物车为空，请先加入商品。」
  - 同时仍展示收货表单；与购物车页已有「长乐公主」矛盾
- **严重度**：高（结账主路径信任崩）

### QA-10 · 首页维护弹层挡住首屏点击（高）
- **状态**：**已修**（本机 `maintenance:disable` + 关过期波次；见 `qa10-12-13-fix.md`；待 Browser 实点确认）
- **操作**：打开入口 `https://p05113ef3.test.weline.com:9555/` → 立即截图
- **URL**：`/`
- **可见现象**：居中 dialog「抱歉，网站正在升级维护」+「维护补偿礼金」；按钮「稍后再来 / 耐心等待」。未关闭前顶栏/主导航点击被拦截（Click target intercepted by `#weline-maintenance-wait-modal`）。点「稍后再来」后可继续，但同会话内偶发再次挡住。
- **截图**：`channel/sitewide-click-qa-shots/00-home.png`
- **严重度**：高（店面首屏可交互性被挡；本机验收环境是否应开维护态需产品确认）

### QA-11 · 「订单跟踪」无游客追踪表单（复核确认 · 高）
- **操作**：顶栏「订单跟踪」→ 立即截图 + Runtime 可见文本扫描
- **链接目标**：`/customer/account/index#orders` → 未登录跳转登录
- **URL**：`/customer/account/login?referer=https%253A%252F%252F...#orders`（见 QA-12）
- **可见现象**：标准「欢迎回来 / 登录」页；**无**订单号/运单号/游客追踪表单；扫描 `hasGuestTrack=false`。与清单既有 QA-02 一致，本席复核升为「高」（顶栏主入口承诺「跟踪」却强制登录）。
- **截图**：`channel/sitewide-click-qa-shots/02-order-track-login.png`、`19-order-login.png`
- **严重度**：高

### QA-12 · 登录跳转 referer 双重 URL 编码（中）
- **状态**：**已修**（`redirect(..., ['referer'=>…])` 只 encode 一次；见 `qa10-12-13-fix.md`）
- **操作**：未登录点「订单跟踪」或「我的收藏」
- **URL 例**：`/customer/account/login?referer=https%253A%252F%252Fp05113ef3.test.weline.com%253A9555%252Fcustomer%252Faccount%252Findex`
- **可见现象**：query 中 `:` `/` 被编码成 `%253A` `%252F`（双重 encode）；登录后回跳可能失败或落到错误路径。
- **严重度**：中

### QA-13 · FAQ/页脚短链政策 URL 404（高）
- **状态**：**已修**（短链/`create` → 301 规范路径；见 `qa10-12-13-fix.md`）
- **操作**：解析 FAQ/页脚「隐私政策 / Cookie / 退款」等 href → 打开短链
- **失败 URL（HTTP 404，可见「抱歉，找不到您要的页面」）**：
  - `/privacy`
  - `/cookie`
  - `/cookies`
  - `/refund`
  - `/guide/privacy`、`/guide/terms`（错误猜测路径）
  - `/customer/account/create`（注册别名；正确为 `/customer/account/register`）
- **可用对照**：`/policy/privacy`、`/policy/cookie`、`/policy/term-condition`、`/policy/refund`、`/guide/shipping`、`/guide/returns` → 200
- **截图**：`channel/sitewide-click-qa-shots/16-privacy404.png`
- **严重度**：高（合规/政策入口链出死链）

### QA-14 · 分类页 document title 笼统（低）
- **操作**：主导航「女装 / 男装 / 童装 / 配饰 / 套装专区 / 汉服」
- **URL**：`/category/women|men|kids|accessories|sets|hanfu`
- **可见现象**：页面 h1 正确（如「女装」），但 `<title>` 均为「分类 | 长安汉服」，不利于 SEO/标签页辨识。
- **严重度**：低

### QA-15 · 结算页「Back to cart」未译（低）
- **操作**：打开 `/checkout` → 立即截图
- **URL**：`/checkout`
- **可见现象**：中文结账页右上角链接文案为英文 `Back to cart`；同页另见「正在加载结账信息...」与空车提示（与 QA-09 叠加）。
- **截图**：`channel/sitewide-click-qa-shots/13-checkout.png`
- **严重度**：低

### QA-16 · 商品列表首卡 CTA 不一致（低）
- **操作**：主导航「全部商品」→ `/products` 立即截图
- **URL**：`/products`
- **可见现象**：首卡仅「查看详情」，其余卡为「加入购物车 / 立即购买」；无 Fatal/500。可能为可配置商品缺默认规格，但列表 CTA 不一致易误为坏链。
- **截图**：`channel/sitewide-click-qa-shots/03-products.png`
- **严重度**：低

### QA-17 · 无会话直开购物车长时间「正在加载…」（中 · 补证 QA-05）
- **状态**：**并入 QA-05 已修**（启动即 loading 互斥；待 Browser）
- **操作**：直开 `/cart`（无浏览器会话 cookie）→ 截图
- **URL**：`/cart`
- **可见现象**：零售车中心转圈「正在加载购物车...」；交互会话中迷你车曾有「长乐公主」可展示。与 QA-05 状态机混乱相关，补截图证。
- **截图**：`channel/sitewide-click-qa-shots/12-cart.png`
- **严重度**：中

---

## 已覆盖（部分）

首页、帮助中心(FAQ)、订单跟踪→登录、女装分类、热搜搜索、购物车；入口多次红灯中断深点。

## 下一步

计划已并入 `sitewide-qa-fix-plan.md`（含 QA-10～17）。施工中：Wave0～2 + QA-10/12/13 快修席。

---

## 本席走查结束（店面点击席 · 2026-09-23）

**本席新增/复核编号**：QA-10～QA-17（QA-11 为 QA-02 复核升级说明；QA-17 为 QA-05 补证）。  
**本席问题条数（新增独立项）**：**8**（QA-10～17）；其中复核升格不另计重复根因。  
**截图目录**（本地生成、已 `.gitignore`，勿提交）：`channel/sitewide-click-qa-shots/`

### 本席覆盖页面列表
| 区域 | 页面/动作 | 结果摘要 |
|------|-----------|----------|
| 入口 | `/` | 可达 200；维护弹层 QA-10 |
| 顶栏 | 帮助中心 → `/faq` | 正常，无 Fatal |
| 顶栏 | 订单跟踪 → 登录 | 无游客表单 QA-11/02；referer 双编码 QA-12 |
| 顶栏 | 配送至 | 弹层「当前国家暂无地址」可关，无 Fatal |
| 顶栏 | 我的收藏 / 登录 | 未登录进登录页（预期） |
| 顶栏 | 购物车 `/cart` | 加载态/状态混乱 QA-17/05 |
| 主导航 | 全部商品 `/products` | 列表正常；CTA 不一致 QA-16 |
| 主导航 | 女装 `/category/women` | 正常；title 笼统 QA-14 |
| 主导航 | 男装/童装/配饰/套装/汉服 | HTTP+正文扫描无 Fatal；title 同 QA-14 |
| 主导航 | 关于我们 `/about` | 200 正常 |
| 扩展 | 博客 `/blog`、今日特价 `/promotion/deals` | 200 无 Fatal |
| 搜索 | `/search?q=汉服` | 200；热搜「汉服配饰」见既有 QA-03 |
| PDP | `/product/543` → slug | 详情正常（长乐公主） |
| 结算 | `/checkout` | 加载中+空车+英文 Back to cart → QA-09/15 |
| 注册 | `/customer/account/register` | 200「创建账户」；`/create` 404 → QA-13 |
| 政策 | `/policy/*` 正常；短链 `/privacy` 等 404 → QA-13 |
| 页脚/政策 | shipping/returns | 200 正常 |

**未深点（本席）**：语言/货币切换实选、首页加购/立即购买完整闭环、品类圆标逐一点、购物政策下拉全项、客户服务弹层交互（入口可达性已记；深点受 Browser `chrome-error` / 维护弹层干扰，见 QA-01/10）。

**方法备注**：点击后截图 + `Runtime.evaluate` 可见文本扫 Fatal/Uncaught/SQLSTATE/Whoops/系统错误/页面不存在；正文扫描排除 SCRIPT/STYLE 误报。未执行 server:start/stop。

---
