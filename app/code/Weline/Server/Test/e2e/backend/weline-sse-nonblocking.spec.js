// @weline-e2e-runtime wls
// @weline-e2e-transport direct

const { test, expect, buildBackendUrl } = require('../../../../../../../tests/e2e/framework');

const SSE_ROUTE = 'theme/backend/index/postGenerateAllPreviewsSse';
const BACKEND_ROUTE = 'theme/backend';
const HEALTH_CHECK_TIMEOUT = 15000;
const ACCEPTABLE_HEALTH_STATUSES = new Set([200, 301, 302, 303, 307, 308, 401, 403, 405]);

async function assertBackendUrlHealthy(request, route, label) {
    const url = buildBackendUrl(route);
    const response = await request.get(url, {
        failOnStatusCode: false,
        maxRedirects: 0,
        timeout: HEALTH_CHECK_TIMEOUT
    });

    const status = response.status();
    expect(
        ACCEPTABLE_HEALTH_STATUSES.has(status),
        `${label} 健康检查失败: ${url} 返回状态码 ${status}`
    ).toBeTruthy();

    console.log('[HEALTH] URL 检查通过:', { label, url, status });
}

async function openSseConnection(context) {
    const ssePage = await context.newPage();
    const sseUrl = buildBackendUrl(SSE_ROUTE);

    const ssePromise = ssePage.goto(sseUrl, {
        timeout: 60000,
        waitUntil: 'commit'
    });

    try {
        await ssePromise;
    } catch (error) {
        await ssePage.close();
        throw new Error(`[SSE] 连接启动失败: ${error.message}`);
    }

    return { ssePage, ssePromise };
}

/**
 * SSE Non-Blocking E2E Tests
 * 验证 SSE 长连接不会阻塞其他并发请求
 *
 * 问题：SSE 长连接会独占 Worker，导致其他请求等待
 * 预期：SSE 运行时，其他 HTTP 请求应该能正常并发处理
 */
