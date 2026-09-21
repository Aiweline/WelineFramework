# Spec: Taglib 编译期静态镜像（高优）

## 背景

部分 Taglib 在属性可确定时仍每请求吐 `<?php … ?>` 再解析资源。目标对齐 `<lang>`：编译期烘焙最终输出。

## 方案

- 契约：`StaticMirrorCapableInterface::tryStaticMirror` + `CompileTimeStaticMirror`
- 高优落地：`theme:css` / `theme:js`、`icon`、`file:image`（条件）

## EARS

- WHEN 标签内容/属性为编译期字面量且无 PHP 嵌入，THE SYSTEM SHALL 在 callback 中尝试直出最终 HTML
- WHEN 存在动态属性或嵌入，THE SYSTEM SHALL 保持原有运行期 PHP 发射路径
- WHEN `file:image` 缺少 layout 或 RequestContext 不可解析，THE SYSTEM SHALL 不镜像并回落 PHP
- IF 标签为实现选择器/ACL/DataTable 等请求态控件，THEN THE SYSTEM SHALL 不做静态镜像

## UC-01 字面量 theme:css

1. 模板含 `<theme:css>Weline_Theme::frontend/…/a.css</theme:css>`
2. 编译产物含最终 `<link href='…'>`，不含 `<?php` / `fetchTagSource`

## UC-02 动态 theme:css

1. 路径含 `<?= $file ?>`
2. 编译产物仍为运行期 PHP

## UC-03 字面量 icon

1. `<w:icon name="settings" size="sm" />`
2. 编译产物为 SVG，不含 `IconRegistry` PHP

## UC-04 file:image 条件镜像

1. 全字面 + width/height（或 aspect_ratio）+ 可解析 Scope/locale → 直出 `<img>`
2. 否则回落 `FileImageRenderer` PHP
