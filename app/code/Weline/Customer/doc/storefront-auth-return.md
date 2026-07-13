# 前台客户认证来源页回跳

`Weline_Customer` 的登录、注册和两步验证共享同一个站内回跳目标。用户从业务页进入认证后，即使在登录、注册或验证页之间切换，认证成功也必须回到最初的业务页。

## 统一契约

- 页面入口优先读取 `redirect_url`，兼容 `redirect` 和 `referer`；没有显式参数时才使用当前请求 Referer。
- `CustomerAuthReturnUrlService` 将通过校验的目标写入前台 Session `login_referer`，登录和注册互相跳转时继续显式传递 `redirect_url`。
- 登录和注册表单都必须携带已规范化的 `redirect_url`，因此 `Weline.Api.resource('account')` 与原生表单回退路径使用同一目标。
- `account.login`、`account.register` 和 `account.completeChallenge` 成功后才消费并删除 Session 目标；失败或页面切换不得提前清除。
- 没有合法目标时回到 `/customer/account`。

## 安全边界

- 只接受站内相对路径或与当前请求完全同源的 `http` / `https` URL；绝对 URL 最终也要收敛为站内路径。
- 首页 `/` 也是合法目标，并保留其 query 和 fragment。
- 拒绝协议相对 URL、站外 URL、用户信息 URL、反斜杠、控制字符、`.` / `..` 路径段和 backend/API area 路径。
- 为防止登录循环，登录、注册、忘记密码、两步验证和退出路由不能成为最终回跳目标。
- 认证路由检查必须使用 `State::resolveLocalizationFromPathSegments()` 的本地化前缀规则，覆盖单货币、单语言和任意顺序的双前缀；返回目标本身保留原货币/语言前缀、query 和 fragment。

## 扩展要求

新增第三方登录、验证挑战或其他认证中间页时，必须继续传递已校验的目标，并在最终认证成功后由 `CustomerAuthReturnUrlService` 消费。不得在 Controller、QueryProvider 或模板中再实现一套手写的同源/路由校验。

## 两步验证可选性

- 两步验证是客户主动启用的可选安全能力，不得在注册或普通登录过程中自动启用或强制绑定。
- 客户必须能在官方个人中心 `/customer/account/index#twofa` 查看状态，并使用当前 6 位动态验证码关闭两步验证；浏览器关闭请求必须走 `Weline.Api.resource('twoFactor').disable()`。
- 关闭成功后，`is_enabled=0` 是登录认证的唯一有效状态：新登录不得创建 challenge，动态验证码和备份码都不得再完成已存在的 challenge。
- 再次启用前，客户按普通密码登录流程认证；成功后仍按本页统一契约回到最初来源网页。

## 验证入口

- 登录：`/customer/account/login?redirect_url=<encoded-storefront-path>`
- 注册：`/customer/account/register?redirect_url=<encoded-storefront-path>`
- 必测链路：业务页 → 登录 → 注册 → 登录，以及业务页 → 注册成功；每一步的 `redirect_url` 都应保持同一个站内目标。
- 安全链路：站外 URL 和带货币/语言前缀的认证页均应被丢弃，页面不得出现循环跳转。
