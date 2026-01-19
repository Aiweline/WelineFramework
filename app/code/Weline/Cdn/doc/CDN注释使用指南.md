# CDN注释使用指南

## 1. 背景介绍

### 1.1 为什么需要CDN注释规则？

在传统的CDN缓存规则管理中，开发者需要：
- 在后台手动配置缓存规则
- 维护复杂的JSON配置文件
- 规则分散在多个地方，难以管理
- 规则与代码分离，容易遗忘更新

**CDN注释规则**通过在代码中直接定义缓存规则，解决了这些问题：
- ✅ **代码即文档**：规则与代码在一起，一目了然
- ✅ **自动收集**：系统自动扫描和收集规则
- ✅ **统一管理**：所有规则集中管理，便于查看和维护
- ✅ **自动推送**：规则自动推送到CDN服务商

### 1.2 工作原理

```
开发者添加@Cdn注释
    ↓
系统升级时自动扫描（setup:upgrade）
    ↓
解析注释并转换为标准格式
    ↓
存储到数据库
    ↓
定时或实时推送到CDN服务商
```

### 1.3 规则通用性

**重要**：注释规则是**通用的**，不指定适配器，适用于所有CDN供应商：
- 规则以Cloudflare Cache Rules格式存储（作为统一标准）
- 所有CDN适配器都会收到规则推送事件
- 各适配器自行将规则转换为自己的格式（如需要）
- 开发者无需关心使用哪个CDN服务商

## 2. 基本用法

### 2.1 简单格式

最简单的用法，只指定缓存时间：

```php
/**
 * 获取产品列表
 * 
 * @Cdn cache=15m
 * @return array
 */
public function getList(): array
{
    // 业务逻辑
    return [];
}
```

**说明**：
- `cache=15m` 表示缓存15分钟
- 默认定时推送（每15分钟）
- 适用于所有CDN服务商

### 2.2 详细格式

指定更多参数：

```php
/**
 * 获取产品详情
 * 
 * @Cdn cache=1h status=200,301,404 description="产品详情缓存1小时"
 * @return array
 */
public function getDetail(): array
{
    // 业务逻辑
    return [];
}
```

**参数说明**：
- `cache=1h`：缓存1小时
- `status=200,301,404`：只缓存这些状态码
- `description`：规则描述

### 2.3 禁用缓存

对于不需要缓存的接口：

```php
/**
 * 创建产品（不缓存）
 * 
 * @Cdn cache=false
 * @return array
 */
public function create(): array
{
    // 业务逻辑
    return [];
}
```

### 2.4 实时推送

需要立即生效的规则：

```php
/**
 * 获取实时数据（立即推送规则）
 * 
 * @Cdn cache=15m trigger=realtime description="实时数据缓存15分钟"
 * @return array
 */
public function getRealtimeData(): array
{
    // 业务逻辑
    return [];
}
```

**说明**：
- `trigger=realtime`：规则收集后立即推送
- `trigger=cron` 或不指定：定时推送（默认，每15分钟）

## 3. API接口使用示例

### 3.1 RESTful API示例

```php
<?php

namespace Weline\Product\Api\Rest\V1;

use Weline\Framework\Controller\AbstractRestController;

class Product extends AbstractRestController
{
    /**
     * 获取产品列表
     * 
     * 路由：/api/rest/v1/product/get-list
     * 
     * @Cdn cache=15m description="产品列表API缓存15分钟"
     * @return string JSON格式
     */
    public function getList(): string
    {
        $products = [
            ['id' => 1, 'name' => 'Product 1'],
            ['id' => 2, 'name' => 'Product 2'],
        ];
        
        return $this->success('获取成功', $products);
    }
    
    /**
     * 获取产品详情
     * 
     * 路由：/api/rest/v1/product/get-detail
     * 
     * @Cdn cache=1h status=200,301 description="产品详情缓存1小时"
     * @return string JSON格式
     */
    public function getDetail(): string
    {
        $productId = $this->request->getParam('id');
        $product = ['id' => $productId, 'name' => 'Product Detail'];
        
        return $this->success('获取成功', $product);
    }
    
    /**
     * 创建产品（不缓存）
     * 
     * 路由：/api/rest/v1/product/create
     * 
     * @Cdn cache=false description="创建产品接口不缓存"
     * @return string JSON格式
     */
    public function create(): string
    {
        // 创建产品逻辑
        return $this->success('创建成功');
    }
    
    /**
     * 更新产品（不缓存，实时推送）
     * 
     * 路由：/api/rest/v1/product/update
     * 
     * @Cdn cache=false trigger=realtime description="更新产品接口不缓存，立即推送"
     * @return string JSON格式
     */
    public function update(): string
    {
        // 更新产品逻辑
        return $this->success('更新成功');
    }
}
```

