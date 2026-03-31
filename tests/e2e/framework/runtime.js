const path = require('path');
const { execFileSync } = require('child_process');

const ROOT_DIR = path.resolve(__dirname, '../../..');
const RUNTIME_INFO_SCRIPT = path.join(__dirname, 'runtime-info.php');
const BACKEND_SESSION_BOOTSTRAP_SCRIPT = path.join(__dirname, 'backend-session-bootstrap.php');

let runtimeCache = null;

function isAbsoluteUrl(value) {
  return /^[a-z][a-z\d+.-]*:\/\//i.test(String(value || ''));
}

function ensureLeadingSlash(value) {
  const normalized = String(value || '').trim();
  if (!normalized) {
    return '/';
  }

  return normalized.startsWith('/') ? normalized : `/${normalized}`;
}

function joinPath(...parts) {
  const segments = parts
    .flat()
    .filter(part => part !== undefined && part !== null && String(part).trim() !== '')
    .map(part => String(part).replace(/^\/+|\/+$/g, ''));

  return `/${segments.join('/')}`.replace(/\/{2,}/g, '/');
}

function shouldUseProxy(options = {}) {
  if (options.useProxy === true) {
    return true;
  }
  if (options.useProxy === false) {
    return false;
  }

  return process.env.PLAYWRIGHT_DISABLE_PROXY !== '1';
}

function getRuntimeInfo(options = {}) {
  const { refresh = false } = options;
  if (!refresh && runtimeCache) {
    return runtimeCache;
  }

  const stdout = execFileSync('php', [RUNTIME_INFO_SCRIPT], {
    cwd: ROOT_DIR,
    env: process.env,
    encoding: 'utf8',
  });

  runtimeCache = JSON.parse(stdout);
  return runtimeCache;
}

function isLikelyFallbackPhpOrigin(origin) {
  try {
    const url = new URL(String(origin || ''));
    const port = Number(url.port || 0);
    return url.protocol === 'http:'
      && url.hostname === '127.0.0.1'
      && port >= 9991
      && port <= 19999;
  } catch (error) {
    return false;
  }
}

function getAdminSessionBootstrapModes(options = {}) {
  const runtime = getRuntimeInfo(options);
  const targetOrigin = runtime.runtime && runtime.runtime.target_origin
    ? runtime.runtime.target_origin
    : getBaseUrl(options);

  return isLikelyFallbackPhpOrigin(targetOrigin) ? ['fpm', 'wls'] : ['wls', 'fpm'];
}

function bootstrapAdminSession(mode, options = {}) {
  const username = options.username || process.env.PLAYWRIGHT_ADMIN_USERNAME || 'admin';
  const password = options.password || process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'admin';
  const stdout = execFileSync('php', [
    BACKEND_SESSION_BOOTSTRAP_SCRIPT,
    `--mode=${mode}`,
    `--username=${username}`,
    `--password=${password}`,
  ], {
    cwd: ROOT_DIR,
    env: process.env,
    encoding: 'utf8',
  });

  return JSON.parse(stdout);
}

async function applyAdminSessionCookie(page, sessionInfo, options = {}) {
  const runtime = getRuntimeInfo(options);
  const cookiePath = sessionInfo.cookie_path || '/';
  const cookieLifetime = Number(sessionInfo.cookie_lifetime || 86400 * 30);
  const expires = Math.floor(Date.now() / 1000) + cookieLifetime;
  const hostnames = new Set();

  for (const origin of [getBaseUrl(options), runtime.runtime?.target_origin, runtime.proxy?.origin]) {
    if (!origin) {
      continue;
    }

    try {
      hostnames.add(new URL(origin).hostname);
    } catch (error) {
      // Ignore invalid origins.
    }
  }

  const cookies = Array.from(hostnames).map(hostname => ({
    name: sessionInfo.session_name,
    value: sessionInfo.session_id,
    domain: hostname,
    path: cookiePath,
    httpOnly: true,
    secure: false,
    sameSite: 'Lax',
    expires,
  }));

  if (cookies.length > 0) {
    await page.context().addCookies(cookies);
  }
}

async function isBackendLoginPage(page) {
  if ((page.url() || '').includes('/admin/login')) {
    return true;
  }

  return page.locator('form[action*="/admin/login/post"]').first()
    .isVisible({ timeout: 1500 })
    .catch(() => false);
}

