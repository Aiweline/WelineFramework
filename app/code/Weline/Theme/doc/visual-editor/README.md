# 可视化编辑器架构文档

> 版本: 2.1.0
> 更新时间: 2026-01-28

## 概述

可视化编辑器是一个用于构建和编辑页面的可视化工具，支持组件拖拽、嵌套放置、实时预览等功能。

## 核心设计

### 1. 区域系统

页面分为三个顶级区域：

| 区域 | 说明 | 组件限制 | 嵌套支持 |
|------|------|----------|----------|
| `header` | 页面头部 | 仅接受 `header` 类别 | 支持 slot 嵌套 |
| `content` | 页面内容 | 接受 `content`、`widget` 类别 | 支持多组件、支持 slot 嵌套 |
| `footer` | 页面底部 | 仅接受 `footer` 类别 | 支持 slot 嵌套 |

### 2. 组件分类

每个组件都有一个 `category` 属性，决定它可以被放置的区域：

- `header`: 头部组件
- `content`: 内容组件
- `footer`: 底部组件
- `widget`: 小部件（可放入 content 区域）

### 3. Slot 嵌套系统

组件可以定义 `slots`，允许其他组件嵌入：

```json
{
  "slots": {
    "items": {
      "name": "项目",
      "accepts": ["content", "widget"],
      "slot_type": "faq-item",
      "max": 30
    }
  }
}
```

关键规则：
- slot 的 `accepts` 必须是父组件所在区域接受的类别的子集
- 嵌套组件跟随父组件移动
- slot 可以设置 `max` 限制最大数量

## 相关文档

- [区域隔离规则](./region-isolation.md)
- [Slot 嵌套规则](./slot-nesting-rules.md)
- [局部刷新机制](./partial-refresh.md)
- [组件库筛选](./component-library-filtering.md)
- [component.json 规范](./component-json-spec.md)

## 相关服务

| 服务 | 路径 | 说明 |
|------|------|------|
| `SlotValidator` | `GuoLaiRen\PageBuilder\Service\Component\SlotValidator` | 组件放置验证 |
| `ComponentRenderer` | `GuoLaiRen\PageBuilder\Service\Component\ComponentRenderer` | 单组件渲染（局部刷新） |
| `ComponentResolver` | `GuoLaiRen\PageBuilder\Service\Component\ComponentResolver` | 组件解析 |

## API 端点

| 端点 | 方法 | 说明 |
|------|------|------|
| `/backend/visual/api/component/validate` | POST | 验证组件是否可放置 |
| `/backend/visual/api/component/compatible` | GET | 获取兼容组件列表 |
| `/backend/visual/api/component/slots` | GET | 获取组件的 slots 定义 |
| `/backend/visual/api/component/add` | POST | 添加组件（支持局部刷新） |
