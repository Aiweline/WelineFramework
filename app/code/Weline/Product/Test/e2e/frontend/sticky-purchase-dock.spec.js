/**
 * PDP sticky purchase dock: scroll-away show + proxy click + specs jump.
 *
 * @weline-e2e-spec { module: Weline_Product, type: feature, layer: frontend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */

const fs = require('fs');
const path = require('path');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Product';
const ROOT = path.resolve(__dirname, '../../../../../../..');
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';
const PDP_CANDIDATES = [
  '/product/558?offer=8645527d-3748-5ab3-b144-f2e8b39f257f',
  '/product/558',
  '/en_US/product/113?size=s&style_type=hf-s-shang-ru-jia-fen-se-qun-zi-4604234',
];

async function openPdpWithSticky(page) {
  const diagnostics = [];
  for (const route of PDP_CANDIDATES) {
    const url = `${BASE}${route}`;
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForSelector('[data-testid="storefront-product-detail"]', { timeout: 20000 }).catch(() => null);
    await page.waitForSelector('[data-testid="product-sticky-purchase"]', { timeout: 10000 }).catch(() => null);
    const body = await page.locator('body').innerText().catch(() => '');
    if (/Fatal error|ParseError|WLS Runtime Error/i.test(body)) {
      diagnostics.push(`${route}: fatal`);
      continue;
    }
    const detailCount = await page.locator('[data-testid="storefront-product-detail"]').count();
    const dockCount = await page.locator('[data-testid="product-sticky-purchase"]').count();
    const addCount = await page.locator('[data-testid="product-add-to-cart"]').count();
    diagnostics.push(`${route}: detail=${detailCount} dock=${dockCount} add=${addCount} url=${page.url()}`);
    if (detailCount > 0 && dockCount > 0 && addCount > 0) {
      await page.waitForSelector('[data-sticky-purchase][data-sticky-ready="1"]', { timeout: 15000 }).catch(() => null);
      return route;
    }
  }
  throw new Error(`No PDP with sticky dock. ${diagnostics.join(' | ')}`);
}

async function revealStickyDock(page) {
  await page.evaluate(() => {
    const sections = document.querySelector('.product-native-detail__detail-sections')
      || document.querySelector('[data-testid="product-description"]')
      || document.querySelector('[data-testid="product-specifications"]');
    if (sections) {
      sections.scrollIntoView({ block: 'start' });
    }
    const actions = document.querySelector('.product-native-detail__actions');
    window.scrollBy(0, Math.max(900, (actions ? actions.getBoundingClientRect().bottom : 0) + 240));
  });
  await page.waitForTimeout(400);
  await page.waitForFunction(() => {
    const dock = document.querySelector('[data-testid="product-sticky-purchase"]');
    return !!(dock && !dock.hidden && dock.getAttribute('aria-hidden') === 'false');
  }, { timeout: 15000 });
}

async function armProxyProbe(page) {
  await page.evaluate(() => {
    window.__stickyProxyHit = 0;
    document.querySelectorAll('[data-testid="product-add-to-cart"]').forEach(function (primary) {
      primary.disabled = false;
      primary.removeAttribute('disabled');
      primary.setAttribute('aria-disabled', 'false');
      primary.addEventListener(
        'click',
        function (e) {
          e.preventDefault();
          e.stopImmediatePropagation();
          window.__stickyProxyHit += 1;
        },
        true
      );
    });
    var sticky = document.querySelector('[data-testid="product-sticky-add-to-cart"]');
    if (sticky) {
      sticky.disabled = false;
      sticky.removeAttribute('disabled');
      sticky.setAttribute('aria-disabled', 'false');
    }
  });
}

