// @weline-e2e-runtime wls
// @weline-e2e-transport direct
/**
 * Task 6 overall acceptance: version-artifact isolation.
 * Covers UC-13 convert/hard-cut, UC-01/04/07 editor contract + create/save/publish formal tree.
 * Reuses theme-editor login/scope fixtures; does not write credentials into the suite.
 */

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  getActiveTheme,
  gotoBackend,
  gotoFrontend,
  loginAsAdmin,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Theme';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const EDITOR_FIXTURE = path.resolve(__dirname, 'theme-editor-fixture.php');
const ISOLATION_FIXTURE = path.resolve(__dirname, 'theme-version-artifact-isolation-fixture.php');

/**
 * 夹具契约：stdout 就是 JSON。但某些真实通路（例如 setup:upgrade 清盘观察者）
 * 会先用 Printing 把进度打到 stdout，于是整段 stdout 不再是合法 JSON。
 * 这里先按整体解析，失败再退化为「从末尾往前找第一行能解析成 JSON 的行」，
 * 这样既保住原有契约，也不会让进度输出把用例判成语法错误。
 */
function parseFixtureJson(stdout) {
  const text = String(stdout || '').trim();
  if (text === '') {
    throw new Error('fixture stdout is empty');
  }
  try {
    return JSON.parse(text);
  } catch {
    const lines = text.split('\n');
    for (let i = lines.length - 1; i >= 0; i -= 1) {
      const line = lines[i].trim();
      if (!line.startsWith('{')) {
        continue;
      }
      try {
        return JSON.parse(line);
      } catch {
        // 继续往前找
      }
    }
  }
  throw new Error(`fixture stdout is not JSON: ${text.slice(0, 400)}`);
}

function runPhpFixture(script, action, payload) {
  const stdout = execFileSync('php', [script], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...(payload || {}) }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  return parseFixtureJson(stdout);
}

function makePageType(testInfo) {
  const worker = Number(testInfo.workerIndex || 0).toString(36);
  return `e2e_iso_${Date.now().toString(36)}_${worker}`;
}

function makeScopeToken(testInfo) {
  const worker = Number(testInfo.workerIndex || 0).toString(36);
  return `iso_${Date.now().toString(36)}_${worker}`;
}

/**
 * 建一套「独立测试 scope」（website/store/channel）供所有写库 action 使用。
 *
 * 背景：本夹具历史上默认写线上活跃主题的主 owner（default.default.default），
 * 导致 e2e 反复发布把线上 owner 的 selection 推到空版本（缺口 #5 的污染源）。
 * 因此所有会写 selection / 版本树的 action 都必须显式绑定独立 scope，
 * 并在 finally 中删掉该 scope 的数据。
 *
 * @returns {{token: string, scopes: Record<string, string>, cleanup: () => void}}
 */
function prepareDedicatedScope(themeId, pageType, testInfo) {
  const token = makeScopeToken(testInfo);
  const hierarchy = runPhpFixture(EDITOR_FIXTURE, 'prepare_scope_hierarchy', {
    theme_id: themeId,
    page_type: pageType,
    token,
  });
  expectOk(hierarchy, 'prepare_scope_hierarchy');
  const scopes = hierarchy.scopes || {};
  expect(scopes.website, JSON.stringify(hierarchy)).toBeTruthy();
  expect(scopes.store, JSON.stringify(hierarchy)).toBeTruthy();
  // 独立 scope 绝不能等于线上主 owner，否则隔离失效。
  expect(scopes.store).not.toBe('default.default.default');
  expect(scopes.website).not.toBe('default.default.default');
  return {
    token,
    scopes,
    cleanup() {
      // 先清 v3（selection/version/子表 + 产物目录），再清 v2。
      // editor 夹具的 cleanup_scope_hierarchy 只覆盖 v2 表，不清 v3；
      // 漏清会让每轮 e2e 都残留一批 e2e-theme-scope-* owner。
      Object.values(scopes).forEach((scopeString) => {
        if (typeof scopeString !== 'string' || scopeString === '' || scopeString === 'default.default.default') {
          return;
        }
        runPhpFixture(ISOLATION_FIXTURE, 'purge_test_scope', {
          theme_id: themeId,
          scope: scopeString,
          store_mode: 'normal',
          area: 'frontend',
        });
      });
      runPhpFixture(EDITOR_FIXTURE, 'cleanup_scope_hierarchy', {
        theme_id: themeId,
        page_type: pageType,
        token,
      });
    },
  };
}

async function waitForThemeEditorShell(page) {
  await page.locator('#themeEditor').waitFor({ state: 'attached', timeout: 60000 });
  await expect(page.locator('#previewFrame')).toHaveAttribute('src', /editor_mode=1/, { timeout: 90000 });
  await expect(page.locator('#previewFrame')).not.toHaveAttribute('src', /theme-preview\/content|theme-editor\/layout-preview/);
}

async function waitForThemeEditor(page) {
  await waitForThemeEditorShell(page);
  await page.waitForFunction(() => {
    if (window.ThemeEditor && typeof window.ThemeEditor.apiJson === 'function') {
      return true;
    }
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
    timeout: 90000,
  }).catch(async (error) => {
    const dump = await page.evaluate(() => ({
      href: location.href,
      hasThemeEditor: typeof window.ThemeEditor,
      themeEditorKeys: window.ThemeEditor ? Object.keys(window.ThemeEditor).slice(0, 20) : [],
      hasWeline: !!window.Weline,
      hasWelineApi: !!(window.Weline && window.Weline.Api),
      hasWelineApiModule: typeof window.WelineApiModule,
      scriptErrors: (window.__e2eScriptErrors || []).slice(0, 8),
    }));
    throw new Error(`${error.message}; api dump=${JSON.stringify(dump)}`);
  });
}

async function waitUntilPreviewNotLoading(page) {
  await expect(page.locator('#previewLoading')).toHaveClass(/hidden/, { timeout: 60000 });
}

function expectOk(result, label) {
  expect(result, `${label} response`).toBeTruthy();
  expect(result.success, `${label}: ${JSON.stringify(result)}`).toBeTruthy();
}

async function callEditorRequest(page, url, method = 'GET', body = null, attempts = 6) {
  let lastError = null;
  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    try {
      const resolved = await page.evaluate((rawInput) => {
        const editorRoute = '/theme/backend/theme-editor';
        const el = document.querySelector('#themeEditor');
        const apiBase = (el?.dataset?.apiBase || '').replace(/\/+$/, '');
        let requestUrl = rawInput.url;
        if (apiBase && requestUrl.startsWith(editorRoute)) {
          requestUrl = apiBase + requestUrl.slice(editorRoute.length);
        }
        if (!/^https?:\/\//i.test(requestUrl)) {
          requestUrl = new URL(requestUrl, location.origin).toString();
        }
        if (rawInput.method === 'GET' && rawInput.body && typeof rawInput.body === 'object') {
          const u = new URL(requestUrl);
          Object.entries(rawInput.body).forEach(([key, value]) => {
            if (value === undefined || value === null) {
              return;
            }
            u.searchParams.set(key, String(value));
          });
          requestUrl = u.toString();
        }
        return requestUrl;
      }, { url, method, body });

      // Node-side Playwright request avoids page-patched window.fetch / Weline.Api circuit.
      const options = {
        headers: {
          accept: 'application/json',
          'x-requested-with': 'XMLHttpRequest',
        },
        failOnStatusCode: false,
      };
      let response;
      if (method === 'GET') {
        response = await page.request.get(resolved, options);
      } else {
        options.headers['content-type'] = 'application/json';
        options.data = body === null || body === undefined ? undefined : body;
        response = method === 'POST'
          ? await page.request.post(resolved, options)
          : await page.request.fetch(resolved, { ...options, method });
      }
      const text = await response.text();
      let json = null;
      try {
        json = text ? JSON.parse(text) : null;
      } catch (parseError) {
        throw new Error(`non-json ${response.status()}: ${text.slice(0, 240)}`);
      }
      if (!response.ok() && (!json || json.success !== true)) {
        throw new Error(`http ${response.status()}: ${text.slice(0, 240)}`);
      }
      return json;
    } catch (error) {
      lastError = error;
      const message = String(error && error.message ? error.message : error);
      if (!/too many in-flight|circuit|cooling|ECONNRESET|http 429|http 503/i.test(message) || attempt >= attempts) {
        throw error;
      }
      await page.waitForTimeout(2000 * attempt);
    }
  }
  throw lastError;
}

