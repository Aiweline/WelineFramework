# WeShop 控制器单元测试运行指南

## 文档概述

本文档提供如何运行和调试WeShop控制器单元测试的详细指南。

## 测试文件位置

所有测试文件位于各模块的 `Test/Unit/Controller/` 目录下：

```
app/code/WeShop/
├── Frontend/Test/Unit/Controller/
│   ├── BaseControllerTest.php (测试基类)
│   └── IndexTest.php
├── Product/Test/Unit/Controller/Frontend/Product/
│   ├── ProductListTest.php
│   └── ViewTest.php
├── Customer/Test/Unit/Controller/Frontend/Account/
│   ├── LoginTest.php
│   ├── RegisterTest.php
│   ├── ForgotPasswordTest.php
│   └── IndexTest.php
├── Cart/Test/Unit/Controller/Frontend/Cart/
│   └── IndexTest.php
└── ... (其他模块的测试文件)
```

## 运行测试

### 1. 运行所有测试

```bash
php bin/w phpunit:run -b
```

### 2. 运行特定模块的测试

```bash
# 运行Frontend模块的测试
php bin/w phpunit:run -b --filter WeShop_Frontend

# 运行Product模块的测试
php bin/w phpunit:run -b --filter WeShop_Product

# 运行Customer模块的测试
php bin/w phpunit:run -b --filter WeShop_Customer
```

### 3. 运行特定测试类

```bash
# 运行首页控制器测试
php bin/w phpunit:run -b --filter IndexTest

# 运行登录控制器测试
php bin/w phpunit:run -b --filter LoginTest

# 运行产品列表控制器测试
php bin/w phpunit:run -b --filter ProductListTest
```

### 4. 运行特定测试方法

```bash
# 运行特定测试方法
php bin/w phpunit:run -b --filter testControllerClassExists
php bin/w phpunit:run -b --filter testLayoutTypeIsHomepage
```

### 5. 生成测试覆盖率报告

```bash
php bin/w phpunit:run -b --coverage-html coverage/
```

## 测试状态说明

### 已完成的基础测试

所有测试文件都包含以下基础测试（✅ 已完成）：
- `testControllerClassExists()` - 验证控制器类存在
- `testControllerExtendsBaseController()` - 验证继承关系
- `testControllerHasIndexMethod()` - 验证方法存在
- `testLayoutTypeIsXxx()` - 验证layoutType属性设置

### 需要完善的功能测试

以下测试标记为 `markTestIncomplete`，需要完善Mock设置：
- 控制器方法的功能测试
- 数据传递验证测试
- 错误处理测试
- 边界条件测试

## Mock设置指南

### 1. Mock ObjectManager

由于ObjectManager使用静态方法 `getInstance()`，Mock比较困难。建议：

**方案1：使用依赖注入（推荐）**
- 修改控制器构造函数，接受Service作为参数
- 在测试中直接传入Mock对象

**方案2：使用Reflection设置私有属性**
```php
$reflection = new \ReflectionClass($controller);
$property = $reflection->getProperty('productService');
$property->setAccessible(true);
$property->setValue($controller, $mockProductService);
```

**方案3：使用测试替身（Test Double）**
- 创建测试专用的Service实现
- 在测试环境中替换ObjectManager

### 2. Mock Request对象

```php
$request = $this->createMock(Request::class);
$request->expects($this->any())
    ->method('getParam')
    ->willReturnMap([
        ['page', null, 1],
        ['category_id', null, 0],
        ['search', null, '']
    ]);

$request->expects($this->any())
    ->method('getPost')
    ->willReturnMap([
        ['email', 'test@example.com'],
        ['password', 'password123']
    ]);
```

### 3. Mock Service对象

```php
$productService = $this->createMock(ProductService::class);
$productService->expects($this->once())
    ->method('getProducts')
    ->with($this->isType('array'), $this->equalTo(1), $this->equalTo(20))
    ->willReturn([
        'items' => [
            [
                'product_id' => 1,
                'name' => 'Test Product',
                'price' => 99.99
            ]
        ],
        'total' => 1,
        'pagination' => ''
    ]);
```

### 4. Mock Session对象

```php
$customerSession = $this->createMock(CustomerSession::class);
$customerSession->expects($this->once())
    ->method('isLoggedIn')
    ->willReturn(true);

$customer = $this->createMock(Customer::class);
$customer->expects($this->any())
    ->method('getId')
    ->willReturn(1);

$customerSession->expects($this->once())
    ->method('getCustomer')
    ->willReturn($customer);
```

## 测试数据准备

### 1. 测试产品数据

