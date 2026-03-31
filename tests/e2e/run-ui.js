/**
 * Playwright UI：全量用例 + 清理残留。
 * - 去掉会缩小列表的环境变量（单文件 / 单模块调试残留）
 * - 启动前尝试结束上次未关的 Playwright UI（node --ui）
 */
const fs = require('fs');
const path = require('path');
const { spawnSync, execSync } = require('child_process');

function killStalePlaywrightUi() {
  if (process.platform === 'win32') {
    const ps1 = path.join(__dirname, 'scripts', 'kill-playwright-ui.ps1');
    if (fs.existsSync(ps1)) {
      try {
        execSync(
          `powershell.exe -NoProfile -ExecutionPolicy Bypass -File "${ps1}"`,
          { stdio: 'pipe' },
        );
        console.log('[test:ui] 已尝试结束残留的 Playwright UI（node）进程');
      } catch {
        /* 无匹配进程时 Stop-Process 可能非零，忽略 */
      }
    }
    return;
  }
  try {
    execSync('pkill -f "[p]laywright.*--ui" || true', { stdio: 'pipe', shell: true });
    console.log('[test:ui] 已尝试结束残留的 Playwright UI 进程（pkill）');
  } catch {
    /* ignore */
  }
}

delete process.env.PLAYWRIGHT_TEST_FILES;
delete process.env.MODULE_FILTER;

killStalePlaywrightUi();

const cwd = __dirname;
const result = spawnSync('npx', ['playwright', 'test', '--ui', '--headed'], {
  cwd,
  env: process.env,
  stdio: 'inherit',
  shell: true,
});

process.exit(result.status ?? 1);
