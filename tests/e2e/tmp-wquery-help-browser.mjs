import { chromium } from '@playwright/test';

const baseUrl = process.env.WQUERY_TEST_URL || 'https://p11005ce4.weline.test:10502/maintenance';

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ ignoreHTTPSErrors: true });
await context.addInitScript(() => {
  // 维护页 recovery probe 会在探测到 200 后 reload，阻塞 w_query 浏览器实测
  try {
    Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
  } catch (_) {}
});
const page = await context.newPage();

const result = {
  url: baseUrl,
  errors: [],
  checks: {},
};

page.on('pageerror', (error) => {
  result.errors.push(`pageerror: ${error.message}`);
});
page.on('framenavigated', (frame) => {
  if (frame === page.mainFrame()) {
    result.errors.push(`navigation: ${frame.url()}`);
  }
});

try {
  const response = await page.goto(baseUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  result.checks.httpStatus = response?.status() ?? null;

  await page.waitForFunction(() => typeof window.w_query === 'function', null, { timeout: 60000 });

  const allChecks = await page.evaluate(async () => {
    const out = {
      hasWQuery: typeof window.w_query === 'function',
      hasQueryHelp: typeof window.Weline?.Query?.help === 'function',
      runtimeEndpoint:
        window.WelineApiConfig?.endpoint
        || window.Weline?.config?.api?.endpoint
        || window.runtimeConfig?.api?.endpoint
        || null,
      defaultClientEndpoint: null,
    };

    try {
      const client = await window.Weline.Api.getClient();
      out.defaultClientEndpoint = client.config?.endpoint || null;
      out.allProviders = await window.w_query();
      out.providerCount = Array.isArray(out.allProviders) ? out.allProviders.length : 0;
      out.hasQueryHelpProvider = Array.isArray(out.allProviders)
        && out.allProviders.some((p) => p.provider === 'query_help');
    } catch (error) {
      out.allProviders = { error: String(error?.message || error) };
    }

    try {
      out.providers = await window.Weline.Api.call('query_help', 'providers', {});
    } catch (error) {
      out.providers = { error: String(error?.message || error) };
    }

    try {
      out.cartHelp = await window.w_query('cart');
      if (out.cartHelp && Array.isArray(out.cartHelp.operations)) {
        out.cartOperationsAllFrontend = out.cartHelp.operations.every((op) => op.frontend === true);
        out.cartOperationNames = out.cartHelp.operations.map((op) => op.name);
        out.cartOperationCount = out.cartHelp.operations.length;
      }
    } catch (error) {
      out.cartHelp = { error: String(error?.message || error) };
    }

    try {
      const CartApi = await window.Weline.Api.resource('cart');
      out.cartCount = await CartApi.count({});
    } catch (error) {
      out.cartCount = { error: String(error?.message || error) };
    }

    return out;
  });

  Object.assign(result.checks, allChecks);
} catch (error) {
  result.errors.push(`fatal: ${error?.message || error}`);
} finally {
  await browser.close();
}

const providersOk = Array.isArray(result.checks.providers) && result.checks.providers.length > 0;
const allProvidersOk = Array.isArray(result.checks.allProviders) && result.checks.hasQueryHelpProvider === true;
const cartHelpOk = result.checks.cartHelp && !result.checks.cartHelp.error && result.checks.cartOperationsAllFrontend === true;
const cartApiOk = result.checks.cartCount && !result.checks.cartCount.error;
const endpointOk = typeof result.checks.defaultClientEndpoint === 'string'
  && result.checks.defaultClientEndpoint.includes('/api123/framework/query-bin');
const pass = result.errors.filter((e) => !e.startsWith('navigation:')).length === 0
  && result.checks.hasQueryHelp
  && endpointOk
  && providersOk
  && allProvidersOk
  && cartHelpOk
  && cartApiOk;

const passBreakdown = {
  noFatalErrors: result.errors.filter((e) => !e.startsWith('navigation:')).length === 0,
  hasQueryHelp: !!result.checks.hasQueryHelp,
  endpointOk,
  providersOk,
  allProvidersOk,
  cartHelpOk,
  cartApiOk,
};
console.log(JSON.stringify({ ...result, pass, passBreakdown }, null, 2));
process.exit(pass ? 0 : 1);
