# Weline.Api 使用指南

## 一、为什么用 Weline.Api

框架内所有 Ajax 异步请求**必须**使用 `Weline.Api.request`，禁止直接使用 `fetch()` 或 `$.ajax()`。

站内 QueryProvider/Graph/Stream 请求由 Theme 运行时注入 `api.endpoint`，实际路径形如 `/{rest_frontend}/framework/query-bin`，其中 `{rest_frontend}` 来自 `Env::getAreaRoutePrefix('rest_frontend')`（例如 `api` 或 `api123`）。业务 JS 不得手写 query-bin URL。

### 1.1 维护模式感知

当系统进入维护模式（503 或 `code: 'maintenance'`）时，Weline.Api 会：

- 自动识别维护响应
- 显示全局维护提示界面
- 将当前请求放入重试队列，维护结束后自动重试

直接使用 `fetch` 时，维护响应无法被统一感知，用户只能看到泛化的网络错误。

### 1.2 404/5xx 友好提示

Weline.Api 会根据 HTTP 状态码提供可读的错误信息：

- **404**：接口或页面不存在，请刷新重试
- **5xx**：服务异常，请稍后重试

若服务端返回 JSON 中的 `msg` 或 `message`，会优先使用该内容。调用方可在 `catch` 中结合 `BackendToast`/`FrontendToast` 展示错误。

### 1.3 统一错误处理与回调层级

错误处理按以下顺序执行（任一层“已处理”可终止后续默认 Toast）：

1. **请求级回调**：本次请求的 `options.onError` 或 `options.onHttpError(status, error)`，返回 `true` 表示业务已处理，不再执行默认 Toast。
2. **全局钩子**：`config.onHttpError(status, error, silent)`，用于统一日志、上报或自定义提示。
3. **默认提示**：未配置或未“接管”时，使用 `BackendToast`/`BackendToast`/`FrontendToast` 展示错误。

可通过 `silent: true` 完全静默，由调用方在 `catch` 中自行处理。

### 1.4 请求重试

维护模式结束后，被缓存的请求会按策略自动重试，减少用户手动刷新。

---

## 二、怎么用

### 2.1 加载 API 模块

后台 head 通常已加载 Theme.js 和 api 模块，可直接使用。若在独立脚本中调用，需先声明：

```javascript
Weline.declare('api');
```

### 2.2 基本用法

```javascript
// GET 请求（推荐）
Weline.Api.get('/backend/example/list', { silent: true })
    .then(function (response) {
        var list = response.data;
        // 业务逻辑
    });

// GET 等价写法
Weline.Api.request('/backend/example/list', { method: 'GET' })
    .then(function (response) {
        if (!response.ok) {
            BackendToast.error(response.data?.msg || '获取失败');
            return;
        }
        var list = response.data;
        // 业务逻辑
    })
    .catch(function (err) {
        // 404/5xx 等错误已由 Api 层展示 Toast
        // 此处可做额外处理，如重置 UI
        if (!err.maintenance) {
            console.error('请求失败:', err.message);
        }
    });

// POST 请求（JSON，推荐）
Weline.Api.post('/backend/example/save', { id: 1, name: 'test' })
    .then(function (response) {
        var data = response.data;
        if (!response.ok) {
            BackendToast.error(data?.msg || '保存失败');
            return;
        }
        BackendToast.success('保存成功');
    })
    .catch(function (err) {
        if (!err.maintenance) {
            console.error(err);
        }
    });

// POST 请求（FormData）
Weline.Api.request('/backend/example/upload', {
    method: 'POST',
    body: formData
    // 不要手动设置 Content-Type，浏览器会自动添加 boundary
})
    .then(function (response) {
        if (response.ok) {
            BackendToast.success('上传成功');
        }
    });
```

### 2.3 响应结构

成功时 `response` 结构：

```javascript
{
    ok: true,
    status: 200,
    statusText: 'OK',
    headers: { ... },
    data: { ... }  // 已解析的响应体（JSON 或 text）
}
```

失败时 `catch` 接收的 `error`：

```javascript
{
    message: '接口或页面不存在，请刷新重试',  // 或服务端 msg
    response: { ok: false, status: 404, data: { ... } },
    status: 404,
    requestUrl: '...',   // 请求 URL（便于在 onError 或 DEV 下排查）
    maintenance: false  // 维护模式时为 true
}
```

