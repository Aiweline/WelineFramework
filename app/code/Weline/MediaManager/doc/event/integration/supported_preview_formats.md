# Weline_MediaManager::integration::supported_preview_formats - 支持的预览格式

## 事件说明

允许其他模块注册可预览的文件格式。通过此事件，第三方模块可以扩展媒体管理器支持的预览类型，例如添加 PDF 预览、视频封面等。

## 事件类型

**Integration Event（集成事件）** - 跨模块/系统的事件

## 触发时机

在 `ThumbnailService::getSupportedFormats()` 方法中触发，当首次获取支持的预览格式列表时。

## 数据格式

```php
[
    'data' => [
        'formats' => &$formats,  // 必需：MIME 类型数组（引用传递）
    ]
]
```

## 可用数据

### 必需字段

- `formats` (array) - MIME 类型数组，可向其中追加新格式

### 默认支持格式

```php
[
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'image/bmp',
    'image/x-icon',
    'image/tiff',
    'image/avif',
]
```

## 使用场景

- 添加 PDF 文件预览支持
- 添加视频文件封面预览
- 添加 Office 文档预览
- 添加 SVG 矢量图预览
- 添加其他自定义格式预览

## 使用方法

### 在 event.xml 中注册观察者

```xml
<event name="Weline_MediaManager::integration::supported_preview_formats">
    <observer name="YourModule::add_preview_formats" 
              instance="YourVendor\YourModule\Observer\AddPreviewFormats" 
              disabled="false" 
              shared="true" 
              sort="10"/>
</event>
```

### 创建观察者类

```php
namespace YourVendor\YourModule\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class AddPreviewFormats implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!isset($data['formats']) || !\is_array($data['formats'])) {
            return;
        }
        
        $formats = &$data['formats'];
        
        // 添加 PDF 预览支持
        $formats[] = 'application/pdf';
        
        // 添加视频封面支持
        $formats[] = 'video/mp4';
        $formats[] = 'video/webm';
    }
}
```

## 使用示例

### 示例：添加 PDF 预览

```php
class PdfPreviewObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (isset($data['formats'])) {
            $data['formats'][] = 'application/pdf';
        }
    }
}
```

## 注意事项

- `formats` 是引用传递，直接修改数组即可
- 添加新格式后，还需要确保 `ThumbnailService` 能够处理该格式
- 对于非图片格式，可能需要自定义预览处理器
- 避免添加不安全的文件类型

## 相关事件

- `Weline_MediaManager::domain::file_upload_after` - 文件上传后，可用于生成自定义预览
