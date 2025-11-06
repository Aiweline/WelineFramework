# Weline_Cdn 模块集成测试建议

## 概述

当前单元测试覆盖率已达到 **77.3%**，核心业务逻辑（Model、Service、Adapter、Observer、Cron、Console）已实现 100% 覆盖。

剩余未覆盖的部分主要是 **Controller层**（5个控制器），建议使用集成测试来覆盖。

## 为什么需要集成测试？

### Controller层的特点

1. **依赖HTTP请求**：需要模拟HTTP请求和响应
2. **依赖路由系统**：需要完整的路由配置
3. **依赖认证系统**：需要模拟用户认证
4. **依赖视图渲染**：需要完整的模板系统
5. **依赖数据库**：需要真实的数据库连接

### 集成测试的优势

- ✅ 测试完整的请求-响应流程
- ✅ 验证路由配置正确性
- ✅ 测试认证和权限控制
- ✅ 验证数据持久化
- ✅ 测试异常处理

## 建议的集成测试方案

### 方案1：HTTP集成测试（推荐）

创建HTTP测试脚本，模拟真实的HTTP请求：

```php
// Test/Integration/Http/Backend/AccountHttpTest.php
<?php
namespace Weline\Cdn\Test\Integration\Http\Backend;

use PHPUnit\Framework\TestCase;

class AccountHttpTest extends TestCase
{
    public function testAccountList()
    {
        // 模拟HTTP请求
        $response = $this->get('/cdn/backend/account/index');
        
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('账户管理', $response->getContent());
    }
    
    public function testAccountCreate()
    {
        // 模拟POST请求
        $response = $this->post('/cdn/backend/account/save', [
            'adapter' => 'cloudflare',
            'name' => 'Test Account',
            'credentials' => ['api_token' => 'test-token']
        ]);
        
        $this->assertEquals(200, $response->getStatusCode());
    }
}
```

### 方案2：Controller单元测试（需要大量Mock）

使用PHPUnit Mock来隔离依赖：

```php
// Test/Unit/Controller/Backend/AccountTest.php
<?php
namespace Weline\Cdn\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Controller\Backend\Account;

class AccountTest extends TestCase
{
    public function testIndex()
    {
        // Mock Request, Response, Service等
        $request = $this->createMock(Request::class);
        $response = $this->createMock(Response::class);
        $accountManager = $this->createMock(AccountManager::class);
        
        $controller = new Account($request, $response, $accountManager);
        $result = $controller->index();
        
        // 验证结果
    }
}
```

### 方案3：端到端测试（E2E）

使用浏览器自动化工具（如Selenium）进行完整测试：

```bash
# 使用Selenium进行E2E测试
php bin/w selenium:test --module=Weline_Cdn
```

## 推荐的测试文件结构

```
Test/
├── Unit/                    # 单元测试（已完成）
│   ├── Model/
│   ├── Service/
│   ├── Adapter/
│   ├── Observer/
│   ├── Cron/
│   └── Console/
├── Integration/             # 集成测试（建议创建）
│   ├── Http/
│   │   ├── Backend/
│   │   │   ├── AccountHttpTest.php
│   │   │   ├── DomainHttpTest.php
│   │   │   ├── RulesHttpTest.php
│   │   │   └── WarmupHttpTest.php
│   │   └── Api/
│   │       └── ClearApiTest.php
│   └── Database/
│       └── ModelIntegrationTest.php
└── README.md
```

## 集成测试示例

### 示例1：账户管理API测试

```php
<?php
namespace Weline\Cdn\Test\Integration\Http\Backend;

use PHPUnit\Framework\TestCase;

class AccountHttpTest extends TestCase
{
    private $baseUrl = 'http://localhost/cdn/backend/account';
    
    public function testGetAccountList()
    {
        $ch = curl_init($this->baseUrl . '/index');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer test-token'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $this->assertEquals(200, $httpCode);
        $this->assertStringContainsString('账户管理', $response);
    }
    
    public function testCreateAccount()
    {
        $data = [
            'adapter' => 'cloudflare',
            'name' => 'Test Account',
            'credentials' => json_encode(['api_token' => 'test-token'])
        ];
        
        $ch = curl_init($this->baseUrl . '/save');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer test-token'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $this->assertEquals(200, $httpCode);
    }
}
```

## 测试数据准备

### 测试数据库

建议使用独立的测试数据库：

```php
// Test/Integration/Fixture/TestDatabase.php
class TestDatabase
{
    public static function setUp(): void
    {
        // 创建测试数据库
        // 加载测试数据
    }
    
    public static function tearDown(): void
    {
        // 清理测试数据
    }
}
```

### 测试数据工厂

```php
// Test/Integration/Fixture/AccountFactory.php
class AccountFactory
{
    public static function create(array $data = []): Account
    {
        $defaults = [
            'adapter' => 'cloudflare',
            'name' => 'Test Account',
            'credentials' => ['api_token' => 'test-token'],
            'status' => 'active'
        ];
        
        $account = new Account();
        $account->setData(array_merge($defaults, $data));
        $account->save();
        
        return $account;
    }
}
```

## 运行集成测试

```bash
# 运行所有集成测试
php bin/w phpunit:run --path=app/code/Weline/Cdn/Test/Integration

# 运行特定集成测试
php bin/w phpunit:run --path=app/code/Weline/Cdn/Test/Integration/Http/Backend
```

## 测试覆盖率目标

完成集成测试后，预期覆盖率：

- **总体覆盖率**: 80%+
- **Controller层**: 60%+（集成测试覆盖主要功能）
- **所有核心功能**: 100%

## 参考资源

- [WelineFramework 集成测试指南](../../../../docs/dev/开发文档.md#集成测试)
- [PHPUnit HTTP测试](https://phpunit.de/documentation.html)
- [HTTP测试最佳实践](https://www.phpunit.de/manual/current/en/testing.html)

---

**注意**: Controller层的单元测试需要大量mock，建议优先使用集成测试来覆盖主要功能场景。

