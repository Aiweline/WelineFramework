# Team:测试: UC-1 验收 — required-default-always-present

> seat：`Team:测试:`  
> UC：UC-1（游客打开登录页须含 `account-social-login`）  
> result：**closed**  
> notify_pm：**true**  
> @项目经理：本席 UC-1 curl + Browser 已过，请收口 SESSION / 汇审

---

## 结论

**PASS。** 游客态登录页出站 HTML 与 Browser DOM 均含必装部件与槽位；快捷登录区可见 Google / Facebook。

---

## 1) curl 探活（字面 URL）

URL：`https://p05113ef3.test.weline.com:9555/customer/account/login`

| 断言 | 结果 |
|------|------|
| HTTP | **200**（可接受） |
| `data-widget-code="account-social-login"` | **1** |
| `data-w-component="account-social-login"` | **18**（字符串计数；非 CSS 选择器误计） |
| 部件标记合计（code ∪ component） | **19 ≥ 1** |
| `data-slot-id="account-login-social-providers"` | **1** |
| 正文含 Google / Facebook | **True** |

备注：HTML ≈ 866648 bytes；无 Cookie 请求 → 游客态。

---

## 2) Browser WB-OP（cursor-ide-browser）

| 门禁 | 执行 |
|------|------|
| 非抢占 | `browser_navigate` **省略** `position` |
| 禁缓存 | `Network.enable` → `Network.setCacheDisabled({cacheDisabled:true})` |
| 抹自动化 | `Page.addScriptToEvaluateOnNewDocument` → `navigator.webdriver` 实测 **false** |
| 游客 | 先确认登出（页头 Log in），再打开登录 URL |

### 观察

- 最终 URL：`https://p05113ef3.test.weline.com:9555/customer/account/login`
- 标题：`Log in`
- a11y：`Sign in with Google`、`Sign in with Facebook`；区标题 `Quick sign-in`
- CDP DOM：
  - `[data-widget-code="account-social-login"]` = **1**
  - `[data-w-component="account-social-login"]` = **1**
  - `[data-slot-id="account-login-social-providers"]` = **1**
  - root `data-testid="account-social-login"` 存在
- 截图：快捷登录区可见 Google / Facebook 入口（事件监视浮层无关 UC）

交付后：本回合 Browser **已 unlock + close**。

---

## 3) UC 映射

| UC | 期望 | 实测 |
|----|------|------|
| UC-1 | 游客登录页 DOM 含 account-social-login；凭据激活时可见 Google/Facebook | **PASS** |

未改生产实现；未向用户索要测试/凭据；本任务无 UI 设计变更（跳过 ui_prototype_gate）。

---

## 交付元数据

| 字段 | 值 |
|------|-----|
| result | closed |
| notify_pm | true |
| plan_id | test-login |
| related_web_urls | `https://p05113ef3.test.weline.com:9555/customer/account/login` |
| verified_at | 2026-09-22T21:55+08 |
