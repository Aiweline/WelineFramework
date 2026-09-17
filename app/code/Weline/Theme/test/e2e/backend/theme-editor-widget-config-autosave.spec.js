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
  return `e2e_wca_${Date.now().toString(36)}_${worker}`;
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
  await page.waitForFunction(() => {
    const editor = window.Weline?.Theme?.Editor || window.ThemeEditor;
    return Boolean(editor && typeof editor.apiJson === 'function' && typeof editor.patchWidgetConfigFields === 'function');
  }, null, { timeout: 60000 });
}

async function callEditorRequest(page, url, method = 'GET', body = null) {
  return page.evaluate(async (rawInput) => {
    const editorRoute = '/theme/backend/theme-editor';
    const apiBase = (document.querySelector('#themeEditor')?.dataset?.apiBase || '').replace(/\/+$/, '');
    const input = Object.assign({}, rawInput);
    if (apiBase && input.url.startsWith(editorRoute)) {
      input.url = apiBase + input.url.slice(editorRoute.length);
    }

    if (window.ThemeEditor && typeof window.ThemeEditor.apiJson === 'function') {
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
      return window.ThemeEditor.apiJson(input.url, options);
    }

    const editor = window.Weline?.Theme?.Editor;
    if (editor && typeof editor.apiJson === 'function') {
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
      return editor.apiJson(input.url, options);
    }

    throw new Error('Theme Editor apiJson is unavailable');
  }, { url, method, body });
}

moduleDescribe(test, MODULE, 'theme editor widget config autosave', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'THEME-EDITOR-WIDGET-AUTOSAVE-001' },
    'field-level scoped patch autosaves to current theme draft and inherits blank locale media',
    async ({ page }, testInfo) => {
      const activeTheme = getActiveTheme('frontend');
      test.skip(!activeTheme, 'No active frontend theme found in runtime info.');

      const themeId = Number(activeTheme.id || 0);
      const pageType = makePageType(testInfo);

      runFixture('cleanup', { theme_id: themeId, page_type: pageType });

      try {
        await loginAsAdmin(page, { timeout: 60000, settleMs: 1000 });
        await gotoBackend(page, `theme/backend/theme-editor/index?theme_id=${themeId}&editor_area=frontend&page_type=${pageType}`, {
          waitUntil: 'domcontentloaded',
          timeout: 60000,
          settleMs: 2000,
        });
        await waitForThemeEditor(page);

        const saveWidget = await callEditorRequest(page, '/theme/backend/theme-editor/save-widget', 'POST', {
          theme_id: themeId,
          page_type: pageType,
          area: 'content',
          slot_id: 'content',
          widget_module: 'Weline_Widget',
          widget_code: 'text',
          widget_type: 'basic',
          sort_order: 0,
          config: { content: 'autosave-base' },
        });
        expectEditorSuccess(saveWidget, 'save-widget');
        const nodeUid = String(saveWidget.node_uid || saveWidget.data?.node_uid || '').trim();
        expect(nodeUid).toMatch(/^[a-f0-9]{32}$/);

        const patchResult = await page.evaluate(async ({ nodeUid: uid, themeId: tid }) => {
          const editor = window.Weline?.Theme?.Editor || window.ThemeEditor;
          if (Number(editor.state?.themeId || 0) !== Number(tid)) {
            throw new Error(`editor theme mismatch: ${editor.state?.themeId} vs ${tid}`);
          }
          await editor.loadScopedWorkspace('layout', { locale: 'default', skipReconcile: true });
          await editor.patchWidgetConfigFields(uid, { content: 'autosave-dirty-only' }, '', {
            summary: 'widget_config_autosave',
          });
          const workspace = await editor.loadScopedWorkspace('layout', { locale: 'default', skipReconcile: true });
          return {
            themeId: editor.state.themeId,
            revision: workspace?.revision,
            content: workspace?.draft_payload?.nodes?.[uid]?.config?.content,
          };
        }, { nodeUid, themeId });

        expect(Number(patchResult.themeId)).toBe(themeId);
        expect(Number(patchResult.revision || 0)).toBeGreaterThan(0);
        expect(patchResult.content).toBe('autosave-dirty-only');

        await page.evaluate(async ({ nodeUid: uid }) => {
          const editor = window.Weline?.Theme?.Editor || window.ThemeEditor;
          await editor.loadScopedWorkspace('i18n', { locale: 'en_US', skipReconcile: true });
          await editor.patchWidgetConfigFields(uid, { content: 'locale-copy' }, 'en_US', {
            summary: 'widget_i18n_autosave',
          });
          await editor.patchWidgetConfigFields(uid, { content: '' }, 'en_US', {
            blankAsInherit: true,
            summary: 'widget_i18n_autosave',
          });
        }, { nodeUid });

        const i18nState = await page.evaluate(async ({ nodeUid: uid }) => {
          const editor = window.Weline?.Theme?.Editor || window.ThemeEditor;
          const workspace = await editor.loadScopedWorkspace('i18n', { locale: 'en_US', skipReconcile: true });
          const owned = workspace?.draft_payload?.translations?.[uid]?.content;
          return {
            themeId: editor.state.themeId,
            hasOwnedContent: owned !== undefined && owned !== null && String(owned) !== '',
          };
        }, { nodeUid });

        expect(Number(i18nState.themeId)).toBe(themeId);
        expect(i18nState.hasOwnedContent).toBe(false);
      } finally {
        runFixture('cleanup', { theme_id: themeId, page_type: pageType });
      }
    },
  );
});