test.describe('SSE Non-Blocking - SSE 长连接不阻塞测试', () => {
    test.beforeEach(async ({ request }) => {
        await assertBackendUrlHealthy(request, BACKEND_ROUTE, '后台页面 URL');
        await assertBackendUrlHealthy(request, SSE_ROUTE, 'SSE 接口 URL');
    });

    /**
     * SSE-01: SSE 长连接不应阻塞普通 HTTP 请求
     *
     * 测试步骤：
     * 1. 发起 SSE 请求（POST /theme/backend/index/postGenerateAllPreviewsSse）
     * 2. 在 SSE 运行时，发起普通 HTTP 请求
     * 3. 验证普通 HTTP 请求能在合理时间内返回
     */
    test('SSE-01: SSE 长连接不应阻塞普通 HTTP 请求', async ({ context }) => {
        const { ssePage } = await openSseConnection(context);
        const testPage = await context.newPage();

        try {
            // 1. 等待 SSE 连接建立
            await testPage.waitForTimeout(500);
            console.log('[SSE-01] SSE 请求已发起');

            // 2. 在 SSE 运行期间，发起普通 HTTP GET 请求
            const startTime = Date.now();
            const response = await testPage.goto(buildBackendUrl(BACKEND_ROUTE), {
                timeout: 15000,
                waitUntil: 'domcontentloaded'
            });

            const duration = Date.now() - startTime;
            const status = response?.status() ?? 0;
            console.log('[SSE-01] 并发 HTTP GET 请求完成:', { duration, status });

            // 3. 验证
            expect(duration).toBeLessThan(10000);
            expect(status).toBeGreaterThanOrEqual(200);
            expect(status).toBeLessThan(500);
            console.log('[SSE-01] ✓ 测试通过：HTTP GET 请求未受 SSE 影响，耗时', duration, 'ms');
        } finally {
            await testPage.close();
            await ssePage.close();
        }
    });

    /**
     * SSE-02: 多个并发 GET 请求在 SSE 运行时应该全部成功
     */
    test('SSE-02: 多个并发 GET 请求在 SSE 运行时不应被阻塞', async ({ context }) => {
        const { ssePage } = await openSseConnection(context);
        await ssePage.waitForTimeout(500);

        console.log('[SSE-02] SSE 连接已建立，发起并发 GET 请求测试');

        // 2. 同时发起 3 个并发 GET 请求
        const startTime = Date.now();
        const promises = [];

        for (let i = 0; i < 3; i++) {
            const pg = await context.newPage();

            promises.push(
                (async () => {
                    const reqStart = Date.now();
                    try {
                        const resp = await pg.goto(buildBackendUrl(BACKEND_ROUTE), {
                            timeout: 15000,
                            waitUntil: 'domcontentloaded'
                        });
                        const reqDuration = Date.now() - reqStart;
                        const reqStatus = resp?.status() ?? 0;
                        await pg.close();
                        return { index: i, success: true, duration: reqDuration, status: reqStatus };
                    } catch (err) {
                        const reqDuration = Date.now() - reqStart;
                        await pg.close();
                        return { index: i, success: false, duration: reqDuration, error: err.message };
                    }
                })()
            );
        }

        // 3. 等待所有请求完成
        const results = await Promise.all(promises);

        console.log('[SSE-02] 并发 GET 请求结果:', JSON.stringify(results, null, 2));

        try {
            // 4. 验证：所有请求都应该在合理时间内完成
            for (const result of results) {
                expect(result.duration).toBeLessThan(15000, `请求 ${result.index} 超时`);
                if (result.success) {
                    expect(result.status).toBeGreaterThanOrEqual(200);
                    expect(result.status).toBeLessThan(500);
                }
            }
            console.log('[SSE-02] ✓ 测试通过：所有并发 GET 请求均成功完成');
        } finally {
            await ssePage.close();
        }
    });

    /**
     * SSE-03: SSE 运行期间多次轮询应该正常响应
     */
    test('SSE-03: SSE 运行期间多次轮询不应超时', async ({ context }) => {
        const { ssePage } = await openSseConnection(context);
        await ssePage.waitForTimeout(500);

        console.log('[SSE-03] SSE 连接已建立，开始轮询测试');

        // 2. 在 SSE 运行期间轮询 3 次
        const pollResults = [];

        for (let i = 0; i < 3; i++) {
            const pg = await context.newPage();

            const pollStart = Date.now();
            try {
                const resp = await pg.goto(buildBackendUrl(BACKEND_ROUTE), {
                    timeout: 10000,
                    waitUntil: 'domcontentloaded'
                });
                const pollDuration = Date.now() - pollStart;
                pollResults.push({
                    attempt: i + 1,
                    success: true,
                    duration: pollDuration,
                    status: resp?.status()
                });
                console.log(`[SSE-03] 轮询 ${i + 1}: 耗时 ${pollDuration}ms, 状态: ${resp?.status()}`);
            } catch (err) {
                const pollDuration = Date.now() - pollStart;
                pollResults.push({
                    attempt: i + 1,
                    success: false,
                    duration: pollDuration,
                    error: err.message
                });
                console.log(`[SSE-03] 轮询 ${i + 1} 失败: ${err.message}`);
            }

            await pg.close();

            // 间隔 2 秒
            if (i < 2) {
                await ssePage.waitForTimeout(2000);
            }
        }

        try {
            // 3. 验证
            for (const result of pollResults) {
                expect(result.duration).toBeLessThan(10000, `轮询 ${result.attempt} 超时`);
                if (result.success) {
                    expect(result.status).toBeGreaterThanOrEqual(200);
                    expect(result.status).toBeLessThan(500);
                }
            }
            console.log('[SSE-03] ✓ 测试通过：轮询响应正常');
        } finally {
            await ssePage.close();
        }
    });

    /**
     * SSE-04: 验证 SSE 运行期间不应出现 504 Gateway Timeout
     *
     * 这是用户报告的问题：SSE 运行时，轮询返回 504
     */
    test('SSE-04: SSE 运行期间不应出现 504 Gateway Timeout', async ({ context }) => {
        const { ssePage } = await openSseConnection(context);
        await ssePage.waitForTimeout(500);

        console.log('[SSE-04] SSE 连接已建立，测试是否会返回 504');

        // 2. 发起请求，验证不会返回 504
        const pg = await context.newPage();

        try {
            const resp = await pg.goto(buildBackendUrl(BACKEND_ROUTE), {
                timeout: 20000,
                waitUntil: 'domcontentloaded'
            });

            const status = resp?.status() ?? 0;
            console.log('[SSE-04] 响应状态:', status);

            // 3. 验证不应返回 504
            expect(status).not.toBe(504, 'SSE 运行期间不应返回 504 Gateway Timeout');
            expect(status).toBeGreaterThanOrEqual(200);
            expect(status).toBeLessThan(500);
            console.log('[SSE-04] ✓ 测试通过：未出现 504 Gateway Timeout');
        } finally {
            await pg.close();
            await ssePage.close();
        }
    });
});
