# Spec: 选图顶部身份引用标签过滤

status: ready-for-plan  
work_kind: feature  
plan_skip: true  
plan_skip_reason: 在既有 MediaReference by-tags / 引用管理能力上补齐顶部标签 UI 与点击过滤，无新领域模型；FE-only 表面增强。

## 澄清记录

- 用户截图箭头指向选图弹层标题区与工具栏之间：展示「当前身份引用」。
- 身份按标签分组；点击组或单标签过滤文件列表。

## 用户故事

作为后台运营，我在选图弹层顶部看到当前业务身份标签，并能点击某一组过滤出被该身份引用的文件，以便快速定位已绑定素材。

## EARS

- WHEN 选图 iframe URL 带有 `identity` / `identity_*` THEN 系统 SHALL 在工具栏上方显示「当前身份引用」条。
- WHEN 身份 path 可解析 THEN 系统 SHALL 按 大类 / 身份 / 范围 / 槽位 分组展示可点击标签。
- WHEN 用户点击某标签 THEN 系统 SHALL 将该标签加入 AND 过滤并调用 by-tags，隐藏未命中文件（目录仍可见）。
- WHEN 用户点击组名 THEN 系统 SHALL 按该组全部标签过滤。
- WHEN 用户点击「全部」 THEN 系统 SHALL 清除过滤并显示当前目录全部文件。
- WHEN 无身份参数 THEN 系统 SHALL 隐藏身份条。

## UC

- UC-1：StoreMusic 选曲打开弹层 → 顶部可见 config/key/scope/kind 等 chips。
- UC-2：点击 `kind:media` → 仅显示命中引用的音频（或空命中提示）。
- UC-3：点击「全部」→ 恢复网格。
