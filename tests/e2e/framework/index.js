// Must load before any spec/helper captures child_process: patches execFileSync
// with a fixture timeout so a stuck PHP fixture cannot freeze the whole run.
require('./fixture-timeout-guard');

const playwright = require('@playwright/test');
const runtime = require('./runtime');
const caseMeta = require('./case-meta');
const backendMenu = require('./backend-menu');
const storefrontScopeContext = require('./storefront-scope-context');

module.exports = {
  ...playwright,
  ...runtime,
  ...caseMeta,
  ...backendMenu,
  ...storefrontScopeContext,
};