moduleDescribe(test, MODULE, 'PDP sticky purchase dock', () => {
  test.setTimeout(180000);

  moduleCase(
    test,
    { module: MODULE, id: 'ac-e2e-specs' },
    '通路：悬浮条规格摘要与跳转规格区',
    async ({ page }) => {
      const info = fs.readFileSync(
        path.join(ROOT, 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml'),
        'utf8'
      );
      const stickyJs = fs.readFileSync(
        path.join(ROOT, 'app/code/Weline/Product/view/statics/js/widgets/product-sticky-purchase.js'),
        'utf8'
      );
      expect(info).toContain('data-testid="product-sticky-specs"');
      expect(info).toContain('data-testid="product-sticky-jump-variants"');
      expect(info).toContain('data-testid="product-sticky-primary-image"');
      expect(info).toContain('data-testid="product-sticky-media"');
      expect(info.indexOf('data-testid="product-sticky-media"')).toBeLessThan(
        info.indexOf('product-native-detail__sticky-purchase-meta')
      );
      expect(info.indexOf('product-native-detail__sticky-purchase-meta')).toBeLessThan(
        info.indexOf('data-testid="product-sticky-specs"')
      );
      expect(stickyJs).toContain('scrollIntoView');
      expect(stickyJs).toContain('product-variant-axes');
      expect(stickyJs).toContain('data-sticky-specs');

      await openPdpWithSticky(page);
      await revealStickyDock(page);
      await expect(page.locator('[data-testid="product-sticky-purchase"]')).toBeVisible();
      await expect(page.locator('[data-testid="product-sticky-primary-image"]')).toBeAttached();

      const layout = await page.evaluate(() => {
        const media = document.querySelector('[data-testid="product-sticky-media"]');
        const meta = document.querySelector('.product-native-detail__sticky-purchase-meta');
        const specs = document.querySelector('[data-testid="product-sticky-specs"]');
        const jump = document.querySelector('[data-testid="product-sticky-jump-variants"]');
        if (!media || !meta || !specs || !jump || media.hidden || jump.hidden) {
          return null;
        }
        const mr = media.getBoundingClientRect();
        const tr = meta.getBoundingClientRect();
        const sr = specs.getBoundingClientRect();
        const jr = jump.getBoundingClientRect();
        return {
          mediaLeft: mr.left,
          metaLeft: tr.left,
          specsLeft: sr.left,
          jumpLeft: jr.left,
        };
      });
      if (layout) {
        expect(layout.mediaLeft).toBeLessThan(layout.metaLeft + 1);
        expect(layout.metaLeft).toBeLessThan(layout.specsLeft + 1);
        expect(layout.specsLeft).toBeLessThanOrEqual(layout.jumpLeft + 1);
      }

      const axesCount = await page.locator('[data-testid="product-variant-axes"]').count();
      if (axesCount > 0) {
        const jump = page.locator('[data-testid="product-sticky-jump-variants"]');
        await expect(jump).toBeVisible();
        const before = await page.evaluate(() => {
          const axes = document.querySelector('[data-testid="product-variant-axes"]');
          return axes ? Math.abs(axes.getBoundingClientRect().top) : -1;
        });
        await page.evaluate(() => {
          const btn = document.querySelector('[data-testid="product-sticky-jump-variants"]');
          if (btn) {
            btn.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
          }
        });
        await page.waitForTimeout(800);
        const after = await page.evaluate(() => {
          const axes = document.querySelector('[data-testid="product-variant-axes"]');
          if (!axes) {
            return { dist: -1, visible: false };
          }
          const rect = axes.getBoundingClientRect();
          return {
            dist: Math.abs(rect.top - window.innerHeight / 2),
            visible: rect.bottom > 0 && rect.top < window.innerHeight,
          };
        });
        expect(after.visible || after.dist < before || after.dist < window.innerHeight).toBeTruthy();
      }
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'ac-e2e-sticky-show' },
    '通路：滚出主加购后悬浮条可见',
    async ({ page }) => {
      const info = fs.readFileSync(
        path.join(ROOT, 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml'),
        'utf8'
      );
      expect(info).toContain('data-testid="product-sticky-purchase"');
      expect(info).toContain('data-weline-load="productStickyPurchase"');
      expect(info).toContain('!$quickAdd && !$quoteOnly && !$isPreviewMode');
      const stickyJs = fs.readFileSync(
        path.join(ROOT, 'app/code/Weline/Product/view/statics/js/widgets/product-sticky-purchase.js'),
        'utf8'
      );
      expect(stickyJs).toContain('IntersectionObserver');

      await openPdpWithSticky(page);
      const dock = page.locator('[data-testid="product-sticky-purchase"]');
      await expect(dock).toBeAttached();
      await revealStickyDock(page);
      await expect(dock).toBeVisible();
      await expect(page.locator('[data-testid="product-sticky-add-to-cart"]')).toBeVisible();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'ac-e2e-sticky-click' },
    '通路：悬浮加购点击代理主 CTA',
    async ({ page }) => {
      await openPdpWithSticky(page);
      await revealStickyDock(page);
      await armProxyProbe(page);
      await page.evaluate(() => {
        var sticky = document.querySelector('[data-testid="product-sticky-add-to-cart"]');
        if (sticky) {
          sticky.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
        }
      });
      await expect
        .poll(async () => page.evaluate(() => window.__stickyProxyHit || 0), { timeout: 8000 })
        .toBeGreaterThan(0);
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'e2e-ch1-clearance' },
    '完整功能通路：sticky 显示后 clearance 变量前后端生效',
    async ({ page }) => {
      const info = fs.readFileSync(
        path.join(ROOT, 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml'),
        'utf8'
      );
      const storeMusicCss = fs.readFileSync(
        path.join(ROOT, 'app/code/Weline/StoreMusic/view/statics/css/store-music.css'),
        'utf8'
      );
      const csCss = fs.readFileSync(
        path.join(ROOT, 'app/code/Weline/CustomerService/view/statics/css/customer-service.css'),
        'utf8'
      );
      expect(info).toContain('--weline-product-sticky-purchase-clearance');
      expect(storeMusicCss).toContain('--weline-product-sticky-purchase-clearance');
      expect(csCss).toContain('--weline-product-sticky-purchase-clearance');

      await openPdpWithSticky(page);
      await revealStickyDock(page);
      await expect(page.locator('[data-testid="product-sticky-purchase"]')).toBeVisible();

      const clearance = await page.evaluate(() => {
        const html = document.documentElement;
        if (!html.classList.contains('has-product-sticky-purchase')) {
          return { ok: false, reason: 'missing-class' };
        }
        const raw = getComputedStyle(html).getPropertyValue('--weline-product-sticky-purchase-clearance').trim();
        return { ok: !!raw && raw !== '0px' && raw !== '0', raw };
      });
      expect(clearance.ok).toBeTruthy();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'e2e-ch2-floats' },
    '完整功能通路：sticky 可见时浮层高于购买栏不挡 CTA',
    async ({ page }) => {
      await openPdpWithSticky(page);
      await revealStickyDock(page);
      await expect(page.locator('[data-testid="product-sticky-purchase"]')).toBeVisible();

      const geometry = await page.evaluate(() => {
        const dock = document.querySelector('[data-testid="product-sticky-purchase"]');
        const pet = document.querySelector('.w-store-music');
        const cs = document.querySelector('#customer-service-widget .cs-chat-button')
          || document.querySelector('#customer-service-widget');
        if (!dock) {
          return { ok: false, reason: 'no-dock' };
        }
        const dockTop = dock.getBoundingClientRect().top;
        const out = { ok: true, dockTop, pet: null, cs: null };
        if (pet) {
          const r = pet.getBoundingClientRect();
          out.pet = { bottom: r.bottom, clear: r.bottom <= dockTop - 2 };
          if (!out.pet.clear) {
            out.ok = false;
          }
        }
        if (cs) {
          const r = cs.getBoundingClientRect();
          out.cs = { bottom: r.bottom, clear: r.bottom <= dockTop - 2 };
          if (!out.cs.clear) {
            out.ok = false;
          }
        }
        return out;
      });
      expect(geometry.ok, JSON.stringify(geometry)).toBeTruthy();
    }
  );

  moduleCase(
    test,
    { module: MODULE, id: 'e2e-plan-suite' },
    '完整功能通路：摘要同步 + 跳转 + 代理 + 浮层避障',
    async ({ page }) => {
      const info = fs.readFileSync(
        path.join(ROOT, 'app/code/Weline/Product/view/templates/frontend/widgets/product-info.phtml'),
        'utf8'
      );
      const js = fs.readFileSync(
        path.join(ROOT, 'app/code/Weline/Product/view/statics/js/widgets/product-sticky-purchase.js'),
        'utf8'
      );
      const modules = fs.readFileSync(
        path.join(ROOT, 'app/code/Weline/Product/view/statics/frontend/weline.modules.js'),
        'utf8'
      );
      expect(info).toContain('product-sticky-purchase');
      expect(info).toContain('product-sticky-specs');
      expect(info).toContain('product-sticky-jump-variants');
      expect(info).toContain('product-sticky-primary-image');
      expect(info).toContain('--weline-z-sticky-header');
      expect(info).toContain('--weline-product-sticky-purchase-clearance');
      expect(info).toContain('env(safe-area-inset-bottom');
      expect(js).toContain('IntersectionObserver');
      expect(js).toContain('scrollIntoView');
      expect(js).toContain('dispatchEvent');
      expect(modules).toContain('productStickyPurchase');

      await openPdpWithSticky(page);
      await revealStickyDock(page);
      await expect(page.locator('[data-testid="product-sticky-purchase"]')).toBeVisible();
      await expect(page.locator('[data-testid="product-sticky-primary-image"]')).toBeAttached();

      const floatClear = await page.evaluate(() => {
        const dock = document.querySelector('[data-testid="product-sticky-purchase"]');
        if (!dock) {
          return false;
        }
        const dockTop = dock.getBoundingClientRect().top;
        const pet = document.querySelector('.w-store-music');
        const cs = document.querySelector('#customer-service-widget .cs-chat-button')
          || document.querySelector('#customer-service-widget');
        let ok = true;
        if (pet && pet.getBoundingClientRect().bottom > dockTop - 2) {
          ok = false;
        }
        if (cs && cs.getBoundingClientRect().bottom > dockTop - 2) {
          ok = false;
        }
        return ok;
      });
      expect(floatClear).toBeTruthy();

      await armProxyProbe(page);
      await page.evaluate(() => {
        var sticky = document.querySelector('[data-testid="product-sticky-add-to-cart"]');
        if (sticky) {
          sticky.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
        }
      });
      await expect
        .poll(async () => page.evaluate(() => window.__stickyProxyHit || 0), { timeout: 8000 })
        .toBeGreaterThan(0);
    }
  );
});
