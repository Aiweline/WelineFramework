# BinQuery Provider 开发指南

BinQuery 不要求开发者放弃旧 descriptor 数组。推荐新开发使用 Attribute，旧数组继续兼容；框架会在 `framework:compile` 阶段合并 Attribute，生成最终不可变 descriptor。

## 编译与发布

Provider 或 Attribute 变更后必须执行：

```bash
php bin/w framework:compile
```

命令会生成 provider、operation 和 frontend/backend external area 索引。PROD/WLS 的
descriptor 与 BinQuery 准入热路径只读该索引，不实例化 Provider、不反射方法、
不读 Provider PHP 源文件。缺少索引或格式过期会明确失败，不会在生产请求中退回动态扫描。

`getDescriptor()` 及 Attribute 转换后的值必须是纯标量数组；不允许 Closure、资源、
服务对象或动态回调。provider name 和 operation name 必须唯一，否则编译直接失败。

## Attribute 示例

```php
use Weline\Framework\Service\Query\Attribute\BinQueryOperation;
use Weline\Framework\Service\Query\Attribute\BinQueryParam;
use Weline\Framework\Service\Query\Attribute\BinQueryCache;
use Weline\Framework\Service\Query\Attribute\BinQueryExample;

#[BinQueryOperation(
    name: 'list',
    description: '获取主题列表',
    mode: 'read',
    external: true,
    frontend: true,
    graph: true,
    cost: 1
)]
#[BinQueryParam('page', type: 'int', required: false, default: 1, description: '页码', cacheKey: true)]
#[BinQueryParam('page_size', type: 'int', required: false, default: 20, description: '每页数量', cacheKey: true)]
#[BinQueryCache(ttl: '15m', description: '主题列表 BinQuery 缓存 15 分钟')]
#[BinQueryExample(params: ['page' => 1, 'page_size' => 20])]
private function list(array $params): array
{
    return [];
}
```

## 合并规则

- 旧 descriptor 的 `operations` 继续有效。
- Attribute operation 与旧 descriptor 同名时，Attribute 字段优先。
- `BinQueryParam` 会按参数名合并，Attribute 字段优先。
- `BinQueryExample` 会追加到 `examples`。
- `BinQueryCache` 只在 `external=true` 且 `mode=read` 时进入最终 descriptor。
- `BinQueryParam(cacheKey: true)` 会自动进入 `cache.key_params`，除非 `BinQueryCache(keyParams: [...])` 已显式指定。

## 外部访问要求

默认 `area=frontend`，因此站外 SDK 可见 operation 至少需要：

```php
#[BinQueryOperation(
    name: 'list',
    mode: 'read',
    external: true,
    frontend: true
)]
```

写操作可以设置 `mode: 'write'`，但不会进入 CDN 缓存，也不能进入 graph。

## CDN 缓存要求

`BinQueryCache` 会生成现有 `cdn_api_rule`：

```php
#[BinQueryCache(
    ttl: '15m',
    description: '主题列表 BinQuery 缓存 15 分钟'
)]
```

最终规则匹配：

```text
/bin/query + __wq_cache=wq1.frontend.{provider}.{operation}.{hash}
```

Cloudflare 适配器优先使用该规则；其他 CDN 适配器按现有规则推送能力处理。