function parseGotoOptions(options = {}) {
  const normalized = { ...(options || {}) };
  const timeout = normalized.timeout || 30000;
  const requestedWaitUntil = normalized.waitUntil || 'domcontentloaded';
  const readySelector = normalized.readySelector === undefined ? 'body' : normalized.readySelector;
  const readyState = normalized.readyState === undefined
    ? (requestedWaitUntil === 'commit' ? 'domcontentloaded' : requestedWaitUntil)
    : normalized.readyState;
  const loadStateTimeout = normalized.loadStateTimeout || Math.min(timeout, 10000);
  const allowLoadStateTimeout = normalized.allowLoadStateTimeout !== false;
  const settleMs = normalized.settleMs || 0;

  delete normalized.readySelector;
  delete normalized.readyState;
  delete normalized.loadStateTimeout;
  delete normalized.allowLoadStateTimeout;
  delete normalized.settleMs;
  normalized.timeout = timeout;
  normalized.waitUntil = 'commit';

  return {
    gotoOptions: normalized,
    readySelector,
    readyState,
    loadStateTimeout,
    allowLoadStateTimeout,
    settleMs,
  };
}

async function gotoUrl(page, url, options = {}) {
  const {
    gotoOptions,
    readySelector,
    readyState,
    loadStateTimeout,
    allowLoadStateTimeout,
    settleMs,
  } = parseGotoOptions(options);

  const response = await page.goto(url, gotoOptions);

  if (readySelector) {
    await page.locator(readySelector).first().waitFor({
      state: 'attached',
      timeout: gotoOptions.timeout,
    });
  }

  if (readyState && readyState !== 'commit') {
    try {
      await page.waitForLoadState(readyState, { timeout: loadStateTimeout });
    } catch (error) {
      if (!allowLoadStateTimeout) {
        throw error;
      }
    }
  }

  if (settleMs > 0) {
    await page.waitForTimeout(settleMs);
  }

  return response;
}

function buildProxyUrl(input = '/', options = {}) {
  if (isAbsoluteUrl(input)) {
    return input;
  }

  const runtime = getRuntimeInfo(options);
  return new URL(ensureLeadingSlash(input), runtime.proxy.origin).toString();
}

function buildTargetUrl(input = '/', options = {}) {
  if (isAbsoluteUrl(input)) {
    return input;
  }

  const runtime = getRuntimeInfo(options);
  return new URL(ensureLeadingSlash(input), runtime.runtime.target_origin).toString();
}

function buildAccessibleUrl(input = '/', options = {}) {
  return shouldUseProxy(options)
    ? buildProxyUrl(input, options)
    : buildTargetUrl(input, options);
}

function buildTargetAreaUrl(pathKey, route = '', options = {}) {
  const runtime = getRuntimeInfo(options);
  const basePath = runtime.paths && runtime.paths[pathKey] ? runtime.paths[pathKey] : '/';
  return buildTargetUrl(joinPath(basePath, route), options);
}

function buildBackendPath(route = '', options = {}) {
  // 之前统一把后台路由包在 `/@backend/...` 里，能保证代理层稳定转发，
  // 但会让地址栏出现 `/@backend/...` 这种“测试占位符”。
  //
  // 这里做最小兼容：
  // - 当 route 已经是 `admin` 系列（如 `admin` / `admin/login`）时，直接使用 `/<route>`，
  //   代理端已有规则会把它前缀改写成真实 backend 前缀；
  // - 其它后台路由仍保留 `@backend`，避免代理端无法推断导致转发失败。
  const normalizedRoute = String(route ?? '').trim().replace(/^\/+|\/+$/g, '');
  if (normalizedRoute === 'admin' || normalizedRoute.startsWith('admin/')) {
    return joinPath(normalizedRoute);
  }

  return joinPath('@backend', normalizedRoute);
}

function buildApiPath(route = '') {
  return joinPath('@api', route);
}

/**
 * 前端「原生模块」REST 相对路径：/{frontendApiPrefix}/{moduleRouter}/rest/v1/{...segments}
 * 前缀来自 runtime-info.php → paths.frontend_api_prefix_path（与 app/etc env 中 area_routes.rest_frontend.prefix 一致）。
 * WeShop ApiBridge 使用 {@link buildApiPath}（@api → .../api/rest/v1/weshop/...），勿与此混用。
 */
function buildFrontendModuleRestPath(moduleRouter, ...segmentsAfterV1) {
  const runtime = getRuntimeInfo();
  let prefix = String(runtime.paths?.frontend_api_prefix_path ?? '/api').trim();
  prefix = prefix.replace(/^\/+|\/+$/g, '');
  if (!prefix) {
    prefix = 'api';
  }
  const router = String(moduleRouter ?? '').replace(/^\/+|\/+$/g, '');
  if (!router) {
    throw new Error('buildFrontendModuleRestPath: moduleRouter is required');
  }
  return joinPath(prefix, router, 'rest', 'v1', ...segmentsAfterV1);
}

