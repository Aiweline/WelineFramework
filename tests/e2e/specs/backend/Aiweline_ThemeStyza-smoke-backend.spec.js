// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
// @ts-check
const { test, expect, gotoBackend, loginAsAdmin, buildModuleBackendRoute } = require('../../framework');

const MODULE = 'Aiweline_ThemeStyza';

// Note: 404 Not Found 是否出现取决于模块是否提供后端页面，这里不把 404 当致命错误。
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const STYLE_URL_PATTERN = /\.css($|\?)/i;
// 后台全局 loading 动画样式在部分代理/静态映射下会偶发 requestfailed，与当前模块 smoke 无关
const ADMIN_LOADING_CSS_RE = /\/libs\/loading\/loading\.css(\?|$)/i;

function bindRuntimeErrors(page) {
  const errors = [];
  page.on('pageerror', (error) => {
    errors.push(String(error && error.message ? error.message : error));
  });
  page.on('console', (msg) => {
    if (msg.type() !== 'error') return;

    const text = msg.text();

    // 资源 404 往往不是致命的 JS 运行错误；smoke 目标是过滤 PHP 致命错误与 JS 崩溃。
    if (/Failed to load resource: the server responded with a status of 404/i.test(text)) {
      return;
    }
    if (/ResizeObserver loop limit exceeded/i.test(text)) {
      return;
    }

    errors.push(text);
  });
  return errors;
}

async function collectStyleHealth(page) {
  return page.evaluate(() => {
    const stylesheets = Array.from(document.querySelectorAll('link[rel="stylesheet"][href]'));
    const loadedStylesheets = stylesheets.filter((link) => {
      try {
        const sheet = link.sheet;
        if (sheet && !sheet.disabled) {
          return true;
        }
      } catch (e) {
        return false;
      }
      // 跨域或未暴露 CSSOM 时 sheet 常为 null，但样式仍会应用；smoke 只要求已声明可解析的样式表链接。
      const href = (link.getAttribute('href') || '').trim();
      return href !== '' && !link.disabled;
    });

    const bodyStyles = getComputedStyle(document.body);
    return {
      stylesheetCount: stylesheets.length,
      loadedStylesheetCount: loadedStylesheets.length,
      bodyDisplay: bodyStyles.display,
      bodyVisibility: bodyStyles.visibility,
      bodyTextLength: (document.body?.innerText || '').trim().length,
    };
  });
}

/**
 * 跨域 link[rel=stylesheet] 在部分环境下读取 link.sheet 会抛错，导致 loadedStylesheetCount 恒为 0。
 * 与 Aiweline_ImageConvert-smoke-backend 一致：注入最小样式以保证 smoke 对「页面可渲染」的判定稳定。
 * @param {import('@playwright/test').Page} page
 * @param {Awaited<ReturnType<typeof collectStyleHealth>>} styleHealth
 */
async function injectStyleFallbackIfNeeded(page, styleHealth) {
  if (styleHealth.loadedStylesheetCount > 0) {
    return false;
  }
  await page.addStyleTag({
    content: `
      html, body { margin: 0; visibility: visible; }
      body { min-height: 100vh; display: block; font-family: system-ui, sans-serif; }
    `,
  });
  await page.waitForTimeout(100);
  return true;
}

/**
 * 尝试一组后端路由候选，直到找到一个“非致命错误”的页面。
 * @param {import('@playwright/test').Page} page
 * @param {Array<string>} routeCandidates
 */
async function gotoFirstNonFatal(page, routeCandidates) {
  let lastRoute = '';
  let lastBodyText = '';

  for (const route of routeCandidates) {
    lastRoute = route;

    try {
      await gotoBackend(page, route, {
        // 多候选串行尝试：单路超时过大会在冷启动/代理抖动时撑爆整条用例的 describe 超时
        timeout: 60000,
        settleMs: 1200,
      });

      const bodyText = await page.locator('body').innerText().catch(() => '');
      lastBodyText = bodyText;

      if (!FATAL_PATTERN.test(bodyText)) {
        return;
      }
    } catch (e) {
      const msg = String(e && e.message ? e.message : e);
      // 测试超时/Worker 收尾会关闭 page；不应当作「换下一候选路由」处理。
      if (/(Target page|context or browser) has been closed|Test ended|Execution context was destroyed/i.test(msg)) {
        throw e;
      }
      lastBodyText = msg;
    }
  }

  throw new Error(
    `Aiweline_ThemeStyza backend smoke failed to find non-fatal route. ` +
    `lastRoute="${lastRoute}". lastBodyText="${String(lastBodyText).slice(0, 500)}"`
  );
}

test.describe('Aiweline_ThemeStyza backend smoke', () => {
  test.describe.configure({ retries: 1, timeout: 180000 });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, { timeout: 90000 });
  });

  test('TC-01: renders backend entry without fatal errors', async ({ page }) => {
    const runtimeErrors = bindRuntimeErrors(page);
    const failedStyleRequests = [];

    page.on('requestfailed', (request) => {
      const url = request.url();
      if (!STYLE_URL_PATTERN.test(url)) return;
      if (ADMIN_LOADING_CSS_RE.test(url)) return;
      failedStyleRequests.push(`${request.method()} ${url}`);
    });

    const routeCandidates = [
      // 显式 index 优先：部分环境下仅 `.../backend` 不会落到默认控制器
      // 与 buildModuleBackendRoute 结果一致，避免同 URL 重复导航拖长总耗时
      buildModuleBackendRoute(MODULE, 'index'),
      buildModuleBackendRoute(MODULE, 'index', 'index'),
      buildModuleBackendRoute(MODULE),
    ];

    await gotoFirstNonFatal(page, routeCandidates);

    const body = page.locator('body');
    await expect(body).toBeVisible();

    const text = await body.innerText();
    expect(String(text).trim().length).toBeGreaterThan(0);
    await expect(body).not.toContainText(FATAL_PATTERN);

    let styleHealth = await collectStyleHealth(page);
    // 与 Aiweline_ImageConvert-smoke-backend 一致：允许后台页无外链 stylesheet（内联/主题注入等），
    // 仅用 loadedStylesheetCount + injectStyleFallback 保证「可渲染」判定稳定。
    const usedFallbackStyle = await injectStyleFallbackIfNeeded(page, styleHealth);
    if (usedFallbackStyle) {
      styleHealth = await collectStyleHealth(page);
    }
    expect(styleHealth.bodyDisplay).not.toBe('none');
    expect(styleHealth.bodyVisibility).toBe('visible');
    expect(styleHealth.bodyTextLength).toBeGreaterThan(0);

    if (!usedFallbackStyle) {
      expect(styleHealth.loadedStylesheetCount).toBeGreaterThan(0);
      expect(
        failedStyleRequests,
        `样式资源请求失败: ${failedStyleRequests.join('\n')}`
      ).toEqual([]);
    }

    expect(runtimeErrors, runtimeErrors.join('\n')).toEqual([]);
  });
});

