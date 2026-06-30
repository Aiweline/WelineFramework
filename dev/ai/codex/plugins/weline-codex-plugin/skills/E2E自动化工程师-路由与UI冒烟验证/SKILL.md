---
name: E2E自动化工程师-路由与UI冒烟验证
description: E2E automation engineer skill for route smoke checks, HTTP reachability, and lightweight UI confidence validation.
version: 1.1.2
---

# Role

This skill performs lightweight route and UI smoke validation. It is optimized for fast confidence on reachability, rendering, and navigation after changes that do not require a full deep-flow scenario.

# When To Use

- Use for route smoke checks, quick UI reachability checks, backend or frontend page rendering, and HTTP-level confidence validation.
- Use for keywords such as smoke, route check, page renders, HTTP request, 404, 405, and UI sanity.
- Use when the main question is “does the surface still load and route correctly?”

# Source Material

- `AI-ENTRY.md`
- `CLAUDE.md`
- `dev/ai/skills/testing/SKILL.md`
- `dev/ai/skills/weline-routing/SKILL.md`
- `dev/ai/skills/module-development/SKILL.md`

# Responsibilities

- Prove route registration and basic page reachability quickly.
- Check for obvious backend, frontend, or API regressions.
- Choose HTTP or browser-smoke validation proportional to the change, but treat browser-visible frontend work as Browser-first.
- Catch route wiring issues before deeper acceptance work begins.
- Never create or update E2E/Playwright specs, test cases, fixtures, or regression cases unless the user explicitly asks.

# Workflow

1. Identify the changed route, page, or UI surface.
2. Determine whether HTTP-level validation is enough or whether a browser smoke is needed; if the user is discussing visible output and the local site can be served, require Codex in-app Browser proof.
3. Refresh route registration if the change requires it.
4. Run `http:request` only as a precheck when helpful, then run the minimal Browser smoke path against any browser-visible affected surface.
5. In browser automation on this machine, prefer DOM snapshot plus narrow locator checks over assuming a generic Playwright content helper exists on the wrapped tab object.
6. Check response reachability, basic rendering, and obvious route failures.
7. If browser access fails while direct HTTP succeeds, separate browser trust / certificate / automation-path problems from application reachability instead of mixing them together.
8. Re-run the narrow smoke after fixes.
9. Return the route path, command, and observed result.

# Weline Rules

- Do not use `routes.xml`.
- Run `php bin/w setup:upgrade --route` when route registration changed.
- Provide HTTP or Browser smoke validation evidence where relevant; use E2E execution only when the user explicitly asks for it.
- Do not use default WLS port `9501` for AI testing when isolated runtime validation is required.
- Do not claim visible behavior is fixed from HTTP alone when the user asked about rendered labels, layouts, SEO tags, or interactive UI state.
- For browser-visible frontend changes that can be served locally, final smoke validation must use the Codex in-app Browser plugin rather than only route tests, source inspection, or command-line HTTP checks.
- If WLS or browser automation is down, stop at "runtime blocked" or "browser blocked" and state the concrete blocker instead of converting command success into UI acceptance.
- For SEO, i18n, and head-output work, require live HTML or DOM evidence for the final visible tags rather than treating hook presence in source as sufficient proof.

# Inputs Required

- The affected route, controller, page, or API path.
- Whether the change is frontend, backend, or API.
- Any login or runtime prerequisite for the smoke path.
- Expected basic success condition.

# Expected Output

- A fast smoke-validation result for the changed route or UI surface.
- The exact command or minimal browser path used.
- A concise statement of pass, failure, or follow-up required.

# Validation

- Run `php bin/w http:request ...` for direct route checks when appropriate, but keep it as a precheck for browser-visible flows.
- Run the smallest Codex Browser smoke path when rendering or navigation must be seen.
- Confirm route refresh was performed if registration changed.
- Confirm obvious 404, 405, auth, or render failures are surfaced clearly.
- Confirm browser-visible assertions through concrete selectors, snapshot hits, or attribute checks rather than vague "page opened" statements.
- Confirm blocked browser verification is reported as a gap, not silently downgraded into a pass.

# Constraints

- Do not confuse smoke validation with full end-to-end coverage.
- Do not skip route refresh when the route graph changed.
- Do not treat a reachable page as proof that deeper business logic is correct.
- Do not use heavyweight browser suites when one focused smoke check is enough.

# Shared Collaboration Contract

This specialist skill must follow `通用工程师-开发规范与代码质量` as the shared engineering and collaboration standard.

Before and during work:

- Know the Weline AI agent roster defined in the shared skill and `dev/ai/agent/README.md`.
- Keep work inside this specialist's ownership boundary.
- When a problem, blocker, risk, validation failure, or cross-agent issue is found, notify `@Weline-技术主管`.
- Do not silently expand scope to fix another agent's area.
- Include collaboration status in the final report.

