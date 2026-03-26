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
const ALLOW_FALLBACK_RUNTIME_REUSE = process.env.PLAYWRIGHT_REUSE_FALLBACK_RUNTIME === '1';
const RUNTIME_STRATEGIES = new Set(['auto', 'wls', 'fallback']);
const TRANSPORT_STRATEGIES = new Set(['proxy', 'direct']);

function readTestFileSource(file) {
    try {
        return fs.readFileSync(file, 'utf8');
    } catch (error) {
        return '';
    }
}

function readRuntimeInfo(env = process.env) {
    const stdout = execFileSync('php', [RUNTIME_INFO_SCRIPT], {
        cwd: ROOT_DIR,
        env,
        encoding: 'utf8',
    });

    return JSON.parse(stdout);
}

function normalizeRuntimeStrategy(value, fallback = 'auto') {
    const normalized = String(value || '').trim().toLowerCase();
    return RUNTIME_STRATEGIES.has(normalized) ? normalized : fallback;
}

function normalizeTransportStrategy(value, fallback = null) {
    const normalized = String(value || '').trim().toLowerCase();
    return TRANSPORT_STRATEGIES.has(normalized) ? normalized : fallback;
}

function resolveRequestedTestFile(file) {
    if (typeof file !== 'string' || !file.endsWith('.js')) {
        return null;
    }

    const candidates = [];
    const normalizedFile = file.replace(/\\/g, '/');
    if (path.isAbsolute(file)) {
        candidates.push(file);
    } else {
        candidates.push(path.resolve(process.cwd(), file));
        candidates.push(path.resolve(__dirname, file));
        candidates.push(path.resolve(ROOT_DIR, file));

        for (const anchor of ['app/', 'tests/']) {
            const anchorIndex = normalizedFile.indexOf(anchor);
            if (anchorIndex !== -1) {
                candidates.push(path.join(ROOT_DIR, normalizedFile.slice(anchorIndex).replace(/\//g, path.sep)));
            }
        }
    }

    for (const candidate of candidates) {
        if (fs.existsSync(candidate) && fs.statSync(candidate).isFile()) {
            return candidate;
        }
    }

    return null;
}

function resolveRequestedTestFiles(args = []) {
    return args
        .filter(arg => typeof arg === 'string' && !arg.startsWith('-') && arg.endsWith('.js'))
        .map(resolveRequestedTestFile)
        .filter(Boolean);
}

function toProjectRelativePath(file) {
    return path.relative(ROOT_DIR, file).replace(/\\/g, '/');
}

function inferRuntimeStrategyFromArgs(args = []) {
    for (const file of resolveRequestedTestFiles(args)) {
        const source = readTestFileSource(file);
        const marker = source.match(/@weline-e2e-runtime\s*:?\s*([a-z-]+)/i);
        if (marker) {
            return normalizeRuntimeStrategy(marker[1], null);
        }
    }

    return null;
}

function inferTransportStrategyFromArgs(args = []) {
    for (const file of resolveRequestedTestFiles(args)) {
        const source = readTestFileSource(file);
        const marker = source.match(/@weline-e2e-transport\s*:?\s*([a-z-]+)/i);
        if (marker) {
            return normalizeTransportStrategy(marker[1], null);
        }
    }

    return null;
}

function resolveRuntimeStrategy(args = [], env = process.env) {
    if (Object.prototype.hasOwnProperty.call(env, 'PLAYWRIGHT_RUNTIME_STRATEGY')) {
        return normalizeRuntimeStrategy(env.PLAYWRIGHT_RUNTIME_STRATEGY);
    }

    return inferRuntimeStrategyFromArgs(args) || 'auto';
}

function resolveTransportStrategy(args = [], env = process.env) {
    if (Object.prototype.hasOwnProperty.call(env, 'PLAYWRIGHT_E2E_TRANSPORT')) {
        return normalizeTransportStrategy(env.PLAYWRIGHT_E2E_TRANSPORT);
    }

    return inferTransportStrategyFromArgs(args);
}

function normalizePlaywrightArgs(args = []) {
    return args.map(arg => {
        if (typeof arg !== 'string' || arg.startsWith('-') || !arg.endsWith('.js')) {
            return arg;
        }

        const resolved = resolveRequestedTestFile(arg);
        return resolved
            ? path.relative(__dirname, resolved).replace(/\\/g, '/')
            : arg;
    });
}

function stripFileArgs(args = []) {
    return args.filter(arg => !(typeof arg === 'string' && !arg.startsWith('-') && arg.endsWith('.js')));
}

function getModuleFilter(args = [], env = process.env) {
    const fromArgs = args.find(arg => typeof arg === 'string' && arg.startsWith('--module='))?.split('=')[1];
    return env.MODULE_FILTER || fromArgs || null;
}

function collectSelectedTestFiles(args = [], env = process.env) {
    const requestedTestFiles = resolveRequestedTestFiles(args).map(toProjectRelativePath);
    if (requestedTestFiles.length > 0) {
        return requestedTestFiles;
    }

    try {
        const { collectAllTests } = require('./collect-tests');
        const result = collectAllTests();
        const moduleFilter = getModuleFilter(args, env);
        let files = Array.isArray(result.all_test_files) ? result.all_test_files : [];

        if (moduleFilter) {
            const moduleTests = result.modules && result.modules[moduleFilter];
            files = Array.isArray(moduleTests?.test_files) ? moduleTests.test_files : [];
        }

        return Array.from(new Set(files.map(file => {
            const resolved = resolveRequestedTestFile(file) || path.resolve(ROOT_DIR, String(file));
            return toProjectRelativePath(resolved);
        })));
    } catch (error) {
        return requestedTestFiles;
    }
}

function readSpecExecutionHints(file) {
    const resolvedFile = resolveRequestedTestFile(file) || path.resolve(ROOT_DIR, String(file));
    const source = readTestFileSource(resolvedFile);
    const runtimeMarker = source.match(/@weline-e2e-runtime\s*:?\s*([a-z-]+)/i);
    const transportMarker = source.match(/@weline-e2e-transport\s*:?\s*([a-z-]+)/i);

    return {
        runtimeStrategy: normalizeRuntimeStrategy(runtimeMarker && runtimeMarker[1], 'auto'),
        transportStrategy: normalizeTransportStrategy(transportMarker && transportMarker[1], 'auto') || 'auto',
    };
}

function buildExecutionGroups(files = []) {
    const groupMap = new Map();

    for (const file of files) {
        const normalizedFile = String(file).replace(/\\/g, '/');
        const { runtimeStrategy, transportStrategy } = readSpecExecutionHints(normalizedFile);
        const key = `${runtimeStrategy}|${transportStrategy}`;

        if (!groupMap.has(key)) {
            groupMap.set(key, {
                runtimeStrategy,
                transportStrategy,
                files: [],
            });
        }

        groupMap.get(key).files.push(normalizedFile);
    }

    return Array.from(groupMap.values());
}

function shouldUseGroupedExecution(groups = [], requestedTestFiles = [], env = process.env) {
    if (env.PLAYWRIGHT_SKIP_GROUPING === '1') {
        return false;
    }

    if (
        Object.prototype.hasOwnProperty.call(env, 'PLAYWRIGHT_RUNTIME_STRATEGY')
        || Object.prototype.hasOwnProperty.call(env, 'PLAYWRIGHT_TARGET_ORIGIN')
    ) {
        return false;
    }

    if (groups.length === 0) {
        return false;
    }

    if (groups.length > 1) {
        return true;
    }

    if (requestedTestFiles.length > 0) {
        return false;
    }

    const [group] = groups;
    return group.runtimeStrategy !== 'auto' || group.transportStrategy !== 'auto';
}

function describeExecutionGroup(group) {
    return `runtime=${group.runtimeStrategy}, transport=${group.transportStrategy}, files=${group.files.length}`;
}

function runGroupedExecution(rawArgs = [], groups = []) {
    const sharedArgs = stripFileArgs(rawArgs);
    const scriptPath = path.resolve(__dirname, 'start.js');
    const inheritedTransport = normalizeTransportStrategy(process.env.PLAYWRIGHT_E2E_TRANSPORT, 'auto') || 'auto';
    const inheritedDisableProxy = process.env.PLAYWRIGHT_DISABLE_PROXY;

    console.log(`[e2e] detected ${groups.length} execution groups from spec markers; running them sequentially.\n`);

    for (const [index, group] of groups.entries()) {
        const groupEnv = { ...process.env };
        delete groupEnv.PLAYWRIGHT_RUNTIME_STRATEGY;
        delete groupEnv.PLAYWRIGHT_E2E_TRANSPORT;
        delete groupEnv.PLAYWRIGHT_TARGET_ORIGIN;
        delete groupEnv.PLAYWRIGHT_DISABLE_PROXY;
        delete groupEnv.PLAYWRIGHT_TEST_FILES;

        groupEnv.PLAYWRIGHT_SKIP_GROUPING = '1';
        groupEnv.PLAYWRIGHT_SKIP_PREFLIGHT = '1';

        if (typeof inheritedDisableProxy !== 'undefined') {
            groupEnv.PLAYWRIGHT_DISABLE_PROXY = inheritedDisableProxy;
        }

        if (group.runtimeStrategy !== 'auto') {
            groupEnv.PLAYWRIGHT_RUNTIME_STRATEGY = group.runtimeStrategy;
        }

        if (inheritedTransport !== 'auto') {
            groupEnv.PLAYWRIGHT_E2E_TRANSPORT = inheritedTransport;
        }

        if (group.transportStrategy !== 'auto') {
            groupEnv.PLAYWRIGHT_E2E_TRANSPORT = group.transportStrategy;
        }

        console.log(`[e2e] group ${index + 1}/${groups.length}: ${describeExecutionGroup(group)}`);
        console.log(`[e2e] files: ${group.files.join(', ')}\n`);

        try {
            execFileSync(process.execPath, [scriptPath, ...sharedArgs, ...group.files], {
                stdio: 'inherit',
                cwd: __dirname,
                env: groupEnv,
            });
        } catch (error) {
            return error.status || 1;
        }
    }

    return 0;
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
        attempts: 120,
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
        const listening = await isPortListening(port, FALLBACK_HOST);
        if (listening && ALLOW_FALLBACK_RUNTIME_REUSE) {
            const reusable = await requestUrl(origin, { timeoutMs: 2000 });
            if (reusable.ok) {
                return {
                    origin,
                    reused: true,
                    cleanup: null,
                };
            }
        }

        if (!listening) {
            return startFallbackPhpServer(port);
        }
    }

    const dynamicPort = await findFreePort(FALLBACK_HOST);
    return startFallbackPhpServer(dynamicPort);
}

async function finalizePreparedRuntime(runtimeInfo, userPinnedProxyMode, note, cleanup = null) {
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
                note = note
                    ? `${note}; proxy ${runtimeInfo.proxy.origin} is occupied but not reusable, running tests in direct mode`
                    : `[e2e] proxy ${runtimeInfo.proxy.origin} is occupied but not reusable; running tests in direct mode`;
            }
        }
    }

    return { runtimeInfo, cleanup, note };
}

