# Visitor模块单元测试

## 测试结构

```
app/code/Weline/Visitor/test/
├── Unit/
│   ├── Model/
│   │   ├── PixelTest.php              # 像素模型测试
│   │   └── PixelAdditionalTest.php    # 像素附加数据模型测试
│   ├── Service/
│   │   ├── PixelEncryptionServiceTest.php  # 加密服务测试
│   │   └── PixelEncryptionTokenManagementTest.php  # Token管理测试
│   ├── Api/
│   │   └── PixelApiTest.php           # 像素API测试
│   ├── Observer/
│   │   ├── LoginPixelTest.php         # 登录像素观察者测试
│   │   └── RegisterPixelTest.php      # 注册像素观察者测试
│   └── Cron/
│       └── CleanExpiredTokensTest.php # 清理过期Token定时任务测试
├── Http/
│   ├── PixelApiHttpTest.php           # 像素API HTTP测试
│   ├── AnalyticsApiHttpTest.php       # 分析API HTTP测试
│   └── run-all-tests.script.php       # 运行所有HTTP测试的脚本
├── Browser/
│   └── PixelTrackingBrowserTest.html  # 浏览器端像素跟踪测试页面
└── README.md
```

## 运行测试

### 单元测试（PHPUnit）

#### 运行所有Visitor模块测试

```bash
php bin/w p:r Weline_Visitor
```

#### 运行特定测试文件

```bash
# 运行像素模型测试
php bin/w p:r app/code/Weline/Visitor/test/Unit/Model/PixelTest.php

# 运行加密服务测试
php bin/w p:r app/code/Weline/Visitor/test/Unit/Service/PixelEncryptionServiceTest.php

# 运行API测试
php bin/w p:r app/code/Weline/Visitor/test/Unit/Api/PixelApiTest.php
```

#### 运行特定测试方法

```bash
# 运行特定测试方法（需要在测试类中指定）
php bin/w p:r Weline_Visitor --filter testSavePixelData
```

### HTTP集成测试（浏览器测试）

HTTP测试可以通过浏览器直接访问，适合手动测试和集成测试。

#### 访问测试主页

在浏览器中访问：
```
http://your-domain/visitor/test/http/run-all-tests.script
```

这将显示一个包含所有测试链接的页面。

#### 运行完整测试套件

**像素API完整测试：**
```
http://your-domain/visitor/test/http/pixel-api-http-test/run-all
```

**分析API完整测试：**
```
http://your-domain/visitor/test/http/analytics-api-http-test/run-all
```

#### 运行单独测试

**像素API测试：**
- 接收明文像素数据: `/visitor/test/http/pixel-api-http-test/plain-data`
- 接收加密像素数据: `/visitor/test/http/pixel-api-http-test/encrypted-data`
- 数据验证和清理: `/visitor/test/http/pixel-api-http-test/data-validation`
- 站点ID识别: `/visitor/test/http/pixel-api-http-test/website-id`
- A/B测试数据保存: `/visitor/test/http/pixel-api-http-test/ab-test-data`

**分析API测试：**
- 商业价值分析: `/visitor/test/http/analytics-api-http-test/business-value?websiteId=1&period=daily`
- 实时大屏数据: `/visitor/test/http/analytics-api-http-test/dashboard?websiteId=1&interval=10&hours=24`
- A/B测试数据: `/visitor/test/http/analytics-api-http-test/ab-test?websiteId=1&testId=test_001`

### 浏览器端测试（前端JavaScript测试）

浏览器测试页面提供了完整的前端像素跟踪功能测试，包括JavaScript交互、事件触发、数据发送等。

#### 访问浏览器测试页面

在浏览器中访问：
```
http://your-domain/visitor/test/browser
```

#### 测试功能

**基础像素跟踪测试：**
- 测试基础像素跟踪
- 测试A/B测试像素
- 测试自定义数据像素

**像素脚本加载测试：**
- 测试像素脚本加载
- 测试版本号获取

**事件触发测试：**
- 测试点击事件
- 测试自定义事件
- 测试A/B测试事件（变体A/B）

**数据验证测试：**
- 测试数据验证
- 测试站点ID检测

**完整测试套件：**
- 运行所有测试（自动执行所有测试用例）

## 测试覆盖范围

### 单元测试

#### PixelTest (像素模型测试)
- ✅ 保存像素数据
- ✅ 根据站点ID获取像素数据
- ✅ 统计站点像素数量
- ✅ 获取站点摘要信息
- ✅ IP地址正确获取和保存
- ✅ 浏览器、语言、网站ID、货币信息收集
- ✅ 像素数据正常收集和保存（完整数据）

