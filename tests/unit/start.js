/**
 * Vitest 启动脚本
 * 一条命令启动 watch 模式，随时更新随时测试
 */

import { execSync } from 'child_process';
import { fileURLToPath } from 'url';
import { dirname, resolve } from 'path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

console.log('🚀 启动 Vitest Watch 模式...\n');
console.log('📝 文件变更将自动重新运行测试\n');
console.log('💡 提示：按 q 退出，按 u 打开 UI 界面\n');

try {
  // 获取命令行参数
  const args = process.argv.slice(2);
  const command = args.length > 0 
    ? `npx vitest --watch ${args.join(' ')}`
    : 'npx vitest --watch';
  
  execSync(command, { 
    stdio: 'inherit',
    cwd: __dirname,
    shell: true
  });
} catch (error) {
  // 用户按 q 退出时，退出码为 0，这是正常的
  if (error.status !== 0) {
    console.error('❌ 测试运行失败:', error.message);
    process.exit(error.status || 1);
  }
}
