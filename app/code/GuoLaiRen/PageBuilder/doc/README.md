# PageBuilder 文档中心

本文是 `GuoLaiRen_PageBuilder` 模块的文档入口。目录已按长期可复用内容整理，保留现行契约、架构流程、API、配置、使用说明、样例和排障文档；一次性修复报告、测试记录、临时计划和已废弃方案不再作为模块文档维护。

## 阅读顺序

1. 模块入口：[../README.md](../README.md)
2. 表结构升级规范：[../开发文档.md](../开发文档.md)
3. 架构变更记录：[../CHANGELOG-重构日志.md](../CHANGELOG-重构日志.md)
4. AI 建站强契约：[PR需求-PageBuilder-AI智能建站强契约与整站视觉验收.md](PR需求-PageBuilder-AI智能建站强契约与整站视觉验收.md)
5. AI 建站门禁：[PageBuilder_AI建站_13项门禁与Prompt对照表.md](PageBuilder_AI建站_13项门禁与Prompt对照表.md)
6. SSE 契约：[SSE-契约清单.md](SSE-契约清单.md)

## 文档维护规则

| 类型 | 维护方式 |
| --- | --- |
| 现行契约、架构、API、配置 | 保留在 `doc/`，并从本 README 建立入口 |
| 一次性修复记录、测试报告、临时计划 | 不再新增独立文档；可复用经验合并到长期文档 |
| 调试过程、临时命令、个人任务记录 | 放到任务工作区或本地记录，不进入模块长期文档 |
| 接口或行为变更 | 更新对应 API、契约或使用说明，不写流水账 |

## AI 建站契约与架构

| 文档 | 主题 |
| --- | --- |
| [PR需求-PageBuilder-AI智能建站强契约与整站视觉验收.md](PR需求-PageBuilder-AI智能建站强契约与整站视觉验收.md) | 强契约与整站视觉验收需求 |
| [PageBuilder_AI建站_13项门禁与Prompt对照表.md](PageBuilder_AI建站_13项门禁与Prompt对照表.md) | 质量门禁与 Prompt 契约映射 |
| [SSE-契约清单.md](SSE-契约清单.md) | AI 工作台 SSE 事件契约 |
| [AI建站工作台-前端强契约并发任务.md](AI建站工作台-前端强契约并发任务.md) | 前端并发任务与状态契约 |
| [AI建站工作台强契约前端状态矩阵.md](AI建站工作台强契约前端状态矩阵.md) | 工作台前端状态矩阵 |
| [PageBuilder_第一阶段强契约流程图.md](PageBuilder_第一阶段强契约流程图.md) | 第一阶段 PlanJson 生成流程 |
| [PageBuilder_第二阶段强契约并发构建流程图.md](PageBuilder_第二阶段强契约并发构建流程图.md) | 第二阶段并发构建流程 |
| [PageBuilder_第三阶段强契约发布流程图.md](PageBuilder_第三阶段强契约发布流程图.md) | 第三阶段发布流程 |
| [PageBuilder_AI建站块级并发与图片插槽架构.md](PageBuilder_AI建站块级并发与图片插槽架构.md) | 块级并发与图片插槽 |
| [PageBuilder_AI建站_懒加载租借存储架构.md](PageBuilder_AI建站_懒加载租借存储架构.md) | 懒加载与单一状态源 |
| [ai-site-asset-block-cache.md](ai-site-asset-block-cache.md) | AI 站点资源块缓存 |
| [PageBuilder-块微调智能体API.md](PageBuilder-块微调智能体API.md) | 块级微调智能体 API |

## 页面、渲染与可视化配置

