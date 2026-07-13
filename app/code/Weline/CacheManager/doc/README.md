# Weline CacheManager 缓存管理模块

## 模块概述

Weline CacheManager 是系统的缓存管理模块，提供了统一的缓存管理界面、缓存状态控制、缓存清理等功能。该模块支持多种缓存类型，包括系统缓存和应用缓存，并提供可视化的缓存管理工具。

Cron 是可选集成：安装后缓存面板通过 `Weline\Cron\Api\Task\CronTaskCatalogInterface` 读取不可变 `CronTaskRecord` 投影，未安装或 Provider 不可用时返回空列表且不影响缓存管理。CacheManager 不得直接读取 Cron ORM Model、字段常量或 Query Builder。

跨模块读取运行时 TTL 时使用 `Weline\CacheManager\Api\RuntimeCachePolicy`。该 facade
只返回限幅后的整数 TTL，并在 CacheManager 内部委托配置默认合并与请求级缓存；调用模块不得
引用 `CacheManager\Service\RuntimeCachePolicy`。

## 主要功能

### 1. 缓存管理界面
- 缓存列表展示
- 缓存状态控制（启用/禁用）
- 缓存类型分类
- 缓存描述信息

### 2. 缓存控制
- 动态启用/禁用缓存
- 缓存状态持久化
- 缓存配置管理
- 缓存环境配置

### 3. 缓存类型支持
- 系统缓存（type=0）
- 应用缓存（type=1）
- 模块缓存
- 持久化缓存

### 4. 缓存操作
- 缓存清理
- 缓存重建
- 缓存统计
- 缓存监控

### 5. 后台集成
- 后台管理界面
- 缓存状态切换
- 分页显示
- 搜索过滤

## 使用方法

### 缓存模型使用
```php
use Weline\CacheManager\Model\Cache;

$cacheModel = new Cache();

// 获取所有缓存
$caches = $cacheModel->select()->fetchArray();

// 获取启用的缓存
$enabledCaches = $cacheModel->where('status', 1)->select()->fetchArray();

// 获取特定模块的缓存
$moduleCaches = $cacheModel->where('module', 'Your_Module')->select()->fetchArray();
```

### 缓存状态控制
```php
use Weline\CacheManager\Model\Cache;
use Weline\Framework\App\Env;

$cacheModel = new Cache();

// 启用缓存
$cacheModel->where('identity', 'your_cache_identity')
    ->update(['status' => 1])
    ->fetch();

// 禁用缓存
$cacheModel->where('identity', 'your_cache_identity')
    ->update(['status' => 0])
    ->fetch();

// 更新环境配置
$cacheEnv = Env::getInstance()->getConfig('cache');
$cacheEnv['status']['your_cache_identity'] = 1; // 1启用，0禁用
Env::getInstance()->setConfig('cache', $cacheEnv);
```

### 创建自定义缓存
```php
use Weline\CacheManager\Model\Cache;

$cache = new Cache();
$cache->setName('自定义缓存')
    ->setStatus(1) // 1启用，0禁用
    ->setPermanently(0) // 0不持久化，1持久化
    ->setModule('Your_Module')
    ->setType(1) // 0系统缓存，1应用缓存
    ->setIdentity('your_cache_identity')
    ->setFile('/path/to/cache/file')
    ->setDescription('这是一个自定义缓存')
    ->save();
```

