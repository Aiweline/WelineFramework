/**
 * 万能商城内核计划：Product SceneRenderer Browser 入口（TEST-P2C-RENDER-01/02/03）
 *
 * - 隔离 registry harness（不污染生产 Extends）
 * - 01：default / missing custom fallback / duplicate code
 * - 02：bug empty → fallback；handled_empty → 真空；异常 → fallback + 诊断码（异常消息不进 drain）
 * - 03：HTML/script 转义；template_path 拒绝；DOM 注入后 console 无 XSS 执行
 *
 * 单 case 串行覆盖三 ID，避免连续资源调用触发 scope_rate_limited(429)。
 *
 * @weline-e2e-spec { module: Weline_Product, type: plan, layer: frontend }
 */

const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Product';
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE_SCRIPT = path.resolve(__dirname, 'plan-p2c-render-scene-fixture.php');
const DIRECT = { useProxy: false };

function runFixture(action, payload = {}) {
  const stdout = execFileSync('php', [FIXTURE_SCRIPT], {
    cwd: ROOT_DIR,
    input: JSON.stringify({ action, ...payload }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const last = lines[lines.length - 1] || '{}';
  const parsed = JSON.parse(last);
  if (!parsed.ok) {
    throw new Error(`p2c-render fixture ${action} failed: ${parsed.error || stdout}`);
  }
  return parsed;
}

async function ensureApi(page) {
  await page.waitForFunction(() => {
    const w = window.Weline;
    return !!(w && ((w.Api && typeof w.Api.resource === 'function') || typeof w.load === 'function'));
  }, { timeout: 30000 });
  await page.evaluate(async () => {
    const w = window.Weline;
    if (w && w.Api && typeof w.Api.resource === 'function') {
      return;
    }
    if (w && typeof w.load === 'function') {
      await w.load('api');
    }
  });
  await page.waitForFunction(
    () => !!(window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function'),
    { timeout: 15000 },
  );
}

async function callScene(page, operation, params = {}) {
  return page.evaluate(async ({ operation: op, params: p }) => {
    let api = window.Weline && window.Weline.Api;
    if ((!api || typeof api.resource !== 'function') && window.Weline && typeof window.Weline.load === 'function') {
      api = await window.Weline.load('api');
    }
    if (!api || typeof api.resource !== 'function') {
      return { __no_api: true };
    }
    const resource = await api.resource('product_scene');
    if (!resource || typeof resource[op] !== 'function') {
      return { __no_op: op, keys: resource ? Object.keys(resource) : [] };
    }
    try {
      const data = await resource[op](p);
      return { ok: true, data };
    } catch (err) {
      return {
        ok: false,
        message: String(err && (err.message || err)),
        response: err && err.response && err.response.data ? err.response.data : null,
      };
    }
  }, { operation, params });
}

function unwrap(result) {
  if (!result || result.__no_api || result.__no_op) {
    throw new Error(`product_scene api unavailable: ${JSON.stringify(result)}`);
  }
  if (!result.ok) {
    const nested = result.response && result.response.data ? result.response.data : null;
    if (nested && typeof nested === 'object') {
      return nested;
    }
    throw new Error(`product_scene op failed: ${JSON.stringify(result)}`);
  }
  const data = result.data;
  if (data && typeof data === 'object' && data.data && typeof data.data === 'object' && data.success === undefined) {
    return data.data;
  }
  return data;
}

moduleDescribe(test, MODULE, '计划 P2C-RENDER SceneRenderer Browser', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-P2C-RENDER-01' },
    '01/02/03：default/custom/duplicate；empty/handled_empty/exception；XSS 转义与 template_path 拒绝',
    async ({ page }) => {
      const fixture = runFixture('prepare');
      expect(fixture.harness_active).toBeTruthy();
      const consoleErrors = [];
      page.on('console', (msg) => {
        if (msg.type() === 'error') {
          consoleErrors.push(msg.text());
        }
      });
      page.on('pageerror', (err) => {
        consoleErrors.push(String(err && err.message ? err.message : err));
      });

      try {
        await gotoFrontend(page, '/', DIRECT);
        await expect(page.locator('body')).toBeVisible();
        await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);
        await ensureApi(page);

        // --- TEST-P2C-RENDER-01 ---
        const dup = unwrap(await callScene(page, 'tryRegisterDuplicate', {
          code: 'default',
          type: 'simple',
        }));
        expect(dup.success, JSON.stringify(dup)).toBeTruthy();
        expect(dup.duplicated).toBeTruthy();
        expect(dup.error_code).toBe('product_provider_code_duplicate');

        const def = unwrap(await callScene(page, 'renderScene', {
          scene: 'detail',
          product_type: 'simple',
          product: { name: 'Demo', sku: 'SKU-1', description: 'Hello', price_label: '¥10' },
        }));
        expect(def.success, JSON.stringify(def)).toBeTruthy();
        expect(def.result.used_fallback).toBeFalsy();
        expect(String(def.result.html)).toContain('w-product--detail');
        expect(String(def.result.html)).toContain('Demo');
        expect(def.result.provider_code).toBe('default');

        const missing = unwrap(await callScene(page, 'renderScene', {
          scene: 'list',
          product_type: 'gift',
          product: { name: 'Gift', sku: 'G1' },
        }));
        expect(missing.success, JSON.stringify(missing)).toBeTruthy();
        expect(missing.result.used_fallback).toBeTruthy();
        expect(missing.result.error_code).toBe('product_renderer_exception');
        expect(String(missing.result.html)).toContain('Gift');

        // --- TEST-P2C-RENDER-02 ---
        const bug = unwrap(await callScene(page, 'renderScene', {
          scene: 'detail',
          product_type: 'empty_bug',
          product: { name: 'ShouldFallback', sku: 'E1' },
        }));
        expect(bug.result.used_fallback).toBeTruthy();
        expect(bug.result.error_code).toBe('product_renderer_empty_bug');
        expect(String(bug.result.html)).toContain('ShouldFallback');

        const ok = unwrap(await callScene(page, 'renderScene', {
          scene: 'detail',
          product_type: 'empty_ok',
          product: { name: 'Hidden', sku: 'E2' },
        }));
        expect(ok.result.handled_empty).toBeTruthy();
        expect(ok.result.used_fallback).toBeFalsy();
        expect(String(ok.result.html)).toBe('');

        const boom = unwrap(await callScene(page, 'renderScene', {
          scene: 'detail',
          product_type: 'boom',
          product: { name: 'AfterCrash', sku: 'E3' },
        }));
        expect(boom.result.used_fallback).toBeTruthy();
        expect(boom.result.error_code).toBe('product_renderer_exception');
        expect(String(boom.result.html)).toContain('AfterCrash');
        expect(Array.isArray(boom.logged_errors)).toBeTruthy();
        expect(boom.logged_errors).toContain('product_renderer_exception');
        expect(
          boom.logged_errors.some((c) => String(c).includes('boom') || String(c).includes('harness_boom')),
        ).toBeFalsy();

        // --- TEST-P2C-RENDER-03（限流友好：detail 覆盖 script+img；list 覆盖 template_path）---
        const xss = unwrap(await callScene(page, 'renderScene', {
          scene: 'detail',
          product_type: 'simple',
          product: fixture.xss_product,
        }));
        expect(xss.success, JSON.stringify(xss)).toBeTruthy();
        expect(String(xss.result.html)).not.toContain('<script>');
        expect(String(xss.result.html)).toContain('&lt;script&gt;');
        expect(String(xss.result.html)).toContain('&lt;img');

        await page.evaluate((html) => {
          let host = document.getElementById('p2c-render-xss-host');
          if (!host) {
            host = document.createElement('div');
            host.id = 'p2c-render-xss-host';
            document.body.appendChild(host);
          }
          host.innerHTML = html;
        }, xss.result.html);
        await page.waitForTimeout(200);

        const rejected = unwrap(await callScene(page, 'renderScene', {
          scene: 'list',
          product_type: 'simple',
          product: { name: 'X', sku: '1' },
          options: { template_path: '/etc/passwd' },
        }));
        expect(rejected.result.error_code).toBe('product_template_path_rejected');
        expect(rejected.result.used_fallback).toBeTruthy();
        expect(String(rejected.result.html)).toContain('w-product--list');

        expect(
          consoleErrors.filter((t) => /alert|XSS|Script error/i.test(t)).length,
          JSON.stringify(consoleErrors),
        ).toBe(0);
      } finally {
        runFixture('cleanup');
      }
    },
  );
});
