# Weline Payment 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Payment::frontend::layouts::checkout-payment::methods-before`
- **显示名称**：支付方式选择区域之前
- **Hook 类型**：标准格式 Hook
- **功能说明**：在结账界面支付方式选择区域之前执行的Hook点，可以用于添加自定义内容。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Payment/frontend/layouts/checkout-payment/methods-before.phtml`

## 使用场景

- 在支付方式选择区域之前显示提示信息
- 添加支付相关的说明内容
- 显示优惠活动信息

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Payment/frontend/layouts/checkout-payment/methods-before.phtml 文件中 -->
<div class="payment-notice">
    <p><lang>请选择支付方式</lang></p>
</div>
```

## 注意事项

- 此 hook 在支付方式选择区域之前执行
- 可以用于显示提示、说明或优惠信息
- 建议使用与结账页面一致的样式
