// tests/e2e/playwright.config.js
// 基于项目内《Playwright E2E测试指南》的推荐配置，用于前台端到端测试
// 支持从模块目录动态收集测试用例

// 使用 CommonJS 写法，避免额外开启 ESM 支持
// eslint-disable-next-line @typescript-eslint/no-var-requires, import/no-extraneous-dependencies
const { defineConfig, devices } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { collectAllTests } = require('./collect-tests');

// 动态收集测试用例
let testMatch = [];
let testDir = './specs'; // 默认目录（向后兼容）

// 支持命令行参数过滤模块
const moduleFilter = process.env.MODULE_FILTER || process.argv.find(arg => arg.startsWith('--module='))?.split('=')[1];

try {
  // 先尝试收集测试用例
  const result = collectAllTests();
  
  if (result.all_test_files && result.all_test_files.length > 0) {
    let filesToUse = result.all_test_files;
    
    // 如果指定了模块过滤，只使用该模块的测试
    if (moduleFilter) {
      const moduleTests = result.modules[moduleFilter];
      if (moduleTests && moduleTests.test_files) {
        filesToUse = moduleTests.test_files;
        console.log(`📋 已过滤模块 ${moduleFilter}，共 ${moduleTests.test_files.length} 个测试文件`);
      } else {
        console.warn(`⚠️  模块 ${moduleFilter} 没有测试用例`);
        filesToUse = [];
      }
    }
    
    if (filesToUse.length > 0) {
      // 使用收集到的测试文件
      // 由于 Playwright 的 testMatch 在 Windows 上可能有问题，改用 testDir + 默认匹配模式
      // 找到所有测试文件的公共父目录
      const testDirs = new Set();
      filesToUse.forEach(file => {
        const dir = path.dirname(file);
        testDirs.add(dir);
      });
      
      // 如果所有测试都在同一个目录下，使用该目录作为 testDir
      if (testDirs.size === 1) {
        const dir = Array.from(testDirs)[0];
        // 使用 path.posix 确保路径格式正确（使用正斜杠）
        testDir = path.posix.join(...dir.split(path.sep));
        console.log(`📋 已收集 ${filesToUse.length} 个测试文件`);
        console.log(`📋 使用 testDir: ${testDir}`);
        console.log(`📋 实际测试文件:`, filesToUse);
      } else {
        // 多个目录，使用 glob 模式
        testMatch = ['**/test/e2e/**/*.spec.js'];
        console.log(`📋 已收集 ${filesToUse.length} 个测试文件（多个目录）`);
        console.log(`📋 使用 glob 模式: ${testMatch[0]}`);
      }
    }
  } else {
    console.log('⚠️  未发现测试用例，使用默认目录: ./specs');
  }
} catch (error) {
  console.warn('⚠️  测试用例收集失败，使用默认目录:', error.message);
  console.warn('   请确保已运行: php bin/w setup:upgrade');
}

// 设置根目录为项目根目录（相对于配置文件向上两级）
const rootDir = path.resolve(__dirname, '../..');

// 调试：输出配置信息
console.log('🔧 Playwright 配置调试:');
console.log('   Root Dir:', rootDir);
console.log('   Config Dir:', __dirname);
console.log('   Test Match:', testMatch);
console.log('   Test Dir:', testDir);

module.exports = defineConfig({
  // 设置根目录
  rootDir,
  // 设置工作目录为配置文件所在目录（这样 Playwright 可以找到 node_modules）
  // 这确保 @playwright/test 可以从 tests/e2e/node_modules 正确加载
  globalSetup: undefined,
  globalTeardown: undefined,
  // 如果收集到了测试文件，使用 testMatch 或 testDir；否则使用默认 testDir
  // 注意：testMatch 和 testDir 都需要相对于 rootDir 的路径
  // 在 Windows 上，Playwright 可能对路径格式敏感，确保使用正斜杠
  ...(testMatch.length > 0 ? { 
    testMatch: testMatch.map(p => p.replace(/\\/g, '/'))
  } : { 
    testDir: testDir.replace(/\\/g, '/')
  }),
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: 'html',
  use: {
    baseURL: 'http://127.0.0.1:81',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure'
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] }
    }
    // 暂时只使用 Chromium 进行测试，其他浏览器需要安装
    // {
    //   name: 'firefox',
    //   use: { ...devices['Desktop Firefox'] }
    // },
    // {
    //   name: 'webkit',
    //   use: { ...devices['Desktop Safari'] }
    // }
  ],

  // 通过 php bin/w server:start 启动前台服务
  // 注意：如果服务器已经手动启动，可以注释掉 webServer 配置
  // 或者使用 reuseExistingServer: true 来复用已存在的服务器
  // webServer: {
  //   command: 'php bin/w server:start',
  //   cwd: path.resolve(__dirname, '../..'),
  //   url: 'http://127.0.0.1:81',
  //   reuseExistingServer: true,
  //   timeout: 120 * 1000
  // }
});

