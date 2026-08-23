/**
 * Weline_Review backend moderation route, media preview, filter, and approval flow.
 *
 * Seed one pending review before the moderation case and pass its title through
 * WELINE_REVIEW_E2E_TITLE. The test never creates hidden production fallbacks.
 *
 * @weline-e2e-spec { module: Weline_Review, type: flow, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Review';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ROUTE = buildModuleBackendRoute(MODULE, 'review');
const REVIEW_TITLE = String(process.env.WELINE_REVIEW_E2E_TITLE || '').trim();

async function openReviewManagement(page) {
  await loginAsAdmin(page);
  await gotoBackend(page, ROUTE, { timeout: 60000, settleMs: 800 });
  await waitForBackendShellReady(page);
  await expect(page.locator('[data-review-role="management"]')).toBeVisible();
  await expect(page.locator('body')).not.toContainText(FATAL);
}

moduleDescribe(test, MODULE, 'Weline_Review 后台审核', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'REVIEW-BACKEND-001' },
    '后台评论管理入口可达且筛选控件可用',
    async ({ page }) => {
      await openReviewManagement(page);
      await expect(page.locator('[data-review-role="status-filter"]')).toBeVisible();
      await expect(page.locator('body')).toContainText(/商品与目录|Products\s*(?:&|and)\s*Catalog/i);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'REVIEW-MODERATION-001' },
    '待审核图文视频评论可筛选并通过',
    async ({ page }) => {
      test.skip(REVIEW_TITLE === '', 'Set WELINE_REVIEW_E2E_TITLE to the exact seeded pending review title.');
      await openReviewManagement(page);

      const filter = page.locator('[data-review-role="status-filter"]');
      await filter.locator('select[name="status"]').selectOption('pending');
      await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        filter.locator('button[type="submit"]').click(),
      ]);
      await waitForBackendShellReady(page);

      const row = page.locator('[data-review-role="row"]').filter({ hasText: REVIEW_TITLE }).first();
      await expect(row).toBeVisible();
      await expect(row.locator('img')).toHaveCount(1);
      await expect(row.locator('video[controls]')).toHaveCount(1);

      const approve = row.locator('[data-review-role="approve"]');
      await approve.scrollIntoViewIfNeeded();
      await expect(approve).toBeInViewport();
      await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        approve.click(),
      ]);
      await waitForBackendShellReady(page);
      await expect(page.locator('body')).toContainText(/评论已通过|Review approved/i);

      const approvedFilter = page.locator('[data-review-role="status-filter"]');
      await approvedFilter.locator('select[name="status"]').selectOption('approved');
      await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        approvedFilter.locator('button[type="submit"]').click(),
      ]);
      await waitForBackendShellReady(page);

      const approvedRow = page.locator('[data-review-role="row"]').filter({ hasText: REVIEW_TITLE }).first();
      await expect(approvedRow.locator('[data-review-role="status"]')).toContainText(/已通过|Approved/);
      await expect(page.locator('body')).not.toContainText(FATAL);
    }
  );
});
