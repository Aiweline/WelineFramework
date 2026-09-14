/** @weline-e2e-spec { module: Weline_Payment, type: flow, layer: backend } */
const { test, expect, loginAsAdmin, gotoBackend, buildModuleBackendRoute, moduleDescribe, moduleCase, installBackendBrowserGuards, waitForBackendShellReady } = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Payment';

moduleDescribe(test, MODULE, 'DevRelay 后台 UI', () => {
  test.setTimeout(180000);

  moduleCase(test, { module: MODULE, id: 'CK-PAYMENT-DEV-RELAY-UI-001' }, '开发 Webhook 转发页使用 Weline UI 2.0 面板', async ({ page }) => {
    const guards = installBackendBrowserGuards(page);
    await loginAsAdmin(page, { timeout: 90000, settleMs: 1200, useProxy: false });

    const routes = [
      `${buildModuleBackendRoute(MODULE, 'dev-relay/index')}`,
      'payment/backend/dev-relay/index',
    ];

    let opened = false;
    let lastBody = '';
    for (const route of routes) {
      await gotoBackend(page, route, { timeout: 90000, settleMs: 1200, useProxy: false });
      await waitForBackendShellReady(page);
      lastBody = await page.locator('body').innerText().catch(() => '');
      if (/未登录|后台登录/.test(lastBody)) {
        await loginAsAdmin(page, { timeout: 90000, settleMs: 1200, useProxy: false });
        await gotoBackend(page, route, { timeout: 90000, settleMs: 1200, useProxy: false });
        await waitForBackendShellReady(page);
        lastBody = await page.locator('body').innerText().catch(() => '');
      }
      if ((await page.getByTestId('payment-dev-relay-management').count()) > 0) {
        opened = true;
        break;
      }
    }

    expect(opened, `dev-relay not opened; body=${lastBody.slice(0, 400)}`).toBeTruthy();
    await expect(page.getByTestId('payment-dev-relay-management')).toBeVisible({ timeout: 30000 });
    await expect(page.getByTestId('payment-dev-relay-guide')).toBeVisible();
    await expect(page.getByTestId('payment-dev-relay-guide-when')).toContainText(/何时开启/);
    await expect(page.getByTestId('payment-dev-relay-guide-payment')).toContainText(/支付怎么用/);
    await expect(page.getByTestId('payment-dev-relay-guide-dropship')).toContainText(/万能货源怎么用/);
    await expect(page.getByTestId('payment-dev-relay-settings')).toBeVisible();
    await expect(page.getByTestId('payment-dev-relay-save-settings')).toBeVisible();
    await expect(page.locator('.payment-dev-relay .w-card').first()).toBeVisible();
    await expect(page.locator('.payment-dev-relay .w-button[data-tone="primary"]').first()).toBeVisible();
    await expect(page.locator('body')).not.toContainText('模板文件不存在');

    const localPanel = page.getByTestId('payment-dev-relay-local');
    if ((await localPanel.count()) > 0) {
      await expect(localPanel).toBeVisible();
      const startBtn = page.getByTestId('payment-dev-relay-worker-start');
      const stopBtn = page.getByTestId('payment-dev-relay-worker-stop');
      await expect(startBtn).toBeEnabled();
      await expect(stopBtn).toBeEnabled();
      await expect(page.getByTestId('payment-dev-relay-token-hint')).toBeVisible();
      await expect(page.getByTestId('payment-dev-relay-local-actions')).toBeVisible();
      await expect(page.getByTestId('payment-dev-relay-worker-summary')).toBeVisible();
      await expect(page.getByTestId('payment-dev-relay-worker-summary')).toContainText(/连接详情/);
      await expect(page.getByTestId('payment-dev-relay-worker-badge')).toContainText(/已停止|运行中/);
      await expect(page.getByTestId('payment-dev-relay-worker-tech')).toBeVisible();
      const pe = await page.getByTestId('payment-dev-relay-actions').evaluate((el) => getComputedStyle(el).pointerEvents);
      expect(pe).not.toBe('none');
      await page.getByLabel(/线上用户 API Token/).fill('');
      await startBtn.click();
      const feedback = page.getByTestId('payment-dev-relay-local-feedback');
      await expect(feedback).toBeVisible({ timeout: 15000 });
      // 首次无凭证 → 告警必填；本机已记住 Token → 可直接开启成功。
      await expect(feedback).toContainText(/请填写线上用户 API Token|静默中继已开启|Silent worker|请提供|Token/i);
      const feedbackText = await feedback.innerText();
      if (/请填写线上用户 API Token/.test(feedbackText)) {
        await expect(page.getByTestId('payment-dev-relay-log')).toContainText(/请填写线上用户 API Token/i, { timeout: 15000 });
        await expect(page.locator('#dev-relay-user-token')).toHaveAttribute('aria-invalid', 'true');
      }
    }

    guards.assertClean();
  });
});
