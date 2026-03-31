$ErrorActionPreference = 'Stop'

$env:CI = '1'
$env:PLAYWRIGHT_DISABLE_PROXY = '1'
$env:PLAYWRIGHT_TEST_FILES = '["app/code/Agent/CursorBase/test/e2e/backend/Agent_CursorBase-smoke-backend.spec.js"]'

npx playwright test --project=chromium --workers=1

