/**
 * E2E 测试启动脚本
 * 一条命令完成：检查 modules.json -> 收集测试用例 -> 运行测试
 */

const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const MODULES_JSON = path.join(__dirname, 'modules.json');

console.log('🚀 启动 E2E 测试流程...\n');

// 1. 检查 modules.json 是否存在
if (!fs.existsSync(MODULES_JSON)) {
    console.error('❌ 错误: modules.json 不存在！');
    console.error('   请先运行: php bin/w setup:upgrade');
    process.exit(1);
}

console.log('✓ modules.json 存在\n');

// 2. 收集测试用例
console.log('📋 收集测试用例...\n');
try {
    const { collectAllTests } = require('./collect-tests');
    const result = collectAllTests();
    
    if (result.total_tests === 0) {
        console.warn('⚠️  未发现任何测试用例');
        console.warn('   请确保模块目录下有 test/e2e/*.spec.js 文件\n');
    }
} catch (error) {
    console.error('❌ 测试用例收集失败:', error.message);
    process.exit(1);
}

// 3. 运行 Playwright 测试
console.log('▶️  运行 Playwright 测试...\n');
try {
    // 获取命令行参数（传递给 Playwright）
    const args = process.argv.slice(2);
    const command = args.length > 0 
        ? `npx playwright test ${args.join(' ')}`
        : 'npx playwright test';
    
    execSync(command, { 
        stdio: 'inherit',
        cwd: __dirname,
        shell: true  // Windows 需要 shell
    });
} catch (error) {
    // Playwright 测试失败时，退出码不为 0，这是正常的
    process.exit(error.status || 1);
}
