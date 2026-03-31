const fs = require('fs');
const http = require('http');
const https = require('https');
const path = require('path');
const { spawnSync } = require('child_process');
const { URL } = require('url');
const { getRuntimeInfo } = require('./runtime');

const rootDir = path.resolve(__dirname, '../../..');
const bootstrapRuntime = getRuntimeInfo({ refresh: true });
const proxyUrl = new URL(bootstrapRuntime.proxy.origin);
let wlsStartAttempted = false;

function resolveRuntime() {
  return getRuntimeInfo({ refresh: true });
}

function maybeStartWls(runtime) {
  if (wlsStartAttempted) {
    return;
  }

  const source = String(runtime.runtime && runtime.runtime.source ? runtime.runtime.source : '');
  if (!source.startsWith('wls_') || runtime.runtime.reachable !== false) {
    return;
  }

  wlsStartAttempted = true;
  const instanceName = String(runtime.runtime.instance_name || 'default').trim() || 'default';
  const args = ['bin/w', 'server:start'];
  if (instanceName !== 'default') {
    args.push(instanceName);
  }

  console.log(`[weline-e2e] target ${runtime.runtime.target_origin} is not reachable, starting WLS instance "${instanceName}"...`);
  const result = spawnSync('php', args, {
    cwd: rootDir,
    env: process.env,
    encoding: 'utf8',
  });

  if (result.stdout) {
    process.stdout.write(result.stdout);
  }
  if (result.stderr) {
    process.stderr.write(result.stderr);
  }
  if (result.error) {
    console.warn(`[weline-e2e] failed to start WLS automatically: ${result.error.message}`);
  }
}

function createUpstreamAgent(targetUrl) {
  return targetUrl.protocol === 'https:'
    ? new https.Agent({ rejectUnauthorized: false })
    : new http.Agent();
}

function getForwardedPort(targetUrl) {
  return targetUrl.port || (targetUrl.protocol === 'https:' ? '443' : '80');
}

function getForwardedHost(targetUrl) {
  const forwardedPort = getForwardedPort(targetUrl);
  const isDefaultPort = (targetUrl.protocol === 'https:' && forwardedPort === '443')
    || (targetUrl.protocol === 'http:' && forwardedPort === '80');

  return isDefaultPort ? targetUrl.hostname : `${targetUrl.hostname}:${forwardedPort}`;
}

function buildForwardedHeaders(clientRequest, targetUrl) {
  const browserFacingProto = proxyUrl.protocol.replace(':', '');
  const browserFacingPort = proxyUrl.port || (browserFacingProto === 'https' ? '443' : '80');
  // 必须使用浏览器访问代理时的 Host（含 :port），否则 PHP 的 getBaseHost() 会生成
  // https://127.0.0.1/static/...（无代理端口），子资源绕过代理直连上游默认端口导致 E2E 样式断言失败。
  const browserFacingHost =
    clientRequest.headers.host
    || clientRequest.headers.Host
    || getForwardedHost(proxyUrl);

  return {
    ...clientRequest.headers,
    host: browserFacingHost,
    'x-forwarded-host': browserFacingHost,
    'x-forwarded-proto': browserFacingProto,
    'x-forwarded-port': browserFacingPort,
    'weline-via-dispatcher': '1',
    'weline-original-host': browserFacingHost,
    'weline-original-scheme': browserFacingProto,
    'weline-original-port': browserFacingPort,
    'weline-original-ssl': browserFacingProto === 'https' ? 'on' : 'off',
  };
}

function sendJson(response, statusCode, payload) {
  if (!response || response.destroyed || response.writableEnded || response.headersSent) {
    return false;
  }

  response.writeHead(statusCode, {
    'content-type': 'application/json; charset=utf-8',
    'cache-control': 'no-store',
  });
  response.end(JSON.stringify(payload, null, 2));
  return true;
}

function rewritePath(pathname, runtime, searchParams) {
  const cleanPath = pathname || '/';
  if ((cleanPath === '/' || cleanPath === '') && searchParams && searchParams.has('preview_theme')) {
    return '/theme/frontend/theme-preview/gateway';
  }

  const segments = cleanPath.replace(/^\/+/, '').split('/').filter(Boolean);
  if (segments.length === 0) {
    return cleanPath;
  }

  const [first, second] = segments;
  const { backend, rest_frontend: restFrontend, rest_backend: restBackend } = runtime.routes;

  if (first === '@backend') {
    return `/${[backend, ...segments.slice(1)].join('/')}`;
  }

  if (first === '@api') {
    return `/${[restFrontend, ...segments.slice(1)].join('/')}`;
  }

  if (first === '@backend-api') {
    return `/${[restBackend, ...segments.slice(1)].join('/')}`;
  }

  if ([backend, restFrontend, restBackend].includes(first)) {
    return cleanPath;
  }

  if (first === 'admin' || second === 'admin' || second === 'backend') {
    return `/${[backend, ...segments].join('/')}`;
  }

  return cleanPath;
}

