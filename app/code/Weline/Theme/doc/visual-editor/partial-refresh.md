# 局部刷新机制

## 概述

局部刷新机制在添加、移动、删除组件时，只更新受影响的 DOM 区域，避免全页面刷新。

## 工作流程

### 1. 添加组件

```
用户拖拽组件 -> 验证放置规则 -> 保存布局 -> 渲染单个组件 -> 插入 DOM
```

#### API 请求

```javascript
POST /backend/visual/api/component/add
{
    "page_id": 123,
    "component_code": "faq",
    "region": "content",
    "position": 2,
    "return_html": true  // 请求返回渲染的 HTML
}
```

#### API 响应

```javascript
{
    "success": true,
    "instance_id": "comp-abc123",
    "component_html": "<div class=\"vb-component\">...</div>",
    "position": 2,
    "partial": true  // 标识为局部更新
}
```

### 2. 前端 DOM 操作

```javascript
function insertComponentDOM(response) {
    const { instance_id, component_html, position, region } = response;
    
    // 创建临时容器
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = component_html;
    const componentElement = tempDiv.firstElementChild;
    
    // 找到目标容器
    const container = document.querySelector(`[data-region="${region}"]`);
    const children = container.querySelectorAll('.vb-component');
    
    // 插入到指定位置
    if (position < children.length) {
        container.insertBefore(componentElement, children[position]);
    } else {
        container.appendChild(componentElement);
    }
    
    // 初始化拖拽功能
    initComponentDraggable(componentElement);
}
```

## ComponentRenderer 服务

后端 `ComponentRenderer` 服务负责渲染单个组件：

```php
// GuoLaiRen\PageBuilder\Service\Component\ComponentRenderer

$renderer = ComponentRenderer::getInstance();
$result = $renderer->renderSingle(
    'faq',                // 组件代码
    'comp-abc123',        // 实例ID
    'tpmst',              // 模板代码
    $config,              // 组件配置
    [
        'region' => 'content',
        'index' => 2,
        'visual_mode' => true,  // 可视化编辑模式
        'page' => $page,
    ]
);

if ($result->isSuccess()) {
    $html = $result->getHtml();
}
```

## 组件 HTML 结构

可视化编辑模式下的组件包装结构：

```html
<div class="vb-component pb-component" 
     data-instance-id="comp-abc123"
     data-component="faq" 
     data-region="content" 
     data-index="2" 
     data-style-code="tpmst"
     data-has-children="true">
    <div class="vb-component-header">
        <span class="vb-component-name">
            <i class="mdi mdi-drag component-drag-handle"></i>
            faq
        </span>
        <div class="vb-component-actions">
            <button class="btn btn-sm btn-link" onclick="VB.editComponent('comp-abc123')">
                <i class="mdi mdi-pencil"></i>
            </button>
            <button class="btn btn-sm btn-link text-danger" onclick="VB.removeComponent('comp-abc123')">
                <i class="mdi mdi-delete"></i>
            </button>
        </div>
    </div>
    <div class="vb-component-content">
        <!-- 组件实际内容 -->
    </div>
</div>
```

## 性能优化

1. **按需渲染**: 只渲染新增/变更的组件
2. **DOM 复用**: 移动时直接操作现有 DOM 节点
3. **批量更新**: 多个操作合并为一次 DOM 更新
4. **占位符**: 添加时先显示加载占位符

```javascript
// 生成占位符
function generatePlaceholder(instanceId, region) {
    return `<div class="vb-component vb-component-loading" 
                 data-instance-id="${instanceId}"
                 data-region="${region}">
        <div class="vb-component-loading-spinner">
            <i class="mdi mdi-loading mdi-spin"></i>
            <span>加载中...</span>
        </div>
    </div>`;
}
```

## 错误处理

```javascript
async function addComponent(componentCode, region, position) {
    try {
        const response = await fetch('/backend/visual/api/component/add', {
            method: 'POST',
            body: JSON.stringify({
                page_id: pageId,
                component_code: componentCode,
                region: region,
                position: position,
                return_html: true
            })
        });
        
        const data = await response.json();
        
        if (data.success && data.partial) {
            insertComponentDOM(data);
        } else if (data.validation_failed) {
            showValidationError(data.message);
        } else {
            // 回退到全量刷新
            refreshPage();
        }
    } catch (error) {
        console.error('添加组件失败:', error);
        refreshPage();
    }
}
```
