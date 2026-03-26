# Plan - commit datatable fixes

## Outcome

- The DataTable frontend-demo closure is reviewed, one remaining defect is fixed, focused verification is green again, and the commit only contains the intended DataTable slice.

## Steps

- [x] Clarify scope, affected files, and risks.
- [x] Review the DataTable diff for remaining defects worth fixing before commit.
- [x] Remove the leftover frontend demo debug script from `view/frontend/templates/test/form.phtml`.
- [x] Re-run focused verification.
- [x] Update task docs for the original DataTable task and this packaging task.
- [x] Create a commit containing only the DataTable slice and related task docs.

## Verification Targets

- [x] `php vendor/bin/phpunit --no-coverage app/code/Weline/DataTable/Test/Unit --colors=never`
- [x] `php tests/e2e/framework/preflight-refresh.php`
- [x] `$env:PLAYWRIGHT_TARGET_ORIGIN='http://127.0.0.1:9996'; $env:PLAYWRIGHT_DISABLE_PROXY='1'; node tests/e2e/start.js specs/frontend/weline-datatable.spec.js`
- [ ] `php bin/w setup:upgrade --route`
  Still blocked by the known workspace/framework CLI argument-validation defect.
