# Hook: account.sidebar.group.commerce / header-account-links / account.sidebar.content

## Owner
`Weline_B2B`

## Purpose
- 侧栏与头像下拉提供 `#b2b-identity` 入口（挂单跟进 + 订单沟通）；旧 `#b2b-order-chat` 仅跳转壳。
- **订单沟通手风琴**：`partials/order-chat-accordion.phtml` + `js/order-chat-accordion.js`；挂在账户 hang/身份枢纽、**定金挂单行内操作区**（`b2b-hang-order-ops`，非独立 colspan 行）、后台订单详情（部件 `b2b-backend-order-chat` → 槽 `backend-order-b2b-chat`）。
- 前后台共用 Query `b2b.orderChat.*`（customer / merchant 由会话分流）。

## Types
仅挂既有账户/header/Order Hook；第三段 type 为 `partials`/`layouts` 语义下的账户壳，不发明他人 Owner 功能名。

## Related
- `AccountMenuSignalProvider` code=`b2b.order_chat`
- Query `b2b.orderChat.*`
- Hook `Weline_Order::backend::order::view::after`
