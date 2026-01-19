# 主题文件覆盖机制测试说明

## 测试文件

1. **ThemeFileOverrideTest.php** - 主题文件覆盖机制综合测试
2. **AssetMergerOverrideTest.php** - AssetMerger文件覆盖机制专项测试

## 测试覆盖范围

### 1. 模板文件覆盖机制

**测试方法**: `testTemplateFileOverrideMechanism()`

**验证内容**:
- 激活主题的同名文件优先于父主题
- 如果激活主题存在同名文件，直接返回，不再查找父主题
- 文件查找顺序：激活主题 → 父主题链 → 默认主题

**实现位置**: `TemplateFetchFile::resolveThemeFile()`

### 2. JS模块文件覆盖机制

**测试方法**: 
- `ThemeFileOverrideTest::testJsModuleFileOverrideMechanism()`
- `AssetMergerOverrideTest::testSameNameJsFileOverride()`

**验证内容**:
- 同名JS文件以激活主题为准
- 如果激活主题存在同名JS文件，只收集激活主题的版本
- 父主题的同名JS文件不会被收集（如果激活主题有同名文件）

**实现位置**: `AssetMerger::mergeAssets()`

### 3. CSS文件覆盖机制

**测试方法**: 
- `ThemeFileOverrideTest::testCssFileOverrideMechanism()`
- `AssetMergerOverrideTest::testSameNameCssFileOverride()`

**验证内容**:
- 同名CSS文件以激活主题为准
- 确保没有重复的CSS文件名

### 4. 同名文件覆盖规则

**测试方法**: 
- `ThemeFileOverrideTest::testSameNameFileOverrideRule()`
- `AssetMergerOverrideTest::testDeduplicationLogic()`

**验证内容**:
- 如果激活主题和父主题都有同名文件，只使用激活主题的版本
- 如果只有激活主题有文件，使用激活主题的版本
- 如果只有父主题有文件（激活主题没有），使用父主题的版本

### 5. 主题继承链验证

**测试方法**: 
- `ThemeFileOverrideTest::testThemeChainFileLookupOrder()`
- `AssetMergerOverrideTest::testThemeChainAssetCollection()`

**验证内容**:
- 主题继承链的顺序正确（最后一个应该是激活主题）
- 收集的文件都来自主题继承链或基础模块

### 6. 去重机制验证

**测试方法**: 
- `ThemeFileOverrideTest::testAssetMergerDeduplication()`
- `AssetMergerOverrideTest::testDeduplicationLogic()`

**验证内容**:
- 确保没有重复的文件名
- 同名文件只保留激活主题的版本

## 核心规则验证

### 规则1：同名文件以激活主题为准

**测试场景**:
- 激活主题有 `search.js`
- 父主题也有 `search.js`
- **预期结果**: 只收集激活主题的 `search.js`

### 规则2：文件查找顺序

**测试场景**:
- 查找文件时，先查找激活主题
- 如果激活主题存在，直接使用
- 如果激活主题不存在，再查找父主题链

### 规则3：JS模块收集机制

**测试场景**:
- 从激活主题开始收集（反向遍历主题链）
- 使用文件名作为键，确保同名文件只保留激活主题的版本

## 运行测试

### 运行所有覆盖机制测试

```bash
php bin/w p:r Weline_Theme --filter=ThemeFileOverrideTest
php bin/w p:r Weline_Theme --filter=AssetMergerOverrideTest
```

### 运行特定测试方法

```bash
# 使用PHPUnit直接运行
php vendor/bin/phpunit app/code/Weline/Theme/test/Unit/ThemeFileOverrideTest.php --filter testJsModuleFileOverrideMechanism
php vendor/bin/phpunit app/code/Weline/Theme/test/Unit/AssetMergerOverrideTest.php --filter testSameNameJsFileOverride
```

## 测试前置条件

1. **激活的主题**: 测试需要数据库中有激活的主题
   - 如果没有激活主题，测试会被跳过（使用 `markTestSkipped()`）

2. **主题文件**: 测试会检查实际的主题文件
   - 如果测试文件不存在，相关测试会被跳过

3. **主题继承关系**: 某些测试需要主题有父主题
   - 如果激活主题没有父主题，相关测试会被跳过

## 测试结果说明

- **✅ 通过**: 覆盖机制工作正常
- **⏭️ 跳过**: 缺少测试前置条件（如没有激活主题）
- **❌ 失败**: 覆盖机制存在问题，需要修复

## 注意事项

1. 测试在CLI环境下运行，某些功能可能受限
2. 测试需要数据库中有正确的主题配置
3. 测试会检查实际的文件系统，确保文件存在
4. 测试使用反射调用私有方法，这是正常的测试实践

## 相关实现文件

- `app/code/Weline/Theme/Observer/TemplateFetchFile.php` - 模板文件解析
- `app/code/Weline/Theme/Helper/AssetMerger.php` - 资源文件合并
- `app/code/Weline/Theme/Model/WelineTheme.php` - 主题模型
