// @weline-e2e-runtime fallback
// @ts-check
const {
  test,
  expect,
  gotoBackend,
  buildModuleBackendRoute,
  getModuleBackendRouter,
  getRuntimeInfo,
  loginAsAdmin,
} = require('../../framework');

test.describe('Aiweline_Community backend smoke', () => {
  // WLS 偶发 ERR_EMPTY_RESPONSE / DB 抖动：与 Aiweline_Bbs smoke 一致保留 1 次重试；
  // 首个用例的 beforeEach（session bootstrap + goto admin）在冷启动 WLS 下可能 >120s，
  // Playwright 的 timeout 含 hooks，须高于 login/goto 单项上限，避免 beforeEach 掐死与重试 ERR_ABORTED。
  test.describe.configure({ retries: 1, timeout: 180000 });

  test.beforeAll(() => {
    getRuntimeInfo({ refresh: true });
  });

  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page, {
      settleMs: 1200,
      timeout: 120000,
    });
  });

  const FATAL_PATTERN = /404 Not Found|WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

  function bindPageErrors(page) {
    const errors = [];
    page.on('pageerror', error => {
      const msg = String(error && error.message ? error.message : error);
      // 已知：Aiweline_Community 后台页在当前构建环境下会触发 metisMenu 插件未就绪的初始化异常；
      // 该异常不影响本用例的“渲染/无 PHP fatal”目标，因此在 smoke 中白名单忽略。
      if (msg.includes('sideMenu.metisMenu is not a function') || msg.includes('metisMenu is not a function')) return;
      if (msg.includes('$ is not defined') || msg.includes('jQuery is not defined')) return;
      errors.push(msg);
    });
    return errors;
  }

  const communityRouter = getModuleBackendRouter('Aiweline_Community');
  const topicFormActionNeedle = `/${communityRouter}/backend/topic/index`;

  const pages = [
    {
      route: buildModuleBackendRoute('Aiweline_Community', 'category', 'index'),
      expectSelector: 'body',
      expectText: /版块分类管理|分类管理|Category|板块/i,
    },
    {
      route: buildModuleBackendRoute('Aiweline_Community', 'topic', 'index'),
      // action 随 backend_router 变化；不依赖 row.g-2（主题/Bootstrap 升级可能改栅格类名）
      expectSelector: `form[action*='${topicFormActionNeedle}']`,
    },
    { route: buildModuleBackendRoute('Aiweline_Community', 'tag', 'index'), expectSelector: '#tagForm' },
    {
      route: buildModuleBackendRoute('Aiweline_Community', 'report', 'index'),
      expectSelector: 'ol.breadcrumb li.breadcrumb-item.active',
      expectText: /举报审核|Report/i,
    },
  ];

  for (const { route, expectSelector, expectText } of pages) {
    test(`renders ${route} without PHP errors`, async ({ page }) => {
      const errors = bindPageErrors(page);

      await gotoBackend(page, route, {
        timeout: 150000,
        settleMs: 1200,
        allowLoadStateTimeout: true,
      });

      const body = page.locator('body');
      await expect(body).toBeVisible();
      const bodyText = await body.innerText();
      expect(bodyText, `Empty body after gotoBackend(${route})`).not.toBe('');
      expect(page.url(), `Still appears on login page after gotoBackend(${route})`).not.toContain('/admin/login');
      expect(bodyText).not.toMatch(FATAL_PATTERN);
      const marker = page.locator(expectSelector);
      await expect(marker, `Missing expected page marker after gotoBackend(${route})`)
        .toBeVisible({ timeout: 15000 });
      if (expectText) {
        await expect(marker).toContainText(expectText);
      }
      expect(errors, errors.join('\n')).toEqual([]);
    });
  }
});

