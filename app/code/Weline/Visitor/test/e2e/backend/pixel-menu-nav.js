/**
 * 像素分析侧栏菜单导航（WebUI e2e 入口必须从菜单开始，禁止仅靠深链假装可达）。
 *
 * DOM 约定（MenuRenderService）：
 * - 分组：`#side-menu a[data-source][role=button].has-arrow`
 * - 叶子：`#side-menu a[data-source][href]`（真实后台 URL）
 *
 * 注意：图标侧栏 / vertical-collpsed 下 metisMenu 不初始化，点击不会改 aria-expanded；
 * 须先展开侧栏，再逐级展开「数据工具 → 像素分析」。
 */
const {
  BACKEND_FATAL_PATTERN,
  ensureBackendSidebarExpanded,
  expandBackendMenuGroup,
  openBackendMenuBySource,
} = require('../../../../../../../tests/e2e/framework');

const FATAL = BACKEND_FATAL_PATTERN;

/** @type {const} */
const PIXEL_MENU = {
  dataTools: 'Weline_Backend::data_tools_group',
  group: 'Weline_Visitor::pixel_dashboard',
  index: 'Weline_Visitor::pixel_dashboard_index',
  list: 'Weline_Visitor::pixel_dashboard_list',
  archiveList: 'Weline_Visitor::pixel_dashboard_archive_list',
  trafficChannel: 'Weline_Visitor::traffic_channel',
};

/** @type {Record<string, string>} */
const PIXEL_MENU_TITLES = {
  [PIXEL_MENU.index]: '事件看板',
  [PIXEL_MENU.list]: '热表明细',
  [PIXEL_MENU.archiveList]: '冷归档明细',
  [PIXEL_MENU.trafficChannel]: '流量渠道',
};

/**
 * @param {import('@playwright/test').Page} page
 */
async function ensureExpandedSidebar(page) {
  return ensureBackendSidebarExpanded(page);
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {string} sourceId
 */
async function expandSidebarGroup(page, sourceId) {
  return expandBackendMenuGroup(page, sourceId);
}

/**
 * 确保「数据工具 → 像素分析」分组已展开，叶子菜单可见。
 * @param {import('@playwright/test').Page} page
 */
async function revealPixelMenuGroup(page) {
  await ensureExpandedSidebar(page);
  await expect(page.locator('#side-menu')).toBeVisible({ timeout: 20000 });
  await expandSidebarGroup(page, PIXEL_MENU.dataTools);
  await expandSidebarGroup(page, PIXEL_MENU.group);
}

/**
 * 侧栏搜索菜单（图标侧栏兜底路径，仍属菜单级入口）。
 * @param {import('@playwright/test').Page} page
 * @param {string} title
 * @param {string} [leafSource]
 */
async function openPixelMenuViaSearch(page, title, leafSource) {
  return openBackendMenuBySource(page, leafSource, { title });
}

/**
 * 从侧栏点击像素叶子菜单并等待后台壳就绪。
 * @param {import('@playwright/test').Page} page
 * @param {string} leafSource data-source，如 PIXEL_MENU.index
 * @param {{ urlIncludes?: string|RegExp }} [options]
 */
async function openPixelSidebarMenu(page, leafSource, options = {}) {
  return openBackendMenuBySource(page, leafSource, {
    ...options,
    title: PIXEL_MENU_TITLES[leafSource] || leafSource,
    parentSources: [PIXEL_MENU.dataTools, PIXEL_MENU.group],
    urlIncludes: options.urlIncludes || '/visitor/backend/',
  });
}

module.exports = {
  FATAL,
  PIXEL_MENU,
  PIXEL_MENU_TITLES,
  ensureExpandedSidebar,
  expandSidebarGroup,
  revealPixelMenuGroup,
  openPixelMenuViaSearch,
  openPixelSidebarMenu,
};
