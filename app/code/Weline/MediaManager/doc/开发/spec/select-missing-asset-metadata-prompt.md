---
status: ready-for-plan
work_kind: feature
feature_slug: select-missing-asset-metadata-prompt
module: Weline_MediaManager
updated: 2026-09-16
plan_complexity: simple
plan_skip_rationale: >-
  单模块选择确认 UX：复用已有 Weline.UI.dialog.prompt 元数据序列；无新扩展点发明；
  无 asset_id 时经 FileAssetLibrary 边界注册既有对象后同一套弹窗；前后端路径无歧义。
ui_skill_decision: participate
---

# 选择确认缺元数据时立即弹窗补充

## Clarify

| 问题 | 结论 |
|------|------|
| 触发 | 选择确认（工具栏确认 / iframe 双击确认）遇到「无可选 FileAsset / 当前语言名称·alt·描述未审核」 |
| 替代行为 | **立即**弹出补充窗体，**不再**仅 toast `assetMetadataRequired` |
| 已有 `asset_id` | 复用现有名称 → 默认 alt → 描述 → caption 顺序 `dialog.prompt`，`asset_metadata` 保存为 reviewed |
| 无 `asset_id` | 先 `asset_ensure` 为磁盘对象建立 FileAsset，再同一套弹窗补齐并审核 |
| 成功后 | 刷新本地 `FILES` 描述；若已 `asset_selectable` 则**自动重试**本次 `confirmSelection` |
| 取消弹窗 | 中止选择确认，不报「尚未建立」红 toast |
| 非目标 | 改右侧语言工作台布局；改一键翻译；强制用户重传文件 |

## User story

作为后台运营，我在媒体管理器确认选用文件时，若资源尚未可选或当前语言文案未审核，希望立刻弹出补充窗体完成名称/alt/描述，而不是只看到错误提示。

## EARS

1. WHEN 用户确认选择且目标文件因缺少可选 FileAsset 或当前语言名称/alt/描述未审核而不可选, the system SHALL 立即弹出元数据补充窗体，SHALL NOT 仅展示 `assetMetadataRequired` toast。
2. WHEN 用户在补充窗体中填完必填项并确认保存成功且资源变为可选, the system SHALL 自动继续完成本次选择确认。
3. IF 用户取消任一补充步骤, the system SHALL 中止选择确认且不得写入未确认文案。
4. IF 目标对象尚无 `asset_id`, the system SHALL 先注册既有存储对象为 FileAsset，再进入同一套补充窗体。

## Use cases

### UC1 已有实体但未审核（主成功）

1. 选中 `image-en.png`（有 `asset_id`，`asset_selectable=false`）。
2. 点确认选择。
3. 立即弹出资源名称 → alt → 描述 → caption。
4. 保存成功 toast；自动完成选择回传。

### UC2 无 FileAsset（磁盘直写）

1. 选中仅磁盘存在、无 `asset_id` 的文件。
2. 确认选择 → `asset_ensure` → 同一套弹窗 → 保存审核 → 自动确认选择。

### UC3 取消

1. 确认选择弹出名称窗体后点取消。
2. 不写入；不报红 toast「尚未建立…」；选择未完成。

## 隐形需求

- 已有 `editSelectedAssetMetadata` / `requestUploadMetadata` 弹窗模式与 i18n key。
- 可选条件权威在 `FileAssetLibrary::describe`（ready + reviewed + 名称/alt/描述非空）。
- 写操作须走 Connector POST `MUTATING_COMMANDS`。

## 验收

- 契约：`manager.js` 在 `blockedByAssetMetadata` 分支调用补充流程；Connector 含 `asset_ensure`。
- 本机 Browser WB-OP：对不可选文件点确认可见弹窗而非仅红 toast；填完后可选。
