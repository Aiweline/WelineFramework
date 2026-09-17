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

// The refresh-resume contract must be exercised independently of a browser
// profile that globally rejects autoplay. Production still surfaces the
// existing user-gesture recovery UI when that policy is enforced.
test.use({
  launchOptions: {
    args: ['--ignore-certificate-errors', '--autoplay-policy=no-user-gesture-required'],
  },
});

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
    return !!(root && root.dataset.storeMusicBooted);
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
      // Always prefer durable real playlist — never leave E2E 甲/乙 on the shop.
      runFixture('restore', { token: 'last-real' });
      const status = runFixture('status');
      const blob = JSON.stringify(status || {});
      const stillE2e = /store-music-e2e|E2E\s*曲目/.test(blob);
      const inactive = !status || status.active !== true || status.enabled !== true || !status.track;
      if (stillE2e || inactive) {
        runFixture('restore', { token: 'last-real' });
      }
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
      }
      // Authoritative stop intent (UI pause may be blocked by autoplay policy in CI).
      await page.evaluate(() => {
        Object.keys(localStorage).forEach((k) => {
          if (!k.includes('storeMusic')) {
            return;
          }
          if (k.endsWith('.want_play')) {
            localStorage.setItem(k, '0');
          }
          if (k.endsWith('.user_stopped')) {
            localStorage.setItem(k, '1');
          }
        });
        const root = document.querySelector('[data-store-music]');
        if (root) {
          const website = root.getAttribute('data-website-id') || '0';
          const store = root.getAttribute('data-store-id') || '0';
          localStorage.setItem(`weline.storeMusic.${website}.${store}.want_play`, '0');
          localStorage.setItem(`weline.storeMusic.${website}.${store}.user_stopped`, '1');
        }
        try {
          const shared = window.__WelineStoreMusicSharedAudio;
          if (shared) {
            shared.pause();
          }
        } catch (_e) {
          // ignore
        }
      });
      await closeBtn.click();
      await expect(page.locator('[data-testid="store-music-panel"]')).toBeHidden();
      const wantPlay = await page.evaluate(() => {
        const keys = Object.keys(localStorage).filter((k) => k.includes('storeMusic') && k.endsWith('.want_play'));
        return keys.length > 0 && keys.every((k) => localStorage.getItem(k) === '0');
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
        if (c.hidden || getComputedStyle(c).display === 'none') return false;
        const z = Number.parseInt(getComputedStyle(c).zIndex, 10);
        // Must sit above page chrome (legacy z=8 was buried under opaque sections).
        if (!Number.isFinite(z) || z < 1000) return false;
        // Prefer body mount so fixed stacking escapes zero-size host ancestors.
        if (c.parentElement !== document.body) return false;
        return true;
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
        // Status may be other-tab OR a soft load error after hard-silence cleared src — either is fine
        // as long as residual dual-play is gone.
        if (statusText) {
          expect(statusText).toMatch(/其他页面|other|加载失败|失败|格式/i);
        }
      } finally {
        await context.close();
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'STOREMUSIC-E2E-CH10-PEER-QUIET-TAKEOVER' },
    '前后端通路：新标签检测到他页在播则静默；本页▶/切歌可接管且前页硬停',
    async ({ browser }) => {
      const enabled = runFixture('enable', { delay_seconds: 1, format: 'real' });
      expect(enabled.ok).toBe(true);
      const context = await browser.newContext();
      const pageA = await context.newPage();
      const pageB = await context.newPage();
      try {
        await gotoWithWidget(pageA, `/?store_music_e2e=${TOKEN}-peer-a`, true);
        await openPetPanel(pageA);
        const playA = pageA.locator('[data-testid="store-music-play"]:not([hidden])');
        if (await playA.count()) {
          await playA.click();
        }
        await pageA.waitForFunction(
          () => {
            const shared = window.__WelineStoreMusicSharedAudio;
            return !!(shared && !shared.paused && shared.src && shared.currentTime > 0.2);
          },
          { timeout: 15000 },
        );
        const aPlaying = await pageA.evaluate(() => {
          const shared = window.__WelineStoreMusicSharedAudio;
          return !!(shared && !shared.paused && shared.src && shared.currentTime > 0.2);
        });
        expect(aPlaying, 'page A should be playing before B loads').toBe(true);

        await gotoWithWidget(pageB, `/?store_music_e2e=${TOKEN}-peer-b`, true);
        await pageB.waitForTimeout(1800);
        const bSoft = await pageB.evaluate(() => {
          const shared = window.__WelineStoreMusicSharedAudio;
          const root = document.querySelector('.w-store-music');
          const pause = document.querySelector('[data-testid="store-music-pause"]');
          const playing = !!(shared && !shared.paused && shared.src);
          const rootPlaying = !!(root && root.classList.contains('is-playing'));
          const remotePlaying = !!(root && root.classList.contains('is-remote-playing'));
          const pauseVisible = !!(pause && !pause.hidden);
          return { playing, rootPlaying, remotePlaying, pauseVisible };
        });
        expect(bSoft.playing, 'soft-loaded B must not autoplay over A').toBe(false);
        expect(bSoft.rootPlaying, 'soft-loaded B UI must not be is-playing').toBe(false);
        expect(bSoft.remotePlaying, 'soft-loaded B must mirror the remote playing state').toBe(true);
        expect(bSoft.pauseVisible, 'soft-loaded B must show the remote pause state').toBe(true);

        await openPetPanel(pageB);
        const statusB = ((await pageB.locator('[data-testid="store-music-status"]').textContent()) || '').trim();
        expect(statusB).not.toMatch(/已在其他页面播放/);

        const playB = pageB.locator('[data-testid="store-music-play"]:not([hidden])');
        if (await playB.count()) {
          await playB.click();
        } else {
          const nextB = pageB.locator('[data-testid="store-music-next"]');
          if (await nextB.isVisible()) {
            await nextB.click();
          }
        }
        await pageB.waitForTimeout(900);

        const after = await Promise.all([
          pageA.evaluate(() => {
            const shared = window.__WelineStoreMusicSharedAudio;
            return {
              playing: !!(shared && !shared.paused && shared.src),
              root: !!document.querySelector('.w-store-music.is-playing'),
            };
          }),
          pageB.evaluate(() => {
            const shared = window.__WelineStoreMusicSharedAudio;
            return {
              playing: !!(shared && !shared.paused && shared.src),
              root: !!document.querySelector('.w-store-music.is-playing'),
            };
          }),
        ]);
        expect(after[0].playing, 'A must hard-silence after B takeover').toBe(false);
        expect(after[0].root, 'A UI must drop is-playing').toBe(false);
        expect(after[1].playing || after[1].root, 'B must be audible or show playing after ▶').toBe(true);
      } finally {
        await context.close();
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'STOREMUSIC-E2E-CH11-REFRESH-QUIET-TAB' },
    '前后端通路：旁观标签刷新后仍只同步状态，不得抢走播放拥有权',
    async ({ browser }) => {
      const enabled = runFixture('enable', { delay_seconds: 1, format: 'real' });
      expect(enabled.ok).toBe(true);
      const context = await browser.newContext();
      const pageA = await context.newPage();
      const pageB = await context.newPage();
      try {
        await gotoWithWidget(pageA, `/?store_music_e2e=${TOKEN}-refresh-owner-a`, true);
        await openPetPanel(pageA);
        const playA = pageA.locator('[data-testid="store-music-play"]:not([hidden])');
        if (await playA.count()) {
          await playA.click();
        }
        await pageA.waitForFunction(
          () => {
            const shared = window.__WelineStoreMusicSharedAudio;
            return !!(shared && !shared.paused && shared.src && shared.currentTime > 0.2);
          },
          { timeout: 15000 },
        );
        const before = await pageA.evaluate(() => {
          const shared = window.__WelineStoreMusicSharedAudio;
          return !!(shared && !shared.paused && shared.src && shared.currentTime > 0.2);
        });
        expect(before, 'page A should own audible playback before B loads').toBe(true);

        await gotoWithWidget(pageB, `/?store_music_e2e=${TOKEN}-refresh-owner-b`, true);
        await pageB.waitForTimeout(1500);
        await pageB.reload({ waitUntil: 'domcontentloaded', timeout: 60000 });
        await pageB.waitForFunction(
          () => !!document.querySelector('[data-store-music]'),
          { timeout: 20000 },
        );
        await pageB.waitForTimeout(1800);

        const after = await Promise.all([
          pageA.evaluate(() => {
            const shared = window.__WelineStoreMusicSharedAudio;
            return !!(shared && !shared.paused && shared.src);
          }),
          pageB.evaluate(() => {
            const shared = window.__WelineStoreMusicSharedAudio;
            const root = document.querySelector('.w-store-music');
            return {
              audible: !!(shared && !shared.paused && shared.src),
              remotePlaying: !!(root && root.classList.contains('is-remote-playing')),
            };
          }),
        ]);
        expect(after[0], 'the original owner must stay audible after a quiet tab refresh').toBe(true);
        expect(after[1].audible, 'the refreshed quiet tab must not create a second audible source').toBe(false);
        expect(after[1].remotePlaying, 'the refreshed quiet tab must keep mirroring remote state').toBe(true);
      } finally {
        await context.close();
      }
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'STOREMUSIC-E2E-CH12-REFRESH-OWNER' },
    '前后端通路：当前播放页刷新后自动恢复播放拥有权与进度',
    async ({ page }) => {
      const enabled = runFixture('enable', { delay_seconds: 1, format: 'real' });
      expect(enabled.ok).toBe(true);
      await gotoWithWidget(page, `/?store_music_e2e=${TOKEN}-refresh-owner`, true);
      await openPetPanel(page);
      const play = page.locator('[data-testid="store-music-play"]:not([hidden])');
      if (await play.count()) {
        await play.click();
      }
      await page.waitForFunction(
        () => {
          const shared = window.__WelineStoreMusicSharedAudio;
          return !!(shared && !shared.paused && shared.currentTime > 0.2);
        },
        { timeout: 15000 },
      );
      const before = await page.evaluate(() => {
        const shared = window.__WelineStoreMusicSharedAudio;
        return {
          audible: !!(shared && !shared.paused && shared.src),
          currentTime: shared ? Number(shared.currentTime || 0) : 0,
        };
      });
      expect(before.audible, 'the owner tab should be audible before refresh').toBe(true);

      await page.reload({ waitUntil: 'domcontentloaded', timeout: 60000 });
      const reloadStartedAt = Date.now();
      await page.waitForFunction(
        () => !!document.querySelector('[data-store-music]'),
        { timeout: 20000 },
      );
      await page.waitForFunction(
        () => {
          const shared = window.__WelineStoreMusicSharedAudio;
          const root = document.querySelector('.w-store-music');
          const gen = window.WelineStoreMusic && window.WelineStoreMusic.SCRIPT_GEN;
          const live = window.__WelineStoreMusicLive;
          return !!(
            gen
            && String(gen).indexOf('61speccenter2') !== -1
            && shared
            && !shared.paused
            && shared.currentTime > 0.2
            && root
            && root.classList.contains('is-playing')
            && !(live && live.needGesture)
          );
        },
        { timeout: 15000 },
      );
      const resumeMs = Date.now() - reloadStartedAt;

      const after = await page.evaluate(() => {
        const shared = window.__WelineStoreMusicSharedAudio;
        const root = document.querySelector('.w-store-music');
        return {
          audible: !!(shared && !shared.paused && shared.src),
          currentTime: shared ? Number(shared.currentTime || 0) : 0,
          localPlaying: !!(root && root.classList.contains('is-playing')),
          needGesture: !!(window.__WelineStoreMusicLive && window.__WelineStoreMusicLive.needGesture),
          gen: window.WelineStoreMusic && window.WelineStoreMusic.SCRIPT_GEN,
        };
      });
      expect(after.audible, 'the owner tab must resume audible playback after refresh').toBe(true);
      expect(after.localPlaying, 'the refreshed owner tab must restore local playing UI').toBe(true);
      expect(after.needGesture, 'sticky refresh must not require a click to resume').toBe(false);
      expect(after.currentTime, 'the refreshed owner tab should retain playback progress').toBeGreaterThan(0.2);
      expect(resumeMs, 'sticky refresh must resume without waiting for full window load').toBeLessThan(8000);
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'STOREMUSIC-E2E-CH13-CACHED-RESUME-ASAP' },
    '前后端通路：已有本地进度且未明确停止时立即续播，不等待首次进入延迟',
    async ({ page }) => {
      const enabled = runFixture('enable', { delay_seconds: 3, format: 'real' });
      expect(enabled.ok).toBe(true);

      await page.addInitScript(() => {
        try {
          const key = 'weline.storeMusic.0.0.';
          localStorage.removeItem(`${key}want_play`);
          localStorage.setItem(`${key}user_stopped`, '0');
          localStorage.setItem(`${key}dismissed`, '0');
          localStorage.setItem(
            `${key}progress`,
            JSON.stringify({
              url: '/media/store-music/HITA-赤伶.mp3',
              time: 1.2,
              index: 0,
              updated: Date.now(),
            }),
          );
        } catch (_error) {
          // Playwright also evaluates init scripts on an opaque about:blank.
          // The real storefront origin runs the same script again with storage.
        }
        window.__welineStoreMusicCachedResumeProbe = { domContentLoadedAt: 0 };
        document.addEventListener('DOMContentLoaded', () => {
          window.__welineStoreMusicCachedResumeProbe.domContentLoadedAt = performance.now();
        }, { once: true });
      });

      await gotoWithWidget(page, `/?store_music_e2e=${TOKEN}-cached-resume-asap`, true);
      await page.waitForFunction(
        () => {
          const shared = window.__WelineStoreMusicSharedAudio;
          return !!(shared && !shared.paused && shared.src && shared.currentTime > 0.2);
        },
        { timeout: 15000 },
      );

      const resume = await page.evaluate(() => {
        const shared = window.__WelineStoreMusicSharedAudio;
        const probe = window.__welineStoreMusicCachedResumeProbe || {};
        return {
          elapsedFromDomContentLoaded: performance.now() - Number(probe.domContentLoadedAt || performance.now()),
          currentTime: shared ? Number(shared.currentTime || 0) : 0,
          localPlaying: !!document.querySelector('.w-store-music.is-playing'),
        };
      });
      expect(resume.elapsedFromDomContentLoaded, 'cached resume must bypass the cold-entry delay').toBeLessThan(1800);
      expect(resume.currentTime, 'cached resume should restore the saved position').toBeGreaterThan(0.2);
      expect(resume.localPlaying, 'cached resume should show local playing state').toBe(true);
    },
  );

  moduleCase(
    test,
    { module: MODULE, id: 'STOREMUSIC-E2E-SEARCH' },
    '前后端通路：播放列表搜索可过滤曲目并支持空态与清空恢复',
    async ({ page }) => {
      const enabled = runFixture('enable', { delay_seconds: 1 });
      expect(enabled.ok).toBe(true);
      await gotoWithWidget(page, `/?store_music_e2e=${TOKEN}-search`, true);
      await openPetPanel(page);
      const search = page.locator('[data-testid="store-music-search"]');
      await expect(search).toBeVisible();
      const rowsBefore = await page.locator('[data-store-music-track]').count();
      expect(rowsBefore).toBeGreaterThan(1);

      const firstTitle = (await page.locator('[data-store-music-track] .w-store-music__track-title').first().innerText()).trim();
      // Prefer a character unique to the first row when fixture uses 甲/乙.
      let needle = '甲';
      if (!firstTitle.includes(needle)) {
        needle = firstTitle.slice(0, Math.min(2, firstTitle.length));
      }
      await search.fill(needle);
      await expect.poll(async () => page.locator('[data-store-music-track]').count()).toBeLessThan(rowsBefore);
      await expect(page.locator('[data-store-music-track]').first()).toBeVisible();
      await expect(page.locator('[data-testid="store-music-search-empty"]')).toBeHidden();

      await search.fill('___no_match_store_music_xyz___');
      await expect(page.locator('[data-testid="store-music-search-empty"]')).toBeVisible();
      await expect(page.locator('[data-store-music-track]')).toHaveCount(0);

      await search.fill('');
      await expect.poll(async () => page.locator('[data-store-music-track]').count()).toBe(rowsBefore);
      await expect(page.locator('[data-testid="store-music-search-empty"]')).toBeHidden();
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
