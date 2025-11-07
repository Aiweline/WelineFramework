# Weline_Cdn 模块单元测试

## 概述

本目录包含 Weline_Cdn 模块的单元测试文件，使用 PHPUnit 作为测试框架。

## 测试结构

```
Test/
├── Unit/                          # 单元测试
│   ├── Model/                    # Model层测试
│   │   ├── AccountTest.php       # 账户模型测试
│   │   └── DomainTest.php        # 域名模型测试
│   ├── Service/                  # Service层测试
│   │   ├── AdapterResolverTest.php    # 适配器解析器测试
│   │   └── AccountManagerTest.php     # 账户管理服务测试
│   └── Adapter/                  # Adapter层测试
│       └── CloudflareTest.php    # Cloudflare适配器测试
└── README.md                     # 本文件
```

## 运行测试

### 运行所有测试

```bash
# 运行Weline_Cdn模块的所有测试
php bin/w p:r Weline_Cdn

# 或者使用完整路径
php bin/w p:r app/code/Weline/Cdn/Test
```

### 运行特定测试文件

```bash
# 运行Account模型测试
php bin/w p:r app/code/Weline/Cdn/Test/Unit/Model/AccountTest.php

# 运行AdapterResolver服务测试
php bin/w p:r app/code/Weline/Cdn/Test/Unit/Service/AdapterResolverTest.php
```

### 运行特定测试类

```bash
php bin/w phpunit:run --name=AccountTest
```

## 测试覆盖范围

### Model层

- ✅ `Account` - 账户模型
  - 字段常量定义
  - 数据设置和获取
  - 凭据数组处理
  - 状态管理

- ✅ `Domain` - 域名模型
  - 字段常量定义
  - 数据设置和获取
  - 规则覆盖处理

### Service层

- ✅ `AdapterResolver` - 适配器解析器
  - 适配器扫描
  - 适配器获取
  - 适配器验证

- ✅ `AccountManager` - 账户管理服务
  - 账户获取
  - 默认账户设置
  - 账户删除

### Adapter层

- ✅ `Cloudflare` - Cloudflare适配器
  - 接口实现验证
  - 方法存在性检查
  - 基础功能测试

## 测试注意事项

### 数据库依赖

部分测试需要数据库连接。如果数据库未配置，测试会自动跳过：

```php
$this->markTestSkipped('数据库未配置，跳过测试');
```

### API Token依赖

Cloudflare适配器的实际功能测试需要有效的API Token。当前测试主要验证接口和方法的存在性，不进行实际的API调用。

### Mock对象

某些测试可能需要mock对象来隔离依赖。可以在测试中使用PHPUnit的mock功能：

```php
$mock = $this->createMock(SomeClass::class);
```

## 添加新测试

### 测试文件命名规范

- 测试类名应该以 `Test` 结尾
- 文件名应该与类名一致
- 测试方法应该以 `test` 开头

### 测试方法示例

```php
public function testYourFunction(): void
{
    // Arrange - 准备测试数据
    $data = ['key' => 'value'];
    
    // Act - 执行被测试的方法
    $result = $this->service->yourFunction($data);
    
    // Assert - 验证结果
    $this->assertNotNull($result);
    $this->assertEquals('expected', $result);
}
```

## 持续集成

测试报告会自动生成在 `dev/phpunit/report/` 目录下：

- `index.html` - HTML测试报告
- `junit.xml` - JUnit XML格式报告
- `testdox.txt` - TestDox文本报告

## 参考文档

- [WelineFramework 单元测试指南](../../../../docs/单元测试.md)
- [PHPUnit 官方文档](https://phpunit.de/documentation.html)