### 2.4 静默模式（silent）

调用方自行处理错误、不触发默认 Toast 时：

```javascript
Weline.Api.request(url, { method: 'GET', silent: true })
    .then(function (response) { /* ... */ })
    .catch(function (err) {
        // 自行处理，例如自定义弹窗
        myCustomErrorHandler(err);
    });
```

### 2.5 请求级错误回调（业务层可控）

单次请求可通过 `onError` 或 `onHttpError` 自定义错误处理；**返回 `true` 表示已处理，不再执行默认 Toast**。

```javascript
// 本请求自定义提示，并阻止默认 Toast
Weline.Api.request('/backend/example/save', {
    method: 'POST',
    body: JSON.stringify(payload),
    onError: function (status, error) {
        console.warn('保存失败', status, error.response?.data);
        if (window.BackendToast) {
            window.BackendToast.warning(error.response?.data?.msg || '保存失败，请重试');
        }
        return true;  // 已处理，不再执行默认 Toast
    }
});

// 本请求只记录日志，继续使用默认 Toast（返回 false 或不 return）
Weline.Api.request('/backend/example/list', {
    onHttpError: function (status, error) {
        myLogger.report(status, error.requestUrl, error.response?.data);
        return false;  // 继续执行默认 Toast
    }
});
```

`error` 对象包含：`message`、`status`、`response`（含 `data`）、`requestUrl`（DEV 及通过 theme.js 时可用）。

### 2.6 全局错误钩子

在 `window.__WelineThemeConfig` 或主题配置中设置，对所有请求生效（在请求级回调之后执行）：

```javascript
window.__WelineThemeConfig = {
    api: {
        onHttpError: function (status, error, silent) {
            if (silent) return;
            // 自定义逻辑，如统一日志上报
            console.warn('[API Error]', status, error.message);
            // 若需展示，可调用 BackendToast
            (window.BackendToast || window.BackendToast).error(error.message);
        }
    }
};
```

### 2.7 开发环境（DEV）下的错误信息

当 `window.DEV === true` 或 `window.WELINE_ENV === 'DEV'` 时：

- 默认 Toast 会附带完整信息：请求 URL、HTTP 状态码、服务端返回的 `data`、错误文案。
- 控制台会输出 `[Weline.Api] 请求失败`，包含 `requestUrl`、`status`、`response`、`message`，便于排查。

Theme.js 的 `Weline.Api.request` 在 DEV 下也会在控制台打印请求失败详情。

---

## 三、建议用法

### 3.1 强制规范

- 所有业务 Ajax 必须使用 `Weline.Api.request`
- 禁止直接使用 `fetch()`、`$.ajax()`（第三方库内部除外）
- 错误提示使用 `BackendToast`/`FrontendToast`，禁止 `alert`/`confirm`/`prompt`

### 3.2 推荐模式

```javascript
Weline.Api.request(url, options)
    .then(function (response) {
        var data = response.data;
        if (!response.ok) {
            BackendToast.error(data?.msg || data?.message || '操作失败');
            return;
        }
        // 成功逻辑
        BackendToast.success(data?.message || '操作成功');
    })
    .catch(function (err) {
        // 非维护模式时，Api 层已展示 Toast，此处做兜底或 UI 恢复
        if (!err.maintenance && (window.BackendToast || window.BackendToast)) {
            (window.BackendToast || window.BackendToast).error(err.message || '网络异常');
        }
    });
```

### 3.3 FormData 提交

```javascript
var form = document.getElementById('myForm');
var formData = new FormData(form);

Weline.Api.request(form.action, {
    method: form.method || 'POST',
    body: formData
    // 不设置 Content-Type
});
```

---

## 四、与 fetch/$.ajax 的对比

| 能力             | fetch / $.ajax | Weline.Api                                      |
|------------------|----------------|-------------------------------------------------|
| 维护模式感知     | 否             | 是                                              |
| 404/5xx 提示     | 需自行实现     | 内置 + 可配置                                   |
| 错误统一处理     | 否             | 是（请求级 onError/onHttpError + 全局 onHttpError） |
| 业务层回调控制   | 否             | 是（单次请求 onError 返回 true 即接管）         |
| DEV 完整错误暴露 | 否             | 是（Toast 详情 + console 输出）                 |
| 请求重试         | 否             | 是（维护结束后）                                |
| 推荐用于业务     | 否             | 是                                              |
