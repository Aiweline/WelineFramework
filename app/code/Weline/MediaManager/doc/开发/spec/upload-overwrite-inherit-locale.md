---
status: ready-for-plan
work_kind: feature
feature_slug: upload-overwrite-inherit-locale
module: Weline_MediaManager
updated: 2026-09-15
---

# 上传同名覆盖并继承多语言文案

## Clarify

| 问题 | 结论 |
|------|------|
| 覆盖语义 | 同一 `object_key` / 同一 `asset_id` 原位替换字节 |
| 文案 | 保留全部 `FileAssetLocale`；前端不再收集 alt/描述，也不走一键翻译 |
| 确认 | 冲突弹窗选「覆盖已有」后须二次确认（destructive） |
| 默认同名 | 无显式 `overwrite` 标志时服务端仍拒绝 |
| 非目标 | 移动/重命名覆盖；AI 作图覆盖协议 |

## User story

作为媒体运营，我希望上传同名文件时可确认覆盖已有资源，并自动继承已有多语言文案，以免重复填写。

## EARS

1. WHEN 目标目录已存在同名文件 AND 用户在冲突弹窗选择「确定(新名)」AND 新名不冲突, the system SHALL 按新名新建 FileAsset 并要求填写当前语言文案。
2. WHEN 目标目录已存在同名文件 AND 用户选择「覆盖已有」AND 在二次确认中确认, the system SHALL 原位替换文件字节、保留 `asset_id` 与全部 locales，且 SHALL NOT 要求重新填写 alt/描述或一键翻译。
3. WHEN 用户在二次确认中取消, the system SHALL 返回冲突弹窗，不得写入存储。
4. WHEN 上传请求未携带对应文件的 `overwrite=true` 且目标已存在, the system SHALL 拒绝并提示目标文件已存在。
5. WHEN 一批上传含覆盖与新建文件, the system SHALL 仅对新建文件收集文案；覆盖项继承既有文案。
6. IF 覆盖请求指向已删除或不存在的 FileAsset, the system SHALL 失败关闭（不得静默新建冒充覆盖）。

## Use cases

### UC1 改名上传（主成功）

1. 用户上传 `image.png`，目录已有同名。
2. 弹窗建议 `image (1).png`；用户也可只填主名（扩展由客户端按源文件自动补齐）。
3. 系统收集 alt/描述后新建资源。

### UC2 覆盖并继承（主成功）

1. 用户上传 `image.png`，目录已有同名且已有多语言标签。
2. 用户点「覆盖已有」→ 确认覆盖。
3. 系统跳过文案弹窗，原位替换字节；详情侧多语言仍在，`asset_id` 不变。

### UC3 取消覆盖

1. 用户点「覆盖已有」后在确认层取消。
2. 回到冲突弹窗；存储未变。

### UC4 无标志服务端拒绝

1. 客户端绕过 UI 直接上传同名且无 `overwrite`。
2. Connector/Upload 返回目标已存在错误。

### UC5 混合批次

1. 两文件：一覆盖、一新名。
2. 仅新名文件弹出文案；覆盖文件携带 `overwrite:true`。
