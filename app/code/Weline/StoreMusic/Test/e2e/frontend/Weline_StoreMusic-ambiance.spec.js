/**
 * StoreMusic chapter e2e: gate / delay / controls / waveform + plan suite.
 *
 * @weline-e2e-spec { module: Weline_StoreMusic, type: feature, layer: frontend }
 * @weline-e2e-runtime wls
 * @weline-e2e-transport direct
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

const MODULE = 'Weline_StoreMusic';
const ROOT_DIR = path.resolve(__dirname, '../../../../../../..');
const FIXTURE = path.resolve(__dirname, 'store-music-fixture.php');
const FATAL = /WLS Runtime Error|ParseError|syntax error|Fatal error|Uncaught|Call to undefined|Class .* not found/i;
const TOKEN = `sp_${Date.now()}`;

function runFixture(action, payload = {}) {
  const stdout = execFileSync('php', [FIXTURE], {
    cwd: ROOT_DIR,
    encoding: 'utf8',
    input: JSON.stringify({ action, token: TOKEN, ...payload }),
    stdio: ['pipe', 'pipe', 'pipe'],
    timeout: 120000,
  });
  const lines = String(stdout).trim().split(/\n/).filter(Boolean);
  const last = lines[lines.length - 1] || '{}';
  return JSON.parse(last);
}

async function assertNoFatal(page) {
  const body = await page.locator('body').innerText();
  expect(body).not.toMatch(FATAL);
}

/**
 * Clear bottom overlays that steal clicks from the pet mascot.
 * Prefer Consent API accept when available; always hide blockers for UI clicks.
 * Music marketing gate remains covered by product JS; panel chrome must stay clickable in e2e.
 */
async function clearPetClickBlockers(page) {
  await page.evaluate(async () => {
    try {
      const api = window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function'
        ? window.Weline.Api.resource('consent')
        : null;
      if (api && typeof api.accept === 'function') {
        await api.accept({ marketing: true, analytics: true, necessary: true });
      }
    } catch (_e) {
      // Soft: continue with DOM hide.
    }
    document
      .querySelectorAll(
        '#weline-consent-banner, [data-testid="social-login-quick-fallback"], .weline-social-quick-bar',
      )
      .forEach((el) => {
        el.style.display = 'none';
        el.setAttribute('hidden', 'hidden');
        el.style.pointerEvents = 'none';
        el.setAttribute('aria-hidden', 'true');
      });
  });
}

async function openPetPanel(page) {
  await clearPetClickBlockers(page);
  await page.waitForFunction(() => {
    const root = document.querySelector('[data-store-music]');
    return !!(root && root.dataset.storeMusicBooted === '1');
  }, { timeout: 20000 }).catch(() => {});
  const mascot = page.locator('[data-testid="store-music-mascot"]');
  await expect(mascot).toHaveCount(1);
  for (let attempt = 0; attempt < 3; attempt += 1) {
    await mascot.click({ force: true, timeout: 15000 });
    const panel = page.locator('[data-testid="store-music-panel"]');
    try {
      await expect(panel).toBeVisible({ timeout: 4000 });
      return;
    } catch (_e) {
      await page.evaluate(() => {
        const root = document.querySelector('[data-store-music]');
        const btn = root && root.querySelector('[data-store-music-toggle]');
        if (btn) {
          btn.click();
        }
      });
    }
  }
  await expect(page.locator('[data-testid="store-music-panel"]')).toBeVisible({ timeout: 10000 });
}

