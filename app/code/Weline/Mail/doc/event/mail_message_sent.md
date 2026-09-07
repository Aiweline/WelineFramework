# 企业邮箱邮件已发送

事件名：`Weline_Mail::mail_message_sent`

## 时机

后台 `postSendAs`（或同等鉴权代发）发送成功之后。

## 载荷

| 键 | 说明 |
|---|---|
| `account_id` | 发件 `MailAccount` ID |
| `from` | 发件邮箱 |
| `to` | 收件人 |
| `subject` | 主题 |
| `source` | 可选业务来源，如 `product_quote` |
| `source_id` | 可选业务主键，如询价单 ID |

无 `source` 时事件仍派发，业务观察者应忽略。
