# Weline Payment 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Payment::frontend::layouts::checkout-payment::methods-after`
- **显示名称**：支付方式选择区域之后
- **Hook 类型**：标准格式 Hook
- **功能说明**：在结账界面支付方式选择区域之后执行的Hook点，可以用于添加自定义内容。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Payment/frontend/layouts/checkout-payment/methods-after.phtml`

## 使用场景

- 在支付方式选择区域之后显示补充信息
- 添加支付安全提示
- 显示支付协议链接

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Payment/frontend/layouts/checkout-payment/methods-after.phtml 文件中 -->
<div class="payment-security-notice">
    <p><lang>您的支付信息将被安全加密</lang></p>
</div>
```

## 注意事项

- 此 hook 在支付方式选择区域之后执行
- 可以用于显示安全提示、协议链接等
- 建议使用与结账页面一致的样式