### 3.2 前端API示例

```php
<?php

namespace Weline\Product\Api\Rest\V1\Frontend;

use Weline\Framework\Controller\FrontendRestController;

class Product extends FrontendRestController
{
    /**
     * 获取热门产品
     * 
     * 路由：/api/rest/v1/frontend/product/get-hot
     * 
     * @Cdn cache=30m description="热门产品缓存30分钟"
     * @return string JSON格式
     */
    public function getHot(): string
    {
        $hotProducts = [
            ['id' => 1, 'name' => 'Hot Product 1'],
        ];
        
        return $this->success('获取成功', $hotProducts);
    }
}
```

### 3.3 后端API示例

```php
<?php

namespace Weline\Product\Api\Rest\V1\Backend;

use Weline\Framework\Controller\BackendRestController;

class Product extends BackendRestController
{
    /**
     * 获取产品统计
     * 
     * 路由：/api/rest/v1/backend/product/get-stats
     * 
     * @Cdn cache=5m description="产品统计缓存5分钟"
     * @return string JSON格式
     */
    public function getStats(): string
    {
        $stats = [
            'total' => 100,
            'active' => 80,
        ];
        
        return $this->success('获取成功', $stats);
    }
}
```

## 4. PC控制器使用示例

### 4.1 前端控制器示例

```php
<?php

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\Controller\FrontendController;

class Product extends FrontendController
{
    /**
     * 产品列表页
     * 
     * 路由：/frontend/product/list
     * 
     * @Cdn cache=10m description="产品列表页缓存10分钟"
     * @return string 视图模板
     */
    public function list(): string
    {
        $products = [
            ['id' => 1, 'name' => 'Product 1'],
        ];
        
        $this->assign('products', $products);
        return $this->fetch();
    }
    
    /**
     * 产品详情页
     * 
     * 路由：/frontend/product/detail
     * 
     * @Cdn cache=30m description="产品详情页缓存30分钟"
     * @return string 视图模板
     */
    public function detail(): string
    {
        $productId = $this->request->getParam('id');
        $product = ['id' => $productId, 'name' => 'Product Detail'];
        
        $this->assign('product', $product);
        return $this->fetch();
    }
    
    /**
     * 搜索页面（不缓存）
     * 
     * 路由：/frontend/product/search
     * 
     * @Cdn cache=false description="搜索页面不缓存"
     * @return string 视图模板
     */
    public function search(): string
    {
        $keyword = $this->request->getParam('keyword');
        $this->assign('keyword', $keyword);
        return $this->fetch();
    }
}
```

### 4.2 后端控制器示例

```php
<?php

namespace Weline\Product\Controller\Backend;

use Weline\Framework\Controller\BackendController;

class Product extends BackendController
{
    /**
     * 产品管理列表页
     * 
     * 路由：/backend/product/index
     * 
     * @Cdn cache=false description="后台管理页面不缓存"
     * @return string 视图模板
     */
    public function index(): string
    {
        $products = [
            ['id' => 1, 'name' => 'Product 1'],
        ];
        
        $this->assign('products', $products);
        return $this->fetch();
    }
    
    /**
     * 产品编辑页
     * 
     * 路由：/backend/product/edit
     * 
     * @Cdn cache=false description="编辑页面不缓存"
     * @return string 视图模板
     */
    public function edit(): string
    {
        $productId = $this->request->getParam('id');
        $product = ['id' => $productId, 'name' => 'Product'];
        
        $this->assign('product', $product);
        return $this->fetch();
    }
}
```

## 5. 参数说明

### 5.1 完整参数列表

| 参数 | 类型 | 必填 | 说明 | 示例 |
|------|------|------|------|------|
| `cache` | string/boolean | 是 | 缓存时间或false（禁用缓存） | `15m`, `1h`, `1d`, `30d`, `false` |
| `status` | string | 否 | 缓存的状态码，逗号分隔 | `200,301,308`（默认） |
| `ttl` | integer | 否 | TTL秒数，自动从cache计算 | `900`（15m=900秒） |
| `description` | string | 否 | 规则描述 | `"API数据缓存15分钟"` |
| `trigger` | string | 否 | 触发方式：`cron`（定时，默认）或`realtime`（实时） | `cron`, `realtime` |

### 5.2 缓存时间格式

支持的时间单位：
- `m` - 分钟（如：`15m` = 900秒）
- `h` - 小时（如：`1h` = 3600秒）
- `d` - 天（如：`1d` = 86400秒，`30d` = 2592000秒）

### 5.3 触发方式

- **`trigger=cron`**（默认）：定时推送，由Cron任务每15分钟统一推送
- **`trigger=realtime`**：实时推送，规则收集后立即推送

## 6. 使用场景

### 6.1 适合使用缓存的场景