/** @param {string} moduleName 模块名，如 WeShop_B2B（对应 app/etc/modules.php 键） */
function getModuleFrontendRouter(moduleName) {
  const runtime = getRuntimeInfo();
  const row = runtime.modules?.routers?.[moduleName];
  const r = String(row?.router ?? '').trim();
  if (!r) {
    throw new Error(
      `getModuleFrontendRouter: missing router for module "${moduleName}" in E2E runtime (modules.routers).`,
    );
  }
  return r;
}

/** @param {string} moduleName 模块名；REST 段与路由表一致（控制器段 + 动作段） */
function buildFrontendModuleRestPathForModule(moduleName, ...segmentsAfterV1) {
  return buildFrontendModuleRestPath(getModuleFrontendRouter(moduleName), ...segmentsAfterV1);
}

/** @param {string} moduleName 模块名；优先 backend_router，缺省回退 router */
function getModuleBackendRouter(moduleName) {
  const runtime = getRuntimeInfo();
  const row = runtime.modules?.routers?.[moduleName];
  const r = String(row?.backend_router ?? row?.router ?? '').trim();
  if (!r) {
    throw new Error(
      `getModuleBackendRouter: missing backend_router for module "${moduleName}" in E2E runtime (modules.routers).`,
    );
  }
  return r;
}

/**
 * 后台 PC 路由片段（不含全局 backend 前缀），供 gotoBackend 第二个参数使用。
 * 例：WeShop_B2B + credit → b2b/backend/credit
 */
function buildModuleBackendRoute(moduleName, ...pathSegments) {
  const full = joinPath(getModuleBackendRouter(moduleName), 'backend', ...pathSegments);
  return full.startsWith('/') ? full.slice(1) : full;
}

function buildBackendApiPath(route = '') {
  return joinPath('@backend-api', route);
}

function buildBackendUrl(route = '', options = {}) {
  return shouldUseProxy(options)
    ? buildProxyUrl(buildBackendPath(route), options)
    : buildTargetAreaUrl('backend_prefix_path', route, options);
}

function buildApiUrl(route = '', options = {}) {
  return shouldUseProxy(options)
    ? buildProxyUrl(buildApiPath(route), options)
    : buildTargetAreaUrl('frontend_api_prefix_path', route, options);
}

function buildBackendApiUrl(route = '', options = {}) {
  return shouldUseProxy(options)
    ? buildProxyUrl(buildBackendApiPath(route), options)
    : buildTargetAreaUrl('backend_api_prefix_path', route, options);
}

function getActiveTheme(area = 'frontend', options = {}) {
  const runtime = getRuntimeInfo(options);
  const activeThemes = runtime.themes && runtime.themes.active ? runtime.themes.active : {};
  return activeThemes[area] || activeThemes.global || null;
}

function getActiveThemeId(area = 'frontend', options = {}) {
  const theme = getActiveTheme(area, options);
  return theme ? Number(theme.id || 0) : 0;
}

function buildThemePreviewPath(previewOptions = {}) {
  const themeId = Number(previewOptions.themeId || getActiveThemeId('frontend'));
  if (!themeId) {
    throw new Error('No active frontend theme id available in E2E runtime info.');
  }

  const params = new URLSearchParams();
  params.set('preview_theme', String(themeId));
  params.set('page_type', String(previewOptions.pageType || 'homepage'));
  params.set('preview_mode', String(previewOptions.previewMode || 'live'));
  params.set('status', String(previewOptions.status || 'draft'));

  if (previewOptions.autoLogin !== undefined) {
    params.set('auto_login', String(previewOptions.autoLogin));
  }
  if (previewOptions.scope) {
    params.set('scope', String(previewOptions.scope));
  }
  if (previewOptions.versionId) {
    params.set('version_id', String(previewOptions.versionId));
  }
  if (previewOptions.editorArea) {
    params.set('editor_area', String(previewOptions.editorArea));
  }

  return `/theme/frontend/theme-preview/gateway?${params.toString()}`;
}

function buildThemePreviewUrl(previewOptions = {}, options = {}) {
  return buildAccessibleUrl(buildThemePreviewPath(previewOptions), options);
}

function getBaseUrl(options = {}) {
  const runtime = getRuntimeInfo(options);
  return shouldUseProxy(options) ? runtime.proxy.origin : runtime.runtime.target_origin;
}

function getBackendRoot(options = {}) {
  const runtime = getRuntimeInfo(options);
  return buildAccessibleUrl(runtime.paths.backend_prefix_path, options);
}

