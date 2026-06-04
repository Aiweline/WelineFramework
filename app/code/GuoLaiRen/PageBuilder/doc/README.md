# PageBuilder 文档中心

本文是 `GuoLaiRen_PageBuilder` 模块的文档入口。当前目录中混有现行契约、架构计划、功能说明、修复记录和历史样例；第一轮整理先建立索引和归类规则，不批量移动既有文件，避免破坏已有文档链接和测试提示。

## 阅读顺序

1. 模块入口：[../README.md](../README.md)
2. 长期规范：[../docs/TEMPLATE_SPEC.md](../docs/TEMPLATE_SPEC.md)、[../docs/COMPONENT_SPEC.md](../docs/COMPONENT_SPEC.md)
3. AI 运行模式：[../docs/AI_RUNTIME_MODE_SPEC.md](../docs/AI_RUNTIME_MODE_SPEC.md)
4. AI 建站主线：[PageBuilder_AI建站工作台重构计划_v2.2_Codex开发版.md](PageBuilder_AI建站工作台重构计划_v2.2_Codex开发版.md)
5. SSE 契约：[SSE-契约清单.md](SSE-契约清单.md)
6. 表结构升级规范：[../开发文档.md](../开发文档.md)
7. 架构变更记录：[../CHANGELOG-重构日志.md](../CHANGELOG-重构日志.md)

## 文档边界

| 位置 | 用途 | 维护规则 |
| --- | --- | --- |
| `../README.md` | 模块入口、快速启动、当前能力概览 | 只放入口信息，不继续追加长篇实现细节 |
| `../docs/` | 长期有效的开发规范和契约规格 | 优先放稳定规范，避免放一次性修复报告 |
| `./` | 历史文档、功能说明、架构计划、修复记录 | 新文档必须先按下面分类命名 |
| `../开发文档.md` | 数据库表结构升级规范 | 涉及表结构变更时同步阅读 |
| `../CHANGELOG-重构日志.md` | 跨版本架构变更记录 | 只记录高层变更，不放调试过程 |

## 现行契约与架构

这些文档代表当前重构、契约或运行时行为，改代码前优先检查：

| 文档 | 主题 |
| --- | --- |
| [PageBuilder_AI建站工作台重构计划_v2.2_Codex开发版.md](PageBuilder_AI建站工作台重构计划_v2.2_Codex开发版.md) | AI 建站工作台主架构与 PlanJson |
| [../docs/AI_RUNTIME_MODE_SPEC.md](../docs/AI_RUNTIME_MODE_SPEC.md) | AI 运行模式，明确 fake 模式只替代真实 AI 调用，不跳过完整逻辑 |
| [PR需求-PageBuilder-AI智能建站强契约与整站视觉验收.md](PR需求-PageBuilder-AI智能建站强契约与整站视觉验收.md) | 强契约与整站视觉验收需求 |
| [PageBuilder_AI建站_13项门禁与Prompt对照表.md](PageBuilder_AI建站_13项门禁与Prompt对照表.md) | 质量门禁与 Prompt 契约映射 |
| [SSE-契约清单.md](SSE-契约清单.md) | AI 工作台 SSE 事件契约 |
| [AI建站工作台-前端强契约并发任务.md](AI建站工作台-前端强契约并发任务.md) | 前端并发任务与状态契约 |
| [AI建站工作台强契约前端状态矩阵.md](AI建站工作台强契约前端状态矩阵.md) | 工作台前端状态矩阵 |
| [PageBuilder_第一阶段强契约流程图.md](PageBuilder_第一阶段强契约流程图.md) | 第一阶段流程 |
| [PageBuilder_第二阶段强契约并发构建流程图.md](PageBuilder_第二阶段强契约并发构建流程图.md) | 第二阶段并发构建流程 |
| [PageBuilder_第三阶段强契约发布流程图.md](PageBuilder_第三阶段强契约发布流程图.md) | 第三阶段发布流程 |
| [PageBuilder_AI建站块级并发与图片插槽架构.md](PageBuilder_AI建站块级并发与图片插槽架构.md) | 块级并发与图片插槽 |
| `PageBuilder_AI建站_懒加载租借存储架构.md` | 懒加载租借存储；当前工作区是未跟踪草稿，确认纳入版本后再转为正式链接 |
| [ai-site-asset-block-cache.md](ai-site-asset-block-cache.md) | AI 站点资源块缓存 |
| [PageBuilder-块微调智能体API.md](PageBuilder-块微调智能体API.md) | 块级微调智能体 API |

## 模板、页面与渲染

