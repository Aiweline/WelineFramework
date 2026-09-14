# Weline Project Intelligence MCP

`Weline_Ai` 同梱のローカル项目インテリジェンス MCP（PHP 8.2+ / SQLite / STDIO）。

## 位置づけ（2026-09-13）

MCP は**任意の知識面**です：

- スキル／文書インデックス（`resolve_skill` / `get_skill`）
- コードマップと知識検索（`resolve_task_context` / `search_project_knowledge`）
- 領域ハードルール配信（`prepare_project.agent_guidance.hard_constraints`）

**コーディングはホスト標準編集ツールで行います。** MCP を必須の書込経路にせず、ツール完備 / ready を開発前提にしません。

詳細は [PROJECT-INTELLIGENCE.md](docs/PROJECT-INTELLIGENCE.md) と [OPERATIONS.md](docs/OPERATIONS.md) を参照。
