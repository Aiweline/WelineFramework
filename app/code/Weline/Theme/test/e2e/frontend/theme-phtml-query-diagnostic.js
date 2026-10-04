const { execFileSync } = require('child_process');
const { createHash } = require('crypto');

// 仅复用生产协议解码器，并在内存中裁剪掉认证值与业务数据。
const decoder = String.raw`
$root = $argv[1];
require $root . '/app/code/Weline/Framework/Binary/Limits.php';
require $root . '/app/code/Weline/Framework/Binary/WelineBinaryCodec.php';
$value = (new \Weline\Framework\Binary\WelineBinaryCodec())->decodePacket(stream_get_contents(STDIN));
$safe = [];
foreach (['type', 'provider', 'operation', 'request_id', 'ok'] as $key) {
    if (isset($value[$key]) && is_scalar($value[$key])) $safe[$key] = $value[$key];
}
if (isset($value['error']) && is_array($value['error'])) {
    $safe['error'] = array_filter(array_intersect_key($value['error'], ['code' => true, 'message' => true]), 'is_scalar');
}
echo json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
`;

function decodeSafe(bytes, root) {
  if (!Buffer.isBuffer(bytes)) return { available: false };
  try {
    const value = JSON.parse(execFileSync('php', ['-r', decoder, root], { input: bytes, timeout: 5000, maxBuffer: 65536, stdio: ['pipe', 'pipe', 'pipe'] }).toString());
    if (value.error?.message) value.error.message = String(value.error.message).replace(/((?:token|signature|capability|cookie|secret)\s*[=:]\s*)\S+/gi, '$1[redacted]');
    return { available: true, ...value };
  } catch { return { available: false, reason: 'existing WQB1 codec could not decode packet' }; }
}

async function observeQueryResponse(response, root, started) {
  const request = response.request();
  const responseAt = Date.now();
  const headers = response.headers();
  const requestHeaders = await request.allHeaders();
  const signedNames = ['x-weline-worker-session', 'x-weline-worker-capability', 'x-weline-worker-nonce', 'x-weline-worker-timestamp', 'x-weline-worker-body-hash', 'x-weline-worker-signature'];
  const securityFields = signedNames.map(field => ({ field, present: !!requestHeaders[field], value_sha256: requestHeaders[field] ? createHash('sha256').update(requestHeaders[field]).digest('hex') : null }));
  const cookieNames = (requestHeaders.cookie || '').split(';').map(part => part.split('=')[0].trim()).filter(Boolean);
  const body = await response.body();
  // 先保留响应内存；正常 DOM 快照完成后才同步解码，避免观测解码影响取样时序。
  return { finalize: () => ({ started, response_at: responseAt, status: response.status(), url: response.url(), request_id: headers['x-weline-request-id'],
    response_headers: Object.fromEntries(Object.entries(headers).filter(([key]) => /^x-weline-query-bin-(type|provider|operation|time)$|^content-type$|^cache-control$/.test(key))),
    request_security_fields: securityFields, http_state_names: cookieNames, auth_header_exists: !!requestHeaders.authorization,
    request_summary: decodeSafe(request.postDataBuffer(), root), response_summary: decodeSafe(body, root) }) };
}

// 普通验收只读取已实际失败的包，成功请求保持原观测开销。
function frozenWebsitePrefix(item) {
  try {
    const fs = require('node:fs');
    const proof = item.transport_proof.configuration_proof;
    const bytes = fs.readFileSync(proof.file);
    if (createHash('sha256').update(bytes).digest('hex') !== proof.sha256) return null;
    const catalogSource = JSON.parse(bytes).catalog;
    const catalogBytes = fs.readFileSync(catalogSource.file);
    if (createHash('sha256').update(catalogBytes).digest('hex') !== catalogSource.sha256) return null;
    const url = new URL(item.url);
    const prefixes = [...new Set(JSON.parse(catalogBytes).domains
      .filter(row => String(row.website_id) === String(item.request_scope.website_id) && row.domain === url.hostname)
      .map(row => String(row.sub_path || '').replace(/\/$/, ''))
      .filter(prefix => prefix === '' || (/^\/[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/.test(prefix)
        && (url.pathname === prefix || url.pathname.startsWith(prefix + '/')))))];
    return prefixes.length === 1 ? prefixes[0] : null;
  } catch { return null; }
}

function shouldObserveFailedQueryResponse(responseUrl, status, pageUrl, locale, websitePrefix = '') {
  if (!(status >= 400)) return false;
  try {
    const endpoint = new URL(responseUrl);
    const page = new URL(pageUrl);
    const bases = ['', ...(typeof websitePrefix === 'string' && websitePrefix ? [websitePrefix] : [])];
    return endpoint.origin === page.origin && !endpoint.username && !endpoint.password
      && bases.some(prefix => endpoint.pathname === `${prefix}/api/framework/query-bin`
        || (typeof locale === 'string' && endpoint.pathname === `${prefix}/api/${locale}/framework/query-bin`));
  } catch { return false; }
}

async function observeFailedQueryResponse(response, root, started) {
  const headers = response.headers();
  const responseAt = Date.now();
  const body = await response.body();
  const requestBytes = response.request().postDataBuffer();
  return { finalize: () => ({ started, response_at: responseAt, status: response.status(), request_id: headers['x-weline-request-id'],
    response_headers: Object.fromEntries(Object.entries(headers).filter(([key]) => /^x-weline-query-bin-(type|provider|operation|time)$|^content-type$/.test(key))),
    request_summary: decodeSafe(requestBytes, root), response_summary: decodeSafe(body, root) }) };
}

module.exports = { observeQueryResponse, observeFailedQueryResponse, shouldObserveFailedQueryResponse, frozenWebsitePrefix, decodeSafe };
