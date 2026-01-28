/**
 * AutoLeadAgent 扩展部署脚本
 * 构建完成后自动复制到 browser-extension 目录并更新 ZIP 包
 */
import { cpSync, rmSync, existsSync, mkdirSync } from 'fs';
import { execSync } from 'child_process';
import { dirname, resolve } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const rootDir = resolve(__dirname, '..');
const distDir = resolve(rootDir, 'dist');
const browserExtDir = resolve(rootDir, '..', 'browser-extension');
const downloadsDir = resolve(rootDir, '..', 'view', 'statics', 'downloads');
const inferenceWorker = resolve(rootDir, 'chrome-extension', 'public', 'inference-worker.js');

console.log('📦 开始部署 AutoLeadAgent 扩展...');

// 1. 确保目标目录存在
if (!existsSync(browserExtDir)) {
  mkdirSync(browserExtDir, { recursive: true });
}
if (!existsSync(downloadsDir)) {
  mkdirSync(downloadsDir, { recursive: true });
}

// 2. 清理旧文件
console.log('🧹 清理旧文件...');
try {
  rmSync(browserExtDir, { recursive: true, force: true });
  mkdirSync(browserExtDir, { recursive: true });
} catch (e) {
  console.warn('清理警告:', e.message);
}

// 3. 复制构建产物
console.log('📁 复制构建产物到 browser-extension/...');
cpSync(distDir, browserExtDir, { recursive: true });

// 4. 复制 inference-worker.js (本地推理 WebWorker)
if (existsSync(inferenceWorker)) {
  console.log('🤖 复制本地推理 Worker...');
  cpSync(inferenceWorker, resolve(browserExtDir, 'inference-worker.js'));
}

// 5. 创建 ZIP 包
console.log('📦 创建 ZIP 分发包...');
const zipPath = resolve(downloadsDir, 'AutoLeadAgent-Extension.zip');

// 删除旧 ZIP
if (existsSync(zipPath)) {
  rmSync(zipPath, { force: true });
}

// 使用系统命令创建 ZIP (跨平台)
try {
  if (process.platform === 'win32') {
    // Windows PowerShell
    execSync(`powershell -Command "Compress-Archive -Path '${browserExtDir}\\*' -DestinationPath '${zipPath}' -Force"`, { stdio: 'inherit' });
  } else {
    // Unix/Linux/Mac
    execSync(`cd "${browserExtDir}" && zip -r "${zipPath}" .`, { stdio: 'inherit' });
  }
  console.log('✅ ZIP 包创建成功:', zipPath);
} catch (e) {
  console.error('❌ ZIP 创建失败:', e.message);
}

console.log('');
console.log('✅ 部署完成!');
console.log('   扩展目录: browser-extension/');
console.log('   ZIP 下载: view/statics/downloads/AutoLeadAgent-Extension.zip');
