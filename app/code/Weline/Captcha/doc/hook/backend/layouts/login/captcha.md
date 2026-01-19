# Weline Captcha 模块 - Hook 文档

## Hook 信息

- **Hook 名称**：`Weline_Captcha::backend::layouts::login::captcha`
- **显示名称**：后台登录验证码
- **Hook 类型**：标准格式 Hook
- **功能说明**：在后台登录页面显示验证码，允许其他模块自定义验证码显示方式。

## 使用方法

在模块的 `view/hooks/` 目录下创建文件：`view/hooks/Weline_Captcha/backend/layouts/login/captcha.phtml`

## 使用场景

- 在后台登录页面显示验证码
- 自定义验证码的显示样式
- 集成第三方验证码服务

## 示例代码

```html
<!-- 在模块的 view/hooks/Weline_Captcha/backend/layouts/login/captcha.phtml 文件中 -->
<div class="captcha-container">
    <img src="/captcha/image" alt="验证码" id="captcha-image">
    <input type="text" name="captcha" placeholder="请输入验证码" required>
    <button type="button" onclick="refreshCaptcha()">刷新</button>
</div>
```

## 注意事项

- 此 hook 用于后台登录页面的验证码显示
- 需要实现验证码的刷新功能
- 建议使用与后台主题一致的样式
