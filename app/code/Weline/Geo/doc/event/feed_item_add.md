# Weline_Geo::feed_item_add - Feed条目添加

## 事件说明

在需要向Feed添加新条目时触发，允许其他模块通过事件系统添加Feed条目，实现模块间的解耦。

## 触发时机

当需要向Feed添加新条目时，由其他模块触发此事件。

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

- `feed_id` (int) - Feed ID，指定要添加到的Feed
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

- CMS模块：文章发布后添加到Feed
- 产品模块：产品上架后添加到Feed
- 页面模块：页面发布后添加到Feed
- 任何需要将内容同步到Feed的场景

## 使用方法

### 方法1：使用FeedEventDispatcher服务（推荐）

```php
use Weline\Geo\Service\FeedEventDispatcher;
use Weline\Framework\Manager\ObjectManager;

// 获取事件分发服务
/** @var FeedEventDispatcher $dispatcher */
$dispatcher = ObjectManager::getInstance(FeedEventDispatcher::class);

// 添加Feed条目
$dispatcher->dispatchFeedItemAdd(
    feedId: 1,
    itemType: 'article',
    itemId: 123,
    itemData: [
        'title' => '文章标题',
        'content' => '文章内容',
        'url' => 'https://example.com/article/123',
        'metadata' => [
            'author' => '作者名',
            'tags' => ['标签1', '标签2'],
        ],
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

// 添加Feed条目
$eventsManager->dispatch('Weline_Geo::feed_item_add', [
    'feed_id' => 1,
    'item_type' => 'article',
    'item_id' => 123,
    'title' => '文章标题',
    'content' => '文章内容',
    'url' => 'https://example.com/article/123',
]);
```

## 使用示例

### 示例：文章发布后添加到Feed

```php
namespace Weline\Cms\Controller\Backend;

use Weline\Geo\Service\FeedEventDispatcher;
use Weline\Framework\Manager\ObjectManager;

class Article extends BackendController
{
    public function save(): string
    {
        // ... 保存文章逻辑 ...
        
        // 文章保存成功后，触发Feed事件
        if ($article->isPublished()) {
            /** @var FeedEventDispatcher $dispatcher */
            $dispatcher = ObjectManager::getInstance(FeedEventDispatcher::class);
            
            // 获取所有内容类型的Feed ID
            $feedIds = $this->getContentFeedIds();
            
            // 添加到Feed
            $dispatcher->dispatchFeedItemAddToFeeds(
                feedIds: $feedIds,
                itemType: 'article',
                itemId: $article->getId(),
                itemData: [
                    'title' => $article->getTitle(),
                    'content' => $article->getContent(),
                    'url' => $article->getUrl(),
                    'metadata' => [
                        'author' => $article->getAuthor(),
                        'category' => $article->getCategory(),
                        'tags' => $article->getTags(),
                    ],
                    'published_at' => $article->getPublishedAt(),
                ]
            );
        }
        
        return $this->jsonResponse(true, '保存成功');
    }
}
```

## 自动处理机制

事件触发后，`FeedItemObserver` 会自动处理：

1. 验证必需字段（feed_id, item_type, item_id）
2. 检查Feed是否存在且已启用
3. 如果条目已存在，执行更新操作
4. 如果条目不存在，创建新条目
5. 如果Feed配置了自动推送，会触发推送

## 注意事项

- 如果条目已存在（相同的feed_id, item_type, item_id），会自动更新而不是创建新条目
- Feed必须存在且已启用，否则事件会被忽略
- 建议使用 `FeedEventDispatcher` 服务，提供更好的类型安全
- 批量操作时使用 `dispatchFeedItemAddToFeeds` 方法

## 相关事件

- `Weline_Geo::feed_item_update` - Feed条目更新
- `Weline_Geo::feed_item_delete` - Feed条目删除
