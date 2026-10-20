/**
 * 夹具进程超时护栏（架构级）。
 *
 * 大量 spec 用 `execFileSync('php', [fixture], { input: JSON.stringify(...) })`
 * 同步调用 PHP 夹具。夹具一旦卡在数据库锁/迁移/外部依赖上，Playwright 的
 * test timeout 根本来不及触发——整个 worker 被同步调用冻住，门禁会挂 40 分钟
 * 都不返回（本仓库真实发生过）。
 *
 * 这里在测试进程内给「带 stdin 的 execFileSync」补一个默认超时：
 * - 只作用于 options.input 存在的调用（本仓库夹具约定），不碰其它子进程；
 * - 显式传了 timeout 的调用保持不变；
 * - 可用 WELINE_E2E_FIXTURE_TIMEOUT_MS 覆盖，默认 180s。
 *
 * 超时后 execFileSync 抛 ETIMEDOUT，用例以可读错误失败，而不是拖死整轮门禁。
 */
const childProcess = require('child_process');

const DEFAULT_FIXTURE_TIMEOUT_MS = 180000;

function resolveFixtureTimeout() {
  const raw = Number(process.env.WELINE_E2E_FIXTURE_TIMEOUT_MS);
  return Number.isFinite(raw) && raw > 0 ? raw : DEFAULT_FIXTURE_TIMEOUT_MS;
}

function installFixtureTimeoutGuard() {
  if (childProcess.__welineE2eFixtureTimeoutGuard) {
    return;
  }
  childProcess.__welineE2eFixtureTimeoutGuard = true;

  const originalExecFileSync = childProcess.execFileSync;
  childProcess.execFileSync = function execFileSyncWithFixtureTimeout(file, args, options) {
    let nextOptions = options;
    if (
      nextOptions
      && typeof nextOptions === 'object'
      && nextOptions.input !== undefined
      && nextOptions.timeout === undefined
      && nextOptions.timeoutMS === undefined
    ) {
      nextOptions = { ...nextOptions, timeout: resolveFixtureTimeout() };
    }

    try {
      return originalExecFileSync.call(this, file, args, nextOptions);
    } catch (error) {
      if (error && (error.code === 'ETIMEDOUT' || error.signal === 'SIGTERM')) {
        const fixture = Array.isArray(args) ? String(args[0] || '') : String(file || '');
        error.message = `[e2e] PHP fixture timed out after ${resolveFixtureTimeout()}ms: ${fixture}. `
          + '夹具被同步调用冻住会让整轮门禁挂死；先查数据库锁/迁移/外部依赖，再重跑。';
      }
      throw error;
    }
  };
}

installFixtureTimeoutGuard();

module.exports = {
  DEFAULT_FIXTURE_TIMEOUT_MS,
  resolveFixtureTimeout,
};
