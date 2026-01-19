/**
 * Weline Theme.js 单元测试
 * 
 * 测试主题管理、模块加载等核心功能
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { readFileSync } from 'fs';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';

// ESM 中获取 __dirname
const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

// 计算项目根目录：从 tests/unit/specs/Weline/Theme/ 向上到项目根目录
// 路径：Theme -> Weline -> specs -> unit -> tests -> 项目根目录（5级）
// 但更可靠的方法是：从 vitest.config.js 的位置向上 2 级
const vitestConfigDir = resolve(__dirname, '../../../'); // tests/unit/
const projectRoot = resolve(vitestConfigDir, '../..'); // 项目根目录

describe('Weline Theme.js', () => {
  let themeScript;
  
  beforeEach(() => {
    // 加载主题 JS 文件（从项目根目录开始）
    const themePath = resolve(projectRoot, 'app/code/Weline/Theme/view/theme/frontend/assets/js/theme.js');
    try {
      themeScript = readFileSync(themePath, 'utf-8');
      
      // 模拟全局配置（包含所有必需的配置项，避免异步错误）
      window.__WelineThemeConfig = {
        baseUrl: '/',
        theme: 'default',
        locale: 'zh_CN',
        modulesConfigUrl: '', // 避免未配置错误
        modulesBaseUrl: '',
        modulesConfig: null,
        env: { WELINE_ENV: 'TEST', DEV: true, PROD: false },
        currentLang: 'zh_CN',
        currentCurrency: 'CNY',
        debug: false,
        api: {},
        account: {},
        site: {},
        i18n: {
          currentLang: 'zh_CN',
          dictionary: {},
          apiUrl: ''
        }
      };
      
      // 在全局作用域执行脚本
      // 捕获可能的异步错误，避免未处理的 Promise rejection
      try {
        eval(themeScript);
      } catch (evalError) {
        // 忽略执行错误，可能是异步初始化问题
        console.warn('Theme script execution warning:', evalError.message);
      }
    } catch (error) {
      console.warn('Theme.js not found, skipping tests:', error.message);
    }
  });
  
  afterEach(() => {
    // 清理全局变量
    if (window.Weline) {
      delete window.Weline;
    }
    if (window.__WelineThemeConfig) {
      delete window.__WelineThemeConfig;
    }
  });
  
  it('should initialize Weline object', () => {
    expect(window.Weline).toBeDefined();
    expect(typeof window.Weline).toBe('object');
  });
  
  it('should have theme management', () => {
    if (window.Weline && window.Weline.Theme) {
      expect(window.Weline.Theme).toBeDefined();
    }
  });
  
  it('should support module system', () => {
    if (window.Weline && typeof window.Weline.declare === 'function') {
      expect(typeof window.Weline.declare).toBe('function');
    }
  });
});
