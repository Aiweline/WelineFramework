---
name: 框架核心工程师-路由事件与扩展
description: >-
  Implement or diagnose Weline controller routing, ModuleRouter custom public URLs, events, observers,
  Hooks, and extends points. Use for Router.php, aliases, random paths, process_uri_before, getUrl,
  or setup:upgrade --route behavior; do not use URL/query/router state to choose a Theme layout.
---

# Routing, events, and extension points

## Choose the mechanism

| Need | Mechanism |
|---|---|
| Fixed module/controller/action path | `etc/env.php` router + Controller, collected with `setup:upgrade --route` |
| Custom, random, short, or alias public URL | Module `Controller/Router.php` implementing `RouterInterface`, rewriting `$path` to a static internal route |
| Notify other owners | Event/Observer |
| Render/extend a host | Hook or `extends` contract |

**Hard（见 `global-constraints.md` §4）**：

- `Framework/` 是抽象层：只提供中立 Event / Interface；禁止把 Website 等业务语义写进核心。
- 模块之间禁止强制相互引用对方具体类；跨模块通知/副作用必须用 Event/Observer 解耦，不得 `use`/注入/`new` 对方 Service/Model 来“直接调用”。


Do not use `routes.xml`, an extra PHP entrypoint, or Nginx routing for a module URL alias.

## Router boundaries

- Return immediately when a route is already matched; use a distinctive prefix and cached configuration before expensive work.
- Compare secret/random path material precisely (for example `hash_equals`) and clear `ProcessUrlBefore`/module caches when dynamic path configuration changes.
- A Router rewrites URL identity; it does not select Theme layout. Controllers, events, configuration, or business context own layout selection.
- Localized paths support currency-only, language-only, currency/language, and language/currency. Reuse the shared parser without consulting allowed-value configuration during early stripping.
- Backend URLs start with the runtime `Env::getAreaRoutePrefix('backend')` value. Module `backend_router` is not that key; never guess `/admin` or `/backend`.

## Workflow

1. Inspect the current Controller, `etc/env.php`, generated-route owner, ModuleRouter observer/reader, URL helper, and affected callers.
2. Choose static routing or ModuleRouter and identify the exact external-to-internal mapping.
3. Keep Router matching short, cached, and independent of Theme layout identity.
4. Use framework URL builders for frontend/backend links.
5. Run `php bin/w setup:upgrade --route` only when a Controller/API route signature changed; Router matching-only changes normally need cache invalidation, not route collection.
6. Validate the real external path, internal Controller hit, redirects, localization forms, and cache refresh.

## Validation

- Static route: route upgrade plus focused HTTP/Browser evidence.
- ModuleRouter: external-path probe and proof of the intended internal Controller.
- Backend: runtime backend key works and the same path without the key is not treated as backend.
- Theme page: no public `layout_type`, `page_type`, `layout_option`, or preview-policy routing dependency.
- Dynamic path: cache clear and old/new path behavior are explicit.

Read `app/code/Weline/Framework/doc/event/router/URI处理前.md` and current `Weline_ModuleRouter` source when changing the mechanism.
