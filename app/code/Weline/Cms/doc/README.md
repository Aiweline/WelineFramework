# Weline_Cms

`Weline_Cms` 负责 CMS 页面实体、站点/path/slug 组织、发布状态和回收站接入。页面布局、SEO/head/meta 和可视化编辑数据仍归 `Weline_Theme` / `Weline_Meta`。

## Activity 依赖

CMS 依赖 `Weline_BackendActivity` 的公开契约记录后台用户操作：

- 新建草稿：`cms_page / create_draft`
- 保存页面：`cms_page / create|save`
- 预览页面：`cms_page / preview`
- 拷贝页面到目标站点：`cms_page / copy`
- 保存一级 path：`cms_path_group / save`
- 拷贝一级 path 及其页面到目标站点：`cms_path_group / copy`
- 页面移入回收站：`cms_page / trash`
- 页面从回收站恢复：`cms_page / restore`
- 页面永久清理：`cms_page / purge`
- 一级 path 移入回收站/恢复/永久清理：`cms_path_group / trash|restore|purge`

记录入口是 `Weline\BackendActivity\Api\BusinessContextInterface`。CMS 不直接写 Activity 表，也不直接调用 Activity 内部 Service。

## Dependency Inventory

- Backend、BackendActivity 和 Theme 是必需依赖：CMS 发布后台资源、记录操作上下文，并使用 Theme 的公开目标契约及布局运行时。
- Seo 和 Trash 是可选集成：它们仅扩展站点地图/URL 通知与回收站能力，缺失时 CMS 基本读写仍可用。
- CMS 不得要求 Seo、Trash 或 Theme 反向依赖 CMS；可选集成保持在 `extends/module/<Target>` 边界。

CMS 前台页的布局状态使用 `Weline\Theme\Api\Layout\LayoutStatus`，预览请求的
mode/shell/area 使用 immutable `Weline\Theme\Api\Preview\PreviewContext`；不得引用 ThemeLayout Model
或 PreviewContextService。SEO 安装时，URL 变更通过可选
`Weline\Seo\Api\Url\UrlChangeNotifierInterface` 同步返回处理结果；通知仍发生在
`Weline_Cms::page_save_after/page_delete_after` 事件之前，以保持原 SEO 副作用顺序。

## 回收站关系

CMS 页面和一级 path 的删除、恢复、永久清理由 `Weline_Trash` 调用 CMS 的 Trash provider 完成。Provider 在业务操作成功后标记 Activity 上下文，因此 Activity 列表中能看到真实业务对象，而不是只有通用回收站请求。

## 页面与 path 拷贝

后台 CMS 列表支持在页面卡片或一级 path 标题上点击“拷贝”，再选择目标站点。页面拷贝会在目标站点创建同一路径、标题、状态和 scope 的新页面，并通过 `w_query('theme', 'copyTargetLayoutData', ...)` 复制 Theme 布局选择、可视化布局行和虚拟布局版本。一级 path 拷贝会先检查目标站点是否已有冲突页面路径；无冲突时创建/复用目标站点的同名 path，并逐页执行页面拷贝。
