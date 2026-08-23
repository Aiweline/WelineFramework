// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  getActiveTheme,
  gotoBackend,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Theme';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'theme-editor-fixture.php');

function makeToken(testInfo) {
  return `responsive_${Date.now().toString(36)}_${Number(testInfo.workerIndex || 0).toString(36)}`;
}

function runFixture(action, payload) {
  const stdout = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...(payload || {}) }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  return JSON.parse(stdout);
}

async function waitForThemeEditor(page) {
  await page.locator('#themeEditor').waitFor({ state: 'attached', timeout: 60000 });
  await page.waitForFunction(() => Boolean(
    (window.Weline?.Theme?.Editor || window.ThemeEditor)?.apiJson
  ), null, { timeout: 60000 });
  await expect(page.locator('#currentVersionDisplay')).not.toContainText(
    /(?:加载中|Loading|context_mismatch|raw_context_mismatch)/i,
    { timeout: 20000 },
  );
}

async function closeFloatingUi(page) {
    await page.keyboard.press('Escape');
    await page.waitForTimeout(100);
}

async function openToolbarAction(page, actionSelector) {
  const action = page.locator(actionSelector);
  if (!await action.isVisible()) {
    const more = page.locator('#themeEditorToolbarMore');
    await expect(more).toBeVisible();
    await more.click({ timeout: 10000 });
    await expect(page.locator('#themeEditorToolbarOverflowMenu')).toBeVisible();
    await expect(action).toBeVisible();
  }
  await action.click({ timeout: 10000 });
}

async function auditVisibleThemeControls(page, label, minimumControls = 1) {
  const report = await page.evaluate((auditLabel) => {
    const tolerance = 1.5;
    const viewport = {
      left: 0,
      top: 0,
      right: document.documentElement.clientWidth,
      bottom: document.documentElement.clientHeight,
    };
    const visible = (element) => {
      if (!(element instanceof HTMLElement)) return false;
      const style = getComputedStyle(element);
      const rect = element.getBoundingClientRect();
      return !element.hidden
        && style.display !== 'none'
        && style.visibility !== 'hidden'
        && Number(style.opacity || 1) !== 0
        && rect.width > 0
        && rect.height > 0;
    };
    const rectJson = (rect) => ({
      left: Number(rect.left.toFixed(2)),
      right: Number(rect.right.toFixed(2)),
      top: Number(rect.top.toFixed(2)),
      bottom: Number(rect.bottom.toFixed(2)),
      width: Number((rect.width ?? (rect.right - rect.left)).toFixed(2)),
      height: Number((rect.height ?? (rect.bottom - rect.top)).toFixed(2)),
    });
    const violations = [];
    const records = [];
    const checkBounds = (element, boundary, boundaryName) => {
      const rect = element.getBoundingClientRect();
      const bounds = boundary instanceof Element ? boundary.getBoundingClientRect() : viewport;
      if (rect.left < bounds.left - tolerance || rect.right > bounds.right + tolerance) {
        violations.push({
          label: auditLabel,
          element: element.id ? `#${element.id}` : element.className || element.tagName,
          boundary: boundaryName,
          rect: rectJson(rect),
          bounds: rectJson(bounds),
        });
      }
    };

    const controls = Array.from(document.querySelectorAll([
      '#themeEditor input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="color"]):not([type="range"])',
      '#themeEditor select',
      '#themeEditor textarea',
      '#themeEditor .w-tree-select-trigger',
      '.w-theme-editor-dialog input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="color"]):not([type="range"])',
      '.w-theme-editor-dialog select',
      '.w-theme-editor-dialog textarea',
      '.w-theme-disk-appearance-panel input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="color"]):not([type="range"])',
      '.w-theme-disk-appearance-panel select',
      '.w-theme-disk-appearance-panel textarea',
      '.w-toolbar-overflow__menu select',
    ].join(','))).filter(visible);

    controls.forEach((control) => {
      const rect = control.getBoundingClientRect();
      const local = control.closest([
        '.w-field',
        '.form-group',
        '.config-field',
        '.toolbar-select-field',
        '.w-theme-disk-token__controls',
      ].join(','));
      const overlay = control.closest([
        '.w-dialog',
        '.w-drawer',
        '.w-popover',
        '.w-menu',
        '.w-tree-select-dropdown',
      ].join(','));
      if (local && local !== control && !(overlay && local.contains(overlay))) {
        checkBounds(control, local, 'nearest field container');
      }
      if (overlay && overlay !== local) checkBounds(control, overlay, 'nearest floating container');
      checkBounds(control, viewport, 'viewport');
      records.push({
        element: control.id ? `#${control.id}` : control.className || control.tagName,
        rect: rectJson(rect),
        boxSizing: getComputedStyle(control).boxSizing,
        minInlineSize: getComputedStyle(control).minInlineSize,
        maxInlineSize: getComputedStyle(control).maxInlineSize,
      });
    });

    const floating = Array.from(document.querySelectorAll([
      '.w-dialog',
      '.w-drawer',
      '.w-popover',
      '.w-toolbar-overflow__menu',
      '.w-tree-select-dropdown',
    ].join(','))).filter(visible);
    floating.forEach((panel) => checkBounds(panel, viewport, 'viewport'));

    return {
      label: auditLabel,
      viewport,
      controlCount: controls.length,
      floatingCount: floating.length,
      documentWidth: document.documentElement.scrollWidth,
      records,
      violations,
    };
  }, label);

  expect(report.controlCount, JSON.stringify(report, null, 2)).toBeGreaterThanOrEqual(minimumControls);
  expect(report.violations, JSON.stringify(report, null, 2)).toEqual([]);
  return report;
}

