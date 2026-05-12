# Weline_Geo::feed_item_delete - Feed条目删除

## 事件说明

在需要删除Feed条目时触发，允许其他模块通过事件系统删除Feed条目，实现模块间的解耦。

## 触发时机

当需要删除Feed条目时，由其他模块触发此事件。

## 数据格式

```php
[
    'feed_id' => int,              // 必需：Feed ID
    'item_type' => string,         // 必需：条目类型（article, product, page等）
    'item_id' => int,              // 必需：条目ID（源数据ID）
]
```

## 可用数据

### 必需字段

- `feed_id` (int) - Feed ID，指定要删除的Feed
- `item_type` (string) - 条目类型，用于分类（如：article, product, page等）
- `item_id` (int) - 条目ID，源数据的ID

## 使用场景

- CMS模块：文章删除后从Feed删除
- 产品模块：产品下架后从Feed删除
- 页面模块：页面删除后从Feed删除
- 任何需要从Feed移除内容的场景

## 使用方法

### 方法1：使用FeedEventDispatcher服务（推荐）

```php
use Weline\Geo\Service\FeedEventDispatcher;
use Weline\Framework\Manager\ObjectManager;

// 获取事件分发服务
/** @var FeedEventDispatcher $dispatcher */
$dispatcher = ObjectManager::getInstance(FeedEventDispatcher::class);

// 删除Feed条目
$dispatcher->dispatchFeedItemDelete(
    feedId: 1,
    itemType: 'article',
    itemId: 123
);
```

### 方法2：直接使用EventsManager

```php
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

// 获取事件管理器
/** @var EventsManager $eventsManager */
$eventsManager = ObjectManager::getInstance(EventsManager::class);

// 删除Feed条目
$eventsManager->dispatch('Weline_Geo::feed_item_delete', [
    'feed_id' => 1,
    'item_type' => 'article',
    'item_id' => 123,
]);
```

## 使用示例

### 示例：文章删除后从Feed删除

```php
namespace Weline\Cms\Controller\Backend;

use Weline\Geo\Service\FeedEventDispatcher;
use Weline\Framework\Manager\ObjectManager;

class Article extends BackendController
{
    public function delete(): string
    {
        $articleId = $this->request->getParam('id');
        
        // ... 删除文章逻辑 ...
        
        // 文章删除后，从Feed删除
        /** @var FeedEventDispatcher $dispatcher */
        $dispatcher = ObjectManager::getInstance(FeedEventDispatcher::class);
        
        // 获取所有内容类型的Feed ID
        $feedIds = $this->getContentFeedIds();
        
        // 从所有Feed删除
        foreach ($feedIds as $feedId) {
            $dispatcher->dispatchFeedItemDelete(
                feedId: $feedId,
                itemType: 'article',
                itemId: $articleId
            );
        }
        
        return $this->jsonResponse(true, '删除成功');
    }
}
```

## 自动处理机制

事件触发后，`FeedItemObserver` 会自动处理：

1. 验证必需字段（feed_id, item_type, item_id）
2. 检查Feed是否存在且已启用
3. 查找现有条目（根据feed_id, item_type, item_id）
4. 如果条目存在，删除条目
5. 如果条目不存在，忽略操作（不会报错）

## 注意事项

- 如果条目不存在，操作会被忽略，不会报错
- Feed必须存在且已启用，否则事件会被忽略
- 删除操作是物理删除，无法恢复
- 建议使用 `FeedEventDispatcher` 服务，提供更好的类型安全
- 批量删除时，可以循环调用或使用队列异步处理

## 相关事件

- `Weline_Geo::feed_item_add` - Feed条目添加
- `Weline_Geo::feed_item_update` - Feed条目更新
