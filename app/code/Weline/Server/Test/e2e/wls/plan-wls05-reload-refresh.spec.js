/**
 * 万能商城内核计划：WLS reload 后新代码生效 / 旧 worker 退场 / 会话不串（TEST-WLS-05）
 *
 * 决定性证据（不依赖页面表象）：
 * 1) 新代码生效：worker 的 Static L1 命中不做 filemtime 校验，磁盘改动在 reload 前必须仍返回旧内容，
 *    reload 后必须返回新内容 —— 由此证明「reload 才是生效边界」，而不是浏览器缓存假绿。
 * 2) 旧 worker 不再服务：`X-WLS-Benchmark-Worker: 1` 返回 `X-WLS-Worker-PID`，reload 前后 PID 集合必须完全不相交。
 * 3) 会话不串：reload 后新标签页强制刷新仍为已登录后台；同时全新 browser context 必须落到登录页。
 *
 * DEF-WLS-05-01：浏览器持空闲 keep-alive 时，优雅 `server:reload`（无 `-f`）也必须完成滚动替换（退出码 0）。
 * Worker 软期限到点后主动关闭空闲 keep-alive；Master 等待时间 = Worker 软期限 + margin。
 * 有真实在途写缓冲/Fiber 时仍不得截断（该路径另测）；`-f` 仅作停机型逃生口，本用例主路径不再依赖它。
 *
 * 前置：`PLAYWRIGHT_INSTANCE_NAME` 必须是本任务专用 `ai-test-*` 实例（禁止 reload 生产 9501）。
 *
 * @weline-e2e-spec { module: Weline_Server, type: plan, layer: wls }
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const {
  test,
  expect,
  loginAsAdmin,
  gotoBackend,
  getRuntimeInfo,
  buildTargetUrl,
  waitForBackendShellReady,
  moduleDescribe,
  moduleCase,
} = require('../../../../../../../tests/e2e/framework');

const MODULE = 'Weline_Server';
const DIRECT = { useProxy: false };
const FATAL_PATTERN = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const INSTANCE = String(process.env.PLAYWRIGHT_INSTANCE_NAME || '').trim();
const MARKER_TOKEN = `${Date.now().toString(36)}${process.pid.toString(36)}`;
const MARKER_DIR = path.join(ROOT_DIR, 'pub', 'ai-test-wls05');
const MARKER_FILE = path.join(MARKER_DIR, `marker-${MARKER_TOKEN}.js`);
const MARKER_URI = `/ai-test-wls05/marker-${MARKER_TOKEN}.js`;
const MARKER_V1 = `window.__WLS05_MARKER__='V1-${MARKER_TOKEN}';`;
const MARKER_V2 = `window.__WLS05_MARKER__='V2-${MARKER_TOKEN}';`;

function writeMarker(content) {
  fs.mkdirSync(MARKER_DIR, { recursive: true });
  fs.writeFileSync(MARKER_FILE, `${content}\n`, 'utf8');
}

function removeMarker() {
  fs.rmSync(MARKER_FILE, { force: true });
  try {
    if (fs.readdirSync(MARKER_DIR).length === 0) {
      fs.rmdirSync(MARKER_DIR);
    }
  } catch (error) {
    // 目录被其它并发用例占用时保留即可
  }
}

function runWlsCli(args) {
  try {
    return execFileSync('php', ['bin/w', ...args], {
      cwd: ROOT_DIR,
      env: process.env,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    });
  } catch (error) {
    const stdout = String(error.stdout || '');
    const stderr = String(error.stderr || '');
    throw new Error(`bin/w ${args.join(' ')} 失败(status=${error.status}):\nSTDOUT:\n${stdout}\nSTDERR:\n${stderr}`);
  }
}

/** 优雅 reload 必须成功；失败时带出完整输出便于对照 DEF-WLS-05-01。 */
function runWlsCliAllowFailure(args) {
  try {
    const stdout = execFileSync('php', ['bin/w', ...args], {
      cwd: ROOT_DIR,
      env: process.env,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    });

    return { status: 0, output: String(stdout) };
  } catch (error) {
    return {
      status: Number(error.status ?? -1),
      output: `${String(error.stdout || '')}${String(error.stderr || '')}`,
    };
  }
}

