---
name: media-reference-identity
description: "Build media occupancy identity with w_scope(scope?, type, code, other?). Use for image pick, upload, AI insert, reference unbind. Forbids hand-built identity paths."
---

# 媒体引用身份（w_scope）

## When To Use

选图、上传、AI 插图、删商品/主题后卸引用、FileManager 引用管理。

## Load First

- `app/code/Weline/FileManager/doc/media-reference-identity-protocol.md`

## Steps

1. `get_skill(media-reference-identity)` / 读本 skill
2. 备齐 type + code；scope 有上下文可省略，**CLI 必传**
3. **只调用** `w_scope(scope?, type, code, other?)`（PHP）或 `window.w_scope`（JS）
4. 选图/生成/上传走门面入账；换图只卸引用
5. 实体删：`w_changed` 带 `resource.code`（+ 可选 `resource.scope`）

## Guardrails

- 禁止手拼 `identity_path`
- 禁止合成 `scope~sku`
- 禁止换图时物理删文件
- 多 instance 部件由可视化编辑器给 Tag 设显式身份

## Output

返回 `path` / `tags` / `scope` / `code`；入账后可反查 FileAssetReference。
