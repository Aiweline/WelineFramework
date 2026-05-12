# Weline Theme - Account Sidebar Hook

## Hook 信息

- **Hook 名称**：`account.sidebar`
- **显示名称**：账户侧栏
- **功能说明**：在账户页面侧栏导航中注入内容。扩展模块可以直接输出自己的父子菜单结构。

## 使用方法

在模块的 `view/hooks/` 目录中创建文件：

```text
view/hooks/account.sidebar.phtml
```

## 父子菜单结构

扩展方如果需要分组，直接在 hook 文件中输出分组容器：

```html
<div class="account-hook-nav-group">
    <div class="account-hook-nav-title"><lang>订单与服务</lang></div>
    <a class="account-hook-nav-link" href="/customer/account/index#orders" data-section="orders" data-account-nav-link="true">
        <span class="account-hook-nav-link__label">
            <i class="ri-shopping-bag-line" aria-hidden="true"></i>
            <span class="account-hook-nav-link__text">
                <strong><lang>我的订单</lang></strong>
                <span><lang>查看订单进度与支付状态</lang></span>
            </span>
        </span>
    </a>
</div>
```

默认布局不会为扩展 hook 额外包分组。需要父级标题、子项、外链或内容区切换时，由注入方自己决定。

## 内容区切换约定

如果菜单项用于切换账户页内容区，链接需要包含：

- `data-account-nav-link="true"`
- `data-section="section-id"`

对应内容区通过 `account.sidebar.content` 输出，并设置：

- `data-account-section="section-id"`
- 默认 `hidden` 或 `d-none`

如果菜单项只是跳转到其他页面，不要添加 `data-account-nav-link` 和 `data-section`。
