#!/usr/bin/env node
/**
 * Clean orphaned Playwright / MCP / headless Chrome leftovers after e2e or Agent browser work.
 *
 * SAFE: does NOT quit the user's daily Google Chrome.app.
 * Targets only automation leftovers (headless_shell, Chrome for Testing, orphan
 * chrome-devtools-mcp when :9222 is dead, stale /tmp playwright|chrome profiles).
 *
 * Usage:
 *   node tests/e2e/framework/cleanup-orphaned-browsers.js [--json] [--dry-run] [--close-acceptance-tabs]
 */

'use strict';

const { execFileSync, spawnSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

/**
 * @typedef {{
 *   dryRun: boolean,
 *   closeAcceptanceTabs: boolean,
 *   killedPids: number[],
 *   closedTabs: number,
 *   removedPaths: string[],
 *   skipped: string[],
 *   notes: string[],
 * }} CleanupReport
 */

function parseArgs(argv) {
  return {
    json: argv.includes('--json'),
    dryRun: argv.includes('--dry-run'),
    closeAcceptanceTabs: argv.includes('--close-acceptance-tabs'),
  };
}

function run(cmd, args, opts = {}) {
  try {
    return execFileSync(cmd, args, {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: opts.timeout ?? 15000,
    });
  } catch (error) {
    if (opts.allowFail) {
      return String(error.stdout || '') + String(error.stderr || '');
    }
    throw error;
  }
}

function listProcessLines() {
  if (process.platform === 'win32') {
    return [];
  }
  const out = run('ps', ['-axo', 'pid=,command='], { allowFail: true });
  return String(out || '')
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean);
}

function portListening(port) {
  if (process.platform === 'win32') {
    return false;
  }
  const out = run('lsof', ['-nP', `-iTCP:${port}`, '-sTCP:LISTEN'], { allowFail: true });
  return String(out || '').includes(`:${port}`);
}

/**
 * Match only automation leftovers — never the user's /Applications/Google Chrome.app main UI.
 * @param {string} command
 */
function isOrphanAutomationBrowser(command) {
  const cmd = String(command || '');
  if (!cmd) {
    return false;
  }
  // Never touch daily Chrome / Electron host apps.
  if (
    cmd.includes('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome')
    || cmd.includes('/Applications/Google Chrome.app/Contents/Frameworks/')
    || cmd.includes('/Applications/Cursor.app/')
    || cmd.includes('/Applications/Antigravity.app/')
    || cmd.includes('chrome_crashpad_handler')
  ) {
    return false;
  }

  if (/chrome-headless-shell/i.test(cmd)) {
    return true;
  }
  if (/Chrome for Testing/i.test(cmd)) {
    return true;
  }
  if (/ms-playwright/i.test(cmd) && /chrom/i.test(cmd)) {
    return true;
  }
  if (/playwright.*(chromium|chrome)/i.test(cmd) && /--user-data-dir=/i.test(cmd)) {
    return true;
  }
  if (/headless.?shell/i.test(cmd)) {
    return true;
  }
  // Disposable profiles under /tmp
  if (/--user-data-dir=\/tmp\//i.test(cmd) && /chrom/i.test(cmd)) {
    return true;
  }
  if (/dsh-chrome-profile/i.test(cmd)) {
    return true;
  }
  return false;
}

function isOrphanChromeDevtoolsMcp(command) {
  const cmd = String(command || '');
  if (!/chrome-devtools-mcp/i.test(cmd)) {
    return false;
  }
  // Keep live MCP only when debugging port is actually listening.
  return !portListening(9222);
}

/**
 * @param {{dryRun?: boolean, closeAcceptanceTabs?: boolean}} options
 * @returns {CleanupReport}
 */
