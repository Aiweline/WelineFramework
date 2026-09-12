# Hook: account.sidebar.group.commerce / header-account-links / account.sidebar.content

## Owner
`Weline_B2B`

## Purpose
- 侧栏与头像下拉提供 `#b2b-order-chat` 入口空壳（`data-account-menu-signal="b2b.order_chat"`，SSR 不渲染数字）。
- `account.sidebar.content` 在 `b2b-order-chat` section 渲染订单沟通列表（与批发身份同文件、门禁分流）。

## Types
仅挂既有账户/header Hook；第三段 type 为 `partials`/`layouts` 语义下的账户壳，不发明他人 Owner 功能名。

## Related
- `AccountMenuSignalProvider` code=`b2b.order_chat`
- Query `b2b.orderChat.*`
