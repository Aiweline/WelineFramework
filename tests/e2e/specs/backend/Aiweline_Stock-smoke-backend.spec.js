// @weline-e2e-runtime wls
// @weline-e2e-transport proxy
// @ts-check
const fs = require('fs');
const path = require('path');
const {
  test,
  gotoBackend,
  loginAsAdmin,
  getRuntimeInfo,
  buildModuleBackendRoute,
} = require('../../framework');

/** @type {import('@playwright/test').BrowserContext | null} */
let stockSmokeContext = null;
/** @type {import('@playwright/test').Page | null} */
let stockSmokePage = null;

const FATAL_PATTERN = /404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Undefined variable|Class .* not found/i;

function ensureAdminThemeScandirDir() {
  const missingThemeDir = path.resolve(
    __dirname,
    '../../../../app/code/Weline/Admin/view/tpl/zh_Hans_CN/theme'
  );
  fs.mkdirSync(missingThemeDir, { recursive: true });
}

function ensurePageBuilderTplScandirDir() {
  const missingPageBuilderTplDir = path.resolve(
    __dirname,
    '../../../../app/code/GuoLaiRen/PageBuilder/view/tpl'
  );
  fs.mkdirSync(missingPageBuilderTplDir, { recursive: true });
}

function ensureCatalogTplScandirDir() {
  const missingCatalogTplDir = path.resolve(
    __dirname,
    '../../../../app/code/WeShop/Catalog/view/tpl'
  );
  fs.mkdirSync(missingCatalogTplDir, { recursive: true });
}

function ensurePlaywrightArtifactsDir() {
  const testResultsDir = path.resolve(__dirname, '../../../../test-results');
  fs.mkdirSync(testResultsDir, { recursive: true });
}

/** 当前工作区是否包含 Aiweline_Stock 源码（可选模块，缺失时不应判失败）。 */
function isAiwelineStockModulePresent() {
  const roots = [
    path.resolve(__dirname, '../../../../app/code/Aiweline/Stock/register.php'),
    path.resolve(__dirname, '../../../../app/code/Weline/Stock/register.php'),
  ];
  return roots.some((p) => fs.existsSync(p));
}

/**
 * 从运行时 modules.routers 解析后台路由前缀；模块未注册时返回空数组。
 * @param {string[][]} segmentVariants
 */
function stockModuleBackendRouteCandidates(segmentVariants) {
  /** @type {string[]} */
  const out = [];
  for (const segments of segmentVariants) {
    try {
      out.push(buildModuleBackendRoute('Aiweline_Stock', ...segments));
    } catch {
      // 模块未安装或未进 runtime 时忽略
    }
  }
  return out;
}

async function gotoBackendRaw(page, route, { timeoutMs = 60000, settleMs = 800 } = {}) {
  const runtime = getRuntimeInfo();
  const origin = runtime.runtime.target_origin;
  const backendPrefixPath = runtime.paths.backend_prefix_path || '/';

  const normalizedRoute = String(route ?? '')
    .trim()
    .replace(/^\/+|\/+$/g, '');

  const fullPath = `${backendPrefixPath.replace(/\/+$/, '')}/${normalizedRoute}`;
  const url = new URL(fullPath, origin).toString();

  await page.goto(url, { waitUntil: 'commit', timeout: timeoutMs });
  if (settleMs > 0) {
    await page.waitForTimeout(settleMs);
  }
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string[]} routeCandidates
 * @param {RegExp} expectedTextRe
 * @param {{ navigateTimeoutMs?: number, settleMs?: number }} [opts]
 */
async function gotoFirstMatchingExpected(page, routeCandidates, expectedTextRe, opts = {}) {
  const navigateTimeoutMs = opts.navigateTimeoutMs ?? 60000;
  const settleMs = opts.settleMs ?? 800;
  let lastRoute = '';
  let lastBody = '';

  for (const route of routeCandidates) {
    try {
      await gotoBackend(page, route, {
        timeout: navigateTimeoutMs,
        settleMs,
      });
    } catch (error) {
      const message = String(error && error.message ? error.message : error);
      // 首屏/冷启动偶发超过 20s：超时后换下一候选，避免单一路径拖死整条 smoke
      if (/Timeout|timeout|Navigation|ECONNRESET/i.test(message)) {
        lastRoute = route;
        lastBody = `[navigation error] ${message}`;
        continue;
      }
      throw error;
    }

    const bodyText = await page.locator('body').innerText().catch(() => '');
    lastRoute = route;
    lastBody = bodyText;

    if (!bodyText || bodyText.trim().length === 0) {
      continue;
    }

    if (FATAL_PATTERN.test(bodyText)) {
      // 404 页面尝试无 @backend 包装的 raw 路由，若仍失败则继续其它候选
      if (/404/i.test(bodyText)) {
        try {
          await gotoBackendRaw(page, route, { timeoutMs: navigateTimeoutMs, settleMs });
          const rawBodyText = await page.locator('body').innerText().catch(() => '');
          lastBody = rawBodyText;

          if (expectedTextRe.test(rawBodyText)) {
            return;
          }
        } catch (error) {
          // ignore and fallthrough to next candidate
        }

        continue;
      }

      throw new Error(
        `Aiweline_Stock smoke: fatal pattern matched after gotoBackend(${route}). ` +
        `Excerpt: ${String(bodyText).slice(0, 500)}`
      );
    }

    if (expectedTextRe.test(bodyText)) {
      return;
    }

    // 走到这里表示 bodyText 非 fatal，但也没匹配预期文本；若是 404 则再尝试 raw
    if (/404/i.test(bodyText)) {
      try {
        await gotoBackendRaw(page, route, { timeoutMs: navigateTimeoutMs, settleMs });
        const rawBodyText = await page.locator('body').innerText().catch(() => '');
        lastBody = rawBodyText;
        if (expectedTextRe.test(rawBodyText)) {
          return;
        }
      } catch (error) {
        // ignore and fallthrough to next candidate
      }
    }
  }

  throw new Error(
    `Aiweline_Stock smoke failed. ` +
    `Tried routes: ${JSON.stringify(routeCandidates)}. ` +
    `Last route: ${lastRoute}. ` +
    `Last body excerpt: ${String(lastBody).slice(0, 500)}`
  );
}