### 后台控制器使用
```php
namespace Your\Module\Controller\System;

use Weline\Framework\App\Controller\BackendPageController;
use Weline\Framework\Manager\ObjectManager;
use Weline\CacheManager\Model\Cache;

class CacheController extends BackendPageController
{
    public function index()
    {
        /**@var Cache $cacheModel */
        $cacheModel = ObjectManager::getInstance(Cache::class);
        
        $caches = $cacheModel->pagination(
            $this->request->getParam('page', 1),
            $this->request->getParam('pageSize', 10),
            $this->request->getParams()
        )->select()->fetch();
        
        $this->assign('caches', $caches->getItems());
        $this->assign('pagination', $caches->getPagination());
        $this->assign('total', $caches->getPaginationData()['totalSize']);
        
        return $this->fetch();
    }
    
    public function toggleStatus()
    {
        $identity = $this->request->getParam('identity');
        $status = $this->request->getParam('status') === 'true' ? 1 : 0;
        
        /**@var Cache $cacheModel */
        $cacheModel = ObjectManager::getInstance(Cache::class);
        
        try {
            $cacheModel->where('identity', $identity)
                ->update(['status' => $status])
                ->fetch();
                
            // 更新环境配置
            $this->updateCacheConfig($identity, $status);
            
            return $this->fetchJson([
                'code' => 200, 
                'msg' => '操作成功！', 
                'data' => $status
            ]);
        } catch (\Exception $exception) {
            return $this->fetchJson([
                'code' => 403, 
                'msg' => $exception->getMessage(), 
                'data' => $status
            ]);
        }
    }
    
    private function updateCacheConfig($identity, $status)
    {
        $cacheEnv = \Weline\Framework\App\Env::getInstance()->getConfig('cache');
        $cacheEnv['status'] = $cacheEnv['status'] ?? [];
        $cacheEnv['status'][$identity] = $status;
        \Weline\Framework\App\Env::getInstance()->setConfig('cache', $cacheEnv);
    }
}
```

## 配置说明

### 缓存配置
在 `app/etc/cache.php` 中配置缓存相关设置：

```php
'cache' => [
    'default' => 'file',
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => 'var/cache'
        ],
        'redis' => [
            'driver' => 'redis',
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0
        ]
    ],
    'status' => [
        'system_cache' => 1,
        'app_cache' => 1,
        'your_cache_identity' => 1
    ]
]
```

### 缓存类型配置
```php
'cache_types' => [
    0 => '系统缓存',
    1 => '应用缓存'
]
```

## 依赖关系

- Weline_Framework
- Weline_Admin

## 版本信息

- 当前版本：1.1.0
- 作者：秋枫雁飞
- 邮箱：aiweline@qq.com
- 网址：aiweline.com

## 缓存类型说明

### 系统缓存（type=0）
- 框架核心缓存
- 系统配置缓存
- 路由缓存
- 模块缓存

### 应用缓存（type=1）
- 应用数据缓存
- 业务逻辑缓存
- 用户会话缓存
- 临时数据缓存

### 持久化缓存（permanently=1）
- 跨会话保持
- 系统重启后保留
- 重要数据缓存

## 缓存管理界面

### 缓存列表页面
```html
<!-- 缓存管理界面 -->
<div class="cache-manager">
    <div class="toolbar">
        <button class="btn btn-primary" onclick="refreshCache()">刷新缓存</button>
        <button class="btn btn-danger" onclick="clearAllCache()">清理所有缓存</button>
    </div>
    
    <div class="cache-list">
        {foreach $caches as $cache}
            <div class="cache-item">
                <div class="cache-info">
                    <h4>{$cache.name}</h4>
                    <p>{$cache.description}</p>
                    <small>模块: {$cache.module} | 类型: {$cache.type == 0 ? '系统缓存' : '应用缓存'}</small>
                </div>
                
                <div class="cache-actions">
                    <label class="switch">
                        <input type="checkbox" 
                               {if $cache.status == 1}checked{/if}
                               onchange="toggleCache('{$cache.identity}', this.checked)">
                        <span class="slider"></span>
                    </label>
                    
                    <button class="btn btn-sm btn-warning" 
                            onclick="clearCache('{$cache.identity}')">
                        清理
                    </button>
                </div>
            </div>
        {/foreach}
    </div>
    
    <!-- 分页 -->
    {include file="pagination.phtml"}
</div>
```

