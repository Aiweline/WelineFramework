// @weline-e2e-runtime fallback
// @ts-check
const {
  test,
  expect,
  gotoFrontend,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Theme';
const FORBIDDEN_UI_RESOURCE = /(?:^|[/._-])(bootstrap|jquery|require(?:js)?|vue|metismenu|simplebar|waves|sweetalert2|select2|parsley|inputmask)(?:[./_-]|$)/i;

async function measureFloating(locator) {
  return locator.evaluate((element) => {
    const rect = element.getBoundingClientRect();
    const visual = window.visualViewport;
    return {
      left: rect.left,
      top: rect.top,
      right: rect.right,
      bottom: rect.bottom,
      width: rect.width,
      height: rect.height,
      viewportLeft: visual?.offsetLeft || 0,
      viewportTop: visual?.offsetTop || 0,
      viewportRight: (visual?.offsetLeft || 0) + (visual?.width || document.documentElement.clientWidth),
      viewportBottom: (visual?.offsetTop || 0) + (visual?.height || document.documentElement.clientHeight),
      clientWidth: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
    };
  });
}

function expectInsideViewport(rect, padding = 8) {
  expect(rect.left).toBeGreaterThanOrEqual(rect.viewportLeft + padding - 1);
  expect(rect.top).toBeGreaterThanOrEqual(rect.viewportTop + padding - 1);
  expect(rect.right).toBeLessThanOrEqual(rect.viewportRight - padding + 1);
  expect(rect.bottom).toBeLessThanOrEqual(rect.viewportBottom - padding + 1);
  expect(rect.scrollWidth).toBeLessThanOrEqual(rect.clientWidth);
}

moduleDescribe(test, MODULE, 'Weline UI 浮层边界与移动导航', () => {
  moduleCase(
    test,
    { module: MODULE, id: 'THEME-FLOATING-FE-001' },
    '移动导航稳定限界，嵌套菜单只关闭顶层且无禁用依赖',
    async ({ page }) => {
      const forbiddenRequests = [];
      page.on('request', (request) => {
        const pathname = new URL(request.url()).pathname;
        if (FORBIDDEN_UI_RESOURCE.test(pathname)) forbiddenRequests.push(pathname);
      });

      await page.setViewportSize({ width: 375, height: 700 });
      await gotoFrontend(page, '/policy/privacy', { waitUntil: 'domcontentloaded', timeout: 60000 });
      await page.waitForFunction(() => !!window.Weline?.UI);

      const trigger = page.locator('.w-frontend-mobile-nav [data-w-popover-trigger]');
      const panel = page.locator('.w-frontend-mobile-nav__panel');
      await expect(trigger).toBeVisible();
      expect((await trigger.boundingBox())?.height || 0).toBeGreaterThanOrEqual(44);

      const repeated = [];
      for (let cycle = 0; cycle < 3; cycle += 1) {
        await trigger.click();
        await expect(panel).toHaveAttribute('data-state', 'open');
        const rect = await measureFloating(panel);
        expectInsideViewport(rect, 12);
        repeated.push(rect);
        if (cycle < 2) {
          await panel.locator('[data-w-popover-close]').click();
        } else {
          await page.keyboard.press('Escape');
        }
        await expect(panel).toBeHidden();
        await expect(trigger).toBeFocused();
      }
      for (const rect of repeated.slice(1)) {
        expect(Math.abs(rect.left - repeated[0].left)).toBeLessThanOrEqual(1);
        expect(Math.abs(rect.top - repeated[0].top)).toBeLessThanOrEqual(1);
        expect(Math.abs(rect.width - repeated[0].width)).toBeLessThanOrEqual(1);
      }

      await trigger.click();
      await expect(panel).toHaveAttribute('data-state', 'open');
      const outsidePoint = await panel.evaluate((element) => {
        const rect = element.getBoundingClientRect();
        return {
          x: Math.max(1, rect.left - 4),
          y: Math.min(window.innerHeight - 4, rect.bottom + 8),
        };
      });
      await page.mouse.click(outsidePoint.x, outsidePoint.y);
      await expect(panel).toBeHidden();
      await expect(trigger).toHaveAttribute('aria-expanded', 'false');

      await trigger.click();
      await expect(panel).toHaveAttribute('data-state', 'open');
      await page.setViewportSize({ width: 700, height: 375 });
      expectInsideViewport(await measureFloating(panel), 12);
      await panel.locator('[data-w-popover-close]').click();

      await page.setViewportSize({ width: 768, height: 900 });
      await trigger.click();
      expectInsideViewport(await measureFloating(panel), 12);
      await panel.locator('[data-w-popover-close]').click();

      for (const width of [1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(trigger).toBeHidden();
        await expect(page.locator('.w-frontend-nav__desktop')).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
      }

      await page.setViewportSize({ width: 375, height: 700 });
      await page.evaluate(() => {
        const harness = document.createElement('div');
        harness.id = 'w-floating-nested-harness';
        harness.style.cssText = 'position:fixed;inset-block-start:5rem;inset-inline-end:.5rem;z-index:1';
        harness.innerHTML = `
          <div data-w-component="popover" data-w-placement="bottom-end" data-w-anchor-mode="element">
            <button id="w-nested-parent-trigger" class="w-button" type="button"
                    data-w-popover-trigger aria-expanded="false">Parent</button>
            <div id="w-nested-parent-panel" class="w-popover" data-w-popover-panel
                 data-state="closed" role="dialog" aria-hidden="true" hidden>
              <button id="w-nested-parent-action" class="w-button" type="button">Parent action</button>
              <div data-w-component="menu" data-w-placement="right-start" data-w-anchor-mode="element">
                <button id="w-nested-child-trigger" class="w-button" type="button"
                        data-w-menu-trigger aria-expanded="false">Child</button>
                <div id="w-nested-child-panel" class="w-menu" data-w-menu-panel
                     data-state="closed" role="menu" aria-hidden="true" hidden>
                  <button id="w-nested-child-action" class="w-menu__item" role="menuitem" type="button">Action</button>
                </div>
              </div>
            </div>
          </div>`;
        document.body.append(harness);
        window.Weline.UI.mount(harness);
      });

      const parentTrigger = page.locator('#w-nested-parent-trigger');
      const parentPanel = page.locator('#w-nested-parent-panel');
      const childTrigger = page.locator('#w-nested-child-trigger');
      const childPanel = page.locator('#w-nested-child-panel');
      await parentTrigger.click();
      await childTrigger.click();
      await expect(childPanel).toHaveAttribute('data-state', 'open');
      expect((await childPanel.getAttribute('data-w-actual-placement')) || '').toMatch(/^left-/);
      expectInsideViewport(await measureFloating(childPanel));

      await page.locator('#w-nested-parent-action').click();
      await expect(childPanel).toBeHidden();
      await expect(parentPanel).toHaveAttribute('data-state', 'open');
      await childTrigger.click();
      await expect(childPanel).toHaveAttribute('data-state', 'open');

      await page.locator('#w-nested-child-action').click();
      await expect(childPanel).toBeHidden();
      await expect(parentPanel).toHaveAttribute('data-state', 'open');

      await childTrigger.click();
      await page.keyboard.press('Escape');
      await expect(childPanel).toBeHidden();
      await expect(parentPanel).toHaveAttribute('data-state', 'open');
      await page.keyboard.press('Escape');
      await expect(parentPanel).toBeHidden();
      await expect(parentTrigger).toBeFocused();

      expect(await page.evaluate(() => ({
        jQuery: typeof window.jQuery,
        bootstrap: typeof window.bootstrap,
        Swal: typeof window.Swal,
      }))).toEqual({
        jQuery: 'undefined',
        bootstrap: 'undefined',
        Swal: 'undefined',
      });
      expect(forbiddenRequests).toEqual([]);

      await parentTrigger.click();
      await childTrigger.click();
      await expect(childPanel).toHaveAttribute('data-state', 'open');
      await page.evaluate(() => {
        const harness = document.getElementById('w-floating-nested-harness');
        if (harness) {
          window.Weline.UI.unmount(harness);
          harness.remove();
        }
      });
      await expect(parentPanel).toHaveCount(0);
      await expect(childPanel).toHaveCount(0);
      await expect(page.locator('[data-w-floating-portal]')).toHaveCount(0);
    },
  );
});
