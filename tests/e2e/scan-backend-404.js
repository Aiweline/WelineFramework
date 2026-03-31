/* eslint-disable no-console */
// Scan Weline backend pages and record those that return 404.
// NOTE: This script only reads current server responses; it does not change WLS lifecycle.

'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { chromium } = require('playwright');

const ROOT_DIR = path.resolve(__dirname, '../..');
const APP_CODE_DIR = path.join(ROOT_DIR, 'app', 'code');
const MODULES_FILE = path.join(ROOT_DIR, 'app', 'etc', 'modules.php'); // for reference only

const RUNTIME_INFO_SCRIPT = path.join(__dirname, 'framework', 'runtime-info.php');
const BACKEND_SESSION_BOOTSTRAP_SCRIPT = path.join(__dirname, 'framework', 'backend-session-bootstrap.php');

const DEFAULT_CONCURRENCY = 50;
const DEFAULT_TIMEOUT_MS = 60000;

function lowerCamelCase(input) {
  const s = String(input || '');
  if (!s) return '';
  return s.charAt(0).toLowerCase() + s.slice(1);
}

function fileBaseName(filePath) {
  return path.basename(filePath, '.php');
}

function joinUrl(base, routeFragment) {
  const baseStr = String(base || '').replace(/\/+$/g, '');
  const frag = String(routeFragment || '').replace(/^\/+/g, '');
  if (!frag) return baseStr + '/';
  return `${baseStr}/${frag}`;
}

function shouldExcludeAction(actionName) {
  const a = String(actionName || '');
  if (!a) return false;

  // Heuristic: skip "mutation"/"post" handlers and record-required views.
  // Goal: maximize "open in browser without extra params".
  const excluded = new Set([
    'Post',
    'post',
    'Save',
    'save',
    'Delete',
    'delete',
    'Remove',
    'remove',
    'Update',
    'update',
    'Edit',
    'edit',
    'View',
    'view',
    'Logout',
    'logout',
    'Reject',
    'reject',
    'Approve',
    'approve',
    'Confirm',
    'confirm',
    'Cancel',
    'cancel',
    'Verification',
    'verification',
    'VerificationCode',
    'verificationCode',
    'Verificationcode',
    'verificationcode',
    'Check',
    'check',
    'Verification-code',
    'verification-code',
    'BaseController',
    'baseController',
  ]);
  return excluded.has(a);
}