```php
$testProducts = [
    [
        'product_id' => 1,
        'name' => 'Test Product 1',
        'price' => 99.99,
        'stock' => 10,
        'status' => 'enabled'
    ],
    [
        'product_id' => 2,
        'name' => 'Test Product 2',
        'price' => 199.99,
        'stock' => 5,
        'status' => 'enabled'
    ]
];
```

### 2. 测试分类数据

```php
$testCategories = [
    [
        'category_id' => 1,
        'name' => 'Category 1',
        'parent_id' => 0,
        'children' => []
    ]
];
```

### 3. 测试订单数据

```php
$testOrder = [
    'order_id' => 1,
    'increment_id' => 'ORD-20240101-0001',
    'customer_id' => 1,
    'status' => 'pending',
    'total' => 299.98,
    'created_at' => '2024-01-01 10:00:00'
];
```

## 常见测试场景

### 1. 测试成功场景

```php
public function testIndexSuccess(): void
{
    // 设置Mock返回成功数据
    $this->productService->expects($this->once())
        ->method('getProducts')
        ->willReturn(['items' => $testProducts, 'total' => 2]);
    
    // 执行方法
    $result = $this->controller->index();
    
    // 验证结果
    $this->assertIsString($result);
    $this->assertNotEmpty($result);
}
```

### 2. 测试错误场景

```php
public function testIndexHandlesServiceException(): void
{
    // 设置Mock抛出异常
    $this->productService->expects($this->once())
        ->method('getProducts')
        ->willThrowException(new \Exception('Service error'));
    
    // 验证异常处理
    $this->expectException(\Exception::class);
    
    $this->controller->index();
}
```

### 3. 测试边界条件

```php
public function testIndexHandlesEmptyResults(): void
{
    // 设置Mock返回空数据
    $this->productService->expects($this->once())
        ->method('getProducts')
        ->willReturn(['items' => [], 'total' => 0]);
    
    $result = $this->controller->index();
    
    // 验证空数据处理
    $this->assertIsString($result);
}
```

## 调试测试

### 1. 使用var_dump或print_r

```php
public function testIndex(): void
{
    $result = $this->controller->index();
    var_dump($result); // 调试输出
    $this->assertIsString($result);
}
```

### 2. 使用PHPUnit的断言方法

```php
// 验证值
$this->assertEquals($expected, $actual);

// 验证类型
$this->assertIsString($value);
$this->assertIsArray($value);

// 验证包含
$this->assertStringContainsString('expected', $actual);

// 验证计数
$this->assertCount(2, $array);
```

### 3. 使用Mock的expects验证

```php
// 验证方法被调用一次
$mock->expects($this->once())
    ->method('someMethod');

// 验证方法被调用指定次数
$mock->expects($this->exactly(3))
    ->method('someMethod');

// 验证方法参数
$mock->expects($this->once())
    ->method('someMethod')
    ->with($this->equalTo('expectedValue'));
```

## 测试最佳实践

### 1. 测试隔离

- 每个测试应该独立运行
- 使用setUp和tearDown清理测试环境
- 避免测试之间的依赖

### 2. 测试命名

- 使用描述性的测试方法名
- 遵循 `test{MethodName}{Scenario}` 格式
- 例如：`testPostLoginValidatesEmptyEmailAndPassword`

### 3. 测试组织

- 按功能分组测试
- 使用@group注解标记测试组
- 使用@dataProvider提供测试数据

### 4. 测试覆盖

- 测试成功场景
- 测试失败场景
- 测试边界条件
- 测试异常处理

## 已知问题和限制

### 1. ObjectManager静态调用

**问题**：ObjectManager使用静态方法，难以Mock

**解决方案**：
- 使用依赖注入
- 使用Reflection设置私有属性
- 创建测试专用的Service实现

### 2. 数据库依赖

**问题**：某些测试需要数据库连接

**解决方案**：
- 使用Mock对象替代数据库
- 使用测试数据库
- 使用事务回滚

### 3. Session依赖

**问题**：控制器依赖Session状态

**解决方案**：
- Mock Session对象
- 使用测试专用的Session实现
- 在setUp中初始化Session状态

## 下一步工作

1. **完善Mock设置**
   - 为所有控制器创建完整的Mock对象
   - 解决ObjectManager Mock问题
   - 创建测试数据工厂

2. **实现功能测试**
   - 完成所有标记为incomplete的测试
   - 添加错误处理测试
   - 添加边界条件测试

3. **提高测试覆盖率**
   - 目标：90%以上覆盖率
   - 覆盖所有控制器方法
   - 覆盖所有错误处理路径

4. **集成测试**
   - 使用HTTP请求工具进行端到端测试
   - 验证实际HTTP响应
   - 验证布局加载

---

**最后更新**: 2024-01-XX
**状态**: 基础测试已完成，功能测试待完善
