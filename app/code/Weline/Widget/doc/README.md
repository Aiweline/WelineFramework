# Widget 模块文档索引

## AI / 开发入口

开发或修改 Widget、可视化编辑器部件、`w:widget`、slot 注入、部件查询事件前，先读：

1. [AI-INDEX.md](./AI-INDEX.md)
2. [../Theme/doc/AI-INDEX.md](../../Theme/doc/AI-INDEX.md)
3. [../Theme/doc/theme-inheritance-and-file-conventions.md](../../Theme/doc/theme-inheritance-and-file-conventions.md)
4. [../Theme/doc/部件开发指南.md](../../Theme/doc/部件开发指南.md)
5. [../Theme/doc/widget-slot-attributes.md](../../Theme/doc/widget-slot-attributes.md)

当前推荐注册路径是 `app/code/{Vendor}/{Module}/extends/module/Weline_Widget/{ModuleName}/widget.php`。旧式 `extends/Weline_Widget/...` 只作为兼容扫描存在，不要作为新开发首选。

## 公开 PHP 边界

跨模块 PHP 代码只能引用 `Weline\Widget\Api\*`。编辑器的数据注册表使用
`WidgetRegistryInterface`；参数定义/表单能力使用 `WidgetConfigService`；安全预览渲染使用
`WidgetPreviewService`。后两者是一版命名空间迁移的精确运行时别名，保持旧
`Service` 类对象、构造参数和扩展类容。新模块不得再直接引用
`Weline\Widget\Service\*`。

Theme/编辑器需要参数表单时使用 `Api\Param\ParamFormRendererInterface`，需要判断参数
是否可翻译时使用纯函数 `Api\Param\ParamDefinition::isTranslatable()`。运行期渲染内联
Widget 模板使用 `Api\Rendering\RuntimeTemplateRendererInterface`。三个边界只交换字符串、
标量和数组；参数 UI 类型类、`ParamTypeRenderer` 与 `WidgetRuntimeTemplateRenderer` 都是
Widget 内部实现，由模块 `provides` 编译注册。

Widget 向 Ai 模块发布的 Agent 扩展只使用 `Weline\Ai\Api\AgentInterface`、
`AgentResult`、`AiModel` 与 `AgentModelExecutorInterface`。供应商工厂和具体
Provider 属于 Ai 内部实现，不得在 Widget 扩展中查找或注入。

AI Widget 生成服务仅注入 `Weline\Ai\Api\AiRuntimeInterface`，并通过其
`executeAgent()` 公开契约执行 `widget_builder`。Widget 不得引用
`Weline\Ai\Service\AiService` 或其他 Ai 内部类。

## 依赖清单

Ai、Backend、Extends、Framework、Meta 与 Taglib 是当前 Widget 装配的必需依赖。
`WidgetQueryProvider` 固定装配 AI 生成服务，因此 Ai 仍是 `requires`；装配时由
`AiRuntimeInterfaceFactory` 解析具体实现，缺失必需依赖必须在编译/启动预检阶段失败。

## 📚 文档目录

### 用户文档
- [快速开始指南](./快速开始.md) - 5分钟快速上手可视化编辑器
- [使用手册](./使用手册.md) - 完整的功能使用说明

### 开发文档
- [开发指南](./开发指南.md) - 如何创建自定义部件
- [API参考](./API参考.md) - 完整的API文档
- [部件开发规范](./部件开发规范.md) - 部件开发的最佳实践
- [单事件查询 API 与架构](./单事件查询API与架构.md) - 对外事件 `Weline_Widget::query` 约定与架构图

### 参考文档
- [部件类型列表](./部件类型列表.md) - 支持的部件类型说明
- [常见问题](./常见问题.md) - FAQ和故障排查

## 🚀 快速导航

### 我是用户，想使用可视化编辑器
👉 [快速开始指南](./快速开始.md)

### 我是开发者，想创建自定义部件
👉 [开发指南](./开发指南.md)

### 我需要查看API文档
👉 [API参考](./API参考.md)

### 我需要在 Theme/其他模块中通过事件获取部件列表、配置、预览
👉 [单事件查询 API 与架构](./单事件查询API与架构.md)

### 我遇到了问题
👉 [常见问题](./常见问题.md)

## 📖 模块概述

Widget 模块是一个强大的后端可视化编辑器，允许您通过拖放方式组织页面，使用 `w:widget` 标签存储和渲染部件。

### 核心特性

- ✅ **可视化编辑** - 拖放式页面编辑器
- ✅ **部件系统** - 可扩展的部件架构
- ✅ **实时预览** - 即时查看编辑效果
- ✅ **参数配置** - 灵活的部件参数系统
- ✅ **标签渲染** - 使用 `w:widget` 标签存储和渲染

### 技术栈

- PHP 8.0+
- Weline Framework
- JavaScript (ES6+)
- HTML5 Drag & Drop API
- Bootstrap 5

## 🔗 相关链接

- [模块 README](../README.md) - 模块完整说明
- [扩展点文档](../extends.md) - 扩展点使用说明
- [框架文档](../../Framework/doc/README.md) - Weline Framework 文档
