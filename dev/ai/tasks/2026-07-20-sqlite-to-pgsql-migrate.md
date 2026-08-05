# 任务：SQLite 数据搬迁到 PostgreSQL

- 日期：2026-07-20
- 状态：完成
- 源：`/private/tmp/weline-payment-flow-stable-20260719.sqlite`
- 目标：`127.0.0.1:5432` / `weline` / `weline`
- 步骤：
  1. 备份旧 pgsql：`var/backup/weline-pgsql-before-sqlite-migrate-20260720-140229.dump`
  2. 重建空库 `weline`
  3. PHP 迁移脚本拷贝 792 表 / 约 21 万+ 行
  4. 补迁失败表（eav_* / backend_activity_log / lost_and_found）
  5. `env.php` 的 `db` + `sandbox_db` 切到 pgsql（prefix `w_`）
  6. `db:fix-sequence` 复位序列
- 验证：
  - `db:query current_database()` → `weline`
  - `weline_payment_intent=185`、`weline_order=10` 与 sqlite 一致
- 历史说明：本次迁移当时同时把 sandbox 切到 pgsql。自 2026-07-29
  数据库默认合同修正后，`DEBUG=1` 不再隐式选择 sandbox；普通开发与
  生产运行时均使用主库 `db.default=pgsql`，只有显式 Sandbox 才使用
  `sandbox_db`（其默认可为 SQLite）。