moduleDescribe(test, MODULE, 'theme version artifact isolation (Task 6)', () => {
  test.setTimeout(240000);

  moduleCase(test, { module: MODULE, id: 'UC-13' }, '转换幂等：pending=0 且无旧 migrate-bindings', async () => {
    const status = runPhpFixture(ISOLATION_FIXTURE, 'convert_status', {});
    expectOk(status, 'convert_status');
    expect(status.pending_count, JSON.stringify(status)).toBe(0);
    expect(status.already_converted_count).toBeGreaterThan(0);

    const tree = runPhpFixture(ISOLATION_FIXTURE, 'inspect_tree', {});
    expectOk(tree, 'inspect_tree');
    expect(tree.legacy_count, JSON.stringify(tree.legacy_sample)).toBe(0);
  });

  moduleCase(test, { module: MODULE, id: 'UC-01-04-07' }, 'scope-* 编辑器契约：新 data-api 在场且旧 versions/* 已移除', async ({ page }, testInfo) => {
    const activeTheme = getActiveTheme('frontend');
    const themeId = Number(activeTheme?.id || activeTheme?.theme_id || 0);
    expect(themeId).toBeGreaterThan(0);
    const pageType = makePageType(testInfo);

    runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
    try {
      await loginAsAdmin(page, { timeout: 60000, settleMs: 1000 });
      await page.addInitScript(() => {
        window.__e2eScriptErrors = [];
        window.addEventListener('error', (event) => {
          window.__e2eScriptErrors.push(String(event.message || event.error || 'error'));
        });
      });
      await gotoBackend(page, `theme/backend/theme-editor?theme_id=${themeId}&editor_area=frontend&page_type=${pageType}`, {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
        settleMs: 1500,
      });
      await waitForThemeEditorShell(page);
      await waitUntilPreviewNotLoading(page);

      const editorRoot = page.locator('#themeEditor');
      await expect(editorRoot).toHaveAttribute('data-api-scope-versions', /scope-versions/);
      await expect(editorRoot).toHaveAttribute('data-api-create-scope-draft', /create-scope-draft/);
      await expect(editorRoot).toHaveAttribute('data-api-save-scope-version', /save-scope-version/);
      await expect(editorRoot).toHaveAttribute('data-api-publish-scope-version', /publish-scope-version/);
      await expect(editorRoot).toHaveAttribute('data-api-restore-scope-defaults', /restore-scope-defaults/);
      await expect(editorRoot).not.toHaveAttribute('data-api-save-version', /./);
      await expect(editorRoot).not.toHaveAttribute('data-api-switch-version', /./);
      await expect(editorRoot).not.toHaveAttribute('data-api-restore-original', /./);
      await expect(editorRoot).not.toHaveAttribute('data-api-publish-version', /./);
      await expect(editorRoot).not.toHaveAttribute('data-api-versions', /./);

      const tree = runPhpFixture(ISOLATION_FIXTURE, 'inspect_tree', { theme_id: themeId });
      expectOk(tree, 'inspect_tree');
      expect(tree.legacy_count, JSON.stringify(tree.legacy_sample)).toBe(0);

      await expect(page.frameLocator('#previewFrame').locator('body')).toBeVisible({ timeout: 30000 });
    } finally {
      runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
    }
  });

  moduleCase(
    test,
    { module: MODULE, id: 'UC-01-03-04' },
    'create/save/publish：正式树落在 tvN/formal，无 draft 段泄漏',
    async ({ page }, testInfo) => {
      const activeTheme = getActiveTheme('frontend');
      const themeId = Number(activeTheme?.id || activeTheme?.theme_id || 0);
      expect(themeId).toBeGreaterThan(0);
      const pageType = makePageType(testInfo);
      const scope = prepareDedicatedScope(themeId, pageType, testInfo);

      runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
      try {
        const flow = runPhpFixture(ISOLATION_FIXTURE, 'publish_flow', {
          theme_id: themeId,
          page_type: pageType,
          marker: 'ISO E2E Button',
          scope: scope.scopes.store,
          store_mode: 'normal',
        });
        expectOk(flow, 'publish_flow');
        expect(Number(flow.published_version_id || 0)).toBeGreaterThan(0);
        expect(String(flow.published_dir || '')).toMatch(/\/tv\d+\/formal(?:\/|$)/);
        expect(String(flow.published_dir || '')).not.toMatch(/\/draft(?:\/|$)/);
        expect(Number(flow.published_version_id)).toBe(Number(flow.seal?.data?.theme_version_id || flow.draft_version_id || 0));

        const selection = runPhpFixture(ISOLATION_FIXTURE, 'selection', {
          theme_id: themeId,
          scope: scope.scopes.store,
          store_mode: 'normal',
          area: 'frontend',
        });
        expectOk(selection, 'selection after publish');
        expect(Number(selection.published_version_id || 0)).toBe(Number(flow.published_version_id));
        expect(String(selection.published_dir || '')).toMatch(/\/tv\d+\/formal(?:\/|$)/);

        const tree = runPhpFixture(ISOLATION_FIXTURE, 'inspect_tree', { theme_id: themeId });
        expectOk(tree, 'inspect_tree after publish');
        expect(tree.legacy_count, JSON.stringify(tree.legacy_sample)).toBe(0);

        await loginAsAdmin(page, { timeout: 60000, settleMs: 1000 });
        await gotoBackend(page, `theme/backend/theme-editor?theme_id=${themeId}&editor_area=frontend&page_type=${pageType}`, {
          waitUntil: 'domcontentloaded',
          timeout: 60000,
          settleMs: 1500,
        });
        await waitForThemeEditorShell(page);
        const editorRoot = page.locator('#themeEditor');
        await expect(editorRoot).toHaveAttribute('data-api-publish-scope-version', /publish-scope-version/);
        await expect(editorRoot).not.toHaveAttribute('data-api-publish-version', /./);
      } finally {
        scope.cleanup();
        runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
      }
    },
  );

  moduleCase(test, { module: MODULE, id: 'UC-02' }, '草稿耐久：清派生物后 D 仍在 selection，P 不变', async ({}, testInfo) => {
    const activeTheme = getActiveTheme('frontend');
    const themeId = Number(activeTheme?.id || activeTheme?.theme_id || 0);
    expect(themeId).toBeGreaterThan(0);
    const pageType = makePageType(testInfo);
    const scope = prepareDedicatedScope(themeId, pageType, testInfo);

    runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
    try {
      const result = runPhpFixture(ISOLATION_FIXTURE, 'draft_durability', {
        theme_id: themeId,
        page_type: pageType,
        scope: scope.scopes.store,
        store_mode: 'normal',
      });
      expectOk(result, 'draft_durability');
      // 断言非空转：必须先有真实存在的正式版本 P，再谈「P 不变」。
      expect(Number(result.published_before || 0), JSON.stringify(result)).toBeGreaterThan(0);
      expect(result.draft_survived, JSON.stringify(result)).toBeTruthy();
      expect(result.published_unchanged, JSON.stringify(result)).toBeTruthy();
      expect(Number(result.draft_version_id || 0)).toBeGreaterThan(0);
      expect(String(result.draft_lifecycle)).toBe('draft');
    } finally {
      scope.cleanup();
      runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
    }
  });

  moduleCase(test, { module: MODULE, id: 'UC-06' }, '文件复用：同 hash 路径/inode 独立，写目标不污染源版本', async ({}, testInfo) => {
    const activeTheme = getActiveTheme('frontend');
    const themeId = Number(activeTheme?.id || activeTheme?.theme_id || 0);
    expect(themeId).toBeGreaterThan(0);
    const pageType = makePageType(testInfo);
    const scope = prepareDedicatedScope(themeId, pageType, testInfo);

    runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
    try {
      const result = runPhpFixture(ISOLATION_FIXTURE, 'artifact_reuse', {
        theme_id: themeId,
        page_type: pageType,
        marker: `UC06 ${pageType}`,
        scope: scope.scopes.store,
        store_mode: 'normal',
      });
      expectOk(result, 'artifact_reuse');

      // 断言非空转：必须有真实固化产物（layout.phtml + shell.phtml），否则路径/inode 断言无意义。
      expect(result.artifacts_materialized, JSON.stringify(result)).toBeTruthy();
      expect(Number(result.v1_artifact_count || 0)).toBeGreaterThan(0);
      expect(Number(result.v2_artifact_count || 0)).toBeGreaterThan(0);
      expect(Number(result.v3_artifact_count || 0)).toBeGreaterThan(0);

      // 三个版本各自目录，互不相同。
      expect(result.paths_distinct, JSON.stringify(result)).toBeTruthy();
      expect(Number(result.v1_published_version_id || 0)).toBeGreaterThan(0);
      expect(Number(result.v2_published_version_id || 0)).toBeGreaterThan(0);
      expect(Number(result.v3_published_version_id || 0)).toBeGreaterThan(0);

      // 同 owner/同内容/仅 V 不同：结构身份（hash）相同，但路径各属其版本。
      expect(result.v1_v2_same_structure_key, JSON.stringify(result)).toBeTruthy();

      // 未选历史继承：不得共用 inode（不得隐式硬链）。
      expect(result.v1_v2_inode_isolated, JSON.stringify(result)).toBeTruthy();
      expect(result.v1_v2_shared_inodes, JSON.stringify(result)).toEqual([]);

      // 显式历史继承：内容等价（同结构身份），且写目标版本不得污染源版本字节。
      expect(result.v1_v3_same_structure_key, JSON.stringify(result)).toBeTruthy();
      expect(result.source_untouched, JSON.stringify(result)).toBeTruthy();
      expect(result.v1_bytes_changed_after_v3, JSON.stringify(result)).toEqual([]);
      expect(result.v1_inode_changed_after_v3, JSON.stringify(result)).toEqual([]);

      // 强制重烘目标版本（V2）后，源版本（V1）仍逐字节不变 —— 硬链写入污染源的核心风险。
      expect(result.v2_rebake_structure_key_changed, JSON.stringify(result)).toBeTruthy();
      expect(result.source_untouched_after_target_rebake, JSON.stringify(result)).toBeTruthy();
      expect(result.v1_bytes_changed_after_v2_rebake, JSON.stringify(result)).toEqual([]);
      expect(result.v1_inode_changed_after_v2_rebake, JSON.stringify(result)).toEqual([]);
    } finally {
      scope.cleanup();
      runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
    }
  });

  // UC-08 写入端作用范围传播：祖先基准继承 + 后代版本生成（缺口 #1）。
  // 断言全部读真实 DB 行 / 真实发布返回值，不复用发布结果自证。
  moduleCase(test, { module: MODULE, id: 'GAP-01' }, 'Scope 传播：子继承祖先基准、未覆盖值进入无冲突 C\'、本级覆盖保留、冲突子整套留 C、C\' 父来源不漂移', async ({}, testInfo) => {
    const activeTheme = getActiveTheme('frontend');
    const themeId = Number(activeTheme?.id || activeTheme?.theme_id || 0);
    expect(themeId).toBeGreaterThan(0);
    const pageType = makePageType(testInfo);
    // 复用统一 helper：它同时清 v2 与 v3，避免 scope 行残留。
    const scope = prepareDedicatedScope(themeId, pageType, testInfo);
    const scopes = scope.scopes;
    expect(scopes.website, JSON.stringify(scopes)).toBeTruthy();
    expect(scopes.store, JSON.stringify(scopes)).toBeTruthy();
    expect(scopes.channel, JSON.stringify(scopes)).toBeTruthy();

    try {
      const result = runPhpFixture(ISOLATION_FIXTURE, 'scope_propagation_probe', {
        theme_id: themeId,
        page_type: pageType,
        parent_scope: scopes.website,
        child_scope: scopes.store,
        grandchild_scope: scopes.channel,
        marker: `GAP01 ${pageType}`,
      });
      expectOk(result, 'scope_propagation_probe');

      // 非空转：父四次发布与两个子发布都真实成功。
      expect(result.parent_publish_ok, JSON.stringify(result.parent_publish_steps)).toEqual([true, true, true, true]);
      expect(result.override_child_publish_ok, JSON.stringify(result.override_child_publish_steps)).toBeTruthy();
      expect(result.child_publish_ok, JSON.stringify(result.child_publish_steps)).toBeTruthy();
      expect(Number(result.parent_published_versions?.[0] || 0)).toBeGreaterThan(0);

      // 传播确实接进真实写路径：枚举出的后代非空、且确实是严格后代（不是空转常量）。
      expect(result.write_path_descendant_propagation_wired, JSON.stringify(result.p2_descendant_changed_resources)).toBe(true);
      expect(result.child_is_strict_descendant_of_parent, JSON.stringify(result.child_ancestor_chain)).toBe(true);
      expect(Array.isArray(result.descendant_owners_enumerated)).toBe(true);
      expect(result.descendant_owners_enumerated.length, JSON.stringify(result.descendant_owners_enumerated))
        .toBeGreaterThanOrEqual(2);
      expect(result.descendant_owners_enumerated).toContain(scopes.store);
      expect(result.descendant_owners_enumerated).toContain(scopes.channel);

      // A) 写入端祖先基准继承：本级无已发布版本时基准来自祖先 owner。
      expect(result.child_can_inherit_ancestor_base, JSON.stringify(result.child_create_steps)).toBe(true);
      expect(result.child_base_inherited_from_ancestor).toBe(true);
      expect(result.child_base_is_ancestor_published, JSON.stringify({
        base: result.child_base_version_id,
        parentP1: result.parent_published_versions?.[0],
      })).toBe(true);
      expect(result.child_base_source_is_parent_scope, String(result.child_base_source_scope)).toBe(true);

      // E) 无本级覆盖者用祖先 owner：还没有版本行的 scope 由祖先解析出已发布版本。
      expect(result.fallback_resolved_from_ancestor_owner, JSON.stringify(result.fallback_resolved_without_local_versions)).toBe(true);

      // B) 未覆盖值进入无冲突 C'：覆盖 layout、未覆盖 chrome 的子，父再发布后获得系统派生版本。
      const cPrime = result.c_prime_derived_revision || {};
      expect(Number(result.c_prime_version_before_parent_republish || 0)).toBeGreaterThan(0);
      expect(result.c_prime_got_derived_version, JSON.stringify(result)).toBe(true);
      // 只有未覆盖的 chrome 穿透进 C'；本级覆盖的 layout 仍在后代手里。
      expect(result.c_prime_inherited_resources, JSON.stringify(result)).toEqual(['chrome']);
      expect(result.c_prime_overridden_resources, JSON.stringify(result)).toContain(`layout:${pageType}`);
      expect(result.c_prime_records_new_parent, JSON.stringify(cPrime)).toBe(true);
      expect(Number(cPrime.scope_source_version_id)).toBe(Number(result.parent_published_versions?.[1] || 0));
      // 本地意图基准是子自己原来的那个 C：本级覆盖不因父发布而丢失。
      expect(result.c_prime_base_is_previous_child_version, JSON.stringify(cPrime)).toBe(true);
      expect(Number(cPrime.base_version_id)).toBe(Number(result.c_prime_version_before_parent_republish));
      expect(result.c_prime_actor_is_system, JSON.stringify(cPrime)).toBe(true);
      expect(result.c_prime_lifecycle).toBe('sealed');
      expect(result.c_prime_version_type).toBe('scope_rebase');
      expect(result.c_prime_creation_source_is_previous_child_version, JSON.stringify({
        creation: result.c_prime_creation_source_version_id,
        before: result.c_prime_version_before_parent_republish,
      })).toBe(true);

      // ★ 不变量：C' 会**立即**成为该 owner 的 published_version_id，因此必须带可比的
      // structure_key —— 它继承 chrome，所以结构就等于父 P2 的结构。修复前这里是空串，
      // 而 structureKeyChanged() 的 `$prevKey !== '' && $nextKey !== ''` 守卫会把
      // 「该 owner 的上一已发布版本」判成「结构未变化」（见 UC-08-derived-parent）。
      expect(result.c_prime_structure_key, JSON.stringify({
        cPrime: result.c_prime_structure_key,
        parentP2: result.c_prime_inherited_chrome_source_structure_key,
        sourceVersion: result.c_prime_inherited_chrome_source_version_id,
      })).toBeTruthy();
      expect(result.c_prime_structure_key_matches_inherited_chrome, JSON.stringify({
        cPrime: result.c_prime_structure_key,
        parentP2: result.c_prime_inherited_chrome_source_structure_key,
      })).toBe(true);
      expect(result.c_prime_structure_key).toBe(result.c_prime_inherited_chrome_source_structure_key);

      // 缺陷 B 的回归护栏：父每次发布后的版本行都必须带 chrome 载荷，不能是空壳行。
      // 旧实现下 P2/P3 的载荷会塌成空数组（structure_key = sha256('[]')），让「父 chrome
      // 结构变化」恒真、冲突判定失真 —— 实测旧值 p2=0 / 修复后 p2=23。这条断言专门防止
      // 那种「空壳塌陷冒充结构变化」的情况再悄悄回来。
      const chromeCounts = result.parent_chrome_node_counts || {};
      expect(Number(chromeCounts.p1 || 0), JSON.stringify(chromeCounts)).toBeGreaterThan(0);
      expect(Number(chromeCounts.p2 || 0), JSON.stringify(chromeCounts)).toBeGreaterThan(0);
      expect(Number(chromeCounts.p4 || 0), JSON.stringify(chromeCounts)).toBeGreaterThan(0);

      // C) 冲突子整套留 C：覆盖 chrome + 有未覆盖变更 + 父 chrome 结构变化 ⇒ 不进 updates、整套保留 C。
      expect(result.parent_p1_p2_structure_changed, JSON.stringify(result.parent_structure_keys)).toBe(true);
      expect(result.conflict_child_bucket_scopes, JSON.stringify(result)).toContain(scopes.store);
      expect(result.p2_descendant_update_scopes, JSON.stringify(result)).toContain(scopes.channel);
      expect(result.conflict_child_kept_c, JSON.stringify({
        before: result.conflict_child_version_before,
        after: result.conflict_child_version_after,
      })).toBe(true);
      expect(result.conflict_child_kept_version_matches_c, JSON.stringify(result)).toBe(true);
      expect(Number(result.conflict_child_kept_version_id)).toBe(Number(result.conflict_child_version_before));
      expect(result.conflict_child_c1_lifecycle).toBe('sealed');

      // D) 历史不追今日父版：C' 记录的父来源固定为 P2，父再发 P3 后不变。
      expect(result.c_prime_got_second_derived_version, JSON.stringify(result)).toBe(true);
      expect(result.derived_source_unchanged_after_parent_republish, JSON.stringify({
        afterP3: result.derived_revision_after_parent_republish,
        versions: result.parent_published_versions,
      })).toBe(true);
      expect(Number(result.derived_revision_after_parent_republish?.scope_source_version_id || 0))
        .toBe(Number(result.parent_published_versions?.[1] || 0));
      // 固定的是 P2 那一代 C' 自己的父来源，而不是最新父版。
      expect(Number(result.derived_revision_after_parent_republish?.scope_source_version_id || 0))
        .not.toBe(Number(result.parent_published_versions?.[2] || 0));
    } finally {
      scope.cleanup();
      runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
    }
  });

  // 系统派生版本 C' 作为「上一已发布版本」时的冲突判定 + chrome ensure 的 bootstrap 边界。
  // 两处缺陷同一根因（C' 是「半成品已发布版本」）：
  //   D) C' 不带 structure_key ⇒ 持有 C' 的 owner 再发布时被判成「父 chrome 结构未变化」，
  //      覆盖 chrome 的冲突子被误判成可自动前进、本级 chrome 覆盖被 C'_leaf 顶掉。
  //   F) C' 按设计没有磁盘产物 ⇒ 解析不到已发布 chrome ⇒ bootstrap 回退把**用户正在编辑的
  //      草稿**直接 markPublished()（静默发布，违反 UC-04），并让发布走 selectHistory()、
  //      后代传播整段失效。
  moduleCase(
    test,
    { module: MODULE, id: 'UC-08-derived-parent' },
    "系统派生 C' 作为上一已发布版本：C' 带可比 structure_key、再发布走 publish()、覆盖 chrome 的冲突子整套留 C，且 bootstrap 不得把草稿当已发布发布",
    async ({}, testInfo) => {
      const activeTheme = getActiveTheme('frontend');
      const themeId = Number(activeTheme?.id || activeTheme?.theme_id || 0);
      expect(themeId).toBeGreaterThan(0);
      const pageType = makePageType(testInfo);
      const scope = prepareDedicatedScope(themeId, pageType, testInfo);
      const scopes = scope.scopes;
      try {
        const result = runPhpFixture(ISOLATION_FIXTURE, 'derived_parent_republish', {
          theme_id: themeId,
          page_type: pageType,
          root_scope: scopes.website,
          mid_scope: scopes.store,
          leaf_scope: scopes.channel,
          marker: `DP ${pageType}`,
        });
        expectOk(result, 'derived_parent_republish');

        // 非空转：五步真实发布全部成功，且 leaf 真的存下了一个 header 部件。
        expect(result.step_ok, JSON.stringify(result.step_ok)).toEqual({
          root_p1: true,
          mid_own: true,
          leaf_own: true,
          root_p2: true,
          mid_republish: true,
        });
        expect(result.leaf_widget_saved).toBe(true);

        // 场景建立：mid 的 published 确实变成了系统派生版本 C'，且以 P2 为父来源。
        expect(result.mid_got_derived_version_after_root_p2, JSON.stringify(result.versions)).toBe(true);
        expect(result.mid_c_prime_version_type).toBe('scope_rebase');
        expect(result.mid_c_prime_lifecycle).toBe('sealed');
        expect(result.mid_c_prime_scope_source_is_p2, JSON.stringify(result.versions)).toBe(true);

        // ★ 缺陷 D：C' 必须带可比 structure_key，且等于它继承 chrome 的那个版本（P2）的键。
        expect(result.p2_structure_key, JSON.stringify(result)).toBeTruthy();
        expect(result.mid_c_prime_structure_key, JSON.stringify(result)).toBeTruthy();
        expect(result.mid_c_prime_structure_key_matches_p2).toBe(true);
        expect(result.mid_c_prime_structure_key).toBe(result.p2_structure_key);

        // 对照：root 再发布时，覆盖 chrome 的 leaf 必须整套留 C。
        expect(result.leaf_kept_c_after_root_p2, JSON.stringify(result.versions)).toBe(true);
        expect(result.root_p2_conflict_scopes, JSON.stringify(result)).toContain(scopes.channel);

        // ★ 缺陷 F：封存不得把草稿当已发布发布 —— published 不变、draft 仍指向新草稿。
        expect(result.mid_republish_kept_draft_pointer, JSON.stringify({
          afterSeal: result.mid_selection_after_republish_seal,
          newDraft: result.mid_republish_version_id,
        })).toBe(true);

        // ★ 触发点成立：mid 的上一已发布版本(C') 与本次新版本都必须有非空 structure_key，
        //   否则 structureKeyChanged() 会直接返回 false。
        expect(result.mid_prev_structure_key_before_republish, JSON.stringify(result)).toBeTruthy();
        expect(result.mid_new_structure_key_after_republish, JSON.stringify(result)).toBeTruthy();
        expect(result.mid_prev_structure_key_before_republish)
          .not.toBe(result.mid_new_structure_key_after_republish);
        expect(result.mid_republish_changed_resources, JSON.stringify(result)).toContain('chrome');

        // 走的是 publish() 而不是 selectHistory()：否则后代传播整段被跳过，本用例失去判别力。
        expect(result.mid_republish_went_publish_path, JSON.stringify({
          before: result.mid_selection_before_republish_publish,
          newVersion: result.mid_republish_version_id,
        })).toBe(true);

        // ★ 缺陷 D 的行为后果：mid 的 chrome 结构确实变了 ⇒ 覆盖 chrome 的 leaf 必须留 C，
        //   不得被自动前进到 C'_leaf（那会把 leaf 自己的 chrome 覆盖顶掉）。
        expect(result.mid_republish_conflict_scopes, JSON.stringify(result)).toContain(scopes.channel);
        expect(result.mid_republish_update_scopes, JSON.stringify(result)).not.toContain(scopes.channel);
        expect(result.leaf_bucket_is_conflict_after_mid_republish).toBe(true);
        expect(result.leaf_not_auto_advanced_after_mid_republish).toBe(true);
        expect(result.leaf_kept_c_after_mid_republish, JSON.stringify(result.versions)).toBe(true);
      } finally {
        scope.cleanup();
        runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
      }
    },
  );

  moduleCase(test, { module: MODULE, id: 'UC-10' }, '纯配置：只改配置时 PHTML hash/mtime/inode 不变，新旧配置不混', async ({}, testInfo) => {
    const activeTheme = getActiveTheme('frontend');
    const themeId = Number(activeTheme?.id || activeTheme?.theme_id || 0);
    expect(themeId).toBeGreaterThan(0);
    const pageType = makePageType(testInfo);
    const scope = prepareDedicatedScope(themeId, pageType, testInfo);

    runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
    try {
      const result = runPhpFixture(ISOLATION_FIXTURE, 'config_only_publish', {
        theme_id: themeId,
        page_type: pageType,
        marker: `UC10 ${pageType}`,
        scope: scope.scopes.store,
        store_mode: 'normal',
      });
      expectOk(result, 'config_only_publish');
      expect(Number(result.published_version_id || 0)).toBeGreaterThan(0);
      expect(String(result.structure_key || '')).toMatch(/^[a-f0-9]{64}$/);
      expect(result.layout_path_stable, JSON.stringify(result)).toBeTruthy();

      // 核心：只改配置不得重写关系 PHTML（hash/mtime/inode 三者都不变）。
      expect(result.phtml_hash_unchanged, JSON.stringify(result.phtml_artifacts)).toBeTruthy();
      expect(result.phtml_mtime_unchanged, JSON.stringify(result.phtml_artifacts)).toBeTruthy();
      expect(result.phtml_inode_unchanged, JSON.stringify(result.phtml_artifacts)).toBeTruthy();

      // 配置确实变了，且新旧配置各在各自目录（不混）。
      expect(result.config_changed, JSON.stringify(result)).toBeTruthy();
      expect(result.config_paths_distinct, JSON.stringify(result)).toBeTruthy();
      expect(result.old_binding_keeps_old_config, JSON.stringify(result)).toBeTruthy();
      expect(result.new_binding_keeps_new_config, JSON.stringify(result)).toBeTruthy();
      expect(result.no_mixed_config, JSON.stringify(result)).toBeTruthy();

      // 旧请求绑定仍能完成读取。
      expect(result.binding_reread_ok, JSON.stringify(result)).toBeTruthy();
    } finally {
      scope.cleanup();
      runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
    }
  });

  moduleCase(test, { module: MODULE, id: 'UC-12' }, '注入扇出：只命中目标 layout 的正式+历史草稿 owner，不相关 layout 不进入', async () => {
    const activeTheme = getActiveTheme('frontend');
    const themeId = Number(activeTheme?.id || activeTheme?.theme_id || 0);
    expect(themeId).toBeGreaterThan(0);

    // 必须用该主题下真实存在 workspace 的 layout（合成 page_type 不建 workspace，会空转）。
    const result = runPhpFixture(ISOLATION_FIXTURE, 'injection_fanout', {
      theme_id: themeId,
      page_type: 'homepage',
      unrelated_page_type: 'best_sellers',
    });
    expectOk(result, 'injection_fanout');

    // 选择精度：「不相关布局不重烘」的机制本身。
    expect(result.selection_precise, JSON.stringify(result.affects)).toBeTruthy();
    expect(result.affects?.target_matches_target).toBe(true);
    expect(result.affects?.target_excludes_unrelated).toBe(true);
    expect(result.affects?.empty_change_affects_all).toBe(true);
    expect(result.affects?.backend_change_skipped).toBe(true);

    // 非空转：目标布局必须能枚举到真实 owner（423 个正式/草稿 owner）。
    expect(result.target_fanout_non_empty, JSON.stringify(result.target_fanout)).toBeTruthy();
    expect(Number(result.target_fanout?.target_count || 0)).toBeGreaterThan(0);

    // 只命中目标 layout，排除不相关。
    expect(result.target_fanout_types_are_target_only, JSON.stringify(result.target_fanout)).toBeTruthy();
    expect(result.target_fanout_excludes_unrelated, JSON.stringify(result.target_fanout)).toBeTruthy();
    expect(result.unrelated_fanout_excludes_target, JSON.stringify(result.unrelated_fanout)).toBeTruthy();

    // 同一 layout 下的正式与历史草稿 owner 均在覆盖内。
    expect(result.target_covers_published_or_draft, JSON.stringify(result.target_fanout)).toBeTruthy();

    // chrome 槽不受 layout_type 限定，波及面不小于单布局。
    expect(result.chrome_is_layout_agnostic, JSON.stringify(result)).toBeTruthy();

    // 真实重烘：只处理 version_resolved 的目标。
    expect(result.report_wellformed, JSON.stringify(result.report)).toBeTruthy();
    expect(result.migrated_matches_resolved_fanout, JSON.stringify({
      migrated: result.migrated,
      fanout: result.target_fanout,
    })).toBeTruthy();
  });

  // UC-09 仍为「部分可用」：本用例覆盖「卸载决定记录/读回、按目标版本隔离」，
  // 以及原缺口 #5「生产目标版本解析落到陈旧版本」的修复验证。
  // 修复前：resolveTargetThemeVersionId 读 ThemeScopeVersion 的 is_current/is_published 标志，
  // 而 v3 发布只维护 selection 表 → 纯 v3 owner 上返回 0、根本不落库。
  // 修复后：getCurrent/getPublished 以 selection 为权威（flags 仅作旧数据回落），
  // 独立 scope 上 resolved_target_version_id === v1_version_id。
  // 本用例只覆盖「决定记录/读回 + 按版本隔离 + 目标版本解析」这一层；
  // 「删除后刷新/续编/继承/单页发布均不复活」「恢复默认后重新出现」「无目标决定时 required 入槽」
  // 由下面的 UC-09（完整通路，`required_default_no_resurrect`）单独覆盖。
  moduleCase(test, { module: MODULE, id: 'UC-09-partial' }, '必装/删除（部分）：卸载决定按版本记录并隔离；目标版本解析已落到实际发布的版本', async ({}, testInfo) => {
    const activeTheme = getActiveTheme('frontend');
    const themeId = Number(activeTheme?.id || activeTheme?.theme_id || 0);
    expect(themeId).toBeGreaterThan(0);
    const pageType = makePageType(testInfo);
    const scope = prepareDedicatedScope(themeId, pageType, testInfo);

    runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
    try {
      const result = runPhpFixture(ISOLATION_FIXTURE, 'required_default_uninstall', {
        theme_id: themeId,
        page_type: pageType,
        marker: `UC09 ${pageType}`,
        scope: scope.scopes.store,
        store_mode: 'normal',
      });
      expectOk(result, 'required_default_uninstall');
      expect(Number(result.v1_version_id || 0)).toBeGreaterThan(0);
      expect(Number(result.v2_version_id || 0)).toBeGreaterThan(0);
      expect(result.v2_publish_ok, JSON.stringify(result)).toBeTruthy();

      // 已实现：资源身份哈希为稳定 sha256。
      expect(result.chrome_hash_is_sha256, JSON.stringify(result)).toBeTruthy();

      // 已实现：卸载决定的记录 → 读回往返正确。
      expect(result.omission_roundtrip, JSON.stringify(result.mine)).toBeTruthy();
      expect(result.mine?.slot_id).toBe('footer-help-links');
      expect(result.mine?.widget_module).toBe('Weline_Theme');
      expect(result.mine?.widget_code).toBe('basic/button');

      // 已实现：决定严格按目标主题版本隔离（本版本卸载不得影响其它版本）。
      expect(result.decision_isolated_per_version, JSON.stringify(result)).toBeTruthy();
      expect(result.other_version_has_omission).toBe(false);

      // 缺口 #5 修复验证（真实通路）：生产目标版本解析必须落到本 owner 实际发布的版本，
      // 而不是陈旧 flags 值、更不是 0（0 会让卸载决定无处可落）。
      expect(Number(result.resolved_target_version_id || 0), JSON.stringify({
        resolved: result.resolved_target_version_id,
        v1: result.v1_version_id,
      })).toBe(Number(result.v1_version_id));
      expect(result.resolve_targets_v1, JSON.stringify(result)).toBe(true);
    } finally {
      scope.cleanup();
      runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
    }
  });

  /**
   * UC-09 完整通路（真实通路复验，替代旧「部分可用」结论）。
   *
   * 观测点全部是真实产物与真实 DB 行，不用任何自造返回值自证：
   *  - 版本 chrome 侧车：chrome/configs/vV/<artifact_key>/config.json（每个 key 一条节点记录）；
   *  - 版本行载荷：w_theme_scope_version.chrome_payload_json；
   *  - 卸载决定：w_theme_scope_version_widget_decision（经真实服务读回）。
   *
   * 真实声明取自 `Weline_Customer` 部件模板的 `default_injections`：
   * `footer-my-account-link` → 槽 `footer-help-links`（required=true）。
   * 注意必装节点 uid 由固化端按 **`homepage`** 派生（`bakeChromeFromNodes()` 对必装合并固定传
   * `'homepage'`，见 ThemeLayoutEntityBakeCoordinator），所以断言里的 uid 也按 homepage 派生。
   */
  moduleCase(
    test,
    { module: MODULE, id: 'UC-09' },
    '必装/删除（完整）：删除后刷新/续编/继承/单页发布均不复活，恢复默认后重新出现，源版本不受影响',
    async ({}, testInfo) => {
      const activeTheme = getActiveTheme('frontend');
      const themeId = Number(activeTheme?.id || activeTheme?.theme_id || 0);
      expect(themeId).toBeGreaterThan(0);
      const pageType = makePageType(testInfo);
      const scope = prepareDedicatedScope(themeId, pageType, testInfo);

      runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
      try {
        const result = runPhpFixture(ISOLATION_FIXTURE, 'required_default_no_resurrect', {
          theme_id: themeId,
          page_type: pageType,
          marker: `UC09R ${pageType}`,
          scope: scope.scopes.store,
          store_mode: 'normal',
        });
        expectOk(result, 'required_default_no_resurrect');

        // 断言非空转：每一步读数都来自真实存在且非空的 chrome 侧车文件集合。
        expect(result.disk_reads_non_empty, JSON.stringify(result)).toBe(true);
        expect(Number(result.v1_node_count || 0), JSON.stringify(result)).toBeGreaterThan(0);

        // ① 无目标决定时 required 入槽。
        expect(
          result.required_present_before_delete,
          JSON.stringify(result.required_node_before_delete),
        ).toBe(true);
        expect(result.required_node_before_delete?.source).toBe('default_injection');
        expect(result.required_node_before_delete?.is_active).toBe(true);

        // ② 真实编辑器删除通路：卸载决定按目标版本落库。
        expect(result.decision_recorded, JSON.stringify(result.remove_decision_row)).toBe(true);
        expect(Number(result.remove_version_id || 0)).toBeGreaterThan(0);
        expect(Number(result.node_count_after_delete || 0), JSON.stringify(result)).toBeGreaterThan(0);

        // ③ 刷新不复活：同版本重烘后节点仍停用，且带 user_deleted@{version} 标记。
        expect(result.required_absent_after_refresh, JSON.stringify(result.required_node_after_refresh)).toBe(true);
        expect(result.required_node_after_refresh?.is_active).toBe(false);
        expect(String(result.required_node_after_refresh?.source || '')).toMatch(/^user_deleted@\d+$/);

        // ④ 续编不复活（真实修订通路把 chrome 载荷整份带到新修订，删除标记随行）。
        expect(Number(result.continue_node_count || 0), JSON.stringify(result)).toBeGreaterThan(0);
        expect(result.continue_required_absent, JSON.stringify(result.continue_node_after_refresh)).toBe(true);

        // ⑤ 单页发布（publish_set 非 all）不复活。
        expect(result.single_page_publish_ok, JSON.stringify(result)).toBe(true);
        expect(Number(result.single_page_node_count || 0), JSON.stringify(result)).toBeGreaterThan(0);
        expect(result.single_page_required_absent, JSON.stringify(result)).toBe(true);

        // ⑥ 显式历史继承不复活：源版本真实载荷写回新版本并重烘后仍不复活。
        expect(Number(result.inherit_source_node_count || 0), JSON.stringify(result)).toBeGreaterThan(0);
        expect(Number(result.inherit_node_count || 0), JSON.stringify(result)).toBeGreaterThan(0);
        expect(result.inherit_required_absent, JSON.stringify(result.inherit_required_node)).toBe(true);

        // 源版本不受影响（删除只落在目标版本）。
        expect(result.published_base_unaffected_by_delete, JSON.stringify(result)).toBe(true);
        expect(result.inherit_source_unchanged, JSON.stringify(result)).toBe(true);

        // ⑦ 恢复默认后目标项重新出现（回到 default_injection / is_active=true）。
        expect(result.restore_ok, JSON.stringify(result)).toBe(true);
        expect(Number(result.restore_node_count || 0), JSON.stringify(result)).toBeGreaterThan(0);
        expect(result.restore_required_present, JSON.stringify(result.restore_required_node)).toBe(true);
        expect(result.restore_required_node?.source).toBe('default_injection');
        expect(result.restore_required_node?.is_active).toBe(true);
      } finally {
        scope.cleanup();
        runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
      }
    },
  );

  /**
   * UC-03 单页发布（真实控制器通路）。
   *
   * 与 UC-09 的 S4 差别：那里 draft_resources 只有 `['chrome']`，未选中集合为空 ⇒
   * 根本不需要 D'，证明不了 D' 分配通路。本用例刻意让未选中集合非空（四项资源只发布 page），
   * 才能真正打到 `single_page_publish_requires_draft_prime_id` 那条分支。
   */
  moduleCase(
    test,
    { module: MODULE, id: 'UC-03' },
    '单页发布：只发布当前页，D\' 被真实分配并承接 chrome 载荷与卸载决定',
    async ({}, testInfo) => {
      const activeTheme = getActiveTheme('frontend');
      const themeId = Number(activeTheme?.id || activeTheme?.theme_id || 0);
      expect(themeId).toBeGreaterThan(0);
      const pageType = makePageType(testInfo);
      const scope = prepareDedicatedScope(themeId, pageType, testInfo);

      runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
      try {
        const result = runPhpFixture(ISOLATION_FIXTURE, 'single_page_publish_remainder', {
          theme_id: themeId,
          page_type: pageType,
          marker: `UC03 ${pageType}`,
          scope: scope.scopes.store,
          store_mode: 'normal',
        });
        expectOk(result, 'single_page_publish_remainder');

        // 断言非空转：基线与删除后的节点读数都必须来自真实存在且非空的 chrome 侧车。
        expect(Number(result.p0_node_count || 0), JSON.stringify(result)).toBeGreaterThan(0);
        expect(result.p0_required_present, JSON.stringify(result)).toBe(true);
        expect(Number(result.d_node_count_after_remove || 0), JSON.stringify(result)).toBeGreaterThan(0);

        // 缺陷 B 已修：新分配的版本行不再是不带 chrome 载荷的空壳行。
        expect(Number(result.d_carried_chrome_nodes || 0), JSON.stringify(result)).toBeGreaterThan(0);
        expect(Number(result.d_chrome_count_after_create || 0), JSON.stringify(result)).toBeGreaterThan(0);

        // 真实卸载决定落在 D 上（证明后面的 D' 承接的是「有内容的」chrome，而非空壳）。
        expect(result.d_decision_recorded, JSON.stringify(result.d_decision_row)).toBe(true);
        expect(result.d_required_absent, JSON.stringify(result)).toBe(true);

        // ① 单页发布成功：不再抛 single_page_publish_requires_draft_prime_id。
        expect(result.publish_ok, JSON.stringify(result)).toBe(true);
        expect(String(result.publish_code || '')).not.toContain('draft_prime');

        // ② 资源切分正确：只发布 page，其余三项留在草稿。
        expect(result.published_resources, JSON.stringify(result)).toEqual([result.page_resource]);
        expect(result.remaining_draft_resources, JSON.stringify(result)).toEqual([
          'chrome',
          'appearance',
          'theme_binding',
        ]);

        // ③ D' 被真实分配，且 selection 的草稿指针指向它。
        expect(result.d_prime_allocated, JSON.stringify(result)).toBe(true);
        expect(result.selection_points_to_d_prime, JSON.stringify(result)).toBe(true);
        expect(Number(result.d_prime_version_id || 0)).not.toBe(Number(result.d_version_id || 0));

        // ④ D' 承接 chrome 载荷与卸载决定（「D' 保留 chrome 及决定」）。
        //
        // 口径（实测得出，勿收紧）：平台对**任何**新分配的草稿都只在「首次真实写入」
        // 时才烘焙磁盘产物 —— 刚创建 D 时 `d_node_count_after_create=0`，而 D 上做过一次
        // 真实删除后 `d_node_count_after_remove=22`；D' 同理。故此处**不**要求 D' 立即
        // 有磁盘侧车（那会要求 D' 比常规新草稿更"熟"，属凭空加码）；要求的是
        // 「载荷与决定确实随行」＋「磁盘态不劣于新草稿基线」。
        expect(Number(result.d_prime_chrome_count || 0), JSON.stringify(result)).toBeGreaterThan(0);
        expect(result.d_prime_decision_carried, JSON.stringify(result.d_prime_decision_row)).toBe(true);
        expect(
          Number(result.d_prime_node_count || 0),
          JSON.stringify(result),
        ).toBe(Number(result.d_node_count_after_create || 0));
        // 注：不再断言 `d_prime_required_absent` —— 当 D' 尚无磁盘侧车时节点集合为空，
        // `!activeOf([])` 恒为 true，属**空转断言**（观测值仍保留在 evidence 里备查）。

        // ⑤ N = D：发布仍是原地封存，发布指针指向刚发布的那份。
        expect(result.n_equals_d, JSON.stringify(result)).toBe(true);
      } finally {
        scope.cleanup();
        runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
      }
    },
  );

  moduleCase(test, { module: MODULE, id: 'UC-14' }, '清理：离线 GC 只删孤儿，全部可达版本保留', async ({}, testInfo) => {
    const activeTheme = getActiveTheme('frontend');
    const themeId = Number(activeTheme?.id || activeTheme?.theme_id || 0);
    expect(themeId).toBeGreaterThan(0);
    const pageType = makePageType(testInfo);
    // 孤儿目录必须建在独立测试 scope 下（不往线上 owner 的产物树写测试垃圾）。
    const scope = prepareDedicatedScope(themeId, pageType, testInfo);

    try {
      const result = runPhpFixture(ISOLATION_FIXTURE, 'gc_orphan', {
        theme_id: themeId,
        page_type: pageType,
        scope: scope.scopes.store,
        store_mode: 'normal',
      });
      expectOk(result, 'gc_orphan');
      expect(result.orphan_removed, JSON.stringify(result)).toBeTruthy();
      expect(Number(result.reachable_count || 0)).toBeGreaterThan(0);

      // 断言非空转：必须存在「可达且已落盘」的版本目录，否则「不误删」无从谈起。
      expect(Number(result.reachable_dir_existing || 0), JSON.stringify(result)).toBeGreaterThan(0);

      // 核心不变量：全树 GC 不得删除任何可达版本目录。
      // （reachable 必须覆盖全部 selection 行 —— 只保护单个 owner 会误删
      //   website/channel 等其它 owner 的可达产物。）
      expect(result.reachable_dir_deleted, JSON.stringify(result)).toEqual([]);
      expect(result.published_not_deleted, JSON.stringify(result)).toBeTruthy();

      const tree = runPhpFixture(ISOLATION_FIXTURE, 'inspect_tree', { theme_id: themeId });
      expectOk(tree, 'inspect_tree after gc');
      expect(tree.legacy_count, JSON.stringify(tree.legacy_sample)).toBe(0);
    } finally {
      scope.cleanup();
    }
  });

  // 缺口 #3：主题绑定必须留在版本外（theme↔version 无循环）。
  // 修复前：assertThemeBindingHasNoVersionCycle 生产调用数 = 0（死守卫），
  // 且模型层 save_before() 把绑定行上的版本号静默改写成 0 —— 调用方 bug 被掩盖。
  // 修复后：模型层直接拒绝；服务层 apply() 前置守卫在存量漂移时拒绝绑定补丁。
  // 注意：绑定上下文只认 website scope（store scope 解析不到 system_config 站点范围）。
  moduleCase(test, { module: MODULE, id: 'GAP-03' }, '绑定无版本循环：模型层拒绝 + 服务层守卫拒绝漂移绑定', async ({}, testInfo) => {
    const activeTheme = getActiveTheme('frontend');
    const themeId = Number(activeTheme?.id || activeTheme?.theme_id || 0);
    expect(themeId).toBeGreaterThan(0);
    const pageType = makePageType(testInfo);
    const scope = prepareDedicatedScope(themeId, pageType, testInfo);

    try {
      const result = runPhpFixture(ISOLATION_FIXTURE, 'binding_version_guard', {
        theme_id: themeId,
        page_type: pageType,
        scope: scope.scopes.website,
        store_mode: 'normal',
      });
      expectOk(result, 'binding_version_guard');

      // 真实 DB 行：合法写入的绑定必须停在版本外，且身份键就是裸身份哈希（不含 v: 前缀）。
      expect(Number(result.persisted_version_id), JSON.stringify(result)).toBe(0);
      expect(result.binding_key_has_no_version_prefix, JSON.stringify(result)).toBe(true);

      // 模型层：绑定行带真实版本号必须被拒绝（修复前是静默改写为 0）。
      expect(result.model_rejected_binding_with_version, JSON.stringify(result)).toBe(true);
      expect(String(result.model_error)).toContain('theme_binding_must_not_own_theme_version');

      // 健康对照：未漂移时守卫不得触发 —— 证明上面的拒绝不是「总是拒绝」。
      expect(result.healthy_guard_fired, JSON.stringify(result)).toBe(false);

      // 服务层：漂移确实写进了库（防空转），真实 apply() 必须按守卫消息拒绝。
      expect(Number(result.drift_rows_affected), JSON.stringify(result)).toBeGreaterThan(0);
      expect(Number(result.drift_version_id_after_write), JSON.stringify(result)).toBe(42);
      expect(result.service_rejected_drifted_binding, JSON.stringify(result)).toBe(true);
      expect(String(result.service_error)).toContain('theme_binding_must_not_own_theme_version');
    } finally {
      scope.cleanup();
      runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
    }
  });

  // 缺口 #2（UC-05 发布缺烘焙）：修复前 publishScopeVersionPayload 只翻 selection 指针，
  // 从不物化产物，于是 selection 指向 tvN/formal 而该目录并不存在（published_dir_exists=false）。
  // 缺口 #4：ThemeScopeVersionResourceSnapshot 从未写入过任何一行。
  // 修复后：封存 → 烘焙 → 写快照 → 最后才翻转指针；指针可见即代表 formal 产物已在磁盘上。
  // 本用例全部证据都从磁盘/DB 直读，不复用发布返回值自证。
  moduleCase(
    test,
    { module: MODULE, id: 'GAP-02-04' },
    '发布先烘焙：formal 产物发布前不存在、发布后落盘且指纹与磁盘一致；资源快照按版本落库',
    async ({}, testInfo) => {
      const activeTheme = getActiveTheme('frontend');
      const themeId = Number(activeTheme?.id || activeTheme?.theme_id || 0);
      expect(themeId).toBeGreaterThan(0);
      const pageType = makePageType(testInfo);
      const scope = prepareDedicatedScope(themeId, pageType, testInfo);
      const marker = `ISO BAKE ${Date.now()}`;

      runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
      try {
        const flow = runPhpFixture(ISOLATION_FIXTURE, 'publish_flow', {
          theme_id: themeId,
          page_type: pageType,
          marker,
          scope: scope.scopes.store,
          store_mode: 'normal',
          mismatch_scope: scope.scopes.channel,
        });
        expectOk(flow, 'publish_flow (bake evidence)');

        // 防空转：写部件必须真的成功，节点数必须 > 0，否则后面的「产物存在」毫无意义。
        expect(flow.save_widget?.success, JSON.stringify(flow.save_widget)).toBe(true);
        expect(Number(flow.node_count), JSON.stringify(flow.publish?.data?.artifacts)).toBeGreaterThan(0);

        // 缺口 #2 核心：发布前 formal 目录不存在，发布后存在 —— 产物确实是发布这一步烘焙出来的。
        expect(flow.formal_dir_existed_before_publish, JSON.stringify(flow)).toBe(false);
        expect(flow.published_dir_exists, JSON.stringify(flow)).toBe(true);
        expect(String(flow.published_dir || '')).toMatch(/\/tv\d+\/formal(?:\/|$)/);
        expect(String(flow.published_dir || '')).not.toMatch(/\/draft(?:\/|$)/);

        // 产物落盘且指纹自洽：返回值里的 artifact_id 必须等于磁盘真实 sha256。
        expect(flow.page_path_exists, JSON.stringify(flow.page_path)).toBe(true);
        expect(
          flow.artifact_matches_disk,
          JSON.stringify({ reported: flow.page_artifact_id, on_disk: flow.on_disk_sha256 }),
        ).toBe(true);

        // formal 树里必须是该版本自己的内容（不是空壳）：标记出现在产物里，绑定也已落盘。
        expect(flow.marker_in_formal_tree, JSON.stringify(flow.marker_files)).toBe(true);
        expect((flow.marker_files || []).length).toBeGreaterThan(0);
        expect((flow.binding_files || []).length).toBeGreaterThan(0);

        // 烘焙 fail-closed：错 owner 上下文必须按身份错误抛出，发布路径据此在翻转指针前中止。
        expect(String(flow.bake_mismatch_error)).toContain('theme_layout_entity_publish_version_mismatch');

        // 缺口 #4：资源快照按版本落库，且 theme_binding 按设计留在版本外、不写。
        expect(Number(flow.snapshot_count), JSON.stringify(flow.snapshot_rows)).toBeGreaterThan(0);
        const snapshotTypes = flow.snapshot_types || [];
        expect(snapshotTypes).toContain('layout');
        expect(snapshotTypes).not.toContain('theme_binding');
        for (const row of flow.snapshot_rows || []) {
          expect(Number(row.theme_version_id)).toBe(Number(flow.published_version_id));
          expect(Number(row.content_revision)).toBeGreaterThan(0);
          expect(String(row.resource_identity_hash || '')).toMatch(/^[a-f0-9]{64}$/);
        }
        // 至少一行快照记录的就是磁盘上那个页面产物（快照不是空壳）。
        expect(flow.snapshot_records_page_artifact, JSON.stringify(flow.snapshot_fingerprints)).toBe(true);
      } finally {
        scope.cleanup();
        runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
      }
    },
  );

  // UC-11 源升级：变更源模板（删旧默认节点）→ setup:upgrade 清盘 → 首访从
  // 「当前源模板 + 该版本持久用户意图」重建。
  //
  // 证据全部来自真实磁盘/DB/HTTP：页面结构键从产物目录名直读（不复用发布返回值自证），
  // 用户覆盖标记从产物文件内容直读。源模板是本用例自建、结束时删除的探针 layout，
  // 不触碰 design 主题既有模板。
  //
  // 本用例会跑真实的清盘观察者（删全站派生磁盘产物，这是 setup:upgrade 的既定语义），
  // 故置于末尾，并在最后用真实 HTTP 首访确认店面仍可重建。
  moduleCase(
    test,
    { module: MODULE, id: 'UC-11' },
    '源升级：源模板变更被重烘采纳（页面结构键变、chrome 键不变）、清盘后 DB 保留、首访重建且用户覆盖保留、失效操作有诊断',
    async ({ page }, testInfo) => {
      const activeTheme = getActiveTheme('frontend');
      const themeId = Number(activeTheme?.id || activeTheme?.theme_id || 0);
      expect(themeId).toBeGreaterThan(0);
      const pageType = makePageType(testInfo);
      const probeLayoutType = `${pageType}_src`;
      const scope = prepareDedicatedScope(themeId, pageType, testInfo);

      runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
      try {
        const result = runPhpFixture(ISOLATION_FIXTURE, 'source_upgrade_probe', {
          theme_id: themeId,
          probe_layout_type: probeLayoutType,
          page_type: probeLayoutType,
          scope: scope.scopes.store,
          store_mode: 'normal',
        });
        expectOk(result, 'source_upgrade_probe');

        // 防空转：发布前必须先有产物，且「用户覆盖」真的写进了产物；重建用的是 DB 里的持久意图。
        expect(result.baked_before_purge, JSON.stringify(result)).toBe(true);
        expect(Number(result.artifact_count_v1 || 0), JSON.stringify(result)).toBeGreaterThan(0);
        expect(result.user_override_in_artifact_before_purge, JSON.stringify(result)).toBe(true);
        expect(String(result.rebuild_nodes_source), JSON.stringify(result)).toBe('db_version_payload');
        expect(Number(result.db_payload_node_count || 0), JSON.stringify(result)).toBeGreaterThan(0);

        // 源模板确实变了。
        expect(
          result.source_hash_changed,
          JSON.stringify({ v1: result.source_sha_v1, v2: result.source_sha_v2 }),
        ).toBe(true);

        // 核心 1：源指纹被采纳 —— 页面结构键必须变；chrome 结构键必须**不变**，
        // 后者排除「任何重烘都会换键」，把变化唯一归因到源模板。
        expect(String(result.structure_key_v1 || '')).toMatch(/^[a-f0-9]{64}$/);
        expect(String(result.structure_key_v2 || '')).toMatch(/^[a-f0-9]{64}$/);
        expect(result.structure_key_v1).not.toBe(result.structure_key_v2);
        expect(String(result.chrome_structure_key_v1 || '')).toMatch(/^[a-f0-9]{64}$/);
        expect(result.chrome_structure_key_v1).toBe(result.chrome_structure_key_v2);
        expect(result.uc11_source_fingerprint_adopted, JSON.stringify(result)).toBe(true);

        // 核心 2：清盘删的是派生磁盘产物 —— 磁盘归零、DB 原样保留。
        expect(Number(result.artifact_count_after_purge)).toBe(0);
        expect(result.version_formal_dir_gone_after_purge, JSON.stringify(result)).toBe(true);
        // 观察者确实跑到了打印进度那一步（证明清盘走的是真实观察者，不是夹具手工删目录）。
        expect(String(result.purge_output || '')).toContain('theme-layout-entities');
        expect(String(result.purge_output || '')).toContain('删除节点');
        expect(result.selection_intact_after_purge, JSON.stringify(result)).toBe(true);
        expect(result.version_row_survives_purge, JSON.stringify(result)).toBe(true);
        expect(result.db_payload_intact_after_purge, JSON.stringify(result)).toBe(true);
        expect(result.uc11_db_survived_purge, JSON.stringify(result)).toBe(true);

        // 核心 3：首访重建走新源模板，且用户覆盖保留。
        expect(result.rebuilt_exists, JSON.stringify(result.rebuilt_path)).toBe(true);
        expect(String(result.rebuilt_path || '')).toContain(String(result.structure_key_v2));
        expect(result.uc11_first_visit_uses_new_source, JSON.stringify(result)).toBe(true);
        expect(result.user_override_present_after_rebuild, JSON.stringify(result)).toBe(true);
        expect(result.uc11_user_override_preserved, JSON.stringify(result)).toBe(true);

        // 核心 4：失效操作有诊断（首访入口非法输入 fail-closed；越界 purge 根被拒）。
        expect(result.uc11_invalid_ops_diagnosed, JSON.stringify(result)).toBe(true);
        expect(String(result.out_of_root_purge_error || '')).toContain('theme_layout_entity_purge_basename_mismatch');
        expect(result.in_root_purge_accepted, JSON.stringify(result)).toBe(true);

        expect(result.success, JSON.stringify(result)).toBe(true);

        // 真实 HTTP 首访：全站清盘后店面必须能自行重建（首访重建产物），状态 200 且外壳完整。
        const homeResponse = await page.request.get('/');
        expect(homeResponse.status(), 'homepage status after global purge').toBe(200);
        const homeBody = await homeResponse.text();
        expect(homeBody.length).toBeGreaterThan(10000);
        expect(homeBody).toContain('</footer>');

        await gotoFrontend(page, '/');
        const html = await page.content();
        expect(html).toContain('</footer>');
        expect(html.length).toBeGreaterThan(10000);
      } finally {
        scope.cleanup();
        runPhpFixture(EDITOR_FIXTURE, 'cleanup', { theme_id: themeId, page_type: pageType });
      }
    },
  );
});
