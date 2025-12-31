# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`header-account`
- **显示名称**：页头账户菜单
- **Hook 类型**：简单格式 Hook（向后兼容）
- **功能说明**：在页头显示账户菜单，允许其他模块实现账户登录/注册功能。支持 hover 展开下拉菜单。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/header-account.phtml`

## 使用场景

- 实现账户登录/注册功能
- 显示用户登录状态
- 显示用户信息（用户名、头像等）
- 自定义账户菜单样式

## 示例代码

```html
<!-- 在模块的 view/hooks/header-account.phtml 文件中 -->
<?php
use Weline\Customer\Session\CustomerSession;
use Weline\Framework\Manager\ObjectManager;

$session = ObjectManager::getInstance(CustomerSession::class);
$isLoggedIn = $session->isLogin();

if ($isLoggedIn) {
    $customer = $session->getLoginUser();
    ?>
    <a href="/customer/account/index" class="action-link">
        <span class="action-line-1"><?= __('您好, %{1}', [$customer->getUsername()]) ?></span>
        <span class="action-line-2"><?= __('我的账户') ?></span>
    </a>
    <?php
} else {
    ?>
    <a href="/customer/account/login" class="action-link">
        <span class="action-line-1"><?= __('您好, 登录') ?></span>
        <span class="action-line-2"><?= __('账户及心愿单') ?></span>
    </a>
    <?php
}
?>
```

**注意**：由于 Theme 模块的 head partial 已经设置了 `<base>` 标签，所有相对路径都会基于 frontend 基础 URL。因此可以直接使用相对路径（如 `/customer/account/index`），无需使用 `$frontendUrl` 变量。

## CSS 类说明

- `.header-account` - 账户菜单容器
- `.account-dropdown` - 账户下拉菜单
- `.action-link` - 账户链接样式
- `.action-line-1` - 第一行文字
- `.action-line-2` - 第二行文字

## Hover 展开

账户下拉菜单通过 CSS `:hover` 实现展开，无需 JavaScript。

## 注意事项

- 此 hook 需要配合 `header-account-links` hook 使用
- 如果未实现此 hook，将显示默认的登录链接
- 建议检查用户登录状态，显示不同的内容