function parsePublicMethodNamesFromPhpFile(content) {
  const text = String(content || '');
  const re = /public\s+function\s+([A-Za-z0-9_]+)\s*\(/g;
  const out = new Set();
  let m;
  while ((m = re.exec(text)) !== null) {
    const name = String(m[1] || '');
    if (!name) continue;
    if (name === '__construct') continue;
    out.add(name);
  }
  return [...out];
}

function listPhpFilesRecursive(startDir) {
  const out = [];
  const stack = [startDir];
  while (stack.length) {
    const dir = stack.pop();
    let entries = [];
    try {
      entries = fs.readdirSync(dir, { withFileTypes: true });
    } catch (e) {
      continue;
    }
    for (const ent of entries) {
      const full = path.join(dir, ent.name);
      if (ent.isDirectory()) {
        stack.push(full);
        continue;
      }
      if (ent.isFile() && ent.name.endsWith('.php')) {
        out.push(full);
      }
    }
  }
  return out;
}

function generateRouteFragmentsFromModule(moduleDir, moduleName, backendRouter) {
  const routeFragments = [];
  const controllerDir = path.join(moduleDir, 'Controller');
  // Keep candidate scan scope small: only actions likely to be real page entries.
  const allowedActionSegments = new Set([
    'index',
    'listing',
    'add',
    'dashboard',
    'config',
    'setting',
    'preview',
    'full',
    'header',
    'content',
    'footer',
    'stylePreview',
    'login',
    'logout',
    'register',
    'search',
    'history',
    'domain',
  ]);

  // 1) Controller/Backend/...  -> <backendRouter>/backend/...
  const backendControllerDir = path.join(controllerDir, 'Backend');
  if (fs.existsSync(backendControllerDir)) {
    const phpFiles = listPhpFilesRecursive(backendControllerDir);
    for (const file of phpFiles) {
      const rel = path.relative(backendControllerDir, file).replace(/\\/g, '/');
      const parts = rel.split('/');
      const base = parts[parts.length - 1];
      const action = fileBaseName(base);

      // Skip obvious mutation handlers.
      if (shouldExcludeAction(action) === true) continue;

      const afterBackend = parts.slice(0, -1); // directories before filename
      const controllerSegments = afterBackend.map(lowerCamelCase);

      // Controller/Backend/<Controller>.php (depth 0 after Backend/): parse public methods as actions.
      if (controllerSegments.length === 0) {
        const controllerSlug = lowerCamelCase(action);
        if (controllerSlug.includes('baseController') || controllerSlug === 'baseController') continue;

        // Special case: Controller/Backend/Index.php -> base route is /{backendRouter}/backend
        // and public getIndex() is usually mapped to the default "index" action.
        const content = (() => {
          try {
            return fs.readFileSync(file, 'utf8');
          } catch (e) {
            return '';
          }
        })();
        const methods = parsePublicMethodNamesFromPhpFile(content);

        for (const methodName of methods) {
          // Skip POST handlers; scan is GET-only.
          if (/^post/i.test(methodName)) continue;

          // Weline convention: getX() maps to route action "x"
          let actionSeg = '';
          if (/^get/i.test(methodName)) {
            actionSeg = lowerCamelCase(methodName.slice(3));
          } else {
            actionSeg = lowerCamelCase(methodName);
          }
          if (!actionSeg) continue;
          if (shouldExcludeAction(actionSeg) === true) continue;
          // Reduce false positives and avoid huge candidate expansion.
          if (!allowedActionSegments.has(actionSeg)) continue;

          if (controllerSlug === 'index') {
            // Controller/Backend/Index.php
            if (actionSeg === 'index') {
              routeFragments.push(`${backendRouter}/backend`);
            } else {
              routeFragments.push(`${backendRouter}/backend/${actionSeg}`);
            }
          } else {
            if (actionSeg === 'index') {
              routeFragments.push(`${backendRouter}/backend/${controllerSlug}`);
            } else {
              routeFragments.push(`${backendRouter}/backend/${controllerSlug}/${actionSeg}`);
            }
          }
        }

        continue;
      }

      // Controller/Backend/<Controller>/Index.php
      if (action === 'Index' && controllerSegments.length === 1) {
        routeFragments.push(`${backendRouter}/backend/${controllerSegments[0]}`);
        continue;
      }

      // Controller/Backend/<Controller>/<Action>.php
      if (controllerSegments.length >= 1) {
        const lastController = controllerSegments[0]; // most modules are 1-level deep here
        if (action === 'Index' && controllerSegments.length === 1) {
          routeFragments.push(`${backendRouter}/backend/${lastController}`);
        } else if (action !== 'Index') {
          // Only support 1 controller segment to reduce false positives.
          if (controllerSegments.length === 1) {
            routeFragments.push(`${backendRouter}/backend/${lastController}/${lowerCamelCase(action)}`);
          }
        }
      }
    }
  }

  // 2) Controller/...  -> <backendRouter>/...
  // Includes core modules like Weline_Admin where routes are like /admin/login.
  if (fs.existsSync(controllerDir)) {
    const phpFiles = listPhpFilesRecursive(controllerDir);
    for (const file of phpFiles) {
      const rel = path.relative(controllerDir, file).replace(/\\/g, '/');
      const parts = rel.split('/');

      // Ignore non-backend controllers to avoid noise.
      const bannedDirSegments = new Set([
        'Backend',
        'Frontend',
        'Api',
        'Rest',
        'Console',
      ]);
      if (parts.some(p => bannedDirSegments.has(p))) continue;

      const action = fileBaseName(parts[parts.length - 1]);
      if (shouldExcludeAction(action) === true) continue;
      if (String(action || '').toLowerCase().includes('basecontroller')) continue;

      // Controller/Index.php  -> /{backendRouter}
      if (parts.length === 1 && action === 'Index') {
        routeFragments.push(`${backendRouter}`);
        continue;
      }

      // Controller/Login.php -> /{backendRouter}/login
      if (parts.length === 1 && action !== 'Index') {
        routeFragments.push(`${backendRouter}/${lowerCamelCase(action)}`);
        continue;
      }

      // Controller/<Controller>/<Action>.php
      if (parts.length === 2) {
        const controllerSeg = lowerCamelCase(parts[0]);
        if (action === 'Index') {
          routeFragments.push(`${backendRouter}/${controllerSeg}`);
        } else {
          routeFragments.push(`${backendRouter}/${controllerSeg}/${lowerCamelCase(action)}`);
        }
      }
    }
  }

  // De-dup
  return [...new Set(routeFragments.filter(Boolean))];
}

function isRouteFragmentSane(routeFragment) {
  const s = String(routeFragment || '').trim();
  if (!s) return false;
  // Avoid accidental absolute fragments or empty slashes.
  if (s.includes('..')) return false;
  if (s.startsWith('//')) return false;
  return true;
}

function buildCandidateRoutes() {
  const runtime = JSON.parse(execFileSync('php', [RUNTIME_INFO_SCRIPT], {
    cwd: ROOT_DIR,
    env: process.env,
    encoding: 'utf8',
  }));

  const moduleRouters = runtime.modules?.routers || {};
  const candidateRoutes = new Set();

  const vendorDirs = fs.readdirSync(APP_CODE_DIR, { withFileTypes: true })
    .filter(d => d.isDirectory())
    .map(d => d.name);

  for (const vendor of vendorDirs) {
    const vendorPath = path.join(APP_CODE_DIR, vendor);
    const moduleDirs = fs.readdirSync(vendorPath, { withFileTypes: true })
      .filter(d => d.isDirectory())
      .map(d => d.name);
    for (const module of moduleDirs) {
      const moduleName = `${vendor}_${module}`;
      const meta = moduleRouters[moduleName] || null;
      const backendRouter = meta?.backend_router ? String(meta.backend_router).trim() : '';
      if (!backendRouter) continue;

      const moduleDir = path.join(vendorPath, module);
      const routeFragments = generateRouteFragmentsFromModule(moduleDir, moduleName, backendRouter);
      for (const rf of routeFragments) {
        if (isRouteFragmentSane(rf)) candidateRoutes.add(rf);
      }
    }
  }

  return {
    runtime,
    routes: [...candidateRoutes],
  };
}

function bootstrapBackendSessionCookie() {
  const runtime = JSON.parse(execFileSync('php', [RUNTIME_INFO_SCRIPT], {
    cwd: ROOT_DIR,
    env: process.env,
    encoding: 'utf8',
  }));

  // Prefer WLS mode to match runtime.instance_name/default.
  // The bootstrap script checks user credentials and generates a session for backend only.
  const mode = process.env.PLAYWRIGHT_BOOTSTRAP_MODE || 'wls';
  const session = JSON.parse(execFileSync('php', [
    BACKEND_SESSION_BOOTSTRAP_SCRIPT,
    `--mode=${mode}`,
    `--username=${process.env.PLAYWRIGHT_ADMIN_USERNAME || 'admin'}`,
    `--password=${process.env.PLAYWRIGHT_ADMIN_PASSWORD || 'admin'}`,
  ], {
    cwd: ROOT_DIR,
    env: process.env,
    encoding: 'utf8',
  }));

  return {
    runtime,
    cookie: {
      name: session.session_name,
      value: session.session_id,
      path: session.cookie_path || '/',
      httpOnly: true,
      secure: false,
      sameSite: 'Lax',
      domain: new URL(runtime.runtime.target_origin).hostname,
      expires: Math.floor(Date.now() / 1000) + Number(session.cookie_lifetime || 86400 * 30),
    },
  };
}

async function scan404() {
  const concurrency = Number(process.env.SCAN_BACKEND_CONCURRENCY || DEFAULT_CONCURRENCY);
  const timeoutMs = Number(process.env.SCAN_BACKEND_TIMEOUT_MS || DEFAULT_TIMEOUT_MS);

  const { runtime, routes } = buildCandidateRoutes();
  const backendPrefixPath = runtime.paths.backend_prefix_path || '';
  const targetOrigin = runtime.runtime.target_origin || '';
  const backendRoot = joinUrl(targetOrigin, backendPrefixPath);

  const scanOutDir = path.join(__dirname, 'results');
  if (!fs.existsSync(scanOutDir)) fs.mkdirSync(scanOutDir, { recursive: true });

  const outFile = path.join(
    scanOutDir,
    `backend-404-scan-${new Date().toISOString().slice(0, 10)}.json`,
  );

  console.log(`[scan] candidates=${routes.length} concurrency=${concurrency} backendRoot=${backendRoot}`);

  const { cookie } = bootstrapBackendSessionCookie();

  const browser = await chromium.launch({
    headless: true,
  });

  const context = await browser.newContext({
    viewport: { width: 1366, height: 768 },
  });

  // Inject backend session cookie so pages should render instead of redirecting to /admin/login.
  await context.addCookies([cookie]);

  const results = [];
  let cursor = 0;

  const workers = Array.from({ length: concurrency }, () => (async () => {
    const page = await context.newPage();
    while (true) {
      const idx = cursor;
      cursor += 1;
      if (idx >= routes.length) break;
      const routeFragment = routes[idx];
      const url = joinUrl(backendRoot, routeFragment);

      try {
        const response = await page.goto(url, {
          timeout: timeoutMs,
          waitUntil: 'domcontentloaded',
        });

        const status = response ? response.status() : null;

        // Heuristic: treat as 404 if HTTP status is 404, or body contains 404-not-found patterns.
        let bodyText = '';
        try {
          bodyText = await page.locator('body').innerText({ timeout: 3000 });
        } catch (e) {
          bodyText = '';
        }

        const is404 =
          status === 404
          || /404\s+Not\s+Found/i.test(bodyText)
          || /页面不存在|未找到|资源未找到/i.test(bodyText)
          || page.url().includes('/404');

        if (is404) {
          results.push({
            route: routeFragment,
            url,
            status,
            finalUrl: page.url(),
            title: await page.title().catch(() => ''),
          });
        }
      } catch (error) {
        // navigation timeout/network error: ignore for this "404 only" scan.
      }
    }
    await page.close();
  })());

  await Promise.all(workers);
  await browser.close();

  fs.writeFileSync(outFile, JSON.stringify({
    generatedAt: new Date().toISOString(),
    backendRoot,
    concurrency,
    candidateCount: routes.length,
    resultsCount: results.length,
    results,
  }, null, 2), 'utf8');

  console.log(`[scan] done resultsCount=${results.length} outFile=${outFile}`);
}

scan404().catch((e) => {
  console.error('[scan] fatal', e);
  process.exit(1);
});

