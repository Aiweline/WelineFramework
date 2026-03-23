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

function handleWellKnown(response, pathname, runtime) {
  if (pathname === '/.well-known/weline-e2e/health') {
    if (runtime.runtime && runtime.runtime.reachable === false) {
      maybeStartWls(runtime);
      sendJson(response, 503, {
        status: 'waiting_for_target',
        target: runtime.runtime.target_origin,
        source: runtime.runtime.source,
      });
      return true;
    }

    response.writeHead(200, {
      'content-type': 'text/plain; charset=utf-8',
      'cache-control': 'no-store',
    });
    response.end('ok');
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

  const headers = {
    ...clientRequest.headers,
    host: targetUrl.host,
    'x-forwarded-host': proxyUrl.host,
    'x-forwarded-proto': proxyUrl.protocol.replace(':', ''),
    'x-forwarded-port': proxyUrl.port || (proxyUrl.protocol === 'https:' ? '443' : '80'),
  };

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
server.listen(Number(proxyUrl.port), proxyUrl.hostname, () => {
  const runtime = resolveRuntime();
  console.log(`[weline-e2e] proxy listening on ${runtime.proxy.origin}`);
  console.log(`[weline-e2e] proxy target ${runtime.runtime.target_origin}`);
});

function closeServer(exitCode) {
  server.close(() => process.exit(exitCode));
}

process.on('SIGINT', () => closeServer(130));
process.on('SIGTERM', () => closeServer(0));
