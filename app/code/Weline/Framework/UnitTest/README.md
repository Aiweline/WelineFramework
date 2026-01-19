# Pest PHP 测试框架集成

## 概述

WelineFramework 已集成 Pest PHP 测试框架，支持通过命令行参数自动加载测试环境。

## 安装

### 1. 安装 Pest 依赖

```bash
# 安装 Pest 1.x（兼容 PHPUnit 9.6）
composer require --dev pestphp/pest

# 允许 Pest 插件
composer config --no-plugins allow-plugins.pestphp/pest-plugin true
```

**注意**：由于项目使用 PHPUnit 9.6，我们使用 Pest 1.x 版本以确保兼容性。

### 2. 初始化 Pest 配置

Pest 配置文件已位于项目根目录的 `Pest.php` 文件中。

## 使用方法

### 方式一：通过命令行参数自动加载

在启动服务时添加 `--test` 或 `-t` 参数：

```bash
# 启动服务并启用测试模式
php bin/w server:start --test

# 或者使用短参数
php bin/w server:start -t
```

### 方式二：通过环境变量

```bash
# 设置环境变量
export WELINE_ENABLE_TEST=1

# 启动服务
php bin/w server:start
```

### 方式三：通过控制台命令运行测试

```bash
# 运行所有测试
php bin/w test:pest:run

# 运行指定目录的测试
php bin/w test:pest:run --path=tests/Unit

# 运行过滤的测试
php bin/w test:pest:run --filter=ExampleTest

# 运行指定组的测试
php bin/w test:pest:run --group=unit

# 并行运行测试
php bin/w test:pest:run --parallel

# 生成代码覆盖率报告
php bin/w test:pest:run --coverage
```

### 方式四：直接使用 Pest 命令

```bash
# 运行所有测试
vendor/bin/pest

# 运行指定文件
vendor/bin/pest tests/ExampleTest.php

# 运行过滤的测试
vendor/bin/pest --filter=ExampleTest

# 并行运行
vendor/bin/pest --parallel
```

## 测试文件结构

测试文件应放在 `tests/` 目录下，使用 `.php` 扩展名。

### 示例测试文件

```php
<?php

use Weline\Framework\UnitTest\TestCore;

uses(TestCore::class);

test('示例测试', function () {
    expect(true)->toBeTrue();
});
```

## 配置说明

### Pest.php 配置文件

项目根目录的 `Pest.php` 文件配置了测试基类：

```php
uses(TestCore::class)->in('tests');
```

### 测试基类

所有测试都继承自 `Weline\Framework\UnitTest\TestCore`，该类提供了：

- 框架初始化
- 对象管理器访问
- 请求初始化方法

## 命令行参数说明

### 启动服务时的测试参数

| 参数 | 说明 |
|------|------|
| `--test` 或 `-t` | 启用测试模式，自动加载 Pest 测试框架 |

### 运行测试时的参数

| 参数 | 说明 | 示例 |
|------|------|------|
| `--filter` 或 `-f` | 过滤测试名称 | `--filter=ExampleTest` |
| `--group` 或 `-g` | 运行指定组的测试 | `--group=unit` |
| `--parallel` 或 `-p` | 并行运行测试 | `--parallel` |
| `--coverage` 或 `-c` | 生成代码覆盖率 | `--coverage` |
| `--min` | 最小覆盖率要求 | `--min=80` |
| `--testsuite` 或 `-s` | 运行指定测试套件 | `--testsuite=Unit` |
| `--path` | 指定测试路径 | `--path=tests/Unit` |

## 环境变量

| 变量名 | 值 | 说明 |
|--------|-----|------|
| `WELINE_ENABLE_TEST` | `1` 或 `true` | 启用测试模式 |

## 注意事项

1. **开发依赖**：Pest 作为开发依赖安装，不会影响生产环境
2. **自动加载**：只有在指定参数或环境变量时才会加载测试框架
3. **静默失败**：如果 Pest 未安装，框架会静默失败，不影响正常应用运行
4. **测试环境**：启用测试模式时，会自动设置 `ENV_TEST` 常量为 `true`

## 故障排除

### Pest 未安装

如果遇到 "Pest 测试框架未安装" 错误，请运行：

```bash
composer require --dev pestphp/pest
```

### 测试无法运行

1. 检查 Pest 是否正确安装：`vendor/bin/pest --version`
2. 检查测试文件是否在 `tests/` 目录下
3. 检查 Pest.php 配置文件是否存在

### 框架初始化失败

确保 `app/bootstrap_phpunit.php` 文件存在且正确配置。

## 相关文件

- `app/code/Weline/Framework/UnitTest/Pest/Pest.php` - Pest 集成类
- `app/code/Weline/Framework/UnitTest/Pest/Boot.php` - Pest 启动类
- `app/code/Weline/Framework/UnitTest/Console/Pest/Run.php` - Pest 测试运行命令
- `app/code/Weline/Framework/UnitTest/Console/PhpUnit/Run.php` - PHPUnit 测试运行命令（默认支持 Pest）
- `Pest.php` - Pest 配置文件（项目根目录）
- `tests/ExampleTest.php` - 示例测试文件

## phpunit:run 命令支持

`phpunit:run` 命令现在默认优先使用 Pest 测试框架（如果已安装）：

```bash
# 默认使用 Pest（如果已安装）
php bin/w phpunit:run

# 强制使用 PHPUnit
php bin/w phpunit:run --phpunit

# 使用 Pest 运行指定测试
php bin/w phpunit:run --name=ExampleTest

# 使用 Pest 运行指定模块
php bin/w phpunit:run --module=Weline_Framework
```

### 测试框架选择逻辑

1. **默认行为**：如果 Pest 已安装，优先使用 Pest
2. **强制 PHPUnit**：使用 `--phpunit` 参数强制使用 PHPUnit
3. **自动回退**：如果 Pest 运行失败，自动回退到 PHPUnit

## 更多信息

- [Pest PHP 官方文档](https://pestphp.com/docs)
- [WelineFramework 测试文档](../doc/3-开发/)
