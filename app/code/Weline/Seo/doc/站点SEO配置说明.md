# 站点 SEO 配置功能说明

## 功能概述

支持为每个站点单独配置 SEO 参数，包括 Sitemap 生成频率、抓取频率、URL 优先级等。**一个 SEO 账号可以关联多个网站**，每个网站可以有不同的配置。

---

## 🎯 核心特性

### 1. **一个 SEO 账号 → 多个站点**

```
SEO 账号 (Google)
├── 站点 A (example-a.com)
│   ├── Sitemap频率: 每天
│   ├── 抓取频率: weekly
│   └── 优先级: 0.8
├── 站点 B (example-b.com)
│   ├── Sitemap频率: 每小时
│   ├── 抓取频率: daily
│   └── 优先级: 0.9
└── 站点 C (example-c.com)
    ├── Sitemap频率: 手动
    ├── 抓取频率: monthly
    └── 优先级: 0.5
```

### 2. **灵活的配置参数**

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `is_auto_submit` | boolean | `true` | 是否自动提交 sitemap |
| `sitemap_frequency` | string | `daily` | Sitemap 生成频率 |
| `crawl_frequency` | string | `weekly` | 抓取频率（changefreq） |
| `priority` | float | `0.5` | URL 优先级（0.0-1.0） |
| `config` | array | `[]` | 其他自定义配置 |

### 3. **默认值机制**

如果绑定时不提供配置，自动使用默认值：

```php
const DEFAULT_SITEMAP_FREQUENCY = 'daily';    // 每天
const DEFAULT_CRAWL_FREQUENCY = 'weekly';     // 每周
const DEFAULT_PRIORITY = 0.5;                 // 中等优先级
```

---

## 📊 配置字段详解

### Sitemap 生成频率 (`sitemap_frequency`)

**控制 sitemap 文件的生成频率（Cron 任务调度）**

| 值 | 说明 | 适用场景 |
|---|------|----------|
| `realtime` | 实时 | 高频更新的新闻站点 |
| `hourly` | 每小时 | 电商、博客 |
| `daily` | 每天（默认） | 大多数网站 |
| `weekly` | 每周 | 更新较少的企业站 |
| `monthly` | 每月 | 静态内容站点 |
| `manual` | 手动 | 完全手动控制 |

**示例：**
```php
'sitemap_frequency' => 'daily'  // 每天生成一次 sitemap
```

### 抓取频率 (`crawl_frequency`)

**提示搜索引擎的爬虫多久访问一次（sitemap XML 的 `<changefreq>`）**

| 值 | 说明 | 搜索引擎建议 |
|---|------|--------------|
| `always` | 总是 | 每次访问都会变化的页面 |
| `hourly` | 每小时 | 高频更新内容 |
| `daily` | 每天 | 日常更新的博客 |
| `weekly` | 每周（默认） | 定期更新的内容 |
| `monthly` | 每月 | 较少变化的页面 |
| `yearly` | 每年 | 存档内容 |
| `never` | 从不 | 静态页面 |

**注意：** 这只是建议值，搜索引擎可能不遵守。

**示例：**
```php
'crawl_frequency' => 'weekly'  // 建议每周抓取
```

### URL 优先级 (`priority`)

**指定页面相对于站点其他页面的重要性（0.0 - 1.0）**

| 值 | 建议用途 |
|----|----------|
| `1.0` | 最重要的页面（首页） |
| `0.8` | 重要分类、产品页 |
| `0.5` | 普通页面（默认） |
| `0.3` | 次要页面 |
| `0.0` | 不重要的页面 |

**示例：**
```php
'priority' => 0.8  // 较高优先级
```

### 额外配置 (`config`)

**JSON 格式存储其他自定义配置**

```php
'config' => [
    'max_urls_per_file' => 10000,      // 每个文件最大 URL 数
    'exclude_patterns' => ['/test/*'], // 排除的 URL 模式
    'custom_params' => [...]           // 其他自定义参数
]
```

---

## 💻 使用示例

### 示例 1：绑定站点时设置完整配置

```php
use Weline\Seo\Model\SeoWebsiteAccount;
use Weline\Framework\Manager\ObjectManager;

$binding = ObjectManager::getInstance(SeoWebsiteAccount::class);

// 为高频更新的新闻站点配置
$binding->bindWebsiteAccount(
    websiteId: 1,
    accountId: $googleAccountId,
    config: [
        'is_auto_submit' => true,
        'sitemap_frequency' => 'hourly',     // 每小时生成
        'crawl_frequency' => 'daily',        // 建议每天抓取
        'priority' => 0.9,                   // 高优先级
        'config' => [
            'max_urls_per_file' => 50000,
            'submit_ping' => true
        ]
    ]
);
```

### 示例 2：使用默认配置（简化版）

```php
// 不提供 config 参数，使用所有默认值
$binding->bindWebsiteAccount(
    websiteId: 2,
    accountId: $bingAccountId
);

// 等价于：
// - is_auto_submit: true
// - sitemap_frequency: daily
// - crawl_frequency: weekly
// - priority: 0.5
```

