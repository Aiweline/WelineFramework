# CDN注释使用指南

## 0. 先分清：`@Extra type=fpc` ≠ `@Cdn`（硬）

| 声明 | 管什么 | 权威 |
|------|--------|------|
| **`@Extra type=fpc enabled=… ttl=… namespaces=… public_path_patterns=…`** | **源站 WLS 全页缓存（FPC）** 是否可缓存、TTL、失效命名空间 | Framework `controller-extra-fpc`；`FpcExtraType` |
| **`@Cdn cache=…`** | **CDN 边缘 Cache Rules**（推 Cloudflare 等） | 本文后续章节 |

要点：

- 想让**新路由被 FPC 识别**：必须在控制器上声明 **`@Extra type=fpc`**，再经路由收集 / `setup:upgrade` 进 Extra 侧车。  
- **只写 `@Cdn`、不写 Extra** ≠ 已启用源站 FPC。  
- Cloudflare Free 下，店面与 **API** 的 GET/HEAD 进边缘仍依赖：已推送的 `default-rules`（`bypass_by_default`，**不再**整类禁 `/api/`）+ 源站出站 `Cache-Control` / `CDN-Cache-Control`（店面多为 FPC HIT）。细 TTL 注解默认经 **三面打包**进边缘（见 §0.1 / §0.2），**不是**逐条细推。  
- CDN 后台「开发模式」（按 Scope）是**临时旁路 FPC**，不改写、不删除控制器上的 Extra 声明。

FPC Extra 写法示例与失效（`w_changed`）见：

- `app/code/Weline/Framework/doc/开发/team/controller-extra-fpc/README.md`
- `app/code/Weline/Framework/doc/开发/spec/controller-extra-fpc.md`

本功能规格：`app/code/Weline/Cdn/doc/开发/spec/cdn-route-realtime-fpc-devmode.md`

---

## 0.1 双视图（硬）：库内 `getMergedRules` ≠ 边缘 `planRulesForEdgePush`

| 视图 | 权威方法 | 语义 | 后台/API 说法 |
|------|----------|------|----------------|
| **库内展开（inventory）** | `RuleManager::getMergedRules` | 启用中的 `@Cdn` 注解（**未打包**）+ 域名覆盖 + `default-rules`；便于对照代码与库内行 | 「库内合并 / inventory」——**禁止**标成「已上 Cloudflare / 已推送」 |
| **边缘真相（planned）** | `RuleManager::planRulesForEdgePush` | 实际将/已计划 PUT 到 CF Cache Rules 的集合（pack / 细推 / 或仅 defaults） | 「将推送 / 边缘计划 / planned」 |

**一句话**：后台「API 规则」列表里看到的**逐条注解**只证明已收集入库；是否写到 Cloudflare，只看推送计划 **`planRulesForEdgePush`**（及推送动作结果）。`getMergedRules` **不是**推送结果。

推送 / realtime / Cron / FPC 同步共用 `planRulesForEdgePush`；**不要**用 `getMergedRules` 当 PUT 载荷。

---

## 0.2 注解三面打包与 Free 容量（`cdn/rules/pack_annotations`，默认开）

Cloudflare Free Cache Rules 约 **10** 条；模块 `default-rules.json` 已占若干条，注解预算 = `capacity - count(defaults)`。若不打包，Free 历史上只能推 defaults，细 TTL 注解永远上不了边缘。

| 项 | 说明 |
|----|------|
| **开关** | SystemConfig `cdn/rules/pack_annotations`（bool，**默认 `1`**）；解析器 `AnnotationPackFlagResolver`；模板 `extends/.../cdn-rules-pack-annotations.phtml` |
| **开（默认）** | 无论 Free/付费，注解侧走 **打包**（付费也不再默认细推，避免双语义）→ plan ≈ `fidelity(≤1) + packs(≤3，同面可拆) + overrides(预算内) + defaults` |
| **关 + capacity≤10** | 闸门不允许注解 → plan **仅 defaults** |
| **关 + capacity>10** | 细推：未打包 api 规则 + overrides（预算截断）+ defaults |
| **闸门** | `annotationsAllowedOnEdge` = `(capacity > 10) OR packEnabled` |
| **三面** | `api` / `backend` / `frontend`；同面可缓存路径 OR；TTL=同面中位数；identity=`pack:{surface}:{ordinal}` |
| **保真车道（硬）** | BinQuery 等带 `POST` + `__wq_cache` / custom `cache_key` 的规则标 `lane=predicate_fidelity`；**禁止**进 path-OR pack。planned 压成 ≤1 条 `fidelity:binquery:0`（保留 method+marker+cache_key）。预算不够 → **安全缺席**，禁止降级为 `starts_with(/bin/query)` |
| **永不缓存边缘硬拒绝** | 可缓存 pack 不得含交易/账户/搜索等前缀（`/checkout` `/cart` `/payment` `/search` `/admin`…，见 `AnnotationRulePacker::NEVER_CACHE_EDGE_PREFIXES`）；与源站 FPC 永不共享清单对齐。`/guide/*` 公开说明页放行 |
| **生效** | 改开关后须再执行一次 CDN 规则推送才会写到 Cloudflare |