/** 静态资源必须走无条件 GET，否则会落到 worker 的完整校验路径而绕开 Static L1。 */
async function fetchMarker(request) {
  const response = await request.get(buildTargetUrl(MARKER_URI, DIRECT), {
    headers: { 'Cache-Control': 'no-store' },
    timeout: 30000,
  });

  return {
    status: response.status(),
    body: (await response.text()).trim(),
    staticCache: String(response.headers()['x-wls-static-cache'] || ''),
  };
}

async function collectWorkerPids(request, probes) {
  const pids = new Set();
  for (let i = 0; i < probes; i += 1) {
    const response = await request.get(buildTargetUrl(`/?__wls05Probe=${MARKER_TOKEN}-${i}`, DIRECT), {
      headers: { 'X-WLS-Benchmark-Worker': '1' },
      timeout: 30000,
    });
    const pid = String(response.headers()['x-wls-worker-pid'] || '').trim();
    if (pid !== '') {
      pids.add(pid);
    }
  }

  return pids;
}

async function waitForOriginReady(request, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  let lastStatus = 0;
  while (Date.now() < deadline) {
    try {
      const response = await request.get(buildTargetUrl(`/?__wls05Ready=${Date.now()}`, DIRECT), { timeout: 10000 });
      lastStatus = response.status();
      if (lastStatus > 0 && lastStatus < 500) {
        return lastStatus;
      }
    } catch (error) {
      lastStatus = 0;
    }
    await new Promise(resolve => setTimeout(resolve, 500));
  }

  return lastStatus;
}

async function isBackendLoginSurface(page) {
  if ((page.url() || '').includes('/admin/login')) {
    return true;
  }

  return page.locator('form[action*="/admin/login/post"], input[name="username"]').first()
    .isVisible({ timeout: 2000 })
    .catch(() => false);
}

