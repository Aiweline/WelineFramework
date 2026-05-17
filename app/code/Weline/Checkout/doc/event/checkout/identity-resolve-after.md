# 结账身份解析后

在 `CheckoutIdentityService` 标准化账户结账/匿名结账选择之后触发。

事件数据包含 `context` 和 `identity`，模块可补充或覆盖身份结果。
