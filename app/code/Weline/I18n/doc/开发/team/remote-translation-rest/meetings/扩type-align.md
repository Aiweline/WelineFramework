# 对齐会 — remote-translation type 扩面

- 席位：`Team:架构师:`（扩面冻结）+ `Team:API:` / I18n
- 日期：2026-09-22
- 状态：**closed**
- 依据：用户要求覆盖 meta / 部件 meta / Local Model；架构决议用 `type` 区分，不新开 Path

## 决议

1. Path / ACL source_id **不变**（仍 `contracts.md` 原表）。
2. `type ∈ {phrase, meta, local_model}`；缺省 `phrase`。
3. phrase/meta → LocaleDictionary + publishLocale；local_model → Local 表 upsert。
4. `collect*` 对 `local_model` → **422**。
5. Demo / README / Provider 文档必须写清三 type 与 WidgetI18n（中文源=phrase）边界。

## UC 增量（EARS）

1. WHEN `postPending` 且 `type=phrase`（或缺省），SHALL 不返回 `@meta::` 前缀词。
2. WHEN `postPending` 且 `type=meta`，SHALL 仅返回 `@meta::` 前缀未译词。
3. WHEN `postPending` 且 `type=local_model`，SHALL 返回 LocalModel 未译字段行（含 local_model/record_id/field/locale/source）。
4. WHEN `postIngest` 且 type 与 item 形不符，SHALL invalid（`wrong_shape_for_type`）或不成功写入。
5. WHEN `postCollectStart` 且 `type=local_model`，SHALL 422。
6. WHEN 未知 type，SHALL 422。
