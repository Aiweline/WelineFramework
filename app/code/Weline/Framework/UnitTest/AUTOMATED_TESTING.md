# 自动化测试用例文档

## 概述

WelineFramework 集成了完整的自动化测试框架，支持 PHPUnit 和 Pest 两种测试框架，提供了灵活的测试运行方式和自动化测试能力。

## 测试框架支持

### 1. Pest PHP（推荐）

Pest 是一个优雅的 PHP 测试框架，提供了简洁的语法和强大的功能。

**特点：**
- 简洁优雅的测试语法
- 内置断言库
- 支持并行测试
- 代码覆盖率支持
- 更好的错误信息

**安装：**
```bash
composer require --dev pestphp/pest
```

### 2. PHPUnit（传统）

PHPUnit 是 PHP 生态系统中广泛使用的测试框架。

**特点：**
- 成熟稳定
- 丰富的功能
- 详细的测试报告
- HTML 报告支持

## 测试运行方式

### 方式一：使用 phpunit:run 命令（推荐）

`phpunit:run` 命令现在默认优先使用 Pest，如果 Pest 未安装则使用 PHPUnit。

```bash
# 默认使用 Pest（如果已安装）
php bin/w phpunit:run

# 运行指定测试文件
php bin/w phpunit:run --name=ExampleTest

# 运行指定模块的测试
php bin/w phpunit:run --module=Weline_Framework

# 运行指定测试方法
php bin/w phpunit:run --name=ExampleTest::testMethod

# 强制使用 PHPUnit
php bin/w phpunit:run --phpunit

# 使用 Pest 的并行测试
php bin/w phpunit:run --parallel

# 生成代码覆盖率
php bin/w phpunit:run --coverage
```

### 方式二：使用 test:pest:run 命令

专门用于运行 Pest 测试的命令。

```bash
# 运行所有测试
php bin/w test:pest:run

# 运行指定目录
php bin/w test:pest:run --path=tests/Unit

# 过滤测试
php bin/w test:pest:run --filter=ExampleTest

# 并行运行
php bin/w test:pest:run --parallel
```

### 方式三：直接使用框架命令

```bash
# 使用 Pest
vendor/bin/pest

# 使用 PHPUnit
vendor/bin/phpunit
```

## 测试文件结构

### Pest 测试文件

测试文件应放在 `tests/` 目录下：

```php
<?php

use Weline\Framework\UnitTest\TestCore;

uses(TestCore::class);

test('示例测试', function () {
    expect(true)->toBeTrue();
    expect(1 + 1)->toBe(2);
});

test('字符串操作', function () {
    $str = 'Hello Weline';
    expect($str)->toContain('Weline');
});
```

### PHPUnit 测试文件

测试文件应放在模块的 `Test` 目录下：

```php
<?php

namespace Weline\Framework\Test;

use Weline\Framework\UnitTest\TestCore;
use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCore
{
    public function testExample()
    {
        $this->assertTrue(true);
        $this->assertEquals(2, 1 + 1);
    }
}
```

## 自动化测试配置

### 1. CI/CD 集成

#### GitHub Actions 示例

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    steps:
      - uses: actions/checkout@v2
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
          
      - name: Install dependencies
        run: composer install
        
      - name: Run tests
        run: php bin/w phpunit:run --coverage
```

#### GitLab CI 示例

```yaml
test:
  image: php:8.1
  script:
    - composer install
    - php bin/w phpunit:run --coverage
```

### 2. 本地开发环境

#### 使用测试模式启动服务

```bash
# 启动服务并启用测试模式
php bin/w server:start --test

# 或使用环境变量
export WELINE_ENABLE_TEST=1
php bin/w server:start
```

#### 运行特定测试

```bash
# 运行单个测试文件
php bin/w phpunit:run --name=ExampleTest

# 运行单个测试方法
php bin/w phpunit:run --name=ExampleTest::testMethod

# 运行模块的所有测试
php bin/w phpunit:run --module=Weline_Framework
```

## 测试最佳实践

### 1. 测试组织

- **单元测试**：测试单个类或方法
- **集成测试**：测试模块之间的交互
- **功能测试**：测试完整的功能流程

### 2. 测试命名

- 使用描述性的测试名称
- 遵循 `test` 前缀或 `test()` 函数
- 说明测试的目的和预期结果

### 3. 测试隔离

- 每个测试应该独立运行
- 使用 setUp 和 tearDown 方法
- 避免测试之间的依赖

### 4. 断言使用

**Pest 断言：**
```php
expect($value)->toBe($expected);
expect($value)->toBeTrue();
expect($value)->toContain('text');
expect($array)->toHaveCount(5);
```

**PHPUnit 断言：**
```php
$this->assertEquals($expected, $value);
$this->assertTrue($value);
$this->assertContains('text', $value);
$this->assertCount(5, $array);
```

## 代码覆盖率

### 生成覆盖率报告

```bash
# 使用 Pest
php bin/w phpunit:run --coverage

# 使用 PHPUnit
php bin/w phpunit:run --phpunit --coverage
```

### 覆盖率要求

```bash
# 设置最小覆盖率要求（Pest）
php bin/w phpunit:run --coverage --min=80
```

## 测试调试

### 启用调试模式

```bash
# 显示详细调试信息
php bin/w phpunit:run --debug

# 运行单个测试并查看详细输出
php bin/w phpunit:run --name=ExampleTest --debug
```

### 常见问题排查

1. **测试无法运行**
   - 检查测试文件是否存在
   - 检查命名空间是否正确
   - 检查测试类是否继承正确的基类

2. **Pest 未找到**
   - 运行 `composer require --dev pestphp/pest`
   - 检查 `vendor/bin/pest` 是否存在

3. **PHPUnit 配置问题**
   - 检查 `phpunit.xml` 配置文件
   - 检查测试套件配置

## 持续集成建议

### 1. 自动化测试流程

1. **代码提交时**：运行快速测试套件
2. **Pull Request 时**：运行完整测试套件和代码覆盖率
3. **发布前**：运行所有测试并生成报告

### 2. 测试报告

- 使用 HTML 报告查看详细结果
- 集成到 CI/CD 平台
- 设置覆盖率阈值

### 3. 性能优化

- 使用并行测试加速执行
- 只运行相关的测试
- 使用测试缓存

## 相关资源

- [Pest PHP 官方文档](https://pestphp.com/docs)
- [PHPUnit 官方文档](https://phpunit.de/documentation.html)
- [WelineFramework 测试文档](./README.md)

## 总结

WelineFramework 提供了完整的自动化测试解决方案：

- ✅ 支持 Pest 和 PHPUnit 两种框架
- ✅ 灵活的测试运行方式
- ✅ 智能的框架选择机制
- ✅ 完善的 CI/CD 集成支持
- ✅ 详细的测试报告和覆盖率支持

通过合理使用这些功能，可以确保代码质量和系统的稳定性。