#### PixelAdditionalTest (像素附加数据模型测试)
- ✅ 保存像素附加数据
- ✅ 根据像素ID获取附加数据
- ✅ 获取A/B测试数据
- ✅ 获取事件数据（数组格式）

#### PixelEncryptionServiceTest (加密服务测试)
- ✅ 加密和解密数据
- ✅ 使用指定版本加密和解密
- ✅ 获取当前版本token
- ✅ 生成版本token
- ✅ 多token解密尝试
- ✅ 版本号匹配
- ✅ 不同数据类型加密解密

#### PixelEncryptionTokenManagementTest (Token管理测试)
- ✅ 生成版本token
- ✅ 重复生成相同版本token不重复创建
- ✅ 旧token自动标记为已删除
- ✅ 获取当前版本token
- ✅ 根据版本号获取token
- ✅ 获取所有有效token

#### LoginPixelTest (登录像素观察者测试)
- ✅ 登录事件触发像素发送（有token）
- ✅ 登录事件无token时静默处理
- ✅ 登录事件缺少用户或请求时静默返回
- ✅ 登录事件加密数据正确发送

#### RegisterPixelTest (注册像素观察者测试)
- ✅ 注册事件触发像素发送（有token）
- ✅ 注册事件无token时静默处理
- ✅ 注册事件加密数据正确发送

#### CleanExpiredTokensTest (清理过期Token定时任务测试)
- ✅ 定时清理任务执行
- ✅ 清理任务不删除未过期的token
- ✅ 清理任务错误处理

#### PixelApiTest (像素API测试)
- ✅ 接收明文像素数据
- ✅ 数据验证和清理
- ✅ 站点ID识别
- ✅ 无token时的处理
- ✅ 解密失败时的处理
- ✅ API错误时的处理
- ✅ 接收加密数据并解密

### HTTP集成测试（浏览器测试）

#### PixelApiHttpTest (像素API HTTP测试)
- ✅ 接收明文像素数据（HTTP）
- ✅ 接收加密像素数据（HTTP）
- ✅ 数据验证和清理（HTTP）
- ✅ 站点ID识别（HTTP）
- ✅ A/B测试数据保存（HTTP）
- ✅ 运行所有像素API测试

#### AnalyticsApiHttpTest (分析API HTTP测试)
- ✅ 商业价值分析（HTTP）
- ✅ 实时大屏数据（HTTP）
- ✅ A/B测试数据分析（HTTP）
- ✅ 运行所有分析API测试

### 浏览器端测试（前端JavaScript测试）

#### PixelTrackingBrowserTest (浏览器像素跟踪测试)
- ✅ 基础像素跟踪测试
- ✅ A/B测试像素测试
- ✅ 自定义数据像素测试
- ✅ 像素脚本加载测试
- ✅ 版本号获取测试
- ✅ 点击事件测试
- ✅ 自定义事件测试
- ✅ A/B测试事件测试（变体A/B）
- ✅ 数据验证测试
- ✅ 站点ID检测测试
- ✅ 完整测试套件（自动运行所有测试）

## 测试数据清理

所有测试都会在 `tearDown()` 方法中自动清理测试数据，确保测试之间不会相互影响。

## 注意事项

1. **数据库要求**: 测试需要数据库连接，确保测试环境已正确配置
2. **测试数据隔离**: 每个测试使用独立的测试数据，避免数据冲突
3. **环境变量**: 某些测试可能需要特定的环境变量（如 `WELINE_WEBSITE_ID`）
4. **Token生成**: 加密服务测试可能需要先创建加密token，如果token不存在，相关测试会被跳过

## 扩展测试

如需添加新的测试用例，请遵循以下规范：

1. 测试类必须继承 `Weline\Framework\UnitTest\TestCore`
2. 测试方法必须以 `test` 开头
3. 在 `setUp()` 中初始化测试对象
4. 在 `tearDown()` 中清理测试数据
5. 使用有意义的测试数据，避免使用真实用户数据

## 示例

```php
<?php
declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Model;

use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;

class YourTest extends TestCore
{
    private Pixel $pixel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pixel = ObjectManager::getInstance(Pixel::class);
    }

    protected function tearDown(): void
    {
        if ($this->pixel->getId()) {
            $this->pixel->delete();
        }
        parent::tearDown();
    }

    public function testYourFunction()
    {
        // 测试逻辑
        $this->assertTrue(true);
    }
}
```

