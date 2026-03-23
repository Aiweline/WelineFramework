# Progress - weshop auth controller contract slice

- 2026-03-23 04:15 Created the task workspace.
- 2026-03-23 11:49 Resumed WeShop auth API work after the token-area slice and narrowed scope to route-level controller semantics for `login`, `refresh`, `logout`, `me`, and `challenge/verify`.
- 2026-03-23 11:52 Added failing controller-first coverage in `app/code/WeShop/Auth/Test/Unit/Api/Rest/V1/AuthControllerTest.php`.
  - The first red run confirmed `postRefresh()` and `postLogin()` were still delegating to `postToken()` and therefore relied on caller-supplied `grant_type`, which is too loose for route-specific endpoints.
- 2026-03-23 11:54 Updated `app/code/WeShop/Auth/Api/Rest/V1/Auth.php` so:
  - `postLogin()` always executes the password grant using the route-level username/email, password, and area inputs
  - `postRefresh()` always executes the refresh-token grant using the route-level refresh token input
- 2026-03-23 11:56 Expanded controller coverage with:
  - `getMe()` token-header fallback coverage
  - `postLogout()` bearer-token coverage
  - `app/code/WeShop/Auth/Test/Unit/Api/Rest/V1/Auth/ChallengeControllerTest.php` for `challenge/verify`
- 2026-03-23 11:57 Validation completed:
  - `php -l` passed for the touched controller and new tests
  - focused controller PHPUnit passed: `5 tests, 15 assertions`
  - full `app/code/WeShop/Auth/Test/Unit` suite passed: `16 tests, 72 assertions`
  - non-blocking: PHPUnit still reports `1` deprecation warning in this repo
- 2026-03-23 12:23 Committed the slice as `7efbf68c test(weshop): lock auth controller grant contracts`.
- 2026-03-23 12:25 Noted a workspace hygiene issue: the commit also carried previously staged `Weline_Server` files (`Maintenance.php`, `ServiceOrchestrator.php`, `ServiceOrchestratorControlQueueTest.php`) from the dirty index. They were not part of this WeShop slice's intended white-list and should be treated as unrelated carry-over when reading commit history.
