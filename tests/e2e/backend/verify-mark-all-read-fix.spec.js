// tests/e2e/backend/verify-mark-all-read-fix.spec.js
// Regression: "全部已读" must not throw Weline.Api circuit-breaker error.
// Verifies BOTH: (a) served JS contains the adminRequest fix, and
// (b) triggering the call in browser works without the circuit breaker firing.
'use strict';
const { test, expect } = require('@playwright/test');

const HOST = 'p05113ef3.test.weline.com';
const ORIGIN = `https://${HOST}:9555`;
const ADMIN_PREFIX = 'jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH';

test.describe.configure({ mode: 'serial' });

test('(a) served notification-center.js contains the fix', async ({ request }) => {
    // Browse via the actual URL the browser would use
    const candidates = [
        `/static/Weline/hanfu/Weline/Backend/view/statics/js/notification-center.js`,
        `/static/Weline/Backend/js/notification-center.js`,
        `/static/Weline/Theme/view/theme/Weline/Backend/view/statics/js/notification-center.js`,
    ];
    let served = null;
    for (const p of candidates) {
        const resp = await request.get(`${ORIGIN}${p}`);
        if (resp.status() === 200) { served = await resp.text(); break; }
    }
    expect(served, 'served notification-center.js must exist').toBeTruthy();
    expect(served).toMatch(/adminRequest\(\s*['"]backend_admin['"]/);
    expect(served).not.toMatch(/fetch\(/);
});

test('(b) calling adminRequest at runtime does not trip circuit-breaker', async ({ page }) => {
    const consoleErrors = [];
    const pageErrors = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
    page.on('pageerror', (err) => pageErrors.push(err.message));

    // Login (local dev DB still uses the default admin/admin; production was
    // changed to weline/weline18 on 2026-09-25)
    await page.goto(`${ORIGIN}/${ADMIN_PREFIX}/admin/login`, { waitUntil: 'networkidle' });
    await page.locator('input[name="username"]').fill('admin');
    await page.locator('input[name="password"]').fill('admin');
    await page.locator('button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    // Navigate to notification page so the script attaches
    await page.goto(`${ORIGIN}/${ADMIN_PREFIX}/system/backend/notification`, {
        waitUntil: 'networkidle',
    });

    // Wait for the bridge to be available (Weline.adminRequest is global)
    await page.waitForFunction(
        () => window.Weline && typeof window.Weline.adminRequest === 'function',
        { timeout: 10000 }
    );

    // Click 全部已读 if it exists (no notifications → no button → still test below via JS)
    const markAllBtn = page.locator('[data-w-notification-action="mark-all-read"]');
    const hasBtn = await markAllBtn.count();

    // Trigger the call directly to verify the bridge works end-to-end regardless of button
    const networkCapture = { url: null, status: null, body: null };
    page.on('response', async (resp) => {
        const u = resp.url();
        if (u.includes('/markAllRead') || u.includes('query-bin')) {
            networkCapture.url = u;
            networkCapture.status = resp.status();
            try {
                networkCapture.body = (await resp.text()).slice(0, 500);
            } catch (_) { /* noop */ }
        }
    });
    const result = await page.evaluate(async (prefix) => {
        const start = performance.now();
        try {
            const sec = document.querySelector('[data-w-component="notification-center"]');
            const url = sec ? sec.getAttribute('data-w-mark-all-url') : null;
            const target = url || `/${prefix}/system/backend/notification/markAllRead`;
            const resp = await window.Weline.adminRequest('backend_admin', target, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: '',
            });
            return {
                ok: resp && resp.ok,
                success: resp && resp.success,
                status: resp && resp.status,
                message: resp && resp.message,
                elapsedMs: Math.round(performance.now() - start),
                target,
            };
        } catch (e) {
            return { error: String(e && e.message ? e.message : e), elapsedMs: Math.round(performance.now() - start) };
        }
    }, ADMIN_PREFIX);

    console.log('TRIGGER_RESULT=' + JSON.stringify(result));
    console.log('NETWORK_CAPTURE=' + JSON.stringify(networkCapture));
    console.log('HAS_BTN=' + hasBtn);
    console.log('CIRCUIT_ERRORS=' + JSON.stringify(consoleErrors.filter((m) => m.includes('too many in-flight'))));

    // THE KEY ASSERTION (the user-reported bug):
    // clicking 全部已读 must NOT trip the Weline.Api per-pathname circuit breaker.
    const circuitErrors = consoleErrors.filter((m) => m.includes('too many in-flight'));
    expect(circuitErrors).toEqual([]);

    // Sanity: the call went through the bridge (query-bin endpoint hit), not raw fetch.
    expect(networkCapture.url).toBeTruthy();
    expect(networkCapture.url).toMatch(/query-bin/);
});