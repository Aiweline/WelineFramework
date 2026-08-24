# Pest PHP 测试框架集成总结

## ✅ 集成完成

已成功将 Pest PHP 测试框架集成到 WelineFramework 中，支持通过启动参数自动加载。

## 📦 已创建/修改的文件

### 1. 依赖配置
- ✅ `composer.json` - 添加了 `pestphp/pest ^1.0` 开发依赖（兼容 PHPUnit 9.6）

### 2. 核心类文件
- ✅ `app/code/Weline/Framework/UnitTest/Pest/Pest.php` - Pest 集成类
- ✅ `app/code/Weline/Framework/UnitTest/Pest/Boot.php` - Pest 启动类
- ✅ `app/code/Weline/Framework/UnitTest/Boot.php` - 更新了 Boot trait

### 3. 控制台命令
- ✅ `app/code/Weline/Framework/UnitTest/Console/Pest/Run.php` - Pest 测试运行命令

### 4. 配置文件
- ✅ `Pest.php` - Pest 配置文件（项目根目录）

### 5. 框架集成
- ✅ `app/code/Weline/Framework/App.php` - 添加了测试模式检测和自动加载逻辑

### 6. 示例和文档
- ✅ `tests/ExampleTest.php` - 示例测试文件
- ✅ `app/code/Weline/Framework/UnitTest/README.md` - 使用文档

## 🚀 使用方法

### 安装依赖

```bash
composer require --dev pestphp/pest
```

### 启动服务时启用测试模式

```bash
# 方式一：使用参数
php bin/w server:start --test

# 方式二：使用环境变量
export WELINE_ENABLE_TEST=1
php bin/w server:start
```

### 运行测试

```bash
# 使用框架命令
php bin/w test:pest:run

# 或直接使用 Pest
vendor/bin/pest
```

## 🔧 技术实现

### 1. 自动加载机制

在 `App.php` 的 `init()` 方法中：
- 检测命令行参数 `--test` 或 `-t`
- 检测环境变量 `WELINE_ENABLE_TEST`
- 检测 `ENV_TEST` 常量
- 如果满足条件，自动调用 `PestBoot::boot()` 初始化测试框架

### 2. 测试环境初始化

`PestBoot::boot()` 方法：
- 检查是否应该启用测试模式
- 调用 `Pest::init()` 初始化测试环境
- 加载 `bootstrap_phpunit.php` 文件

### 3. 命令执行

`Pest::run()` 方法：
- 检查 Pest 是否可用
- 构建 Pest 命令参数
- 执行 Pest 测试命令
- 返回退出代码

### 4. phpunit:run 命令集成

`phpunit:run` 命令现在默认优先使用 Pest：
- 如果 Pest 已安装，自动使用 Pest 运行测试
- 可通过 `--phpunit` 参数强制使用 PHPUnit
- 如果 Pest 运行失败，自动回退到 PHPUnit

## 📝 命令说明

### 启动服务命令

| 参数 | 说明 |
|------|------|
| `--test` 或 `-t` | 启用测试模式 |

### 运行测试命令

| 参数 | 说明 |
|------|------|
| `--filter` 或 `-f` | 过滤测试名称 |
| `--group` 或 `-g` | 运行指定组的测试 |
| `--parallel` 或 `-p` | 并行运行测试 |
| `--coverage` 或 `-c` | 生成代码覆盖率 |
| `--min` | 最小覆盖率要求 |
| `--testsuite` 或 `-s` | 运行指定测试套件 |
| `--path` | 指定测试路径 |

## 🎯 下一步

1. 运行 `composer require --dev pestphp/pest` 安装依赖
2. 运行 `php bin/w command:upgrade` 更新命令列表
3. 运行 `php bin/w test:pest:run` 测试集成是否成功

## 📚 相关文档

- [Pest PHP 官方文档](https://pestphp.com/docs)
- [WelineFramework 测试文档](../doc/3-开发/)
- [使用文档](./README.md)
