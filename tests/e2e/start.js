/**
 * E2E start script.
 * One command performs:
 * 1. Check modules.json
 * 2. Collect tests
 * 3. Run Playwright
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const MODULES_JSON = path.join(__dirname, 'modules.json');

console.log('[e2e] starting test flow...\n');

if (!fs.existsSync(MODULES_JSON)) {
    console.error('[e2e] error: modules.json is missing');
    console.error('   Run: php bin/w setup:upgrade');
    process.exit(1);
}

console.log('[e2e] modules.json found\n');

console.log('[e2e] collecting tests...\n');
try {
    const { collectAllTests } = require('./collect-tests');
    const result = collectAllTests();

    if (result.total_tests === 0) {
        console.warn('[e2e] no tests were collected');
        console.warn('   Check app/code/*/*/test/e2e/*.spec.js or tests/e2e/specs/**/*.spec.js\n');
    }
} catch (error) {
    console.error('[e2e] test collection failed:', error.message);
    process.exit(1);
}

console.log('[e2e] running Playwright...\n');
try {
    const args = process.argv.slice(2);
    const command = args.length > 0
        ? `npx playwright test ${args.join(' ')}`
        : 'npx playwright test';

    execSync(command, {
        stdio: 'inherit',
        cwd: __dirname,
        shell: true
    });
} catch (error) {
    process.exit(error.status || 1);
}
