/**
 * Parse PHP e2e fixture stdout that may contain ANSI / CLI noise under FORCE_COLOR.
 * @param {string} stdout
 * @param {string} [label]
 * @returns {object}
 */
function parsePhpFixtureStdout(stdout, label = 'fixture') {
  const cleaned = String(stdout || '')
    .replace(/\u001b\[[0-9;?]*[ -/]*[@-~]/g, '')
    .replace(/\r/g, '');
  const lines = cleaned.split('\n').map((l) => l.trim()).filter(Boolean);
  for (let i = lines.length - 1; i >= 0; i -= 1) {
    const line = lines[i];
    if (!(line.startsWith('{') || line.startsWith('['))) continue;
    try {
      return JSON.parse(line);
    } catch (_) {
      // try earlier line
    }
  }
  const slice = cleaned.trim().slice(-800);
  throw new Error(`${label} stdout not JSON: ${slice}`);
}

/** Env overrides so PHP CLI does not paint ANSI onto fixture JSON. */
function phpFixtureEnv(baseEnv = process.env) {
  return {
    ...baseEnv,
    NO_COLOR: '1',
    FORCE_COLOR: '0',
    TERM: 'dumb',
    HTTPS_PROXY: '',
    https_proxy: '',
    HTTP_PROXY: '',
    http_proxy: '',
    ALL_PROXY: '',
    all_proxy: '',
  };
}

module.exports = {
  parsePhpFixtureStdout,
  phpFixtureEnv,
};
