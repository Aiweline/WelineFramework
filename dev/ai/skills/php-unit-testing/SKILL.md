---
name: php-unit-testing
description: |
  测试技能 - 以 PHPUnit 单元测试为主。测试功能时优先写单元测试。
  
  触发词：测试, test, 单元测试, unit test, phpunit, 测一下, 验证, verify, 功能测试,
  怎么测, 如何测试, 测试方法, 测试用例, 跑测试, 运行测试, 写测试, 加测试,
  Test.php, /test/, /Test/, assert, 断言, mock, 模拟, integration test, 集成测试,
  HTTP 测试, 接口测试, API 测试, 路由测试, 端点测试
globs:
  - "**/test/**/*.php"
  - "**/Test/**/*.php"
  - "**/*Test.php"
alwaysApply: false
---

# Weline Framework 测试技能

## 核心原则：以单元测试为主

**测试策略优先级：**
1. **PHPUnit 单元测试** — 首选，覆盖模型、服务、控制器等
2. **http:req 命令** — 辅助，快速验证 HTTP 路由/接口
3. **Browser MCP** — 仅限前端 UI 交互验证

**禁止：** 为每个功能单独创建测试脚本文件！测试代码统一放在模块 `test/` 目录。

---

## 一、PHPUnit 单元测试（首选）

### 1.1 测试目录结构

```
app/code/Vendor/Module/
├── test/                         # 测试目录
│   ├── Unit/                     # 单元测试
│   │   ├── Model/
│   │   │   └── MyModelTest.php
│   │   └── Service/
│   │       └── MyServiceTest.php
│   └── Integration/              # 集成测试（需要时）
│       └── MyIntegrationTest.php
```

### 1.2 运行测试命令

```bash
# 运行模块全部测试
php bin/w phpunit:run --module=Vendor_Module

# 运行指定测试文件
php bin/w phpunit:run --module=Vendor_Module --name=Unit/Service/MyServiceTest.php

# 运行后台环境测试（需要后台权限）
php bin/w phpunit:run -b --module=Vendor_Module

# 查看帮助
php bin/w phpunit:run -h
```

### 1.3 测试类模板

```php
<?php
declare(strict_types=1);

namespace Vendor\Module\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Vendor\Module\Service\MyService;

class MyServiceTest extends TestCase
{
    private MyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MyService();
    }

    public function testBasicFunctionality(): void
    {
        $result = $this->service->process('input');
        $this->assertEquals('expected', $result);
    }

    public function testEdgeCases(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->process('');
    }

    /**
     * @dataProvider dataProvider
     */
    public function testWithDataProvider(string $input, string $expected): void
    {
        $this->assertEquals($expected, $this->service->transform($input));
    }

    public static function dataProvider(): array
    {
        return [
            ['case1', 'result1'],
            ['case2', 'result2'],
        ];
    }
}
```

### 1.4 常用断言

```php
// 相等性
$this->assertEquals($expected, $actual);
$this->assertSame($expected, $actual);        // 严格类型
$this->assertNotEquals($expected, $actual);

// 布尔
$this->assertTrue($condition);
$this->assertFalse($condition);

// Null
$this->assertNull($value);
$this->assertNotNull($value);

// 数组
$this->assertCount(3, $array);
$this->assertArrayHasKey('key', $array);
$this->assertContains('item', $array);

// 字符串
$this->assertStringContainsString('needle', $haystack);
$this->assertMatchesRegularExpression('/pattern/', $string);

// 异常
$this->expectException(ExceptionClass::class);
$this->expectExceptionMessage('error message');

// 类型
$this->assertInstanceOf(ClassName::class, $object);
```

### 1.5 Mock 与依赖

```php
// 创建 Mock
$mock = $this->createMock(DependencyInterface::class);
$mock->method('getData')->willReturn(['test' => 'data']);

// 验证调用
$mock->expects($this->once())
     ->method('save')
     ->with($this->equalTo($expectedData));

// 注入依赖
$service = new MyService($mock);
```

---

## 二、HTTP 请求测试（辅助）

用于快速验证 HTTP 路由、API 接口是否正常。

### 2.1 基本用法

```bash
# 测试前端路由
php bin/w http:req /

# 测试后台路由（自动登录）
php bin/w http:req admin/dashboard -b

# 测试 API 接口（自动登录）
php bin/w http:req rest/v1/data -api

# 搜索响应内容
php bin/w http:req / filter=welcome

# POST 请求
php bin/w http:req api/submit -m=POST -d='{"key":"value"}'
```

### 2.2 常用选项

| 选项 | 说明 |
|------|------|
| `-b` | 后台路由，自动登录 |
| `-api` | API 路由，自动登录 |
| `-m=POST` | HTTP 方法 |
| `-d='...'` | 请求数据 |
| `filter=xxx` | 搜索响应内容 |
| `-n=5` | filter 上下文行数 |
| `-C -t=100` | 并发测试 100 次 |

### 2.3 集成到单元测试

```php
public function testApiEndpoint(): void
{
    // 在单元测试中调用 HTTP 命令
    $output = shell_exec('php bin/w http:req rest/v1/users -api 2>&1');
    $this->assertStringContainsString('200', $output);
    $this->assertStringContainsString('"success":true', $output);
}
```

---

## 三、前端测试（仅限 UI 验证）

仅在需要验证前端页面交互时使用 Browser MCP。

### 3.1 Browser MCP 快速验证

```javascript
// 导航
browser_navigate({ url: 'http://127.0.0.1:9981/admin/login' })

// 获取页面快照
browser_snapshot()

// 填写表单
browser_fill({ elementRef: 'ref', value: 'admin' })

// 点击
browser_click({ elementRef: 'ref' })
```

### 3.2 Playwright E2E（CI/CD 场景）

测试文件位置：`app/code/Vendor/Module/test/e2e/`

```bash
cd tests/e2e
npm start -- --module=Vendor_Module
```

---

## 四、测试工作流

### 4.1 TDD 流程

1. **写测试** — 先写测试用例，确保失败
2. **实现代码** — 写最少代码让测试通过
3. **重构** — 优化代码，保持测试通过

### 4.2 功能完成检查清单

- [ ] 核心逻辑有单元测试覆盖
- [ ] 边界情况和异常情况测试
- [ ] HTTP 接口用 `http:req` 验证
- [ ] 前端交互用 Browser MCP 验证（如有）

---

## 五、常见问题

### PHPUnit 版本参数不兼容
```
Unknown option "--no-interaction"
```
**处理**：检查 `phpunit:run` 命令，移除不支持的参数。

### 只运行单个测试
```bash
php bin/w phpunit:run --module=Vendor_Module --name=Unit/Service/MyServiceTest.php
```

### 内存溢出
**处理**：避免全量扫描，指定具体测试文件。

---

## 六、禁止事项

- ❌ **禁止**为每个功能创建单独的测试脚本文件
- ❌ **禁止**只手动测试不写自动化测试
- ❌ **禁止**跳过边界条件和异常场景测试
- ✅ **必须**将测试代码放在模块 `test/` 目录
- ✅ **必须**遵循 `*Test.php` 命名规范
