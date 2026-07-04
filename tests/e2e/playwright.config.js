// tests/e2e/playwright.config.js
// Shared Playwright config for module-local test/e2e specs.

const path = require('path');
const fs = require('fs');
const Module = require('module');
const { defineConfig, devices } = require('@playwright/test');
const { collectAllTests } = require('./collect-tests');
const { getRuntimeInfo } = require('./framework/runtime');

const rootDir = path.resolve(__dirname, '../..');
const localNodeModules = path.resolve(__dirname, 'node_modules');
// Some modules call scandir on this legacy compiled-template directory during discovery.
// Ensure it exists in every module before Playwright starts loading specs.
function ensureLegacyTplDirs() {
  const moduleRoots = new Set();
  const appCodeRoot = path.join(rootDir, 'app', 'code');
  if (!fs.existsSync(appCodeRoot) || !fs.statSync(appCodeRoot).isDirectory()) {
    return;
  }

  let vendors = [];
  try {
    vendors = fs.readdirSync(appCodeRoot, { withFileTypes: true }).filter(entry => entry.isDirectory());
  } catch (error) {
    console.warn(`[playwright] skip legacy tpl dir bootstrap: ${error.message}`);
    return;
  }

  for (const vendor of vendors) {
    const vendorRoot = path.join(appCodeRoot, vendor.name);
    let modules = [];
    try {
      modules = fs.readdirSync(vendorRoot, { withFileTypes: true }).filter(entry => entry.isDirectory());
    } catch (error) {
      console.warn(`[playwright] skip vendor tpl bootstrap ${vendor.name}: ${error.message}`);
      continue;
    }

    for (const module of modules) {
      moduleRoots.add(path.join(vendorRoot, module.name));
    }
  }

  // Some workspaces include modules that are not fully discoverable from the filesystem
  // scan (symlink/junction edge cases). Merge module roots from modules.json as fallback.
  try {
    const modulesJsonPath = path.join(__dirname, 'modules.json');
    if (fs.existsSync(modulesJsonPath)) {
      const modulesJson = JSON.parse(fs.readFileSync(modulesJsonPath, 'utf8'));
      for (const moduleInfo of Object.values(modulesJson.modules || {})) {
        const basePath = String(moduleInfo?.base_path || '').trim();
        if (!basePath) {
          continue;
        }
        const moduleRoot = path.isAbsolute(basePath)
          ? basePath
          : path.join(rootDir, basePath.replace(/\//g, path.sep));
        moduleRoots.add(moduleRoot);
      }
    }
  } catch (error) {
    console.warn(`[playwright] skip modules.json tpl bootstrap: ${error.message}`);
  }

  for (const moduleRoot of moduleRoots) {
    try {
      const tplRoot = path.join(moduleRoot, 'view', 'tpl');
      fs.mkdirSync(tplRoot, { recursive: true });
      // Some runtime paths still probe locale subdirectories directly.
      fs.mkdirSync(path.join(tplRoot, 'zh_Hans_CN'), { recursive: true });
      fs.mkdirSync(path.join(tplRoot, 'en_US'), { recursive: true });
    } catch (error) {
      console.warn(`[playwright] skip module tpl bootstrap ${moduleRoot}: ${error.message}`);
    }
  }
}

ensureLegacyTplDirs();

// 动态端口支持：proxy-server.js 端口被占用时自动换端口，端口号写入 .active-proxy-port 文件
const ACTIVE_PORT_FILE = path.join(__dirname, '.active-proxy-port');
function resolveProxyOrigin(runtimeInfo) {
  try {
    if (fs.existsSync(ACTIVE_PORT_FILE)) {
      const content = fs.readFileSync(ACTIVE_PORT_FILE, 'utf8').trim();
      const match = content.match(/^PORT=(\d+)$/);
      if (match) {
        const dynamicPort = match[1];
        const parsed = new URL(runtimeInfo.proxy.origin);
        const dynamicOrigin = `${parsed.protocol}//${parsed.hostname}:${dynamicPort}`;
        console.log(`[playwright] using dynamic proxy port from ${ACTIVE_PORT_FILE}: ${dynamicPort}`);
        return dynamicOrigin;
      }
    }
  } catch (error) {
    console.warn(`[playwright] failed to read dynamic proxy port: ${error.message}`);
  }
  return runtimeInfo.proxy.origin;
}

const runtimeInfo = getRuntimeInfo({ refresh: true });
const baseURL = resolveProxyOrigin(runtimeInfo);
const moduleFilter = process.env.MODULE_FILTER || process.argv.find(arg => arg.startsWith('--module='))?.split('=')[1];
const cliSpecArgs = (() => {
  const args = process.argv.slice(2);
  const result = [];
  args.forEach((arg) => {
    const raw = String(arg || '').trim();
    if (!raw || raw.startsWith('-')) {
      return;
    }
    // Playwright positional filter 可能是测试标题/正则；仅把明显的 spec 路径当成显式文件
    if (!/\.spec\.js$/i.test(raw)) {
      return;
    }
    const normalized = raw.replace(/\\/g, '/');
    result.push(normalized);
  });
  return result;
})();
// 主进程与 worker 进程的 argv 可能不同；显式 spec 通过环境变量固化，避免 “Test not found in worker process”
if (cliSpecArgs.length > 0 && !process.env.PLAYWRIGHT_TEST_FILES) {
  const normalized = [];
  cliSpecArgs.forEach((specArg) => {
    const absolute = path.isAbsolute(specArg)
      ? specArg
      : path.resolve(process.cwd(), specArg);
    if (!fs.existsSync(absolute) || !fs.statSync(absolute).isFile()) {
      return;
    }
    normalized.push(path.relative(rootDir, absolute).replace(/\\/g, '/'));
  });
  if (normalized.length > 0) {
    process.env.PLAYWRIGHT_TEST_FILES = JSON.stringify(normalized);
  }
}
const explicitTestFiles = (() => {
  if (cliSpecArgs.length > 0) {
    const normalized = [];
    cliSpecArgs.forEach((specArg) => {
      const absolute = path.isAbsolute(specArg)
        ? specArg
        : path.resolve(process.cwd(), specArg);
      if (!fs.existsSync(absolute) || !fs.statSync(absolute).isFile()) {
        return;
      }
      normalized.push(path.relative(rootDir, absolute).replace(/\\/g, '/'));
    });
    if (normalized.length > 0) {
      return new Set(normalized);
    }
  }
  const raw = process.env.PLAYWRIGHT_TEST_FILES;
  if (!raw) {
    return null;
  }

  try {
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) {
      return null;
    }

    return new Set(parsed.map(file => String(file).replace(/\\/g, '/')));
  } catch (error) {
    console.warn('[playwright] failed to parse PLAYWRIGHT_TEST_FILES:', error.message);
    return null;
  }
})();
const configuredWorkers = Number(process.env.PLAYWRIGHT_WORKERS || 1);
// 后台 loginAsAdmin 会跑 PHP session bootstrap + 多次导航，30s 级超时必炸（beforeEach 被整体掐断）。
// 默认 120s；若环境显式设得更短，则至少抬到 90s，避免本地/CI 误配 PLAYWRIGHT_TEST_TIMEOUT。
const configuredTimeoutRaw = Number(process.env.PLAYWRIGHT_TEST_TIMEOUT);
const configuredTimeout = Number.isFinite(configuredTimeoutRaw) && configuredTimeoutRaw > 0
  ? configuredTimeoutRaw
  : 120000;
const configuredExpectTimeout = Number(process.env.PLAYWRIGHT_EXPECT_TIMEOUT || 10000);

const resolvedWorkers = Number.isFinite(configuredWorkers) && configuredWorkers > 0
  ? configuredWorkers
  : 1;
const resolvedTimeout = Math.max(
  Number.isFinite(configuredTimeout) && configuredTimeout > 0 ? configuredTimeout : 120000,
  90000,
);
const resolvedExpectTimeout = Number.isFinite(configuredExpectTimeout) && configuredExpectTimeout > 0
  ? configuredExpectTimeout
  : 10000;

process.env.NODE_PATH = [localNodeModules, __dirname, process.env.NODE_PATH]
  .filter(Boolean)
  .join(path.delim);
Module._initPaths();

// Specs now live under app/code/*/*/test/e2e. Their native Node resolution chain
// does not pass through tests/e2e/node_modules, so pin Playwright imports to the
// runner-local package shim prepared by php bin/w e2e:run.
function installPlaywrightModuleResolver() {
  if (Module.__welineE2eResolverInstalled) {
    return;
  }

  const originalResolveFilename = Module._resolveFilename;
  Module._resolveFilename = function resolveWelineE2eModule(request, parent, isMain, options) {
    if (request === '@playwright/test') {
      const candidate = path.join(localNodeModules, '@playwright', 'test', 'index.js');
      if (fs.existsSync(candidate)) {
        return candidate;
      }
    }
    if (request === 'playwright/test') {
      const candidate = path.join(localNodeModules, 'playwright', 'test.js');
      if (fs.existsSync(candidate)) {
        return candidate;
      }
    }

    return originalResolveFilename.call(this, request, parent, isMain, options);
  };
  Module.__welineE2eResolverInstalled = true;
}

installPlaywrightModuleResolver();

let testDir = rootDir;
let testMatch = [];

function normalizeFilePattern(file) {
  return `**/${String(file).replace(/\\/g, '/')}`;
}

if (explicitTestFiles && explicitTestFiles.size > 0) {
  const filesToUse = Array.from(explicitTestFiles);
  testMatch = filesToUse.map(normalizeFilePattern);
  if (testMatch.every(file => file.startsWith('**/tests/e2e/'))) {
    testDir = path.join(rootDir, 'tests', 'e2e');
    testMatch = testMatch.map(file => file.replace(/^\*\*\/tests\/e2e\//, ''));
  }
  console.log(`[playwright] explicit file mode using ${filesToUse.length} files`);
} else {
try {
  const result = collectAllTests();

    if (result.all_test_files && result.all_test_files.length > 0) {
      let filesToUse = result.all_test_files;

      if (moduleFilter) {
        const moduleTests = result.modules[moduleFilter];
        if (moduleTests && moduleTests.test_files && moduleTests.test_files.length > 0) {
          filesToUse = moduleTests.test_files;
          console.log(`[playwright] module filter "${moduleFilter}" matched ${moduleTests.test_files.length} files`);
        } else {
          console.warn(`[playwright] module "${moduleFilter}" has no collected module-local test files`);
          filesToUse = [];
        }
      }

      if (filesToUse.length > 0) {
      testMatch = filesToUse.map(normalizeFilePattern);
      // Narrow test discovery root when all files are inside tests/e2e.
      // This avoids expensive root-level scans in large monorepos.
      if (testMatch.every(file => file.startsWith('tests/e2e/'))) {
        testDir = path.join(rootDir, 'tests', 'e2e');
        testMatch = testMatch.map(file => file.replace(/^tests\/e2e\//, ''));
      }
      console.log(`[playwright] collected ${filesToUse.length} test files`);
    } else {
      console.log('[playwright] no collected files after filtering');
    }
  } else {
    console.log('[playwright] no collected files');
  }
} catch (error) {
  console.warn('[playwright] test collection failed:', error.message);
  console.warn('   Make sure setup metadata is available when needed.');
}
}

console.log('[playwright] rootDir:', rootDir);
console.log('[playwright] testDir:', testDir);
console.log('[playwright] testMatch:', testMatch);
console.log('[playwright] proxy baseURL:', baseURL);
console.log('[playwright] target origin:', runtimeInfo.runtime.target_origin);

const globalSetupHostsPath = path.join(__dirname, 'global-setup-hosts.js');

// 截图、trace、失败产物等统一落在 tests/e2e/test-results（勿用仓库根 test-results）
const e2eTestResultsDir = path.join(__dirname, 'test-results');

module.exports = defineConfig({
  rootDir,
  globalSetup: process.env.PLAYWRIGHT_E2E_HOSTS_FQDN ? globalSetupHostsPath : undefined,
  globalTeardown: undefined,
  outputDir: e2eTestResultsDir,
  timeout: resolvedTimeout,
  expect: {
    timeout: resolvedExpectTimeout,
  },
  testDir,
  testMatch: testMatch.length > 0 ? testMatch : [
    'app/code/*/*/test/e2e/**/*.spec.js',
    'app/code/*/*/Test/e2e/**/*.spec.js',
    'app/code/*/*/test/E2E/**/*.spec.js',
    'app/code/*/*/Test/E2E/**/*.spec.js',
  ],
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: resolvedWorkers,
  reporter: process.env.PLAYWRIGHT_HTML_REPORT === '0' || process.env.CI
    ? [['list']]
    : [
        ['list'],
        [
          'html',
          {
            outputFolder: path.join(__dirname, 'playwright-report'),
            open: 'never',
          },
        ],
      ],
  // 默认复用已存在的 e2e 代理；如果端口被占用，proxy-server.js 会自动换到下一个可用端口。
  // 需要强制独占启动新代理时设置：PLAYWRIGHT_FORCE_NEW_PROXY=1
  webServer: process.env.PLAYWRIGHT_DISABLE_PROXY === '1' ? undefined : {
    command: 'node framework/proxy-server.js',
    cwd: __dirname,
    // baseURL 已动态解析（从 .active-proxy-port 读取实际端口），所以这里直接用。
    url: `${baseURL}/.well-known/weline-e2e/health`,
    reuseExistingServer: process.env.PLAYWRIGHT_FORCE_NEW_PROXY !== '1',
    timeout: Number(process.env.PLAYWRIGHT_WEBSERVER_TIMEOUT_MS || 180000),
    ignoreHTTPSErrors: true,
  },
  use: {
    baseURL: process.env.PLAYWRIGHT_DISABLE_PROXY === '1' ? runtimeInfo.runtime.target_origin : baseURL,
    ignoreHTTPSErrors: true,
    // CI 默认 headless；本地默认 headed。无显示环境可设 PLAYWRIGHT_HEADLESS=1。
    headless: Boolean(process.env.CI) || process.env.PLAYWRIGHT_HEADLESS === '1',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure'
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        // 直连 WLS https://127.0.0.1 自签证书时，避免 Chromium 落到 chrome-error 页
        launchOptions: {
          executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
          args: ['--ignore-certificate-errors'],
        },
      },
    },
  ],
});
