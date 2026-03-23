# WeShop 国际电商

这个目录用于记录 WeShop 国际电商全量完善实施的设计、范围、验收矩阵与执行进度。

当前实施优先级：

1. 认证与统一 API 认证底座
2. 前后台 Google 登录与 2FA 编排
3. `default` 主题兼容与缺失 slot/hook 告警
4. 后台 IA、菜单资源和代表性模块补齐
5. 测试闭环与持续扩展

API path note:

- WeShop frontend REST APIs must follow the framework route order `/{rest_frontend_prefix}/{module_router}/rest/v1/...`
- In the current env the default auth entry is `/api/weshop/rest/v1/auth/...`
