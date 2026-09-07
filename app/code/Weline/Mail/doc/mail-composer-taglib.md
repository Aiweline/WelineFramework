# mail-composer Taglib 使用指南

企业邮箱全局写信/业务线程浮层，对标 FileManager 选择器：页面挂壳后任意处用 JS API 打开。

## 标签

```html
<w:mail-composer area="backend"/>
<!-- 或 -->
<w:mail-composer area="frontend"/>
```

| 属性 | 必填 | 说明 |
|------|------|------|
| `id` | 否 | 根节点 id，默认 `weline-mail-composer` |
| `area` | 否 | `backend` / `frontend` / `auto`，影响 thread/send 默认路径 |

静态资源在 Taglib callback 内通过 `fetchTagSource` 解析，**禁止**裸 `@static(...)`。

## JS API

```js
WelineMailComposer.open({
  to: 'buyer@example.com',
  subject: '询价回复 #1',
  body: '……',
  account_id: 1,
  source: 'product_quote',
  source_id: 1,
  mailboxes: [{ account_id: 1, email: 'support@x.invalid', is_fake: true }],
  read_only: false
});
WelineMailComposer.close();
```

- 仅关闭按钮可关（忽略 Esc / 点遮罩）。
- 发信走 POST JSON，禁止把正文塞进 GET query。

## 业务来源线程

- 落库字段：`MailMessage.source` / `source_id`
- Query：`mail.listThreadBySource` / `mail.sendComposerMessage`
- 成功派发 `Weline_Mail::mail_message_sent`（变量引用载荷）

## 调用约定

- 询价后台 / 个人中心询价：必须 `<w:mail-composer/>` + `WelineMailComposer.open`
- **禁止**在 Product/Customer 再造写信弹窗 DOM

## 相关

- 场景映射：`Taglib/doc/场景映射表.md`
- 自定义标签：`Taglib/doc/如何自定义Tag.md`
- 事件：`doc/event/mail_message_sent.md`
