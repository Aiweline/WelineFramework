const playwright = require('@playwright/test');
const runtime = require('./runtime');
const caseMeta = require('./case-meta');

module.exports = {
  ...playwright,
  ...runtime,
  ...caseMeta,
};