test.describe.serial('Aiweline_Stock backend smoke', () => {
  // 单条用例：登录已迁到 beforeAll，此处主要为冷启动导航 + 慢查询留余量
  test.describe.configure({ retries: 0, timeout: 300000 });

  test.beforeAll(async ({ browser }) => {
    if (!isAiwelineStockModulePresent()) {
      return;
    }
    ensurePlaywrightArtifactsDir();
    ensureAdminThemeScandirDir();
    ensurePageBuilderTplScandirDir();
    ensureCatalogTplScandirDir();
    stockSmokeContext = await browser.newContext();
    stockSmokePage = await stockSmokeContext.newPage();
    // 优先走 wls，会话引导失败时再回退 fpm，避免仅 fpm 场景下数据库抖动导致全部用例失败
    await loginAsAdmin(stockSmokePage, { bootstrapModes: ['wls', 'fpm'] });
  });

  test.afterAll(async () => {
    await stockSmokeContext?.close().catch(() => {});
    stockSmokeContext = null;
    stockSmokePage = null;
  });

  test.beforeEach(async ({}, testInfo) => {
    if (!isAiwelineStockModulePresent()) {
      testInfo.skip(true, 'Aiweline_Stock 未包含在当前 app/code 工作区，跳过可选模块 smoke。');
      return;
    }
    if (!stockSmokePage) {
      testInfo.skip(true, 'Stock smoke：beforeAll 未初始化页面。');
    }
  });

  // 冷启动时首条后台导航 commit 易超时：先跑 verification 预热 WLS/代理，再打开统计更重的 index
  test('renders algorithm verification statistics without fatal errors', async () => {
    await gotoFirstMatchingExpected(
      /** @type {import('@playwright/test').Page} */ (stockSmokePage),
      [
        ...stockModuleBackendRouteCandidates([['verification', 'index'], ['verification']]),
        'stock/backend/verification/index',
        'stock/backend/verification',
        'aiweline-stock/backend/verification/index',
        'aiweline-stock/backend/verification',
      ],
      /算法验证统计/i,
      { navigateTimeoutMs: 60000 },
    );
  });

  test('renders stock analysis home page without fatal errors', async () => {
    // env router 为 stock；优先 runtime 解析路径，aiweline-stock 仅作模板历史兼容
    await gotoFirstMatchingExpected(
      /** @type {import('@playwright/test').Page} */ (stockSmokePage),
      [
        ...stockModuleBackendRouteCandidates([['index', 'index'], ['index']]),
        'stock/backend/index/index',
        'stock/backend/index',
        'aiweline-stock/backend/index/index',
        'aiweline-stock/backend/index',
      ],
      /股票分析系统/i,
      { navigateTimeoutMs: 90000, settleMs: 1200 },
    );
  });

  test('renders AI finance model config page without fatal errors', async () => {
    // 中文标题 + en_US 下「AI Finance · Analyzer Model Config / Analyzer adapter config」（见 Stock i18n/en_US.csv）
    await gotoFirstMatchingExpected(
      /** @type {import('@playwright/test').Page} */ (stockSmokePage),
      [
        ...stockModuleBackendRouteCandidates([
          ['aiFinanceConfig', 'index'],
          ['aiFinanceConfig'],
          ['ai_finance_config', 'index'],
          ['ai_finance_config'],
        ]),
        'stock/backend/aiFinanceConfig/index',
        'stock/backend/aiFinanceConfig',
        'stock/backend/ai_finance_config/index',
        'stock/backend/ai_finance_config',
        'stock/backend/ai-finance-config/index',
        'stock/backend/ai-finance-config',
        'aiweline-stock/backend/aiFinanceConfig/index',
        'aiweline-stock/backend/aiFinanceConfig',
        'aiweline-stock/backend/ai_finance_config/index',
        'aiweline-stock/backend/ai_finance_config',
      ],
      /AI财经[\s·]*解析模型配置|解析模型配置|AI Finance[\s·]*Analyzer Model Config|Analyzer adapter config/i,
      { navigateTimeoutMs: 60000 },
    );
  });
});

