---
status: ready-for-plan
work_kind: feature
feature_slug: upload-conflict-auto-extension
module: Weline_MediaManager
updated: 2026-09-16
---

# 上传冲突弹窗：新文件名自动补齐扩展名

## Clarify

| 问题 | 结论 |
|------|------|
| 扩展名来源 | 以冲突源文件名为准（如 `image.png` → `.png`），固定不变 |
| 可带可不带 | 用户只填主名时自动补齐；已带正确扩展名则不重复拼接 |
| 错误扩展 | 若用户手写其它扩展段，替换为源文件扩展（后缀固定） |
| 非目标 | 覆盖流程、移动/重命名已有对象、改服务端允许无扩展上传 |

## User story

作为媒体运营，我在粘贴/上传遇到「文件名已存在」时，希望只填主文件名也能成功保存，系统自动补齐与源文件一致的扩展名，避免无扩展导致上传报错。

## EARS

1. WHEN 冲突弹窗用户确认新名且输入不含扩展名 AND 源文件有扩展名, the system SHALL 在提交前把源扩展名追加到新名。
2. WHEN 用户输入已以源扩展名结尾（大小写不敏感）, the system SHALL 使用该输入，不得再追加一次扩展名。
3. WHEN 用户输入以其它扩展段结尾 AND 源文件有扩展名, the system SHALL 将末尾扩展段替换为源扩展名。
4. WHEN 源文件本身无扩展名, the system SHALL 不擅自追加扩展名。
5. IF 用户确认空名, the system SHALL 仍视为取消/无效，不得提交。

## Use cases

### UC1 不带扩展（主成功）

1. 粘贴 `image.png`，目录已有同名。
2. 弹窗建议 `image (1).png`；用户改成 `新图` 并确定。
3. 系统以 `新图.png` 上传成功。

### UC2 带扩展

1. 用户输入 `新图.png` 确定。
2. 系统以 `新图.png` 上传，不产生 `新图.png.png`。

### UC3 错误扩展被纠正

1. 源为 `.png`，用户输入 `新图.jpg`。
2. 系统以 `新图.png` 提交。
