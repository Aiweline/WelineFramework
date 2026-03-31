// @weline-e2e-runtime fallback
// @ts-check
process.env.PLAYWRIGHT_DISABLE_PROXY = '1';
const {
  test,
  expect,
  gotoBackend,
  loginAsAdmin,
  buildModuleBackendRoute,
} = require('../../framework');
const fs = require('fs');
const path = require('path');

const FATAL_PATTERN = /404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function bindPageErrors(page) {
  const errors = [];
  page.on('pageerror', error => errors.push(String(error && error.message ? error.message : error)));
  return errors;
}

function ensureScreenshotDir() {
  const dir = path.resolve(__dirname, '../../results/guolairen-blog-smoke');
  fs.mkdirSync(dir, { recursive: true });
  return dir;
}

function ensureBackendTplScandirDir() {
  const tplDir = path.resolve(
    __dirname,
    '../../../../app/code/Weline/Admin/view/tpl'
  );
  fs.mkdirSync(tplDir, { recursive: true });
}

async function captureCheckScreenshot(page, name) {
  const dir = ensureScreenshotDir();
  await page.screenshot({
    path: path.join(dir, `${name}.png`),
    fullPage: true,
  });
}

test.describe('GuoLaiRen_Blog backend smoke', () => {
  test.describe.configure({ retries: 1 });

  test.beforeEach(async ({ page }) => {
    ensureBackendTplScandirDir();
    await loginAsAdmin(page);
  });

  test('TC-01: renders blog post index without PHP errors', async ({ page }) => {
    const errors = bindPageErrors(page);

    const route = buildModuleBackendRoute('GuoLaiRen_Blog', 'post', 'index');
    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();

    const text = await body.innerText();
    await captureCheckScreenshot(page, 'tc01-blog-post-index');
    expect(text).toMatch(/博客管理|文章管理|blog|post|GuoLaiRen_Blog/i);
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
  });

  test('TC-02: renders blog category index without PHP errors', async ({ page }) => {
    const errors = bindPageErrors(page);

    const route = buildModuleBackendRoute('GuoLaiRen_Blog', 'category', 'index');
    await gotoBackend(page, route, {
      timeout: 60000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();

    const text = await body.innerText();
    await captureCheckScreenshot(page, 'tc02-blog-category-index');
    expect(text).toMatch(/分类管理|博客分类|category|GuoLaiRen_Blog/i);
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
  });

  test('TC-03: renders Trends 配置 page without PHP errors', async ({ page }) => {
    const errors = bindPageErrors(page);

    const route = buildModuleBackendRoute('GuoLaiRen_Blog', 'trends-config', 'index');
    await gotoBackend(page, route, {
      timeout: 90000,
      settleMs: 1200,
    });

    const body = page.locator('body');
    await expect(body).toBeVisible();

    const text = await body.innerText();
    await captureCheckScreenshot(page, 'tc03-trends-config-index');
    expect(text).toMatch(/Trends\s*配置|Trends 配置|trends|config|GuoLaiRen_Blog/i);
    expect(text).not.toMatch(FATAL_PATTERN);
    expect(errors, errors.join('\n')).toEqual([]);
  });
});

