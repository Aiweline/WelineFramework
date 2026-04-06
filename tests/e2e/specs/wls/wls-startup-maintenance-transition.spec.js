// @weline-e2e-runtime fallback
// @weline-e2e-transport direct
/**
 * WLS startup transition E2E:
 * 1. dispatcher starts and the first request waits during startup
 * 2. maintenance worker comes online and serves maintenance
 * 3. normal workers become READY and traffic returns to normal pages
 *
 * Enable with:
 *   $env:WLS_STARTUP_TRANSITION_E2E='1'
 *   php bin/w e2e:run tests/e2e/specs/wls/wls-startup-maintenance-transition.spec.js --project=chromium
 */

const fs = require('fs');
const net = require('net');
const path = require('path');
const http = require('http');
const { spawn, spawnSync } = require('child_process');
const { test, expect } = require('@playwright/test');

const ROOT_DIR = path.resolve(__dirname, '../../../..');
const RUN = process.env.WLS_STARTUP_TRANSITION_E2E === '1';
const HOST = '127.0.0.1';
const MAINTENANCE_PATTERN = /网站维护|网站正在维护|maintenance/i;

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

function findFreePort(host = HOST) {
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

async function findSafeMainPort(host = HOST, startPort = 18080, endPort = 18980) {
  for (let port = startPort; port <= endPort; port += 1) {
    try {
      const available = await new Promise(resolve => {
        const server = net.createServer();
        server.unref();
        server.once('error', () => resolve(false));
        server.listen(port, host, () => {
          server.close(() => resolve(true));
        });
      });

      if (available) {
        return port;
      }
    } catch {
      // Continue scanning.
    }
  }

  return findFreePort(host);
}

function waitForPortListening(port, host = HOST, timeoutMs = 15000) {
  return new Promise((resolve, reject) => {
    const deadline = Date.now() + timeoutMs;
    const attempt = () => {
      const socket = net.createConnection({ port, host });
      socket.setTimeout(1000);
      socket.once('connect', () => {
        socket.destroy();
        resolve();
      });
      const retry = () => {
        socket.destroy();
        if (Date.now() >= deadline) {
          reject(new Error(`port ${host}:${port} did not start listening within ${timeoutMs}ms`));
          return;
        }
        setTimeout(attempt, 150);
      };
      socket.once('timeout', retry);
      socket.once('error', retry);
    };

    attempt();
  });
}

function requestText(origin, requestPath = '/', timeoutMs = 15000) {
  return new Promise((resolve, reject) => {
    const startedAt = Date.now();
    const target = new URL(requestPath, origin);
    const req = http.request(
      {
        protocol: target.protocol,
        hostname: target.hostname,
        port: target.port,
        path: `${target.pathname}${target.search}`,
        method: 'GET',
        headers: {
          Host: target.host,
          Connection: 'close',
          'User-Agent': 'weline-startup-e2e',
        },
      },
      res => {
        const chunks = [];
        res.on('data', chunk => chunks.push(Buffer.from(chunk)));
        res.on('end', () => {
          resolve({
            status: res.statusCode || 0,
            headers: res.headers,
            body: Buffer.concat(chunks).toString('utf8'),
            durationMs: Date.now() - startedAt,
          });
        });
      }
    );

    req.setTimeout(timeoutMs, () => {
      req.destroy(new Error(`request timeout after ${timeoutMs}ms`));
    });
    req.on('error', reject);
    req.end();
  });
}

function isMaintenanceResponse(result) {
  return MAINTENANCE_PATTERN.test(String(result.body || ''));
}

async function waitForNormalResponse(origin, timeoutMs = 30000) {
  const deadline = Date.now() + timeoutMs;
  let lastResult = null;
  while (Date.now() < deadline) {
    try {
      lastResult = await requestText(origin, '/', 8000);
      if (lastResult.status === 200 && !isMaintenanceResponse(lastResult)) {
        return lastResult;
      }
    } catch (error) {
      lastResult = { error };
    }
    await sleep(400);
  }

  throw new Error(`normal worker response not observed within ${timeoutMs}ms; last=${JSON.stringify(lastResult)}`);
}

function runPhp(args, env = {}, timeout = 120000) {
  const result = spawnSync('php', args, {
    cwd: ROOT_DIR,
    env: { ...process.env, ...env },
    encoding: 'utf8',
    timeout,
  });

  if (result.error) {
    throw result.error;
  }

  return result;
}

function spawnPhp(args, env = {}) {
  const child = spawn('php', args, {
    cwd: ROOT_DIR,
    env: { ...process.env, ...env },
    stdio: 'ignore',
    detached: true,
  });
  child.unref();

  return {
    child,
  };
}

function waitForChildExit(child, timeoutMs = 30000) {
  return new Promise((resolve, reject) => {
    if (child.exitCode !== null) {
      resolve(child.exitCode);
      return;
    }

    const timer = setTimeout(() => {
      reject(new Error(`child process did not exit within ${timeoutMs}ms`));
    }, timeoutMs);

    child.once('exit', code => {
      clearTimeout(timer);
      resolve(code ?? 0);
    });
    child.once('error', error => {
      clearTimeout(timer);
      reject(error);
    });
  });
}

function cleanupInstanceFiles(instanceName) {
  const instanceFile = path.join(ROOT_DIR, 'var', 'server', 'instances', `${instanceName}.json`);
  const configFile = path.join(ROOT_DIR, 'var', 'server', 'config', `${instanceName}.json`);
  for (const file of [instanceFile, configFile]) {
    if (fs.existsSync(file)) {
      try {
        fs.unlinkSync(file);
      } catch {
        // Best-effort cleanup.
      }
    }
  }
}

test.describe('WLS startup maintenance transition', () => {
  test.describe.configure({ timeout: 240000, retries: process.env.CI ? 1 : 0 });

  test('dispatcher waits, maintenance takes over, then normal workers serve traffic', async () => {
    test.skip(!RUN, '设置 WLS_STARTUP_TRANSITION_E2E=1 后才执行该用例');

    const mainPort = await findSafeMainPort();
    const instanceName = `e2e-startup-${Date.now()}-${process.pid}`;
    const origin = `http://${HOST}:${mainPort}`;
    const maintenanceDelayMs = Number(process.env.WLS_E2E_MAINTENANCE_READY_DELAY_MS || 2500);
    const workerDelayMs = Number(process.env.WLS_E2E_WORKER_READY_DELAY_MS || 9000);
    const listenTimeoutMs = Math.max(120000, workerDelayMs + 60000);

    const env = {
      WLS_E2E_MAINTENANCE_READY_DELAY_MS: String(maintenanceDelayMs),
      WLS_E2E_WORKER_READY_DELAY_MS: String(workerDelayMs),
    };

    const started = spawnPhp(
      ['bin/w', 'server:start', instanceName, '-p', String(mainPort), '-c', '2', '--no-ssl', '--no-daemon'],
      env
    );

    try {
      await waitForPortListening(mainPort, HOST, listenTimeoutMs);
      expect(started.child.exitCode).toBeNull();

      const firstResponse = await requestText(origin, '/', 20000);
      expect(firstResponse.durationMs).toBeGreaterThanOrEqual(Math.max(1000, maintenanceDelayMs - 800));
      expect(
        isMaintenanceResponse(firstResponse),
        `expected maintenance response during startup, got status=${firstResponse.status}, body=${firstResponse.body.slice(0, 220)}`
      ).toBeTruthy();

      const secondResponse = await requestText(origin, '/', 8000);
      expect(
        isMaintenanceResponse(secondResponse),
        `expected maintenance to continue before normal workers become ready, got status=${secondResponse.status}, body=${secondResponse.body.slice(0, 220)}`
      ).toBeTruthy();

      const normalResponse = await waitForNormalResponse(origin, workerDelayMs + 25000);
      expect(normalResponse.status).toBe(200);
      expect(isMaintenanceResponse(normalResponse)).toBeFalsy();
      expect(normalResponse.body.length).toBeGreaterThan(50);
    } finally {
      runPhp(['bin/w', 'server:stop', instanceName, '-f'], {}, 120000);
      try {
        await waitForChildExit(started.child, 30000);
      } catch {
        if (process.platform === 'win32' && started.child.pid) {
          spawnSync('taskkill', ['/PID', String(started.child.pid), '/T', '/F'], {
            cwd: ROOT_DIR,
            stdio: 'ignore',
          });
        } else {
          started.child.kill('SIGTERM');
        }
      }
      cleanupInstanceFiles(instanceName);
    }
  });
});
