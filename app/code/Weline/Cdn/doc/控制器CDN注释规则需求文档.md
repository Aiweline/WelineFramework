# 控制器CDN注释规则需求文档

## 1. 需求概述

### 1.1 背景

当前Weline_Cdn模块支持通过配置文件（`default-rules.json`）和后台界面管理CDN缓存规则。为了提升开发效率和代码可维护性，需要支持开发者在Api和Controller类的方法注释中直接定义CDN缓存规则，系统自动收集、存储、展示并推送到CDN服务商。

**规则格式完全对标Cloudflare Cache Rules**，确保规则格式的标准化和可扩展性。

### 1.2 核心需求

1. **注释定义规则**：开发者可以在Api和Controller方法注释中使用`@Cdn`标签定义缓存规则
2. **规则通用性**：注释规则是通用的，不指定适配器，适用于所有CDN供应商
3. **自动收集**：系统在路由注册阶段自动扫描和收集所有`@Cdn`注释规则
4. **规则存储**：收集到的规则以**Cloudflare Cache Rules标准格式**存储到数据库（作为统一标准格式）
5. **后台展示**：在后台管理界面展示所有收集到的规则（对标Cloudflare规则设置界面）
6. **事件驱动推送**：通过事件机制触发规则推送，所有已激活的CDN适配器都会监听事件并自行处理规则转换和推送
7. **规则合并**：API注释规则与默认规则、域名覆盖规则合并后触发推送事件
8. **职责分离**：CDN模块只负责规则收集、存储、展示，不关心具体推送逻辑；各适配器负责规则转换和推送

### 1.3 关键约束

**⚠️ 性能要求（重要）**

- **规则收集必须在系统更新路由阶段执行**，绝对不能在请求生命周期内进行反射和解析
- 所有注释解析、反射操作必须在`setup:upgrade`命令执行时完成
- 运行时只从数据库读取已收集的规则，不进行任何反射操作
- 确保系统性能和响应速度不受影响

## 2. 功能设计

### 2.1 @Cdn注释格式

注释格式设计为简化版本，系统会自动转换为Cloudflare Cache Rules标准格式。

#### 2.1.1 简单格式

```php
/**
 * 获取产品列表
 * 
 * @Cdn cache=15m
 * @return array
 */
public function getList(): array
{
    // ...
}
```

**转换后的Cloudflare规则格式**：
```json
{
    "expression": "http.request.uri.path matches \"^/api/rest/v1/product/get-list\"",
    "action": {
        "cache": {
            "status_code": [200, 301, 308],
            "ttl": 900
        }
    },
    "description": "获取产品列表",
    "enabled": true
}
```

#### 2.1.2 详细格式

```php
/**
 * 获取产品详情
 * 
 * @Cdn cache=1h status=200,301,404 description="产品详情缓存1小时"
 * @return array
 */
public function getDetail(): array
{
    // ...
}
```

#### 2.1.3 实时触发格式

```php
/**
 * 获取实时数据（需要立即推送规则）
 * 
 * @Cdn cache=15m trigger=realtime description="实时数据缓存15分钟，立即推送"
 * @return array
 */
public function getRealtimeData(): array
{
    // ...
}
```

**说明**：
- `trigger=realtime`：规则收集后立即触发推送事件
- `trigger=cron` 或不指定：由Cron定时任务统一推送（默认）

**转换后的Cloudflare规则格式**：
```json
{
    "expression": "http.request.uri.path matches \"^/api/rest/v1/product/get-detail\"",
    "action": {
        "cache": {
            "status_code": [200, 301, 404],
            "ttl": 3600
        }
    },
    "description": "产品详情缓存1小时",
    "enabled": true
}
```

#### 2.1.4 禁用缓存

```php
/**
 * 创建产品（不缓存）
 * 
 * @Cdn cache=false
 * @return array
 */
public function create(): array
{
    // ...
}
```

**转换后的Cloudflare规则格式**：
```json
{
    "expression": "http.request.uri.path matches \"^/api/rest/v1/product/create\"",
    "action": {
        "cache": false
    },
    "description": "创建产品（不缓存）",
    "enabled": true
}
```

#### 2.1.5 完整Cloudflare表达式（高级用法）

如果需要使用Cloudflare的完整表达式语法，可以直接在注释中定义：

```php
/**
 * 复杂规则示例
 * 
 * @Cdn expression="(http.request.uri.path matches \"^/api/\" and http.request.method eq \"GET\")" action='{"cache":{"ttl":900}}' trigger=realtime
 * @return array
 */
public function complexRule(): array
{
    // ...
}
```

**注意**：使用完整表达式时，系统会直接使用，不再进行转换。

### 2.2 参数说明