| 文档 | 主题 |
| --- | --- |
| [../docs/TEMPLATE_SPEC.md](../docs/TEMPLATE_SPEC.md) | 模板开发规范 |
| [../docs/COMPONENT_SPEC.md](../docs/COMPONENT_SPEC.md) | 组件开发规范 |
| [前端页面渲染架构.md](前端页面渲染架构.md) | 前台渲染架构 |
| [PageBuilder-页面配置与跟踪代码.md](PageBuilder-页面配置与跟踪代码.md) | 页面配置和跟踪代码 |
| [PageBuilder-Header配置说明.md](PageBuilder-Header配置说明.md) | Header 配置 |
| [可视化配置功能使用指南.md](可视化配置功能使用指南.md) | 可视化配置 |
| [可视化编辑器-响应式配置.md](可视化编辑器-响应式配置.md) | 可视化编辑器响应式配置 |
| [响应式配置格式使用指南.md](响应式配置格式使用指南.md) | 响应式配置格式 |
| [响应式配置-快速参考.md](响应式配置-快速参考.md) | 响应式配置速查 |
| [像素统计配置说明.md](像素统计配置说明.md) | 像素统计配置 |
| [页面管理-更多操作菜单.md](页面管理-更多操作菜单.md) | 页面管理更多操作 |

## 多语言与 Local 标签

| 文档 | 主题 |
| --- | --- |
| [i18n-translation-guide.md](i18n-translation-guide.md) | PageBuilder 多语言翻译指南 |
| [local-tag-usage-guide.md](local-tag-usage-guide.md) | Local 标签使用 |
| [local-tag-translation-guide.md](local-tag-translation-guide.md) | Local 标签翻译 |
| [backend-field-translation-guide.md](backend-field-translation-guide.md) | 后台字段翻译 |
| [translation-quick-reference.md](translation-quick-reference.md) | 翻译速查 |
| [PageBuilder-多语言配置系统.md](PageBuilder-多语言配置系统.md) | 多语言配置系统 |
| [多语言默认语言改进.md](多语言默认语言改进.md) | 默认语言改进 |
| [多语言默认语言-测试指南.md](多语言默认语言-测试指南.md) | 默认语言测试 |

## AI 内容生成

| 文档 | 主题 |
| --- | --- |
| [AI一键生成内容使用指南.md](AI一键生成内容使用指南.md) | AI 一键生成内容 |
| [智能体组件生成计划.md](智能体组件生成计划.md) | 智能体组件生成计划 |
| [智能体组件生成-真实组件学习与一次性生成计划.md](智能体组件生成-真实组件学习与一次性生成计划.md) | 真实组件学习与一次性生成 |
| [计划-AI组件生成代码整理.md](计划-AI组件生成代码整理.md) | AI 组件生成代码整理 |
| [故障排查-预览生成失败.md](故障排查-预览生成失败.md) | 预览生成失败排查 |

## 表单、SEO 与扩展集成

| 文档 | 主题 |
| --- | --- |
| [完整解决方案-表单提交与配置.md](完整解决方案-表单提交与配置.md) | 表单提交与配置完整方案 |
| [总结-表单提交完整解决方案.md](总结-表单提交完整解决方案.md) | 表单提交方案总结 |
| [PageBuilder-表单布局优化说明.md](PageBuilder-表单布局优化说明.md) | 表单布局优化 |
| [Sitemap配置与使用指南.md](Sitemap配置与使用指南.md) | Sitemap 配置 |
| [Sitemap快速参考.md](Sitemap快速参考.md) | Sitemap 速查 |
| [Sitemap自动生成与SEO集成.md](Sitemap自动生成与SEO集成.md) | Sitemap 自动生成与 SEO |
| [Sitemap-Nginx配置修复.md](Sitemap-Nginx配置修复.md) | Sitemap Nginx 配置修复 |
| [Extends衍生功能集成总结.md](Extends衍生功能集成总结.md) | extends/module 集成总结 |

## 模板专题与样例

| 文档 | 主题 |
| --- | --- |
| [marketing-landing-模板说明.md](marketing-landing-模板说明.md) | marketing-landing 模板说明 |
| [marketing-landing-快速启动.md](marketing-landing-快速启动.md) | marketing-landing 快速启动 |
| [CHANGELOG-EDITORJS.md](CHANGELOG-EDITORJS.md) | Editor.js 变更记录 |
| [README-EXAMPLES.md](README-EXAMPLES.md) | 示例说明 |
| [example-money-calendar-page.json](example-money-calendar-page.json) | 示例页面 JSON |

## 功能记录

这些文档多为单次功能落地记录，后续可逐步合并到长期规范或归档：

