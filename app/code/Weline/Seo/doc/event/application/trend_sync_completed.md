# Weline_Seo::application::trend_sync_completed - SEO趋势数据同步完成

## 事件说明

当趋势数据同步任务完成时触发，允许其他模块监听趋势同步结果。

## 事件类型

**Application Event（应用事件）** - 应用层事件

## 触发时机

在趋势数据同步任务完成后触发，通常在 `TrendSyncService::sync()` 方法中。

## 数据格式

```php
[
    'platform' => string,             // 必需：平台（google, baidu等）
    'keyword_count' => int,           // 必需：处理的关键词数量
    'trend_count' => int,             // 必需：保存的趋势数据数量
    'error_count' => int,             // 可选：错误数量
]
```

## 可用数据

### 必需字段

- `platform` (string) - 平台：google, baidu等
- `keyword_count` (integer) - 处理的关键词数量
- `trend_count` (integer) - 保存的趋势数据数量

### 可选字段

- `error_count` (integer) - 错误数量

## 使用场景

- 监听趋势同步结果，执行相关操作
- 记录趋势同步日志
- 同步趋势数据到外部系统
- 触发趋势分析报告生成

## 使用方法

### 在 event.xml 中注册观察者

```xml
<event name="Weline_Seo::application::trend_sync_completed">
    <observer name="Weline_YourModule::trend_sync_completed" 
              instance="Weline\YourModule\Observer\TrendSyncCompletedObserver" 
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

class TrendSyncCompletedObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        $platform = $data['platform'] ?? '';
        $keywordCount = $data['keyword_count'] ?? 0;
        $trendCount = $data['trend_count'] ?? 0;
        $errorCount = $data['error_count'] ?? 0;
        
        if (!$platform) {
            return;
        }
        
        // 执行相关操作
        $this->handleTrendSyncCompleted($platform, $keywordCount, $trendCount, $errorCount);
    }
    
    private function handleTrendSyncCompleted(string $platform, int $keywordCount, int $trendCount, int $errorCount): void
    {
        // 记录同步日志
        $this->logTrendSync($platform, $keywordCount, $trendCount, $errorCount);
        
        // 同步趋势数据到外部系统
        $this->syncTrendDataToExternalSystem($platform, $trendCount);
        
        // 如果有错误，发送告警
        if ($errorCount > 0) {
            $this->sendAlert($platform, $errorCount);
        }
        
        // 触发趋势分析报告生成
        $this->triggerTrendReportGeneration($platform);
    }
    
    private function logTrendSync(string $platform, int $keywordCount, int $trendCount, int $errorCount): void
    {
        error_log("趋势数据同步完成: 平台={$platform}, 关键词数={$keywordCount}, 趋势数={$trendCount}, 错误数={$errorCount}");
    }
    
    private function syncTrendDataToExternalSystem(string $platform, int $trendCount): void
    {
        // 实现同步逻辑
    }
    
    private function sendAlert(string $platform, int $errorCount): void
    {
        // 实现告警逻辑
    }
    
    private function triggerTrendReportGeneration(string $platform): void
    {
        // 实现报告生成逻辑
    }
}
```

## 注意事项

- 趋势数据已同步到数据库
- `keyword_count` 表示处理的关键词总数
- `trend_count` 表示成功保存的趋势数据数量
- `error_count` 表示同步过程中的错误数量
- 建议在观察者中进行异步操作，避免阻塞主流程

## 相关事件

- `Weline_Seo::integration::task_completed` - SEO任务处理完成
- `Weline_Seo::domain::keywords_extracted` - 关键词提取完成
