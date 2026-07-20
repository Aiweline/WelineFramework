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
- 说明：因 `DEBUG=1` 会走 sandbox，故 sandbox 同步为 pgsql，避免继续落到 sqlite
