# PageBuilder 长期规范

这个目录只放长期有效、可被开发和测试反复引用的规范文档。一次性修复记录、调试过程、阶段性计划先放到 [../doc/README.md](../doc/README.md) 归类。

## 当前规范

| 文档 | 用途 |
| --- | --- |
| [TEMPLATE_SPEC.md](TEMPLATE_SPEC.md) | PageBuilder 模板开发规范 |
| [COMPONENT_SPEC.md](COMPONENT_SPEC.md) | PageBuilder 组件开发规范 |
| [AI_RUNTIME_MODE_SPEC.md](AI_RUNTIME_MODE_SPEC.md) | PageBuilder AI 运行模式规范，尤其是 fake 模式边界 |

## 收录规则

- 文档内容应描述稳定规则、接口契约或开发约定。
- 不收录单次问题修复、临时调试、会议式计划和截图验收记录。
- 若 `doc/` 中的历史文档已经沉淀为长期规则，先合并去重，再迁入本目录。
- 迁移文档时必须同步检查代码、测试和其他文档中的硬编码路径。
