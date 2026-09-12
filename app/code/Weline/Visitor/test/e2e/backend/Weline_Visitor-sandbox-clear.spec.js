/**
 * 本会话沙盒：清理后不得因短轮询把旧缓冲 merge 回流。
 *
 * @weline-e2e-spec { module: Weline_Visitor, type: flow, layer: backend }
 */
const { spawnSync } = require('child_process');
const path = require('path');
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

const MODULE = 'Weline_Visitor';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const TV_READY = '[data-testid="tracking-vendor-admin"], main#main-content, main.backend-main-content';
const REPO = path.resolve(__dirname, '../../../../../../..');

function seedServerBuffer(websiteId, eventName) {
  const php = `
require 'vendor/autoload.php';
require 'app/bootstrap.php';
$svc = \\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\Weline\\Visitor\\Service\\EventPickerTokenService::class);
$wid = ${Number(websiteId) || 0};
$svc->pushAccumulate($wid, [
  'weline_event' => ${JSON.stringify(eventName)},
  'source' => 'sandbox_stream',
  'path' => '/e2e-clear',
  'params' => ['e2e' => 1],
  'event_hit' => false,
]);
echo json_encode(['ok' => true, 'n' => count($svc->listAccumulate($wid, 5)), 'gen' => $svc->bufferGeneration($wid)]);
`;
  const r = spawnSync('php', ['-d', 'memory_limit=512M', '-r', php], { encoding: 'utf8', cwd: REPO });
  if (r.status !== 0) {
    throw new Error(`seedServerBuffer failed: ${r.stderr || r.stdout}`);
  }
  return String(r.stdout || '').trim();
}

moduleDescribe(test, MODULE, 'Visitor 沙盒会话清理 e2e', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'VISITOR-SANDBOX-CLEAR-001' },
    '清理后会话流保持空，短轮询不回流',
    async ({ page }) => {
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800 });
      const route = buildModuleBackendRoute(MODULE, 'tracking-vendor/index');
      await gotoBackend(page, `${route}?tab=map&vendor=weline`, {
        timeout: 90000,
        settleMs: 800,
      });
      await waitForBackendShellReady(page);
      await expect(page.locator(TV_READY).first()).toBeVisible({ timeout: 45000 });
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.getByTestId('tv-acc-clear')).toBeVisible({ timeout: 15000 });

      const websiteId = Number(
        (await page.locator('input[name="website_id"]').first().inputValue().catch(() => '0')) || '0'
      ) || 0;

      const marker = `e2e_clear_${Date.now()}`;
      seedServerBuffer(websiteId, marker);
      seedServerBuffer(0, marker);

      await page.evaluate((name) => {
        const rows = [
          {
            id: 'e2e-local',
            seq: 9,
            weline_event: name,
            source: 'sandbox_stream',
            at: new Date().toISOString(),
            path: '/e2e-local',
            event_hit: false,
            params: { local: 1 },
          },
        ];
        const keys = [];
        for (let i = 0; i < sessionStorage.length; i++) {
          const k = sessionStorage.key(i);
          if (k && k.indexOf('tvp_session_stream_v1') === 0) keys.push(k);
        }
        if (!keys.length) keys.push('tvp_session_stream_v1:0:');
        keys.forEach((k) => sessionStorage.setItem(k, JSON.stringify(rows)));
      }, marker);

      await page.getByTestId('tv-acc-refresh').click();
      await page.waitForTimeout(800);
      // 允许服务端继电或本地 seed 至少出现一条
      await expect
        .poll(async () => page.getByTestId('tv-acc-stream-row').count(), { timeout: 8000 })
        .toBeGreaterThan(0);

      const clearResp = page.waitForResponse(
        (r) =>
          r.url().includes('postClearSessionSandbox') &&
          r.request().method() === 'POST',
        { timeout: 20000 }
      );
      await page.getByTestId('tv-acc-clear').click();
      const resp = await clearResp;
      expect(resp.ok()).toBeTruthy();
      const body = await resp.json().catch(() => ({}));
      expect(body.ok !== false).toBeTruthy();

      // 超过 LIVE_POLL_MS 若干拍，确认不会回流
      await page.waitForTimeout(3500);
      await expect(page.getByTestId('tv-acc-stream-row')).toHaveCount(0);
      await expect(page.getByTestId('tv-acc-list')).toContainText(/沙盒暂无事件/);
      await expect(page.getByTestId('tv-stream-all-count')).toHaveText('0');
    }
  );
});
