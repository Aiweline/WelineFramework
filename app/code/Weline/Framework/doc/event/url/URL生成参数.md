# Weline_Framework_Url::url_generate_params

事件名：`Weline_Framework_Url::url_generate_params`

## 用途

在 `Url::extractedUrl` **完成 path/query 组装之后**始终派发（与 `Env::seo` 无关）。  
观察者可向 URL **追加/合并 query 参数**（例如可视化编辑画布的 `theme_id`）。

## 与 `url_generate_rewrite` 的边界

| 事件 | 何时派发 | 允许做什么 | 禁止 |
|------|----------|------------|------|
| `url_generate_rewrite` | 仅 `seo=on` | Seo path 重写 | 业务身份 query 注入（会漏掉不走重写的 URL） |
| `url_generate_params` | **始终** | 追加 query | 改写 path / 冒充 Seo 重写 |

## 触发

```php
// Url::extractedUrl 末尾
if (Env::get('seo')) {
    $eventManager->dispatch('Weline_Framework_Url::url_generate_rewrite', $url);
}
$eventManager->dispatch('Weline_Framework_Url::url_generate_params', $url);
```

数据为 URL **字符串**（引用回写）：观察者 `$event->setData('data', $newUrl)`。

## 性能

每次 `getUrl` / `getFrontendUrl` / `getBackendUrl` 都会触发。无业务必要时必须立即 return。

## 修订

- 2026-09-20：首版。从 rewrite 拆出，避免干扰 Seo，并覆盖 seo=off / 不走重写的生成路径。