| 文档 | 主题 |
| --- | --- |
| [功能-CTA转化事件跟踪.md](功能-CTA转化事件跟踪.md) | CTA 转化事件跟踪 |
| [功能-卡片式样式选择器.md](功能-卡片式样式选择器.md) | 卡片式样式选择器 |
| [功能-图片容器三端宽度配置.md](功能-图片容器三端宽度配置.md) | 图片容器三端宽度 |
| [功能-样式切换自动保存.md](功能-样式切换自动保存.md) | 样式切换自动保存 |
| [功能-样式卡片预览按钮.md](功能-样式卡片预览按钮.md) | 样式卡片预览 |
| [功能-表单异步提交和跳转.md](功能-表单异步提交和跳转.md) | 表单异步提交和跳转 |
| [功能-页面句柄唯一性实时检查.md](功能-页面句柄唯一性实时检查.md) | 页面句柄唯一性检查 |
| [更新日志-静态资源本地化.md](更新日志-静态资源本地化.md) | 静态资源本地化 |

## 修复与测试记录

这些文档主要用于追溯历史问题。新代码不要只依赖这些记录，应优先看现行契约和源码。

| 文档 | 主题 |
| --- | --- |
| [修复-AI建站非目标语言可见文案门禁.md](修复-AI建站非目标语言可见文案门禁.md) | 非目标语言文案门禁 |
| [修复-API路由映射错误.md](修复-API路由映射错误.md) | API 路由映射 |
| [修复-Logo位置配置问题.md](修复-Logo位置配置问题.md) | Logo 位置配置 |
| [修复-marketing-landing-iPad平板端优化.md](修复-marketing-landing-iPad平板端优化.md) | iPad 平板端优化 |
| [修复-marketing-landing-Logo-URL解析错误.md](修复-marketing-landing-Logo-URL解析错误.md) | Logo URL 解析 |
| [修复-marketing-landing布局和样式优化.md](修复-marketing-landing布局和样式优化.md) | 布局和样式 |
| [修复-marketing-landing样式模板识别.md](修复-marketing-landing样式模板识别.md) | 样式模板识别 |
| [修复-marketing-landing移动端优化.md](修复-marketing-landing移动端优化.md) | 移动端优化 |
| [修复-marketing-landing表单样式.md](修复-marketing-landing表单样式.md) | 表单样式 |
| [修复-删除右侧重复的样式选择器.md](修复-删除右侧重复的样式选择器.md) | 删除重复样式选择器 |
| [修复-单位显示和帮助信息展开.md](修复-单位显示和帮助信息展开.md) | 单位显示和帮助信息 |
| [修复-可视化编辑器配置项显示优化.md](修复-可视化编辑器配置项显示优化.md) | 可视化编辑器配置项显示 |
| [修复-样式配置无法加载.md](修复-样式配置无法加载.md) | 样式配置加载 |
| [修复-表单提交路由错误.md](修复-表单提交路由错误.md) | 表单提交路由 |
| [修复-表单模型字段缺失.md](修复-表单模型字段缺失.md) | 表单模型字段 |
| [补充修复-分组级别帮助信息显示.md](补充修复-分组级别帮助信息显示.md) | 分组帮助信息 |
| [测试-CTA转化事件跟踪.md](测试-CTA转化事件跟踪.md) | CTA 测试 |
| [测试-可视化编辑器配置显示.md](测试-可视化编辑器配置显示.md) | 可视化配置显示测试 |

## 开发临时记录

| 文档 | 主题 |
| --- | --- |
| [开发/plan.md](开发/plan.md) | 临时开发计划 |
| [开发/task.md](开发/task.md) | 临时开发任务 |

## 新文档命名规则

新文档不要继续随意命名。按用途选择前缀：

| 类型 | 命名格式 | 示例 |
| --- | --- | --- |
| 长期规范 | 放入 `../docs/`，使用英文大写规格名 | `../docs/TEMPLATE_SPEC.md` |
| 当前架构 | `架构-领域-主题.md` | `架构-AI建站-PlanJson.md` |
| 契约 | `契约-领域-主题.md` | `契约-AI建站-SSE事件.md` |
| 功能说明 | `功能-领域-主题.md` | `功能-页面-自动保存.md` |
| 排障 | `排障-领域-问题.md` | `排障-AI建站-预览失败.md` |
| 修复记录 | `修复-领域-问题.md` | `修复-页面-路由映射错误.md` |
| 测试记录 | `测试-领域-主题.md` | `测试-页面-CTA事件.md` |

## 后续整理计划

1. 第二轮：把修复记录和测试记录移入 `doc/archive/`，并同步内部链接。
2. 第三轮：把仍有效的功能说明合并到 `../docs/` 的长期规范。
3. 第四轮：把 AI 建站文档拆成 `contract`、`workflow`、`quality`、`runtime` 四组。
4. 第五轮：检查根 README 的既有结构描述，改为只保留模块入口和文档导航。
