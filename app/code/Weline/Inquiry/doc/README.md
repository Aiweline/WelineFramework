# Weline_Inquiry

`Weline_Inquiry` 提供独立的多语言询盘表单能力，当前模块版本为 `1.0.0`。

## 主要能力

- `FormSchemaService` 与 `FormVersionService` 管理版本化表单结构。
- `LocalizedFormResolver` 根据语言与可用版本解析展示内容。
- `SubmissionService` 处理询盘提交及附件归档。
- `InquiryFormCatalogInterface`、`InquiryRendererInterface` 是跨模块公开契约。
- `Taglib/Inquiry.php`、Widget 扩展及后台 Controller 提供前台嵌入和后台管理入口。

## 依赖与边界

- Framework、Backend、I18n、Taglib、Widget 和 SystemConfig 是必需依赖。
- ACL、Captcha、MediaManager 是可选增强；业务代码应通过公开契约检测能力，不反向读取可选模块内部实现。
- 表单 Schema、版本和提交数据的真实状态以服务与模型为准，模板只负责渲染和交互。

## 文档

- [需求](需求.md)
- [开发日志](开发日志.md)
- [当前源码能力快照](功能现状.md)
