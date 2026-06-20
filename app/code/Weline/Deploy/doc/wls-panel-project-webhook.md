# WLS Panel Project Webhook Context

This note records the WLS Panel project-scoped webhook contract for
`Weline_Deploy`.

## Context Input

The public `~wh~` webhook endpoint can receive safe WLS project context through
query parameters:

```text
https://example.com/~wh~random-path?project_id=12&domain=shop.example.com&project_type=wls
```

The same context may also be sent in the JSON payload top level or inside one
of these nested objects: `wls`, `wls_project`, `project`.

Supported keys:

- `profile_key`
- `project_id`
- `domain`
- `project_type`

## Runtime Rules

- The random `~wh~` path remains the global public entry path.
- When the context matches an enabled `DeployProjectProfile`, that Profile may
  bind its own `webhook_secret`. The webhook controller resolves the effective
  project config before token verification, so a project secret can accept the
  request while the global secret is rejected for that project.
- When no project secret is configured, webhook verification falls back to the
  global `webhook_secret` for backward compatibility.
- The enabled Profile also provides the release values for repository, branch,
  remote, deploy root, trigger mode, branch/tag filters, backup switch,
  Composer command, and post-deploy command.
- `deploy_root` is the execution root for Git operations, backup source,
  post-deploy command, `var/deploy/current.json`, and `server:reload`.
- Health and version probes use the same project context. When
  `profile_key`, `project_id`, `domain`, or `project_type` is supplied to
  `~wh~...?health=1` or `~wh~.../version`, the response reads
  `deploy_root/var/deploy/current.json` for the matched enabled Profile instead
  of the host WLS project's runtime stamp.
- A configured `deploy_root` must be an existing absolute path. Invalid roots
  fail the release rather than falling back to the host WLS project.
- Without context, or without an enabled matching Profile, the webhook keeps the
  legacy global Deploy behavior.

## Validation Path

Use WLS Deploy's Webhook Replay preflight first. For a safer live webhook check,
post a ref that the selected Profile should skip; the response should return
`202` with `skipped=true`, a reason such as `trigger_mode_branch_only`, and the
resolved `profile_key`.

For a controlled success-path check, do not add a production `dry_run` flag that
returns a fake `200`. Instead, create a temporary bare Git remote and a temporary
working clone under `var/tmp`, use that clone as the selected Profile's
`deploy_root`, disable backup/Composer/post-deploy commands, and POST a real tag
payload to the public `~wh~` route with the project webhook secret. The expected
result is:

- HTTP `200` with `ok=true`.
- The deployed version matches the posted tag.
- `deploy_root/var/deploy/current.json` exists and records the same version.
- `GET ~wh~.../version?project_id=...` and `GET ~wh~...?health=1&project_id=...`
  report the same project-scoped release ID and version.
- A project-scoped `DeployRelease` record is created for the selected
  `profile_key` / `project_id`.
- Cleanup restores `app/etc/env.php` and `deploy_settings`, deletes the
  temporary Profile/release records, stops the dedicated WLS instance, confirms
  the port is closed, and removes the temporary Git tree.

## Manual Release Plan

The WLS Deploy panel exposes a manual release planning step and a guarded
manual execution gate. Operators can build a read-only plan first, then run a
release only after entering a ref and checking the explicit confirmation box.

Runtime rules:

- `deploy/backend/wls-deploy/manual-plan-run` accepts only POST requests from
  the selected panel context. Both `Build Plan` and `Run Release` use this
  already-registered route; `Run Release` adds `manual_action=run_release`.
- The controller reloads the selected project Profile, applies its effective
  deploy config, and recomputes `buildPanelPreflight()`.
- The plan is not built and the release is not executed when the preflight
  status is `danger`.
- `Run Release` requires `confirm_manual_release=1`. The browser button also
  stays disabled until a ref exists and the checkbox is checked, but the server
  repeats the confirmation/preflight checks before any release side effect.
- Raw ref names are interpreted conservatively: in tag mode a raw name becomes
  `refs/tags/<name>`, in branch mode it becomes `refs/heads/<name>`, and
  explicit `refs/tags/...` / `refs/heads/...` are preferred.
- Ref resolution still goes through `DeployWebhookRefResolver`, so the same
  tag-only/branch-only/both trigger policy is used by Webhook Replay and Manual
  Plan.
- The rendered plan lists the future release path: Profile, deploy root,
  remote/update mode, backup, Git update, Composer, post-deploy command,
  runtime stamp, project-scoped release history, release-after dispatch, and
  WLS reload.
- `Build Plan` never calls `DeployOrchestratorService::release()`, never writes
  `current.json`, never creates `DeployRelease` rows, and never reloads WLS.
- `Run Release` calls `DeployOrchestratorService::release()` with
  `trigger=manual`, the same project release context, the same effective
  webhook shell config overlay, and the same resolver output used by the plan.
  If the trigger policy resolves to `skipped`, it redirects back with
  `manual_release_skipped` and performs no release.

Validation path:

- Save an enabled WLS project Profile in the panel.
- Build a manual plan for `refs/tags/v9.9.9`.
- Expect `manual_status=ready`, a visible `data-wls-manual-plan-result`, the
  deploy version `v9.9.9`, and an `Execution gate` step.
- Build or replay a branch ref while the Profile is tag-only and expect
  `manual_status=skipped` with the same trigger-policy semantics as Webhook
  Replay.
- In a `danger` preflight context, fill `refs/tags/v1.0.0` and check the
  confirmation box; the `Run Release` button must remain disabled and the
  server would still reject the execution path.

## Project Rollback Action

The WLS Deploy panel also uses the same project Profile context for rollback.
Rollback is not accepted as a free-form request parameter at execution time.
The operator must first save `rollback_ref` in the selected project Profile.

Runtime rules:

- The panel enables the rollback action only when the selected Profile exists,
  is enabled, has a saved rollback ref, and the release preflight is not
  `danger`.
- The rollback POST requires `confirm_rollback=1`.
- The controller reloads the Profile and recomputes effective project config
  before execution, so stale browser state cannot bypass Profile/preflight
  policy.
- The orchestrator executes rollback inside the selected `deploy_root`, writes
  `deploy_root/var/deploy/current.json`, and records project-scoped release
  history.
- Allowed rollback refs are the same values accepted by the Profile policy:
  tag-like refs, `refs/tags/...`, `refs/heads/...`, or 7-40 character commit
  SHAs.

Validation path:

- Create a temporary bare Git remote and temporary clone under `var/tmp`.
- Save an enabled temporary project Profile with that clone as `deploy_root`
  and `rollback_ref=refs/tags/v-rollback-1`.
- Run `DeployOrchestratorService::rollback()` through the project-effective
  config.
- Verify the clone HEAD matches the rollback tag commit, `current.json`
  records the rollback ref/version/profile, one project-scoped release record
  is created, and cleanup restores `app/etc/env.php`, deletes temporary
  Profile/release rows, and removes the temporary Git tree.
