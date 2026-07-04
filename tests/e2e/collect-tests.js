/**
 * E2E 测试用例收集脚本
 * 
 * 功能：
 * 1. 读取 modules.json 获取所有模块信息
 * 2. 扫描每个模块的 test/e2e/ 或 Test/e2e/ 目录
 * 3. 收集所有 *.spec.js 测试文件
 * 4. 生成测试文件列表供 Playwright 使用
 */

const fs = require('fs');
const path = require('path');

// 项目根目录（相对于当前脚本位置）
const ROOT_DIR = path.resolve(__dirname, '../..');
const MODULES_JSON = path.join(__dirname, 'modules.json');
const OUTPUT_FILE = path.join(__dirname, 'collected-tests.json');
const TEST_DIR_CANDIDATES = [
    ['test', 'e2e'],
    ['Test', 'e2e'],
    ['test', 'E2E'],
    ['Test', 'E2E'],
    ['tests', 'e2e'],
    ['Tests', 'e2e'],
];

function toRelativeProjectPath(fullPath, baseDir = ROOT_DIR) {
    return path.relative(baseDir, fullPath).replace(/\\/g, '/');
}

function pathKey(filePath) {
    try {
        return fs.realpathSync(filePath).toLowerCase();
    } catch (error) {
        return path.resolve(filePath).toLowerCase();
    }
}

function uniqueExistingDirs(dirs) {
    const unique = new Map();
    for (const dir of dirs) {
        if (!dir || !fs.existsSync(dir) || !fs.statSync(dir).isDirectory()) {
            continue;
        }
        const key = pathKey(dir);
        if (!unique.has(key)) {
            unique.set(key, dir);
        }
    }
    return Array.from(unique.values()).sort();
}

function uniqueProjectPaths(files) {
    const unique = new Map();
    for (const file of files) {
        const absolute = path.isAbsolute(file) ? file : path.join(ROOT_DIR, file.replace(/\//g, path.sep));
        const key = pathKey(absolute);
        if (!unique.has(key)) {
            unique.set(key, toRelativeProjectPath(absolute));
        }
    }
    return Array.from(unique.values()).sort();
}

function resolveModuleTestDirs(moduleInfo) {
    const candidateDirs = [];

    if (moduleInfo.test_path) {
        if (path.isAbsolute(moduleInfo.test_path)) {
            candidateDirs.push(moduleInfo.test_path);
        } else {
            candidateDirs.push(path.join(ROOT_DIR, moduleInfo.test_path.replace(/\//g, path.sep)));
        }
    }

    if (!moduleInfo.base_path) {
        return uniqueExistingDirs(candidateDirs);
    }

    const moduleBasePath = path.isAbsolute(moduleInfo.base_path)
        ? moduleInfo.base_path
        : path.join(ROOT_DIR, moduleInfo.base_path.replace(/\//g, path.sep));

    for (const parts of TEST_DIR_CANDIDATES) {
        candidateDirs.push(path.join(moduleBasePath, ...parts));
    }

    return uniqueExistingDirs(candidateDirs);
}

/**
 * 递归扫描目录，收集所有 .spec.js 文件
 * @param {string} dir - 要扫描的目录
 * @param {string} baseDir - 基础目录（用于生成相对路径）
 * @returns {string[]} 测试文件路径数组
 */
function collectTestFiles(dir, baseDir = ROOT_DIR) {
    const testFiles = [];
    
    let directoryStats;
    try {
        directoryStats = fs.statSync(dir);
    } catch (error) {
        return testFiles;
    }

    if (!directoryStats.isDirectory()) {
        return testFiles;
    }
    
    let entries = [];
    try {
        entries = fs.readdirSync(dir, { withFileTypes: true });
    } catch (error) {
        console.warn(`[collect-tests] skip unreadable directory: ${dir} (${error.message})`);
        return testFiles;
    }
    
    for (const entry of entries) {
        const fullPath = path.join(dir, entry.name);
        
        if (entry.isDirectory()) {
            // 递归扫描子目录
            testFiles.push(...collectTestFiles(fullPath, baseDir));
        } else if (entry.isFile() && entry.name.endsWith('.spec.js')) {
            // 生成相对路径（从项目根目录开始）
            const relativePath = toRelativeProjectPath(fullPath, baseDir);
            testFiles.push(relativePath);
        }
    }
    
    return uniqueProjectPaths(testFiles);
}

/**
 * 主函数：收集所有模块的测试用例
 */
function collectAllTests() {
    console.log('🔍 开始收集 E2E 测试用例...\n');
    
    // 检查 modules.json 是否存在
    if (!fs.existsSync(MODULES_JSON)) {
        console.error('❌ 错误: modules.json 不存在！');
        console.error('   请先运行: php bin/w setup:upgrade');
        process.exit(1);
    }
    
    // 读取 modules.json
    let modulesData;
    try {
        const jsonContent = fs.readFileSync(MODULES_JSON, 'utf-8');
        modulesData = JSON.parse(jsonContent);
    } catch (error) {
        console.error('❌ 错误: 无法读取或解析 modules.json');
        console.error('   错误信息:', error.message);
        process.exit(1);
    }
    
    const allTestFiles = [];
    const moduleTestMap = {};

    // 遍历所有模块
    for (const [moduleName, moduleInfo] of Object.entries(modulesData.modules || {})) {
        const testDirs = resolveModuleTestDirs(moduleInfo);
        if (testDirs.length === 0) {
            continue;
        }

        const testFiles = uniqueProjectPaths(testDirs.flatMap(testDir => collectTestFiles(testDir)));
        
        if (testFiles.length > 0) {
            moduleTestMap[moduleName] = {
                module: moduleName,
                base_path: moduleInfo.base_path,
                test_path: moduleInfo.test_path || toRelativeProjectPath(testDirs[0]),
                test_paths: testDirs.map(testDir => toRelativeProjectPath(testDir)),
                test_files: testFiles,
                count: testFiles.length,
                autodiscovered: !moduleInfo.test_path
            };
            allTestFiles.push(...testFiles);
            
            console.log(`✓ ${moduleName}: 发现 ${testFiles.length} 个测试文件`);
            testFiles.forEach(file => {
                console.log(`  - ${file}`);
            });
        }
    }
    
    // 生成收集结果
    const result = {
        generated_at: new Date().toISOString(),
        total_modules: Object.keys(moduleTestMap).length,
        total_tests: uniqueProjectPaths(allTestFiles).length,
        modules: moduleTestMap,
        all_test_files: uniqueProjectPaths(allTestFiles)
    };
    
    // 保存到文件
    fs.writeFileSync(OUTPUT_FILE, JSON.stringify(result, null, 2), 'utf-8');
    
    console.log(`\n✅ 测试用例收集完成！`);
    console.log(`   总模块数: ${result.total_modules}`);
    console.log(`   总测试文件数: ${result.total_tests}`);
    console.log(`   结果已保存到: ${OUTPUT_FILE}\n`);
    
    return result;
}

// 如果直接运行此脚本
if (require.main === module) {
    collectAllTests();
}

// 导出函数供其他脚本使用
module.exports = { collectAllTests, collectTestFiles };
