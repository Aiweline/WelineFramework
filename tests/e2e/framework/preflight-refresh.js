const path = require('path');
const { spawnSync } = require('child_process');

const ROOT_DIR = path.resolve(__dirname, '../../..');
const PREFLIGHT_SCRIPT = path.join(__dirname, 'preflight-refresh.php');

function runFrameworkPreflight(resolvePhpBinary, env = process.env) {
  if (env.PLAYWRIGHT_SKIP_PREFLIGHT === '1') {
    return {
      skipped: true,
      output: '[e2e] skipping framework preflight because PLAYWRIGHT_SKIP_PREFLIGHT=1',
    };
  }

  const result = spawnSync(resolvePhpBinary(), [PREFLIGHT_SCRIPT], {
    cwd: ROOT_DIR,
    env,
    encoding: 'utf8',
    windowsHide: true,
  });

  if (result.error) {
    throw result.error;
  }

  const output = [result.stdout || '', result.stderr || '']
    .join('')
    .trim();

  if (result.status !== 0) {
    const error = new Error(`Framework preflight failed with exit code ${result.status || 1}.`);
    error.details = output;
    throw error;
  }

  return {
    skipped: false,
    output,
  };
}

module.exports = {
  runFrameworkPreflight,
};
