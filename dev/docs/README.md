# 项目橱窗文档（`dev/docs/`）

> **权威不在这里。** 模块规范看 `app/code/*/doc/`；AI/工程流程看 `app/code/Weline/Ai/doc/` + `dev/ai-command/`。  
> 本目录只做**仓库对外入门与跨模块导览**。工作资料（会话/纪要/证据）走 `dev/session` / `dev/team` / `dev/tmp`。  
> **入伍门禁（先过再写）**：[文档组织与入伍门禁.md](../../app/code/Weline/Ai/doc/文档组织与入伍门禁.md)

## 入口

| 用途 | 路径 |
|------|------|
| 架构导览 | [weline/README.md](./weline/README.md) · [开发者入口](./weline/开发者入口.md) · [架构总览](./weline/架构总览.md) |
| 多语言 README | [readme/README.md](./readme/README.md) |
| 部署入门 | [部署文档.md](./部署文档.md) · [部署/](./部署/) |
| 操作指南 / 测试 | [guides/](./guides/) |
| 故障排查 | [fix/](./fix/) |
| API / 命令速查 | [api/](./api/) · [commands/](./commands/) |
| 封面资源 | [assets/](./assets/) |

## 内容分类（2026-10-13 · R2 收紧后）

| 类别 | 处理 |
|------|------|
| 模块三文档 / 耐久 spec | 留在 `app/code/*/doc/`（橱窗只留兼容指针） |
| 开发日志 | 近窗保留；过旧裁到本机 `dev/tmp/devlog-archive/` |
| 工程 team 纪要 | 只在 `dev/team/`（已从模块 doc 迁出） |
| 站点私有诊断/运维 | `websites/<site>/`（**禁止**进本橱窗 fix） |
| 过期大全 / 旧 AI 提示 / 旧版本计划 | 已从 `archive/` 删除 |
| 自动快照 `AI-INDEX` / `功能现状` | 保留（可重生，不作手改权威） |

## 全仓索引

权威轻量总目录：[Ai/doc/文档索引.md](../../app/code/Weline/Ai/doc/文档索引.md)
