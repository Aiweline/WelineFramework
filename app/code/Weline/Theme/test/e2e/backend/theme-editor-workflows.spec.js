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

function makePageType(testInfo) {
  const worker = Number(testInfo.workerIndex || 0).toString(36);
  return `e2e_te_${Date.now().toString(36)}_${worker}`;
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

function expectEditorSuccess(result, label) {
  expect(result, `${label} returned a response`).toBeTruthy();
  expect(result.success, `${label} response: ${JSON.stringify(result)}`).toBeTruthy();
}

async function waitForThemeEditor(page) {
  await page.locator('#themeEditor').waitFor({ state: 'attached', timeout: 60000 });
  await page.waitForFunction(() => {
    const candidates = [
      window.Weline && window.Weline.Api,
      window.WelineApiModule,
    ];
    return candidates.some((api) => {
      if (!api) {
        return false;
      }
      if (api.__backend === true && typeof api.request === 'function') {
        return true;
      }
      return api.__backend !== true && typeof api.call === 'function';
    });
  }, null, {
    timeout: 60000,
  });
  await expect(page.locator('#previewFrame')).toHaveAttribute('src', /theme-preview|layout-preview/, { timeout: 60000 });
}

async function callEditorRequest(page, url, method = 'GET', body = null) {
  return page.evaluate(async (input) => {
    const backendApi = [
      window.Weline && window.Weline.Api,
      window.WelineApiModule,
    ].find((candidate) => candidate && candidate.__backend === true && typeof candidate.request === 'function');
    if (backendApi) {
      const options = {
        method: input.method,
        headers: {
          accept: 'application/json',
          'content-type': 'application/json',
          'x-requested-with': 'XMLHttpRequest',
        },
      };
      if (input.body !== null && input.body !== undefined) {
        options.body = JSON.stringify(input.body);
      }
      return backendApi.request(input.url, options);
    }

    const providerApi = [
      window.WelineApiModule,
      window.Weline && window.Weline.Api,
    ].find((candidate) => candidate && candidate.__backend !== true && typeof candidate.call === 'function');
    if (!providerApi) {
      throw new Error('Weline API is not available for ThemeEditor E2E.');
    }
    const params = {
      url: input.url,
      method: input.method,
      headers: {
        accept: 'application/json',
        'content-type': 'application/json',
      },
    };
    if (input.body !== null && input.body !== undefined) {
      params.body = JSON.stringify(input.body);
    }
    return providerApi.call('theme', 'editorRequest', params, { silent: true });
  }, { url, method, body });
}

async function waitUntilPreviewNotLoading(page) {
  await expect(page.locator('#previewLoading')).toHaveClass(/hidden/, { timeout: 60000 });
}

moduleDescribe(test, MODULE, 'theme editor workflows', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'THEME-EDITOR-WORKER-001' },
    'worker bridge supports versions, widget config, publish, restore, and visible sort order',
    async ({ page }, testInfo) => {
      const activeTheme = getActiveTheme('frontend');
      test.skip(!activeTheme, 'No active frontend theme found in runtime info.');

      const themeId = Number(activeTheme.id || 0);
      const pageType = makePageType(testInfo);
      const consoleIssues = [];
      page.on('console', (message) => {
        if (!['error', 'warning'].includes(message.type())) {
          return;
        }
        const text = message.text();
        if (/ThemeEditor|Weline\.Api|editorRequest|worker|Fatal|ParseError/i.test(text)) {
          consoleIssues.push(`${message.type()}: ${text}`);
        }
      });

      runFixture('cleanup', { theme_id: themeId, page_type: pageType });

      try {
        await loginAsAdmin(page, { timeout: 60000, settleMs: 1000 });
        await gotoBackend(page, `theme/backend/theme-editor?theme_id=${themeId}&editor_area=frontend&page_type=${pageType}`, {
          waitUntil: 'domcontentloaded',
          timeout: 60000,
          settleMs: 1500,
        });

        await waitForThemeEditor(page);
        await waitUntilPreviewNotLoading(page);

        await page.locator('.btn-version-toggle').click();
        await expect(page.locator('#versionPanel')).toHaveClass(/open/, { timeout: 10000 });
        await expect(page.locator('#currentVersionDisplay')).not.toContainText(/Loading|加载中/i, { timeout: 20000 });
        await expect(page.locator('#versionList')).not.toContainText(/Loading|加载中/i, { timeout: 20000 });

        const buttonSave = await callEditorRequest(page, '/theme/backend/theme-editor/save-widget', 'POST', {
          theme_id: themeId,
          page_type: pageType,
          area: 'content',
          slot_id: 'content',
          widget_module: 'Weline_Theme',
          widget_type: 'theme_component',
          widget_code: 'basic/button',
          config: {
            text: 'E2E Button',
            type: 'primary',
            size: 'md',
          },
          sort_order: 0,
          exclusive: false,
        });
        expectEditorSuccess(buttonSave, 'save button widget');
        const buttonLayoutId = Number(buttonSave.data?.layout_id || 0);
        expect(buttonLayoutId).toBeGreaterThan(0);

        const cardSave = await callEditorRequest(page, '/theme/backend/theme-editor/save-widget', 'POST', {
          theme_id: themeId,
          page_type: pageType,
          area: 'content',
          slot_id: 'content',
          widget_module: 'Weline_Theme',
          widget_type: 'theme_component',
          widget_code: 'basic/card',
          config: {
            title: 'E2E Card',
            content: 'E2E card content',
          },
          sort_order: 1,
          exclusive: false,
        });
        expectEditorSuccess(cardSave, 'save card widget');
        const cardLayoutId = Number(cardSave.data?.layout_id || 0);
        expect(cardLayoutId).toBeGreaterThan(0);

        const widgetConfig = await callEditorRequest(
          page,
          `/theme/backend/theme-editor/widget-config?layout_id=${buttonLayoutId}`,
          'GET',
        );
        expectEditorSuccess(widgetConfig, 'widget config');
        expect(widgetConfig.data?.widget_code).toBe('basic/button');
        expect(Object.keys(widgetConfig.data?.params || {})).toContain('text');

        const updateConfig = await callEditorRequest(page, '/theme/backend/theme-editor/update-config', 'POST', {
          layout_id: buttonLayoutId,
          config: {
            text: 'E2E Button Updated',
            type: 'secondary',
            size: 'lg',
          },
        });
        expectEditorSuccess(updateConfig, 'update widget config');
        expect(String(updateConfig.preview_html || '')).toContain('E2E Button Updated');

        const updateSort = await callEditorRequest(page, '/theme/backend/theme-editor/update-sort', 'POST', {
          sort_data: [
            { layout_id: buttonLayoutId, sort_order: 0 },
            { layout_id: cardLayoutId, sort_order: 1 },
          ],
        });
        expectEditorSuccess(updateSort, 'update sort');

        const savedVersion = await callEditorRequest(page, '/theme/backend/theme-editor/save-version', 'POST', {
          theme_id: themeId,
          page_type: pageType,
          version_name: 'E2E visible workflow',
          description: 'Saved by theme editor E2E.',
        });
        expectEditorSuccess(savedVersion, 'save version');
        const versionId = Number(savedVersion.data?.version_id || 0);
        expect(versionId).toBeGreaterThan(0);

        const published = await callEditorRequest(page, '/theme/backend/theme-editor/publish-version', 'POST', {
          theme_id: themeId,
          page_type: pageType,
          version_id: versionId,
        });
        expectEditorSuccess(published, 'publish version');

        await page.reload({ waitUntil: 'domcontentloaded', timeout: 60000 });
        await waitForThemeEditor(page);
        await waitUntilPreviewNotLoading(page);
        await expect(page.frameLocator('#previewFrame').locator('body')).toContainText('E2E Button Updated', { timeout: 30000 });
        await expect(page.frameLocator('#previewFrame').locator('body')).toContainText('E2E Card', { timeout: 30000 });
        await page.locator('.preview-tab[data-view="structure"]').click();
        await expect(page.locator('#previewViewStructure')).toHaveClass(/active/, { timeout: 10000 });
        await expect(page.locator('.content-slot-widgets .preview-widget-item')).toHaveCount(2, { timeout: 20000 });
        await expect(page.locator('.content-slot-widgets .preview-widget-item').nth(0)).toHaveAttribute('data-widget-code', 'basic/button');
        await expect(page.locator('.content-slot-widgets .preview-widget-item').nth(1)).toHaveAttribute('data-widget-code', 'basic/card');

        const firstStructureWidget = page.locator('.content-slot-widgets .preview-widget-item').nth(0);
        await firstStructureWidget.hover();
        await firstStructureWidget.locator('.btn-edit-widget').click();
        await expect(page.locator('#widgetConfigModal')).toHaveClass(/show/, { timeout: 20000 });
        await expect(page.locator('#widgetConfigModal')).not.toContainText(/Loading|加载中/i, { timeout: 20000 });

        const restored = await callEditorRequest(page, '/theme/backend/theme-editor/restore-original', 'POST', {
          theme_id: themeId,
          page_type: pageType,
        });
        expectEditorSuccess(restored, 'restore original');

        await page.reload({ waitUntil: 'domcontentloaded', timeout: 60000 });
        await waitForThemeEditor(page);
        await waitUntilPreviewNotLoading(page);
        await page.locator('.preview-tab[data-view="structure"]').click();
        await expect(page.locator('.content-slot-widgets .preview-widget-item')).toHaveCount(0, { timeout: 20000 });
        await expect(page.locator('.content-slot-widgets .slot-placeholder-large')).toHaveCount(1, { timeout: 20000 });

        const snapshot = runFixture('snapshot', { theme_id: themeId, page_type: pageType });
        expect(snapshot.success).toBeTruthy();
        expect(snapshot.layout.filter((row) => row.status === 'draft')).toHaveLength(0);
        expect(snapshot.versions.length).toBeGreaterThanOrEqual(3);
        expect(consoleIssues, consoleIssues.join('\n')).toEqual([]);
      } finally {
        runFixture('cleanup', { theme_id: themeId, page_type: pageType });
      }
    },
  );
});
