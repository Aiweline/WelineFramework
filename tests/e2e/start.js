/**
 * E2E start script.
 * One command performs:
 * 1. Check modules.json
 * 2. Collect tests
 * 3. Run Playwright
 */

const fs = require('fs');
const path = require('path');
const http = require('http');
const https = require('https');
const net = require('net');
const { execFileSync, execSync, spawn, spawnSync } = require('child_process');
const { runFrameworkPreflight } = require('./framework/preflight-refresh');

const ROOT_DIR = path.resolve(__dirname, '../..');
const MODULES_JSON = path.join(__dirname, 'modules.json');
const RUNTIME_INFO_SCRIPT = path.join(__dirname, 'framework', 'runtime-info.php');
const FALLBACK_HOST = '127.0.0.1';
const FALLBACK_PORTS = [9991, 9992, 9993, 9994, 9995];

function readRuntimeInfo(env = process.env) {
    const stdout = execFileSync('php', [RUNTIME_INFO_SCRIPT], {
        cwd: ROOT_DIR,
        env,
        encoding: 'utf8',
    });

    return JSON.parse(stdout);
}

function resolvePhpBinary() {
    const bundledPhp = path.join(ROOT_DIR, 'extend', 'server', 'php', 'php.exe');
    if (fs.existsSync(bundledPhp)) {
        return bundledPhp;
    }

    return process.env.PHP_BINARY || 'php';
}

function requestUrl(url, options = {}) {
    const timeoutMs = options.timeoutMs || 5000;
    const validateStatus = options.validateStatus || (status => Number.isInteger(status) && status >= 200 && status < 500);

    return new Promise(resolve => {
        let settled = false;
        const parsedUrl = new URL(url);
        const client = parsedUrl.protocol === 'https:' ? https : http;
        const request = client.request(
            parsedUrl,
            {
                method: 'GET',
                rejectUnauthorized: false,
                timeout: timeoutMs,
            },
            response => {
                response.resume();
                if (settled) {
                    return;
                }

                settled = true;
                resolve({
                    ok: validateStatus(response.statusCode),
                    statusCode: response.statusCode || null,
                });
            }
        );

        request.on('timeout', () => {
            request.destroy(new Error(`timeout after ${timeoutMs}ms`));
        });

        request.on('error', error => {
            if (settled) {
                return;
            }

            settled = true;
            resolve({
                ok: false,
                statusCode: null,
                error: error.message,
            });
        });

        request.end();
    });
}

