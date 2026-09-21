# roster — process-l1-pressure-eviction

| 字段 | 值 |
|------|-----|
| slug | `process-l1-pressure-eviction` |
| 模式 | team（复杂 · 框架进程 L1 架构） |
| work_kind | feature |
| fe_be_scope | backend_only |
| ui_skill_decision | skip（无模板/视觉） |
| 归属模块 | `Weline_Framework`（编排触点 `Weline_Server` 只读边界） |

## 本波席位

| 席位 | 状态 |
|------|------|
| 项目经理 | 主持技术方案会 |
| 需求分析 | closed（EARS/UC） |
| 领域探查 | closed（先前探查 + 本会引用） |
| 架构师 | closed（方案） |
| 扩展点 | closed（surfaces） |
| 后端 | 同意（带条件） |
| 安全 | 同意（带条件） |
| 测试 | 验收波再入 |
| 文档 | 本会落盘 |

## 专席触发

| 专席 | 触发 | 说明 |
|------|------|------|
| 扩展点 | 是 | surfaces 冻结 |
| 事件 | skip | 不新开压力 Event |
| 查询 | skip | 无跨模块读列表 |
| Taglib/Hook/Provider/ACL | skip | 无 UI/菜单 |
| i18n | 旁路 | Phrase 袋迁移，不改文案源串 |
| Setup | 施工升版时 | Framework/Server 升版 |
| 合规 | skip | 非电商交易面 |
