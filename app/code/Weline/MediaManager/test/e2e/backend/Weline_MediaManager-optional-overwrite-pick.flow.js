/**
 * 可选覆盖目标（上传 / AI 保存）通路体。
 *
 * 这里是**非 spec** 的可复用模块：flow 用例与 plan-suite 用例各自注册
 * `moduleCase`，避免 spec→spec `require`（Playwright 会 fail-closed：
 * "should not import test file"）。
 */
const {
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_MediaManager';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

async function runOptionalOverwritePickFlow(page) {
  await loginAsAdmin(page);
  await gotoBackend(page, buildModuleBackendRoute(MODULE, 'manager'), {
    timeout: 60000,
    settleMs: 800,
  });
  await waitForBackendShellReady(page);
  await expect(page.locator('body')).not.toContainText(FATAL);

  const overlay = page.locator('#mmf-overwrite-pick-overlay');
  await expect(overlay).toHaveCount(1);
  await expect(overlay).toBeHidden();
  await expect(page.locator('[data-mmf-overwrite-pick-rows]')).toHaveCount(1);
  await expect(page.locator('#mmf-overwrite-dock')).toHaveCount(0);
  await expect(page.locator('.mmf-statusbar')).toBeVisible();
  await expect(page.locator('#mmf-ai-save-target-pick')).toHaveCount(1);
  await expect(page.locator('input[name="mmf_ai_save_mode"][value="replace_target"]')).toHaveCount(1);

  const hasFn = await page.evaluate(() => {
    const src = Array.from(document.scripts)
      .map((s) => s.textContent || '')
      .join('\n');
    return src.includes('promptOptionalOverwriteTargets')
      || !!(window.WelineMediaManager && typeof window.WelineMediaManager.init === 'function');
  });
  expect(hasFn).toBeTruthy();

  const statusbar = await page.evaluate(() => {
    const bar = document.querySelector('.mmf-statusbar');
    const content = document.querySelector('.mmf-content');
    if (!bar || !content) return null;
    const br = bar.getBoundingClientRect();
    const cr = content.getBoundingClientRect();
    const cs = getComputedStyle(bar);
    return {
      inView: br.top >= 0 && br.bottom <= window.innerHeight + 2,
      belowContent: br.top >= cr.bottom - 2,
      flexShrink: cs.flexShrink,
    };
  });
  expect(statusbar).toBeTruthy();
  expect(statusbar.inView).toBeTruthy();
  expect(statusbar.belowContent).toBeTruthy();

  const i18n = await page.evaluate(() => {
    const cfg = window.MMF_CONFIG || {};
    const t = cfg.i18n || {};
    return {
      title: t.overwritePickTitle || '',
      duplicate: t.overwritePickDuplicateTarget || '',
      replace: t.aiSaveReplaceTarget || '',
    };
  });
  const body = await page.locator('body').innerText();
  const scripts = (await page.locator('script').allTextContents()).join('');
  expect(
    !!i18n.title
      || body.includes('指定覆盖目标')
      || scripts.includes('overwritePickTitle'),
  ).toBeTruthy();
  expect(
    !!i18n.replace
      || body.includes('覆盖指定文件')
      || scripts.includes('aiSaveReplaceTarget'),
  ).toBeTruthy();
}

module.exports = {
  MODULE,
  FATAL,
  runOptionalOverwritePickFlow,
};
