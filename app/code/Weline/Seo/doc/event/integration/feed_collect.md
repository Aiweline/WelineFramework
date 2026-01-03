# Weline_Seo::integration::feed_collect - SEO Feed收集

## 事件说明

允许其他模块向SEO模块注入Feed数据，实现跨模块的SEO信息收集。

## 事件类型

**Integration Event（集成事件）** - 跨模块/系统的事件

## 触发时机

当其他模块需要向SEO模块提供Feed数据时触发。

## 数据格式

```php
[
    'subject_type' => string,          // 必需：主体类型
    'subject_id' => int,              // 必需：主体ID
    'feed_data' => array,              // 必需：Feed数据
]
```

## 可用数据

### 必需字段

- `subject_type` (string) - 主体类型（如：store, website等）
- `subject_id` (integer) - 主体ID
- `feed_data` (array) - Feed数据，包含：
  - `url` (string) - URL地址
  - `title` (string) - 标题
  - `description` (string) - 描述
  - `keywords` (array) - 关键词列表
  - `meta_data` (array) - 元数据
  - 其他自定义字段

## 使用场景

- 其他模块向SEO模块提供内容数据
- 实现跨模块的SEO信息收集
- 统一管理SEO数据源

## 使用方法

### 触发事件

```php
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

// 获取事件管理器
/** @var EventsManager $eventsManager */
$eventsManager = ObjectManager::getInstance(EventsManager::class);

// 触发Feed收集事件
$eventsManager->dispatch('Weline_Seo::integration::feed_collect', [
    'subject_type' => 'store',
    'subject_id' => 123,
    'feed_data' => [
        'url' => 'https://example.com/store/123',
        'title' => '店铺标题',
        'description' => '店铺描述',
        'keywords' => ['关键词1', '关键词2'],
        'meta_data' => [
            'locale' => 'zh-CN',
            'category' => 'electronics',
        ],
    ],
]);
```

## 使用示例

### 示例：店铺模块向SEO模块提供Feed数据

```php
namespace Weline\Store\Model;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class Store extends Model
{
    public function save_after(): void
    {
        // 店铺保存后，向SEO模块提供Feed数据
        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        
        $eventsManager->dispatch('Weline_Seo::integration::feed_collect', [
            'subject_type' => 'store',
            'subject_id' => $this->getId(),
            'feed_data' => [
                'url' => $this->getUrl(),
                'title' => $this->getName(),
                'description' => $this->getDescription(),
                'keywords' => $this->getKeywords(),
                'meta_data' => [
                    'locale' => $this->getLocale(),
                    'category' => $this->getCategory(),
                ],
            ],
        ]);
    }
}
```

## 自动处理机制

事件触发后，SEO模块会自动处理：

1. 验证必需字段
2. 创建或更新SEO主体
3. 保存Feed数据
4. 触发关键词提取任务（如果适用）

## 注意事项

- 这是一个集成事件，用于跨模块通信
- Feed数据会被SEO模块处理并保存
- 建议在数据保存后触发此事件
- 如果Feed数据格式不正确，事件可能被忽略

## 相关事件

- `Weline_Seo::domain::subject_created` - SEO主体创建
- `Weline_Seo::integration::task_enqueued` - SEO任务入队
