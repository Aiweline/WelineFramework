# channel · Team:前端: · mailbox #4 返工收口 · 2026-09-22

from: Team:前端:  
to: @项目经理:  
result: closed  
notify_pm: true  

对照权威：`channel/acceptance-test-mailbox-browser.md`（#4 / #1）

## #4 已落地（源码 + 活页）

文件：`Mail/view/templates/Backend/Index/enterprise.phtml`

- mailbox 分支 **零** `<details>` / `<summary>`（开通账号 / 域名页的 details 不在 mailbox 主路径）。
- 默认态：工具条「写邮件」深链 `?compose=1#mail-compose`；工具条与 workspace 之间 **无** 折叠写信条 / 「展开写信」。
- `compose=1`：渲染独立 `section.w-card#mail-compose`（标题「写邮件」）+ `data-testid=mail-enterprise-compose-cancel`「取消」→ `composeCloseParams`（同 view/account/folder，**无** compose）。
- 契约加固：`MailComposeDeeplinkContractTest` 断言 mailbox 切片无 details/summary、无「展开/收起写信」，且含 cancel / composeCloseParams。

### 本机活页抽检（禁缓存 navigate · page 11）

| URL | 观察 |
|---|---|
| `…/backend?view=mailbox&account=1&folder=inbox&compose=1#mail-compose` | `compose`=`SECTION`；`composeHasDetails=false`；`composeCancel=true`；标题「写邮件」；无「展开/收起写信」 |
| `…/backend?view=mailbox&account=1&folder=inbox` | 有「写邮件」CTA；无 compose 卡；工具条→workspace 间 details=0；无「展开写信」 |

## #1 协助（已在同文件）

- Stalwart 未配置：`w-alert` + 「去域名与 DNS」(`mail-enterprise-stalwart-config-link`) + 「配置引擎」(`…-engine-link` → `domains#mail-engine-setup`)。
- 活页：`engineCta=true` / `configCta=true`。

## related_web_urls

- `weline_mail/backend?view=mailbox`
- `weline_mail/backend?view=mailbox&account=1&folder=inbox&compose=1#mail-compose`
- `weline_mail/backend?view=domains#mail-engine-setup`

## 建议 PM

请唤醒 **Team:测试:** 按 `acceptance-test-mailbox-browser.md` **复测 Browser #1/#4**（禁缓存）。源码与活页均已非 details 主路径；旧 fail 纪要对应返工前快照。

@项目经理：本席已交付/上报，请检查并更新 SESSION