async function prepareRuntime(args = process.argv.slice(2)) {
    const userPinnedTarget = Boolean(process.env.PLAYWRIGHT_TARGET_ORIGIN);
    const userPinnedProxyMode = Object.prototype.hasOwnProperty.call(process.env, 'PLAYWRIGHT_DISABLE_PROXY');
    const runtimeStrategy = resolveRuntimeStrategy(args, process.env);
    const transportStrategy = resolveTransportStrategy(args, process.env);

    if (!userPinnedProxyMode && transportStrategy === 'direct') {
        process.env.PLAYWRIGHT_DISABLE_PROXY = '1';
    }

    if (!userPinnedTarget) {
        const detectedRuntimeInfo = readRuntimeInfo(process.env);
        const runtimeSource = String(detectedRuntimeInfo.runtime && detectedRuntimeInfo.runtime.source ? detectedRuntimeInfo.runtime.source : '');
        const hasWlsTarget = runtimeSource.startsWith('wls_');

        if (runtimeStrategy === 'wls') {
            if (!hasWlsTarget) {
                throw new Error('E2E runtime strategy "wls" was requested, but runtime-info.php did not discover a WLS target.');
            }

            const note = detectedRuntimeInfo.runtime.reachable
                ? `[e2e] using detected WLS runtime ${detectedRuntimeInfo.runtime.target_origin} because the selected specs require it`
                : `[e2e] waiting for detected WLS runtime ${detectedRuntimeInfo.runtime.target_origin} because the selected specs require it`;

            return finalizePreparedRuntime(detectedRuntimeInfo, userPinnedProxyMode, note);
        }

        if (runtimeStrategy !== 'fallback' && detectedRuntimeInfo.runtime.reachable) {
            const note = `[e2e] using detected runtime ${detectedRuntimeInfo.runtime.target_origin} (strategy: ${runtimeStrategy})`;
            return finalizePreparedRuntime(detectedRuntimeInfo, userPinnedProxyMode, note);
        }

        const preferredLocalRuntime = await resolveFallbackRuntime();
        process.env.PLAYWRIGHT_TARGET_ORIGIN = preferredLocalRuntime.origin;
        if (!userPinnedProxyMode) {
            process.env.PLAYWRIGHT_DISABLE_PROXY = '1';
        }

        const runtimeInfo = readRuntimeInfo(process.env);
        const note = preferredLocalRuntime.reused
            ? `[e2e] reusing local PHP runtime ${preferredLocalRuntime.origin} for a stable test target`
            : `[e2e] started fresh local PHP runtime ${preferredLocalRuntime.origin} for a stable test target`;

        return {
            runtimeInfo,
            cleanup: preferredLocalRuntime.cleanup,
            note,
        };
    }

    return finalizePreparedRuntime(readRuntimeInfo(process.env), userPinnedProxyMode, null);
}

