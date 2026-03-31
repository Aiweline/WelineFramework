/**
 * Cart 专用 Playwright：单一 origin + 禁用代理。
 * 将 PLAYWRIGHT_TARGET_ORIGIN 设为 runtime.cli_origin，使 framework/gotoFrontend 与页面 baseURL 一致
 * （避免 WLS 元数据指向 https:443 而本机仅监听 http:9981）。
 *
 * 可选：PLAYWRIGHT_BASE_URL 覆盖完整 origin。
 */
if (!process.env.PLAYWRIGHT_BASE_URL) {
  process.env.PLAYWRIGHT_DISABLE_PROXY = '1';
}

const { defineConfig, devices } = require('@playwright/test');
const { getRuntimeInfo } = require('./framework/runtime');

const probe = getRuntimeInfo({ refresh: true });
if (!process.env.PLAYWRIGHT_BASE_URL && !process.env.PLAYWRIGHT_TARGET_ORIGIN) {
  process.env.PLAYWRIGHT_TARGET_ORIGIN = probe.runtime.cli_origin || probe.runtime.target_origin;
}
const runtimeInfo = getRuntimeInfo({ refresh: true });
const baseURL =
  process.env.PLAYWRIGHT_BASE_URL || runtimeInfo.runtime.target_origin;

module.exports = defineConfig({
  testDir: '.',
  timeout: Number(process.env.PLAYWRIGHT_TEST_TIMEOUT || 120000),
  expect: {
    timeout: Number(process.env.PLAYWRIGHT_EXPECT_TIMEOUT || 15000),
  },
  workers: Number(process.env.PLAYWRIGHT_WORKERS || 1),
  fullyParallel: false,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL,
    ignoreHTTPSErrors: true,
    headless: process.env.CI ? true : false,
    trace: 'off',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
