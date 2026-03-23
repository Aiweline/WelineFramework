// tests/e2e/playwright.config.js
// Shared Playwright config for module-local test/e2e specs and shared tests/e2e/specs.

const path = require('path');
const Module = require('module');
const { defineConfig, devices } = require('@playwright/test');
const { collectAllTests } = require('./collect-tests');
const { getRuntimeInfo } = require('./framework/runtime');

const rootDir = path.resolve(__dirname, '../..');
const localNodeModules = path.resolve(__dirname, 'node_modules');
const moduleFilter = process.env.MODULE_FILTER || process.argv.find(arg => arg.startsWith('--module='))?.split('=')[1];
const runtimeInfo = getRuntimeInfo({ refresh: true });
const baseURL = runtimeInfo.proxy.origin;

process.env.NODE_PATH = [localNodeModules, __dirname, process.env.NODE_PATH]
  .filter(Boolean)
  .join(path.delim);
Module._initPaths();

let testDir = rootDir;
let testMatch = [];

function normalizeFilePattern(file) {
  return `**/${String(file).replace(/\\/g, '/')}`;
}

try {
  const result = collectAllTests();

  if (result.all_test_files && result.all_test_files.length > 0) {
    let filesToUse = result.all_test_files;

    if (moduleFilter) {
      const moduleTests = result.modules[moduleFilter];
      if (moduleTests && moduleTests.test_files) {
        filesToUse = moduleTests.test_files;
        console.log(`[playwright] module filter "${moduleFilter}" matched ${moduleTests.test_files.length} files`);
      } else {
        console.warn(`[playwright] module "${moduleFilter}" has no collected test files`);
        filesToUse = [];
      }
    }

    if (filesToUse.length > 0) {
      testMatch = filesToUse.map(normalizeFilePattern);
      console.log(`[playwright] collected ${filesToUse.length} test files`);
    } else {
      console.log('[playwright] no collected files after filtering, falling back to shared specs');
    }
  } else {
    console.log('[playwright] no collected files, falling back to shared specs');
  }
} catch (error) {
  console.warn('[playwright] test collection failed, falling back to shared specs:', error.message);
  console.warn('   Make sure setup metadata is available when needed.');
}

console.log('[playwright] rootDir:', rootDir);
console.log('[playwright] testDir:', testDir);
console.log('[playwright] testMatch:', testMatch);
console.log('[playwright] proxy baseURL:', baseURL);
console.log('[playwright] target origin:', runtimeInfo.runtime.target_origin);

module.exports = defineConfig({
  rootDir,
  globalSetup: undefined,
  globalTeardown: undefined,
  testDir,
  testMatch: testMatch.length > 0 ? testMatch : ['tests/e2e/specs/**/*.spec.js'],
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: 'html',
  webServer: process.env.PLAYWRIGHT_DISABLE_PROXY === '1' ? undefined : {
    command: 'node framework/proxy-server.js',
    cwd: __dirname,
    url: `${baseURL}/.well-known/weline-e2e/health`,
    reuseExistingServer: !process.env.CI,
    timeout: 120000,
    ignoreHTTPSErrors: true,
  },
  use: {
    baseURL: process.env.PLAYWRIGHT_DISABLE_PROXY === '1' ? runtimeInfo.runtime.target_origin : baseURL,
    ignoreHTTPSErrors: true,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure'
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] }
    }
  ]
});