| 参数 | 类型 | 必填 | 说明 | 示例 |
|------|------|------|------|------|
| `cache` | string/boolean | 是 | 缓存时间或false（禁用缓存） | `15m`, `1h`, `1d`, `30d`, `false` |
| `status` | string | 否 | 缓存的状态码，逗号分隔 | `200,301,308`（默认） |
| `ttl` | integer | 否 | TTL秒数，自动从cache计算 | `900`（15m=900秒） |
| `description` | string | 否 | 规则描述 | `"API数据缓存15分钟"` |
| `trigger` | string | 否 | 触发方式：`cron`（定时，默认）或`realtime`（实时） | `cron`, `realtime` |

### 2.3 缓存时间格式

支持的时间单位：
- `m` - 分钟（如：`15m` = 900秒）
- `h` - 小时（如：`1h` = 3600秒）
- `d` - 天（如：`1d` = 86400秒，`30d` = 2592000秒）

## 3. 架构设计

### 3.1 数据流

#### 3.1.1 规则收集流程

```
开发者添加@Cdn注释
    ↓
setup:upgrade 路由注册阶段
    ↓
CdnRuleCollector 扫描Api/Controller目录
    ↓
解析@Cdn标签并转换为Cloudflare标准格式
    ↓
判断触发方式（trigger参数）
    ↓
存储到 cdn_api_rule 数据库表（Cloudflare格式）
    ↓
如果 trigger=realtime，立即触发推送事件
    ↓
后台管理界面展示规则列表（对标Cloudflare界面）
```

#### 3.1.2 定时推送流程

```
Cron定时任务执行（每15分钟）
    ↓
扫描需要推送的规则（trigger=cron 或未指定）
    ↓
按域名分组合并规则
    ↓
CDN模块触发事件：Weline_Cdn::push_rules
    ↓
各CDN适配器监听事件
    ↓
适配器自行转换规则格式（如Cloudflare直接使用，其他适配器转换）
    ↓
适配器推送到各自的CDN平台
```

#### 3.1.3 实时推送流程

```
规则收集时检测到 trigger=realtime
    ↓
立即获取所有启用的CDN域名（不区分适配器）
    ↓
为每个域名合并规则（默认+域名+该实时规则）
    ↓
CDN模块触发事件：Weline_Cdn::push_rules（所有适配器都会收到）
    ↓
所有已激活的CDN适配器监听事件
    ↓
各适配器检查域名是否属于自己，属于则转换并推送
```

### 3.2 职责分离

#### 3.2.1 CDN模块职责（Weline_Cdn）

- ✅ **规则收集**：扫描Api/Controller目录，解析@Cdn注释（通用规则，不指定适配器）
- ✅ **规则转换**：将注释转换为Cloudflare Cache Rules标准格式（作为统一标准）
- ✅ **规则存储**：以Cloudflare格式存储到数据库
- ✅ **规则展示**：后台界面展示规则（对标Cloudflare规则设置界面）
- ✅ **事件触发**：触发`Weline_Cdn::push_rules`事件，所有适配器都会收到
- ❌ **不负责**：适配器选择、具体推送逻辑、规则格式转换（适配器自行处理）

#### 3.2.2 CDN适配器职责（各适配器）

- ✅ **事件监听**：监听`Weline_Cdn::push_rules`事件（所有适配器都监听）
- ✅ **域名过滤**：检查事件中的域名是否使用自己的适配器
- ✅ **规则转换**：将Cloudflare格式转换为自己的格式（如需要）
- ✅ **规则推送**：调用自己的API推送规则
- ✅ **错误处理**：处理推送失败的情况，不影响其他适配器

### 3.2 核心组件

1. **CdnRuleCollector** - 规则收集器服务类
   - 扫描所有模块的`Api/`和`Controller/`目录
   - 解析方法注释中的`@Cdn`标签
   - 生成路由路径和Cloudflare标准格式的规则表达式
   - 转换为Cloudflare Cache Rules格式
   - 存储到数据库（Cloudflare格式）

2. **ApiRule** - API规则数据模型
   - 存储收集到的规则信息（Cloudflare格式）
   - 字段结构对标Cloudflare Cache Rules API
   - 支持按模块、类、方法查询

3. **RuleManager** - 规则管理器（扩展）
   - 合并默认规则、域名规则和API规则
   - 触发`Weline_Cdn::push_rules`事件
   - 不负责具体推送逻辑

4. **事件系统** - 规则推送事件
   - 事件名称：`Weline_Cdn::push_rules`
   - 事件数据：`['domain' => Domain, 'rules' => array]`
   - 各适配器通过Observer监听并处理

### 3.3 执行时机

**关键：规则收集必须在系统升级阶段执行**

#### 3.3.1 集成到路由注册流程

在`Weline\Framework\Module\Helper\Data::registerModuleRouter()`方法中，当扫描Api和Controller类时，同时收集CDN规则：

