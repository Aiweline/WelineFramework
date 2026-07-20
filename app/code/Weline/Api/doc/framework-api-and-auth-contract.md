# Framework API 与鉴权约定

## 1. 浏览器业务接口

浏览器里的业务请求统一走：

- `Weline.Api.resource()`
- `Weline.Api.graph()`
- `Weline.Api.stream()`

这里的约束不是“推荐”，而是框架开发协议。业务模块不要因为局部方便就退回：

- 原生 `fetch`
- `XMLHttpRequest`
- `axios`
- 随手自定义 `/api/foo/bar` 协议

对应入口在：

- `app/code/Weline/Api/Controller/Framework/Query.php`
- `app/code/Weline/Api/Api/Framework/Stream.php`
- `app/code/Weline/Api/Controller/Framework/QueryBin.php`

## 2. REST 接口层

REST 接口按 `Api/Rest/V1/*` 组织。这里更适合：

- API 用户登录/刷新/交换 token
- 后台集成
- 对外应用接入
- 明确版本化的稳定接口

而不是替代浏览器业务 Query 协议。

框架管理的 SSE 订阅入口是一个例外：它位于
`Api/Framework/Stream.php`，因此会生成到前台 REST 路由表并由
`/api/framework/stream` 提供服务。不要把它放进 `Controller/`，否则会被
错误地注册为页面路由。

## 3. 鉴权判断入口

统一鉴权门在：

- `app/code/Weline/Api/Observer/ApiControllerInitBefore.php`

它会在请求初始化前区分：

- 公共认证路由
- guest 前端白名单路由
- 完全公开 API
- 后台 API
- 前台 API

新增接口时，要先决定自己属于哪一类，再写控制器，不要写完后靠临时 if 兜。

## 4. `#[Acl]` 的真实语义

`app/code/Weline/Api/Service/ApiSecurityService.php` 当前规则非常关键：

- 控制器动作有 `#[Acl]`，不是公开 API。
- 控制器类有 `#[Acl]`，不是公开 API。
- 类和动作都没有 `#[Acl]`，按公开 API 处理。

所以：

- “忘了加 Acl” 不会更安全，只会更开放。
- 评审 API 改动时，要把 `#[Acl]` 是否存在当成安全边界检查项。

## 5. 令牌与限制

模块内已有这些统一能力：

- `TokenService`
- `ApiAppTokenService`
- `ApiAppService`
- `IpWhitelistService`
- `UserAgentRestrictionService`

做集成令牌时优先复用它们。不要在别的模块再复制一套白名单、UA 校验和 token 规则。

## 6. 开发反例

- 反例：前端业务接口暴露成 REST，只因为“写起来顺手”。
- 反例：把“没有 `#[Acl]`”理解成“还没配置，默认应拦截”。
- 反例：在业务模块里自己维护一份 auth 例外路由名单。