| 文档 | 主题 |
| --- | --- |
| [前端页面渲染架构.md](前端页面渲染架构.md) | 前台页面渲染架构 |
| [PageBuilder-页面配置与跟踪代码.md](PageBuilder-页面配置与跟踪代码.md) | 页面配置和跟踪代码 |
| [PageBuilder-Header配置说明.md](PageBuilder-Header配置说明.md) | Header 配置 |
| [PageBuilder-自动保存调试指南.md](PageBuilder-自动保存调试指南.md) | 自动保存排查 |
| [可视化配置功能使用指南.md](可视化配置功能使用指南.md) | 可视化配置 |
| [可视化编辑器-响应式配置.md](可视化编辑器-响应式配置.md) | 响应式配置界面 |
| [响应式配置格式使用指南.md](响应式配置格式使用指南.md) | 响应式配置格式 |
| [响应式配置-快速参考.md](响应式配置-快速参考.md) | 响应式配置速查 |
| [像素统计配置说明.md](像素统计配置说明.md) | 像素统计配置 |
| [页面管理-更多操作菜单.md](页面管理-更多操作菜单.md) | 页面管理更多操作 |

## 多语言与 Local 标签

| 文档 | 主题 |
| --- | --- |
| [i18n-translation-guide.md](i18n-translation-guide.md) | PageBuilder 多语言翻译 |
| [local-tag-usage-guide.md](local-tag-usage-guide.md) | Local 标签使用 |
| [local-tag-translation-guide.md](local-tag-translation-guide.md) | Local 标签翻译 |
| [backend-field-translation-guide.md](backend-field-translation-guide.md) | 后台字段翻译 |
| [translation-quick-reference.md](translation-quick-reference.md) | 翻译速查 |
| [PageBuilder-多语言配置系统.md](PageBuilder-多语言配置系统.md) | 多语言配置系统 |
| [多语言默认语言改进.md](多语言默认语言改进.md) | 默认语言改进 |

## AI 内容生成与排障

| 文档 | 主题 |
| --- | --- |
| [AI一键生成内容使用指南.md](AI一键生成内容使用指南.md) | AI 一键生成内容 |
| [故障排查-预览生成失败.md](故障排查-预览生成失败.md) | 预览生成失败排查 |

## 表单、SEO 与扩展集成

| 文档 | 主题 |
| --- | --- |
| [PageBuilder-表单布局优化说明.md](PageBuilder-表单布局优化说明.md) | 表单布局优化 |
| [功能-表单异步提交和跳转.md](功能-表单异步提交和跳转.md) | 表单异步提交和跳转 |
| [Sitemap配置与使用指南.md](Sitemap配置与使用指南.md) | Sitemap 配置 |
| [Sitemap快速参考.md](Sitemap快速参考.md) | Sitemap 速查 |
| [Sitemap自动生成与SEO集成.md](Sitemap自动生成与SEO集成.md) | Sitemap 自动生成与 SEO |
| [Extends衍生功能集成总结.md](Extends衍生功能集成总结.md) | extends/module 集成 |

## 模板专题与样例

| 文档 | 主题 |
| --- | --- |
| [marketing-landing-模板说明.md](marketing-landing-模板说明.md) | marketing-landing 模板说明 |
| [marketing-landing-快速启动.md](marketing-landing-快速启动.md) | marketing-landing 快速启动 |
| [README-EXAMPLES.md](README-EXAMPLES.md) | 示例说明 |
| [example-money-calendar-page.json](example-money-calendar-page.json) | 示例页面 JSON |

## 功能说明

| 文档 | 主题 |
| --- | --- |
| [功能-CTA转化事件跟踪.md](功能-CTA转化事件跟踪.md) | CTA 转化事件跟踪 |
| [功能-卡片式样式选择器.md](功能-卡片式样式选择器.md) | 卡片式样式选择器 |
| [功能-图片容器三端宽度配置.md](功能-图片容器三端宽度配置.md) | 图片容器三端宽度 |
| [功能-样式切换自动保存.md](功能-样式切换自动保存.md) | 样式切换自动保存 |
| [功能-样式卡片预览按钮.md](功能-样式卡片预览按钮.md) | 样式卡片预览 |
| [功能-页面句柄唯一性实时检查.md](功能-页面句柄唯一性实时检查.md) | 页面句柄唯一性检查 |
