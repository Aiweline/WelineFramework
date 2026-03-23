const { buildBackendUrl, getBackendRoot, getBaseUrl, loginAsAdmin } = require('../../../framework');

function buildWorkbenchUrl(backendRoot = getBackendRoot(), provider = 'pagebuilder', fakeMode = false) {
  const normalizedBackendRoot = String(backendRoot || getBackendRoot()).replace(/\/+$/, '');
  const url = new URL(`${normalizedBackendRoot}/websites/backend/site-builder-agent/index`);
  url.searchParams.set('provider', provider);
  if (fakeMode) {
    url.searchParams.set('fake_mode', '1');
  }
  return url.toString();
}

module.exports = {
  buildWorkbenchUrl,
  buildBackendUrl,
  getBaseUrl,
  loginAsAdmin,
};