moduleDescribe(test, MODULE, '计划 WLS reload 生效边界 Browser 用例', () => {
  test.setTimeout(300000);

  test.afterAll(() => {
    removeMarker();
  });

  moduleCase(
    test,
    { module: MODULE, id: 'TEST-WLS-05' },
    'reload 后新标签页强制刷新：新代码生效、旧 worker 退场、会话不丢也不串',
    async ({ page, context, browser }) => {
      test.skip(
        !INSTANCE.startsWith('ai-test-'),
        `TEST-WLS-05 需要本任务专用实例：请设置 PLAYWRIGHT_INSTANCE_NAME=ai-test-*（当前 "${INSTANCE || '未设置'}"），禁止 reload 生产实例`,
      );

      const runtime = getRuntimeInfo({ refresh: true });
      const targetOrigin = String(runtime.runtime && runtime.runtime.target_origin);
      expect(targetOrigin, 'target_origin 必须可用').toContain('127.0.0.1');

      const statusBefore = runWlsCli(['server:status', INSTANCE]);
      expect(statusBefore, `reload 前实例 ${INSTANCE} 必须在运行`).toContain('运行中');
      expect(statusBefore).toContain(INSTANCE);

      // —— reload 前：已登录后台 + worker 已缓存旧资源 ——
      await loginAsAdmin(page, { timeout: 90000, settleMs: 800, ...DIRECT });
      await gotoBackend(page, 'admin', { timeout: 60000, settleMs: 1000, ...DIRECT });
      await waitForBackendShellReady(page);
      expect(await isBackendLoginSurface(page), 'reload 前必须已登录后台').toBeFalsy();
      await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);

      writeMarker(MARKER_V1);
      const first = await fetchMarker(page.request);
      expect(first.status, `marker 首次请求应 200，实际 ${first.status}`).toBe(200);
      expect(first.body).toContain(`V1-${MARKER_TOKEN}`);

      const second = await fetchMarker(page.request);
      expect(second.staticCache.toUpperCase(), '第二次请求必须命中 worker Static L1').toBe('HIT');
      expect(second.body).toContain(`V1-${MARKER_TOKEN}`);

      // 磁盘换新内容：reload 之前 worker 必须仍然吐旧内容，否则本用例的生效边界断言无意义
      writeMarker(MARKER_V2);
      const staleAfterEdit = await fetchMarker(page.request);
      expect(staleAfterEdit.staticCache.toUpperCase(), 'reload 前仍应命中旧缓存').toBe('HIT');
      expect(
        staleAfterEdit.body,
        'reload 前 worker 必须仍返回旧内容（证明生效边界在 reload）',
      ).toContain(`V1-${MARKER_TOKEN}`);
      expect(staleAfterEdit.body).not.toContain(`V2-${MARKER_TOKEN}`);

      const pidsBefore = await collectWorkerPids(page.request, 8);
      expect(pidsBefore.size, '优雅 reload 前必须采到 worker PID').toBeGreaterThan(0);

      // —— 优雅 reload（浏览器持空闲 keep-alive）：DEF-WLS-05-01 要求完成替换 ——
      const graceful = runWlsCliAllowFailure(['server:reload', INSTANCE]);
      const gracefulCompleted = /滚动重启完成/.test(graceful.output);
      const gracefulDrainRetained = /drain timeout; live old Workers remain draining safely/.test(graceful.output);
      const gracefulSummary = `status=${graceful.status} completed=${gracefulCompleted} drain_retained=${gracefulDrainRetained}`;
      test.info().annotations.push({ type: 'wls05-graceful-reload', description: gracefulSummary });
      console.log(`[TEST-WLS-05] graceful reload -> ${gracefulSummary}`);
      expect(
        gracefulDrainRetained,
        `空闲 keep-alive 场景不得再走 drain timeout 保留旧 worker：\n${graceful.output}`,
      ).toBeFalsy();
      expect(graceful.status, `优雅 reload 必须零退出：\n${graceful.output}`).toBe(0);
      expect(gracefulCompleted, `优雅 reload 必须完成滚动重启：\n${graceful.output}`).toBeTruthy();

      const readyStatus = await waitForOriginReady(page.request, 90000);
      expect(readyStatus, `reload 后源站必须恢复可达，实际 ${readyStatus}`).toBeLessThan(500);
      expect(readyStatus).toBeGreaterThan(0);

      await gotoBackend(page, 'admin', { timeout: 60000, settleMs: 800, ...DIRECT });
      await waitForBackendShellReady(page);
      expect(await isBackendLoginSurface(page), '优雅 reload 后原会话必须仍然有效').toBeFalsy();
      await expect(page.locator('body')).not.toContainText(FATAL_PATTERN);

      // —— reload 后：新代码生效 ——
      const afterReload = await fetchMarker(page.request);
      expect(afterReload.status).toBe(200);
      expect(afterReload.body, 'reload 后必须返回新内容').toContain(`V2-${MARKER_TOKEN}`);
      expect(afterReload.body).not.toContain(`V1-${MARKER_TOKEN}`);

      // —— reload 后：旧 worker 不再服务 ——
      const pidsAfter = await collectWorkerPids(page.request, 8);
      expect(pidsAfter.size, 'reload 后必须采到 worker PID').toBeGreaterThan(0);
      const survived = [...pidsAfter].filter(pid => pidsBefore.has(pid));
      expect(
        survived,
        `优雅 reload 后旧 worker 必须全部退场：before=${[...pidsBefore].join(',')} after=${[...pidsAfter].join(',')}`,
      ).toEqual([]);

      // —— reload 后：新标签页强制刷新仍是同一登录会话 ——
      const freshTab = await context.newPage();
      try {
        await gotoBackend(freshTab, 'admin', { timeout: 60000, settleMs: 1000, ...DIRECT });
        await freshTab.reload({ waitUntil: 'domcontentloaded', timeout: 60000 });
        await waitForBackendShellReady(freshTab);
        expect(await isBackendLoginSurface(freshTab), 'reload 后新标签页必须仍是已登录后台').toBeFalsy();
        await expect(freshTab.locator('body')).not.toContainText(FATAL_PATTERN);

        const tabMarker = await fetchMarker(freshTab.request);
        expect(tabMarker.body, '新标签页读到的也必须是新内容').toContain(`V2-${MARKER_TOKEN}`);
      } finally {
        await freshTab.close();
      }

      // —— reload 后：会话不串给新访客 ——
      const guestContext = await browser.newContext();
      try {
        const guestPage = await guestContext.newPage();
        await gotoBackend(guestPage, 'admin', { timeout: 60000, settleMs: 800, ...DIRECT });
        expect(
          await isBackendLoginSurface(guestPage),
          'reload 后全新 context 必须落到登录页（会话不得串给新访客）',
        ).toBeTruthy();
        await expect(guestPage.locator('body')).not.toContainText(FATAL_PATTERN);
      } finally {
        await guestContext.close();
      }

      const statusAfter = runWlsCli(['server:status', INSTANCE]);
      expect(statusAfter, `reload 后实例 ${INSTANCE} 必须仍在运行`).toContain('运行中');
      expect(statusAfter).toContain(INSTANCE);
    },
  );
});
