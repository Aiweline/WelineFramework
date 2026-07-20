# Weline MCP

[简体中文](README.md) · [English](README.en.md) · **日本語**

**Codex のファイル単位の試行を、タスク単位の一括コーディングへ。**

**Token を節約し、取得を高速化し、プロジェクトを自動分離し、複数セッションの上書きを防ぎ、検証済み結果を再利用可能な Skill に変換します。**

Weline MCP は Codex 向けのローカル優先プロジェクトインテリジェンス、トランザクション編集、証拠ベース学習エンジンです。

1. タスク、既知パス、影響シンボルを `get_edit_bundle` に一度だけ渡します。
2. 明示パスと Hash を更新確認し、Token Budget 内で必要なコード領域、関連ファイル、影響、文書、ルール、Skill だけを返します。
3. Codex は 1 つの `edit-plan.v1` を送ります。
4. `apply_compact_edit` がロック、Hash 再確認、原子的コミット、検証、再インデックス、安全なロールバックを行います。

通常は PHP 8.2+、SQLite 拡張、Git だけで動作します。Composer と Node.js は任意の配布入口です。npm は依存関係のないプロセスラッパーで、同じ PHP STDIO Server がプロトコルを処理します。

## 主な機能

- コード、文書、シンボル、関係、ルール、Skill の永続増分インデックス
- 精密パス／シンボル検索、全文・trigram・ローカル疎検索、影響分析
- リポジトリ全体ではなく Token Budget 内の有界領域を返却
- プロセス間ロック、固定ロック順、Hash、Journal、原子的置換、検証、ロールバック
- canonical Git root ごとに独立した `project.sqlite`
- 証拠、作用域、重複、競合、信頼度、成熟度、期限、コードドリフトを確認して `SKILL.md` を生成

「プロジェクトごとにゼロ設定」は、一度 Codex にグローバル登録した後の意味です。初回だけインストーラーまたは `codex mcp add` が必要です。

## 必要環境

PHP 8.2+、`pdo_sqlite`、`json`、`mbstring`、`openssl`、Git。既定の検証済み経験分類には Codex CLI、短い非同期 worker には任意で `pcntl`/`posix` を使います。

## ソースから導入

```bash
git clone https://github.com/Aiweline/Weline-Codex-Mcp.git
cd Weline-Codex-Mcp
./start.sh
```

Gitee は `https://gitee.com/aiweline/weline-codex-mcp.git`、Windows は `start.bat` を使用します。スクリプトは必要環境を確認・導入し、設定がなければ作成して STDIO Server を起動します。診断は stderr のみです。

## Composer

```bash
composer global config repositories.weline-mcp vcs https://github.com/Aiweline/Weline-Codex-Mcp
composer global require aiweline/weline-codex-mcp:^0.9
composer global exec -- weline-mcp-install --register-codex
```

必要なら VCS URL を Gitee に変更します。Packagist 公開後は `composer global require aiweline/weline-codex-mcp`。既存設定を保持し、`--register-codex` 指定時だけ Codex を変更します。

## Node/npm ラッパー

```bash
npm install -g git+https://github.com/Aiweline/Weline-Codex-Mcp.git
codex mcp add weline -- weline-mcp
```

Gitee の Git URL も利用できます。npm Registry 公開後は `npm install -g weline-codex-mcp`。ラッパーは stdio、引数、環境、終了状態、シグナルを PHP に渡します。PHP は `WELINE_MCP_PHP` または `PHP_BINARY` で選択できます。

## Codex に接続

```bash
codex mcp add weline -- /absolute/path/to/Weline-Codex-Mcp/bin/learning-mcp --config /absolute/path/to/config.yaml
codex mcp list
```

Codex Desktop、CLI、IDE Extension は同じ Host の MCP 設定を共有します。Desktop/IDE の **Settings → MCP servers** から STDIO Server を追加することもできます。

```toml
[mcp_servers.weline]
command = "/absolute/path/to/Weline-Codex-Mcp/bin/learning-mcp"
args = ["--config", "/absolute/path/to/config.yaml"]
startup_timeout_sec = 20
tool_timeout_sec = 120
```

## Skill 出力先

```yaml
knowledge:
  learning_skills:
    output_directory: ".codex/skills"
```

既定設定は `~/.learning-mcp/config.yaml`、Windows は `%USERPROFILE%\.learning-mcp\config.yaml`。相対パスは各リポジトリ root 基準、絶対パスも利用できます。環境変数は `LEARNING_MCP_CONFIG` と `LEARNING_MCP_SKILL_OUTPUT_DIR` です。

## 推奨 Codex フロー

```markdown
- Call get_edit_bundle once with the task, all known paths, and affected symbols.
- Use returned regions, hashes, impacts, docs, and Skills instead of repository-wide scans.
- Submit one edit-plan.v1 and call apply_compact_edit once.
- Use get_edit_status and rollback_edit for recovery only.
```

主要ツール：`get_edit_bundle`、`apply_compact_edit`、`get_edit_status`、`rollback_edit`、`health`。

詳細：[運用](docs/OPERATIONS.md)・[アーキテクチャ](docs/ARCHITECTURE.md)・[セキュリティ](docs/SECURITY.md)・[実装境界](docs/IMPLEMENTATION-STATUS.md)

リポジトリ：[GitHub](https://github.com/Aiweline/Weline-Codex-Mcp) · [Gitee](https://gitee.com/aiweline/weline-codex-mcp) · Apache-2.0
