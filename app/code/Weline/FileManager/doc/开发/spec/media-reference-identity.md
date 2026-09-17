# 选图引用身份 — 规格摘要（EARS）

权威全文：[media-reference-identity-protocol.md](../../media-reference-identity-protocol.md)

## EARS

- WHEN 用户确认选图 THEN 系统 SHALL 立即建立本身份引用（含 scope 与身份标签）。
- WHEN `ref_mode=single` 换图 THEN 系统 SHALL 卸旧引用并建新引用，且 SHALL NOT 删除物理文件。
- WHEN `ref_mode=multi` 同步 THEN 系统 SHALL 以 `asset_id[]` 差量同步，且 SHALL NOT 删除物理文件。
- WHEN 构造身份 THEN 调用方 SHALL 使用 `w_scope`；SHALL NOT 手拼 path。
- WHEN Web 请求有 Scope/Ambient 上下文 THEN `w_scope` / `resource.scope` MAY 省略 scope。
- WHEN CLI 无上下文 THEN `w_scope` / 媒体 delete 的 `resource.scope` SHALL 显式传入。
- WHEN 实体 delete 且带 code（及可解析 scope） THEN FM SHALL 按 type+scope+code AND 卸引用且不删文件。

## UC 摘要

- UC-1.1 `w_scope` 产出合法 path（sku 与 scope 分型）
- UC-2.2b 选图确认入账
- UC-2.2c single 换图只卸引用
- UC-2.2d multi 按 asset_id[] 同步
