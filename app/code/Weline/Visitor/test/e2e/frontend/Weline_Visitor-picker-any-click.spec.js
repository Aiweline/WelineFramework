/**
 * 事件拾取：未打标点击也出候选（任意可点捕捉）。
 *
 * @weline-e2e-spec { module: Weline_Visitor, type: flow, layer: frontend }
 */
const { spawnSync } = require('child_process');
const path = require('path');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
  getRuntimeInfo,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Visitor';
const ROOT = path.resolve(__dirname, '../../../../../../../');

function issuePickerToken() {
  const php = spawnSync(
    'php',
    [
      '-r',
      'require "app/bootstrap.php";' +
        '$p=\\Weline\\Framework\\Manager\\ObjectManager::getInstance(\\Weline\\Visitor\\Service\\EventPickerTokenService::class);' +
        'echo json_encode($p->issue(1,"weline",0,"default.default.default"));',
    ],
    { cwd: ROOT, encoding: 'utf8', timeout: 60000 }
  );
  if (php.status !== 0) {
    throw new Error(`issue token failed: ${php.stderr || php.stdout}`);
  }
  const line = String(php.stdout || '')
    .trim()
    .split('\n')
    .filter(Boolean)
    .pop();
  return JSON.parse(line);
}

moduleDescribe(test, MODULE, 'Visitor 事件拾取任意点击捕捉', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'VISITOR-PICKER-CLICK-001' },
    '未打标按钮点击后出现候选与捕捉闪示',
    async ({ page }) => {
      const runtime = getRuntimeInfo();
      const origin = String(runtime.runtime?.target_origin || '').replace(/\/$/, '');
      expect(origin).toBeTruthy();

      const issued = issuePickerToken();
      expect(issued.token, 'picker token').toBeTruthy();

      const url =
        `${origin}/?weline_pixel_picker=1` +
        `&token=${encodeURIComponent(issued.token)}` +
        `&vendor=weline&website_id=1&storage_scope=default.default.default` +
        `&weline_e2e_picker_click=1`;

      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 90000 });
      await page.waitForTimeout(1500);

      const root = page.locator('[data-testid="weline-pixel-picker"], #wpp-root').first();
      await expect(root).toBeVisible({ timeout: 30000 });
      await expect(root).toHaveAttribute('data-wpp-build', /1\.1\.1[12]-/);

      // 注入未打标按钮，避免依赖主题是否已标 weline-pixel::
      await page.evaluate(() => {
        const b = document.createElement('button');
        b.type = 'button';
        b.id = 'e2e-unmarked-cta';
        b.textContent = '浏览系列';
        b.style.cssText = 'position:fixed;left:24px;bottom:24px;z-index:2147483000;padding:12px 18px;';
        document.body.appendChild(b);
      });

      await page.locator('#e2e-unmarked-cta').click();
      await page.waitForTimeout(600);

      const flash = page.locator('[data-testid="wpp-capture-flash"]').first();
      await expect(flash).toBeVisible({ timeout: 10000 });
      await expect(flash).toContainText(/已捕捉/);

      await expect(page.locator('#wpp-list .wpp-item--custom').first()).toBeVisible({ timeout: 10000 });
      await expect(page.locator('#wpp-list')).toContainText(/点击捕捉|浏览系列|click_/);
      await expect(page.locator('#wpp-list [data-wpp-record]').first()).toBeVisible();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'VISITOR-PICKER-RECORD-REV-001' },
    '录入自定义后响应 config_revision 变大',
    async ({ page, request }) => {
      const runtime = getRuntimeInfo();
      const origin = String(runtime.runtime?.target_origin || '').replace(/\/$/, '');
      expect(origin).toBeTruthy();

      const issued = issuePickerToken();
      const before = await request.get(
        `${origin}/visitor/analytics/event-picker/mapped?runtime_config=1&revision_only=1&website_id=1&storage_scope=default.default.default`,
        { headers: { Accept: 'application/json' } }
      );
      const beforeJson = await before.json();
      const beforeRev = Number(beforeJson.config_revision || beforeJson.configRevision || 0) || 0;

      const name = `e2e_rec_rev_${Date.now()}`;
      const record = await request.post(`${origin}/visitor/analytics/event-picker/record`, {
        form: {
          token: issued.token,
          weline_event: name,
          third_party_event: name,
          summary: 'named_custom',
          path: '/',
          kind: 'single',
        },
        headers: { Accept: 'application/json' },
      });
      const recordText = await record.text();
      expect(record.ok(), `record status=${record.status()} body=${recordText.slice(0, 300)}`).toBeTruthy();
      const recordJson = JSON.parse(recordText);
      expect(recordJson.ok).toBeTruthy();
      const recordRev = Number(recordJson.config_revision || recordJson.configRevision || 0) || 0;
      expect(recordRev, `record response must bump revision (before=${beforeRev})`).toBeGreaterThan(beforeRev);

      const after = await request.get(
        `${origin}/visitor/analytics/event-picker/mapped?runtime_config=1&revision_only=1&website_id=1&storage_scope=default.default.default&_=${Date.now()}`,
        { headers: { Accept: 'application/json' } }
      );
      const afterJson = await after.json();
      const afterRev = Number(afterJson.config_revision || afterJson.configRevision || 0) || 0;
      expect(afterRev).toBeGreaterThanOrEqual(recordRev);
    }
  );
});
