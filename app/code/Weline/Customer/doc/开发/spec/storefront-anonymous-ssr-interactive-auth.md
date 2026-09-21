---
status: ready-for-plan
work_kind: feature
feature_slug: storefront-anonymous-ssr-interactive-auth
module: Weline_Customer
updated: 2026-09-21
fe_be_scope: both
plan_complexity: complex
---

# 店面 SSR 匿名与交互才鉴权

## 澄清记录

| Q | A |
|---|---|
| SSR 与登录关系 | 公共页 HTML/文案与登录无关；恒游客壳 |
| Cookie | SSR/静默不发身份 Cookie；Account Session 仅 Account BinQuery；cart guest_token 仅加购交互 |
| 静默浏览 | 全部浏览器侧展示；零身份后端请求 |
| 交互 | 加购/账户/个人中心/切 ToB 等才 BinQuery |
| 需登录 | Account `ensureLogin` → `customer/login-panel` |
| B2B | 域逻辑在 `selling-mode.js`；不进 Account.js |
| 批发价静默 | 接受 toc/游客壳；切 ToB/加购后再出批发价 |
| keepalive | 取消静默续期；401 → ensureLogin |
| 编辑器 | 允许 force 对账例外 |

## 非目标

- 后台 SSR/登录
- 账户私有页 `customer/account/*` 的 Session SSR（可保留）
- 打开已登录用户写入共享 FPC

## 用户故事

### US-1 游客/已登录用户静默浏览公共页

As a 店面访客, I want 公共页首屏不依赖登录态也不自动请求账户接口, so that FPC 可共享且首屏更快、更干净。

**EARS**

1. WHEN 用户打开公共店面页且无交互 THEN 系统 SHALL 输出与登录无关的游客壳 HTML，且响应不含身份 Set-Cookie。
2. WHILE 用户仅浏览未操作 THEN 浏览器 SHALL 不发起 `account.*` / `membership.*` / 身份相关 query-bin。
3. IF localStorage 存在会话快照 THEN 顶栏 SHALL 可仅用本地快照展示（可陈旧），且仍不自动打网。

### US-2 交互才鉴权与登录窗

As a 店面用户, I want 在加购或进入需登录能力时再与后端交互并在需要时弹出登录窗, so that 未操作时不被打扰。

**EARS**

1. WHEN 用户点击账户相关入口或触发需登录的加购/批发操作 AND 本地未登录 THEN 系统 SHALL 通过 Account `ensureLogin` 打开 `customer/login-panel`。
2. WHEN 交互触发的 BinQuery 返回未登录/401 THEN 系统 SHALL 调用 `ensureLogin` 后再允许用户继续。
3. IF 用户完成登录 THEN 系统 SHALL 派发既有登录事件并更新本地快照与相关 UI。

### US-3 B2B 售卖模式

As a 批发访客, I want PDP 首屏固定零售游客壳且切 ToB 后再拉会员/批发价, so that SSR 可缓存且不静默打网。

**EARS**

1. WHEN PDP 首屏渲染 THEN B2B 部件 SHALL 固定 `data-customer-logged-in=0` 与 toc 壳，不读 Session。
2. WHEN 用户切换 ToB 或打开申请抽屉或批发加购 THEN B2B SHALL 才请求 `membership`/相关 BinQuery。
3. IF 操作需登录 THEN B2B SHALL 调用 Account `ensureLogin`，不得自造第二套登录窗。

## 隐形需求摘要

- 复用现有 guest SSR 页头/迷你车约定与 FPC serve-guest 策略。
- Taglib/Hook/BinQuery 既有加载规范；禁止页载裸 fetch。
- Theme 编辑器预览需 force 对账例外，避免画布账户错态。

## 用例

### UC-1 静默首屏零身份请求

| 字段 | 内容 |
|------|------|
| 角色 | 游客或本地有快照的已登录用户 |
| 前置 | 公共首页或 PDP；无 `w_auth` |
| 主成功步骤 | 1. 打开公共页 2. 观察 Network 3. 顶栏若有本地快照可显示已登录外观 |
| 备选/异常 | 无快照 → 顶栏保持游客壳 |
| 期望结果 | 无 query-bin/account/membership；HTML 为游客壳；无身份 Set-Cookie |
| 映射 | e2e silent-first-paint |

### UC-2 点击账户触发登录或对账

| 字段 | 内容 |
|------|------|
| 角色 | 游客 |
| 前置 | 公共页顶栏游客壳 |
| 主成功步骤 | 1. 点击需登录的账户入口 2. 出现 login-panel 3. 登录成功顶栏切 signed-in |
| 备选/异常 | 取消登录 → 保持游客 |
| 期望结果 | 仅在点击后出现 Account BinQuery；登录成功派发事件 |
| 映射 | e2e ensure-login-panel |

### UC-3 切 ToB 才拉会员

| 字段 | 内容 |
|------|------|
| 角色 | 已登录会员（本地快照） |
| 前置 | PDP 含 selling-mode；首屏 toc |
| 主成功步骤 | 1. 确认首屏无 membership 请求 2. 点击 ToB 3. 出现 membership/相关请求并可显示批发态 |
| 备选/异常 | 未登录点 ToB → ensureLogin |
| 期望结果 | 静默无 membership；交互后才有 |
| 映射 | e2e b2b-interactive-membership |

## 验收意图

- `type=e2e`：silent-first-paint、ensure-login-panel、b2b-interactive-membership（可合并章节）
- `e2e-plan-suite`：计划组套件覆盖上述主路径
- UT：Template auth 恒 guest；部件禁止 Session；JS 契约无页载自动 current/membership/visibility fetch

## 就绪检查

- [x] status = ready-for-plan
- [x] ≥1 用户故事 + 每故事 ≥2 EARS
- [x] ≥1 用例（含主成功）
- [x] 非目标明确
- [x] 已点名 e2e / plan-suite 意图
- [x] 未把补丁步骤写进规格 what
