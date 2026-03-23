# Roadmap

## Wave 1

- 统一认证对象与 challenge 流程
- GoogleAuth 模块骨架
- 前台客户密码登录、注册、找回密码重构
- 统一 API 认证入口

## Wave 2

- 后台登录扩展点
- 后台 Google 绑定与登录
- 2FA 编排接入前后台与 API
- 兼容旧 API 代理

## Wave 3

- 主题兼容检查与告警
- 后台 IA 与菜单资源
- 代表性交易链模块补齐
- 测试矩阵完善

## Current Execution Notes

- Checkout payment methods must be provided through `w_query`, not hardcoded in controllers or theme layouts.
- New frontend-facing slices should avoid adding extra `Frontend` path layers when new route files are introduced; existing legacy controllers can be refactored in place.
- `default` theme checkout, account center, recommendations, and related storefront layouts should prefer rendering controller/page `content` through shared layout shells instead of duplicating module business UI in the layout file.
- When a theme layout is missing required hooks or slots, WeShop should patch the `default` theme where possible and later surface compatibility warnings rather than coupling modules to one theme implementation.
- Payment wave priority is:
  - `manual_transfer`
  - `cash_on_delivery`
  - `paypal`
  - `alipay` / `wechatpay` scaffolded but disabled by default until gateway completion
