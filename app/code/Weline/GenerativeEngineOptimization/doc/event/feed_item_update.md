# Weline_GenerativeEngineOptimization::feed_item_update - Feed条目更新

## 事件说明

在需要更新Feed条目时触发，允许其他模块通过事件系统更新Feed条目。如果条目不存在，会自动创建。

## 触发时机

当需要更新Feed条目时，由其他模块触发此事件。

## 数据格式

```php
[
    'feed_id' => int,              // 必需：Feed ID
    'item_type' => string,         // 必需：条目类型（article, product, page等）
    'item_id' => int,              // 必需：条目ID（源数据ID）
    'title' => string,             // 可选：标题
    'content' => string,           // 可选：内容
    'url' => string,               // 可选：URL
    'metadata' => array,            // 可选：元数据（数组）
    'is_published' => int,         // 可选：是否发布（默认1）
    'published_at' => int,          // 可选：发布时间（默认当前时间）
]
```

## 可用数据

### 必需字段

- `feed_id` (int) - Feed ID，指定要更新的Feed
- `item_type` (string) - 条目类型，用于分类（如：article, product, page等）
- `item_id` (int) - 条目ID，源数据的ID

### 可选字段

- `title` (string) - 标题
- `content` (string) - 内容（纯文本）
- `url` (string) - URL地址
- `metadata` (array) - 元数据，可包含：
  - `content_html`：HTML内容
  - `author`：作者
  - `tags`：标签数组
  - `category`：分类
  - 其他自定义字段
- `is_published` (int) - 是否发布（1=发布，0=未发布，默认1）
- `published_at` (int) - 发布时间戳（默认当前时间）

## 使用场景

- CMS模块：文章更新后更新Feed
- 产品模块：产品信息更新后更新Feed
- 页面模块：页面内容更新后更新Feed
- 任何需要同步更新Feed内容的场景

## 使用方法

### 方法1：使用FeedEventDispatcher服务（推荐）

```php
use Weline\GenerativeEngineOptimization\Service\FeedEventDispatcher;
use Weline\Framework\Manager\ObjectManager;

// 获取事件分发服务
/** @var FeedEventDispatcher $dispatcher */
$dispatcher = ObjectManager::getInstance(FeedEventDispatcher::class);

// 更新Feed条目
$dispatcher->dispatchFeedItemUpdate(
    feedId: 1,
    itemType: 'article',
    itemId: 123,
    itemData: [
        'title' => '更新后的标题',
        'content' => '更新后的内容',
        'url' => 'https://example.com/article/123',
    ]
);
```

### 方法2：直接使用EventsManager

```php
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

// 获取事件管理器
/** @var EventsManager $eventsManager */
$eventsManager = ObjectManager::getInstance(EventsManager::class);

// 更新Feed条目
$eventsManager->dispatch('Weline_GenerativeEngineOptimization::feed_item_update', [
    'feed_id' => 1,
    'item_type' => 'article',
    'item_id' => 123,
    'title' => '更新后的标题',
    'content' => '更新后的内容',
]);
```

## 使用示例

### 示例：产品更新后更新Feed

```php
namespace Weline\Product\Model;

use Weline\GenerativeEngineOptimization\Service\FeedEventDispatcher;
use Weline\Framework\Manager\ObjectManager;

class Product extends Model
{
    public function save_after(): void
    {
        // 产品保存后触发Feed更新事件
        if ($this->isEnabled()) {
            /** @var FeedEventDispatcher $dispatcher */
            $dispatcher = ObjectManager::getInstance(FeedEventDispatcher::class);
            
            // 获取所有产品类型的Feed ID
            $feedIds = $this->getProductFeedIds();
            
            // 更新Feed条目
            $dispatcher->dispatchFeedItemUpdateToFeeds(
                feedIds: $feedIds,
                itemType: 'product',
                itemId: $this->getId(),
                itemData: [
                    'title' => $this->getName(),
                    'content' => $this->getDescription(),
                    'url' => $this->getUrl(),
                    'metadata' => [
                        'price' => $this->getPrice(),
                        'sku' => $this->getSku(),
                        'category' => $this->getCategory(),
                    ],
                ]
            );
        }
    }
}
```

## 自动处理机制

事件触发后，`FeedItemObserver` 会自动处理：

1. 验证必需字段（feed_id, item_type, item_id）
2. 检查Feed是否存在且已启用
3. 查找现有条目（根据feed_id, item_type, item_id）
4. 如果条目存在，更新数据
5. 如果条目不存在，创建新条目（相当于执行add操作）
6. 如果Feed配置了自动推送，会触发推送

## 注意事项

- **如果条目不存在，会自动创建**，相当于执行add操作
- Feed必须存在且已启用，否则事件会被忽略
- 只更新提供的字段，未提供的字段保持不变
- 建议使用 `FeedEventDispatcher` 服务，提供更好的类型安全
- 批量操作时使用 `dispatchFeedItemUpdateToFeeds` 方法

## 相关事件

- `Weline_GenerativeEngineOptimization::feed_item_add` - Feed条目添加
- `Weline_GenerativeEngineOptimization::feed_item_delete` - Feed条目删除
