---
name: backend-menu-signal
description: >-
  后台侧栏菜单消息角标与深链。用户说菜单消息、菜单角标、侧栏未读、
  邮箱消息传导到菜单时读取本技能。归属模块用 BackendMenuSignalProvider
  写未读收件箱；点开进具体记录。订单与企业邮局都走这条，不是 SMTP 或通知渠道。
---

# 后台菜单消息提示

菜单侧栏角标与可点深链。机制在 `Weline_Admin`，条数和链接由归属业务模块自己写。企业邮局的未读信也走这里，不要改用 SMTP 或站内通知渠道来画角标。

## 归属

| 层 | 模块 |
|---|---|
| 机制 | `Weline_Admin`：`BackendMenuSignalProvider` + 可选 `BackendMenuSignalItemsProvider` |
| 绘制 | `admin.menuSignals` + `backend-menu-signals.js`（父级自动把子叶数量往上加） |
| 业务条数与链接 | **归属业务模块**自己的 Provider（订单 `Weline_Order::order_list`，企业邮局 `Weline_Mail::mail`） |

## 何时用

后台某个菜单在有**未读新消息**时要在侧栏提示，并且点开能进到具体记录。已读和历史单据不要出现。

## 做法

1. 在归属模块新增  
   `extends/module/Weline_Admin/BackendMenuSignalProvider/{Name}Provider.php`
2. 实现 `BackendMenuSignalProviderInterface`：
   - `code()` **必须等于**该叶菜单的 ACL `source_id`（形如 `Vendor_Module::name`）
   - `count($backendUserId)` 只返回未读条数；无登录用户返回 `0`
3. 角标只表示**未读**。不要把全部待办/历史单据都算进去。
4. 写入统一收件箱 `Weline\Admin\Service\BackendMenuSignalInbox`：
   - `publish($code, $itemId, $title, $href, $unread)` 新消息 `$unread=true`
   - 打开或标已读时 `markRead($code, $itemId, $backendUserId)`。用户 ID 用 `BackendUserContext`（与 `admin.menuSignals` 同一人）
   - 打开对应记录后立刻 `Weline.BackendMenuSignals.noteViewed()`，并 `refresh()`。不要等 30 秒轮询才消角标
   - 用户把当前这条再标成未读时 `markUnread`，并 `releaseViewed()`。`publish` 不会把已读行翻回去
   - 列表只用 `unreadItems()` / `unreadCount()`
5. `items()` 每条必须带 `unread: true`。没有这个标志，或已读，聚合器直接丢掉，角标不加。
   - `href` 只用归属模块自己的后台 URL（`UrlInterface::getBackendUrl`）
   - 条数上限用 `BackendMenuSignalAggregator::MAX_ITEMS_PER_CODE`
6. `Weline_Admin` 标成 **optional** 依赖。不要在 Admin 的 JS 里写业务路径。
7. 改完跑 `php bin/w extends:rebuild`（或 `setup:upgrade`），让 `generated/extends.php` 收进新 Provider。禁止手改 `generated/`。新表随 Admin `setup:upgrade` 建出。

## 逐级往上

父菜单不要再写一份计数。叶菜单的 `source_id` 有信号后，侧栏 JS 会把子叶数量加到没有自己信号的祖先菜单上。点父级角标，面板列出子叶的 `items` 深链。

## 禁止

- 用「全部未完成单据」冒充未读（历史订单、旧会话不要一上来全刷进角标）
- 用 SMTP / 站内通知渠道 Provider 代替菜单角标
- 把邮局未读信直接画进 Admin 的 JS，而不走归属模块 Provider
- 把未读数字写进侧栏 HTML（禁止 SSR；只许 JS 画）
- 在 `backend-menu-signals.js` 里拼订单、会话等业务 URL
- `code()` 用点号账号菜单码（那是前台 `AccountMenuSignal`）

## 订单例子

`Weline_Order`：新订单创建时 `publish(..., unread: true)`。后台打开该订单后 `markRead`。角标里只剩这个人还没看过的新订单。`href` 指向 `order/backend/order/view?id=`。父菜单仍靠子叶汇总。

## 企业邮局例子

`Weline_Mail`：收件箱里仍未读的信，在菜单信号刷新时 `publish` 进同一收件箱，`code` 为 `Weline_Mail::mail`。`href` 指向 `weline_mail/backend?view=mailbox&account=&folder=inbox&message=`。打开正文或标已读时 `markRead`；用户再标未读时 `markUnread`。已读和历史信不要整夹刷进角标。父菜单仍靠子叶汇总。
