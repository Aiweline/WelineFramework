// Playwright globalTeardown: always reap orphaned automation browsers after the run.
'use strict';

const { cleanupOrphanedBrowsers } = require('./framework/cleanup-orphaned-browsers');

module.exports = async function globalTeardownBrowserCleanup() {
  const report = cleanupOrphanedBrowsers({
    dryRun: process.env.PLAYWRIGHT_BROWSER_CLEANUP_DRY_RUN === '1',
    closeAcceptanceTabs: process.env.PLAYWRIGHT_CLOSE_ACCEPTANCE_TABS === '1',
  });
  // Keep teardown output short so CI logs stay readable.
  console.log(
    `[playwright:globalTeardown] browser cleanup killed=${report.killedPids.length} `
    + `removed=${report.removedPaths.length} closed_tabs=${report.closedTabs}`,
  );
};
