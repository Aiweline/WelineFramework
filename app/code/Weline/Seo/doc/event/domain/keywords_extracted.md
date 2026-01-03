# Weline_Seo::domain::keywords_extracted - 关键词提取完成

## 事件说明

当关键词提取任务完成时触发，允许其他模块监听并处理提取的关键词。

## 事件类型

**Domain Event（领域事件）** - 业务领域内的事件

## 触发时机

在关键词提取任务完成后触发，通常在 `KeywordExtractorService::extractKeywords()` 方法中。

## 数据格式

```php
[
    'subject_id' => int,              // 必需：SEO主体ID
    'keywords' => array,              // 必需：提取的关键词列表
    'source' => string,                // 必需：关键词来源（extracted, ai, manual等）
    'count' => int,                    // 可选：关键词数量
]
```

## 可用数据

### 必需字段

- `subject_id` (integer) - SEO主体ID
- `keywords` (array) - 提取的关键词列表
- `source` (string) - 关键词来源：extracted, ai, manual等

### 可选字段

- `count` (integer) - 关键词数量

## 使用场景

- 监听关键词提取结果，执行相关操作
- 同步关键词到外部系统
- 记录关键词提取日志
- 触发SEO建议生成任务

## 使用方法

### 在 event.xml 中注册观察者

```xml
<event name="Weline_Seo::domain::keywords_extracted">
    <observer name="Weline_YourModule::keywords_extracted" 
              instance="Weline\YourModule\Observer\KeywordsExtractedObserver" 
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

class KeywordsExtractedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $subjectId = $data['subject_id'] ?? null;
        $keywords = $data['keywords'] ?? [];
        $source = $data['source'] ?? '';
        
        if (!$subjectId || empty($keywords)) {
            return;
        }
        
        // 执行相关操作
        $this->handleKeywordsExtracted($subjectId, $keywords, $source);
    }
    
    private function handleKeywordsExtracted(int $subjectId, array $keywords, string $source): void
    {
        // 同步关键词到外部系统
        $this->syncKeywordsToExternalSystem($subjectId, $keywords);
        
        // 触发SEO建议生成
        $this->triggerSeoSuggestionGeneration($subjectId, $keywords);
        
        // 记录日志
        $count = count($keywords);
        error_log("关键词提取完成: 主体 #{$subjectId}, 来源: {$source}, 数量: {$count}");
    }
    
    private function syncKeywordsToExternalSystem(int $subjectId, array $keywords): void
    {
        // 实现同步逻辑
    }
    
    private function triggerSeoSuggestionGeneration(int $subjectId, array $keywords): void
    {
        // 实现SEO建议生成逻辑
    }
}
```

## 使用示例

### 示例：关键词提取后触发趋势分析

```php
class KeywordsExtractedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $subjectId = $data['subject_id'];
        $keywords = $data['keywords'];
        
        // 为每个关键词触发趋势分析任务
        foreach ($keywords as $keyword) {
            $this->triggerTrendAnalysis($subjectId, $keyword);
        }
    }
    
    private function triggerTrendAnalysis(int $subjectId, string $keyword): void
    {
        // 实现趋势分析任务触发逻辑
    }
}
```

## 注意事项

- 关键词已保存到数据库
- `keywords` 数组包含提取的关键词列表
- `source` 标识关键词的来源（自动提取、AI生成、手动添加等）
- 建议在观察者中进行异步操作，避免阻塞主流程

## 相关事件

- `Weline_Seo::domain::subject_created` - SEO主体创建
- `Weline_Seo::domain::suggestion_generated` - SEO建议生成完成
