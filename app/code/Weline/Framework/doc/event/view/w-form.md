# `<w:form>` 与表单扩展事件

`<w:form>` 是 Weline 模板的统一表单标签，编译后输出浏览器原生 `<form>`。官方
`app/code/Weline/**/view/**/*.phtml` 模板不得再手写原生 `<form>`；只有浏览器运行时生成
HTML 的 JavaScript 才保留最终 `<form>` 字符串，并必须声明 `data-weline-form="1"`。

支持属性：

- 表单标准属性：`id`、`method`、`action`、`class`、`enctype`、`autocomplete`、`name`、
  `target`、`rel`、`accept-charset`、`role`、`style`、`novalidate`。
- 框架属性：`intent`、`csrf="auto|on|off"`、`captcha="off|auto|required"`。
- 扩展属性：规范命名的 `data-*` 与 `aria-*`；事件处理器属性不会透传。
- 批量属性：`attributes="变量名"`，变量值必须是属性数组；标签上显式属性覆盖数组同名项。

未写 `method` 时保持 HTML 原生默认 `get`。`csrf` 默认使用 `auto`，POST 表单自动注入
CSRF，GET 不注入。`captcha` 默认使用 `off`，普通 GET/POST 表单都不注入挑战；需要验证码的
入口必须显式设置 `captcha="auto"` 或 `captcha="required"`。`auto` 仅在 POST 表单注入，
`required` 声明该入口必须验证。启用后，Google Enterprise 可用时注入 Google 挑战，否则
注入框架本地图形挑战，不能因 Google 未配置而静默跳过。`action` 仅接受相对地址或
`http/https` 地址，全部输出属性会再次转义。

```html
<w:form id="language-request" method="post"
        intent="i18n.language_support_request"
        csrf="auto" captcha="required">
    ...
</w:form>
```

动态属性使用 Taglib 变量语法，不得在 `<w:form>` 属性中嵌入 `<?= ?>`：

```html
<w:form action="@var($saveUrl)"
        class="editor-form @var($extraClass)"
        data-record-id="@var($record['id'])">
    ...
</w:form>
```

业务表单通常不应显式填写 `csrf`，让框架采用 `auto`。普通业务表单也不必填写 `captcha`，
默认即为 `off`；登录、注册、找回密码、公开写入等经风险评审需要验证码的入口，才显式选择
`auto` 或 `required` 并声明稳定的 `intent`。已有人工 CSRF 字段且暂时不能迁移的表单可设置
`csrf="off"` 防止重复。

`captcha` 属性只控制挑战渲染，不替代服务端授权与验证。启用验证码的提交入口必须使用同一个
`intent` 调用 `CaptchaManagerInterface::verifySubmission()`；后台登录使用 `admin.login`，
前台登录使用 `customer.login`。

服务端扩展事件：

- `Weline_Framework::view::form::prepare`：开始标签前调整白名单属性。
- `Weline_Framework::view::form::before_close`：结束标签前向 `html` 追加隐藏字段或验证组件。

浏览器事件：

- `weline:form:mounted`
- `weline:form:prepare-submit`（可取消）
- `weline:form:verified`
- `weline:form:verification-error`

表单上下文保存在 `RequestContext`，不得使用进程静态栈。开始标签之后可通过
`FormRenderer::current()` 读取栈顶白名单上下文；`before_close` 事件执行期间该上下文仍有效，
事件完成后自动出栈。扩展 HTML 有长度上限；模块仍需在服务端业务入口独立复验 CSRF、Captcha
和业务 Scope。

浏览器统一入口为 `Weline.Form.mount(form)` / `Weline.Form.mountAll(root)`。运行时会监听
新增 DOM，并对 `form[data-weline-form]` 幂等挂载，因此异步弹层或 JavaScript 生成的表单也
能触发同一组事件。
