#!/usr/bin/env node
/**
 * Minimal Admin REST demo for remote translation assist.
 * Env: WELINE_BASE_URL, WELINE_ADMIN_PREFIX, WELINE_ADMIN_TOKEN
 * Optional: WELINE_WEBSITE_ID (default 0), WELINE_LOCALES (comma-separated, default en_US)
 * Optional: WELINE_REMOTE_TYPE=phrase|meta|local_model (default phrase)
 * Optional: WELINE_TLS_INSECURE=1 — 跳过 TLS 校验（本机 *.test.weline.com 自签；与 PHP demo CURLOPT_SSL_VERIFYPEER=false 对齐）
 *
 * Does NOT commit or hardcode tokens. ingest/collect examples are commented.
 */

const base = String(process.env.WELINE_BASE_URL || '').replace(/\/$/, '');
const prefix = String(process.env.WELINE_ADMIN_PREFIX || '').replace(/^\/|\/$/g, '');
const token = String(process.env.WELINE_ADMIN_TOKEN || '');
const websiteId = Number.parseInt(process.env.WELINE_WEBSITE_ID || '0', 10);
const locales = String(process.env.WELINE_LOCALES || 'en_US')
  .split(',')
  .map((s) => s.trim())
  .filter(Boolean);
const type = String(process.env.WELINE_REMOTE_TYPE || 'phrase').trim().toLowerCase();
if (!['phrase', 'meta', 'local_model'].includes(type)) {
  console.error('WELINE_REMOTE_TYPE must be phrase|meta|local_model');
  process.exit(1);
}

if (!base || !prefix || !token) {
  console.error('Missing env: WELINE_BASE_URL / WELINE_ADMIN_PREFIX / WELINE_ADMIN_TOKEN');
  process.exit(1);
}
if (locales.length === 0) {
  console.error('WELINE_LOCALES must list at least one locale');
  process.exit(1);
}

// Align with PHP demo (SSL_VERIFYPEER=false): local/test hosts or explicit opt-in.
const insecureTls =
  process.env.WELINE_TLS_INSECURE === '1' ||
  /\.test\.weline\.com(?::\d+)?$/i.test(base) ||
  /^(https?:\/\/)?(127\.0\.0\.1|localhost)(:\d+)?/i.test(base);
if (insecureTls) {
  process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
}

const root = `${base}/${prefix}`;

/**
 * @param {string} method
 * @param {string} url
 * @param {object|null} json
 */
async function request(method, url, json = null) {
  const headers = {
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
  };
  /** @type {RequestInit} */
  const init = { method, headers };
  if (json !== null) {
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(json);
  }
  let res;
  try {
    res = await fetch(url, init);
  } catch (err) {
    const cause = err && typeof err === 'object' && 'cause' in err ? err.cause : null;
    const detail = cause instanceof Error ? cause.message : err instanceof Error ? err.message : String(err);
    throw new Error(
      `fetch failed: ${detail}` +
        (insecureTls
          ? ''
          : ' (本机自签证书可设 WELINE_TLS_INSECURE=1)'),
    );
  }
  const text = await res.text();
  let body = null;
  try {
    body = JSON.parse(text);
  } catch {
    body = null;
  }
  return { code: res.status, body, raw: text };
}

function printStep(label, res) {
  console.log(`=== ${label} HTTP ${res.code} ===`);
  if (res.body) {
    console.log(JSON.stringify(res.body, null, 2));
  } else {
    console.log(String(res.raw).slice(0, 500));
  }
}

async function main() {
  console.log(`type=${type}`);

  const websites = await request(
    'GET',
    `${root}/websites/rest/v1/remote-translation-catalog/websites`,
  );
  printStep('getWebsites', websites);

  const languages = await request(
    'GET',
    `${root}/websites/rest/v1/remote-translation-catalog/languages?website_id=${websiteId}`,
  );
  printStep('getLanguages', languages);

  const pending = await request(
    'POST',
    `${root}/i18n/rest/v1/remote-translation/pending`,
    {
      website_id: websiteId,
      locales,
      limit: 5,
      cursor: null,
      type,
    },
  );
  printStep('postPending', pending);

  /*
   * Phrase/meta ingest example (uncomment after filling real translations):
   *
   * const ingest = await request('POST', `${root}/i18n/rest/v1/remote-translation/ingest`, {
   *   website_id: websiteId,
   *   type: 'phrase', // or meta
   *   items: [
   *     { source: '你好', locale: 'en_US', translation: 'Hello' },
   *   ],
   * });
   * printStep('postIngest', ingest);
   *
   * local_model ingest (only when pending returned rows):
   *
   * const ingest = await request('POST', `${root}/i18n/rest/v1/remote-translation/ingest`, {
   *   website_id: websiteId,
   *   type: 'local_model',
   *   items: [{
   *     local_model: 'Weline\\…\\FooLocalDescription',
   *     local_id_field: 'entity_id',
   *     record_id: 42,
   *     field: 'name',
   *     locale: 'en_US',
   *     translation: 'Hanfu top',
   *     source: '汉服上衣',
   *   }],
   * });
   */

  /*
   * Optional collect — phrase/meta only (local_model → 422):
   *
   * const start = await request('POST', `${root}/i18n/rest/v1/remote-translation/collect-start`, {
   *   website_id: websiteId,
   *   type: 'phrase',
   * });
   * printStep('postCollectStart', start);
   */

  console.log(`Done (pending only, type=${type}). Uncomment ingest/collect in index.js when ready.`);
  process.exit(pending.code >= 200 && pending.code < 300 ? 0 : 2);
}

main().catch((err) => {
  console.error(err instanceof Error ? err.message : String(err));
  process.exit(1);
});