```php
// 在路由注册时同时收集CDN规则
foreach ($api_classs as $api_class) {
    // ... 路由注册逻辑 ...
    
    // 收集CDN规则（新增）
    if (class_exists($api_class, false)) {
        $cdnRuleCollector->collectClass($api_class, $module);
    }
}
```

#### 3.3.2 通过事件监听

创建Observer监听`Weline_Framework_Setup::upgrade_after`事件，在系统升级完成后收集规则：

```php
// etc/event.xml
<event name="Weline_Framework_Setup::upgrade_after">
    <observer name="cdn_collect_rules" 
              instance="Weline\Cdn\Observer\CollectRules" 
              disabled="false" 
              sort="100"/>
</event>
```

#### 3.3.3 命令行工具（可选）

提供独立的命令行工具用于手动收集：

```bash
php bin/w cdn:collect-rules
php bin/w cdn:collect-rules --module Weline_Product
```

## 4. 数据库设计

### 4.1 表结构：cdn_api_rule

表结构完全对标Cloudflare Cache Rules API格式：

| 字段 | 类型 | 说明 | 对应Cloudflare字段 |
|------|------|------|-------------------|
| `rule_id` | INT PRIMARY KEY | 规则ID | - |
| `module` | VARCHAR(100) | 模块名称 | - |
| `class` | VARCHAR(500) | 完整类名 | - |
| `method` | VARCHAR(100) | 方法名 | - |
| `route` | VARCHAR(500) | 路由路径（自动生成） | - |
| `expression` | VARCHAR(1000) | 规则表达式（Cloudflare格式） | `expression` |
| `action` | TEXT | 动作配置JSON（Cloudflare格式） | `action` |
| `description` | VARCHAR(500) | 规则描述 | `description` |
| `enabled` | TINYINT(1) | 是否启用 | `enabled` |
| `trigger` | VARCHAR(20) | 触发方式：`cron`（定时）或`realtime`（实时） | - |
| `priority` | INT | 优先级（可选） | - |
| `created_at` | DATETIME | 创建时间 | - |
| `updated_at` | DATETIME | 更新时间 | - |

**action字段存储格式**（JSON）：
```json
{
    "cache": {
        "status_code": [200, 301, 308],
        "ttl": 900
    }
}
```
或
```json
{
    "cache": false
}
```

### 4.2 索引设计

- `idx_module_class_method` - (module, class, method) 唯一索引
- `idx_route` - (route) 普通索引，用于快速查找路由规则

## 5. 路由规则生成

### 5.1 路由生成规则

基于Weline Framework的路由规则：

- `Api/Rest/V1/Product.php::getList()` → `/api/rest/v1/product/get-list`
- `Controller/Backend/User/Index.php::save()` → `/backend/user/index/save`
- `Controller/Frontend/Product/Detail.php::index()` → `/frontend/product/detail`

### 5.2 规则表达式生成

根据路由生成Cloudflare表达式：

```php
// 路由: /api/rest/v1/product/get-list
// 表达式: http.request.uri.path matches "^/api/rest/v1/product/get-list"
```

### 5.3 缓存时间转换

```php
'15m' => 900秒
'1h'  => 3600秒
'1d'  => 86400秒
'30d' => 2592000秒
```

## 6. Cloudflare Cache Rules格式（标准格式）

**完全对标Cloudflare官方API文档**：https://developers.cloudflare.com/rules/cache-rules/

### 6.1 标准缓存规则格式

```json
{
    "expression": "http.request.uri.path matches \"^/api/rest/v1/product/get-list\"",
    "action": {
        "cache": {
            "status_code": [200, 301, 308],
            "ttl": 900
        }
    },
    "description": "API数据缓存15分钟",
    "enabled": true
}
```

### 6.2 禁用缓存格式

```json
{
    "expression": "http.request.uri.path matches \"^/api/rest/v1/product/create\"",
    "action": {
        "cache": false
    },
    "description": "创建产品接口不缓存",
    "enabled": true
}
```

### 6.3 Cloudflare表达式语法

支持Cloudflare完整的表达式语法，包括：

