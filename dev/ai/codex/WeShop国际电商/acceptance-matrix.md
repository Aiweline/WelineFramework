# Acceptance Matrix

- 前台密码登录、Google 登录、2FA 登录通过
- 后台密码登录、Google 登录、2FA 登录通过
- 统一认证 API 可发 token、刷新、校验 challenge、登出，按 `/api/weshop/rest/v1/auth/*` 验证
- `default` 主题下登录、购物车、结账、订单、RMA、Review、QA 可访问
- 缺失 hook 或 slot 时编辑器和 `w_msg()` 有告警
- Checkout page gets payment methods from `w_query('payment', 'getCheckoutPaymentMethods', ...)`
- Checkout `default` layout variants render controller/page `content` first so dynamic payment UI works across all checkout variants
- Place-order flow no longer calls missing methods; it validates checkout data, creates an order, and returns structured payment result data
