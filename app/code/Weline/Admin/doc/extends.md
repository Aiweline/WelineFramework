# Weline_Admin Extends

## BackendMenuSignalProvider

- 路径：`extends/module/Weline_Admin/BackendMenuSignalProvider/{Name}Provider.php`
- 接口：`Weline\Admin\Api\BackendMenuSignalProviderInterface`
- `code()` 必须等于菜单 `source_id`（ACL source 形，如 `Weline_CustomerService::console`）
- 计数由 JS 经 `admin.menuSignals` 绘制；禁止 SSR 数字进侧栏 HTML
- 角标可点：打开侧栏 `w-menu` 面板列出贡献叶菜单；深链 `href` 取自侧栏已渲染 DOM，禁止 invent URL