function isPortListening(port, host = FALLBACK_HOST) {
    return new Promise(resolve => {
        const socket = net.connect({ host, port });
        let settled = false;

        const finish = result => {
            if (settled) {
                return;
            }

            settled = true;
            socket.destroy();
            resolve(result);
        };

        socket.setTimeout(1000);
        socket.once('connect', () => finish(true));
        socket.once('timeout', () => finish(false));
        socket.once('error', () => finish(false));
    });
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

async function waitForUrl(url, options = {}) {
    const attempts = options.attempts || 30;
    const intervalMs = options.intervalMs || 500;
    const requestOptions = {
        timeoutMs: options.timeoutMs || 5000,
        validateStatus: options.validateStatus,
    };

    for (let attempt = 0; attempt < attempts; attempt++) {
        const result = await requestUrl(url, requestOptions);
        if (result.ok) {
            return true;
        }

        if (attempt < attempts - 1) {
            await sleep(intervalMs);
        }
    }

    return false;
}

function cleanupChildProcess(child) {
    if (!child || !child.pid) {
        return;
    }

    spawnSync('taskkill', ['/PID', String(child.pid), '/T', '/F'], {
        stdio: 'ignore',
        windowsHide: true,
    });
}

function findFreePort(host = FALLBACK_HOST) {
    return new Promise((resolve, reject) => {
        const server = net.createServer();
        server.unref();
        server.on('error', reject);
        server.listen(0, host, () => {
            const address = server.address();
            const port = address && typeof address === 'object' ? address.port : 0;
            server.close(error => {
                if (error) {
                    reject(error);
                    return;
                }

                resolve(port);
            });
        });
    });
}

async function startFallbackPhpServer(port) {
    const origin = `http://${FALLBACK_HOST}:${port}`;
    const child = spawn(
        resolvePhpBinary(),
        ['-S', `${FALLBACK_HOST}:${port}`, '-t', 'pub', 'pub/index.php'],
        {
            cwd: ROOT_DIR,
            stdio: 'ignore',
            windowsHide: true,
        }
    );

    const ready = await waitForUrl(origin, {
        attempts: 60,
        intervalMs: 500,
        timeoutMs: 2000,
    });

    if (!ready) {
        cleanupChildProcess(child);
        throw new Error(`Failed to start local PHP runtime at ${origin}.`);
    }

    return {
        origin,
        reused: false,
        cleanup: () => cleanupChildProcess(child),
    };
}

async function resolveFallbackRuntime() {
    for (const port of FALLBACK_PORTS) {
        const origin = `http://${FALLBACK_HOST}:${port}`;
        const reusable = await requestUrl(origin, { timeoutMs: 2000 });
        if (reusable.ok) {
            return {
                origin,
                reused: true,
                cleanup: null,
            };
        }

        const listening = await isPortListening(port, FALLBACK_HOST);
        if (!listening) {
            return startFallbackPhpServer(port);
        }
    }

    const dynamicPort = await findFreePort(FALLBACK_HOST);
    return startFallbackPhpServer(dynamicPort);
}

async function prepareRuntime() {
    const userPinnedTarget = Boolean(process.env.PLAYWRIGHT_TARGET_ORIGIN);
    const userPinnedProxyMode = Object.prototype.hasOwnProperty.call(process.env, 'PLAYWRIGHT_DISABLE_PROXY');

    if (!userPinnedTarget) {
        const preferredLocalRuntime = await resolveFallbackRuntime();
        process.env.PLAYWRIGHT_TARGET_ORIGIN = preferredLocalRuntime.origin;
        if (!userPinnedProxyMode) {
            process.env.PLAYWRIGHT_DISABLE_PROXY = '1';
        }

        const runtimeInfo = readRuntimeInfo(process.env);
        const note = preferredLocalRuntime.reused
            ? `[e2e] using preferred local PHP runtime ${preferredLocalRuntime.origin} for a stable test target`
            : `[e2e] started local PHP runtime ${preferredLocalRuntime.origin} for a stable test target`;

        return {
            runtimeInfo,
            cleanup: preferredLocalRuntime.cleanup,
            note,
        };
    }

    let runtimeInfo = readRuntimeInfo(process.env);
    let cleanup = null;
    let note = null;

    if (!runtimeInfo.runtime.reachable) {
        return { runtimeInfo, cleanup, note };
    }

    if (!userPinnedProxyMode && process.env.PLAYWRIGHT_DISABLE_PROXY !== '1') {
        const proxyHealth = await requestUrl(
            `${runtimeInfo.proxy.origin}/.well-known/weline-e2e/health`,
            {
                timeoutMs: 2000,
                validateStatus: status => status === 200,
            }
        );

        if (!proxyHealth.ok) {
            const proxyListening = await isPortListening(runtimeInfo.proxy.port, runtimeInfo.proxy.host);
            if (proxyListening) {
                process.env.PLAYWRIGHT_DISABLE_PROXY = '1';
                runtimeInfo = readRuntimeInfo(process.env);
                note = `[e2e] proxy ${runtimeInfo.proxy.origin} is occupied but not reusable; running tests in direct mode`;
            }
        }
    }

    return { runtimeInfo, cleanup, note };
}

async function main() {
    let cleanup = null;

    try {
        console.log('[e2e] running framework preflight...\n');
        try {
            const preflight = runFrameworkPreflight(resolvePhpBinary, process.env);
            if (preflight.output) {
                console.log(`${preflight.output}\n`);
            }
        } catch (error) {
            console.error('[e2e] framework preflight failed.');
            if (error.details) {
                console.error(`${error.details}\n`);
            }
            throw error;
        }

        const preparedRuntime = await prepareRuntime();
        cleanup = preparedRuntime.cleanup;
        const runtimeInfo = preparedRuntime.runtimeInfo;

        console.log('[e2e] starting test flow...\n');
        if (preparedRuntime.note) {
            console.log(`${preparedRuntime.note}\n`);
        }
        console.log(`[e2e] proxy origin: ${runtimeInfo.proxy.origin}`);
        console.log(`[e2e] target origin: ${runtimeInfo.runtime.target_origin}`);
        console.log(`[e2e] transport: ${process.env.PLAYWRIGHT_DISABLE_PROXY === '1' ? 'direct' : 'proxy'}\n`);

        if (!fs.existsSync(MODULES_JSON)) {
            console.error('[e2e] error: modules.json is missing');
            console.error('   Run: php bin/w setup:upgrade');
            return 1;
        }

        console.log('[e2e] modules.json found\n');

        console.log('[e2e] collecting tests...\n');
        try {
            const { collectAllTests } = require('./collect-tests');
            const result = collectAllTests();

            if (result.total_tests === 0) {
                console.warn('[e2e] no tests were collected');
                console.warn('   Check app/code/*/*/test/e2e/*.spec.js or tests/e2e/specs/**/*.spec.js\n');
            }
        } catch (error) {
            console.error('[e2e] test collection failed:', error.message);
            return 1;
        }

        console.log('[e2e] running Playwright...\n');
        try {
            const args = process.argv.slice(2);
            const command = args.length > 0
                ? `npx playwright test ${args.join(' ')}`
                : 'npx playwright test';

            execSync(command, {
                stdio: 'inherit',
                cwd: __dirname,
                shell: true,
                env: process.env,
            });
        } catch (error) {
            return error.status || 1;
        }

        return 0;
    } finally {
        cleanup && cleanup();
    }
}

main()
    .then(exitCode => process.exit(exitCode))
    .catch(error => {
        console.error('[e2e] runtime preparation failed:', error.message);
        process.exit(1);
    });
