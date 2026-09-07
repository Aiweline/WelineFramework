# Product 自研商品询价

## 边界

- 归属：`Weline_Product` 交易路径能力。
- **禁止**依赖 `Weline_Inquiry`、PDP 嵌 `<w:inquiry>`、跨模块 Inquiry Provider。
- 范围：前台提交落库 + 后台列表/状态流转 + 个人中心「我的询价」与 JS 菜单角标。

## 状态机

状态：`new` → `processing` / `replied` / `processed` / `cancelled`；`processing` → `replied` / `processed` / `cancelled`；`replied` → `processing` / `processed` / `cancelled`；`processed`/`cancelled` 终态。

服务：`ProductQuoteRequestStateMachine`（`canTransition` / `transition`）。

事件：

- `Weline_Product::quote_request_submitted`
- `Weline_Product::quote_request_status_can_transition`
- `Weline_Product::quote_request_status_change_before`
- `Weline_Product::quote_request_status_changed`

流转到 `replied`/`processed` 且尚无 `admin_reply_at` 时写入，供未读角标。

## 前台 / 个人中心

见既有 PDP 弹层提交与账户 `#product-quotes`（菜单码 `product.quotes`）。SSR 不渲染角标条数。

PDP「提交询价」弹层含 `<w:theme:address postal="true" postal-lookup="true" detail="true">`；邮编反查由 Theme 地址标签内置。提交字段 `address` → 落库 `address_json`。

## 后台

- 模板：`view/templates/Backend/QuoteRequest/index.phtml`（`fetch('index')`）
- 操作：按可用流转按钮 POST `*/backend/quote-request/transition`；表单须 `w:form` + `csrf="auto"`
- **企业邮箱回复 slot**（`Weline_Mail` 可选启用时）：
  - 普通客服：本人资料 email 命中本机 active 邮箱 →「企业邮箱回复」打开 `<w:mail-composer>` 浮层（`WelineMailComposer.open`）
  - 超管（`user_id=1`）或 `Weline_Mail::mail_send_as`：可下拉任选本机邮箱（含 fake 测试号）代发
  - 非本机邮箱：提示外部发送 + `mailto:`
  - 代发成功且 `source=product_quote` → 观察 `Weline_Mail::mail_message_sent` 自动 `replied`
  - **禁止** compose GET 深链塞 body（易 Bad Request）；见 Mail `doc/mail-composer-taglib.md`
- 新增 Controller action 后需 `php bin/w s:up --route` 注册路由（否则 POST 404）
- `markProcessed` 兼容直达 `processed`
- ACL：`Weline_Product::commerce:catalog:quote-requests`

## 非目标

- 不接入 Inquiry；不把询价转订单；不把角标 SSR 进 FPC。
