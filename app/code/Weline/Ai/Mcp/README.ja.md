# Weline Project Intelligence MCP 0.13.0

`Weline_Ai` に組み込まれた、依存関係のないローカル Project Intelligence MCP です。PHP 8.2+ と SQLite を使用し、STDIO で動作します。WLS、Weline DI、業務 DB、ネットワークサービスには依存しません。

永続的な知識源は `Weline_Ai/doc`、`Weline_Framework/doc`、各モジュールの `doc` だけです。各知識単位には `README.md`、`需求.md`、`开发日志.md` が必要で、派生インデックスと Hash はプロジェクト別 SQLite にだけ保存されます。リポジトリへの Skill 投影は廃止され、`resolve_skill` と `get_skill` は動的タスクガイダンスの互換エイリアスです。

クライアントは `bin/learning-mcp` を起動し、固有の `client_session_id` で `prepare_project` を呼び出します。`project-readiness.v1.status=ready` の場合だけ開発を続行し、以後の保護ツールには同じ `readiness_id` を渡します。文書不足時の修復は、決定的 Bundle を確認して明示承認した後に限ります。一時的なユーザー決定は `set_session_directives` に保存され、プロセスメモリ外には残りません。

検証コマンドは `php bin/learningctl doctor`、`php tests/run.php --quick`、`php tests/project-readiness.php` です。0.13.0 以降の唯一のソースは `app/code/Weline/Ai/Mcp` で、独立リポジトリは最終リリース後に凍結されます。Apache-2.0。