- ✅ **数据查询接口**：产品列表、详情等
- ✅ **静态内容页面**：帮助页面、关于我们等
- ✅ **统计数据接口**：排行榜、统计信息等
- ✅ **配置信息接口**：系统配置、菜单配置等

### 6.2 不适合使用缓存的场景

- ❌ **写操作接口**：创建、更新、删除等
- ❌ **需要实时性的接口**：实时价格、库存等
- ❌ **用户相关接口**：用户信息、购物车等
- ❌ **后台管理页面**：管理后台的所有页面

### 6.3 推荐配置

```php
// 产品列表 - 缓存15分钟
@Cdn cache=15m description="产品列表缓存15分钟"

// 产品详情 - 缓存1小时
@Cdn cache=1h description="产品详情缓存1小时"

// 热门产品 - 缓存30分钟
@Cdn cache=30m description="热门产品缓存30分钟"

// 统计数据 - 缓存5分钟
@Cdn cache=5m description="统计数据缓存5分钟"

// 写操作 - 不缓存
@Cdn cache=false description="写操作不缓存"

// 后台管理 - 不缓存
@Cdn cache=false description="后台管理不缓存"
```

## 7. 工作流程

### 7.1 规则收集

1. **添加注释**：在方法上添加`@Cdn`注释
2. **系统升级**：运行`php bin/w setup:upgrade`
3. **自动扫描**：系统自动扫描Api和Controller目录
4. **规则解析**：解析注释并转换为标准格式
5. **存储规则**：保存到数据库

### 7.2 规则推送

#### 定时推送（默认）

1. **Cron任务**：每15分钟执行一次
2. **扫描域名**：获取所有启用的CDN域名
3. **合并规则**：合并默认规则、域名规则、API规则
4. **触发事件**：触发推送事件
5. **适配器处理**：各适配器自行转换和推送

#### 实时推送

1. **规则收集**：检测到`trigger=realtime`
2. **立即推送**：规则保存后立即触发推送事件
3. **适配器处理**：各适配器立即转换和推送

### 7.3 查看规则

1. **后台管理**：进入"CDN管理 > API规则管理"
2. **查看列表**：查看所有收集到的规则
3. **规则详情**：查看规则的表达式、动作、描述等
4. **推送状态**：查看规则的推送状态

## 8. 注意事项

### 8.1 性能要求

- ⚠️ **规则收集在系统升级阶段执行**，不会影响运行时性能
- ⚠️ **运行时只从数据库读取规则**，不进行反射操作
- ⚠️ **确保系统升级正常**，规则才能被正确收集

### 8.2 规则通用性

- ✅ **规则是通用的**，不指定适配器，适用于所有CDN服务商
- ✅ **所有适配器都会收到规则**，自行决定是否处理
- ✅ **开发者无需关心CDN服务商**，专注于业务逻辑

### 8.3 规则优先级

规则优先级（从高到低）：
1. **API注释规则** - 最精确，针对特定接口
2. **域名覆盖规则** - 域名级别的自定义规则
3. **默认规则** - 全局默认规则

### 8.4 最佳实践

1. **合理设置缓存时间**：根据数据更新频率设置
2. **写操作不缓存**：创建、更新、删除接口必须设置`cache=false`
3. **实时性要求高的不缓存**：价格、库存等实时数据不缓存
4. **使用描述**：为规则添加清晰的描述，便于维护
5. **及时更新规则**：代码变更后及时更新规则

## 9. 常见问题

### 9.1 规则没有生效？

**检查清单**：
1. ✅ 是否运行了`php bin/w setup:upgrade`？
2. ✅ 注释格式是否正确？
3. ✅ CDN域名是否已启用？
4. ✅ 规则是否已推送到CDN？
5. ✅ 查看后台"API规则管理"是否有该规则？

### 9.2 如何查看规则？

1. 进入后台：**CDN管理 > API规则管理**
2. 查看规则列表
3. 点击规则查看详情

### 9.3 如何手动推送规则？

1. 进入后台：**CDN管理 > API规则管理**
2. 选择域名
3. 点击"立即推送规则"

### 9.4 规则格式错误怎么办？

系统会记录错误日志，规则收集失败不会影响系统运行。检查：
1. 注释格式是否正确
2. 参数值是否合法
3. 查看系统日志

## 10. 相关文档

- [控制器CDN注释规则需求文档](控制器CDN注释规则需求文档.md) - 详细的技术实现文档
- [Weline_Cdn模块使用文档](README.md) - 模块整体使用说明
- [Cloudflare API Token权限配置](Cloudflare-API-Token-Permissions.md) - Cloudflare配置指南

---

**文档版本**: 1.0.0  
**最后更新**: 2024年  
**维护者**: Weline Framework Team
