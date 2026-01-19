# Weline Payment 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Payment::frontend::layouts::checkout-payment::form-before`
- **显示名称**：支付表单之前
- **Hook 类型**：标准格式 Hook
- **功能说明**：在结账界面支付表单之前执行的Hook点，可以用于添加自定义表单字段。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Payment/frontend/layouts/checkout-payment/form-before.phtml`

## 使用场景

- 在支付表单之前添加额外的表单字段
- 显示支付相关的提示信息
- 添加支付方式特定的说明

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Payment/frontend/layouts/checkout-payment/form-before.phtml 文件中 -->
<div class="payment-form-notice">
    <p><lang>请填写支付信息</lang></p>
</div>
```

## 注意事项

- 此 hook 在支付表单之前执行
- 可以用于添加表单字段或显示提示信息
- 建议使用与结账页面一致的样式
