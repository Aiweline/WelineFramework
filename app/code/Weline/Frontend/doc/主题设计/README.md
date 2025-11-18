# Weline Frontend 主题设计文档

## 概述

本文档目录包含 Weline Frontend 模块的主题设计相关文档，包括配色系统、目录结构、组件规范等。

## 文档列表

### 核心文档

#### 1. [配色系统设计规范](./配色.md)
- 设计理念和核心原则
- 配色方案和变量定义
- CSS变量系统使用规范
- 主题切换机制
- 最佳实践和扩展指南

#### 2. [主题目录结构](./主题目录结构.md)
- `view/theme/` 目录结构说明
- 各子目录的作用和使用方式
- 加载机制和引用路径
- 其他模块使用规范
- 开发规范和最佳实践

#### 3. [theme/ 目录详细说明](./theme目录详细说明.md)
- 完整的目录结构描述
- 文件关系图
- 使用流程
- 设计原则

#### 4. [变量与颜色主题区别说明](./变量与颜色主题区别说明.md)
- `variables/` 与 `colors/` 的区别
- 工作原理和覆盖机制
- 使用场景说明

### 目录文档

#### 5. [variables/ 目录文档](./variables目录文档.md)
- `_colors.css` - 颜色变量定义
- `_spacing.css` - 间距变量定义
- `_typography.css` - 字体变量定义
- `_shadows.css` - 阴影变量定义
- `_borders.css` - 边框变量定义
- 变量命名规范和使用方式

#### 6. [colors/ 目录文档](./colors目录文档.md)
- `_light.css` - 亮色主题颜色覆盖
- `_dark.css` - 暗色主题颜色覆盖
- `_amazon.css` - Amazon风格主题颜色覆盖
- 主题切换机制
- 创建新主题指南

#### 7. [components/ 目录文档](./components目录文档.md)
- `button.phtml` - 按钮组件
- `input.phtml` - 输入框组件
- `card.phtml` - 卡片组件
- `modal.phtml` - 模态框组件
- `alert.phtml` - 提示框组件
- `form-group.phtml` - 表单组组件
- 组件参数说明和使用示例

#### 8. [layouts/ 目录文档](./layouts目录文档.md)
- `default.phtml` - 默认布局
- `auth.phtml` - 认证页面布局
- `dashboard.phtml` - 仪表盘布局
- `minimal.phtml` - 极简布局
- 布局参数说明和使用方式

#### 9. [partials/ 目录文档](./partials目录文档.md)
- `header.phtml` - 头部片段
- `footer.phtml` - 底部片段
- `sidebar.phtml` - 侧边栏片段
- `breadcrumb.phtml` - 面包屑导航片段
- `pagination.phtml` - 分页片段
- 片段参数说明和使用示例

#### 10. [assets/ 目录文档](./assets目录文档.md)
- `css/theme.css` - 主题主样式
- `css/components.css` - 组件样式
- `css/utilities.css` - 工具类样式
- `js/theme.js` - Weline核心JavaScript（模块加载、API、账户管理等）
- `js/theme.js` - 主题JavaScript（主题切换、工具函数）
- `images/theme/` - 主题图片资源
- 文件加载顺序和使用方式

#### 11. [config/ 目录文档](./config目录文档.md)
- `theme.json` - 主题配置文件
- 配置结构说明
- 在PHP和JavaScript中使用配置

### 待创建文档

#### 12. [组件设计规范](./组件规范.md)（待创建）
- 组件设计原则
- 组件API规范
- 组件使用示例
- 组件扩展指南

#### 13. [主题扩展指南](./主题扩展指南.md)（待创建）
- 如何创建新主题
- 如何扩展现有主题
- 主题配置说明
- 主题切换实现

## 快速开始

### 1. 了解配色系统

阅读 [配色系统设计规范](./配色.md)，了解：
- 如何使用CSS变量
- 配色方案和变量命名
- 主题切换机制

### 2. 了解目录结构

阅读 [主题目录结构](./主题目录结构.md)，了解：
- `view/theme/` 目录的组织方式
- 如何引用主题组件和布局
- 如何扩展主题变量

### 3. 开始使用

```php
// 1. 在模板中引用主题CSS
<link rel="stylesheet" href="@static(Weline_Frontend::theme/assets/css/theme.css)">

// 2. 使用主题组件
<?= $this->fetch('Weline_Frontend::theme/components/button.phtml', [
    'text' => __('提交'),
    'type' => 'primary'
]) ?>

// 3. 使用主题布局
$this->assign('layout', 'Weline_Frontend::theme/layouts/auth.phtml');
```

## 设计原则

1. **统一性** - 所有模块使用统一的设计系统
2. **可维护性** - 通过变量和组件集中管理样式
3. **可扩展性** - 易于扩展新主题和组件
4. **语义化** - 使用语义化的变量和组件名
5. **实用性** - 参考Amazon简约风格，注重实用性

## 目录结构概览

```
app/code/Weline/Frontend/view/theme/
├── variables/      # CSS变量定义
├── colors/         # 颜色主题定义
├── components/     # 可复用组件模板
├── layouts/        # 布局模板
├── partials/       # 部分模板片段
├── assets/         # 主题静态资源
└── config/         # 主题配置
```

## 贡献指南

1. 新增变量时，更新 `配色.md` 中的配色表
2. 新增组件时，更新 `主题目录结构.md` 中的组件列表
3. 重大变更时，记录版本历史
4. 遵循命名规范和开发规范

## 相关资源

- [Weline Framework 开发文档](../../../docs/dev/开发文档.md)
- [CSS Custom Properties (MDN)](https://developer.mozilla.org/en-US/docs/Web/CSS/Using_CSS_custom_properties)
- [Amazon Design System](https://design-system.amazon.com/)

