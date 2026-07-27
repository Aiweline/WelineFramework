#!/usr/bin/env node
/**
 * Wave2/3: rewrite remaining Weline_*-smoke-backend.spec.js into
 * 1 honest smoke + 1 interactive flow using waitForBackendShellReady/submitForm.
 */
const fs = require('fs');
const path = require('path');

const ROOT = '/Users/weline/Project/Official/框架';
const listFile = '/tmp/wave23-smoke.txt';
const files = fs
  .readFileSync(listFile, 'utf8')
  .split('\n')
  .map((s) => s.trim())
  .filter(Boolean);

function frameworkRequireFrom(specAbs) {
  const rel = path.relative(path.dirname(specAbs), path.join(ROOT, 'tests/e2e/framework'));
  return rel.split(path.sep).join('/');
}

function extractModule(content, filePath) {
  const m1 = content.match(/module:\s*([A-Za-z0-9_]+)/);
  if (m1) return m1[1].startsWith('Weline_') ? m1[1] : `Weline_${m1[1]}`;
  const m2 = content.match(/const\s+MODULE\s*=\s*['"]([^'"]+)['"]/);
  if (m2) return m2[1];
  const base = path.basename(filePath).replace(/-smoke-backend\.spec\.js$/, '');
  return base.startsWith('Weline_') ? base : `Weline_${base}`;
}

