# Weline Theme 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`header-orders`
- **显示名称**：页头订单
- **Hook 类型**：简单格式 Hook（向后兼容）
- **功能说明**：在页头右侧操作区显示订单相关入口，允许其他模块实现订单列表、退货中心等功能的快捷访问。如果未实现该 Hook，将显示默认的“退货 与我的订单”链接。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/header-orders.phtml`

## 使用场景

- 在页头显著位置提供“我的订单”入口
- 为已登录用户展示与订单相关的个性化信息（最近订单数、待付款数量等）
- 为未登录用户引导登录后查看订单
- 根据业务需要扩展为“退货/售后中心”、“订单跟踪”等功能

## 默认结构参考

主题默认模板 `header/default.phtml` 中，未实现 Hook 时的结构大致如下：

```html
<div class="header-action-item">
    <a href="/account/orders" class="action-link" title="<lang>我的订单</lang>">
        <span class="action-line-1"><lang>退货</lang></span>
        <span class="action-line-2"><lang>与我的订单</lang></span>
    </a>
</div>
```

## 示例代码

```php
<!-- 在模块的 view/hooks/header-orders.phtml 文件中 -->
<?php
use Weline\Customer\Session\CustomerSession;
use Weline\Framework\Manager\ObjectManager;

/** @var \Weline\Framework\View\Template $this */

$session = ObjectManager::getInstance(CustomerSession::class);
$isLoggedIn = $session->isLogin();

if ($isLoggedIn) {
    $customer = $session->getLoginUser();
    ?>
    <a href="/account/orders" class="action-link" title="<?= __('我的订单') ?>">
        <span class="action-line-1"><?= __('您好, %{1}', [$customer->getUsername()]) ?></span>
        <span class="action-line-2"><?= __('我的订单') ?></span>
    </a>
    <?php
} else {
    ?>
    <a href="/account/orders" class="action-link" title="<?= __('我的订单') ?>">
        <span class="action-line-1"><?= __('退货与售后') ?></span>
        <span class="action-line-2"><?= __('与我的订单') ?></span>
    </a>
    <?php
}
```

## CSS 类说明

- `.header-action-item`：头部操作项容器（由主题模板提供）
- `.action-link`：操作链接样式
- `.action-line-1`：第一行文字（通常为上方小号文字）
- `.action-line-2`：第二行文字（通常为主标题）

## 注意事项

- 建议始终为链接添加合适的 `title` 和可访问性属性（如 `aria-label`）。
- 建议根据登录状态展示不同的提示文案，提升用户体验。
- 如果 Hook 模板不输出任何内容，则会回退到默认的“退货 与我的订单”文案。

