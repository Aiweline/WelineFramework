# Weline_Sticker 模块测试文档

## 概述

本文档描述了为 Weline_Sticker 模块及其相关组件编写的单元测试，包括新添加的 extends.php 功能、ExtendsScanner 识别功能、ExtendsRegistry 新增方法和 Weline_Extends 控制器的测试。

## 测试目录结构

```
app/code/Weline/Sticker/Test/
├── README_TESTS.md              # 本文档
├── run_tests.php               # 测试运行脚本
└── Unit/
    └── StickerExtendsTest.php  # Sticker 扩展配置测试

app/code/Weline/Framework/Extends/Test/Unit/
├── ExtendsScannerStickerTest.php     # ExtendsScanner Sticker 识别测试
└── ExtendsRegistryNewMethodsTest.php # ExtendsRegistry 新增方法测试

app/code/Weline/Extends/Test/Unit/
└── ExtendsControllerTest.php   # Weline_Extends 控制器测试
```

## 测试文件说明

### 1. StickerExtendsTest.php

**测试文件**: `app/code/Weline/Sticker/Test/Unit/StickerExtendsTest.php`

**测试范围**:
- `extends.php` 文件存在性和内容结构
- 配置文件结构完整性验证
- Sticker 扩展点配置验证
- 详细配置信息验证
- `extends.md` 文档存在性和内容结构
- 配置文件语法正确性
- 扩展点类型支持验证
- 路径格式正确性

**关键测试方法**:
- `testExtendsPhpFileExists()` - 验证 extends.php 文件存在
- `testConfigStructureIntegrity()` - 验证配置文件结构
- `testStickerExtensionPointConfig()` - 验证 Sticker 扩展点配置
- `testDetailedConfiguration()` - 验证详细配置信息
- `testExtendsMdDocumentationExists()` - 验证文档文件存在
- `testExtensionTypesSupport()` - 验证扩展类型支持

### 2. ExtendsScannerStickerTest.php

**测试文件**: `app/code/Weline/Framework/Extends/Test/Unit/ExtendsScannerStickerTest.php`

**测试范围**:
- ExtendsScanner 可以识别 Sticker 扩展
- Sticker 扩展路径解析
- 模块级 Sticker 扩展识别
- 主题级 Sticker 扩展识别
- 错误路径处理
- 文件类型识别
- 扩展复杂度计算
- 影响范围评估

**关键测试方法**:
- `testScannerCanIdentifyStickerExtensions()` - 验证扫描器识别能力
- `testStickerExtensionPathParsing()` - 验证路径解析逻辑
- `testModuleLevelStickerExtensionIdentification()` - 验证模块级扩展识别
- `testThemeLevelStickerExtensionIdentification()` - 验证主题级扩展识别
- `testFileTypeIdentification()` - 验证文件类型识别
- `testExtensionComplexityCalculation()` - 验证复杂度计算
- `testImpactScopeAssessment()` - 验证影响范围评估

### 3. ExtendsRegistryNewMethodsTest.php

**测试文件**: `app/code/Weline/Framework/Extends/Test/Unit/ExtendsRegistryNewMethodsTest.php`

**测试范围**:
- 获取模块扩展信息方法
- 获取扩展类型方法
- 检查扩展类型方法
- 获取所有 Sticker 扩展信息
- 获取模块 Sticker 扩展信息
- 检查模块是否被 Sticker 扩展
- 获取扩展统计信息
- 文件类型识别
- 扩展复杂度计算
- 影响范围评估

**关键测试方法**:
- `testGetModuleExtendedBy()` - 验证模块扩展信息获取
- `testGetExtendType()` - 验证扩展类型获取
- `testHasExtendType()` - 验证扩展类型检查
- `testGetAllStickerExtensions()` - 验证 Sticker 扩展获取
- `testGetModuleStickerExtensions()` - 验证模块 Sticker 扩展获取
- `testIsStickerExtended()` - 验证 Sticker 扩展检查
- `testGetExtensionStats()` - 验证扩展统计信息

### 4. ExtendsControllerTest.php

**测试文件**: `app/code/Weline/Extends/Test/Unit/ExtendsControllerTest.php`

**测试范围**:
- 控制器实例化
- 扩展列表页面
- 模块详情页面
- 循环依赖检测
- 刷新注册表
- Sticker 统计页面
- 扩展搜索功能
- 错误处理
- 数据验证
- 统计计算逻辑
- 扩展数据过滤
- 搜索结果排序

**关键测试方法**:
- `testControllerInstantiation()` - 验证控制器实例化
- `testIndexPage()` - 验证扩展列表页面
- `testModuleDetailPage()` - 验证模块详情页面
- `testStickerStatsPage()` - 验证 Sticker 统计页面
- `testExtensionSearch()` - 验证扩展搜索功能
- `testErrorHandling()` - 验证错误处理
- `testStatisticsCalculation()` - 验证统计计算逻辑

## 测试运行

### 1. 运行单个测试文件

