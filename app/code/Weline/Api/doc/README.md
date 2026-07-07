# Weline_Api 模块文档

## 开发前先读

1. `app/code/Weline/Api/doc/AI-INDEX.md`
2. `app/code/Weline/Api/doc/framework-api-and-auth-contract.md`
3. 涉及浏览器交互时，同时读 `app/code/Weline/Frontend/doc/AI-INDEX.md`

## 模块定位

`Weline_Api` 负责两类能力，不要混成一类理解：

- 框架业务 API 适配层：
  浏览器里的业务请求要走 `Weline.Api.resource()`、`Weline.Api.graph()`、`Weline.Api.stream()`，对应 `Controller/Framework/*` 这组入口。
- REST 与认证安全层：
  对外或跨端 API 用户、应用、令牌、白名单、User-Agent 约束、接口文档能力都在这个模块。

## 核心约定

- 浏览器业务请求不能绕过本模块自己写原生 Ajax/fetch/axios 直连后端业务控制器。浏览器业务协议统一走 `Weline.Api.*`。
- `Controller/Framework/Query.php`、`Stream.php`、`QueryBin.php` 是框架请求入口的薄封装，真正协议语义在框架控制器里，不要在业务模块复制一份“私有 query 协议”。
- REST 能力放在 `Api/Rest/V1/*`。新增 REST 时要明确它属于：
  前端用户认证接口、
  后台认证接口、
  应用/集成接口、
  还是框架业务适配接口。
- `Observer/ApiControllerInitBefore.php` 是核心鉴权门。它会区分：
  公共认证路由、
  guest 前端白名单路由、
  完全公开 API、
  后台 API、
  前台 API。
- “公开 API”不是靠文件夹名判断，而是当前控制器/动作没有 `#[Acl]` 时，`ApiSecurityService::isPublicApi()` 会把它当公开接口。也就是说，漏写 `#[Acl]` 可能直接把接口变成公开。
- IP 白名单和 User-Agent 限制是模块内现成能力，服务在 `IpWhitelistService`、`UserAgentRestrictionService`；做集成令牌时优先接这套，不要各模块散写一遍。
- 接口需要被文档系统识别时，沿用现有注释文档约定与 `ApiDocService` 能力，不要新造一套注解格式。

## 典型开发流程

1. 浏览器页面和后台页面发业务请求时，只接 `Weline.Api.*` 或已发布的 QueryProvider。
2. 需要 REST 接口时，在 `Api/Rest/V1/*` 或命中的控制器面新增动作，并明确鉴权策略。
3. 需要受保护的接口时补 `#[Acl]`，不要依赖“默认私有”的错误直觉。
4. 需要登录、token、应用安装或集成能力时，优先复用 `TokenService`、`ApiAppTokenService`、`ApiAppService`、`ApiAppSubjectProviderRegistry`。
5. 接口稳定后补模块 doc，而不是让鉴权规则只藏在 observer 里。

## 常见误区

- 在前端模板里直接写原生 `fetch()` 调后端业务控制器。
- 误以为 REST 都该放进业务模块自己的控制器，不经过统一鉴权门。
- 漏写 `#[Acl]`，把本来应受保护的接口暴露成公开 API。
- 新增认证例外路径却只在单个控制器里写判断，不同步全局鉴权链路。

## 源码锚点

- `app/code/Weline/Api/Controller/Framework/Query.php`
- `app/code/Weline/Api/Controller/Framework/Stream.php`
- `app/code/Weline/Api/Controller/Framework/QueryBin.php`
- `app/code/Weline/Api/Observer/ApiControllerInitBefore.php`
- `app/code/Weline/Api/Service/ApiSecurityService.php`
- `app/code/Weline/Api/Service/TokenService.php`
- `app/code/Weline/Api/Service/IpWhitelistService.php`
- `app/code/Weline/Api/Service/UserAgentRestrictionService.php`
