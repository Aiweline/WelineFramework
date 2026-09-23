# UI 验收 · 企业邮箱「邮箱」页（Team:UI:）

日期：2026-09-22  
主文件：`app/code/Weline/Mail/view/templates/Backend/Index/enterprise.phtml`  
线稿：`channel/ui-shentu-mailbox-20260922.md`  
对照 fail 纪要：`channel/acceptance-test-mailbox-browser.md`  
席位：Team:UI:（视觉层次）· 可与前端席协调结构/交互细节  
**未扮演测试席签收**

## 返工（对照测试 Browser fail #1 / #4）

| # | fail 点 | 返工落盘 |
|---|---|---|
| **#1** | Stalwart 仅 badge + 单链「去域名与 DNS」，无「配置引擎」 | 未就绪时 Hero 右侧改为 `w-alert` 警告条；**两个** CTA：「去域名与 DNS」→ `view=domains`；「配置引擎」→ `view=domains#mail-engine-setup`（domains 页顶有引擎安装说明卡） |
| **#4** | 写信仍为 `<details>/<summary>`（展开/收起写信） | **mailbox 主路径已去掉 details/summary**；仅当 `compose=1`（或深链预填）时渲染独立 `w-card`（标题「写邮件」+「取消」回无 compose 的 mailbox URL）；工具条「写邮件」深链 `compose=1#mail-compose` 保留 |

自检：mailbox 区块内不得出现写信用的 `details`/`summary`（accounts/domains 其它折叠表单不在本清单范围）。

## 返工（用户附图 · 主标题重复 · ui_shot）

| 点 | 根因 | 落盘 |
|---|---|---|
| 顶部「企业邮箱管理」出现两次 | 布局壳/菜单已设页标题；页内又写 `h1.w-backend-page__title` | **已删除**页内重复 h1；heading 仅保留副文案说明 + Stalwart 告警行；面包屑保留，不叠第三个同文案大标题 |

## 原型调整（已落盘）

| 区域 | Variant |
|---|---|
| 页头 | **主标题仅布局壳一处**；页内 = 说明文案 + Stalwart 状态/告警 |
| 主导航 | `w-tabs` + `data-variant="soft"` |
| 工具条 | 当前邮箱 select · 文件夹 pill · 「写邮件」primary（compose 打开时工具条改为「取消」） |
| 写信 | `compose=1` → 独立 `w-card`；取消 = 无 compose 的 mailbox URL |
| 工作区 | 列表 + 阅读双栏 + 空态 CTA |
| Stalwart | `w-alert` + 双行动链 |
| Token | `--weline-*` / `w-*` |

## E / F / G 自检（UI 席，非测试签收）

| 项 | 结论 | 说明 |
|---|---|---|
| **E 人性化** | **pass（UI 自检 · 返工后）** | 双 CTA；compose 卡片+取消；空态下一步仍在；主标题不重复 |
| **F 规范美观** | **pass（UI 自检 · 返工后）** | 无 mailbox 写信 summary；无页内第二主标题；警告条替代孤立 badge |
| **G 主题契合** | **pass（UI 自检）** | `w-backend-page` / `w-alert` / `w-card` / `w-button` / `w-tabs` |

## 截图对照清单（给测试席复测）

1. Hero：**页内不重复主标题**——「企业邮箱管理」仅出现在布局壳标题（及面包屑惯例），页内无第二处同文案大标题/h1；副文案说明 + 未配置时 **`w-alert` 双 CTA**「去域名与 DNS」「配置引擎」。
2. 主导航三 Tab soft segment，「邮箱」为 current。
3. 工具条：当前邮箱 · 文件夹 · **写邮件** primary（默认态无 ▶ / 无「展开写信」summary）。
4. 点「写邮件」→ URL `compose=1` → **独立 w-card**（标题「写邮件」+「取消」）；**无** DisclosureTriangle / details；取消回到无 compose 的 mailbox。
5. 空文件夹：左双 CTA；右引导；双栏同屏。
6. 当前邮箱为人读邮箱/显示名，无「用户 #1」。

## i18n

- 本波仅删页内 h1 引用，说明串仍被使用 → CSV 未改、未重跑 collect

## notify_pm

`notify_pm: true`

@项目经理：UI 席已修用户附图「企业邮箱管理」双标题问题——去掉 `enterprise.phtml` 页内 h1，保留副文案 + Stalwart 双 CTA；验收清单已加「页内不重复主标题」。请测试席复测时顺带核对（UI 席不签收）。
