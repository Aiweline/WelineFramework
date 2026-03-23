const playwright = require('@playwright/test');
const runtime = require('./runtime');

module.exports = {
  ...playwright,
  ...runtime,
};
