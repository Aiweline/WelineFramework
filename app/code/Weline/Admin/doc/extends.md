# Weline_Admin Extends

## BackendMenuSignalProvider

- 路径：`extends/module/Weline_Admin/BackendMenuSignalProvider/{Name}Provider.php`
- 接口：`Weline\Admin\Api\BackendMenuSignalProviderInterface`
- 可选：`Weline\Admin\Api\BackendMenuSignalItemsProviderInterface`（`items()` 返回归属模块自产深链 `{id,title,href,count}`）
- `code()` 必须等于菜单 `source_id`（ACL source 形，如 `Weline_CustomerService::console`）
- 计数与 items 由 JS 经 `admin.menuSignals` 绘制；禁止 SSR 数字进侧栏 HTML
- 角标可点：打开侧栏 `w-menu` 面板优先列 Provider `items` 深链，否则回退贡献叶菜单；Admin JS **禁止 invent URL**
- 有信号的叶菜单：`href` 改写为 `primary_href`；清零后从 `data-backend-menu-href-base` 恢复
