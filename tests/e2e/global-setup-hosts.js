/**
 * Playwright globalSetup：可选将 E2E 用 FQDN 写入本机 hosts（与 php bin/w server:hosts:add 同源）
 * 启用：PLAYWRIGHT_E2E_HOSTS_FQDN=test.weline.local
 */
const path = require('path');
const { execFileSync } = require('child_process');

module.exports = async function globalSetup() {
  const fqdn = String(process.env.PLAYWRIGHT_E2E_HOSTS_FQDN || '').trim();
  if (!fqdn) {
    return;
  }
  const rootDir = path.resolve(__dirname, '../..');
  console.log(`[playwright globalSetup] server:hosts:add ${fqdn}`);
  execFileSync('php', ['bin/w', 'server:hosts:add', fqdn], {
    cwd: rootDir,
    stdio: 'inherit',
  });
};