Demo / 回归：`Test/Regression/DemoTtlAnnotationEdgePush.script.php`（无凭证 SKIP；有凭证 dry-run plan 可见 `pack:`）；保真契约 `BinQueryFidelityEdgePlanContractTest`。冻结验收条目见 `dev/team/cdn-annotation-pack/align-freeze.md` §8（A1–A10）与下文 §11。

---

## 0.3 与源站 FPC 计划对齐（硬）

权威：Framework FPC「默认允许 + 显式控」与店面预热迁 Framework Cron。CDN 侧配合面：

| 对齐项 | CDN 约定 |
|--------|----------|
| **TTL 双轨** | 源站 FPC TTL 与边缘 `@Cdn`/pack **同量级**。Search：源站短 TTL（如 1h）时边缘保持 **`@Cdn cache=false`**（已声明），**禁止** pack 把 `/search` 抬到一年 |
| **永不缓存面** | 结账/支付/HelpPay/账户/Compare 等：源站 Extra `enabled=false`；边缘 **不进可缓存 pack**（Packer 硬拒绝前缀） |
| **BinQuery 保真** | `fidelity:binquery:*` 车道冻结；禁止压成 `starts_with(/bin/query)` |
| **预热** | Sitemap→源站 FPC 归 Framework Orchestrator；**禁止**用 CDN WarmupUrl / `FpcExtraDeclaredUrls` 冒充全量桥 |
| **改完再推** | FPC Extra / 注解变更后 → 重算 `planRulesForEdgePush` → 再 PUT Cloudflare |

一句话：**源站 FPC 怎么定，边缘禁缓存/TTL 就跟；保真车道别动；预热别越界。**

---

## 1. 背景介绍

### 1.1 为什么需要CDN注释规则？

在传统的CDN缓存规则管理中，开发者需要：
- 在后台手动配置缓存规则
- 维护复杂的JSON配置文件
- 规则分散在多个地方，难以管理
- 规则与代码分离，容易遗忘更新

**CDN注释规则**通过在代码中直接定义**边缘**缓存规则，解决了这些问题：
- ✅ **代码即文档**：规则与代码在一起，一目了然
- ✅ **自动收集**：系统自动扫描和收集规则
- ✅ **统一管理**：所有规则集中管理，便于查看和维护
- ✅ **按策略推送**：可定时或 realtime 推送到 CDN（Free 有条数上限；默认 **pack 开**，注解进边缘为 ≤3 面打包，**不是**逐条细推全量）

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

推送载荷一律来自 **`planRulesForEdgePush`**（边缘真相），**不是** `getMergedRules`（库内展开）。见 §0.1 / §0.2。

#### 定时推送（默认）

1. **Cron任务**：每15分钟执行一次
2. **扫描域名**：获取所有启用的CDN域名
3. **边缘计划**：按闸门 + pack 开关生成 `planRulesForEdgePush`（packs 或细推或仅 defaults）
4. **触发事件**：触发推送事件
5. **适配器处理**：各适配器自行转换和推送

#### 实时推送

1. **规则收集**：检测到`trigger=realtime`
2. **闸门**：若 `annotationsAllowedOnEdge` 为假则跳过注解侧 PUT（与定时同一闸门）
3. **立即推送**：用 `planRulesForEdgePush` 触发推送事件
4. **适配器处理**：各适配器立即转换和推送

### 7.3 查看规则

1. **后台管理**：进入"CDN管理 > API规则管理"（库内 inventory）
2. **查看列表**：查看所有**已收集入库**的注解行（≠ 已上边缘）
3. **规则详情**：查看规则的表达式、动作、描述等
4. **边缘计划**：域名规则页 / 查询接口中的 `plannedRules`（或等价）来自 `planRulesForEdgePush`；并排「库内条数 / 边缘计划条数」

## 8. 注意事项

### 8.1 性能要求

- ⚠️ **规则收集在系统升级阶段执行**，不会影响运行时性能
- ⚠️ **运行时只从数据库读取规则**，不进行反射操作
- ⚠️ **确保系统升级正常**，规则才能被正确收集

### 8.2 规则通用性

- ✅ **规则是通用的**，不指定适配器，适用于所有CDN服务商
- ✅ **所有适配器都会收到规则**，自行决定是否处理
- ✅ **开发者无需关心CDN服务商**，专注于业务逻辑

### 8.3 规则优先级（边缘计划顺序）

Cloudflare Cache Rules **先匹配先生效**。`planRulesForEdgePush` 顺序：

1. **打包 packs**（pack 开）或 **细推注解**（pack 关且闸门允许）
2. **域名覆盖规则**
3. **默认规则**（含 `/admin/` `/backend/` 旁路等）

