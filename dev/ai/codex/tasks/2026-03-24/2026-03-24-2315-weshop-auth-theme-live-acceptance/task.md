# Task: weshop-auth-theme-live-acceptance

- Task ID: 2026-03-24-2315-weshop-auth-theme-live-acceptance
- Started: 2026-03-24 23:15
- Status: in_progress
- Owner: Codex
- Source: user request

## Goal

- Validate the current WeShop auth/theme completion work against the live runtime on port `9982`, then close the highest-value acceptance gaps revealed by the verification and parallel audits.

## Scope

- In scope:
- live verification for key WeShop auth/storefront routes on `127.0.0.1:9982`
- auth/theme/default-layout acceptance gap analysis
- the next commit-sized WeShop fixes uncovered by that verification
- task logging for this verification-and-fix wave
- Out of scope:
- modifying `WeShop_Theme` or `Weline_Theme`
- unrelated WLS / Websites / framework worktree changes unless a live WeShop blocker proves they are directly required

## Constraints

- Keep frontend routes clean; do not add extra `frontend` URL segments.
- Use hook/slot-compatible composition so modules can inject into the default theme safely.
- Prefer `w_query()`-driven cross-module reads where cross-module storefront data is needed.
- Port `9982` is the active runtime target for live checks in this session.
- Do not revert unrelated dirty files in the worktree.

## Related Plans

- `dev/ai/codex/WeShop国际电商/README.md`
- `dev/ai/codex/WeShop国际电商/roadmap.md`
- `dev/ai/codex/WeShop国际电商/acceptance-matrix.md`

## Resume

- Check `plan.md`, `progress.md`, and `result.md` in this task directory.
