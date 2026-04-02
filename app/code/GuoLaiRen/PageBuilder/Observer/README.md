# PageBuilder Observer

## VirtualThemeRequestInterceptor

虚拟主题请求拦截器，负责在路由处理前检测虚拟主题请求并注入虚拟主题上下文。

### 功能

1. 监听 `Weline_Framework_Router::route_before` 事件
2. 检测虚拟主题请求（`virtual_theme_id` 参数或 Session 中的虚拟主题上下文）
3. 加载虚拟主题数据
4. 注入虚拟主题上下文到 Request，伪装成普通主题预览
5. 持久化虚拟主题上下文到 Session

### 优先级

- URL 参数 > Session
- 虚拟主题请求 > 普通主题预览

### 使用场景

- AI 建站预览：用户在 AI 建站工作区预览虚拟主题
- 虚拟主题编辑：用户在 PageBuilder 中编辑虚拟主题

### 技术实现

- 事件优先级：sort=5（早于 ACL 检查 sort=1）
- 伪装策略：设置 `preview_theme`, `preview_area`, `frontend_theme_id` 等参数
- 特殊标记：`is_virtual_theme=1`, `virtual_theme_path` 供其他模块识别

### 测试

```bash
php bin/w phpunit:run --module=GuoLaiRen_PageBuilder --filter=VirtualThemeRequestInterceptorTest
```

所有测试用例：
- ✔ Execute with no virtual theme id
- ✔ Execute with virtual theme id from url
- ✔ Execute with virtual theme id from session
- ✔ Execute with invalid virtual theme id
