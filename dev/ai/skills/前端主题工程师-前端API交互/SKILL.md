---
name: 前端主题工程师-前端API交互
description: Implement and review Weline browser business requests through theme.js, weline-api, workers, QueryProvider, Weline.Api.resource/graph/stream, and unified error handling. Use for frontend queries, submissions, refreshes, uploads, streams/SSE, query-bin routing, or browser request failures; do not use direct Ajax/XHR/fetch/axios or controller-style request/get/post for business APIs.
---

# Frontend API interaction

## Load

- `app/code/Weline/Frontend/doc/AI-INDEX.md`
- `app/code/Weline/Theme/doc/AI-INDEX.md`
- Owning module `doc/AI-INDEX.md`
- `app/code/Weline/Frontend/doc/Weline.Api使用指南.md`

Load the theme/component skill only when the same task also changes those surfaces.

## Contract

Browser business traffic follows:

`theme.js → weline-api → worker → query-bin → frontend QueryProvider/service`

Choose:

- `Weline.Api.resource('provider')` for provider operations;
- `Weline.Api.graph()` for aggregated/graph queries;
- `Weline.Api.stream()` for Weline-managed streaming/SSE.

`Weline.Api.request()/get()/post()` are transport primitives for framework infrastructure or non-business resource loading. They must not call module business controllers or become a second browser business API.

## Workflow

1. Locate the rendered surface and current request owner.
2. Discover the published frontend QueryProvider operation and parameters; do not guess.
3. Select `resource`, `graph`, or `stream` from the interaction semantics.
4. Keep worker/query-bin URLs and routing prefixes inside framework transport.
5. Reuse unified maintenance, HTTP-error, retry, and diagnostic behavior; customize handlers only for a real product requirement.
6. Validate the actual browser interaction and resulting state.

## Prohibited

- Native Ajax/XHR/fetch/axios/jQuery Ajax or raw `EventSource(url)` for business flows.
- Handwritten query-bin, REST, controller, worker-protocol, or fixed `/api` URLs.
- `Weline.Api.request/get/post` calls to business-controller routes.
- Fallback branches such as `Weline.Api ? ... : fetch(...)`.
- Examples that normalize a prohibited request path.

## Validation

- Run `php dev/ai/scripts/check-browser-business-requests.php` when browser business code changes.
- Confirm the published operation, request surface, visible result, and error handling in the built-in Browser.
- Record the real route/interaction and any console error; do not claim browser verification from static code alone.
