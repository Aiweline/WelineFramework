/**
 * 真实后台覆盖、Scope、收集、队列失败/重试及源站请求。
 * 这些本机 case 只覆盖标题中的分支；edge、双域、STALE与用户变体另以真实证据签收。
 * @weline-e2e-spec { module: Weline_Cdn, type: plan, layer: backend }
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync, spawnSync } = require('child_process');
const {
  test, expect, moduleDescribe, moduleCase, loginAsAdmin, gotoBackend,
  getRuntimeInfo, waitForBackendShellReady,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Cdn';
const ROOT = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.join(__dirname, 'cdn-fpc-policy-sync-fixture.php');
const ROUTE = 'cdn/backend/api-rules/index';
const FATAL = /WLS Runtime Error|ParseError|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const CACHE_HEADERS = ['cache-control', 'cdn-cache-control', 'cloudflare-cdn-cache-control', 'x-weline-fpc', 'cf-cache-status', 'age', 'content-type', 'date'];

function fixture(run, action, payload = {}) {
  const output = execFileSync('php', [FIXTURE], {
    cwd: ROOT, encoding: 'utf8', input: JSON.stringify({ action, token: run.token, ...payload }),
    timeout: 30000, maxBuffer: 4 * 1024 * 1024,
  });
  const line = output.trim().split(/\r?\n/).filter(value => value.startsWith('{')).pop();
  const result = JSON.parse(line || '{}');
  if (!result.ok) throw new Error(`真实 fixture ${action}失败：${output}`);
  return result;
}

function saveEvidence(run, name, data) {
  fs.writeFileSync(path.join(run.directory, `${name}.json`), JSON.stringify(data, null, 2));
}

function beginRun(testInfo) {
  const token = `fpc-sync-${Date.now()}-${crypto.randomBytes(4).toString('hex')}`;
  const run = { token, directory: path.join(ROOT, 'dev/team/cdn-fpc-policy-sync/evidence', token), testInfo };
  run.baseline = fixture(run, 'prepare');
  saveEvidence(run, 'before', run.baseline);
  return run;
}

async function disableCache(page) {
  await page.context().addInitScript(() => {
    Object.defineProperty(navigator, 'webdriver', { get: () => undefined, configurable: true });
  });
  const cdp = await page.context().newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
}

async function ready(page) {
  await waitForBackendShellReady(page);
  await expect(page.locator('[data-testid="cdn-api-rules-management"]')).toBeVisible();
  await expect(page.locator('body')).not.toContainText(FATAL);
  await page.waitForFunction(() => !!window.Weline?.Api?.resource || !!window.Weline?.load);
  await page.evaluate(async () => { if (!window.Weline?.Api?.resource) await window.Weline.load('api'); });
  await expect(page.locator('[data-cdn-table="fpc"]')).not.toHaveAttribute('aria-busy', 'true');
}

async function callCdnRead(page, operation, params) {
  const result = await page.evaluate(async ({ operation, params }) => {
    const service = window.Weline.Api?.resource ? window.Weline.Api : await window.Weline.load('api');
    return (await service.resource('cdn'))[operation](params);
  }, { operation, params });
  expect(result.success, `${operation}真实返回：${JSON.stringify(result)}`).toBe(true);
  return result.data;
}

async function openManagement(page) {
  console.log('[FPC acceptance] 真实后台登录开始');
  await loginAsAdmin(page, { useProxy: false, timeout: 90000, settleMs: 500 });
  console.log('[FPC acceptance] 登录后实际URL', page.url());
  await gotoBackend(page, `${ROUTE}?tab=fpc&keyword=Currency`, { useProxy: false, timeout: 60000, settleMs: 500 });
  await ready(page);
  const assistantClose = page.locator('[data-ssa-close]');
  if (await assistantClose.isVisible()) await assistantClose.click();
  console.log('[FPC acceptance] 真实管理页已就绪');
  console.log('[FPC acceptance] 实际FPC资源', await page.evaluate(() => performance.getEntriesByType('resource').map(row => row.name).filter(name => name.includes('fpc-policy-management'))));
  await page.screenshot({ path: path.join(ROOT, 'dev/team/cdn-fpc-policy-sync/evidence/management-current.png'), fullPage: true });
  console.log('[FPC acceptance] 管理页控件状态', await page.locator('#cdn-fpc-keyword').isVisible(), await page.locator('#cdn-fpc-scope').inputValue());
  await page.locator('#cdn-fpc-keyword').fill('Currency');
  await page.locator('[data-cdn-action="query"]').click();
  await expect(page.locator('[data-cdn-table="fpc"]')).not.toHaveAttribute('aria-busy', 'true');
  console.log('[FPC acceptance] Currency查询完成');
}

async function selectCell(page, run, key) {
  const [name, mode] = key.split(':');
  const target = run.baseline.scopes[name].target_scope;
  console.log('[FPC acceptance] 选择真实Scope', key, target);
  if (await page.locator('#cdn-fpc-scope').inputValue() !== target) {
    await page.locator('#cdn-fpc-scope_trigger').click();
    const node = page.locator('#cdn-fpc-scope_tree [data-w-scope-node]').filter({ has: page.locator(`[data-w-scope-option]`) });
    const option = page.locator(`#cdn-fpc-scope_tree [data-w-scope-node][data-value="${target}"]`).first();
    const label = await option.getAttribute('data-title-label');
    await option.locator(':scope > [data-w-scope-option]').click();
    await expect(page.locator('#cdn-fpc-scope')).toHaveValue(target);
    if (label) await expect(page.locator('#cdn-fpc-scope_container')).toHaveAttribute('title', label);
    // 保留实际目录节点定位，防止把另一个Scope的标签当已选范围。
    expect(await node.count()).toBeGreaterThan(1);
  }
  if (await page.locator('#cdn-fpc-mode_value').inputValue() !== mode) {
    await page.locator('#cdn-fpc-mode_input').click();
    await page.locator(`#cdn-fpc-mode_list [data-value="${mode}"]`).click();
    await expect(page.locator('#cdn-fpc-mode_value')).toHaveValue(mode);
  }
  await page.locator('[data-cdn-action="query"]').click();
  await expect(page.locator(`[data-cdn-rows="fpc"] tr[data-declaration-id="${run.baseline.declaration_id}"]`)).toBeVisible();
  await expect(page.locator('[data-cdn-table="fpc"]')).not.toHaveAttribute('aria-busy', 'true');
  await expect(page.locator('#cdn-fpc-scope')).toHaveValue(target);
  await expect(page.locator('#cdn-fpc-mode_value')).toHaveValue(mode);
  // 首次默认Global/normal可沿默认上下文；发生选择变化才会写URL参数。
  const params = new URL(page.url()).searchParams;
  if (params.has('target_scope') || target !== run.baseline.scopes.global.target_scope) expect(params.get('target_scope')).toBe(target);
  if (params.has('store_mode') || mode !== 'normal') expect(params.get('store_mode')).toBe(mode);
}

function pair(row) { return { enabled: row.override_enabled, ttl: row.override_ttl }; }
function pendingTargets(job) { return Object.values(job.pending_targets || {}); }

async function openEditor(page, run) {
  await page.locator(`[data-cdn-action="edit"][data-declaration-id="${run.baseline.declaration_id}"]`).click();
  await expect(page.locator('#cdn-fpc-editor')).toBeVisible();
}

async function saveUi(page, run, cell, patch) {
  await selectCell(page, run, cell);
  const before = fixture(run, 'observe').rows[cell];
  const expected = { ...pair(before), ...patch };
  if (JSON.stringify(expected) === JSON.stringify(pair(before))) throw new Error(`UI案例输入没有真实变化：${cell}`);
  fixture(run, 'intent', { cell, expected });
  await openEditor(page, run);
  const dialog = page.locator('#cdn-fpc-editor');
  if (Object.hasOwn(patch, 'enabled')) {
    await dialog.locator('[name="inherit_enabled"]').setChecked(patch.enabled === null);
    if (patch.enabled !== null) await dialog.locator('[name="enabled"]').setChecked(patch.enabled);
  }
  if (Object.hasOwn(patch, 'ttl')) {
    await dialog.locator('[name="inherit_ttl"]').setChecked(patch.ttl === null);
    if (patch.ttl !== null) await dialog.locator('[name="ttl"]').fill(String(patch.ttl));
  }
  await dialog.locator('[data-cdn-save]').click();
  await expect(dialog).not.toBeVisible({ timeout: 60000 });
  await expect.poll(() => pair(fixture(run, 'observe').rows[cell]), { timeout: 30000 }).toEqual(expected);
  const real = fixture(run, 'observe');
  saveEvidence(run, `ui-${cell.replace(':', '-')}-${real.desired_version}`, real);
  console.log('[FPC acceptance] UI保存独立PG回查', cell, JSON.stringify(expected), `desired=${real.desired_version}`);
  return real;
}

async function restoreFieldUi(page, run, cell, field, otherDraft) {
  await selectCell(page, run, cell);
  const current = fixture(run, 'observe').rows[cell];
  const expected = { ...pair(current), [field]: null };
  fixture(run, 'intent', { cell, expected });
  await openEditor(page, run);
  const dialog = page.locator('#cdn-fpc-editor');
  if (otherDraft !== undefined) {
    await dialog.locator('[name="inherit_ttl"]').uncheck();
    await dialog.locator('[name="ttl"]').fill(String(otherDraft));
  }
  await dialog.locator(`[data-cdn-action="restore"][data-fields="${field}"]`).click();
  await expect.poll(() => pair(fixture(run, 'observe').rows[cell]), { timeout: 30000 }).toEqual(expected);
  if (otherDraft !== undefined) await expect(dialog.locator('[name="ttl"]')).toHaveValue(String(otherDraft));
  await dialog.locator('[data-cdn-action="cancel"]').first().click();
  return fixture(run, 'observe');
}

function runOwnQueues(run) {
  const observed = fixture(run, 'observe');
  const executions = [];
  for (const row of observed.queues) {
    const content = row.content || {};
    if (row.status !== 'pending' || content.schema_version !== 'cdn-fpc-sync-job.v1'
      || Number(content.requested_version) <= observed.initial_desired_version
      || !observed.jobs.some(job => job.job_key === content.job_key)) continue;
    const result = spawnSync('php', [path.join(ROOT, 'bin/w'), 'queue:run', `--id=${Number(row.queue_id)}`], {
      cwd: ROOT, encoding: 'utf8', timeout: 60000, maxBuffer: 2 * 1024 * 1024,
    });
    executions.push({ queue_id: row.queue_id, requested_version: content.requested_version, exit: result.status, error: result.error?.message || null, stdout: result.stdout, stderr: result.stderr });
  }
  saveEvidence(run, `queue-execution-${Date.now()}`, executions);
  return fixture(run, 'observe');
}

async function waitForRouteLease(run, label) {
  const lock = path.join(ROOT, 'var/process/setup_database_access.lock');
  const deadline = Date.now() + 300000;
  let lastOwners = '';
  while (Date.now() < deadline) {
    const result = spawnSync('lsof', [lock], { encoding: 'utf8', timeout: 5000 });
    if (result.status === 1 && !result.stdout) return;
    if (result.status !== 0) throw new Error(`只读租约检查失败 ${result.stderr || result.error?.message || result.status}`);
    if (result.stdout !== lastOwners) {
      console.log('[FPC acceptance] 等实际租约自然释放', label, result.stdout.trim());
      fs.appendFileSync(path.join(run.directory, 'route-lease-observations.log'), `${new Date().toISOString()} ${label}\n${result.stdout}\n`);
      lastOwners = result.stdout;
    }
    await new Promise(resolve => setTimeout(resolve, 5000));
  }
  throw new Error('实际共享任务租约5分钟未释放；没有停止任务或绕过门禁');
}

async function routeCollect(run, label) {
  await waitForRouteLease(run, label);
  // PM授权的真实生产路由服务链；完整setup的全主题后处理超时单独保留。
  const result = spawnSync('php', [FIXTURE], {
    cwd: ROOT, encoding: 'utf8', input: JSON.stringify({ action: 'collect_route', token: run.token }), timeout: 120000, maxBuffer: 12 * 1024 * 1024,
  });
  fs.writeFileSync(path.join(run.directory, `route-${label}.log`), `${result.stdout || ''}\n${result.stderr || ''}`);
  expect(result.error?.message || null, `正式route collect ${label}`).toBeNull();
  expect(result.status, `正式route collect ${label}`).toBe(0);
  const actual = fixture(run, 'observe');
  saveEvidence(run, `route-service-${label}`, actual);
  console.log('[FPC acceptance] 真实路由Service收集', label, `desired=${actual.desired_version}`, `codeTTL=${actual.rows['website_a:normal'].code_ttl}`, `effective=${actual.rows['website_a:normal'].effective_ttl}`);
  return actual;
}

async function sourceObservations(browser, run, pathname, count = 3) {
  const origin = process.env.FPC_SYNC_LOCAL_ORIGIN || 'https://p05113ef3.test.weline.com:9555';
  const observations = [];
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  try {
    const page = await context.newPage();
    await disableCache(page);
    for (let index = 0; index < count; index++) {
      const response = await page.goto(new URL(pathname, origin).href, { waitUntil: 'domcontentloaded', timeout: 45000 });
      if (!response) throw new Error('真实源站导航没有响应');
      const allHeaders = await response.allHeaders();
      const text = await page.locator('body').innerText();
      observations.push({ url: page.url(), status: response.status(), headers: Object.fromEntries(CACHE_HEADERS.filter(key => allHeaders[key]).map(key => [key, allHeaders[key]])), body_sha256: crypto.createHash('sha256').update(await response.body()).digest('hex'), body_text: text.slice(0, 3000), observed_at: new Date().toISOString() });
      await expect(page.locator('body')).not.toContainText(FATAL);
    }
  } finally { await context.close(); }
  saveEvidence(run, `http-${pathname.replace(/[^a-z0-9]/gi, '-')}-${Date.now()}`, observations);
  return observations;
}

function assertFreshBound(observations, ttl) {
  const hits = observations.filter(row => String(row.headers['x-weline-fpc']).toUpperCase() === 'HIT');
  expect(hits.length, '必须真实形成源站HIT，200不能替代').toBeGreaterThan(0);
  for (const hit of hits) {
    expect(hit.status).toBe(200);
    for (const key of ['cdn-cache-control', 'cloudflare-cdn-cache-control']) {
      expect(hit.headers[key], key).toMatch(/\bpublic\b/i);
      const age = /(?:^|[,\s])max-age=(\d+)/i.exec(hit.headers[key]);
      expect(age, 'HIT必须有有效剩余寿命').not.toBeNull();
      expect(Number(age[1])).toBeGreaterThan(0);
      expect(Number(age[1])).toBeLessThanOrEqual(ttl);
    }
  }
}

async function capturePage(page, response) {
  const headers = response ? await response.allHeaders() : {};
  const config = page.locator('#weline-frontend-runtime-config');
  const runtime = await config.count() ? JSON.parse(await config.textContent()) : null;
  return {
    url: page.url(), status: response?.status(), observed_at: new Date().toISOString(),
    headers: Object.fromEntries(CACHE_HEADERS.filter(key => headers[key]).map(key => [key, headers[key]])),
    body_sha256: crypto.createHash('sha256').update(await page.content()).digest('hex'),
    headings: await page.locator('h1').allTextContents(),
    runtime: runtime ? { locale: runtime.currentLang, currency: runtime.currentCurrency, user_id: runtime.site?.user_id } : null,
  };
}

async function choosePublicCurrency(page, code) {
  const trigger = page.locator('.w-currency-switcher__trigger:visible').first();
  await expect.poll(() => trigger.evaluate(element => {
    const root = element.closest('.w-currency-switcher');
    return !!(root && window.Weline?.UI?.get?.(root, 'menu') && window.Weline.UI.get(root, 'choice-filter'));
  }), { timeout: 15000, message: '等待真实货币菜单与选项筛选组件挂载' }).toBe(true);
  await trigger.click();
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  const menu = await trigger.getAttribute('aria-controls');
  await page.locator(`#${menu} [data-currency-option][data-currency="${code}"]`).click();
  await page.waitForFunction(expected => {
    const source = document.querySelector('#weline-frontend-runtime-config');
    return source && JSON.parse(source.textContent).currentCurrency === expected;
  }, code, { timeout: 45000 });
}

async function closeRun(run, restoreSource = false) {
  let sourceFailure;
  if (restoreSource) {
    try {
      fixture(run, 'declaration', { variant: 'original' });
      await routeCollect(run, 'finally-restore-source');
    } catch (error) { sourceFailure = error; }
  }
  const cleaned = fixture(run, 'cleanup');
  saveEvidence(run, 'after-cleanup', cleaned);
  runOwnQueues(run);
  await run.testInfo.attach('真实验收fixture基线及清理', { path: path.join(run.directory, 'fixture-manifest.json'), contentType: 'application/json' });
  if (sourceFailure) throw sourceFailure;
}

moduleDescribe(test, MODULE, 'FPC 策略自动同步真实本机通路', () => {
  test.describe.configure({ mode: 'serial' });
  test.use({ actionTimeout: 15000, navigationTimeout: 60000, viewport: { width: 1920, height: 1080 } });
  test.setTimeout(600000);
  test.beforeEach(async ({ page }) => { await disableCache(page); });

  moduleCase(test, { module: MODULE, id: 'FPC-SYNC-UC2' }, '真实UI字段继承、两目录Scope、normal/test、collect保留覆盖', async ({ page }, testInfo) => {
    const run = beginRun(testInfo);
    try {
      await openManagement(page);
      await saveUi(page, run, 'global:normal', { enabled: null, ttl: 67 });
      await saveUi(page, run, 'website_a:normal', { enabled: false, ttl: null });
      await saveUi(page, run, 'store_a:normal', { enabled: null, ttl: 43 });
      let actual = await saveUi(page, run, 'channel_a:normal', { enabled: true, ttl: null });
      expect(actual.rows['channel_a:normal']).toMatchObject({ effective_enabled: true, effective_ttl: 43, enabled_source: actual.scopes.channel_a.canonical_key, ttl_source: actual.scopes.store_a.canonical_key });
      actual = await restoreFieldUi(page, run, 'channel_a:normal', 'enabled', 75);
      expect(actual.rows['channel_a:normal']).toMatchObject({ effective_enabled: false, effective_ttl: 43 });
      actual = await restoreFieldUi(page, run, 'store_a:normal', 'ttl');
      expect(actual.rows['store_a:normal'].effective_ttl).toBe(67);
      await saveUi(page, run, 'website_b:normal', { enabled: true, ttl: 52 });
      await saveUi(page, run, 'global:test', { enabled: true, ttl: 17 });
      actual = await saveUi(page, run, 'website_a:test', { enabled: true, ttl: 19 });
      expect(actual.rows['global:normal'].effective_ttl).toBe(67);
      expect(actual.rows['website_a:normal'].effective_enabled).toBe(false);
      expect(actual.rows['website_a:test']).toMatchObject({ effective_enabled: true, effective_ttl: 19 });
      expect(actual.rows['website_b:normal'].effective_ttl).toBe(52);
      const beforeCollect = actual.rows;
      await selectCell(page, run, 'website_a:normal');
      await page.locator('[data-cdn-action="main"]').click();
      await expect(page.locator('[data-cdn-action="main"]')).not.toHaveAttribute('aria-busy', 'true');
      actual = fixture(run, 'observe');
      for (const [cell, row] of Object.entries(beforeCollect)) expect(pair(actual.rows[cell]), cell).toEqual(pair(row));
      const independent = await callCdnRead(page, 'listFpcPolicies', { target_scope: actual.scopes.website_a.target_scope, store_mode: 'test', keyword: 'Currency', page_size: 100 });
      expect(independent.items.find(row => row.declaration_id === actual.declaration_id)).toMatchObject({ override_ttl: 19, effective_enabled: true });
      saveEvidence(run, 'collect-and-independent-api', { actual, independent });
    } finally { await closeRun(run); }
  });

  moduleCase(test, { module: MODULE, id: 'FPC-SYNC-UC7-LOCAL-FAILURE' }, '真实缺凭据失败、后台重试、独立PG回查不晋级cloud/purge/http', async ({ page }, testInfo) => {
    const run = beginRun(testInfo);
    try {
      await openManagement(page);
      await saveUi(page, run, 'website_a:normal', { ttl: 53, enabled: true });
      runOwnQueues(run);
      await expect.poll(() => fixture(run, 'observe').jobs.find(job => job.adapter === 'cloudflare' && job.domain_ids.includes(2))?.last_error, { timeout: 120000, intervals: [1000, 3000, 5000] }).toContain('cdn_fpc_credentials_unavailable');
      let actual = fixture(run, 'observe');
      const failed = actual.jobs.find(job => job.adapter === 'cloudflare' && job.domain_ids.includes(2));
      expect(failed.status).toBe('error');
      expect(failed.cloud_version).toBeLessThan(failed.desired_version);
      expect(failed.purge_version).toBeLessThan(failed.desired_version);
      expect(failed.verified_version).toBeLessThan(failed.desired_version);
      expect(pendingTargets(failed).length).toBeGreaterThan(0);
      await page.locator('[data-cdn-tab="sync"]').click();
      await page.locator('#cdn-fpc-keyword').fill('changanhanfu.com');
      await page.locator('[data-cdn-action="query"]').click();
      const syncRow = page.locator(`[data-cdn-action="retry"][data-sync-id="${failed.sync_id}"]`).locator('xpath=ancestor::tr');
      await expect(syncRow).toContainText('cdn_fpc_credentials_unavailable');
      await syncRow.locator('summary').click();
      await expect(syncRow).toContainText('未验证');
      await expect(syncRow).toContainText('快照文件版本');
      await expect(syncRow).toContainText('源站已确认版本');
      await syncRow.locator('[data-cdn-action="retry"]').click();
      await expect(page.locator('[data-cdn-notice]')).toContainText('已安排重试');
      runOwnQueues(run);
      actual = fixture(run, 'observe');
      const after = actual.jobs.find(job => job.sync_id === failed.sync_id);
      expect(after.job_key).toBe(failed.job_key);
      expect(after.cloud_version).toBeLessThan(after.desired_version);
      expect(pendingTargets(after).length).toBeGreaterThan(0);
      const independent = await callCdnRead(page, 'listFpcSyncRecords', { keyword: 'changanhanfu.com', page_size: 100 });
      expect(independent.items.find(job => job.sync_id === failed.sync_id).job_key).toBe(failed.job_key);
      saveEvidence(run, 'real-failure-and-retry', { failed, after, independent });
    } finally { await closeRun(run); }
  });

  moduleCase(test, { module: MODULE, id: 'FPC-SYNC-UC3-ORIGIN' }, '真实UI缩短TTL/禁用后currency源站HIT与缓存头', async ({ page, browser }, testInfo) => {
    const run = beginRun(testInfo);
    try {
      await openManagement(page);
      await saveUi(page, run, 'channel_a:normal', { ttl: 67, enabled: true });
      runOwnQueues(run);
      await expect.poll(() => fixture(run, 'observe').origin_version, { timeout: 90000 }).toBeGreaterThanOrEqual(fixture(run, 'observe').desired_version);
      const first = await sourceObservations(browser, run, '/currency');
      assertFreshBound(first, 67);
      expect(first[0].body_text).toMatch(/货币|Currency|currency/);
      await saveUi(page, run, 'channel_a:normal', { ttl: 19 });
      runOwnQueues(run);
      const shortened = await sourceObservations(browser, run, '/currency');
      assertFreshBound(shortened, 19);
      await saveUi(page, run, 'channel_a:normal', { enabled: false });
      runOwnQueues(run);
      const disabled = await sourceObservations(browser, run, '/currency');
      for (const row of disabled) {
        expect(row.status).toBe(200);
        expect(String(row.headers['x-weline-fpc']).toUpperCase()).not.toMatch(/^(HIT|STALE)$/);
        // 冻结契约：bypass维持原响应头；只有STALE要求两个CDN头no-store。
        for (const key of ['cache-control', 'cdn-cache-control', 'cloudflare-cdn-cache-control']) {
          expect(String(row.headers[key] || ''), key).not.toMatch(/\bpublic\b/i);
          const fresh = /(?:^|[,\s])max-age=(\d+)/i.exec(String(row.headers[key] || ''));
          if (fresh) expect(Number(fresh[1]), key).toBe(0);
        }
      }
      saveEvidence(run, 'origin-ttl-disable', { first, shortened, disabled, scope: run.baseline.scopes.channel_a, edge_status: 'not_run_production_not_deployed' });
    } finally { await closeRun(run); }
  });

  moduleCase(test, { module: MODULE, id: 'FPC-SYNC-UC6-LOCAL' }, '真实短TTL到期/停用业务与既有Worker日志（仅已观察分支）', async ({ page, browser }, testInfo) => {
    const run = beginRun(testInfo);
    const stages = [];
    try {
      await openManagement(page);
      await saveUi(page, run, 'channel_a:normal', { enabled: true, ttl: 4 });
      runOwnQueues(run);
      const freshStart = new Date().toISOString();
      const fresh = await sourceObservations(browser, run, '/currency');
      const canonical = await sourceObservations(browser, run, '/currency/frontend/index');
      assertFreshBound(fresh, 4); assertFreshBound(canonical, 4);
      stages.push({ name: 'fresh', start: freshStart, end: new Date().toISOString(), observations: [...fresh, ...canonical] });
      await new Promise(resolve => setTimeout(resolve, 6000));
      const expiryStart = new Date().toISOString();
      const expired = await sourceObservations(browser, run, '/currency');
      const stale = expired.filter(row => String(row.headers['x-weline-fpc']).toUpperCase() === 'STALE');
      for (const row of stale) {
        expect(row.status).toBe(200);
        expect(row.body_text).toMatch(/货币|Currency|currency/);
        for (const header of ['cdn-cache-control', 'cloudflare-cdn-cache-control']) expect(row.headers[header], header).toMatch(/\bno-store\b/i);
      }
      stages.push({ name: 'expired', start: expiryStart, end: new Date().toISOString(), observations: expired });
      await saveUi(page, run, 'channel_a:normal', { enabled: false });
      runOwnQueues(run);
      const disabledStart = new Date().toISOString();
      const disabled = [...await sourceObservations(browser, run, '/currency'), ...await sourceObservations(browser, run, '/currency/frontend/index')];
      for (const row of disabled) {
        expect(row.status).toBe(200); expect(row.body_text).toMatch(/货币|Currency|currency/);
        expect(String(row.headers['x-weline-fpc']).toUpperCase()).not.toMatch(/^(HIT|STALE)$/);
        for (const header of ['cache-control', 'cdn-cache-control', 'cloudflare-cdn-cache-control']) expect(String(row.headers[header] || ''), header).not.toMatch(/\bpublic\b/i);
      }
      stages.push({ name: 'disabled', start: disabledStart, end: new Date().toISOString(), observations: disabled });
      const timingPath = path.join(ROOT, 'var/log/wls/timing.log');
      const size = fs.statSync(timingPath).size;
      const handle = fs.openSync(timingPath, 'r');
      let lines;
      try {
        const buffer = Buffer.alloc(Math.min(size, 4 * 1024 * 1024));
        fs.readSync(handle, buffer, 0, buffer.length, size - buffer.length);
        lines = buffer.toString('utf8').split(/\r?\n/);
      } finally { fs.closeSync(handle); }
      const timing = [];
      for (const line of lines) {
        let row; try { row = JSON.parse(line); } catch { continue; }
        if (!['/currency', '/currency/frontend/index'].includes(row.uri)) continue;
        const time = Date.parse(`${row.timestamp.replace(' ', 'T')}Z`);
        const stage = stages.find(item => time >= Date.parse(item.start) - 1000 && time <= Date.parse(item.end) + 1000);
        if (!stage) continue;
        timing.push({ stage: stage.name, ...Object.fromEntries(['timestamp', 'uri', 'worker_id', 'pid', 'request_count', 'fpc_hit', 'fpc_source'].filter(key => row[key] !== undefined).map(key => [key, row[key]])) });
      }
      const hitWorkers = new Set(timing.filter(row => row.stage === 'fresh' && row.fpc_hit).map(row => `${row.worker_id}:${row.pid}`));
      const disabledWorkers = new Set(timing.filter(row => row.stage === 'disabled' && !row.fpc_hit).map(row => `${row.worker_id}:${row.pid}`));
      saveEvidence(run, 'short-ttl-worker-observation', { stages, timing, stale_observed: stale.length, fresh_workers: [...hitWorkers], disabled_workers: [...disabledWorkers], same_pid_hit_then_disabled: [...hitWorkers].filter(worker => disabledWorkers.has(worker)), legacy_receipt: 'not_verified_no_existing_historical_artifact', warmup: 'not_verified_no_correlated_existing_warmup_receipt', coverage: 'only_actual_observed_branches_not_full_uc6' });
    } finally { await closeRun(run); }
  });

  moduleCase(test, { module: MODULE, id: 'FPC-SYNC-UC1-UC4-LOCAL' }, '真实Currency注释收集、代码硬禁开、声明撤回和累计purge（本机分支）', async ({ page, browser }, testInfo) => {
    test.setTimeout(1200000);
    const run = beginRun(testInfo);
    let sourceTouched = false;
    try {
      await openManagement(page);
      await saveUi(page, run, 'website_a:normal', { enabled: null, ttl: 47 });
      await waitForRouteLease(run, 'before-source-fixture');
      sourceTouched = true;
      fixture(run, 'declaration', { variant: 'ttl90' });
      let actual = await routeCollect(run, 'ttl90');
      expect(actual.rows['website_a:normal']).toMatchObject({ code_ttl: 90, override_ttl: 47 });
      // 先验证canonical是否为真实别名；失败只能作元数据路径迁移，不能称新URL生效。
      const canonical = await sourceObservations(browser, run, '/currency/frontend/index', 1);
      const realAlias = canonical[0].status === 200 && /货币|Currency|currency/.test(canonical[0].body_text);
      fixture(run, 'declaration', { variant: realAlias ? 'canonical_path' : 'path_b' });
      const pathB = await routeCollect(run, realAlias ? 'canonical-alias' : 'metadata-path-b');
      if (realAlias) {
        runOwnQueues(run);
        const responses = await sourceObservations(browser, run, '/currency/frontend/index');
        assertFreshBound(responses, 47);
      }
      fixture(run, 'declaration', { variant: realAlias ? 'ttl90' : 'path_c' });
      const pathC = await routeCollect(run, realAlias ? 'return-real-currency' : 'metadata-path-c');
      fixture(run, 'declaration', { variant: 'disabled' });
      actual = await routeCollect(run, 'code-disabled');
      expect(actual.rows['website_a:normal'].effective_enabled).toBe(false);
      await page.reload({ waitUntil: 'domcontentloaded' }); await ready(page);
      await selectCell(page, run, 'website_a:normal'); await openEditor(page, run);
      await page.locator('#cdn-fpc-editor [name="inherit_enabled"]').uncheck();
      await expect(page.locator('#cdn-fpc-editor [name="enabled"]')).toBeDisabled();
      await expect(page.locator('[data-cdn-code-blocked]')).toBeVisible();
      await page.locator('#cdn-fpc-editor [data-cdn-action="cancel"]').first().click();
      fixture(run, 'declaration', { variant: 'ttl0' });
      actual = await routeCollect(run, 'code-ttl-zero');
      expect(actual.rows['website_a:normal'].effective_enabled).toBe(false);
      fixture(run, 'declaration', { variant: 'withdrawn' });
      const removed = await routeCollect(run, 'declaration-withdrawn');
      expect(removed.rows['website_a:normal'].active).toBe(false);
      expect(removed.rows['website_a:normal'].override_ttl).toBe(47);
      const currentTargets = new Set(removed.jobs.flatMap(job => pendingTargets(job).map(item => JSON.stringify(item.target))));
      for (const observed of [pathB, pathC]) {
        for (const job of observed.jobs) for (const item of pendingTargets(job)) expect(currentTargets.has(JSON.stringify(item.target)), '真实尚未成功purge的目标必须累积').toBe(true);
      }
      expect(removed.desired_version).toBeGreaterThan(pathB.desired_version);
      saveEvidence(run, 'collector-projection-purge', { pathB, pathC, removed, canonical, actual_alias_proven: realAlias, new_path_http_status: realAlias ? 'observed' : 'not_verified_metadata_only', cloud_purge_status: 'not_completed_real_local_credentials_failure' });
    } finally { await closeRun(run, sourceTouched); }
  });

  moduleCase(test, { module: MODULE, id: 'FPC-SYNC-UC8-LOCAL' }, '真实后台私有资格与匿名语言货币业务（本机分支）', async ({ page, browser }, testInfo) => {
    const run = beginRun(testInfo);
    let anonymous;
    let fresh;
    try {
      await openManagement(page);
      const privateUrl = page.url();
      const privateResponse = await page.reload({ waitUntil: 'domcontentloaded' });
      await ready(page);
      const privatePage = await capturePage(page, privateResponse);
      saveEvidence(run, 'private-admin-page', privatePage);
      expect(String(privatePage.headers['x-weline-fpc'] || '')).not.toMatch(/^(HIT|STALE)$/i);
      await expect(page.locator('[data-testid="cdn-api-rules-management"]')).toBeVisible();
      anonymous = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1920, height: 1080 } });
      const guest = await anonymous.newPage(); await disableCache(guest);
      await guest.goto(privateUrl, { waitUntil: 'domcontentloaded' });
      await expect(guest).toHaveURL(/\/admin\/login/);
      await expect(guest.locator('[data-testid="cdn-api-rules-management"]')).toHaveCount(0);
      const origin = process.env.FPC_SYNC_LOCAL_ORIGIN || 'https://p05113ef3.test.weline.com:9555';
      const initialResponse = await guest.goto(new URL('/currency', origin).href, { waitUntil: 'domcontentloaded' });
      const initial = await capturePage(guest, initialResponse);
      saveEvidence(run, 'variant-initial', initial);
      expect(initial.runtime).toMatchObject({ locale: 'en_US', currency: 'USD', user_id: 0 });
      await expect(guest.locator('h1')).toContainText('Currency');
      const languageTrigger = guest.locator('.w-language-switcher__trigger:visible').first();
      await languageTrigger.click();
      const languageMenu = await languageTrigger.getAttribute('aria-controls');
      await Promise.all([
        guest.waitForURL(/\/zh_Hans_CN\/currency/, { timeout: 45000 }),
        guest.locator(`#${languageMenu} [data-language-option][data-lang="zh_Hans_CN"]`).click(),
      ]);
      await guest.waitForLoadState('domcontentloaded');
      await expect(guest.locator('h1')).toContainText('货币');
      const chinese = await capturePage(guest, await guest.reload({ waitUntil: 'domcontentloaded' }));
      saveEvidence(run, 'variant-chinese', chinese);
      expect(chinese.runtime.locale).toBe('zh_Hans_CN');
      expect(chinese.headings).not.toEqual(initial.headings);
      await choosePublicCurrency(guest, 'CNY');
      const cnyGuide = await capturePage(guest, await guest.reload({ waitUntil: 'domcontentloaded' }));
      saveEvidence(run, 'variant-cny-after-ui-switch', cnyGuide);
      console.log('[FPC acceptance] 真实语言货币切换', JSON.stringify({ before: { url: chinese.url, runtime: chinese.runtime }, after: { url: cnyGuide.url, runtime: cnyGuide.runtime } }));
      expect(cnyGuide.runtime).toMatchObject({ locale: 'zh_Hans_CN', currency: 'CNY' });
      // 路径来自真实指南页的业务导航，再选真实库存商品，不造商品或价格响应。
      await guest.locator('a[href*="/products"]:visible').first().click();
      await guest.locator('[data-testid="weline-product-card"] a.wpc-media-link').first().click();
      const product = guest.locator('[data-testid="storefront-product-detail"]').first();
      await expect(product).toBeVisible();
      await expect(product).toHaveAttribute('data-currency', 'CNY');
      const cnyPrice = { amount: await product.getAttribute('data-price'), text: await product.locator('.product-native-detail__price').first().innerText() };
      expect(Number(cnyPrice.amount)).toBeGreaterThan(0);
      expect(cnyPrice.text).toMatch(/[¥￥]/);
      const productUrl = guest.url();
      await choosePublicCurrency(guest, 'USD');
      await guest.reload({ waitUntil: 'domcontentloaded' });
      await expect(product).toHaveAttribute('data-currency', 'USD');
      const usdPrice = { amount: await product.getAttribute('data-price'), text: await product.locator('.product-native-detail__price').first().innerText() };
      expect(Number(usdPrice.amount)).toBeGreaterThan(0);
      expect(usdPrice.text).toContain('$');
      expect(usdPrice.text).not.toEqual(cnyPrice.text);
      fresh = await browser.newContext({ ignoreHTTPSErrors: true });
      const isolated = await fresh.newPage(); await disableCache(isolated);
      const isolatedGuide = await capturePage(isolated, await isolated.goto(new URL('/currency', origin).href, { waitUntil: 'domcontentloaded' }));
      expect(isolatedGuide.runtime).toMatchObject({ locale: 'en_US', currency: 'USD', user_id: 0 });
      saveEvidence(run, 'private-locale-currency-business', { privatePage, initial, chinese, cnyGuide, productUrl, cnyPrice, usdPrice, isolatedGuide, storefront_customer_login: 'not_run_existing_customer_credentials_not_available', edge_receipt: 'not_run' });
    } finally {
      if (fresh) await fresh.close();
      if (anonymous) await anonymous.close();
      await closeRun(run);
    }
  });

  moduleCase(test, { module: MODULE, id: 'FPC-SYNC-UC8-CUSTOMER' }, '正式前台顾客登录、同公共页旁路及私有账户页隔离', async ({ page, browser }, testInfo) => {
    const run = beginRun(testInfo);
    let guest;
    let signedIn = false;
    try {
      const origin = process.env.FPC_SYNC_LOCAL_ORIGIN || 'https://p05113ef3.test.weline.com:9555';
      const anonymous = await sourceObservations(browser, run, '/currency');
      assertFreshBound(anonymous, run.baseline.rows['channel_a:normal'].effective_ttl);
      const loginUrl = new URL('/customer/account/login', origin);
      loginUrl.searchParams.set('redirect_url', new URL('/currency', origin).href);
      await page.goto(loginUrl.href, { waitUntil: 'domcontentloaded' });
      const form = page.locator('[data-w-component="account-login"]');
      await expect.poll(() => form.evaluate(element => !!window.Weline?.UI?.get?.(element, 'account-login')), { timeout: 30000 }).toBe(true);
      await form.locator('[name="username"]').fill(process.env.PLAYWRIGHT_CUSTOMER_USERNAME || 'e2e.customer@weline.local');
      await form.locator('[name="password"]').fill(process.env.PLAYWRIGHT_CUSTOMER_PASSWORD || 'E2eTest!234');
      const imageCaptcha = form.locator('[data-weline-captcha-provider="local_image"] .weline-captcha-image');
      if (!(await imageCaptcha.isVisible())) await form.locator('[data-w-login-submit]').click();
      await expect(imageCaptcha).toBeVisible({ timeout: 30000 });
      await expect.poll(() => imageCaptcha.evaluate(image => !(image instanceof HTMLImageElement) || (image.complete && image.naturalWidth > 0))).toBe(true);
      const display = await page.context().newCDPSession(page);
      await display.send('Emulation.setDeviceMetricsOverride', { width: 1920, height: 1080, deviceScaleFactor: 3, mobile: false });
      await imageCaptcha.screenshot({ path: path.join(run.directory, 'captcha-ui-observation.png'), scale: 'device' });
      console.log('[FPC acceptance] 正常UI图形验证码待肉眼填写', run.directory);
      // 测试席只查看实际图像并输入可见字符，不查服务器答案或改验证策略。
      const answerPath = path.join(run.directory, 'captcha-ui-answer.txt');
      await expect.poll(() => fs.existsSync(answerPath), { timeout: 120000, intervals: [1000] }).toBe(true);
      const answer = fs.readFileSync(answerPath, 'utf8').trim();
      expect(answer).toMatch(/^[A-Za-z0-9]{6}$/);
      await form.locator('[name="captcha_response"]').fill(answer);
      await form.locator('[data-w-login-submit]').click();
      await expect(page).toHaveURL(/\/currency(?:\?|$)/, { timeout: 60000 });
      await page.waitForLoadState('domcontentloaded');
      await page.waitForFunction(() => typeof window.Weline?.load === 'function');
      signedIn = true;
      const identity = await page.evaluate(async () => {
        if (!window.Weline?.Api?.resource) await window.Weline.load('api');
        const result = await (await window.Weline.Api.resource('account')).current({});
        const user = result.user || result.data?.user || {};
        return { isLogin: !!(result.isLogin || result.logged_in || result.data?.isLogin), user_id: Number(user.customer_id || user.user_id || user.id || 0) };
      });
      saveEvidence(run, 'real-customer-identity', identity);
      expect(identity.isLogin).toBe(true); expect(identity.user_id).toBeGreaterThan(0);
      const publicSigned = await capturePage(page, await page.goto(new URL('/currency', origin).href, { waitUntil: 'domcontentloaded' }));
      saveEvidence(run, 'signed-in-public-page', publicSigned);
      expect(publicSigned.status).toBe(200); await expect(page.locator('h1')).toContainText('Currency');
      expect(String(publicSigned.headers['x-weline-fpc'] || '')).not.toMatch(/^(HIT|STALE)$/i);
      for (const key of ['cdn-cache-control', 'cloudflare-cdn-cache-control']) expect(String(publicSigned.headers[key] || '')).not.toMatch(/\bpublic\b/i);
      const privateUrl = new URL('/customer/account/index', origin).href;
      const privateSigned = await capturePage(page, await page.goto(privateUrl, { waitUntil: 'domcontentloaded' }));
      saveEvidence(run, 'signed-in-private-account', privateSigned);
      expect(privateSigned.status).toBe(200); await expect(page.locator('.account-index #accountIdentity')).toContainText(`#${identity.user_id}`);
      expect(String(privateSigned.headers['x-weline-fpc'] || '')).not.toMatch(/^(HIT|STALE)$/i);
      guest = await browser.newContext({ ignoreHTTPSErrors: true });
      const separate = await guest.newPage(); await disableCache(separate);
      await separate.goto(privateUrl, { waitUntil: 'domcontentloaded' });
      await expect(separate).toHaveURL(/\/customer\/account\/login/);
      await expect(separate.locator('#accountIdentity')).toHaveCount(0);
      saveEvidence(run, 'guest-private-account-redirect', await capturePage(separate, null));
    } catch (error) {
      saveEvidence(run, 'customer-login-obstruction', { ...await capturePage(page, null), feedback: await page.locator('[data-w-login-feedback]').allTextContents(), captcha_elements: await page.locator('[data-weline-captcha-provider]').count() });
      throw error;
    } finally {
      if (signedIn) await page.evaluate(async () => {
        if (!window.Weline?.Api?.resource) await window.Weline.load('api');
        await (await window.Weline.Api.resource('account')).logout({});
      });
      if (guest) await guest.close();
      await closeRun(run);
    }
  });

  moduleCase(test, { module: MODULE, id: 'FPC-SYNC-UC8-VARIANTS' }, '既有入口中文USD与英文CNY业务正文、真实商品币种及匿名上下文隔离', async ({ page, browser }, testInfo) => {
    const run = beginRun(testInfo);
    let english;
    let isolated;
    try {
      const origin = process.env.FPC_SYNC_LOCAL_ORIGIN || 'https://p05113ef3.test.weline.com:9555';
      await page.goto(new URL('/currency', origin).href, { waitUntil: 'domcontentloaded' });
      const trigger = page.locator('.w-language-switcher__trigger:visible').first();
      await trigger.click();
      const menu = await trigger.getAttribute('aria-controls');
      await Promise.all([
        page.waitForURL(/\/zh_Hans_CN\/currency/, { timeout: 45000 }),
        page.locator(`#${menu} [data-language-option][data-lang="zh_Hans_CN"]`).click(),
      ]);
      const chinese = await capturePage(page, await page.reload({ waitUntil: 'domcontentloaded' }));
      saveEvidence(run, 'existing-chinese-usd', chinese);
      expect(chinese.status).toBe(200);
      expect(chinese.runtime).toMatchObject({ locale: 'zh_Hans_CN', currency: 'USD', user_id: 0 });
      await expect(page.locator('h1')).toContainText('货币');
      english = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1920, height: 1080 } });
      const customer = await english.newPage(); await disableCache(customer);
      await customer.goto(new URL('/currency', origin).href, { waitUntil: 'domcontentloaded' });
      await choosePublicCurrency(customer, 'CNY');
      const cnyGuide = await capturePage(customer, await customer.reload({ waitUntil: 'domcontentloaded' }));
      saveEvidence(run, 'existing-english-cny', cnyGuide);
      expect(cnyGuide.status).toBe(200);
      expect(cnyGuide.runtime).toMatchObject({ locale: 'en_US', currency: 'CNY', user_id: 0 });
      await expect(customer.locator('h1')).toContainText('Currency');
      await customer.locator('a[href*="/products"]:visible').first().click();
      await customer.locator('[data-testid="weline-product-card"] a.wpc-media-link').first().click();
      const product = customer.locator('[data-testid="storefront-product-detail"]').first();
      await expect(product).toBeVisible(); await expect(product).toHaveAttribute('data-currency', 'CNY');
      const cny = { url: customer.url(), amount: await product.getAttribute('data-price'), text: await product.locator('.product-native-detail__price').first().innerText() };
      saveEvidence(run, 'real-product-cny', cny);
      expect(Number(cny.amount)).toBeGreaterThan(0); expect(cny.text).toMatch(/[¥￥]/);
      await choosePublicCurrency(customer, 'USD');
      await customer.reload({ waitUntil: 'domcontentloaded' });
      await expect(product).toHaveAttribute('data-currency', 'USD');
      const usd = { url: customer.url(), amount: await product.getAttribute('data-price'), text: await product.locator('.product-native-detail__price').first().innerText() };
      saveEvidence(run, 'real-product-usd', usd);
      expect(Number(usd.amount)).toBeGreaterThan(0); expect(usd.text).toContain('$'); expect(usd.text).not.toEqual(cny.text);
      isolated = await browser.newContext({ ignoreHTTPSErrors: true });
      const fresh = await isolated.newPage(); await disableCache(fresh);
      const freshGuide = await capturePage(fresh, await fresh.goto(new URL('/currency', origin).href, { waitUntil: 'domcontentloaded' }));
      saveEvidence(run, 'fresh-context-english-usd', freshGuide);
      expect(freshGuide.status).toBe(200); expect(freshGuide.runtime).toMatchObject({ locale: 'en_US', currency: 'USD', user_id: 0 });
      await expect(fresh.locator('h1')).toContainText('Currency');
    } finally {
      if (isolated) await isolated.close();
      if (english) await english.close();
      await closeRun(run);
    }
  });
});
