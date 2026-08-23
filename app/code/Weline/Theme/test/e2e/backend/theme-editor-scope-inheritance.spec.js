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
  return `scope_${Date.now().toString(36)}_${Number(testInfo.workerIndex || 0).toString(36)}`;
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
  await page.waitForFunction(() => Boolean(
    (window.Weline?.Theme?.Editor || window.ThemeEditor)?.apiJson
    && typeof (window.Weline?.Theme?.Editor || window.ThemeEditor)?.loadScopedWorkspace === 'function'
  ), null, { timeout: 60000 });
}

async function callEditorRequest(page, url, method = 'GET', body = null) {
  return page.evaluate(async (rawInput) => {
    const editorRoute = '/theme/backend/theme-editor';
    const apiBase = (document.querySelector('#themeEditor')?.dataset?.apiBase || '').replace(/\/+$/, '');
    const input = Object.assign({}, rawInput);
    if (apiBase && input.url.startsWith(editorRoute)) {
      input.url = apiBase + input.url.slice(editorRoute.length);
    }
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
    const editor = window.Weline?.Theme?.Editor || window.ThemeEditor;
    return editor.apiJson(input.url, options);
  }, { url, method, body });
}

function globalIdentity() {
  return {
    scope_kind: 'global',
    website_id: null,
    website_code: null,
    store_code: null,
    channel_code: null,
    store_mode: null,
    context_version: 'v1',
  };
}

function editorContext(identity, resourceType, themeId = 0, layoutType = 'default') {
  return {
    scope: { identity },
    area: 'frontend',
    resource_type: resourceType,
    theme_id: resourceType === 'theme_binding' ? 0 : themeId,
    layout_type: resourceType === 'theme_binding' ? 'default' : layoutType,
    layout_option: 'default',
    locale: 'default',
    target_type: 'global',
    target_id: 0,
  };
}

async function loadWorkspace(page, context, label) {
  const query = new URLSearchParams({ editor_context: JSON.stringify(context) });
  const result = await callEditorRequest(
    page,
    `/theme/backend/theme-editor/scoped-workspace?${query.toString()}`,
    'GET',
  );
  expectEditorSuccess(result, label);
  return result.data;
}

async function applyWorkspace(page, context, changes, label, baseState = null) {
  const state = baseState || await loadWorkspace(page, context, `${label} base`);
  const result = await callEditorRequest(page, '/theme/backend/theme-editor/scoped-workspace', 'POST', {
    editor_context: context,
    expected_revision: Number(state.revision || 0),
    expected_parent_release_id: state.expected_parent_release_id ?? null,
    changes,
    summary: label,
  });
  expectEditorSuccess(result, label);
  return result.data;
}

async function publishWorkspace(page, context, label) {
  const state = await loadWorkspace(page, context, `${label} base`);
  const result = await callEditorRequest(page, '/theme/backend/theme-editor/publish-scoped-workspace', 'POST', {
    editor_context: context,
    expected_revision: Number(state.revision || 0),
    expected_parent_release_id: state.expected_parent_release_id ?? null,
    reason: label,
  });
  expectEditorSuccess(result, label);
  return loadWorkspace(page, context, `${label} result`);
}

function owned(state, pathValue) {
  return Array.isArray(state.owned_paths) && state.owned_paths.includes(pathValue);
}

