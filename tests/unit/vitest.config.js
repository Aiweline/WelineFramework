/**
 * Vitest 配置 - 前端单元测试
 * 支持 watch 模式，随时更新随时测试
 */

import { defineConfig } from 'vitest/config';
import { resolve } from 'path';
import { fileURLToPath } from 'url';

const __dirname = fileURLToPath(new URL('.', import.meta.url));

export default defineConfig({
  test: {
    // 使用 happy-dom 作为 DOM 环境（比 jsdom 更快）
    environment: 'happy-dom',
    
    // Watch 模式：只在明确使用 --watch 时启用
    // 使用 npm start 或 npm run test:watch 时启用 watch 模式
    // 使用 npm test 或 npm run test:run 时运行一次后退出
    watch: false,  // 默认不启用，避免进程挂起
    
    // 测试文件匹配模式
    include: [
      '**/*.{test,spec}.{js,mjs,cjs,ts,jsx,tsx}',
      '**/test/**/*.{js,mjs,cjs,ts,jsx,tsx}'
    ],
    
    // 排除的文件/目录
    exclude: [
      '**/node_modules/**',
      '**/dist/**',
      '**/build/**',
      '**/vendor/**',
      '**/var/**',
      '**/generated/**',
      '**/tests/e2e/**'
    ],
    
    // 全局测试文件（在每个测试文件运行前执行）
    globals: true,
    
    // 覆盖率配置
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json', 'html'],
      exclude: [
        '**/node_modules/**',
        '**/tests/**',
        '**/*.config.js',
        '**/*.config.ts'
      ]
    },
    
    // 测试超时时间（毫秒）
    testTimeout: 10000,
    
    // 钩子超时时间
    hookTimeout: 10000,
    
    // 监听模式配置
    watchExclude: [
      '**/node_modules/**',
      '**/dist/**',
      '**/build/**',
      '**/var/**',
      '**/generated/**'
    ]
  },
  
  // 路径别名配置（支持模块路径解析）
  resolve: {
    alias: {
      // 项目根目录（相对于 vitest.config.js）
      '@': resolve(__dirname, '../..'),
      
      // 模块路径别名
      '@Weline/Theme': resolve(__dirname, '../../app/code/Weline/Theme'),
      '@Weline/Frontend': resolve(__dirname, '../../app/code/Weline/Frontend'),
      '@WeShop/Search': resolve(__dirname, '../../app/code/WeShop/Search'),
      
      // 主题路径别名
      '@theme': resolve(__dirname, '../../app/code/Weline/Theme/view/theme/frontend'),
      '@design': resolve(__dirname, '../../app/design')
    }
  }
});
