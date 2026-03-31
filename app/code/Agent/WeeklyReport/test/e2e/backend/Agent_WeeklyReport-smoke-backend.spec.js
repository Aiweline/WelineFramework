const path = require('path');
const fs = require('fs');

const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../../../../../../tests/e2e/framework');

const MODULE_NAME = 'Agent_WeeklyReport';

function toKebabCase(input) {
  let s = String(input ?? '').trim();
  s = s.replace(/_/g, '-');
  s = s.replace(/([a-z0-9])([A-Z])/g, '$1-$2');
  s = s.replace(/([A-Z]+)([A-Z][a-z0-9]+)/g, '$1-$2');
  s = s.replace(/-+/g, '-');
  return s.toLowerCase();
}

function getBackendGroupsWithIndex(basePath) {
  const backendRoot = path.join(basePath, 'Controller', 'Backend');
  if (!fs.existsSync(backendRoot) || !fs.statSync(backendRoot).isDirectory()) {
    return [];
  }

  const entries = fs.readdirSync(backendRoot, { withFileTypes: true });
  const groups = [];

  for (const entry of entries) {
    if (!entry.isDirectory()) {
      continue;
    }

    const groupDir = entry.name;
    const indexPhp = path.join(backendRoot, groupDir, 'Index.php');
    if (fs.existsSync(indexPhp) && fs.statSync(indexPhp).isFile()) {
      groups.push(toKebabCase(groupDir));
    }
  }

  // Sort for deterministic candidate ordering.
  return groups.sort();
}

test.describe('Agent_WeeklyReport backend smoke', () => {
  test.describe.configure({ retries: 1 });

  test('renders at least one backend route without PHP errors', async ({ page }) => {
    test.setTimeout(60000);
    const basePath = path.resolve(__dirname, '../../../');

    await loginAsAdmin(page, { timeout: 90000 });

    const groups = getBackendGroupsWithIndex(basePath);
    const forbiddenRe = /WLS Runtime Error|ParseError|syntax error|Fatal error/i;

    // 限制候选尝试次数，满足“最大 5 次”的要求。
    const batchSize = 5;
    const groupsBatch = groups.slice(0, batchSize);

    const candidates = groupsBatch.length > 0
      ? groupsBatch.map(group => buildModuleBackendRoute(MODULE_NAME, group))
      : [buildModuleBackendRoute(MODULE_NAME)];

    const routesTried = [];
    let renderedRoute = null;
    let renderedBodyText = '';

    for (const route of candidates) {
      const response = await gotoBackend(page, route, {
        timeout: 90000,
        settleMs: 1200,
      });

      const status = response?.status?.() ?? null;
      routesTried.push({ route, status });

      if (status === 404) {
        continue;
      }

      const bodyText = await page.locator('body').textContent().catch(() => '');
      if (forbiddenRe.test(bodyText || '')) {
        continue;
      }

      renderedRoute = route;
      renderedBodyText = bodyText || '';
      break;
    }

    // 按要求输出给调用方收集：routesTried + 成功/失败。
    console.log('[Agent_WeeklyReport-smoke] routesTried:', JSON.stringify(routesTried));

    if (!renderedRoute) {
      throw new Error(`no candidate routes rendered successfully (module=${MODULE_NAME})`);
    }

    // loop 已经做过 forbiddenRe 检测；此处只做纯同步断言避免二次等待导致用例卡住
    expect(forbiddenRe.test(renderedBodyText || '')).toBe(false);
  });
});

