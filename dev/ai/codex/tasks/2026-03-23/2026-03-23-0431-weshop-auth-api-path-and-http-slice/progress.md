# Progress - weshop auth api path and http slice

- 2026-03-23 04:31 Created the task workspace.
- 2026-03-23 12:34 Re-read the framework routing docs, API docs, env route prefixes, generated WeShop auth REST routes, and URL parser behavior.
- 2026-03-23 12:37 Confirmed the framework-correct frontend REST URL order is `/{rest_frontend_prefix}/{module_router}/rest/v1/...`, not `/api/rest/v1/{module_router}/...`.
  - Current local env: `rest_frontend_prefix=api`
  - Current WeShop auth module router: `weshop`
  - Therefore the full default auth URLs are `/api/weshop/rest/v1/auth/*`
- 2026-03-23 12:39 Confirmed `generated/routers/frontend_rest_api.php` stores relative route keys such as `weshop/rest/v1/auth/token`, which aligns with the area prefix being stripped before router lookup.
- 2026-03-23 12:42 Rewrote the WeShop planning docs under `dev/ai/codex/WeShop国际电商/` to use the framework-correct path shape and to document the current auth URL contract consistently.
- 2026-03-23 12:44 Ran lightweight runtime probes with `http:req`:
  - `php bin/w http:req "api/rest/v1/weshop/auth/me" ...` timed out
  - `php bin/w http:req "weshop/rest/v1/auth/me" ...` failed to connect
  - these probes were not reliable enough to serve as passing runtime verification, but they do confirm the local runtime remains unstable for this slice