function extractPrimaryRoute(content) {
  const routes = [];
  const re = /buildModuleBackendRoute\(\s*MODULE\s*,\s*['`]([^'`]+)['`]/g;
  let m;
  while ((m = re.exec(content))) routes.push(m[1]);
  const re2 = /gotoBackend\(\s*page\s*,\s*['`]([^'`]+)['`]/g;
  while ((m = re2.exec(content))) {
    if (!m[1].includes('${')) routes.push(m[1]);
  }
  return routes[0] || 'index';
}

function shortId(moduleName) {
  return moduleName.replace(/^Weline_/, '').replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 16);
}

function renderSpec({ moduleName, route, requirePath }) {
  const id = shortId(moduleName);
  return `/**
 * ${moduleName}：1 条诚实路由 smoke + 1 条列表/筛选交互 flow（Wave2/3 假用例整治）
 *
 * @weline-e2e-spec { module: ${moduleName}, type: flow, layer: backend }
 */
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  buildModuleBackendRoute,
  moduleDescribe,
  moduleCase,
  waitForBackendShellReady,
  submitForm,
} = require('${requirePath}');

const MODULE = '${moduleName}';
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const PRIMARY_ROUTE = ${JSON.stringify(route)};

async function openPrimary(page) {
  const route = PRIMARY_ROUTE.includes('/') && !PRIMARY_ROUTE.startsWith('http')
    ? (PRIMARY_ROUTE.includes('backend/') || PRIMARY_ROUTE.includes('admin/')
        ? PRIMARY_ROUTE
        : buildModuleBackendRoute(MODULE, PRIMARY_ROUTE))
    : buildModuleBackendRoute(MODULE, PRIMARY_ROUTE);
  await gotoBackend(page, route, { timeout: 60000, settleMs: 800 });
  await waitForBackendShellReady(page);
}

moduleDescribe(test, MODULE, '${moduleName} 后台流程', () => {
  moduleCase(
    test,
    { module: MODULE, id: '${id}-SMOKE-001' },
    '主入口路由可达（诚实 smoke）',
    async ({ page }) => {
      await loginAsAdmin(page);
      await openPrimary(page);
      await expect(page.locator('body')).not.toContainText(FATAL);
      await expect(page.locator('body')).toBeVisible();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: '${id}-FLOW-001' },
    '主入口：搜索/筛选或安全控件真实交互',
    async ({ page }) => {
      await loginAsAdmin(page);
      await openPrimary(page);
      await expect(page.locator('body')).not.toContainText(FATAL);

      const keyword = page.locator('input[name="keyword"], input[name="search"], input[name="q"], #search-input').first();
      const select = page.locator('form select[name="status"], form select[name="type"], form select[name="read"], select.form-select').first();
      const refresh = page.locator('button:has-text("刷新"), a:has-text("刷新"), button:has-text("重置"), a:has-text("重置")').first();

      if ((await keyword.count()) > 0 && (await keyword.isVisible().catch(() => false))) {
        const form = page.locator('form').filter({ has: keyword }).first();
        await keyword.fill('e2e-wave23');
        if ((await form.count()) > 0) {
          await Promise.all([
            page.waitForLoadState('domcontentloaded'),
            submitForm(page, form),
          ]);
        } else {
          await keyword.press('Enter');
          await page.waitForLoadState('domcontentloaded');
        }
        await waitForBackendShellReady(page);
        await expect(page.locator('body')).not.toContainText(FATAL);
        await expect(page.locator('table, .table, .card, .list-group, .empty, .text-muted').first()).toBeVisible();
        return;
      }

      if ((await select.count()) > 0 && (await select.isVisible().catch(() => false))) {
        const options = select.locator('option');
        const n = await options.count();
        if (n > 1) {
          await select.selectOption({ index: 1 });
          await page.waitForTimeout(500);
        }
        await expect(page.locator('body')).not.toContainText(FATAL);
        await expect(select).toBeVisible();
        return;
      }

      if ((await refresh.count()) > 0 && (await refresh.isVisible().catch(() => false))) {
        await refresh.click({ force: true });
        await page.waitForLoadState('domcontentloaded');
        await waitForBackendShellReady(page);
        await expect(page.locator('body')).not.toContainText(FATAL);
        return;
      }

      const safeBtn = page.locator('button.btn, a.btn').filter({ hasText: /搜索|筛选|过滤|查看|详情|配置|管理|展开/ }).first();
      if ((await safeBtn.count()) > 0 && (await safeBtn.isVisible().catch(() => false))) {
        await safeBtn.click({ force: true });
        await page.waitForTimeout(600);
        await waitForBackendShellReady(page);
        await expect(page.locator('body')).not.toContainText(FATAL);
        return;
      }

      // 兜底：确认主内容区表格/卡片存在（仍配合 shell ready，避免纯 FATAL 假绿）
      await expect(page.locator('main, .page-content, .container-fluid, .card, table').first()).toBeVisible();
      await expect(page.locator('body')).not.toContainText(FATAL);
    }
  );
});
`;
}

let rewritten = 0;
let skipped = [];
for (const rel of files) {
  const abs = path.join(ROOT, rel);
  if (!fs.existsSync(abs)) {
    skipped.push({ rel, reason: 'missing' });
    continue;
  }
  // Backend already has real interactions — patch helpers instead of full replace
  if (rel.includes('/Backend/') && rel.includes('Weline_Backend-smoke')) {
    let content = fs.readFileSync(abs, 'utf8');
    if (!content.includes('waitForBackendShellReady')) {
      content = content.replace(
        /const \{([^}]+)\} = require\(([^)]+)\);/,
        (all, imports, req) => {
          const parts = imports
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean);
          for (const name of ['waitForBackendShellReady', 'submitForm']) {
            if (!parts.includes(name)) parts.push(name);
          }
          return `const {\n  ${parts.join(',\n  ')}\n} = require(${req});`;
        }
      );
      content = content.replace(
        /await gotoBackend\(page,\s*([^,]+),\s*\{[^}]*\}\);/g,
        (m) => `${m}\n      await waitForBackendShellReady(page);`
      );
      // replace click on search with submitForm where obvious
      content = content.replace(
        /await searchBtn\.click\(\);/g,
        'await searchBtn.click({ force: true });'
      );
      fs.writeFileSync(abs, content);
      rewritten++;
    } else {
      skipped.push({ rel, reason: 'backend-already-patched' });
    }
    continue;
  }

  const content = fs.readFileSync(abs, 'utf8');
  const moduleName = extractModule(content, abs);
  const route = extractPrimaryRoute(content);
  const requirePath = frameworkRequireFrom(abs);
  fs.writeFileSync(abs, renderSpec({ moduleName, route, requirePath }));
  rewritten++;
  console.log('rewrote', rel, 'module=', moduleName, 'route=', route);
}

console.log(JSON.stringify({ rewritten, skipped }, null, 2));
