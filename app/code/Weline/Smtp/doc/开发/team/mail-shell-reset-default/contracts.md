# align-freeze · mail-shell-reset-default

## 背景

可视化编辑邮件时保存会把页头/页尾写入 `smtp_mail_shell_regions`（DB）。用户截图顶部炭黑+朱砂品牌块即库内覆盖；已手工清空 `default.default.default` / `shop.default.default`。现有「重置为默认」只重置渠道正文（`postReset`），**不清壳区**，且 `MailShellHanfuDefaultsService::ensure` 在 header 为空时会再次写回默认页头 HTML。

## 冻结 UC

### UC-1 重置为默认（含壳）
WHEN 操作员在模板编辑页点击「重置为默认」并确认  
THEN 当前 channel+scope+locale 正文回落 Extends/种子默认  
AND 当前 storage_scope 的 `smtp_mail_shell_regions` 清空（header="" footer=[]）  
AND 预览页头回落主题/模块 `shell.phtml`（无库内覆盖）

### UC-2 可视化确认
WHEN 点击「重置为默认」  
THEN 浏览器 `confirm` 提示将丢失自定义正文与壳区改动  
AND 取消则不提交

## 契约

| 席 | 交付 |
|----|------|
| 后端 | `MailShellRegionStore::clear($scope)`；`Template::postReset` 调 clear；`MailShellHanfuDefaultsService` 禁止「空 header 即写回」——仅当检测到 !important/#ff5d05 等坏数据或 `$force` 才写区域；背景图 path 逻辑可保留 |
| 前端 | edit.phtml 重置按钮 confirm；必要时 data-testid；勿另造平行按钮除非确需「仅清壳」 |
| 翻译工程师 | 确认文案/confirm 串 zh_Hans_CN + en_US CSV；`i18n:collect` |
| 测试 | 扩 `MailTemplateUiSendContractTest` / 新增 RegionStore clear 契约；WB 可选 |

## 非目标

- 本波不做百景底图生成（另需求）
- 不改渠道列表页

## 依赖

后端 ∥ 前端 → 翻译可并行文案 → 测试在施工 closed 后
