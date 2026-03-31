// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
// @ts-check
const { test, expect, gotoFrontend, getRuntimeInfo } = require('../../framework');

// 模组 env router 为空串：无独立后台路由，smoke 走站点前台 /index（与 http:request /index 一致）。
// 重点：无 PHP/WLS fatal、无未捕获的前端运行时异常。
// 勿在 goto 中强制 useProxy:false：与 PLAYWRIGHT_DISABLE_PROXY / 代理模式保持一致，避免直连到错误的 https:443 而长时间挂起。
//
// 仅用字面量片段 + 窄正则判断致命页；避免宽泛的 `Class .* not found` 等在正文/内联说明中误伤（与 GuoLaiRen_Desensitization-smoke 一致）。
const FATAL_SNIPPETS = [
  'WLS Runtime Error',
  'Fatal error',
  'ParseError',
  'syntax error',
  'Uncaught',
  'Call to undefined',
  'Undefined variable',
];
/** PHP 8+ 典型：`Class "Foo\\Bar" not found` */
const PHP_CLASS_NOT_FOUND_RE = /Class\s+["'][^"']+["']\s+not found/i;

/**
 * @param {string} bodyText
 */
function bodyLooksFatal(bodyText) {
  const t = String(bodyText || '');
  if (FATAL_SNIPPETS.some(s => t.includes(s))) {
    return true;
  }
  return PHP_CLASS_NOT_FOUND_RE.test(t);
}

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', err => errors.push(String(err && err.message ? err.message : err)));
  return errors;
}

function assertNoPageErrors(errors) {
  expect(errors, errors.join('\n')).toEqual([]);
}

/**
 * Try multiple route candidates until we find one without fatal errors.
 * @param {import('@playwright/test').Page} page
 * @param {string[]} routeCandidates
 * @param {import('@playwright/test').TestInfo} [testInfo]
 */
async function gotoFirstNonFatal(page, routeCandidates, testInfo) {
  let lastBody = '';

  const gotoOpts = {
    timeout: 60000,
    settleMs: 800,
  };

  for (const route of routeCandidates) {
    await gotoFrontend(page, route, gotoOpts);
    const bodyText = await page.locator('body').innerText().catch(() => '');
    lastBody = bodyText;

    const hasFatal = bodyLooksFatal(bodyText);
    if (!hasFatal) {
      return;
    }

    if (testInfo) {
      const screenshotName = `fatal-${route.replace(/[^\w-]+/g, '_') || 'root'}.png`;
      await page.screenshot({
        path: testInfo.outputPath(screenshotName),
        fullPage: true,
      }).catch(() => {});
    }
  }

  throw new Error(
    `Aiweline_PlayingInChina smoke failed: fatal matched. ` +
    `Tried routes: ${JSON.stringify(routeCandidates)}. ` +
    `Last body (trim): ${String(lastBody).slice(0, 500)}`
  );
}

test.describe('Aiweline_PlayingInChina backend smoke', () => {
  test.describe.configure({ retries: 1, timeout: 120000 });

  test.beforeAll(() => {
    getRuntimeInfo({ refresh: true });
  });

  test('renders index page without fatal errors', async ({ page }, testInfo) => {
    const errors = bindPageErrors(page);

    // 优先 `index`，并保留根路由兜底，增强不同实例下的稳定性。
    await gotoFirstNonFatal(
      page,
      [
        '/index',
        '/',
      ],
      testInfo,
    );

    const body = page.locator('body');
    await expect(body).toBeVisible({ timeout: 15000 });
    const bodyText = await body.innerText().catch(() => '');
    for (const snippet of FATAL_SNIPPETS) {
      await expect(body).not.toContainText(snippet, { timeout: 15000 });
    }
    expect(
      PHP_CLASS_NOT_FOUND_RE.test(bodyText),
      `unexpected PHP class-not-found in body sample: ${String(bodyText).slice(0, 500)}`,
    ).toBe(false);
    // 兜底：确保页面有实际渲染内容（避免把空白页误判为 smoke 通过）。
    expect(String(bodyText).trim().length).toBeGreaterThan(0);
    assertNoPageErrors(errors);
  });
});

