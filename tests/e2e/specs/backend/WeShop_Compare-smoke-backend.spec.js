// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
// @ts-check
const {
  test,
  expect,
  gotoBackend,
  loginAsAdmin,
  buildModuleBackendRoute,
  getRuntimeInfo,
} = require('../../framework');

test.describe('WeShop Compare backend (smoke)', () => {
  test.describe.configure({ retries: 1 });

  const FATAL_PATTERN =
    /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Undefined variable|Class .* not found/i;

  test('renders compare management entry without PHP fatal errors', async ({ page }) => {
    // 先完成后台登录，避免未认证时被重定向到登录页造成误判。
    await loginAsAdmin(page, { timeout: 90000 });
    const body = page.locator('body');
    const runtime = getRuntimeInfo();
    const backendPrefixPath = String(runtime.paths?.backend_prefix_path || '/admin')
      .replace(/\/+$/, '')
      .toLowerCase();

    const candidateRoutes = [
      buildModuleBackendRoute('WeShop_Compare', 'compare'),
      buildModuleBackendRoute('WeShop_Compare', 'index'),
      buildModuleBackendRoute('WeShop_Compare'),
    ];

    const navigationErrors = [];
    for (const route of candidateRoutes) {
      try {
        await gotoBackend(page, route, {
          timeout: 30000,
          settleMs: 800,
        });
        await expect(body).toBeVisible();
        await expect(body).not.toContainText(FATAL_PATTERN);
        const currentUrl = new URL(page.url());
        const currentPathname = currentUrl.pathname.toLowerCase();
        const normalizedRoute = String(route || '').replace(/^\/+/, '');
        expect(currentPathname).not.toContain(`${backendPrefixPath}/login`);
        // URL 里可能包含语言/币种段，不能要求 `${backendPrefixPath}/${normalizedRoute}` 连续出现。
        expect(currentPathname).toContain(`/${normalizedRoute.toLowerCase()}`);
        expect(currentPathname).not.toContain('/@backend/');
        return;
      } catch (error) {
        navigationErrors.push(`${route}: ${error?.message || String(error)}`);
      }
    }

    throw new Error(
      `WeShop_Compare backend smoke failed to find non-fatal route. ` +
      `Tried routes: ${candidateRoutes.join(', ')}. ` +
      `Navigation errors: ${navigationErrors.join(' | ')}`
    );
  });

});
