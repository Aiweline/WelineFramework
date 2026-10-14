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