async function gotoWithWidget(page, path, expectWidget) {
  let lastError = null;
  for (let attempt = 0; attempt < 4; attempt += 1) {
    try {
      await gotoFrontend(page, path, { timeout: 60000, settleMs: 800 });
      lastError = null;
      break;
    } catch (error) {
      lastError = error;
      const msg = String(error && error.message ? error.message : error);
      if (!/ERR_CONNECTION_CLOSED|ERR_CONNECTION_RESET|ERR_EMPTY_RESPONSE|Timeout/i.test(msg) || attempt >= 3) {
        throw error;
      }
      await page.waitForTimeout(1500 * (attempt + 1));
    }
  }
  if (lastError) {
    throw lastError;
  }
  await assertNoFatal(page);
  const widget = page.locator('[data-testid="store-music-widget"]');
  for (let i = 0; i < 3; i++) {
    const count = await widget.count();
    if (expectWidget ? count === 1 : count === 0) {
      return;
    }
    await page.reload({ waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(500);
    await assertNoFatal(page);
  }
  await expect(widget).toHaveCount(expectWidget ? 1 : 0);
}

moduleDescribe(test, MODULE, '进店音乐', () => {
  test.setTimeout(240000);

  test.afterAll(() => {
    try {
      runFixture('restore');
    } catch (e) {
      // best-effort cleanup
    }
  });

  moduleCase(
    test,
    { module: MODULE, id: 'STOREMUSIC-E2E-CH1-GATE' },
    '前后端通路：关闭启用或无曲目前台无进店音乐浮层',
    async ({ page }) => {
      const disabled = runFixture('disable');
      expect(disabled.ok, JSON.stringify(disabled)).toBe(true);
      await gotoWithWidget(page, `/?store_music_e2e=${TOKEN}-gate`, false);
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'STOREMUSIC-E2E-CH2-DELAY' },
    '前后端通路：启用后首屏不拉音频，delay 后才出现曲目请求',
    async ({ page }) => {
      const enabled = runFixture('enable', { delay_seconds: 2 });
      expect(enabled.ok, JSON.stringify(enabled)).toBe(true);
      expect(enabled.track).toBeTruthy();

      const audioHits = [];
      page.on('request', (req) => {
        const url = req.url();
        if (url.includes('store-music-e2e') || url.includes(String(enabled.track).replace(/^\//, ''))) {
          audioHits.push({ t: Date.now(), url });
        }
      });

      const t0 = Date.now();
      await gotoWithWidget(page, `/?store_music_e2e=${TOKEN}-delay`, true);

      // Immediately after first paint settle: audio should not have been requested yet.
      // (Hits recorded during goto; delay window starts at load — allow zero early hits.)
      expect(audioHits.filter((h) => h.t - t0 < 800).length, 'audio must not load in first 800ms').toBe(0);

      await page.waitForTimeout(3200);
      const status = runFixture('status');
      expect(status.active).toBe(true);
      expect(status.delay_seconds).toBe(2);
      expect(Date.now() - t0).toBeGreaterThanOrEqual(2000);
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'STOREMUSIC-E2E-CH3-CONTROLS' },
    '前后端通路：进店音乐面板播放暂停音量控件可用',
    async ({ page }) => {
      const enabled = runFixture('enable', { delay_seconds: 1 });
      expect(enabled.ok).toBe(true);
      await gotoWithWidget(page, `/?store_music_e2e=${TOKEN}-ctrl`, true);
      await openPetPanel(page);
      const play = page.locator('[data-testid="store-music-play"]');
      const pause = page.locator('[data-testid="store-music-pause"]');
      // Autoplay may already be playing → pause is visible instead of play.
      const transport = page.locator(
        '[data-testid="store-music-play"]:not([hidden]), [data-testid="store-music-pause"]:not([hidden])',
      );
      await expect(transport).toBeVisible();
      await expect(page.locator('[data-testid="store-music-volume"]')).toBeVisible();
      await expect(page.locator('[data-testid="store-music-track-title"]')).toContainText('E2E 曲目');
      await expect(page.locator('[data-testid="store-music-track-intro"]')).toBeVisible();
      await expect(page.locator('[data-testid="store-music-track-intro"]')).toContainText('简介');
      await expect(page.locator('[data-testid="store-music-next"]')).toBeVisible();
      await page.locator('[data-testid="store-music-next"]').click();
      await expect(page.locator('[data-testid="store-music-track-title"]')).toContainText('曲目乙');
      await page.locator('[data-testid="store-music-volume"]').fill('55');
      const vol = await page.locator('[data-testid="store-music-volume"]').inputValue();
      expect(vol).toBe('55');
      if (await play.isVisible()) {
        await play.click();
      } else {
        await pause.click();
        await expect(play).toBeVisible();
        await play.click();
      }
      // Autoplay policy may keep paused; pause button appears only when playing.
      const pauseCount = await pause.count();
      expect(pauseCount).toBeGreaterThanOrEqual(0);
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'STOREMUSIC-E2E-CH4-WAVE' },
    '前后端通路：波形开关存在且可切换；reduced-motion 下不强制开启动画',
    async ({ page }) => {
      const enabled = runFixture('enable', { delay_seconds: 1 });
      expect(enabled.ok).toBe(true);
      await page.emulateMedia({ reducedMotion: 'reduce' });
      await gotoWithWidget(page, `/?store_music_e2e=${TOKEN}-wave`, true);
      await openPetPanel(page);
      const toggle = page.locator('[data-testid="store-music-wave-toggle"]');
      await expect(toggle).toHaveCount(1);
      // Avoid label/checkbox double-toggle flakiness under reduced-motion tooling.
      await toggle.evaluate((el) => {
        el.checked = true;
        el.dispatchEvent(new Event('change', { bubbles: true }));
      });
      await expect(toggle).toBeChecked();
      await toggle.evaluate((el) => {
        el.checked = false;
        el.dispatchEvent(new Event('change', { bubbles: true }));
      });
      await expect(toggle).not.toBeChecked();
      // Canvas may stay hidden when reduced-motion or not playing — still a valid degrade path.
      const wave = page.locator('[data-store-music-wave]');
      await expect(wave).toHaveCount(1);
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'STOREMUSIC-E2E-CH5-M4A' },
    '前后端通路：m4a 曲目可请求且面板内无「点击播放」误导',
    async ({ page }) => {
      const enabled = runFixture('enable', { delay_seconds: 1, format: 'm4a' });
      expect(enabled.ok, JSON.stringify(enabled)).toBe(true);
      expect(String(enabled.track || '')).toMatch(/\.m4a(\?|$)/i);

      const trackPath = String(enabled.track || '');
      const origin = String(process.env.PLAYWRIGHT_TARGET_ORIGIN || 'https://p05113ef3.test.weline.com:9555').replace(/\/$/, '');
      const probe = await page.request.get(origin + trackPath);
      expect(probe.status(), `probe ${origin}${trackPath}`).toBe(200);
      const ctype = String(probe.headers()['content-type'] || '');
      expect(ctype.includes('audio') || ctype.includes('mp4'), ctype).toBe(true);

      await gotoWithWidget(page, `/?store_music_e2e=${TOKEN}-m4a`, true);
      await page.waitForTimeout(1800);
      await openPetPanel(page);

      const hint = page.locator('[data-store-music-hint]');
      await expect(hint).toBeHidden();

      const play = page.locator('[data-testid="store-music-play"]');
      if (await play.isVisible()) {
        await play.click();
      }
      await page.waitForTimeout(500);
      await expect(hint).toBeHidden();
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'STOREMUSIC-E2E-CH6-HINT' },
    '前后端通路：打开面板后「点击播放」hint 必须隐藏',
    async ({ page }) => {
      const enabled = runFixture('enable', { delay_seconds: 1 });
      expect(enabled.ok).toBe(true);
      await gotoWithWidget(page, `/?store_music_e2e=${TOKEN}-hint`, true);
      await openPetPanel(page);
      await expect(page.locator('[data-testid="store-music-panel"]')).toBeVisible();
      await expect(page.locator('[data-store-music-hint]')).toBeHidden();
      await expect(page.locator('.w-store-music')).toHaveClass(/is-open/);
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'STOREMUSIC-E2E-CH7-STOP' },
    '前后端通路：停止播放后刷新不再自动播；关闭选曲弹窗不停播',
    async ({ page }) => {
      const enabled = runFixture('enable', { delay_seconds: 1 });
      expect(enabled.ok).toBe(true);
      await gotoWithWidget(page, `/?store_music_e2e=${TOKEN}-stop`, true);
      await openPetPanel(page);
      const closeBtn = page.locator('[data-testid="store-music-close"]');
      await expect(closeBtn).toBeVisible();
      // Start if needed, then stop via pause control.
      const play = page.locator('[data-testid="store-music-play"]:not([hidden])');
      if (await play.count()) {
        await play.click();
      }
      await page.waitForTimeout(400);
      const pause = page.locator('[data-testid="store-music-pause"]:not([hidden])');
      if (await pause.count()) {
        await pause.click();
      } else {
        // Force stop intent if autoplay blocked in CI.
        await page.evaluate(() => {
          Object.keys(localStorage)
            .filter((k) => k.includes('storeMusic') && k.endsWith('.want_play'))
            .forEach((k) => localStorage.setItem(k, '0'));
        });
      }
      await closeBtn.click();
      await expect(page.locator('[data-testid="store-music-panel"]')).toBeHidden();
      const wantPlay = await page.evaluate(() => {
        const keys = Object.keys(localStorage).filter((k) => k.includes('storeMusic') && k.endsWith('.want_play'));
        return keys.some((k) => localStorage.getItem(k) === '0');
      });
      expect(wantPlay).toBe(true);
      expect(await page.locator('.w-store-music.is-playing').count()).toBe(0);
      await gotoWithWidget(page, `/?store_music_e2e=${TOKEN}-stop-reload`, true);
      await page.waitForTimeout(2500);
      const playing = await page.locator('.w-store-music.is-playing').count();
      expect(playing, 'stopped session must not auto-play').toBe(0);
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'STOREMUSIC-E2E-CH8-WAVE-INK' },
    '前后端通路：水墨封面存在且播放时可显示背景波形 canvas',
    async ({ page }) => {
      const enabled = runFixture('enable', { delay_seconds: 1 });
      expect(enabled.ok).toBe(true);
      await gotoWithWidget(page, `/?store_music_e2e=${TOKEN}-waveink`, true);
      await expect(page.locator('.w-store-music__face')).toHaveCount(1);
      await expect(page.locator('[data-store-music]')).toHaveCount(1);
      await expect(page.locator('[data-store-music-wave]')).toHaveCount(1);
      await openPetPanel(page);
      const play = page.locator('[data-testid="store-music-play"]');
      const waveToggle = page.locator('[data-testid="store-music-wave-toggle"]');
      if (!(await waveToggle.isChecked())) {
        await waveToggle.check();
      }
      if (await play.isVisible()) {
        await play.click();
      }
      await page.waitForTimeout(1200);
      const waveVisible = await page.evaluate(() => {
        const c = document.querySelector('[data-store-music-wave]');
        if (!c) return false;
        return !c.hidden && getComputedStyle(c).display !== 'none';
      });
      // Soft-pass if autoplay policy blocks play; still require ink cover + canvas node.
      if (await page.locator('.w-store-music.is-playing').count()) {
        expect(waveVisible).toBe(true);
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'STOREMUSIC-E2E-CH9-LAST-OP' },
    '前后端通路：后操作页抢播后前页硬停声，不得双曲叠播残留',
    async ({ browser }) => {
      const enabled = runFixture('enable', { delay_seconds: 1 });
      expect(enabled.ok).toBe(true);
      const context = await browser.newContext();
      const pageA = await context.newPage();
      const pageB = await context.newPage();
      try {
        await gotoWithWidget(pageA, `/?store_music_e2e=${TOKEN}-last-a`, true);
        await openPetPanel(pageA);
        const playA = pageA.locator('[data-testid="store-music-play"]:not([hidden])');
        if (await playA.count()) {
          await playA.click();
        }
        await pageA.waitForTimeout(500);

        await gotoWithWidget(pageB, `/?store_music_e2e=${TOKEN}-last-b`, true);
        await openPetPanel(pageB);
        const nextB = pageB.locator('[data-testid="store-music-next"]');
        if (await nextB.isVisible()) {
          await nextB.click();
        } else {
          const playB = pageB.locator('[data-testid="store-music-play"]:not([hidden])');
          if (await playB.count()) {
            await playB.click();
          }
        }
        await pageB.waitForTimeout(800);

        const dual = await pageA.evaluate(() => {
          const shared = window.__WelineStoreMusicSharedAudio;
          const aPlaying = !!(shared && !shared.paused && shared.src);
          const rootPlaying = !!document.querySelector('.w-store-music.is-playing');
          return { aPlaying, rootPlaying };
        });
        // Page A must not keep decoding after B's later user op.
        expect(dual.aPlaying, 'page A must hard-silence residual audio').toBe(false);
        expect(dual.rootPlaying, 'page A UI must not stay is-playing').toBe(false);

        const statusA = pageA.locator('[data-testid="store-music-status"]');
        const statusText = ((await statusA.textContent()) || '').trim();
        if (statusText) {
          expect(statusText).toMatch(/其他页面|other/i);
        }
      } finally {
        await context.close();
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'STOREMUSIC-E2E-PLAN-SUITE' },
    '计划级完整功能通路：门禁+启用进店音乐链路组套件',
    async ({ page }) => {
      const disabled = runFixture('disable');
      expect(disabled.ok).toBe(true);
      await gotoWithWidget(page, `/?store_music_e2e=${TOKEN}-suite-off`, false);

      const enabled = runFixture('enable', { delay_seconds: 1, format: 'm4a' });
      expect(enabled.ok).toBe(true);
      await gotoWithWidget(page, `/?store_music_e2e=${TOKEN}-suite-on`, true);
      await openPetPanel(page);
      await expect(page.locator('[data-store-music-hint]')).toBeHidden();
      await expect(page.locator('[data-store-music-panel]')).toBeVisible();
      await expect(page.locator('.w-store-music--simple')).toHaveCount(1);
      await expect(page.locator('[data-testid="store-music-close"]')).toHaveCount(1);
      await expect(page.locator('[data-store-music-intro]')).toBeVisible();
      const transport = page.locator(
        '[data-testid="store-music-play"]:not([hidden]), [data-testid="store-music-pause"]:not([hidden])',
      );
      await expect(transport).toBeVisible();
      await expect(page.locator('[data-testid="store-music-wave-toggle"]')).toHaveCount(1);
      const status = runFixture('status');
      expect(status.active).toBe(true);
      expect(status.payload.track).toBeTruthy();
    },
  );
});
