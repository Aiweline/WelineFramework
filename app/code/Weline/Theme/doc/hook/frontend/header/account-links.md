# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`header-account-links`
- **显示名称**：页头账户菜单链接
- **Hook 类型**：简单格式 Hook（向后兼容）
- **功能说明**：在页头账户下拉菜单中显示账户相关链接，允许其他模块自定义账户菜单项。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/header-account-links.phtml`

## 使用场景

- 自定义账户菜单项
- 添加账户相关功能链接
- 根据用户状态显示不同的菜单项
- 添加客户配置、个人资料等链接

## 示例代码

```html
<!-- 在模块的 view/hooks/header-account-links.phtml 文件中 -->
<?php
use Weline\Customer\Session\CustomerSession;
use Weline\Framework\Manager\ObjectManager;

$session = ObjectManager::getInstance(CustomerSession::class);
$frontendUrl = $this->request->getUrlBuilder()->getFrontendUrl('/');
$isLoggedIn = $session->isLogin();

if ($isLoggedIn) {
    ?>
    <li role="none"><a href="<?= $frontendUrl ?>customer/account/index" role="menuitem"><?= __('账户') ?></a></li>
    <li role="none"><a href="<?= $frontendUrl ?>customer/account/orders" role="menuitem"><?= __('我的订单') ?></a></li>
    <li role="none"><a href="<?= $frontendUrl ?>customer/account/settings" role="menuitem"><?= __('账户设置') ?></a></li>
    <li role="none"><a href="<?= $frontendUrl ?>customer/account/profile" role="menuitem"><?= __('个人资料') ?></a></li>
    <li role="none"><a href="<?= $frontendUrl ?>customer/account/logout" role="menuitem"><?= __('退出登录') ?></a></li>
    <?php
} else {
    ?>
    <li role="none"><a href="<?= $frontendUrl ?>customer/account/login" role="menuitem"><?= __('登录') ?></a></li>
    <li role="none"><a href="<?= $frontendUrl ?>customer/account/register" role="menuitem"><?= __('注册') ?></a></li>
    <?php
}
?>
```

## HTML 结构

此 hook 应该返回 `<li>` 元素列表，这些元素会被包裹在 `<ul>` 标签中。

## 菜单项说明

### 已登录状态
- 账户 - 跳转到账户首页
- 我的订单 - 跳转到订单列表
- 账户设置 - 跳转到账户设置页面
- 个人资料 - 跳转到个人资料页面
- 收货地址 - 跳转到收货地址管理
- 修改密码 - 跳转到修改密码页面
- 退出登录 - 退出登录

### 未登录状态
- 登录 - 跳转到登录页面
- 注册 - 跳转到注册页面

## 注意事项

- 此 hook 需要配合 `header-account` hook 使用
- 如果未实现此 hook，将显示默认的账户菜单链接
- 建议根据用户登录状态显示不同的菜单项
- 可以使用分隔线 `<li role="none" class="divider"></li>` 来分隔菜单项

