const fs = require('node:fs');
const path = require('node:path');
const { createHash } = require('node:crypto');
const { frozenWebsitePrefix } = require('./theme-phtml-query-diagnostic');
const sha = bytes => createHash('sha256').update(bytes).digest('hex');

function verifySelectedWebsite(dom, item, root) {
  const scopes = dom?.server_config_scopes || [];
  if (scopes.length) return { confirmed: scopes.some(scope => Number(scope.website_id) === Number(item.request_scope.website_id)), source: 'document_configScope' };
  const failure = reason => ({ confirmed: false, source: 'declared_switcher_runtime', reason });
  try {
    const reference = item.website_identity_contract;
    if (!reference) return failure('No explicit source identity contract');
    const bytes = fs.readFileSync(reference.file);
    if (sha(bytes) !== reference.sha256) return failure('Identity contract SHA drift');
    const contract = JSON.parse(bytes);
    if (contract.protocol !== 'website-scope-declared-switcher-runtime.v1'
      || !Object.keys(contract.source_sha256 || {}).length
      || !Object.entries(contract.source_sha256).every(([file, expected]) => sha(fs.readFileSync(path.join(root, file))) === expected)) return failure('Identity source contract unavailable or source SHA drift');
    const prefix = frozenWebsitePrefix(item);
    if (prefix === null) return failure('Frozen website domain and mount unavailable');
    const declarations = dom.declared_website_scopes || [];
    const runtime = dom.frontend_runtime_identity || [];
    if (!declarations.length || runtime.length !== 1) return failure('Missing declarations or unique runtime identity');
    const websiteId = Number(item.request_scope.website_id);
    if (!declarations.every(scope => /^\d+$/.test(String(scope.website_id)) && Number(scope.website_id) === websiteId && scope.mount === prefix.replace(/^\//, ''))) return failure('Conflicting or incorrect declared website ID or mount');
    const origin = new URL(item.url).origin;
    const base = new URL(runtime[0].base_host), endpoint = new URL(runtime[0].endpoint);
    const endpoints = [prefix + '/api/framework/query-bin'];
    if (typeof item.locale === 'string' && new URL(item.url).pathname.startsWith(prefix + '/' + item.locale + '/')) endpoints.push(prefix + '/api/' + item.locale + '/framework/query-bin');
    if (base.origin !== origin || base.pathname !== prefix + '/' || base.search || base.hash || base.username || base.password
      || endpoint.origin !== origin || !endpoints.includes(endpoint.pathname) || endpoint.search || endpoint.hash || endpoint.username || endpoint.password) return failure('Runtime host or website mount differs from frozen domain');
    return { confirmed: true, source: 'declared_switcher_runtime', website_id: websiteId, mount: prefix, declaration_count: declarations.length, runtime_base_host: runtime[0].base_host, runtime_endpoint: runtime[0].endpoint, source_contract_sha256: reference.sha256 };
  } catch { return failure('Identity evidence unavailable'); }
}
module.exports = { verifySelectedWebsite };