async function exerciseResponsiveSurface(page, scenario) {
  await page.setViewportSize({ width: scenario.width, height: scenario.height });
  await page.waitForTimeout(250);

  await auditVisibleThemeControls(page, `${scenario.label}: toolbar`, 2);

  await page.locator('.toolbar-select-field-scope .w-tree-select-trigger').click({ timeout: 10000 });
  const scopeDropdown = page.locator('#scopeSelect_dropdown');
  await expect(scopeDropdown).toBeVisible();
  await expect(scopeDropdown).toContainText('Website With A Deliberately Long Name');
  await expect(scopeDropdown).toContainText('Store With A Deliberately Long Name');
  await expect(scopeDropdown).toContainText('Channel With A Deliberately Long Name');
  await auditVisibleThemeControls(page, `${scenario.label}: Scope TreeSelect`, 2);
  await closeFloatingUi(page);

  const selectMore = page.locator('#themeEditorSelectsMore');
  if (await selectMore.isVisible()) {
    await selectMore.click({ timeout: 10000 });
    await expect(page.locator('#themeEditorSelectsOverflowMenu')).toBeVisible();
    await auditVisibleThemeControls(page, `${scenario.label}: selector overflow menu`, 2);
    await closeFloatingUi(page);
  }

  await page.locator('#versionHistoryTrigger').click({ timeout: 10000 });
  await expect(page.locator('#versionPanel')).toBeVisible();
  await auditVisibleThemeControls(page, `${scenario.label}: version popover`, 2);
  await closeFloatingUi(page);

  await openToolbarAction(page, '#btnThemeDiskAppearance');
  const drawer = page.locator('#themeDiskAppearanceModal');
  await expect(drawer).toBeVisible();
  await expect(page.locator('#themeDiskAppearancePanel')).toBeVisible();
  await auditVisibleThemeControls(page, `${scenario.label}: appearance drawer`, 1);
  const inheritEdit = drawer.getByRole('button', { name: /继承编辑|inherit edit/i }).first();
  if (await inheritEdit.isVisible()) {
    await inheritEdit.click();
    await expect(page.locator('#themeDiskAppearanceName')).toBeVisible();
    await auditVisibleThemeControls(page, `${scenario.label}: appearance editor`, 2);
  }
  await page.locator('#btnThemeDiskAppearanceClose').click();
  await expect(drawer).toBeHidden();
}

moduleDescribe(test, MODULE, 'responsive Theme controls', () => {
  test.setTimeout(300000);

  moduleCase(
    test,
    { module: MODULE, id: 'THEME-EDITOR-RESPONSIVE-001' },
    'Theme inputs and floating surfaces stay inside their nearest container at all acceptance widths',
    async ({ page }, testInfo) => {
      const activeTheme = getActiveTheme('frontend');
      test.skip(!activeTheme, 'No active frontend theme found in runtime info.');

      const themeId = Number(activeTheme.id || 0);
      const token = makeToken(testInfo);
      const pageType = `e2e_${token}`;
      const fixturePayload = { theme_id: themeId, page_type: pageType, token };
      const fixture = runFixture('prepare_scope_hierarchy', fixturePayload);
      expect(fixture.success).toBeTruthy();

      try {
        await page.setViewportSize({ width: 1280, height: 900 });
        await loginAsAdmin(page, { timeout: 60000, settleMs: 1000 });
        await gotoBackend(
          page,
          `theme/backend/theme-editor?theme_id=${themeId}&editor_area=frontend&page_type=${pageType}&scope=${encodeURIComponent(fixture.scopes.website)}`,
          { waitUntil: 'domcontentloaded', timeout: 60000, settleMs: 1500 },
        );
        await waitForThemeEditor(page);

        const appearanceProbe = await page.evaluate(async () => {
          const root = document.querySelector('#themeEditor');
          const editor = window.Weline?.Theme?.Editor || window.ThemeEditor;
          const context = editor.buildTypedEditorContext('appearance');
          const url = new URL(root.dataset.apiThemeTokens, window.location.origin);
          url.searchParams.set('theme_id', String(document.querySelector('#themeSelect')?.value || root.dataset.themeId || 0));
          url.searchParams.set('editor_area', String(document.querySelector('#editorAreaSelect')?.value || root.dataset.editorArea || 'frontend'));
          url.searchParams.set('scope', editor.getLegacyScope());
          url.searchParams.set('editor_context', JSON.stringify(context));
          try {
            return { context, result: await editor.apiJson(url.toString()) };
          } catch (error) {
            return { context, error: String(error?.message || error) };
          }
        });
        expect(appearanceProbe.error, JSON.stringify(appearanceProbe, null, 2)).toBeUndefined();
        expect(appearanceProbe.result?.success, JSON.stringify(appearanceProbe, null, 2)).toBeTruthy();
        expect(
          Object.keys(appearanceProbe.result?.data?.catalog?.panels || {}).length,
          JSON.stringify(appearanceProbe, null, 2),
        ).toBeGreaterThan(0);

        for (const scenario of [
          { label: '320px', width: 320, height: 900 },
          { label: '375px', width: 375, height: 900 },
          { label: '768px', width: 768, height: 900 },
          { label: '1280px', width: 1280, height: 900 },
          { label: '1280px at 200% effective zoom', width: 640, height: 450 },
        ]) {
          await exerciseResponsiveSurface(page, scenario);
        }
      } finally {
        const cleanup = runFixture('cleanup_scope_hierarchy', fixturePayload);
        expect(cleanup.success).toBeTruthy();
      }
    },
  );
});
