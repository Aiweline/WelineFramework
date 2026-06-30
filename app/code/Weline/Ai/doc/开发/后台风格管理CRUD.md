# 后台风格管理 CRUD

后台入口：`ai/backend/style`

## 行为边界

- 风格目录通过 `post-catalog` 读取，包含内置、模块、自定义和缺失状态风格。
- 内置、系统、模块风格为只读，不能直接编辑、禁用或删除；需要先通过 `post-clone-builtin` 克隆为自定义风格。
- 自定义风格通过 `post-save` 新建和更新，必须包含至少两个结构化字段，不能只填写补充提示词。
- 自定义风格通过 `post-delete` 删除；删除前会清理对应的手动适配器绑定，避免目录中残留失效的手动绑定。
- 适配器手动绑定通过 `post-bind-adapter-style` 和 `post-unbind-adapter-style` 管理，只允许绑定启用状态的风格。

## 前端约束

- 页面业务请求必须走 `window.Weline.Api.request()`；不要直接使用 `fetch()`、`axios` 或 jQuery AJAX。
- 删除确认使用后台统一 `BackendConfirm.show()` 组件。
- 新增可见文案需要进入 `i18n/zh_Hans_CN.csv` 和 `i18n/en_US.csv`。

## 验证

- 修改后至少执行相关 PHP 文件 `php -l`。
- 浏览器验证需要目标环境关闭系统维护模式，并具备后台管理员登录 Session；否则只能看到维护页或登录页，无法完成 CRUD 操作验证。
