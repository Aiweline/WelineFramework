# Weline Payment 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Payment::frontend::layouts::checkout-payment::result`
- **显示名称**：支付结果展示
- **Hook 类型**：标准格式 Hook
- **功能说明**：在结账界面支付结果展示区域执行的Hook点，可以用于自定义支付结果展示。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Payment/frontend/layouts/checkout-payment/result.phtml`

## 使用场景

- 自定义支付成功/失败的展示方式
- 添加支付结果相关的操作按钮
- 显示订单详情或后续操作指引

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Payment/frontend/layouts/checkout-payment/result.phtml 文件中 -->
<?php
$paymentStatus = $this->getData('payment_status') ?? 'pending';
if ($paymentStatus === 'success') {
    ?>
    <div class="payment-success">
        <h3><lang>支付成功</lang></h3>
        <p><lang>您的订单已成功支付</lang></p>
        <a href="/customer/account/orders" class="btn btn-primary"><lang>查看订单</lang></a>
    </div>
    <?php
} else {
    ?>
    <div class="payment-failed">
        <h3><lang>支付失败</lang></h3>
        <p><lang>支付过程中出现问题，请重试</lang></p>
    </div>
    <?php
}
?>
```

## 注意事项

- 此 hook 用于支付结果展示区域
- 可以根据支付状态显示不同的内容
- 建议提供明确的成功/失败提示和后续操作指引
