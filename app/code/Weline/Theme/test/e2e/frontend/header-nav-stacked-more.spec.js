/**
 * 主栏导航 More：两行全宽 + 单行左右互让；左簇整体 More（分类+政策，末尾挂载）。
 *
 * @weline-e2e-spec { module: Weline_Theme, type: e2e, layer: frontend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Theme';
const ROOT = path.resolve(__dirname, '../../../../../../..');
const BASE = process.env.WELINE_E2E_BASE_URL || 'https://p05113ef3.test.weline.com:9555';

function readNavState() {
  const inner = document.querySelector('.header-main-nav-inner');
  const rightCluster = document.querySelector('.header-nav-right-cluster');
  const leftCluster = document.querySelector('.header-nav-left-cluster');
  const navMore = document.getElementById('nav-more-wrapper');
  const catMore = document.querySelector('.categories-overflow-wrapper');
  const rightCandidates = rightCluster
    ? Array.from(rightCluster.querySelectorAll(
      ':scope > .header-nav-extension-link, :scope > .header-nav-extensions .header-nav-extension-link, #nav-links-list > li'
    ))
    : [];
  const catItems = Array.from(document.querySelectorAll('#categories-list > .category-item, .categories-list > .category-item'))
    .filter((el) => !el.classList.contains('categories-overflow-wrapper'));
  const policyUnits = Array.from(document.querySelectorAll(
    '.header-policy-links-slot .header-policy-links__inline, .header-policy-links-slot .header-policy-links__menu-wrap'
  ));
  const leftCandidates = catItems.concat(policyUnits);

  const moreIsVisible = (el) => {
    if (!el) return false;
    if (el.hidden) return false;
    const style = window.getComputedStyle(el);
    if (style.display === 'none' || style.visibility === 'hidden') return false;
    return el.getBoundingClientRect().width > 1;
  };

  const rightHidden = rightCandidates.filter((el) => (
    el.classList.contains('is-nav-overflow-hidden') || el.classList.contains('hidden')
  ));
  const leftHidden = leftCandidates.filter((el) => (
    el.classList.contains('is-nav-overflow-hidden') || el.classList.contains('hidden')
  ));
  const catHidden = catItems.filter((el) => el.classList.contains('hidden'));
  const rowThreshold = leftCluster
    ? Math.max(24, Math.floor((leftCluster.offsetHeight || 40) * 0.75))
    : 24;
  const topDelta = (leftCluster && rightCluster)
    ? Math.abs(leftCluster.offsetTop - rightCluster.offsetTop)
    : 0;

  const policySlot = document.querySelector('.header-policy-links-slot');
  const moreAfterPolicy = !!(catMore && leftCluster && catMore.parentElement === leftCluster
    && leftCluster.lastElementChild === catMore
    && (!policySlot || catMore.compareDocumentPosition(policySlot) & Node.DOCUMENT_POSITION_PRECEDING));

  return {
    stacked: !!(inner && inner.classList.contains('is-nav-stacked')),
    separateRows: !!(inner && inner.classList.contains('is-nav-stacked'))
      || topDelta >= rowThreshold,
    topDelta,
    rowThreshold,
    parentOk: !!(inner && rightCluster && rightCluster.parentElement === inner),
    rightWidth: rightCluster ? rightCluster.getBoundingClientRect().width : 0,
    rightMoreVisible: moreIsVisible(navMore),
    catMoreVisible: moreIsVisible(catMore),
    leftMoreAtClusterEnd: moreAfterPolicy,
    leftMoreHasClusterClass: !!(catMore && catMore.classList.contains('header-left-cluster-more')),
    rightCandidateCount: rightCandidates.length,
    rightHiddenCount: rightHidden.length,
    rightVisibleCount: rightCandidates.length - rightHidden.length,
    catItemCount: catItems.length,
    catHiddenCount: catHidden.length,
    leftCandidateCount: leftCandidates.length,
    leftHiddenCount: leftHidden.length,
  };
}

moduleDescribe(test, MODULE, 'header stacked nav more full-width', () => {
  test.setTimeout(180000);

  moduleCase(test, { module: MODULE, id: 'A-e2e-layout' }, '≤768 两行栈：More 可见当且仅当确有溢出项', async ({ page }) => {
    await page.setViewportSize({ width: 720, height: 900 });
    await page.goto(`${BASE}/?e2e_nav_more=${Date.now()}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(450);

    const state = await page.evaluate(readNavState);

    expect(state.stacked || state.separateRows).toBeTruthy();
    expect(state.parentOk).toBeTruthy();
    expect(state.leftMoreAtClusterEnd).toBeTruthy();
    expect(state.leftMoreHasClusterClass).toBeTruthy();
    if (state.rightCandidateCount > 0) {
      expect(state.rightMoreVisible).toBe(state.rightHiddenCount > 0);
      if (state.stacked || state.separateRows) {
        expect(state.rightWidth).toBeGreaterThan(100);
      }
    }
    if (state.leftCandidateCount > 0) {
      expect(state.catMoreVisible).toBe(state.leftHiddenCount > 0);
    }
    await expect(page.locator('body')).not.toContainText(/Fatal error|ParseError|WLS Runtime Error/i);
  });

  moduleCase(test, { module: MODULE, id: 'A2-desktop-yield' }, '单行宽屏：同行不误判分行，右簇不被挤扁', async ({ page }) => {
    await page.setViewportSize({ width: 1100, height: 900 });
    await page.goto(`${BASE}/?e2e_nav_yield=${Date.now()}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(500);

    const state = await page.evaluate(readNavState);

    expect(state.stacked).toBeFalsy();
    // 同行 offsetTop 抖动（如 11px）不得当成 separateRows
    expect(state.separateRows).toBeFalsy();
    expect(state.topDelta).toBeLessThan(state.rowThreshold);
    expect(state.parentOk).toBeTruthy();
    expect(state.leftMoreAtClusterEnd).toBeTruthy();
    if (state.rightCandidateCount >= 3) {
      // 互让生效：右簇应能露出多项，而不是被左簇撑到几十 px
      expect(state.rightWidth).toBeGreaterThan(120);
      expect(state.rightVisibleCount).toBeGreaterThanOrEqual(1);
      expect(state.rightMoreVisible).toBe(state.rightHiddenCount > 0);
    }
    if (state.leftCandidateCount > 0) {
      expect(state.catMoreVisible).toBe(state.leftHiddenCount > 0);
    }
  });

  moduleCase(test, { module: MODULE, id: 'A3-restore-no-hide-narrow' }, '收窄后再加宽：分类可吐回且不整块 hide-narrow', async ({ page }) => {
    await page.setViewportSize({ width: 800, height: 900 });
    await page.goto(`${BASE}/?e2e_nav_restore=${Date.now()}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(500);

    const narrow = await page.evaluate(() => {
      const cats = document.querySelector('.header-categories');
      const more = document.querySelector('.categories-overflow-wrapper');
      const leftCluster = document.querySelector('.header-nav-left-cluster');
      const items = Array.from(document.querySelectorAll('.categories-list > .category-item'))
        .filter((el) => !el.classList.contains('categories-overflow-wrapper'));
      const policyUnits = Array.from(document.querySelectorAll(
        '.header-policy-links-slot .header-policy-links__inline, .header-policy-links-slot .header-policy-links__menu-wrap'
      ));
      const leftCandidates = items.concat(policyUnits);
      const moreVis = more && !more.hidden && getComputedStyle(more).display !== 'none'
        && more.getBoundingClientRect().width > 1;
      return {
        hideNarrow: !!(cats && cats.classList.contains('hide-narrow')),
        catsDisplay: cats ? getComputedStyle(cats).display : '',
        hid: leftCandidates.filter((c) => c.classList.contains('hidden') || c.classList.contains('is-nav-overflow-hidden')).length,
        vis: leftCandidates.filter((c) => !c.classList.contains('hidden') && !c.classList.contains('is-nav-overflow-hidden')).length,
        moreVis: !!moreVis,
        moreAtEnd: !!(more && leftCluster && leftCluster.lastElementChild === more),
      };
    });
    expect(narrow.hideNarrow).toBeFalsy();
    expect(narrow.catsDisplay).not.toBe('none');
    expect(narrow.moreAtEnd).toBeTruthy();
    // 有溢出时必须露出 More，禁止整块消失
    if (narrow.hid > 0) {
      expect(narrow.moreVis).toBeTruthy();
    }

    await page.setViewportSize({ width: 1400, height: 900 });
    await page.waitForTimeout(700);
    const wide = await page.evaluate(() => {
      const cats = document.querySelector('.header-categories');
      const items = Array.from(document.querySelectorAll('.categories-list > .category-item'))
        .filter((el) => !el.classList.contains('categories-overflow-wrapper'));
      const policyUnits = Array.from(document.querySelectorAll(
        '.header-policy-links-slot .header-policy-links__inline, .header-policy-links-slot .header-policy-links__menu-wrap'
      ));
      const leftCandidates = items.concat(policyUnits);
      return {
        hideNarrow: !!(cats && cats.classList.contains('hide-narrow')),
        vis: leftCandidates.filter((c) => !c.classList.contains('hidden') && !c.classList.contains('is-nav-overflow-hidden')).length,
        hid: leftCandidates.filter((c) => c.classList.contains('hidden') || c.classList.contains('is-nav-overflow-hidden')).length,
        total: leftCandidates.length,
      };
    });
    expect(wide.hideNarrow).toBeFalsy();
    expect(wide.vis).toBeGreaterThan(narrow.vis);
    expect(wide.vis + wide.hid).toBe(wide.total);
  });

  moduleCase(test, { module: MODULE, id: 'B-source-contract' }, '源码契约：分行全宽 + 左簇整体 More', async () => {
    const header = fs.readFileSync(
      path.join(ROOT, 'app/code/Weline/Theme/view/theme/frontend/partials/header/default.phtml'),
      'utf8'
    );
    expect(header).toContain('function clustersOnSeparateRows()');
    expect(header).toContain('rowThreshold');
    expect(header).toContain('跳过互让');
    expect(header).toContain('clearHideNarrowAndReflow');
    expect(header).toContain('部分吐回');
    expect(header).toContain('function collectLeftOverflowCandidates()');
    expect(header).toContain('header-left-cluster-more');
    expect(header).toContain('政策已列入溢出候选，不再单独预留');
    expect(header).not.toContain('const shouldHide = width < 200');
    expect(header).toMatch(/function measureNavAvailableWidth\(\)\s*\{[\s\S]*?clustersOnSeparateRows\(\)/);
    expect(header).toMatch(/function measureCatAvailableWidth\(\)\s*\{[\s\S]*?clustersOnSeparateRows\(\)/);
  });

  moduleCase(test, { module: MODULE, id: 'e2e-plan-suite' }, '完整功能通路 e2e 汇总：Header stacked More 契约', async () => {
    const out = execFileSync(
      'php',
      [
        path.join(ROOT, 'vendor/phpunit/phpunit/phpunit'),
        '--configuration',
        path.join(ROOT, 'tests/phpunit/config.xml'),
        path.join(ROOT, 'app/code/Weline/Theme/test/Unit/ThemeHeaderMobileAmazonContractTest.php'),
      ],
      { cwd: ROOT, encoding: 'utf8' }
    );
    expect(out).toMatch(/OK/);
  });
});
