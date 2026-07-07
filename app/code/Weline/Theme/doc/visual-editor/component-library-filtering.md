# 组件库智能筛选

## 概述

当用户选中某个区域或 slot 时，组件库会自动筛选出可放置的组件，并滚动到相关位置。

## 筛选规则

### 1. 区域筛选

当选中 header 区域时：
- 筛选出 `placeable_in` 包含 `header` 的组件
- 滚动组件库到头部组件分类

### 2. Slot 筛选

当选中某组件的 slot 时：
- 获取 slot 的 `accepts` 配置
- 筛选出类别匹配的组件
- 如果 slot 有 `slot_type`，进一步筛选 `compatible_slot_types` 匹配的组件

## API 端点

### 获取兼容组件

```
GET /backend/visual/api/component/compatible?region=content&style_code=tpmst
```

响应：

```json
{
    "success": true,
    "type": "region",
    "region": "content",
    "accepts": ["content", "widget"],
    "components": ["slider", "banner", "advantages", "games"]
}
```

### 获取 Slot 兼容组件

```
GET /backend/visual/api/component/compatible?parent_component_code=faq&slot=items&style_code=tpmst
```

响应：

```json
{
    "success": true,
    "type": "slot",
    "slot_name": "items",
    "slot_type": "faq-item",
    "accepts": ["content", "widget"],
    "components": ["faq-item-widget"]
}
```

## 前端实现

### 选中事件处理

```javascript
// 选中区域
document.querySelectorAll('[data-region]').forEach(region => {
    region.addEventListener('click', (e) => {
        const regionName = region.dataset.region;
        filterComponentLibrary({ type: 'region', region: regionName });
    });
});

// 选中 Slot
document.querySelectorAll('[data-slot]').forEach(slot => {
    slot.addEventListener('click', (e) => {
        const slotName = slot.dataset.slot;
        const parentCode = slot.closest('[data-component]').dataset.component;
        filterComponentLibrary({ 
            type: 'slot', 
            parentComponent: parentCode,
            slot: slotName 
        });
    });
});
```

### 筛选组件库

```javascript
async function filterComponentLibrary(target) {
    const params = new URLSearchParams({
        style_code: styleCode,
        ...(target.type === 'region' 
            ? { region: target.region }
            : { 
                parent_component_code: target.parentComponent,
                slot: target.slot 
              }
        )
    });
    
    const data = await Weline.Api.get('/backend/visual/api/component/compatible', Object.fromEntries(params));
    
    if (data.success) {
        highlightCompatibleComponents(data.components);
        scrollComponentLibraryToTop(target.type === 'region' ? target.region : data.slot_type);
    }
}

function highlightCompatibleComponents(componentCodes) {
    // 隐藏所有组件
    document.querySelectorAll('.component-library-item').forEach(item => {
        item.classList.add('filtered-out');
    });
    
    // 显示兼容组件
    componentCodes.forEach(code => {
        const item = document.querySelector(`.component-library-item[data-code="${code}"]`);
        if (item) {
            item.classList.remove('filtered-out');
        }
    });
    
    // 更新计数
    updateComponentCount(componentCodes.length);
}

function scrollComponentLibraryToTop(category) {
    const categoryHeader = document.querySelector(`.component-category[data-category="${category}"]`);
    if (categoryHeader) {
        categoryHeader.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}
```

### 清除筛选

```javascript
function clearComponentFilter() {
    document.querySelectorAll('.component-library-item').forEach(item => {
        item.classList.remove('filtered-out');
    });
    document.querySelector('.component-library').scrollTop = 0;
}

// 点击空白区域时清除筛选
document.addEventListener('click', (e) => {
    if (!e.target.closest('[data-region]') && !e.target.closest('[data-slot]')) {
        clearComponentFilter();
    }
});
```

## UI 样式

```css
/* 被筛选掉的组件 */
.component-library-item.filtered-out {
    display: none;
}

/* 兼容组件高亮 */
.component-library-item.compatible {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 2px rgba(var(--primary-rgb), 0.2);
}

/* 筛选提示 */
.component-filter-hint {
    padding: 8px 12px;
    background: var(--info-bg);
    border-radius: 4px;
    margin-bottom: 12px;
}

.component-filter-hint .clear-btn {
    float: right;
    cursor: pointer;
    color: var(--link-color);
}
```

## 用户体验

1. **视觉反馈**: 选中区域/slot 时高亮显示
2. **平滑滚动**: 自动滚动到相关分类
3. **计数显示**: 显示兼容组件数量
4. **清除提示**: 显示"清除筛选"按钮
5. **拖拽提示**: 拖拽到不兼容区域时显示禁止图标
