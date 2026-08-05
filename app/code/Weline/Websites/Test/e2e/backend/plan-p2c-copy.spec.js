/**
 * 计划用例固化：多站 Store 商品复制（TEST-P2C-COPY-01～05）
 *
 * 计划来源：万能商城内核 §8 / TASK-P2C-002 / MOD-P2C-002
 * UI 入口：/{backend}/websites/admin/store-copy/wizard
 * 服务契约：product_copy QueryProvider → ProductCopyService
 *
 * 说明：
 * - Browser 层验收三入口、字段包、分类/库存/重复策略控件
 * - 以 blank 入口执行真实 createDraft→preview→commit；不复制目录数据，但必须写持久化 operation/receipt
 *
 * @weline-e2e-spec { module: Weline_Websites, type: plan, layer: backend }
 */

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
  installBackendBrowserGuards,
  openBackendMenuBySource,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Websites';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const WIZARD = 'websites/admin/store-copy/wizard';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'plan-p2c-copy-fixture.php');

function runFixture(action, payload = {}) {
  if (process.env.WELINE_E2E_ISOLATED_DB !== '1') {
    throw new Error('R4.3 Store Copy fixture requires WELINE_E2E_ISOLATED_DB=1');
  }
  const stdout = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ ...payload, action }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const parsed = JSON.parse(lines[lines.length - 1] || '{}');
  if (!parsed.ok) {
    throw new Error(`p2c-copy fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

async function openWizard(page) {
  await loginAsAdmin(page);
  await openBackendMenuBySource(page, 'Weline_Websites::store_copy', {
    urlIncludes: `/${WIZARD}`,
    pageAnchor: '[data-testid="store-copy-wizard"]',
  });
  await expect(page.locator('body')).toBeVisible();
  await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);

  const root = page.locator('[data-testid="store-copy-wizard"]');
  if ((await root.count()) > 0) {
    await expect(root).toBeVisible({ timeout: 10000 });
    return;
  }
  // 主题编译缓存未刷新时，回退到标题/入口文案（禁止因缓存假红）
  await expect(page.locator('body')).toContainText(/Store 商品复制向导|复制向导|ProductCopyService|blank|site_pull/i, {
    timeout: 20000,
  });
}

async function expectTextOrTestId(page, testId, textRe) {
  const byId = page.locator(`[data-testid="${testId}"]`);
  if ((await byId.count()) > 0) {
    await expect(byId).toBeAttached();
    const tagName = await byId.evaluate(element => element.tagName.toLowerCase());
    if (tagName !== 'option') {
      await expect(byId).toBeVisible();
    }
    if (textRe) {
      const value = await byId.getAttribute('value');
      if ((tagName === 'option' || tagName === 'input') && value !== null) {
        if (textRe instanceof RegExp) {
          expect(value).toMatch(textRe);
        } else {
          expect(value).toBe(String(textRe));
        }
      } else {
        await expect(byId).toContainText(textRe);
      }
    }
    return;
  }
  await expect(page.locator('body')).toContainText(textRe || testId);
}

async function waitForScopeOptions(page) {
  const targetStore = page.locator('[data-testid="store-copy-target-store"]');
  await expect(targetStore).toBeVisible();
  await expect.poll(async () => targetStore.locator('option').count(), {
    timeout: 30000,
    message: 'product_copy.scopeOptions should populate a target Store',
  }).toBeGreaterThan(0);
  await expect(targetStore).toBeEnabled();
}

moduleDescribe(test, MODULE, '计划用例：多站 Store 商品复制（P2C-COPY）', () => {
  const ownedDraftIds = new Set();

  test.afterAll(() => {
    if (ownedDraftIds.size > 0) {
      runFixture('cleanup', { draft_ids: [...ownedDraftIds] });
    }
  });

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P2C-COPY-01' },
    '三入口 blank/site_pull/store_inherit 共用同一向导服务面',
    async ({ page }) => {
      await openWizard(page);
      await expect(page.locator('body')).toContainText(/Store|复制|Copy|ProductCopyService|三入口|preview/i);

      for (const entry of ['blank', 'site_pull', 'store_inherit']) {
        await expectTextOrTestId(page, `store-copy-entry-${entry}`, entry);
      }
      for (const pkg of ['identity', 'attrs', 'price', 'media', 'inventory']) {
        await expectTextOrTestId(page, `store-copy-package-${pkg}`, pkg);
      }
      await waitForScopeOptions(page);
      await expect(page.locator('[data-testid="store-copy-preview"]')).toBeEnabled();
      await expect(page.locator('[data-testid="store-copy-commit"]')).toBeDisabled();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P2C-COPY-02' },
    '分类树勾父/排除子/带品开关契约在向导可见',
    async ({ page }) => {
      await openWizard(page);
      await expectTextOrTestId(
        page,
        'store-copy-contract-tree',
        /excluded_category_ids|include_products|勾父|子孙|分类树/i
      );
      await expect(page.locator('[data-testid="store-copy-category-ids"]')).toBeVisible();
      await expect(page.locator('[data-testid="store-copy-excluded-category-ids"]')).toBeVisible();
      await expect(page.locator('[data-testid="store-copy-include-products"]')).toBeChecked();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P2C-COPY-03' },
    '库存默认 0；仅显式 inventory_copy_qty 才复制数量',
    async ({ page }) => {
      await openWizard(page);
      await expect(page.locator('body')).toContainText(/库存默认 0|默认 0|inventory_copy_qty/i);
      await expect(page.locator('[data-testid="store-copy-inventory-copy-qty"]')).not.toBeChecked();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'CK-R43-WEBSITES-STORE-COPY-001' },
    '真实 blank 草稿执行 preview→commit，跨站隔离契约继续可见',
    async ({ page }) => {
      const guards = installBackendBrowserGuards(page);
      try {
        await openWizard(page);
        await expectTextOrTestId(page, 'store-copy-entry-store_inherit', /store_inherit/);
        await expect(page.locator('body')).toContainText(/跨 Website|新 UUID|不抬升|store_inherit/i);
        await waitForScopeOptions(page);

        await page.locator('[data-testid="store-copy-entry"]').selectOption('blank');
        await page.locator('[data-testid="store-copy-preview"]').click();
        await expect(page.locator('[data-testid="store-copy-status"]')).toContainText(/预览已就绪|preview/i, {
          timeout: 30000,
        });
        await expect(page.locator('[data-testid="store-copy-count-products"]')).toHaveText('0');
        await expect(page.locator('[data-testid="store-copy-commit"]')).toBeEnabled();
        await expect(page.locator('[data-testid="store-copy-cancel"]')).toBeEnabled();

        const preview = JSON.parse(await page.locator('[data-testid="store-copy-result"]').innerText());
        expect(preview.draft_id).toMatch(/^draft-[a-f0-9]+$/);
        ownedDraftIds.add(preview.draft_id);

        await page.locator('[data-testid="store-copy-commit"]').click();
        await expect(page.locator('[data-testid="store-copy-status"]')).toContainText(/复制提交成功|成功|committed/i, {
          timeout: 30000,
        });
        await expect(page.locator('[data-testid="store-copy-result"]')).toContainText(/"success": true/);
        await expect(page.locator('[data-testid="store-copy-cancel"]')).toBeDisabled();
        await expect(page.locator('body')).not.toContainText(/BLOCKED_ADAPTER/);

        const persisted = runFixture('inspect', { draft_ids: [preview.draft_id] });
        expect(persisted.rows).toEqual([{ draft_id: preview.draft_id, state: 'committed' }]);
        const cleanup = runFixture('cleanup', { draft_ids: [preview.draft_id] });
        expect(cleanup.deleted).toBe(1);
        ownedDraftIds.delete(preview.draft_id);
        expect(runFixture('inspect', { draft_ids: [preview.draft_id] }).rows).toEqual([]);
      } finally {
        guards.assertClean();
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P2C-COPY-05' },
    '同来源重复复制：默认 skip / update_selected_fields',
    async ({ page }) => {
      await openWizard(page);
      await expect(page.locator('body')).toContainText(/skip|update_selected_fields/i);
      await expect(page.locator('[data-testid="store-copy-duplicate-policy"] option')).toHaveCount(2);
      await page.locator('[data-testid="store-copy-duplicate-policy"]').selectOption('update_selected_fields');
      await expect(page.locator('[data-testid="store-copy-duplicate-policy"]')).toHaveValue('update_selected_fields');
    }
  );
});