库内 `getMergedRules` 顺序为：未打包注解 → overrides → defaults（仅 inventory，不表示推送）。

**后台路径**：对 `/admin/`、`/backend/` 写可缓存 `@Cdn` **无效且会被 Packer 硬拒绝**；请用 `cache=false`，并依赖 defaults 旁路。

### 8.4 最佳实践

1. **合理设置缓存时间**：根据数据更新频率设置（同面打包后边缘 TTL 为中位数）
2. **写操作不缓存**：创建、更新、删除接口必须设置`cache=false`
3. **实时性要求高的不缓存**：价格、库存等实时数据不缓存
4. **使用描述**：为规则添加清晰的描述，便于维护（边缘上看到的是 `Weline pack:{surface} …`，不是逐条 description）
5. **及时更新规则**：代码变更后及时更新规则；改 pack 开关后须再推送
6. **分清双视图**：调试验证用 plan / CF 控制台，不要把 API 规则列表当「已上边缘」

## 9. 常见问题

### 9.1 规则没有生效？

**检查清单**：
1. ✅ 是否运行了`php bin/w setup:upgrade`？（注解入库）
2. ✅ 注释格式是否正确？
3. ✅ CDN域名是否已启用？
4. ✅ 是否已执行推送？推送用的是 **`planRulesForEdgePush`**，不是库内列表
5. ✅ 后台"API规则管理"有该规则只说明 **inventory**；Free+pack 开时应在 plan 中见 `pack:{surface}:…`，而不是逐条 Demo description
6. ✅ `cdn/rules/pack_annotations` 是否符合预期（默认开）；关且 Free 时 plan **只有** defaults
7. ✅ 可缓存路径是否误含 `/admin/` `/backend/`（会被硬拒绝）

### 9.2 如何查看规则？

1. **库内**：后台 **CDN管理 > API规则管理**（逐条注解）
2. **边缘计划**：域名规则相关查询里的 planned / 边缘计划条数，或 dry-run / 契约测读 `planRulesForEdgePush`
3. **线上**：Cloudflare Cache Rules 控制台（以推送成功后为准）

### 9.3 如何手动推送规则？

1. 进入后台：**CDN管理 > API规则管理** 或域名规则页
2. 选择域名
3. 点击推送；确认后 CF 侧应反映 **plan**（含 pack 描述），不是 inventory 行数

### 9.4 规则格式错误怎么办？

系统会记录错误日志，规则收集失败不会影响系统运行。检查：
1. 注释格式是否正确
2. 参数值是否合法
3. 查看系统日志（含 Packer admin 硬拒绝、预算丢弃）

### 9.5 为什么库内有很多注解，边缘只有几条？

这是 **预期行为**（pack 默认开）：同面多路径压成 ≤3 条 pack（再加 overrides/defaults）。库内 `getMergedRules` 条数可以远大于边缘 `planRulesForEdgePush` 条数。

## 10. 相关文档

- [控制器CDN注释规则需求文档](控制器CDN注释规则需求文档.md) - 历史设计稿；推送入口以本文 §0.1 与现行 `planRulesForEdgePush` 为准
- [Weline_Cdn模块使用文档](README.md) - 模块整体使用说明
- [Cloudflare API Token权限配置](Cloudflare-API-Token-Permissions.md) - Cloudflare配置指南
- [开发日志](开发日志.md) - `1.0.46` 注解三面打包记事
- 对齐冻结：`dev/team/cdn-annotation-pack/align-freeze.md`

## 11. 验收条目对照（align-freeze §8）

| ID | 断言 | 文档落点 |
|----|------|----------|
| A1 | 默认 pack 开（SystemConfig default=`1` / Resolver 默认 true） | §0.2 |
| A2 | 同面多路径 OR + 中位 TTL；identity `pack:{surface}:{ordinal}` | §0.2 |
| A3 | Free + pack 开 → 闸门 true；plan 含 ≤3 pack，无逐条 Demo description | §0.2、§9.1 |
| A4 | pack 关 + Free → plan 仅 defaults | §0.2 |
| A5 | pack 关 + capacity>10 → 细推 | §0.2 |
| A6 | 可缓存注解不得把 `/admin/` `/backend/` 写进 pack expression | §0.2、§8.3 |
| A7 | Packer 读 `ApiRule` class/route，不靠 `toCloudflareRule` 猜面 | §0.2（实现约束）；契约测 |
| A8 | 指南含「边缘真相=plan」「库内 merged ≠ 边缘 plan」 | **§0.1（本条）** |
| A9 | Demo 脚本：无凭证 SKIP；有凭证 dry-run 可见 pack | §0.2 |
| A10 | 受管对象稳定后一次 `aoci_maintain`（非整仓） | 收尾席 / 开发日志 |

契约与回归由测试席执行；本文锁定产品语义与双视图命名，不替代测试绿灯。

---

**文档版本**: 1.0.46  
**最后更新**: 2026-10-12  
**维护者**: Weline Framework Team · Team:文档工程师:
