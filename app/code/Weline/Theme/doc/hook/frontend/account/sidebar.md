# Weline Theme - Account Sidebar Hook

## Hook 信息

- **Hook 名称**：`account.sidebar`
- **显示名称**：账户侧栏（兼容期扁平位）
- **功能说明**：历史扁平注入位。新功能请注入 `account.sidebar.group.*`；分组标题与壳由 `Weline_Customer` 侧栏宿主输出。

## 推荐：按分组注入

| Hook | 用途 | `data-account-nav-parent` |
|---|---|---|
| `account.sidebar.group.security` | 安全子项 | `security` |
| `account.sidebar.group.commerce` | 订阅/资产/分销/订单/收藏 | `commerce` |
| `account.sidebar.group.addresses` | 发货/收货地址 | `addresses` |
| `account.sidebar.group.connections` | 授权应用、邮箱等 | `connections` |
| `account.sidebar.group.developer` | 开发者入口 | `developer` |

文件命名示例：`view/hooks/account.sidebar.group.addresses.phtml`。

条目须包含：

- `data-account-nav-link="true"`
- `data-section="section-id"`
- `data-account-nav-parent="{group}"`

**不要**在业务模块内再输出 `account-hook-nav-group` / `account-hook-nav-title`。

## 内容区切换约定

对应内容区通过 `account.sidebar.content` 输出，并设置：

- `data-account-section="section-id"`
- 默认 `hidden` 或 `d-none`

如果菜单项只是跳转到其他页面，不要添加 `data-account-nav-link` 和 `data-section`。