- **路径匹配**：`http.request.uri.path matches "^/api/"`
- **方法匹配**：`http.request.method eq "GET"`
- **组合条件**：`(http.request.uri.path matches "^/api/" and http.request.method eq "GET")`
- **更多字段**：参考[Cloudflare表达式文档](https://developers.cloudflare.com/rules/transform/expressions/)

### 6.4 Action配置选项

根据Cloudflare API，action支持以下配置：

```json
{
    "cache": {
        "status_code": [200, 301, 308],  // 缓存的状态码
        "ttl": 900,                        // TTL秒数
        "cache_key": {                     // 缓存键配置（可选）
            "include_query_string": {
                "all": true
            }
        }
    }
}
```

或直接禁用：
```json
{
    "cache": false
}
```

## 7. 实现方案

### 7.1 文件清单

#### 7.1.1 新增文件

1. `app/code/Weline/Cdn/Model/ApiRule.php` - API规则模型
2. `app/code/Weline/Cdn/Service/CdnRuleCollector.php` - 规则收集器
3. `app/code/Weline/Cdn/Controller/Backend/ApiRules.php` - 后台控制器
4. `app/code/Weline/Cdn/view/backend/api-rules/index.phtml` - 列表视图
5. `app/code/Weline/Cdn/Cron/PushRules.php` - 规则推送定时任务（**重要**）
6. `app/code/Weline/Cdn/Observer/PushRules.php` - Cloudflare适配器的推送观察者
7. `app/code/Weline/Cdn/Console/Command/CollectRules.php` - 命令行工具（可选，用于手动收集）

#### 7.1.2 修改文件

1. `app/code/Weline/Cdn/Service/RuleManager.php` - 集成API规则，触发推送事件
2. `app/code/Weline/Cdn/etc/backend/menu.xml` - 添加菜单项
3. `app/code/Weline/Cdn/etc/event.xml` - 添加推送事件定义

#### 7.1.3 适配器实现（各适配器自行实现）

1. `app/code/Weline/Cdn/Observer/PushRules.php` - Cloudflare适配器的推送观察者
2. 其他适配器在自己的模块中实现类似的Observer

### 7.2 核心实现逻辑

#### 7.2.1 CdnRuleCollector::collectAll()

```php
public function collectAll(): array
{
    $modules = Env::getInstance()->getModuleList();
    $collected = [];
    
    foreach ($modules as $moduleName => $module) {
        if (!($module['status'] ?? false)) {
            continue;
        }
        
        $collected = array_merge(
            $collected, 
            $this->collectModule($moduleName, $module)
        );
    }
    
    return $collected;
}
```

#### 7.2.2 CdnRuleCollector::parseCdnTag()

```php
private function parseCdnTag(string $docComment, string $route): ?array
{
    // 匹配 @Cdn 标签
    if (!preg_match('/@Cdn\s+(.+)/', $docComment, $matches)) {
        return null;
    }
    
    $config = $this->parseCdnConfig($matches[1]);
    
    // 转换为Cloudflare标准格式
    $rule = $this->convertToCloudflareFormat($config, $route);
    
    // 判断触发方式（默认cron）
    $trigger = $config['trigger'] ?? 'cron';
    $rule['trigger'] = $trigger;
    
    return $rule;
}

/**
 * 转换为Cloudflare Cache Rules格式
 */
private function convertToCloudflareFormat(array $config, string $route): array
{
    $rule = [
        'expression' => 'http.request.uri.path matches "^' . $route . '"',
        'action' => $this->buildCloudflareAction($config),
        'description' => $config['description'] ?? '',
        'enabled' => true,
        'trigger' => $config['trigger'] ?? 'cron' // 默认定时触发
    ];
    
    return $rule;
}

/**
 * 解析@Cdn配置参数
 */
private function parseCdnConfig(string $configString): array
{
    $config = [];
    
    // 解析参数：cache=15m status=200,301 trigger=realtime description="xxx"
    if (preg_match('/cache=([^\s]+)/', $configString, $matches)) {
        $config['cache'] = $matches[1];
    }
    
    if (preg_match('/status=([^\s]+)/', $configString, $matches)) {
        $config['status'] = explode(',', $matches[1]);
    }
    
    if (preg_match('/trigger=([^\s]+)/', $configString, $matches)) {
        $config['trigger'] = $matches[1]; // cron 或 realtime
    }
    
    if (preg_match('/description="([^"]+)"/', $configString, $matches)) {
        $config['description'] = $matches[1];
    }
    
    return $config;
}
```

#### 7.2.3 CdnRuleCollector::collectClass() - 实时推送处理

```php
public function collectClass(string $className, array $module): array
{
    $rules = [];
    $reflection = new \ReflectionClass($className);
    
    foreach ($reflection->getMethods() as $method) {
        $docComment = $method->getDocComment();
        if (!$docComment) {
            continue;
        }
        
        $rule = $this->parseCdnTag($docComment, $this->generateRoute($reflection, $method));
        if (!$rule) {
            continue;
        }
        
        // 保存规则到数据库（通用规则，不指定适配器）
        $apiRule = $this->saveRule($rule, $className, $method->getName(), $module);
        
        // 如果是实时触发，立即推送到所有适配器
        if (($rule['trigger'] ?? 'cron') === 'realtime') {
            $this->pushRealtimeRule($apiRule);
        }
        
        $rules[] = $rule;
    }
    
    return $rules;
}

/**
 * 推送实时规则（推送到所有适配器）
 */
private function pushRealtimeRule(ApiRule $apiRule): void
{
    // 获取所有启用的域名（不区分适配器）
    $domains = $this->domainModel->clear()
        ->where(Domain::fields_ENABLED, 1)
        ->select()
        ->fetch();
    
    foreach ($domains as $domain) {
        // 合并规则（包含这个实时规则）
        $rules = $this->ruleManager->getMergedRules($domain);
        
        // 触发推送事件（所有适配器都会收到）
        $event = new Event([
            'domain' => $domain,
            'rules' => $rules, // 通用规则，所有适配器都可以使用
            'adapter_code' => $domain->getData(Domain::fields_ADAPTER), // 用于适配器过滤
            'trigger_type' => 'realtime' // 标记为实时触发
        ]);
        
        $this->eventsManager->dispatch('Weline_Cdn::push_rules', $event);
    }
}
```

#### 7.2.3 Cron/PushRules.php - 定时推送任务

```php
namespace Weline\Cdn\Cron;

use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\RuleManager;
use Weline\Cdn\Model\ApiRule;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Cron\CronTaskInterface;

/**
 * CDN规则推送定时任务
 * 
 * 定时扫描需要推送的规则，触发推送事件
 * 
 * @package Weline_Cdn
 */
class PushRules implements CronTaskInterface
{
    private RuleManager $ruleManager;
    private EventsManager $eventsManager;
    private Domain $domainModel;
    private ApiRule $apiRuleModel;

    public function __construct(
        RuleManager $ruleManager,
        EventsManager $eventsManager,
        Domain $domainModel,
        ApiRule $apiRuleModel
    ) {
        $this->ruleManager = $ruleManager;
        $this->eventsManager = $eventsManager;
        $this->domainModel = $domainModel;
        $this->apiRuleModel = $apiRuleModel;
    }

    /**
     * @inheritDoc
     */
    public function name(): string
    {
        return 'CDN规则推送任务';
    }

    /**
     * @inheritDoc
     */
    public function execute_name(): string
    {
        return 'cdn_push_rules';
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '定时推送CDN缓存规则到各CDN服务商，每15分钟执行一次';
    }

    /**
     * @inheritDoc
     */
    public function cron_time(): string
    {
        return '*/15 * * * *'; // 每15分钟执行一次
    }

    /**
     * @inheritDoc
     */
    public function execute(): string
    {
        // 1. 获取所有启用的域名
        $domains = $this->domainModel->clear()
            ->where(Domain::fields_ENABLED, 1)
            ->select()
            ->fetch();

        $pushedCount = 0;
        $failedCount = 0;

        foreach ($domains as $domain) {
            try {
                // 2. 获取合并后的规则（只包含定时触发的规则）
                // trigger=cron 或未指定的规则
                $rules = $this->ruleManager->getMergedRules($domain, 'cron');
                
                if (empty($rules)) {
                    continue;
                }

                // 3. 触发推送事件（所有适配器都会收到）
                $event = new Event([
                    'domain' => $domain,
                    'rules' => $rules, // 通用规则，所有适配器都可以使用
                    'adapter_code' => $domain->getData(Domain::fields_ADAPTER), // 用于适配器过滤
                    'trigger_type' => 'cron' // 标记为定时触发
                ]);
                
                $this->eventsManager->dispatch('Weline_Cdn::push_rules', $event);
                
                $pushedCount++;
                
            } catch (\Exception $e) {
                $failedCount++;
                // 记录错误日志
                error_log("CDN规则推送失败 [域名: {$domain->getData(Domain::fields_DOMAIN_NAME)}]: " . $e->getMessage());
            }
        }

        return sprintf(
            "CDN规则推送完成: 成功 %d 个域名, 失败 %d 个域名",
            $pushedCount,
            $failedCount
        );
    }

    /**
     * @inheritDoc
     */
    public function unlock_timeout(): int
    {
        return 30; // 30分钟超时解锁
    }
}
```

#### 7.2.4 RuleManager::getMergedRules() - 合并规则（包含API规则）

```php
/**
 * 获取合并后的规则（通用规则，适用于所有适配器）
 * 
 * @param Domain $domain 域名对象
 * @param string $triggerType 触发类型：'cron'（定时）或'realtime'（实时），null表示全部
 * @return array Cloudflare格式的规则数组（作为统一标准）
 */
public function getMergedRules(Domain $domain, ?string $triggerType = null): array
{
    // 1. 获取默认规则（Cloudflare格式，通用）
    $defaultRules = $this->getDefaultRules();
    
    // 2. 获取域名覆盖规则（Cloudflare格式，通用）
    $overrideRules = $domain->getRulesOverrideArray();
    
    // 3. 获取API注释规则（Cloudflare格式，通用，不指定适配器）
    $apiRules = $this->getApiRules($triggerType);
    
    // 4. 合并规则（优先级：API规则 > 域名规则 > 默认规则）
    // 这些规则是通用的，所有适配器都可以使用
    $mergedRules = array_merge(
        $defaultRules,
        $overrideRules,
        $apiRules
    );
    
    return $mergedRules;
}

/**
 * 获取API注释规则
 * 
 * @param string|null $triggerType 触发类型过滤：'cron'、'realtime' 或 null（全部）
 * @return array
 */
private function getApiRules(?string $triggerType = null): array
{
    $query = $this->apiRuleModel->clear()
        ->where(ApiRule::fields_ENABLED, 1);
    
    // 如果指定了触发类型，只获取对应类型的规则
    if ($triggerType !== null) {
        $query->where(ApiRule::fields_TRIGGER, $triggerType);
    }
    
    $apiRules = $query->select()->fetch();
    
    $rules = [];
    foreach ($apiRules as $apiRule) {
        $rules[] = [
            'expression' => $apiRule->getData(ApiRule::fields_EXPRESSION),
            'action' => json_decode($apiRule->getData(ApiRule::fields_ACTION), true),
            'description' => $apiRule->getData(ApiRule::fields_DESCRIPTION),
            'enabled' => (bool)$apiRule->getData(ApiRule::fields_ENABLED),
            'trigger' => $apiRule->getData(ApiRule::fields_TRIGGER) ?? 'cron'
        ];
    }
    
    return $rules;
}
```

#### 7.2.4 Cloudflare适配器监听事件

```php
// app/code/Weline/Cdn/Observer/PushRules.php
class PushRules implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $domain = $event->getData('domain');
        $rules = $event->getData('rules');
        $adapterCode = $event->getData('adapter_code');
        
        // 只处理Cloudflare适配器
        if ($adapterCode !== 'cloudflare') {
            return;
        }
        
        // 规则已经是Cloudflare格式，直接推送
        $adapter = ObjectManager::getInstance(Cloudflare::class);
        $credentials = $this->getCredentials($domain);
        $zoneId = $domain->getData(Domain::fields_ZONE_ID);
        
        $result = $adapter->putRules($zoneId, $rules, $credentials);
        
        // 记录推送结果
        if (!$result['success']) {
            // 记录错误日志
        }
    }
}
```

## 8. 使用示例

### 8.1 开发者使用

```php
namespace Weline\Product\Api\Rest\V1;

use Weline\Framework\Controller\AbstractRestController;

class Product extends AbstractRestController
{
    /**
     * 获取产品列表（定时推送，默认）
     * 
     * @Cdn cache=15m description="产品列表API缓存15分钟"
     * @return array
     */
    public function getList(): array
    {
        // 业务逻辑
        return [];
    }
    
    /**
     * 获取产品详情（定时推送）
     * 
     * @Cdn cache=1h status=200,301,404 description="产品详情缓存1小时" trigger=cron
     * @return array
     */
    public function getDetail(): array
    {
        // 业务逻辑
        return [];
    }
    
    /**
     * 获取实时数据（立即推送）
     * 
     * @Cdn cache=15m trigger=realtime description="实时数据缓存15分钟，立即推送"
     * @return array
     */
    public function getRealtimeData(): array
    {
        // 业务逻辑
        return [];
    }
    
    /**
     * 创建产品（不缓存，实时推送）
     * 
     * @Cdn cache=false trigger=realtime description="创建产品接口不缓存，立即推送"
     * @return array
     */
    public function create(): array
    {
        // 业务逻辑
        return [];
    }
}
```

**说明**：
- 不指定`trigger`参数：默认`cron`（定时推送，每15分钟）
- `trigger=cron`：定时推送，由Cron任务统一处理
- `trigger=realtime`：实时推送，规则收集后立即推送

### 8.2 后台管理

1. 进入"CDN管理 > API规则管理"
2. 查看收集到的规则列表（按模块、类、方法分组）
3. 点击"重新收集"更新规则（触发setup:upgrade）
4. 选择域名，点击"推送规则"将规则推送到CDN
5. 查看规则详情和表达式

### 8.3 规则合并逻辑

规则优先级（从高到低）：
1. **API注释规则** - 最精确，针对特定接口（Cloudflare格式）
2. **域名覆盖规则** - 域名级别的自定义规则（Cloudflare格式）
3. **默认规则** - 全局默认规则（default-rules.json，Cloudflare格式）

合并策略：
- 所有规则统一为Cloudflare格式存储
- API规则与默认规则合并时，API规则优先
- 相同路由的规则，API规则覆盖默认规则
- 合并后的规则数组触发推送事件
- 各适配器接收Cloudflare格式的规则，自行转换（如需要）

### 8.4 Cron定时推送流程

1. **Cron定时执行**：每15分钟执行一次`Cron/PushRules`任务
2. **扫描域名**：获取所有启用的CDN域名
3. **合并规则**：为每个域名合并默认规则、域名规则、**定时触发的API规则**（trigger=cron）
4. **触发事件**：对每个域名触发`Weline_Cdn::push_rules`事件
5. **事件数据**：
   ```php
   [
       'domain' => Domain对象,
       'rules' => [/* Cloudflare格式的规则数组（只包含定时规则） */],
       'adapter_code' => 'cloudflare',
       'trigger_type' => 'cron'
   ]
   ```
6. **适配器监听**：各适配器的Observer监听事件
7. **规则过滤**：适配器检查`adapter_code`，只处理自己的规则
8. **规则转换**：适配器将Cloudflare格式转换为自己的格式（如需要）
9. **规则推送**：适配器调用自己的API推送规则
10. **结果反馈**：适配器记录推送结果（成功/失败），Cron任务记录统计信息

### 8.5 实时推送流程

1. **规则收集**：在`setup:upgrade`阶段收集规则时，检测到`trigger=realtime`
2. **立即处理**：规则保存到数据库后，立即触发推送流程
3. **获取域名**：获取所有启用的CDN域名
4. **合并规则**：为每个域名合并默认规则、域名规则、**该实时规则**
5. **触发事件**：对每个域名触发`Weline_Cdn::push_rules`事件
6. **事件数据**：
   ```php
   [
       'domain' => Domain对象,
       'rules' => [/* Cloudflare格式的规则数组（包含实时规则） */],
       'adapter_code' => 'cloudflare',
       'trigger_type' => 'realtime'
   ]
   ```
7. **适配器监听**：各适配器的Observer监听事件并立即推送
8. **结果反馈**：适配器记录推送结果，实时推送失败不影响定时推送

### 8.6 手动推送（可选）

除了Cron定时推送和实时推送，也可以支持手动推送：

1. **后台操作**：在后台点击"立即推送规则"
2. **触发推送**：直接调用`RuleManager::pushRules()`方法，不指定trigger_type（推送所有规则）
3. **后续流程**：与Cron推送流程相同（步骤6-10）

## 9. 技术细节

### 9.1 性能优化

1. **批量收集**：在路由注册阶段批量收集，避免多次扫描
2. **增量更新**：只更新变更的规则，不重复处理未变更的规则
3. **缓存机制**：收集结果缓存到数据库，运行时直接读取
4. **事件异步**：规则推送通过事件机制，各适配器可以异步处理

### 9.2 事件系统设计

#### 9.2.1 事件定义

**事件名称**：`Weline_Cdn::push_rules`

**触发时机**：
- Cron定时任务触发（每15分钟）
- 手动推送触发（后台操作）

**事件数据**：
```php
[
    'domain' => Domain对象,           // CDN域名对象
    'rules' => array,                 // Cloudflare格式的规则数组（通用规则，所有适配器可用）
    'adapter_code' => string,         // 适配器代码（用于适配器过滤，判断是否处理该域名）
    'trigger_type' => string          // 触发类型：'cron'（定时）或'realtime'（实时）
]
```

**重要说明**：
- `rules`是通用规则，不指定适配器，所有适配器都会收到
- 各适配器根据`adapter_code`判断是否处理该域名
- 适配器自行将Cloudflare格式转换为自己的格式（如需要）

**Cron任务配置**：
- 执行频率：`*/15 * * * *`（每15分钟）
- 任务类：`Weline\Cdn\Cron\PushRules`
- 超时时间：30分钟

#### 9.2.2 适配器Observer实现示例

```php
// app/code/Weline/Cdn/Observer/PushRules.php
namespace Weline\Cdn\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Cdn\Adapter\Cloudflare;
use Weline\Framework\Manager\ObjectManager;

class PushRules implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $domain = $event->getData('domain');
        $rules = $event->getData('rules'); // 通用规则，Cloudflare格式
        $adapterCode = $event->getData('adapter_code');
        
        // 只处理使用Cloudflare适配器的域名
        if ($adapterCode !== 'cloudflare') {
            return; // 其他适配器的域名，不处理
        }
        
        // 规则已经是Cloudflare格式，直接使用（无需转换）
        $adapter = ObjectManager::getInstance(Cloudflare::class);
        $credentials = $this->getCredentials($domain);
        $zoneId = $domain->getData(Domain::fields_ZONE_ID);
        
        // 调用适配器的putRules方法推送
        $result = $adapter->putRules($zoneId, $rules, $credentials);
        
        // 记录结果
        if ($result['success']) {
            // 记录成功日志
        } else {
            // 记录错误日志
        }
    }
}
```

**说明**：
- 所有适配器都会收到事件，但只处理自己适配器的域名
- Cloudflare适配器可以直接使用规则（已经是Cloudflare格式）
- 其他适配器需要将Cloudflare格式转换为自己的格式

#### 9.2.3 其他适配器实现

其他CDN适配器可以在自己的模块中实现类似的Observer：

```php
// app/code/Vendor/OtherCdn/Observer/PushRules.php
namespace Vendor\OtherCdn\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Vendor\OtherCdn\Adapter\OtherCdnAdapter;

class PushRules implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $domain = $event->getData('domain');
        $adapterCode = $event->getData('adapter_code');
        
        // 只处理使用自己适配器的域名
        if ($adapterCode !== 'othercdn') {
            return; // 其他适配器的域名，不处理
        }
        
        $rules = $event->getData('rules'); // 通用规则，Cloudflare格式
        
        // 转换为自己的格式（因为规则是Cloudflare格式，需要转换）
        $convertedRules = $this->convertFromCloudflare($rules);
        
        // 推送到自己的CDN平台
        $this->pushToOtherCdn($domain, $convertedRules);
    }
    
    /**
     * 将Cloudflare格式转换为自己的格式
     */
    private function convertFromCloudflare(array $cloudflareRules): array
    {
        $convertedRules = [];
        
        foreach ($cloudflareRules as $rule) {
            // 转换expression
            $expression = $this->convertExpression($rule['expression']);
            
            // 转换action
            $action = $this->convertAction($rule['action']);
            
            $convertedRules[] = [
                'expression' => $expression,
                'action' => $action,
                'description' => $rule['description'] ?? ''
            ];
        }
        
        return $convertedRules;
    }
    
    // ... 转换方法实现
}
```

**说明**：
- 所有适配器都会收到事件，但只处理自己适配器的域名
- 非Cloudflare适配器需要将Cloudflare格式转换为自己的格式
- 注释规则是通用的，不指定适配器，所有适配器都可以使用

### 9.2 错误处理

1. **注释解析错误**：记录警告日志，跳过该规则，不影响其他规则收集
2. **路由生成失败**：使用类名+方法名作为备选路由
3. **规则推送失败**：记录错误信息，支持重试

### 9.3 扩展性

1. **适配器扩展**：各适配器通过Observer监听推送事件，自行实现规则转换和推送
2. **规则格式统一**：所有规则以Cloudflare格式存储，适配器自行转换
3. **收集器扩展**：可以扩展收集器支持其他类型的规则定义
4. **事件扩展**：可以添加更多事件（如规则更新、规则删除等）

## 10. 测试验证

### 10.1 单元测试

- `CdnRuleCollectorTest` - 测试规则收集逻辑
- `RuleConverterTest` - 测试规则转换逻辑
- `ApiRuleTest` - 测试数据模型

### 10.2 集成测试

- 测试路由注册时规则收集
- 测试规则推送到Cloudflare
- 测试规则合并逻辑

### 10.3 性能测试

- 验证规则收集不影响系统升级速度
- 验证运行时无反射操作
- 验证规则查询性能

## 11. 注意事项

1. **必须遵守性能约束**：规则收集必须在系统升级阶段执行，不能在请求生命周期内进行
2. **路由依赖**：规则收集依赖路由注册，确保路由注册正常
3. **注释规范**：开发者必须按照规范格式编写`@Cdn`注释
4. **规则格式**：所有规则以Cloudflare Cache Rules格式存储，适配器自行转换
5. **规则冲突**：相同路由的多个规则，后收集的覆盖先收集的
6. **推送时机**：
   - **定时推送**：通过Cron定时任务自动触发（每15分钟），处理`trigger=cron`的规则
   - **实时推送**：规则收集时立即触发，处理`trigger=realtime`的规则
   - **手动推送**：后台操作手动触发，推送所有规则
7. **Cron任务**：必须注册Cron任务`cdn_push_rules`，确保定时推送正常运行
8. **触发方式**：`@Cdn`注释中通过`trigger`参数控制，默认`cron`（定时）
8. **职责分离**：CDN模块只负责收集、存储和触发事件，不关心具体推送逻辑
9. **适配器实现**：各适配器必须实现Observer监听推送事件
10. **错误处理**：适配器推送失败时，应记录错误日志，不影响其他适配器和域名
11. **Cloudflare对标**：规则格式、存储结构、界面展示都要对标Cloudflare
12. **增量推送**：Cron任务可以优化为只推送变更的规则，减少API调用
13. **规则通用性**：注释规则是通用的，不指定适配器，所有适配器都会收到并处理
14. **适配器过滤**：适配器通过`adapter_code`判断是否处理该域名，规则本身不区分适配器

## 12. 后续优化

1. **规则验证**：添加规则格式验证，确保规则正确性
2. **规则预览**：在推送前预览规则效果
3. **规则回滚**：支持规则回滚到上一个版本
4. **规则统计**：统计规则使用情况和缓存命中率
5. **智能推荐**：根据接口特性智能推荐缓存规则

## 13. 相关文档

- [Weline_Cdn模块使用文档](README.md)
- [Cloudflare API Token权限配置](Cloudflare-API-Token-Permissions.md)
- [CDN缓存清理事件文档](event/CDN缓存清理.md)
- [CDN预热URL投递文档](event/CDN预热URL投递.md)

---

**文档版本**: 1.0.0  
**最后更新**: 2024年  
**维护者**: Weline Framework Team