function cleanupOrphanedBrowsers(options = {}) {
  /** @type {CleanupReport} */
  const report = {
    dryRun: !!options.dryRun,
    closeAcceptanceTabs: !!options.closeAcceptanceTabs,
    killedPids: [],
    closedTabs: 0,
    removedPaths: [],
    skipped: [],
    notes: [],
  };

  if (process.platform === 'win32') {
    report.notes.push('windows_cleanup_not_implemented');
    return report;
  }

  const lines = listProcessLines();
  const killTargets = [];
  for (const line of lines) {
    const match = line.match(/^(\d+)\s+(.*)$/);
    if (!match) {
      continue;
    }
    const pid = Number(match[1]);
    const command = match[2];
    if (!Number.isFinite(pid) || pid <= 1) {
      continue;
    }
    if (isOrphanAutomationBrowser(command) || isOrphanChromeDevtoolsMcp(command)) {
      killTargets.push({ pid, command: command.slice(0, 180) });
    }
  }

  for (const target of killTargets) {
    if (report.dryRun) {
      report.killedPids.push(target.pid);
      report.notes.push(`dry_run_kill:${target.pid}:${target.command}`);
      continue;
    }
    try {
      process.kill(target.pid, 'SIGTERM');
      report.killedPids.push(target.pid);
    } catch (error) {
      report.skipped.push(`kill_failed:${target.pid}:${error.message}`);
    }
  }

  // Give SIGTERM a moment, then SIGKILL stubborn leftovers.
  if (!report.dryRun && killTargets.length > 0) {
    try {
      execFileSync('sleep', ['0.4'], { stdio: 'ignore' });
    } catch {
      // ignore
    }
    for (const target of killTargets) {
      try {
        process.kill(target.pid, 0);
        process.kill(target.pid, 'SIGKILL');
      } catch {
        // already gone
      }
    }
  }

  const liveLines = report.dryRun ? lines : listProcessLines();
  const tmpCandidates = [
    path.join(os.tmpdir(), 'dsh-chrome-profile'),
    ...globTmp(/^playwright/i),
    ...globTmp(/^chrome_.*$/i),
    ...globTmp(/^puppeteer_dev_chrome_profile/i),
  ];
  for (const dir of tmpCandidates) {
    if (!fs.existsSync(dir)) {
      continue;
    }
    // Skip if still referenced by a live process.
    const stillUsed = liveLines.some((line) => line.includes(dir));
    if (stillUsed && !report.dryRun) {
      report.skipped.push(`in_use:${dir}`);
      continue;
    }
    if (report.dryRun) {
      report.removedPaths.push(dir);
      continue;
    }
    try {
      fs.rmSync(dir, { recursive: true, force: true });
      report.removedPaths.push(dir);
    } catch (error) {
      report.skipped.push(`rm_failed:${dir}:${error.message}`);
    }
  }

  if (options.closeAcceptanceTabs && process.platform === 'darwin') {
    report.closedTabs = closeAcceptanceTabsInGoogleChrome(report.dryRun, report);
  }

  report.notes.push(
    `killed=${report.killedPids.length}; removed=${report.removedPaths.length}; closed_tabs=${report.closedTabs}`,
  );
  return report;
}

function globTmp(nameRe) {
  const tmp = os.tmpdir();
  try {
    return fs.readdirSync(tmp)
      .filter((name) => nameRe.test(name))
      .map((name) => path.join(tmp, name))
      .filter((full) => {
        try {
          return fs.statSync(full).isDirectory();
        } catch {
          return false;
        }
      });
  } catch {
    return [];
  }
}

function closeAcceptanceTabsInGoogleChrome(dryRun, report) {
  if (dryRun) {
    report.notes.push('dry_run_close_acceptance_tabs');
    return 0;
  }
  const script = `
tell application "System Events"
  if not (exists process "Google Chrome") then return "closed=0"
end tell
tell application "Google Chrome"
  if (count of windows) = 0 then return "closed=0"
  set closedCount to 0
  repeat with w in windows
    set tabCount to count of tabs of w
    repeat with i from tabCount to 1 by -1
      set u to URL of tab i of w
      if u contains "test.weline.com" or u contains "weline.test" then
        close tab i of w
        set closedCount to closedCount + 1
      end if
    end repeat
  end repeat
  return "closed=" & closedCount
end tell
`;
  try {
    const result = spawnSync('osascript', ['-e', script], {
      encoding: 'utf8',
      timeout: 20000,
    });
    const text = String(result.stdout || '').trim();
    const match = text.match(/closed=(\d+)/);
    return match ? Number(match[1]) : 0;
  } catch (error) {
    report.skipped.push(`osascript_failed:${error.message}`);
    return 0;
  }
}

function main(argv) {
  const args = parseArgs(argv);
  const report = cleanupOrphanedBrowsers({
    dryRun: args.dryRun,
    closeAcceptanceTabs: args.closeAcceptanceTabs,
  });
  if (args.json) {
    process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
  } else {
    process.stdout.write(
      `[e2e-browser-cleanup] killed=${report.killedPids.length} removed=${report.removedPaths.length} `
      + `closed_tabs=${report.closedTabs} dry_run=${report.dryRun}\n`,
    );
    if (report.killedPids.length) {
      process.stdout.write(`  pids: ${report.killedPids.join(', ')}\n`);
    }
    if (report.removedPaths.length) {
      process.stdout.write(`  paths: ${report.removedPaths.join(', ')}\n`);
    }
    for (const note of report.skipped) {
      process.stdout.write(`  skip: ${note}\n`);
    }
  }
  return 0;
}

module.exports = {
  cleanupOrphanedBrowsers,
  isOrphanAutomationBrowser,
  isOrphanChromeDevtoolsMcp,
};

if (require.main === module) {
  process.exitCode = main(process.argv.slice(2));
}