### JavaScript 交互
```javascript
// 切换缓存状态
function toggleCache(identity, status) {
    $.ajax({
        url: '/admin/system/cache/postStatus',
        method: 'POST',
        data: {
            identity: identity,
            cache: status
        },
        success: function(response) {
            if (response.code === 200) {
                showMessage('缓存状态更新成功', 'success');
            } else {
                showMessage('操作失败: ' + response.msg, 'error');
            }
        },
        error: function() {
            showMessage('网络错误', 'error');
        }
    });
}

// 清理指定缓存
function clearCache(identity) {
    if (confirm('确定要清理此缓存吗？')) {
        $.ajax({
            url: '/admin/system/cache/clear',
            method: 'POST',
            data: {identity: identity},
            success: function(response) {
                if (response.code === 200) {
                    showMessage('缓存清理成功', 'success');
                } else {
                    showMessage('清理失败: ' + response.msg, 'error');
                }
            }
        });
    }
}

// 清理所有缓存
function clearAllCache() {
    if (confirm('确定要清理所有缓存吗？此操作不可恢复！')) {
        $.ajax({
            url: '/admin/system/cache/clearAll',
            method: 'POST',
            success: function(response) {
                if (response.code === 200) {
                    showMessage('所有缓存清理成功', 'success');
                    location.reload();
                } else {
                    showMessage('清理失败: ' + response.msg, 'error');
                }
            }
        });
    }
}
```

## 缓存监控

### 缓存统计
```php
use Weline\CacheManager\Model\Cache;

$cacheModel = new Cache();

// 获取缓存统计信息
$stats = [
    'total' => $cacheModel->count(),
    'enabled' => $cacheModel->where('status', 1)->count(),
    'disabled' => $cacheModel->where('status', 0)->count(),
    'system' => $cacheModel->where('type', 0)->count(),
    'application' => $cacheModel->where('type', 1)->count(),
    'permanent' => $cacheModel->where('permanently', 1)->count()
];
```

### 缓存性能监控
```php
// 缓存命中率统计
$cacheHits = 0;
$cacheMisses = 0;

// 记录缓存访问
function recordCacheAccess($identity, $hit = true) {
    global $cacheHits, $cacheMisses;
    
    if ($hit) {
        $cacheHits++;
    } else {
        $cacheMisses++;
    }
    
    // 保存到数据库或日志
    $this->logCacheAccess($identity, $hit);
}

// 计算命中率
function getCacheHitRate() {
    global $cacheHits, $cacheMisses;
    $total = $cacheHits + $cacheMisses;
    
    if ($total === 0) {
        return 0;
    }
    
    return ($cacheHits / $total) * 100;
}
```

## 最佳实践

### 1. 缓存策略
- 合理设置缓存过期时间
- 避免缓存过大对象
- 使用缓存标签管理
- 定期清理过期缓存

### 2. 性能优化
- 启用系统关键缓存
- 禁用不必要的缓存
- 监控缓存命中率
- 优化缓存存储

### 3. 缓存安全
- 敏感数据不缓存
- 缓存数据加密
- 缓存访问控制
- 定期清理敏感缓存

### 4. 监控维护
- 定期检查缓存状态
- 监控缓存性能
- 及时处理缓存错误
- 备份重要缓存配置

## 常见问题

### Q: 如何查看缓存状态？
A: 访问后台管理界面 `/admin/system/cache` 查看所有缓存状态。

### Q: 如何启用/禁用缓存？
A: 在缓存管理界面点击开关按钮，或通过代码调用 `toggleStatus` 方法。

### Q: 如何清理特定缓存？
A: 在缓存管理界面点击"清理"按钮，或通过代码调用 `clearCache` 方法。

### Q: 缓存状态不生效怎么办？
A: 检查环境配置是否正确更新，重启应用或清理配置缓存。

### Q: 如何添加新的缓存类型？
A: 在数据库中添加新的缓存记录，设置正确的 identity 和 type 值。
