const { expect } = require('@playwright/test');
const { waitForBackendShellReady } = require('./runtime');

const BACKEND_FATAL_PATTERN =
  /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;

function sourceSelector(sourceId, element = '') {
  const value = String(sourceId || '').replace(/\\/g, '\\\\').replace(/"/g, '\\"');
  return `${element}[data-source="${value}"]`;
}

async function ensureBackendSidebarExpanded(page) {
  const sidebar = page.locator('#side-menu, .vertical-menu').first();
  await sidebar.waitFor({ state: 'attached', timeout: 20000 });

  const desktopCollapsed = (await page.locator('body.vertical-collpsed').count()) > 0;
  if (await sidebar.isVisible().catch(() => false) && !desktopCollapsed) {
    return;
  }

  const toggle = page
    .locator('#vertical-menu-btn, button.vertical-menu-btn, .button-menu-mobile')
    .first();
  await expect(toggle, '收起态侧栏缺少展开按钮').toBeVisible({ timeout: 10000 });
  await toggle.click();
  await expect(sidebar, '后台侧栏无法展开').toBeVisible({ timeout: 10000 });
  if (desktopCollapsed) {
    await page.waitForFunction(
      () => !document.body.classList.contains('vertical-collpsed'),
      null,
      { timeout: 10000 }
    );
  }
  await page.waitForTimeout(500);
}

async function expandBackendMenuGroup(page, sourceId) {
  await ensureBackendSidebarExpanded(page);
  const trigger = page.locator(`#side-menu ${sourceSelector(sourceId, 'a')}`).first();
  await expect(trigger, `侧栏分组未出现: ${sourceId}`).toBeVisible({ timeout: 20000 });
  await trigger.scrollIntoViewIfNeeded();

  const item = page.locator(`#side-menu ${sourceSelector(sourceId, 'li')}`).first();
  const submenu = item.locator(':scope > ul.sub-menu').first();
  if ((await submenu.count()) === 0) {
    return;
  }

  const isOpen = async () => {
    const aria = await trigger.getAttribute('aria-expanded');
    if (aria === 'true') return true;
    return submenu.evaluate(
      (node) => node.classList.contains('mm-show') || getComputedStyle(node).display !== 'none'
    );
  };

  if (!(await isOpen())) {
    await trigger.click();
    await expect.poll(isOpen, {
      message: `侧栏分组无法展开: ${sourceId}`,
      timeout: 10000,
      intervals: [100, 200, 400, 800],
    }).toBe(true);
  }
}

async function discoverBackendMenuAncestors(page, sourceId) {
  const item = page.locator(`#side-menu ${sourceSelector(sourceId, 'li')}`).first();
  if ((await item.count()) === 0) {
    return [];
  }

  return item.evaluate((node) => {
    const sources = [];
    let parent = node.parentElement ? node.parentElement.closest('li[data-source]') : null;
    while (parent) {
      const source = parent.getAttribute('data-source');
      if (source) sources.unshift(source);
      parent = parent.parentElement ? parent.parentElement.closest('li[data-source]') : null;
    }
    return sources;
  });
}

async function openBackendMenuViaSearch(page, sourceId, title) {
  await ensureBackendSidebarExpanded(page);
  const searchLink = page
    .locator('#menu-search-link, #side-menu a:has-text("搜索菜单")')
    .first();
  if (await searchLink.isVisible().catch(() => false)) {
    if ((await searchLink.getAttribute('aria-expanded')) !== 'true') {
      await searchLink.click();
    }
  }

  const input = page.locator('#menu-search-input-menu, #menu-search-input').first();
  await expect(input, '搜索菜单输入框').toBeVisible({ timeout: 15000 });
  await input.fill(String(title || sourceId));
  await page.waitForTimeout(400);

  const result = page
    .locator(
      `.search-result-item ${sourceSelector(sourceId, 'a')}, `
      + `.search-results-list ${sourceSelector(sourceId, 'a')}`
    )
    .first();
  await expect(result, `菜单搜索无结果: ${title || sourceId}`).toBeVisible({ timeout: 10000 });
  await result.click();
}

function urlMatches(url, expected) {
  const pathname = String(url.pathname || '');
  if (pathname.includes('/admin/login')) return false;
  if (expected instanceof RegExp) return expected.test(pathname);
  if (expected) return pathname.includes(String(expected));
  return true;
}

async function openBackendMenuBySource(page, sourceId, options = {}) {
  const parentSources = options.parentSources
    || await discoverBackendMenuAncestors(page, sourceId);

  let link = page.locator(`#side-menu ${sourceSelector(sourceId, 'a')}`).first();
  let useSearch = options.forceSearch === true;
  try {
    if (!useSearch) {
      for (const parentSource of parentSources) {
        await expandBackendMenuGroup(page, parentSource);
      }
      await expect(link, `后台菜单叶子未出现: ${sourceId}`).toBeVisible({
        timeout: Number(options.timeout || 15000),
      });
    }
  } catch (error) {
    if (options.allowSearch === false) throw error;
    useSearch = true;
  }

  if (useSearch) {
    await openBackendMenuViaSearch(page, sourceId, options.title);
  } else {
    await link.scrollIntoViewIfNeeded();
    const href = String((await link.getAttribute('href')) || '').trim();
    expect(href, `菜单 ${sourceId} 缺少真实 href`).not.toBe('');
    expect(href, `菜单 ${sourceId} 不是可导航入口`).not.toMatch(/^(?:#|javascript:)/i);
    await link.click();
  }

  if (options.urlIncludes) {
    await page.waitForURL((url) => urlMatches(url, options.urlIncludes), {
      timeout: Number(options.navigationTimeout || 60000),
      waitUntil: 'domcontentloaded',
    });
  } else {
    await page.waitForLoadState('domcontentloaded', {
      timeout: Number(options.navigationTimeout || 60000),
    }).catch(() => {});
  }

  await waitForBackendShellReady(page);
  await expect(page.locator('body')).not.toContainText(BACKEND_FATAL_PATTERN);
  if (options.pageAnchor) {
    await expect(page.locator(options.pageAnchor).first(), `页面锚点缺失: ${options.pageAnchor}`)
      .toBeVisible({ timeout: Number(options.anchorTimeout || 20000) });
  }

  const expectedPageTitle = String(options.pageTitle || options.title || '').trim();
  if (expectedPageTitle) {
    const pageTitles = await page.evaluate(() => {
      const headings = Array.from(document.querySelectorAll(
        'main h1, main h2, main h3, main h4, main h5, '
        + '.main-content h1, .main-content h2, .main-content h3, '
        + '.page-title, [data-page-title]'
      ))
        .filter((node) => {
          const style = window.getComputedStyle(node);
          return style.visibility !== 'hidden' && style.display !== 'none';
        })
        .map((node) => (node.textContent || '').replace(/\s+/g, ' ').trim())
        .filter(Boolean);
      return [document.title.trim(), ...headings].filter(Boolean);
    });
    expect(
      pageTitles.some((candidate) => candidate.includes(expectedPageTitle)),
      `页面标题未包含“${expectedPageTitle}”: ${pageTitles.join(' | ') || '(empty)'}`
    ).toBe(true);
  }
}

async function collectBackendMenuSnapshot(page) {
  await ensureBackendSidebarExpanded(page);
  return page.locator('#side-menu li[data-source]').evaluateAll((items) => items.map((item) => {
    const anchor = item.querySelector(':scope > a');
    const titleNode = anchor || item.querySelector(':scope > span');
    const submenu = item.querySelector(':scope > ul.sub-menu');
    const parent = item.parentElement ? item.parentElement.closest('li[data-source]') : null;
    return {
      sourceId: item.getAttribute('data-source') || '',
      parentSource: parent ? parent.getAttribute('data-source') || '' : '',
      title: titleNode ? (titleNode.textContent || '').replace(/\s+/g, ' ').trim() : '',
      href: anchor ? anchor.getAttribute('href') || '' : '',
      isGroup: Boolean(submenu),
    };
  }));
}

async function expectBackendMenuTopology(page, manifest, options = {}) {
  const capabilities = Array.isArray(manifest) ? manifest : manifest.capabilities || [];
  const snapshot = await collectBackendMenuSnapshot(page);
  const counts = new Map();
  for (const row of snapshot) {
    counts.set(row.sourceId, (counts.get(row.sourceId) || 0) + 1);
  }

  const duplicateSources = [...counts.entries()].filter(([, count]) => count !== 1);
  expect(duplicateSources, '后台 DOM 存在重复 sourceId').toEqual([]);

  const duplicateKeys = new Map();
  for (const row of snapshot) {
    const key = `${row.parentSource}\u0000${row.title}`;
    duplicateKeys.set(key, (duplicateKeys.get(key) || 0) + 1);
  }
  expect(
    [...duplicateKeys.entries()].filter(([, count]) => count > 1),
    '同一菜单分组存在重复标题'
  ).toEqual([]);

  for (const capability of capabilities.filter((item) => item.layer !== 'non-ui')) {
    const rows = snapshot.filter((item) => item.sourceId === capability.sourceId);
    expect(rows, `菜单入口数量错误: ${capability.capabilityId}`).toHaveLength(1);
    const row = rows[0];
    expect(row.parentSource, `${capability.capabilityId} 父菜单错误`).toBe(capability.parentSource);
    expect(row.title, `${capability.capabilityId} 标题错误`).toContain(capability.title);
    expect(row.href, `${capability.capabilityId} 缺少真实链接`).not.toBe('');
    expect(row.href, `${capability.capabilityId} 不是可导航入口`).not.toMatch(/^(?:#|javascript:)/i);
  }

  if (options.exactSourceSet === true) {
    const actual = snapshot.filter((item) => !item.isGroup).map((item) => item.sourceId).sort();
    const expected = capabilities.filter((item) => item.layer !== 'non-ui')
      .map((item) => item.sourceId).sort();
    expect(actual).toEqual(expected);
  }

  return snapshot;
}

function installBackendBrowserGuards(page, options = {}) {
  const failures = [];
  const allowedStatuses = new Set(options.allowedStatuses || []);
  const allowedUrlPatterns = options.allowedUrlPatterns || [];
  const allowedResponses = options.allowedResponses || [];

  page.on('pageerror', (error) => failures.push(
    `pageerror at ${page.url()}: ${error.message}`
  ));
  page.on('console', (message) => {
    if (message.type() === 'error') {
      failures.push(`console at ${page.url()}: ${message.text()}`);
    }
  });
  page.on('response', (response) => {
    if (response.status() < 400 || allowedStatuses.has(response.status())) return;
    if (allowedUrlPatterns.some((pattern) => pattern.test(response.url()))) return;
    if (allowedResponses.some((rule) => {
      const statuses = new Set(rule.statuses || [rule.status]);
      return statuses.has(response.status()) && rule.pattern instanceof RegExp
        && rule.pattern.test(response.url());
    })) return;
    failures.push(`http ${response.status()} from ${page.url()}: ${response.url()}`);
  });

  return {
    failures,
    assertClean() {
      expect(failures, '后台页面存在未处理浏览器错误').toEqual([]);
    },
  };
}

module.exports = {
  BACKEND_FATAL_PATTERN,
  collectBackendMenuSnapshot,
  ensureBackendSidebarExpanded,
  expandBackendMenuGroup,
  expectBackendMenuTopology,
  installBackendBrowserGuards,
  openBackendMenuBySource,
};