async function main() {
    let cleanup = null;

    try {
        const rawArgs = process.argv.slice(2);
        const args = normalizePlaywrightArgs(rawArgs);
        const requestedTestFiles = resolveRequestedTestFiles(rawArgs).map(toProjectRelativePath);
        const selectedTestFiles = collectSelectedTestFiles(rawArgs, process.env);
        const executionGroups = buildExecutionGroups(selectedTestFiles);
        const useGroupedExecution = shouldUseGroupedExecution(executionGroups, requestedTestFiles, process.env);

        const runtimeStrategy = resolveRuntimeStrategy(args, process.env);
        const transportStrategy = resolveTransportStrategy(args, process.env) || 'auto';
        if (process.env.PLAYWRIGHT_SKIP_PREFLIGHT !== '1') {
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
        }

        if (useGroupedExecution) {
            return runGroupedExecution(rawArgs, executionGroups);
        }

        if (requestedTestFiles.length > 0) {
            process.env.PLAYWRIGHT_TEST_FILES = JSON.stringify(requestedTestFiles);
        } else {
            delete process.env.PLAYWRIGHT_TEST_FILES;
        }

        const preparedRuntime = await prepareRuntime(args);
        cleanup = preparedRuntime.cleanup;
        const runtimeInfo = preparedRuntime.runtimeInfo;

        console.log('[e2e] starting test flow...\n');
        if (preparedRuntime.note) {
            console.log(`${preparedRuntime.note}\n`);
        }
        console.log(`[e2e] runtime strategy: ${runtimeStrategy}`);
        console.log(`[e2e] transport strategy: ${transportStrategy}`);
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
            const playwrightArgs = stripFileArgs(args);
            const command = playwrightArgs.length > 0
                ? `npx playwright test ${playwrightArgs.join(' ')}`
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
