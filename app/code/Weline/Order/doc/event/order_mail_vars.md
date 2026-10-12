# Weline_Order::order_mail_vars

订单邮件发信前变量扩展点（`OrderMailNotifier::enrichMailVars`）。

## 载荷

| 键 | 类型 | 说明 |
|----|------|------|
| `order` | `Weline\Order\Model\Order` | 当前订单 |
| `channel` | `string` | 邮件渠道（如 `Weline_Order::order_paid`） |
| `vars` | `array` | 可写；回写后进入 `smtp.send` |

## 约定

- 观察者只增补文案 / 深链 / HTML 片段，不得改支付状态或金额事实。
- 失败须吞掉并打日志，不得阻断主发信。
