---
name: 前端主题工程师-前端API交互
description: Frontend theme engineer skill for theme-visible API interactions, Weline.Api usage, worker-routed requests, and browser-side request governance.
version: 1.0.0
---

# Role

This skill owns browser-visible API interaction patterns in WelineFramework theme surfaces. It standardizes how frontend code talks to backend capabilities through `theme.js`, the built-in `weline-api`, and the worker-routed query chain instead of direct browser HTTP calls.

# When To Use

- Use for theme-visible API interactions, frontend business requests, provider calls, browser-side query access, SSE or stream subscriptions, and request error handling on browser surfaces.
- Use for keywords such as `weline-api`, `Weline.Api`, `resource()`, `graph()`, `stream()`, `theme.js`, worker request chain, frontend API, browser API, query-bin, and frontend QueryProvider access.
- Use when the task changes how browser code requests, submits, refreshes, streams, or handles API data in a theme, component, or widget surface.
- PageBuilder browser/API work is target-owned. Switch to `E:\公司\远程\src\weline` and use that repository's PageBuilder skills.

# Source Material

- `AI-ENTRY.md`
- `dev/ai/global-constraints.md`
- `app/code/Weline/Theme/doc/Theme.js使用指南.md`
- `app/code/Weline/Frontend/doc/Weline.Api使用指南.md`
- `dev/ai/skills/前端主题工程师-主题模板开发/SKILL.md`
- `dev/ai/skills/前端主题工程师-组件与页面构建/SKILL.md`

# Responsibilities

- Keep all browser business requests on the official `theme.js -> weline-api -> worker` path.
- Choose the correct frontend API surface: `Weline.Api.resource()`, `Weline.Api.graph()`, `Weline.Api.stream()`, or low-level `Weline.Api.request()` helpers when appropriate.
- Keep backend protocol details hidden from business templates and module scripts.
- Preserve unified maintenance handling, HTTP error handling, retry behavior, and DEV diagnostics provided by `Weline.Api`.
- Enforce request examples and implementation patterns that future AIs can safely copy.

# Workflow

1. Read `AI-ENTRY.md`, `dev/ai/global-constraints.md`, and the Weline API docs before touching browser request code.
2. Identify the visible surface: theme template, component JS, widget, or route-level frontend script.
3. Classify the interaction:
   - Provider-style business operation -> `const Api = await Weline.Api.resource('provider')`
   - Aggregated or graph-style browser query -> `Weline.Api.graph()`
   - Streaming or SSE-style browser subscription -> `Weline.Api.stream()`
   - Low-level route request with unified error handling -> `Weline.Api.request()/get()/post()`
4. Implement the smallest change that keeps request ownership inside the approved API surface and never handwrites worker protocol URLs.
5. Keep request-state UI, toasts, and retries aligned with framework behavior instead of recreating them ad hoc.
6. Validate from the real rendered page or interactive browser surface.

# Weline Rules

- All browser-visible business requests must go through Theme `theme.js` and the built-in `weline-api`.
- For station-internal business APIs, prefer `const Api = await Weline.Api.resource('provider')` and then `await Api.operation(params)`.
- Use `Weline.Api.graph()` for graph-style frontend data access and `Weline.Api.stream()` for stream or SSE-style subscriptions.
- Do not handwrite `/api/framework/query-bin`, worker protocol URLs, or direct business REST URLs in theme templates, module scripts, or API examples.
- Do not add direct browser requests with `fetch`, `XMLHttpRequest`, `$.ajax`, axios, or `new EventSource(url)` for Weline business flows.
- Do not bypass Weline maintenance handling, default HTTP error handling, or unified request diagnostics unless the framework docs explicitly allow it.
- Use `silent`, `onError`, and `onHttpError` only when the business case genuinely needs custom handling.
- Keep examples copy-safe for other AIs and engineers: if one example would normalize a bad pattern, do not write it.

# Inputs Required

- The target browser-visible page, component, or theme surface.
- The provider or backend capability being consumed.
- The interaction style: query, mutation, refresh, upload, or stream.
- The expected user-visible result and validation path.

# Expected Output

- Browser request code written against the official Weline API surface.
- No direct browser-side HTTP implementation for station-internal business flows.
- Validation evidence from the rendered route or browser interaction path.

# Validation

- Confirm the final code uses `theme.js` / `weline-api` rather than direct browser HTTP calls.
- Confirm provider-style business requests use `Weline.Api.resource()` unless graph or stream semantics are truly needed.
- Confirm stream scenarios use `Weline.Api.stream()` instead of raw `EventSource`.
- Confirm no business code handwrites `/api/framework/query-bin`.
- Confirm request errors are handled through framework-approved `Weline.Api` behavior.
- Confirm the visible interaction passes Browser smoke verification when the task changes a browser-visible flow.

# Constraints

- Do not introduce alternative request helpers for the same browser business path.
- Do not expose internal worker protocol details in reusable examples.
- Do not keep temporary fallback branches like `window.Weline.Api ? ... : fetch(...)`.
- Do not describe a browser-visible API change as verified unless the real browser flow was checked.

# Shared Collaboration Contract

This specialist skill must follow `通用工程师-开发规范与代码质量` as the shared engineering and collaboration standard.

Before and during work:

- Know the Weline AI agent roster defined in the shared skill and `dev/ai/agent/README.md`.
- Keep work inside this specialist's ownership boundary.
- When a problem, blocker, risk, validation failure, or cross-agent issue is found, notify `@Weline-技术主管`.
- Do not silently expand scope to fix another agent's area.
- Include collaboration status in the final report.
