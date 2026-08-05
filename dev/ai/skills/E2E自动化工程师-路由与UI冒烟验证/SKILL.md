---
name: E2E自动化工程师-路由与UI冒烟验证
description: Diagnose or explicitly design a lightweight Weline route, HTTP, render, navigation, or UI smoke check. Use for 404/405/auth/render failures or a requested smoke suite; routine Browser acceptance for a normal frontend change stays with the owning frontend skill, and deep stateful journeys use the end-to-end skill.
---

# Route and UI smoke

## Boundary

- HTTP proves registration and reachability; it does not prove rendered copy, layout, tags, or interaction.
- Browser smoke proves one visible surface or interaction; it is not full business-flow coverage.
- Use Playwright E2E only when cookies, redirects, auth state, or a multi-step journey make a smoke check insufficient.

## Workflow

1. Identify the exact route, expected status/rendered condition, area, auth prerequisite, and whether route registration changed.
2. Run `php bin/w setup:upgrade --route` only when the route graph changed.
3. Use `php bin/w http:request ...` or a bounded HTTP probe for registration/status diagnosis.
4. For visible output, use the in-app Browser on the served route and inspect a concrete selector/attribute plus console state.
5. If authentication is required, use only user-supplied or locally configured credentials.
6. Separate application failure from certificate, browser automation, or runtime availability failure.
7. Report the full route, command/browser steps, observed result, and any blocked layer.

## Quality bar

- Surface 404, 405, auth redirects, and render errors explicitly.
- Do not convert HTTP success into UI acceptance.
- Do not create a browser suite when one bounded smoke proves the risk.
- A browser/runtime blocker remains a reported gap, not a silent pass.
