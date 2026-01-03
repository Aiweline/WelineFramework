# Weline_Seo::domain::subject_created - SEO主体创建

## 事件说明

当SEO主体（店铺、网站等）被创建时触发，允许其他模块监听并处理SEO主体创建逻辑。

## 事件类型

**Domain Event（领域事件）** - 业务领域内的事件

## 触发时机

在SEO主体创建成功后触发，通常在 `TaskProcessor::processFeedCollectTask()` 或 `SubjectResolver::resolve()` 方法中。

## 数据格式

```php
[
    'subject_id' => int,              // 必需：SEO主体ID
    'subject_type' => string,          // 必需：主体类型（store, website等）
    'subject_entity_id' => int,       // 必需：主体实体ID
    'url' => string,                   // 可选：URL地址
    'title' => string,                 // 可选：标题
    'description' => string,           // 可选：描述
    'locale' => string,                // 可选：语言代码（默认：zh-CN）
]
```

## 可用数据

### 必需字段

- `subject_id` (integer) - SEO主体ID
- `subject_type` (string) - 主体类型：store, website等
- `subject_entity_id` (integer) - 主体实体ID

### 可选字段

- `url` (string) - URL地址
- `title` (string) - 标题
- `description` (string) - 描述
- `locale` (string) - 语言代码（默认：zh-CN）

## 使用场景

- 监听SEO主体创建，执行相关初始化操作
- 同步SEO主体信息到外部系统
- 记录SEO主体创建日志
- 触发SEO分析任务

## 使用方法

### 在 event.xml 中注册观察者

```xml
<event name="Weline_Seo::domain::subject_created">
    <observer name="Weline_YourModule::subject_created" 
              instance="Weline\YourModule\Observer\SubjectCreatedObserver" 
              disabled="false" 
              shared="true" 
              sort="10"/>
</event>
```

### 创建观察者类

```php
namespace Weline\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class SubjectCreatedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $subjectId = $data['subject_id'] ?? null;
        $subjectType = $data['subject_type'] ?? '';
        $subjectEntityId = $data['subject_entity_id'] ?? null;
        
        if (!$subjectId || !$subjectType || !$subjectEntityId) {
            return;
        }
        
        // 执行相关操作
        $this->handleSubjectCreated($subjectId, $subjectType, $subjectEntityId, $data);
    }
    
    private function handleSubjectCreated(int $subjectId, string $subjectType, int $subjectEntityId, array $data): void
    {
        // 同步到外部系统
        $this->syncToExternalSystem($subjectId, $subjectType, $data);
        
        // 记录日志
        error_log("SEO主体已创建: {$subjectType} #{$subjectId}");
    }
    
    private function syncToExternalSystem(int $subjectId, string $subjectType, array $data): void
    {
        // 实现同步逻辑
    }
}
```

## 使用示例

### 示例：创建SEO主体后触发分析任务

```php
class SubjectCreatedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $subjectId = $data['subject_id'];
        $subjectType = $data['subject_type'];
        
        // 触发关键词提取任务
        $this->triggerKeywordExtraction($subjectId, $subjectType);
        
        // 触发SEO分析任务
        $this->triggerSeoAnalysis($subjectId, $subjectType);
    }
    
    private function triggerKeywordExtraction(int $subjectId, string $subjectType): void
    {
        // 实现关键词提取任务触发逻辑
    }
    
    private function triggerSeoAnalysis(int $subjectId, string $subjectType): void
    {
        // 实现SEO分析任务触发逻辑
    }
}
```

## 注意事项

- SEO主体已保存到数据库
- 事件数据包含完整的主体信息
- 建议在观察者中进行异步操作，避免阻塞主流程
- 如果操作失败，应该记录日志而不是抛出异常

## 相关事件

- `Weline_Seo::domain::subject_updated` - SEO主体更新
- `Weline_Seo::domain::keywords_extracted` - 关键词提取完成
