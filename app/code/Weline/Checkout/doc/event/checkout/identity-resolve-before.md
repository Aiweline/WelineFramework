# 结账身份解析前

在 `CheckoutIdentityService` 标准化账户结账/匿名结账选择之前触发。

事件数据包含 `context`，模块可调整 `checkout_mode`、`guest_allowed`、`customer_allowed`、`guest_email` 等上下文。