### 示例 3：只设置部分参数

```php
// 只设置频率，其他使用默认值
$binding->bindWebsiteAccount(
    websiteId: 3,
    accountId: $baiduAccountId,
    config: [
        'sitemap_frequency' => 'weekly',  // 自定义
        // is_auto_submit → true（默认）
        // crawl_frequency → weekly（默认）
        // priority → 0.5（默认）
    ]
);
```

### 示例 4：一个账号关联多个站点，不同配置

```php
$googleAccountId = 1;

// 站点 A：高频电商站
$binding->bindWebsiteAccount(1, $googleAccountId, [
    'sitemap_frequency' => 'hourly',
    'crawl_frequency' => 'daily',
    'priority' => 0.9
]);

// 站点 B：普通博客
$binding->bindWebsiteAccount(2, $googleAccountId, [
    'sitemap_frequency' => 'daily',
    'crawl_frequency' => 'weekly',
    'priority' => 0.7
]);

// 站点 C：静态文档站
$binding->bindWebsiteAccount(3, $googleAccountId, [
    'sitemap_frequency' => 'monthly',
    'crawl_frequency' => 'monthly',
    'priority' => 0.5
]);
```

### 示例 5：读取和更新配置

```php
$binding = $seoWebsiteAccountModel->getByWebsiteAndAccount($websiteId, $accountId);

// 读取配置
$frequency = $binding->getSitemapFrequency();    // 'daily'
$crawl = $binding->getCrawlFrequency();          // 'weekly'
$priority = $binding->getPriority();             // 0.5
$config = $binding->getConfig();                 // []

// 更新配置
$binding->setSitemapFrequency('hourly')
    ->setCrawlFrequency('daily')
    ->setPriority(0.8)
    ->save();
```

---

## 🗄️ 数据库 Schema

### `weline_seo_website_account` 表

| 字段名 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| `id` | INT | - | 主键 |
| `website_id` | INT | - | 站点ID |
| `account_id` | INT | - | SEO账户ID |
| `is_auto_submit` | INT(1) | `1` | 是否自动提交 |
| `sitemap_frequency` | VARCHAR(20) | `daily` | Sitemap生成频率 |
| `crawl_frequency` | VARCHAR(20) | `weekly` | 抓取频率 |
| `priority` | DECIMAL(3,2) | `0.50` | URL优先级 |
| `config_json` | TEXT | - | 额外配置（JSON） |
| `created_at` | DATETIME | - | 创建时间 |
| `updated_at` | DATETIME | - | 更新时间 |

**索引：**
- `unique_website_account` (website_id, account_id) - 唯一索引
- `idx_website` (website_id) - 站点索引

---

## 🚀 Cron 任务集成

### 根据 `sitemap_frequency` 调度生成任务

```php
// Cron 配置示例
'sitemap_generation' => [
    'schedule' => '*/5 * * * *',  // 每5分钟检查一次
    'run' => function() {
        // 获取需要生成的站点
        $bindings = $seoWebsiteAccountModel->reset()
            ->where('is_auto_submit', 1)
            ->select()
            ->fetchArray();
        
        foreach ($bindings as $binding) {
            $frequency = $binding['sitemap_frequency'];
            $lastUpdate = strtotime($binding['updated_at']);
            $now = time();
            
            $shouldGenerate = false;
            switch ($frequency) {
                case 'realtime':
                    $shouldGenerate = true;
                    break;
                case 'hourly':
                    $shouldGenerate = ($now - $lastUpdate) >= 3600;
                    break;
                case 'daily':
                    $shouldGenerate = ($now - $lastUpdate) >= 86400;
                    break;
                case 'weekly':
                    $shouldGenerate = ($now - $lastUpdate) >= 604800;
                    break;
                case 'monthly':
                    $shouldGenerate = ($now - $lastUpdate) >= 2592000;
                    break;
            }
            
            if ($shouldGenerate) {
                // 生成 sitemap
                $webSitemapData->generateForWebsite($binding['website_id']);
            }
        }
    }
]
```

---

## 📝 前端界面建议

### SEO 账户绑定页面

```html
<form action="/seo/backend/account/bind" method="post">
    <!-- 选择站点 -->
    <select name="website_id">
        <option value="1">站点 A</option>
        <option value="2">站点 B</option>
    </select>
    
    <!-- 选择账户 -->
    <select name="account_id">
        <option value="1">Google Account</option>
        <option value="2">Bing Account</option>
    </select>
    
    <!-- 配置选项 -->
    <label>
        <input type="checkbox" name="is_auto_submit" value="1" checked>
        自动提交 Sitemap
    </label>
    
    <select name="sitemap_frequency">
        <option value="realtime">实时</option>
        <option value="hourly">每小时</option>
        <option value="daily" selected>每天（推荐）</option>
        <option value="weekly">每周</option>
        <option value="monthly">每月</option>
        <option value="manual">手动</option>
    </select>
    
    <select name="crawl_frequency">
        <option value="always">总是</option>
        <option value="hourly">每小时</option>
        <option value="daily">每天</option>
        <option value="weekly" selected>每周（推荐）</option>
        <option value="monthly">每月</option>
        <option value="yearly">每年</option>
        <option value="never">从不</option>
    </select>
    
    <input type="number" name="priority" min="0" max="1" step="0.1" value="0.5">
    
    <button type="submit">绑定并保存配置</button>
</form>
```

