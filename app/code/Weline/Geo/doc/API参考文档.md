# Geo API参考文档

## 📚 目录

- [命令行工具](#命令行工具)
- [服务类API](#服务类api)
- [模型API](#模型api)
- [适配器API](#适配器api)

---

## 命令行工具

### 生成Feed

**命令**：`geo:feed:generate`

**语法**：
```bash
php bin/m geo:feed:generate <feed_id> [format]
```

**参数**：
- `feed_id`（必需）：Feed的ID
- `format`（可选）：Feed格式，可选值：`json_feed`、`xml`、`rss`，默认：`json_feed`

**示例**：
```bash
# 生成JSON Feed
php bin/m geo:feed:generate 1 json_feed

# 生成XML Feed
php bin/m geo:feed:generate 1 xml

# 生成RSS Feed
php bin/m geo:feed:generate 1 rss
```

**返回值**：
- 成功：返回 `true`，输出Feed信息
- 失败：返回 `false`，输出错误信息

---

### 推送Feed

**命令**：`geo:feed:push`

**语法**：
```bash
php bin/m geo:feed:push <feed_id> [platform_id]
```

**参数**：
- `feed_id`（必需）：Feed的ID
- `platform_id`（可选）：平台ID，如果不指定则推送到所有启用的平台

**示例**：
```bash
# 推送到指定平台
php bin/m geo:feed:push 1 1

# 推送到所有平台
php bin/m geo:feed:push 1
```

**返回值**：
- 成功：返回 `true`，显示推送结果
- 失败：返回 `false`，显示错误信息

---

## 服务类API

### FeedGeneratorService

Feed生成服务类。

#### generateFeed()

生成Feed内容。

```php
use Weline\Geo\Service\FeedGeneratorService;
use Weline\Geo\Model\Feed;
use Weline\Framework\Manager\ObjectManager;

/** @var FeedGeneratorService $feedGenerator */
$feedGenerator = ObjectManager::getInstance(FeedGeneratorService::class);

/** @var Feed $feed */
$feed = ObjectManager::getInstance(Feed::class)->load(1);

// 生成JSON Feed
$feedContent = $feedGenerator->generateFeed($feed, 'json_feed');

// 生成XML Feed
$feedContent = $feedGenerator->generateFeed($feed, 'xml');
```

**参数**：
- `Feed $feed`：Feed模型实例
- `string $format`：Feed格式（json_feed、xml、rss）

**返回值**：`string` - Feed内容

---

### PushService

推送服务类。

#### pushFeed()

推送Feed到指定平台。

```php
use Weline\Geo\Service\PushService;
use Weline\Geo\Model\Feed;
use Weline\Geo\Model\Platform;
use Weline\Geo\Model\PushLog;
use Weline\Framework\Manager\ObjectManager;

/** @var PushService $pushService */
$pushService = ObjectManager::getInstance(PushService::class);

/** @var Feed $feed */
$feed = ObjectManager::getInstance(Feed::class)->load(1);

/** @var Platform $platform */
$platform = ObjectManager::getInstance(Platform::class)->load(1);

// 推送Feed
$result = $pushService->pushFeed($feed, $platform, null, PushLog::TYPE_MANUAL);

if ($result->success) {
    echo "推送成功：{$result->message}\n";
    echo "推送条目数：{$result->itemsCount}\n";
} else {
    echo "推送失败：{$result->message}\n";
}
```

**参数**：
- `Feed $feed`：Feed模型实例
- `Platform $platform`：平台模型实例
- `PlatformAccount|null $account`：平台账户（null则使用默认账户）
- `string $pushType`：推送类型（PushLog::TYPE_MANUAL、TYPE_AUTO、TYPE_SCHEDULED）

**返回值**：`PushResult` 对象

#### pushFeedToPlatforms()

批量推送到多个平台。

```php
/** @var PushService $pushService */
$pushService = ObjectManager::getInstance(PushService::class);

/** @var Feed $feed */
$feed = ObjectManager::getInstance(Feed::class)->load(1);

// 推送到多个平台
$platformIds = [1, 2, 3];
$results = $pushService->pushFeedToPlatforms($feed, $platformIds, PushLog::TYPE_MANUAL);

foreach ($results as $platformId => $result) {
    echo "平台 {$platformId}: " . ($result->success ? '成功' : '失败') . "\n";
}
```

**参数**：
- `Feed $feed`：Feed模型实例
- `array $platformIds`：平台ID数组
- `string $pushType`：推送类型

**返回值**：`array` - 推送结果数组，键为平台ID，值为PushResult对象

---

### PlatformAdapterService

平台适配器服务类。

#### getAdapter()

获取平台适配器。

```php
use Weline\Geo\Service\PlatformAdapterService;
use Weline\Geo\Model\Platform;
use Weline\Framework\Manager\ObjectManager;

/** @var PlatformAdapterService $adapterService */
$adapterService = ObjectManager::getInstance(PlatformAdapterService::class);

/** @var Platform $platform */
$platform = ObjectManager::getInstance(Platform::class)->load(1);

// 获取适配器
$adapter = $adapterService->getAdapter($platform);

if ($adapter) {
    // 使用适配器
    $feedContent = $adapter->generateFeed($items);
}
```

**参数**：
- `Platform $platform`：平台模型实例

**返回值**：`BaseAdapter|null` - 适配器实例或null

---

### SecretStoreService

密钥加密存储服务类。

#### encryptApiKey()

加密API密钥。

```php
use Weline\Geo\Service\SecretStoreService;
use Weline\Framework\Manager\ObjectManager;

/** @var SecretStoreService $secretStore */
$secretStore = ObjectManager::getInstance(SecretStoreService::class);

$apiKey = 'your-api-key';
$encrypted = $secretStore->encryptApiKey($apiKey);
```

**参数**：
- `string $apiKey`：原始API密钥

**返回值**：`string` - 加密后的密钥（Base64编码）

#### decryptApiKey()

解密API密钥。

```php
$decrypted = $secretStore->decryptApiKey($encrypted);
```

**参数**：
- `string $encryptedKey`：加密的密钥

**返回值**：`string|null` - 解密后的密钥或null

---

## 模型API

### Platform

平台模型。

#### 常量

```php
// 平台代码
Platform::PLATFORM_GOOGLE_SGE = 'google_sge';
Platform::PLATFORM_PERPLEXITY = 'perplexity';
Platform::PLATFORM_BING_CHAT = 'bing_chat';
Platform::PLATFORM_OPENAI = 'openai';
Platform::PLATFORM_CLAUDE = 'claude';

// Feed格式
Platform::FORMAT_JSON_FEED = 'json_feed';
Platform::FORMAT_XML = 'xml';
Platform::FORMAT_RSS = 'rss';
```

#### 常用方法

```php
/** @var Platform $platform */
$platform = ObjectManager::getInstance(Platform::class);

// 加载平台
$platform->load(1);

// 获取配置数组
$config = $platform->getConfigArray();

// 设置配置数组
$platform->setConfigArray(['key' => 'value']);

// 检查是否启用
$isEnabled = $platform->isEnabled();
```

---

### Feed

Feed模型。

#### 常量

```php
// Feed类型
Feed::TYPE_CONTENT = 'content';
Feed::TYPE_PRODUCT = 'product';
Feed::TYPE_ARTICLE = 'article';
Feed::TYPE_CUSTOM = 'custom';

// 数据源类型
Feed::SOURCE_DATABASE = 'database';
Feed::SOURCE_API = 'api';
Feed::SOURCE_CUSTOM = 'custom';

// 更新频率
Feed::FREQUENCY_REALTIME = 'realtime';
Feed::FREQUENCY_HOURLY = 'hourly';
Feed::FREQUENCY_DAILY = 'daily';
Feed::FREQUENCY_WEEKLY = 'weekly';
```

#### 常用方法

```php
/** @var Feed $feed */
$feed = ObjectManager::getInstance(Feed::class);

// 加载Feed
$feed->load(1);

// 获取数据源配置
$sourceConfig = $feed->getSourceConfigArray();

// 设置数据源配置
$feed->setSourceConfigArray(['table' => 'cms_page']);

// 检查是否启用
$isEnabled = $feed->isEnabled();

// 检查是否自动推送
$isAutoPush = $feed->isAutoPush();
```

---

### PlatformAccount

平台账户模型。

#### 常量

```php
// 状态
PlatformAccount::STATUS_PENDING = 'pending';
PlatformAccount::STATUS_ACTIVE = 'active';
PlatformAccount::STATUS_FAILED = 'failed';
```

#### 常用方法

```php
/** @var PlatformAccount $account */
$account = ObjectManager::getInstance(PlatformAccount::class);

// 加载账户
$account->load(1);

// 检查是否默认账户
$isDefault = $account->isDefault();

// 检查是否激活
$isActive = $account->isActive();

// 检查是否可用
$isAvailable = $account->isAvailable();
```

---

## 适配器API

### BaseAdapter

基础适配器接口。

所有平台适配器都实现此接口。

#### generateFeed()

生成Feed。

```php
$adapter = new GoogleSgeAdapter();
$items = [
    [
        'url' => 'https://example.com/page1',
        'title' => 'Page 1',
        'content' => 'Content here',
        'published_at' => time(),
    ],
];
$feedContent = $adapter->generateFeed($items);
```

**参数**：
- `array $items`：Feed条目数组

**返回值**：`string` - Feed内容

#### pushFeed()

推送Feed。

```php
$result = $adapter->pushFeed($feedContent, $account);

if ($result->success) {
    echo "成功推送 {$result->itemsCount} 个条目\n";
} else {
    echo "推送失败：{$result->message}\n";
}
```

**参数**：
- `string $feed`：Feed内容
- `PlatformAccount $account`：平台账户

**返回值**：`PushResult` 对象

#### testConnection()

测试连接。

```php
$isConnected = $adapter->testConnection($account);
```

**参数**：
- `PlatformAccount $account`：平台账户

**返回值**：`bool` - 是否连接成功

---

## PushResult 对象

推送结果对象。

### 属性

```php
class PushResult {
    public bool $success;        // 是否成功
    public string $message;      // 消息
    public array $responseData;   // 响应数据
    public int $itemsCount;      // 推送条目数
}
```

### 使用示例

```php
$result = $pushService->pushFeed($feed, $platform);

if ($result->success) {
    echo "推送成功\n";
    echo "消息：{$result->message}\n";
    echo "条目数：{$result->itemsCount}\n";
    print_r($result->responseData);
} else {
    echo "推送失败：{$result->message}\n";
}
```

---

## 事件监听

### ContentUpdateObserver

内容更新观察者，自动处理内容更新事件。

#### 监听的事件

- `content.created`：内容创建
- `content.updated`：内容更新

#### 功能

- 自动创建Feed条目
- 触发自动推送（如果Feed配置了自动推送）

---

## 扩展开发

### 添加自定义平台适配器

1. 创建适配器类：

```php
namespace Weline\Geo\Adapter;

class CustomAdapter extends BaseAdapter
{
    public function __construct()
    {
        parent::__construct('custom_platform', 'json_feed', 'https://api.example.com/feeds');
    }

    public function generateFeed(array $items): string
    {
        // 实现Feed生成逻辑
    }

    public function pushFeed(string $feed, PlatformAccount $account): PushResult
    {
        // 实现推送逻辑
    }

    public function testConnection(PlatformAccount $account): bool
    {
        // 实现连接测试逻辑
    }
}
```

2. 注册适配器：

```php
use Weline\Geo\Service\PlatformAdapterService;

$adapterService = ObjectManager::getInstance(PlatformAdapterService::class);
$adapterService->registerAdapter('custom_platform', CustomAdapter::class);
```

---

## 相关文档

- [使用指南](使用指南.md)
- [快速入门指南](快速入门指南.md)
- [README](../README.md)