moduleDescribe(test, MODULE, 'scope inheritance workspace', () => {
  test.setTimeout(240000);

  moduleCase(
    test,
    { module: MODULE, id: 'THEME-EDITOR-SCOPE-002' },
    'Website, Store, and Channel independently own Theme bindings and inherit every untouched path',
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
        await loginAsAdmin(page, { timeout: 60000, settleMs: 1000 });
        await gotoBackend(
          page,
          `theme/backend/theme-editor?theme_id=${themeId}&editor_area=frontend&page_type=${pageType}&scope=${encodeURIComponent(fixture.scopes.website)}`,
          { waitUntil: 'domcontentloaded', timeout: 60000, settleMs: 1500 },
        );
        await waitForThemeEditor(page);
        await expect(page.locator('#currentVersionDisplay')).not.toContainText(
          /(?:加载失败|context_mismatch|raw_context_mismatch)/i,
          { timeout: 20000 },
        );

        const currentIdentity = await page.evaluate(() => (
          window.Weline?.Theme?.Editor || window.ThemeEditor
        ).getScopeIdentity());
        expect(currentIdentity).toEqual(fixture.identities.website);
        await expect(page.locator('#themeBindingSource')).toHaveAttribute('data-owned', 'false');

        const availableThemeIds = await page.locator('#themeSelect option').evaluateAll((options) => options
          .map((option) => Number(option.value || 0))
          .filter((value, index, values) => value > 0 && values.indexOf(value) === index));
        expect(availableThemeIds.length).toBeGreaterThan(0);

        const globalBinding = editorContext(globalIdentity(), 'theme_binding');
        const websiteBinding = editorContext(fixture.identities.website, 'theme_binding');
        const storeBinding = editorContext(fixture.identities.store, 'theme_binding');
        const channelBinding = editorContext(fixture.identities.channel, 'theme_binding');
        const globalState = await loadWorkspace(page, globalBinding, 'load Global Theme binding');
        const globalThemeId = Number(globalState.draft_payload?.theme_id || 0);
        expect(globalThemeId).toBeGreaterThan(0);

        const websiteThemeId = availableThemeIds.find((id) => id !== globalThemeId) || globalThemeId;
        const storeThemeId = availableThemeIds.find((id) => id !== websiteThemeId) || globalThemeId;
        const channelThemeId = availableThemeIds.find((id) => id !== storeThemeId) || websiteThemeId;

        const websiteInitial = await loadWorkspace(page, websiteBinding, 'load inherited Website Theme');
        expect(websiteInitial.owned_paths).toEqual([]);
        expect(Number(websiteInitial.draft_payload?.theme_id || 0)).toBe(globalThemeId);

        await applyWorkspace(
          page,
          websiteBinding,
          [{ op: 'set', path: '/theme_id', value: websiteThemeId }],
          'Website owns Theme binding draft',
        );
        const websiteDraft = await loadWorkspace(page, websiteBinding, 'reload Website Theme draft');
        expect(owned(websiteDraft, '/theme_id')).toBeTruthy();
        expect(Number(websiteDraft.draft_payload?.theme_id || 0)).toBe(websiteThemeId);
        expect(Number(websiteDraft.published_payload?.theme_id || 0)).toBe(globalThemeId);

        const storeBeforeWebsitePublish = await loadWorkspace(page, storeBinding, 'Store ignores parent draft');
        expect(Number(storeBeforeWebsitePublish.draft_payload?.theme_id || 0)).toBe(globalThemeId);
        expect(storeBeforeWebsitePublish.owned_paths).toEqual([]);

        const websitePublished = await publishWorkspace(page, websiteBinding, 'publish Website Theme binding');
        expect(owned(websitePublished, '/theme_id')).toBeTruthy();
        const storeInheritedWebsite = await loadWorkspace(page, storeBinding, 'Store inherits Website Theme');
        expect(storeInheritedWebsite.owned_paths).toEqual([]);
        expect(Number(storeInheritedWebsite.draft_payload?.theme_id || 0)).toBe(websiteThemeId);
        expect(storeInheritedWebsite.parent_source_scope).toBe(fixture.scopes.website);

        await page.evaluate(() => (
          window.Weline?.Theme?.Editor || window.ThemeEditor
        ).loadScopedWorkspace('theme_binding'));
        await expect(page.locator('#themeBindingSource')).toHaveAttribute('data-owned', 'true');
        expect(await page.locator('#themeBindingInherit').evaluate((button) => button.hidden)).toBeFalsy();

        await applyWorkspace(
          page,
          storeBinding,
          [{ op: 'set', path: '/theme_id', value: storeThemeId }],
          'Store owns Theme binding',
        );
        await publishWorkspace(page, storeBinding, 'publish Store Theme binding');
        const channelInheritedStore = await loadWorkspace(page, channelBinding, 'Channel inherits Store Theme');
        expect(channelInheritedStore.owned_paths).toEqual([]);
        expect(Number(channelInheritedStore.draft_payload?.theme_id || 0)).toBe(storeThemeId);
        expect(channelInheritedStore.parent_source_scope).toBe(fixture.scopes.store);

        await applyWorkspace(
          page,
          channelBinding,
          [{ op: 'set', path: '/theme_id', value: channelThemeId }],
          'Channel owns Theme binding',
        );
        const channelOwned = await publishWorkspace(page, channelBinding, 'publish Channel Theme binding');
        expect(owned(channelOwned, '/theme_id')).toBeTruthy();
        expect(Number(channelOwned.draft_payload?.theme_id || 0)).toBe(channelThemeId);

        await applyWorkspace(
          page,
          channelBinding,
          [{ op: 'inherit', path: '/theme_id' }],
          'Channel restores Theme inheritance',
        );
        const channelRestored = await publishWorkspace(page, channelBinding, 'publish Channel Theme inheritance');
        expect(channelRestored.owned_paths).toEqual([]);
        expect(Number(channelRestored.draft_payload?.theme_id || 0)).toBe(storeThemeId);

        await applyWorkspace(
          page,
          storeBinding,
          [{ op: 'inherit', path: '/theme_id' }],
          'Store restores Theme inheritance',
        );
        const storeRestored = await publishWorkspace(page, storeBinding, 'publish Store Theme inheritance');
        expect(storeRestored.owned_paths).toEqual([]);
        expect(Number(storeRestored.draft_payload?.theme_id || 0)).toBe(websiteThemeId);

        await applyWorkspace(
          page,
          websiteBinding,
          [{ op: 'inherit', path: '/theme_id' }],
          'Website restores Theme inheritance',
        );
        const websiteRestored = await publishWorkspace(page, websiteBinding, 'publish Website Theme inheritance');
        expect(websiteRestored.owned_paths).toEqual([]);
        expect(Number(websiteRestored.draft_payload?.theme_id || 0)).toBe(globalThemeId);
        const channelAfterBindingRestore = await loadWorkspace(page, channelBinding, 'Channel follows restored Theme chain');
        expect(Number(channelAfterBindingRestore.draft_payload?.theme_id || 0)).toBe(globalThemeId);

        await page.evaluate(() => (
          window.Weline?.Theme?.Editor || window.ThemeEditor
        ).loadScopedWorkspace('theme_binding'));
        await expect(page.locator('#themeBindingSource')).toHaveAttribute('data-owned', 'false');
        expect(await page.locator('#themeBindingInherit').evaluate((button) => button.hidden)).toBeTruthy();

        const websiteLayout = editorContext(fixture.identities.website, 'layout', globalThemeId, pageType);
        const storeLayout = editorContext(fixture.identities.store, 'layout', globalThemeId, pageType);
        const channelLayout = editorContext(fixture.identities.channel, 'layout', globalThemeId, pageType);

        await applyWorkspace(page, websiteLayout, [
          { op: 'set', path: '/selection/e2e_primary', value: 'website-v1' },
          { op: 'set', path: '/selection/e2e_shared', value: 'website-shared-v1' },
        ], 'Website layout draft');
        const storeBeforeLayoutPublish = await loadWorkspace(page, storeLayout, 'Store ignores Website layout draft');
        expect(Object.hasOwn(storeBeforeLayoutPublish.draft_payload?.selection || {}, 'e2e_primary')).toBeFalsy();
        await publishWorkspace(page, websiteLayout, 'publish Website layout');

        const storeInheritedLayout = await loadWorkspace(page, storeLayout, 'Store inherits Website layout paths');
        expect(storeInheritedLayout.owned_paths).toEqual([]);
        expect(storeInheritedLayout.draft_payload.selection.e2e_primary).toBe('website-v1');
        expect(storeInheritedLayout.draft_payload.selection.e2e_shared).toBe('website-shared-v1');

        const staleStoreState = await loadWorkspace(page, storeLayout, 'capture Store optimistic revision');
        await applyWorkspace(page, storeLayout, [
          { op: 'set', path: '/selection/e2e_primary', value: 'store-own' },
          { op: 'set', path: '/selection/e2e_empty', value: '' },
          { op: 'set', path: '/selection/e2e_zero', value: 0 },
          { op: 'set', path: '/selection/e2e_false', value: false },
          { op: 'set', path: '/selection/e2e_null', value: null },
        ], 'Store owns only touched paths', staleStoreState);
        let staleWriteError = '';
        try {
          await callEditorRequest(page, '/theme/backend/theme-editor/scoped-workspace', 'POST', {
            editor_context: storeLayout,
            expected_revision: Number(staleStoreState.revision || 0),
            expected_parent_release_id: staleStoreState.expected_parent_release_id ?? null,
            changes: [{ op: 'set', path: '/selection/e2e_stale', value: 'must-fail' }],
            summary: 'stale optimistic write must fail',
          });
        } catch (error) {
          staleWriteError = String(error?.message || error);
        }
        expect(staleWriteError).toContain('theme_scope_revision_conflict');
        await publishWorkspace(page, storeLayout, 'publish Store owned paths');

        await applyWorkspace(page, websiteLayout, [
          { op: 'set', path: '/selection/e2e_primary', value: 'website-v2' },
          { op: 'set', path: '/selection/e2e_shared', value: 'website-shared-v2' },
        ], 'Website changes owned and unowned paths');
        await publishWorkspace(page, websiteLayout, 'publish Website path update');

        const storeAfterParentUpdate = await loadWorkspace(page, storeLayout, 'Store merges Website path update');
        expect(storeAfterParentUpdate.draft_payload.selection.e2e_primary).toBe('store-own');
        expect(storeAfterParentUpdate.draft_payload.selection.e2e_shared).toBe('website-shared-v2');
        expect(storeAfterParentUpdate.draft_payload.selection.e2e_empty).toBe('');
        expect(storeAfterParentUpdate.draft_payload.selection.e2e_zero).toBe(0);
        expect(storeAfterParentUpdate.draft_payload.selection.e2e_false).toBe(false);
        expect(storeAfterParentUpdate.draft_payload.selection.e2e_null).toBeNull();
        expect(storeAfterParentUpdate.owned_paths).toHaveLength(5);
        expect(storeAfterParentUpdate.owned_paths).toEqual(expect.arrayContaining([
          '/selection/e2e_primary',
          '/selection/e2e_empty',
          '/selection/e2e_zero',
          '/selection/e2e_false',
          '/selection/e2e_null',
        ]));

        await applyWorkspace(
          page,
          channelLayout,
          [{ op: 'set', path: '/selection/e2e_shared', value: 'channel-own' }],
          'Channel owns one layout path',
        );
        await publishWorkspace(page, channelLayout, 'publish Channel owned path');

        await applyWorkspace(
          page,
          websiteLayout,
          [{ op: 'set', path: '/selection/e2e_shared', value: 'website-shared-v3' }],
          'Website updates path owned by Channel',
        );
        await publishWorkspace(page, websiteLayout, 'publish Website update under Channel override');
        const channelAfterParentUpdate = await loadWorkspace(page, channelLayout, 'Channel retains owned path');
        expect(channelAfterParentUpdate.draft_payload.selection.e2e_shared).toBe('channel-own');
        expect(channelAfterParentUpdate.draft_payload.selection.e2e_primary).toBe('store-own');
        expect(channelAfterParentUpdate.draft_payload.selection.e2e_empty).toBe('');
        expect(channelAfterParentUpdate.draft_payload.selection.e2e_zero).toBe(0);
        expect(channelAfterParentUpdate.draft_payload.selection.e2e_false).toBe(false);
        expect(channelAfterParentUpdate.draft_payload.selection.e2e_null).toBeNull();

        await applyWorkspace(
          page,
          storeLayout,
          [{ op: 'inherit', path: '/selection/e2e_primary' }],
          'Store restores one path inheritance',
        );
        const storeAfterRestore = await publishWorkspace(page, storeLayout, 'publish Store path inheritance');
        expect(owned(storeAfterRestore, '/selection/e2e_primary')).toBeFalsy();
        expect(storeAfterRestore.draft_payload.selection.e2e_primary).toBe('website-v2');
        const channelAfterStoreRestore = await loadWorkspace(page, channelLayout, 'Channel follows restored Store path');
        expect(channelAfterStoreRestore.draft_payload.selection.e2e_primary).toBe('website-v2');
        expect(channelAfterStoreRestore.draft_payload.selection.e2e_shared).toBe('channel-own');

        await applyWorkspace(
          page,
          channelLayout,
          [{ op: 'inherit', path: '/selection/e2e_shared' }],
          'Channel restores one path inheritance',
        );
        const channelAfterRestore = await publishWorkspace(page, channelLayout, 'publish Channel path inheritance');
        expect(channelAfterRestore.owned_paths).toEqual([]);
        expect(channelAfterRestore.draft_payload.selection.e2e_shared).toBe('website-shared-v3');

        const tamperedContext = editorContext(
          { ...fixture.identities.website, website_id: fixture.website_id + 1000 },
          'theme_binding',
        );
        const tamperedQuery = new URLSearchParams({ editor_context: JSON.stringify(tamperedContext) });
        let tamperedError = '';
        try {
          await callEditorRequest(
            page,
            `/theme/backend/theme-editor/scoped-workspace?${tamperedQuery.toString()}`,
            'GET',
          );
        } catch (error) {
          tamperedError = String(error?.message || error);
        }
        expect(tamperedError).toMatch(/identity|catalog|website/i);
      } finally {
        const cleanup = runFixture('cleanup_scope_hierarchy', fixturePayload);
        expect(cleanup.success).toBeTruthy();
      }
    },
  );
});