function deriveBackendRoot(currentUrl, options = {}) {
  if (!currentUrl) {
    return getBackendRoot(options);
  }

  try {
    const runtime = getRuntimeInfo(options);
    const url = new URL(currentUrl);
    const backendPrefixPath = ensureLeadingSlash(runtime.paths.backend_prefix_path).replace(/\/+$/, '');
    const path = url.pathname;

    if (!path.startsWith(`${backendPrefixPath}/`)) {
      return getBackendRoot(options);
    }

    const afterPrefix = path.slice(backendPrefixPath.length + 1);
    const segments = afterPrefix.split('/').filter(Boolean);
    // /{backendKey}/admin/{currency}/{language}/...（登录后常见）
    // lastIndexOf('/admin') 会误截成仅 /{backendKey}，导致 E2E 拼接模块路径缺货币/语言段
    if (segments[0] === 'admin' && segments.length >= 3) {
      const currencySeg = segments[1];
      const languageSeg = segments[2];
      return `${url.origin}${backendPrefixPath}/${currencySeg}/${languageSeg}`;
    }

    const adminIndex = path.lastIndexOf('/admin');
    if (adminIndex !== -1) {
      return `${url.origin}${path.slice(0, adminIndex)}`;
    }
  } catch (error) {
    // Fall through to the default backend root below.
  }

  return getBackendRoot(options);
}

async function gotoFrontend(page, input = '/', options) {
  return gotoUrl(page, buildAccessibleUrl(input, options), options);
}

async function gotoBackend(page, route = '', options) {
  return gotoUrl(page, buildBackendUrl(route, options), options);
}

async function gotoApi(page, route = '', options) {
  return gotoUrl(page, buildApiUrl(route, options), options);
}

async function gotoThemePreview(page, previewOptions = {}, options) {
  return gotoUrl(page, buildThemePreviewUrl(previewOptions, options), options);
}

async function loginAsAdmin(page, options = {}) {
  const runtime = getRuntimeInfo({ refresh: options.refreshRuntime === true });
  const loginUrl = buildBackendUrl('admin/login', options);
  const username = options.username || process.env.PLAYWRIGHT_ADMIN_USERNAME || 'admin';
  const password = options.password || process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'admin';
  const timeout = options.timeout || 60000;
  let bootstrapFailure = null;

  for (const mode of (options.bootstrapModes || getAdminSessionBootstrapModes(options))) {
    try {
      const sessionInfo = bootstrapAdminSession(mode, options);
      await applyAdminSessionCookie(page, sessionInfo, options);
      await gotoBackend(page, 'admin', {
        waitUntil: 'domcontentloaded',
        timeout,
        settleMs: options.settleMs || 1000,
        // When caller forces proxy usage (e.g. PLAYWRIGHT_DISABLE_PROXY=1),
        // ensure all internal navigations stay consistent.
        useProxy: options.useProxy,
      });

      if (!(await isBackendLoginPage(page))) {
        return deriveBackendRoot(page.url(), options);
      }

      bootstrapFailure = new Error(`Admin session bootstrap mode "${mode}" did not reach an authenticated backend page.`);
    } catch (error) {
      bootstrapFailure = error;
    }
  }

  await page.goto(loginUrl, {
    waitUntil: 'domcontentloaded',
    timeout,
  });

  const usernameInput = page.locator('input[name="username"], input[type="text"]').first();
  const hasLoginForm = await usernameInput.isVisible({ timeout: 5000 }).catch(() => false);
  if (!hasLoginForm) {
    return deriveBackendRoot(page.url(), options);
  }

  const hasCaptcha = await page.locator('input[name="code"]').first().isVisible({ timeout: 1500 }).catch(() => false);
  if (hasCaptcha && bootstrapFailure) {
    throw bootstrapFailure;
  }

  await usernameInput.fill(username);
  await page.locator('input[name="password"], input[type="password"]').first().fill(password);
  const backendPrefixPath = `/${runtime.routes.backend}/`;

  await Promise.all([
    page.waitForURL(url => {
      const pathname = url.pathname || '';
      return pathname.startsWith(backendPrefixPath)
        && pathname.includes('/admin')
        && !pathname.includes('/admin/login');
    }, {
      timeout,
      waitUntil: 'commit',
    }),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);

  await page.waitForTimeout(options.settleMs || 1000);
  return deriveBackendRoot(page.url(), options);
}

module.exports = {
  buildApiPath,
  buildApiUrl,
  buildBackendApiPath,
  buildBackendApiUrl,
  buildBackendPath,
  buildBackendUrl,
  buildFrontendModuleRestPath,
  buildFrontendModuleRestPathForModule,
  buildModuleBackendRoute,
  deriveBackendRoot,
  buildProxyUrl,
  buildThemePreviewPath,
  buildThemePreviewUrl,
  buildTargetUrl,
  getActiveTheme,
  getActiveThemeId,
  getBackendRoot,
  getBaseUrl,
  getModuleBackendRouter,
  getModuleFrontendRouter,
  getRuntimeInfo,
  gotoUrl,
  gotoApi,
  gotoBackend,
  gotoFrontend,
  gotoThemePreview,
  loginAsAdmin,
};
