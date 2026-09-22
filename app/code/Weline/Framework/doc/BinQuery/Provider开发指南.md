# BinQuery Provider 开发指南

BinQuery 不要求开发者放弃旧 descriptor 数组。推荐新开发使用 Attribute，旧数组继续兼容；框架会在 `framework:compile` 阶段合并 Attribute，生成最终不可变 descriptor。

**后续业务接口默认只写 QueryProvider**（REST / query-bin / `/bin/query` 为入口壳）。权限是入口门 + descriptor 声明，不是「写了 Provider 就安全」。

## 权限默认拒绝（硬）

| 暴露意图 | 必须显式声明 | 禁止 |
|----------|--------------|------|
| 仅进程内 / REST 薄封装 `w_query` | 默认即可：`external=false`、`frontend=false`；REST 侧 `#[Acl]` | 误标 `external`/`frontend` 把敏感 op 拖进网关 |
| 站内 Worker（Weline.Api→query-bin） | `frontend=true` + **`auth`**（`any`/`guest`/`customer`/`backend`） | 缺 `auth` 却当「已鉴权」；后台缺 `backend_acl` |
| 站外 `/bin/query` | `external=true`（frontend 区另需 `frontend=true`）+ 可无会话时 **`auth=any`（或 guest）** + API Key scope | 把 `auth=backend`/`customer` 只靠 API Key 当登录；写操作随便 `external` |
| CDN 公开读 | `external` ∧ `mode=read` ∧ `cache.cdn` ∧ `visibility=public` | 会话/PII/写后强一致数据开 public |

Attribute **默认**（`BinQueryOperation`）：`external=false`、`frontend=false`。公开/站内/站外都必须 **opt-in**，禁止靠默认值暴露。

**暴露面权限 = 契约硬要件（架构级）**：写了 QueryProvider ≠ 前端/后台冰块可调。凡 `frontend=true` 必须显式 `auth`；凡后台可调（`auth=backend` / `backend=true`）必须合格 `backend_acl`。缺则契约不完整，不可上线。

站内冰块调后台的**唯一权限真相**是 descriptor（`auth` + `backend_acl`）由 `FrontendQueryGateway` 执行；REST `#[Acl]` 是另一入口平面，**不能**替代冰块路径的声明。业务内再校验只做纵深，不能当主门。

### 后台写操作模板（如产品编辑）

```text
frontend=true, backend=true, external=false,
auth=backend, mode=write,
backend_acl={ kind: source, source_id: 「与后台菜单/REST 同级的真实 ACL」 }
```

禁止：`auth=any|guest|customer` 承载后台编辑；禁止仅用 `kind=self` 挡产品/订单等资源写；禁止 `external=true` 承载后台编辑。

`w_query()` / `FrameworkQueryService` **不做** descriptor 二次鉴权——信任调用方。对外 HTTP 只能经 Gateway 或带 `#[Acl]` 的 REST 壳。

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
    auth: 'any',
    graph: true,
    cost: 1
)]
#[BinQueryParam('page', type: 'int', required: false, default: 1, description: '页码', cacheKey: true)]
#[BinQueryParam('page_size', type: 'int', required: false, default: 20, description: '每页数量', cacheKey: true)]
#[BinQueryCache(ttl: '15m', description: '主题列表 BinQuery 缓存 15 分钟', visibility: 'public', cdn: true)]
#[BinQueryExample(params: ['page' => 1, 'page_size' => 20])]
private function list(array $params): array
{
    return [];
}
```

后台 Worker 示例（勿设 `external`）：

```php
#[BinQueryOperation(
    name: 'adminList',
    mode: 'read',
    frontend: true,
    backend: true,
    auth: 'backend',
)]
// descriptor 数组侧补 backend_acl（kind=source|…）；Attribute 暂无该字段时用 getDescriptor 合并
```

## 合并规则

- 旧 descriptor 的 `operations` 继续有效。
- Attribute operation 与旧 descriptor 同名时，Attribute 字段优先。
- `BinQueryParam` 会按参数名合并，Attribute 字段优先。
- `BinQueryExample` 会追加到 `examples`。
- `BinQueryCache` 只在 `external=true` 且 `mode=read` 时进入最终 descriptor。
- `BinQueryParam(cacheKey: true)` 会自动进入 `cache.key_params`，除非 `BinQueryCache(keyParams: [...])` 已显式指定。

## 外部访问要求

Attribute **不再**默认 `external`/`frontend`。站外 SDK 可见 operation 至少需要：

```php
#[BinQueryOperation(
    name: 'list',
    mode: 'read',
    external: true,
    frontend: true,
    auth: 'any'
)]
```

写操作可以设置 `mode: 'write'`，但不会进入 CDN 缓存，也不能进入 graph。站外写操作仍须 API Key scope ≥ write，且不得标 CDN public。

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
