# Weline Payment 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Payment::frontend::layouts::checkout-payment::form-after`
- **显示名称**：支付表单之后
- **Hook 类型**：标准格式 Hook
- **功能说明**：在结账界面支付表单之后执行的Hook点，可以用于添加自定义内容。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Payment/frontend/layouts/checkout-payment/form-after.phtml`

## 使用场景

- 在支付表单之后添加确认按钮
- 显示支付协议确认选项
- 添加支付相关的补充信息

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Payment/frontend/layouts/checkout-payment/form-after.phtml 文件中 -->
<div class="payment-agreement">
    <label>
        <input type="checkbox" name="agree_terms" required>
        <lang>我已阅读并同意支付协议</lang>
    </label>
</div>
```

## 注意事项

- 此 hook 在支付表单之后执行
- 可以用于添加确认选项、协议链接等
- 建议使用与结账页面一致的样式