function rewriteLocationHeader(value, runtime, targetUrl) {
  if (!value) {
    return value;
  }

  const rewriteOne = locationValue => {
    try {
      const parsed = new URL(locationValue, runtime.runtime.target_origin);
      if (parsed.origin !== targetUrl.origin) {
        return locationValue;
      }

      parsed.protocol = proxyUrl.protocol;
      parsed.hostname = proxyUrl.hostname;
      parsed.port = proxyUrl.port;
      return parsed.toString();
    } catch (error) {
      return locationValue;
    }
  };

  if (Array.isArray(value)) {
    return value.map(rewriteOne);
  }

  return rewriteOne(value);
}

const DASHBOARD_TEMPLATE = path.join(__dirname, 'e2e-proxy-dashboard.html');

function buildDashboardPayload(runtime) {
  return {
    proxy: runtime.proxy,
    runtime: runtime.runtime,
    routes: runtime.routes,
    paths: runtime.paths,
  };
}

function renderDashboardHtml(runtime) {
  let template;
  try {
    template = fs.readFileSync(DASHBOARD_TEMPLATE, 'utf8');
  } catch {
    template = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>E2E</title></head><body><p>Dashboard template missing.</p></body></html>';
  }
  const json = JSON.stringify(buildDashboardPayload(runtime));
  const safe = json.replace(/</g, '\\u003c');
  return template.replace('__E2E_RUNTIME_JSON__', safe);
}

function handleWellKnown(response, pathname, runtime) {
  if (pathname === '/.well-known/weline-e2e' || pathname === '/.well-known/weline-e2e/') {
    const html = renderDashboardHtml(runtime);
    response.writeHead(200, {
      'content-type': 'text/html; charset=utf-8',
      'cache-control': 'no-store',
    });
    response.end(html);
    return true;
  }

  if (pathname === '/.well-known/weline-e2e/health') {
    // Playwright `webServer.url` only accepts a successful response; 503 here caused
    // indefinite wait until webServer.timeout even though the proxy is already listening.
    if (runtime.runtime && runtime.runtime.reachable === false) {
      maybeStartWls(runtime);
    }

    const refreshed = resolveRuntime();
    const reachable = !(refreshed.runtime && refreshed.runtime.reachable === false);
    response.writeHead(200, {
      'content-type': 'application/json; charset=utf-8',
      'cache-control': 'no-store',
      'x-weline-e2e-upstream-reachable': reachable ? '1' : '0',
    });
    response.end(
      JSON.stringify({
        ok: true,
        proxy: 'up',
        target_reachable: reachable,
        target: refreshed.runtime?.target_origin ?? null,
        source: refreshed.runtime?.source ?? null,
      })
    );
    return true;
  }

  if (pathname === '/.well-known/weline-e2e/runtime.json') {
    sendJson(response, 200, runtime);
    return true;
  }

  return false;
}

