# Checkout i18n guard

## 背景

2026-05-24 排查英语环境结账页仍显示中文时，发现以下根因：

- `WeShop_Notification` 挂到 checkout 的通知渠道 hook 使用数字 HTML 实体输出中文，绕过了 `__()` 和语言包。
- `WeShop_Checkout` 新增账户结账登录弹窗和状态提示后，只写了中文 source key，未同步补齐 `zh_Hans_CN.csv` 与 `en_US.csv`。
- checkout 页面会消费 `WeShop_Shipping` / `WeShop_Payment` 运行时 method 文案；当前请求词典不一定包含这些跨模块词条，因此 checkout 字典需要覆盖实际可见 method 文案。
- 购物车商品通过 `w_query('product', 'getProductByIds')` 进入 checkout 时，必须加载 product local description，不能直接使用商品主表中文名。

这说明仅靠全局文字规则不足以稳定约束交付，checkout 可见文案需要可执行检查。

## 约定

- checkout 页面、hook、弹窗、JS 注入文案必须走 `__()` 或框架等价 i18n 入口。
- 默认 source/key 使用简体中文；`zh_Hans_CN` 保持中文，`en_US` 必须给出英文，不允许中文 source 在 `en_US` 中仍映射为中文。
- 禁止用 `&#12345;` 这类数字 HTML 实体隐藏中文可见文案。
- 修改 checkout 可见文案后，至少运行：

```bash
php vendor/bin/phpunit --no-coverage tests/unit/WeShop/Checkout/CheckoutPageI18nCoverageTest.php
```

## 覆盖范围

当前守护测试覆盖：

- `WeShop_Checkout` 结账/成功页模板、前台 controller、checkout service、checkout query provider。
- checkout 运行时运输/支付 method 文案在 `WeShop_Checkout` 字典中的覆盖。
- `WeShop_Notification` 注入 checkout 的通知渠道 hook。
- `Weline_Checkout` checkout hook 元数据。
