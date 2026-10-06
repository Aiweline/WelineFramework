/**
 * 临时诊断：右簇扩展链 class 与 reserve 实测（跑完即删）。
 *
 * @weline-e2e-spec { module: Weline_Theme, type: e2e, layer: frontend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */
const {
  test,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Theme';
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com';

moduleDescribe(test, MODULE, 'tmp diag right cluster candidates', () => {
  test.setTimeout(120000);

  moduleCase(test, { module: MODULE, id: 'tmp-diag-3' }, '右簇候选识别', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`${BASE}/?diag_nav3=${Date.now()}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForFunction(
      () => {
        const w = document.getElementById('categories-overflow-wrapper');
        return !!(w && w.classList.contains('header-left-cluster-more'));
      },
      { timeout: 30000 }
    );
    await page.waitForTimeout(600);
    const out = await page.evaluate(() => {
      const right = document.querySelector('.header-nav-right-cluster');
      const ext = right
        ? Array.from(right.querySelectorAll(':scope > .header-nav-extension-link, :scope > .header-nav-extensions .header-nav-extension-link'))
        : [];
      const extSlot = right ? right.querySelector('.header-nav-extensions') : null;
      const navLinks = document.getElementById('nav-links-list');
      const left = document.querySelector('.header-nav-left-cluster');
      const leftStyle = left ? getComputedStyle(left) : null;
      return {
        extCount: ext.length,
        extSlotChildren: extSlot
          ? Array.from(extSlot.children).map((el) => ({
              tag: el.tagName,
              cls: String(el.className).slice(0, 60),
              w: +el.getBoundingClientRect().width.toFixed(1),
              text: (el.textContent || '').trim().slice(0, 12),
            }))
          : null,
        extSlotW: extSlot ? +extSlot.getBoundingClientRect().width.toFixed(1) : null,
        navLinksLi: navLinks ? navLinks.querySelectorAll(':scope > li').length : null,
        rightW: right ? +right.getBoundingClientRect().width.toFixed(1) : null,
        leftMaxWidth: leftStyle ? leftStyle.maxWidth : null,
        leftW: left ? +left.getBoundingClientRect().width.toFixed(1) : null,
      };
    });
    console.log('DIAG3-OUTPUT ' + JSON.stringify(out, null, 1));
  });
});