function proxyRequest(clientRequest, clientResponse) {
  const runtime = resolveRuntime();
  const targetUrl = new URL(runtime.runtime.target_origin);
  const upstreamModule = targetUrl.protocol === 'https:' ? https : http;
  const upstreamAgent = createUpstreamAgent(targetUrl);
  const incomingUrl = new URL(clientRequest.url, runtime.proxy.origin);

  if (handleWellKnown(clientResponse, incomingUrl.pathname, runtime)) {
    return;
  }

  const upstreamUrl = new URL(runtime.runtime.target_origin);
  upstreamUrl.pathname = rewritePath(incomingUrl.pathname, runtime, incomingUrl.searchParams);
  upstreamUrl.search = incomingUrl.search;

  const headers = buildForwardedHeaders(clientRequest, targetUrl);

  const requestOptions = {
    protocol: targetUrl.protocol,
    hostname: targetUrl.hostname,
    port: targetUrl.port || (targetUrl.protocol === 'https:' ? 443 : 80),
    method: clientRequest.method,
    path: `${upstreamUrl.pathname}${upstreamUrl.search}`,
    headers,
    agent: upstreamAgent,
    rejectUnauthorized: false,
  };

  const upstreamRequest = upstreamModule.request(requestOptions, upstreamResponse => {
    const responseHeaders = { ...upstreamResponse.headers };
    if (responseHeaders.location) {
      responseHeaders.location = rewriteLocationHeader(responseHeaders.location, runtime, targetUrl);
    }

    if (clientResponse.destroyed || clientResponse.writableEnded) {
      upstreamResponse.destroy();
      return;
    }

    clientResponse.writeHead(upstreamResponse.statusCode || 502, responseHeaders);
    upstreamResponse.pipe(clientResponse);

    upstreamResponse.on('error', error => {
      if (!clientResponse.destroyed && !clientResponse.writableEnded) {
        clientResponse.destroy(error);
      }
    });
  });

  upstreamRequest.on('error', error => {
    const sent = sendJson(clientResponse, 502, {
      error: 'upstream_request_failed',
      message: error.message,
      target: runtime.runtime.target_origin,
      path: `${upstreamUrl.pathname}${upstreamUrl.search}`,
    });
    if (!sent && !clientResponse.destroyed && !clientResponse.writableEnded) {
      clientResponse.destroy(error);
    }
  });

  clientRequest.on('aborted', () => {
    upstreamRequest.destroy();
  });
  clientResponse.on('close', () => {
    if (!upstreamRequest.destroyed) {
      upstreamRequest.destroy();
    }
  });

  clientRequest.pipe(upstreamRequest);
}

function createServer() {
  if (proxyUrl.protocol === 'https:') {
    const certPath = bootstrapRuntime.proxy.cert_path;
    const keyPath = bootstrapRuntime.proxy.key_path;
    if (!fs.existsSync(certPath) || !fs.existsSync(keyPath)) {
      throw new Error(`HTTPS proxy certificate is missing: cert=${certPath}, key=${keyPath}`);
    }

    return https.createServer(
      {
        cert: fs.readFileSync(certPath),
        key: fs.readFileSync(keyPath),
      },
      proxyRequest
    );
  }

  return http.createServer(proxyRequest);
}

const server = createServer();
maybeStartWls(resolveRuntime());

// 动态端口分配：如果首选端口被占用，自动换到下一个可用端口
const net = require('net');
function findAvailablePort(startPort, hostname, maxAttempts = 10) {
  return new Promise((resolve) => {
    let port = startPort;
    let attempts = 0;
    const tryPort = () => {
      if (attempts >= maxAttempts) {
        console.warn(`[weline-e2e] all ports from ${startPort} to ${startPort + maxAttempts - 1} are busy`);
        resolve(null);
        return;
      }
      const sock = net.createConnection({ port, host: hostname, timeout: 1000 });
      sock.on('connect', () => {
        sock.destroy();
        attempts++;
        port = startPort + attempts;
        tryPort();
      });
      sock.on('timeout', () => {
        sock.destroy();
        attempts++;
        port = startPort + attempts;
        tryPort();
      });
      sock.on('error', () => {
        sock.destroy();
        resolve(port);
      });
    };
    tryPort();
  });
}

// 将实际监听的端口写入文件，供 playwright.config.js 读取
const ACTIVE_PORT_FILE = path.join(__dirname, '.active-proxy-port');

async function startServer() {
  const preferredPort = Number(proxyUrl.port) || 3999;
  const hostname = proxyUrl.hostname || '127.0.0.1';
  const actualPort = await findAvailablePort(preferredPort, hostname);

  if (!actualPort) {
    console.error('[weline-e2e] no available port found, exiting');
    process.exit(1);
  }

  server.listen(actualPort, hostname, () => {
    const runtime = resolveRuntime();
    const actualOrigin = `${proxyUrl.protocol}//${hostname}:${actualPort}`;
    // 写入实际端口到文件（格式：PORT=3999）
    fs.writeFileSync(ACTIVE_PORT_FILE, `PORT=${actualPort}`, 'utf8');
    console.log(`[weline-e2e] proxy listening on ${actualOrigin}`);
    console.log(`[weline-e2e] proxy target ${runtime.runtime.target_origin}`);
    console.log(`[weline-e2e] dashboard (后台默认主题风) ${actualOrigin}/.well-known/weline-e2e/`);
    if (actualPort !== preferredPort) {
      console.log(`[weline-e2e] note: preferred port ${preferredPort} was busy, using ${actualPort}`);
    }
  });
}

startServer();

function closeServer(exitCode) {
  server.close(() => process.exit(exitCode));
}

process.on('SIGINT', () => closeServer(130));
process.on('SIGTERM', () => closeServer(0));