### 控制器处理

```php
public function bindAction()
{
    $websiteId = (int)$this->request->getPost('website_id');
    $accountId = (int)$this->request->getPost('account_id');
    
    $config = [
        'is_auto_submit' => (bool)$this->request->getPost('is_auto_submit'),
        'sitemap_frequency' => $this->request->getPost('sitemap_frequency', 'daily'),
        'crawl_frequency' => $this->request->getPost('crawl_frequency', 'weekly'),
        'priority' => (float)$this->request->getPost('priority', 0.5),
    ];
    
    $seoWebsiteAccountModel->bindWebsiteAccount($websiteId, $accountId, $config);
    
    return $this->success('绑定成功');
}
```

---

## 🔄 升级步骤

### 1. 运行数据库升级

```bash
php bin/w setup:upgrade
```

这将自动添加以下字段：
- `sitemap_frequency` (VARCHAR(20), DEFAULT 'daily')
- `crawl_frequency` (VARCHAR(20), DEFAULT 'weekly')
- `priority` (DECIMAL(3,2), DEFAULT 0.50)
- `config_json` (TEXT)

### 2. 更新现有绑定（可选）

如果已有绑定记录，系统会自动使用默认值：

```sql
-- 查看现有绑定
SELECT 
    id,
    website_id,
    account_id,
    sitemap_frequency,  -- 自动为 'daily'
    crawl_frequency,    -- 自动为 'weekly'
    priority            -- 自动为 0.50
FROM weline_seo_website_account;
```

### 3. 批量设置不同频率（可选）

```php
// 为所有电商站点设置高频
$bindings = $seoWebsiteAccountModel->reset()
    ->where('website_id', 'IN', [1, 2, 3])
    ->select()
    ->fetchArray();

foreach ($bindings as $row) {
    $binding = $seoWebsiteAccountModel->reset()->load($row['id']);
    $binding->setSitemapFrequency('hourly')
        ->setCrawlFrequency('daily')
        ->setPriority(0.9)
        ->save();
}
```

---

## 🎯 最佳实践

### 1. **根据站点类型选择频率**

| 站点类型 | Sitemap频率 | 抓取频率 | 优先级 |
|---------|-------------|----------|--------|
| 新闻站点 | hourly/realtime | daily | 0.9 |
| 电商平台 | hourly/daily | daily | 0.8 |
| 博客 | daily | weekly | 0.7 |
| 企业官网 | weekly | monthly | 0.6 |
| 文档站点 | monthly | monthly | 0.5 |

### 2. **平衡服务器负载**

- 避免所有站点都设置为 `realtime` 或 `hourly`
- 根据实际更新频率选择合适的值
- `daily` 对大多数站点已足够

### 3. **优先级设置建议**

```
首页：1.0
重要分类/产品：0.8-0.9
普通内容页：0.5-0.7
标签/归档页：0.3-0.4
测试/临时页：0.0-0.2
```

### 4. **监控和调整**

- 定期检查 `updated_at` 字段，确认生成任务正常运行
- 根据搜索引擎抓取日志调整 `crawl_frequency`
- 分析流量数据，优化 `priority` 设置

---

## 📚 API 参考

### 模型方法

```php
// Getter 方法
$binding->getSitemapFrequency(): string;  // 获取 Sitemap 生成频率
$binding->getCrawlFrequency(): string;    // 获取抓取频率
$binding->getPriority(): float;           // 获取优先级
$binding->getConfig(): array;             // 获取额外配置

// Setter 方法
$binding->setSitemapFrequency(string $frequency): self;
$binding->setCrawlFrequency(string $frequency): self;
$binding->setPriority(float $priority): self;
$binding->setConfig(array $config): self;

// 静态方法
SeoWebsiteAccount::getSitemapFrequencyOptions(): array;  // 获取频率选项
SeoWebsiteAccount::getCrawlFrequencyOptions(): array;    // 获取抓取频率选项
```

---

## ✅ 总结

**核心特性：**
- ✅ 一个 SEO 账号可以关联多个网站
- ✅ 每个网站可以有独立的配置
- ✅ 提供默认值，简化配置
- ✅ 支持自定义扩展配置（config_json）
- ✅ 向后兼容（已有绑定自动使用默认值）

**配置参数：**
- ✅ Sitemap 生成频率（6个选项）
- ✅ 抓取频率建议（7个选项）
- ✅ URL 优先级（0.0-1.0）
- ✅ 额外自定义配置

**适用场景：**
- ✅ 多站点管理
- ✅ 不同类型站点需要不同配置
- ✅ 灵活的调度策略
- ✅ 精细化 SEO 控制

---

**版本：** 1.0.2  
**更新时间：** 2026-01-30  
**状态：** ✅ 已实现