```bash
# 运行 Sticker 扩展配置测试
php vendor/bin/phpunit app/code/Weline/Sticker/Test/Unit/StickerExtendsTest.php

# 运行 ExtendsScanner 测试
php vendor/bin/phpunit app/code/Weline/Framework/Extends/Test/Unit/ExtendsScannerStickerTest.php

# 运行 ExtendsRegistry 测试
php vendor/bin/phpunit app/code/Weline/Framework/Extends/Test/Unit/ExtendsRegistryNewMethodsTest.php

# 运行控制器测试
php vendor/bin/phpunit app/code/Weline/Extends/Test/Unit/ExtendsControllerTest.php
```

### 2. 运行所有测试

```bash
# 使用测试运行脚本
php app/code/Weline/Sticker/Test/run_tests.php

# 或者使用 PHPUnit 直接运行
php vendor/bin/phpunit app/code/Weline/Sticker/Test/
php vendor/bin/phpunit app/code/Weline/Framework/Extends/Test/
php vendor/bin/phpunit app/code/Weline/Extends/Test/
```

### 3. 运行特定测试类

```bash
# 运行特定的测试类
php vendor/bin/phpunit --filter StickerExtendsTest
php vendor/bin/phpunit --filter ExtendsScannerStickerTest
php vendor/bin/phpunit --filter ExtendsRegistryNewMethodsTest
php vendor/bin/phpunit --filter ExtendsControllerTest
```

### 4. 生成覆盖率报告

```bash
# 生成代码覆盖率报告
php vendor/bin/phpunit --coverage-html coverage/ app/code/Weline/Sticker/Test/
php vendor/bin/phpunit --coverage-html coverage/ app/code/Weline/Framework/Extends/Test/
php vendor/bin/phpunit --coverage-html coverage/ app/code/Weline/Extends/Test/
```

## 测试覆盖的功能

### 1. Sticker 模块扩展配置
- ✅ `extends.php` 文件格式和内容验证
- ✅ 扩展点配置结构验证
- ✅ 路径格式验证
- ✅ 文档文件存在性验证

### 2. ExtendsScanner 增强功能
- ✅ Sticker 扩展识别逻辑
- ✅ 模块级和主题级扩展区分
- ✅ 路径解析算法
- ✅ 错误处理机制

### 3. ExtendsRegistry 新增方法
- ✅ 快速查询方法
- ✅ 统计计算功能
- ✅ Sticker 扩展专门处理
- ✅ 元数据增强

### 4. Weline_Extends 控制器增强
- ✅ 新的页面和方法
- ✅ 错误处理机制
- ✅ 数据验证逻辑
- ✅ 搜索和过滤功能

## 测试数据

测试使用了以下模拟数据：

```php
// 模拟扩展数据
$mockExtendsData = [
    'Weline_Sticker' => [
        'extends' => ['Sticker' => ['type' => 'module']],
        'extended_by' => [
            'Weline_ModuleA' => [
                [
                    'is_sticker_extension' => true,
                    'file_path' => 'some/file.php'
                ]
            ]
        ]
    ]
];

// 模拟路径数据
$testPaths = [
    'extends/module/Weline_Sticker/Weline_Demo/view/templates/Backend/index.phtml',
    'extends/theme/default/Weline_Sticker/Weline_Admin/view/templates/index.phtml'
];
```

## 预期测试结果

所有测试应该通过，输出类似：

```
PHPUnit 9.x by Sebastian Bergmann and contributors.

.....                                                           5 / 5 (100%)

Time: 0.XX seconds, Memory: 6.00 MB

OK (5 tests, 15 assertions)
```

## 故障排除

### 1. 测试失败

如果测试失败，请检查：
- PHP 版本兼容性（需要 PHP 8.2+）
- PHPUnit 版本兼容性
- 依赖类是否正确加载
- 模拟对象设置是否正确

### 2. 类不存在错误

如果遇到类不存在错误，请确保：
- 自动加载器正确配置
- 测试文件路径正确
- 命名空间正确

### 3. 权限问题

如果遇到权限问题，请确保：
- 测试文件有读取权限
- 临时目录有写入权限
- 覆盖率报告目录有写入权限

## 持续集成

建议在 CI/CD 流程中运行这些测试：

```yaml
# .github/workflows/tests.yml
name: Tests
on: [push, pull_request]
jobs:
  tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - name: Install dependencies
        run: composer install
      - name: Run tests
        run: php vendor/bin/phpunit app/code/Weline/Sticker/Test/
```

## 总结

这些单元测试确保了：
1. Sticker 模块的扩展配置功能正确实现
2. ExtendsScanner 能够正确识别和处理 Sticker 扩展
3. ExtendsRegistry 的新增功能按预期工作
4. Weline_Extends 控制器的新增方法功能完整
5. 错误处理和边界情况得到妥善处理
6. 性能和复杂度计算逻辑正确

通过这些测试，我们可以确保新功能的稳定性和可靠性，为开发者提供高质量的扩展管理功能。
