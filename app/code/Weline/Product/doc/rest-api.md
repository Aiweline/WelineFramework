# 外部应用产品 REST API

产品模块从 `1.0.166` 提供应用创建、编辑和读取产品接口。接口复用
`ProductAdminCommandInterface` / `ProductAdminReadInterface`，支持当前 Provider
和 Website 商品投影；新增的产品仍为草稿；明确调用 publish 接口，复用现有后台的发布校验与事务。

## 接入与授权

启用 `Weline_Api`，按 Api 模块已有应用授权流程创建应用、批准安装并交换
access token。安装使用 `subject_type=global`、`subject_id=0`，授予以下范围：

| 操作 | Scope | 权限类型 |
|---|---|---|
| 创建 | `Weline_Product::api_products_create` | edit |
| 编辑 | `Weline_Product::api_products_update` | edit |
| 详情 | `Weline_Product::api_products_detail` | read |
| 发布 | `Weline_Product::api_products_publish` | edit |

global 安装可以管理授权接口下的各 Website，`website_id` 是明确的目标站点，
不是令牌自身的授权边界。仅给受信任的目录管理应用授予写权限。
也支持既有 API 登录接口签发的 API 用户令牌，其角色须拥有目标 API 路由权限。身份只来自 Api 模块成功验证后绑定的只读对象，不接受请求体中的用户 ID，也不把普通前台会话或非 global 应用安装视为全站目录权限。
应用状态、安装状态、token、IP/UA 和 API scope 都由现有 Api/Acl 链校验。

授权方式见 `Weline_Api/doc/framework-api-and-auth-contract.md` 与
`Weline_Api/Api/Rest/V1/Apps.php`。调用时携带：

```http
Authorization: Bearer <access_token>
Content-Type: application/json
```

Controller 为 `Api/Rest/V1/Products.php`，动作分别是 `postCreate`、
`putEdit`、`getDetail`；URL 不包含 HTTP 方法前缀。

当前配置环境的基址为 `https://p05113ef3.test.weline.com:9555`，以下地址均经真实 HTTP 验证：

| 方法 | 路径 | 用途 |
|---|---|---|
| POST | `/api/weline_product/rest/v1/products/create` | 创建草稿 |
| PUT | `/api/weline_product/rest/v1/products/edit` | 增量编辑 |
| GET | `/api/weline_product/rest/v1/products/detail` | 读取快照、版本与真实店面链接 |
| POST | `/api/weline_product/rest/v1/products/publish` | 显式发布已保存的产品 |
| POST | `/api/api/api/rest/v1/apps/token` | 用授权码交换应用 token |

token 路径中的三段 `api` 是当前环境实际可用入口；仅由生成路由表拼接得到的
`/api/api/rest/v1/apps/token` 实测为 404。部署到其他环境时按实际路由配置验证，
不要把模块路由与区域前缀重复拼接规律套用于所有模块。
token 请求 JSON 包含 `client_id`、`client_secret`、`code`、`redirect_uri`，
成功后取 `data.access_token`。现有 token 接口的业务错误可能仍是 HTTP 200，
必须同时检查响应 `success` / `code`；本模块业务接口使用下文所列 HTTP 状态。

## 创建

对 Products 的 `create` 路由发送 POST JSON：

```json
{
  "website_id": 0,
  "request_hash": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "payload": {
    "name": "应用导入商品",
    "sku": "EXT-PRODUCT-001",
    "product_type": "simple",
    "store_ids": []
  }
}
```

`website_id` 必填，为非负整数；`payload` 是对象。普通商品至少提供 `name`
和 `sku`，类型缺省为 `simple`。其他类型、规格、属性、价格、媒体与店铺选择
仍遵循已有产品命令规则。创建时 `store_ids` 缺省使用目标 Website 的活动 Store；
显式 `[]` 表示不选择 Store。

`request_hash` 可选，为 64 位十六进制字符串；省略时服务端生成。
调用方需要重试同一次创建时应保留同一 hash 与同一请求内容，新产品使用新 hash。
hash 按安装、Website 与动作隔离；它不是 payload 内容校验器，改变 payload 后
不能把旧 hash 当作新的创建请求。成功 HTTP 201 返回 `data.identity`、
`data.offer_identities`、`data.product_id`。保存 `global_product_uuid` 供后续调用。

## 读取与编辑

对 `detail` 路由发送 GET，query 提供 `website_id` 和 `global_product_uuid`，
可选 `locale` 和 `currency`（缺省 CNY）。成功 HTTP 200 返回产品编辑快照；
`data.product.publish_version` 是编辑时应传的 `local_version`。

对 `edit` 路由发送 PUT JSON：

```json
{
  "website_id": 0,
  "global_product_uuid": "12345678-1234-4234-8234-123456789abc",
  "payload": {
    "local_version": 0,
    "name": "修改后的商品名称"
  }
}
```

编辑只传本次修改字段，未提交的属性、价格、媒体和店铺选择保留原值。
`local_version` 必填，沿用现有产品 CAS；成功响应 `data.local_version` 为新版本。
并发版本冲突时重新读取详情，合并修改后再提交。

支持既有保存命令中的 `name`、`short_description`、`description`、`meta_name`、
`meta_description`、`meta_keywords`、`attributes`、`prices`、
`category_assignments`、`media_assignments`、`store_category_overrides`、
`store_media_overrides`、`store_ids`、`inventory`、`offer_matrix` 与
`type_configuration`。集合字段显式 `[]` 的行为沿用相应产品命令；
例如 `store_ids: []` 会取消 Store 选择，不等于省略该字段。
六类文案可直接按请求语言提交，也可经 `translations` 或已登记的 `attributes` 写入；详见多语言指南。SKU 重命名、产品类型转换、生命周期
转换不属于此增量编辑接口；发布请使用下方 publish 动作，不要传 status 期待被隐式执行。

## 响应和错误

```json
{
  "success": false,
  "error_code": "product_name_required",
  "message": "product_name_required",
  "data": [],
  "code": 400,
  "error": true,
  "msg": "product_name_required"
}
```

JSON 保留布尔值和数字类型。输入错误为 400，缺少应用身份为 401，缺少范围
或安装作用域不适用为 403，不存在的商品/站点投影为 404，版本冲突为 409，
非 JSON 写入为 415，内部错误为 500。鉴权失败响应由统一 Api/Acl 层生成，
其结构可能与产品业务错误不同；先检查 HTTP 状态，再读取错误字段。
500 不向外部返回业务层异常类、SQL 或堆栈诊断。

## 显式发布与文档 Demo

保存后先调用 detail，分别取得 `data.identity.version` 与
`data.product.publish_version`，再 POST `/api/weline_product/rest/v1/products/publish`：

```json
{
  "website_id": 0,
  "global_product_uuid": "12345678-1234-4234-8234-123456789abc",
  "expected_version": 3,
  "payload": {"local_version": 7, "locale": "zh_Hans_CN", "currency": "CNY"}
}
```

数字仅为格式示例，必须使用当前回读版本。发布不会保存文案或价格，先通过 edit
提交内容。校验不通过返回 `product_publish_validation_failed` 和
`data.diagnostics`；通过后在既有事务中发布 Product 与可发布的 Offer。
成功返回 `data.product`、`data.offers` 与 `data.identity`，随后再 detail 回读。
版本冲突按 HTTP 409 处理，重新回读并合并后提交。

simple 产品最小 Demo 使用名称、唯一 SKU、`price_minor` 与 `currency`；价格采用
最小货币单位，CNY 的 100 表示 1 元，显式 0 合法。省略 `store_ids` 使用活动 Store；
空数组表示不选 Store，会令现有发布校验失败。零库存只产生现有警告，不添加媒体或库存的额外限制。

API 文档页面 `/dev/tool/docs/api` 的 Product Demo 使用当前已登录身份：
“创建并发布”依次调用 create → detail → publish → detail，并显示每步实际响应。
“保存当前语言”只发送当前语言名称；“保存多语言译文”只发送 JSON 译文，避免旧译文
覆盖另一操作的名称。`translate_to` 沿既有自动翻译服务，未配置可用渠道时如实返回 502。

详情中的 `data.storefront_urls` 是 Product 服务返回的数组，每项含 `loc`、
`product_id` 和 `store_id`。未发布或尚无可见 Store 时为空；页面仅打开这里的真实链接。
已发布产品编辑由既有 Product changed 链携带当前及先前 URL，实际店面内容、缓存/CDN
失效仍需在配置环境验收，不以接口保存成功推断传播完成。

## 调用示例

将 token 放入当前 shell 的 `PRODUCT_API_TOKEN` 环境变量，保存创建响应中的
`data.identity.global_product_uuid`。下面的编辑版本 `0` 仅适用于刚创建且尚未编辑的产品；
实际应使用详情回读的 `data.product.publish_version`。

```bash
PRODUCT_API_BASE='https://p05113ef3.test.weline.com:9555/api/weline_product/rest/v1/products'

curl --request POST "$PRODUCT_API_BASE/create" \
  --header "Authorization: Bearer $PRODUCT_API_TOKEN" \
  --header 'Content-Type: application/json' \
  --data '{"website_id":0,"payload":{"name":"应用导入商品","sku":"EXT-PRODUCT-001","store_ids":[]}}'

PRODUCT_API_UUID='<创建响应中的 global_product_uuid>'
curl --get "$PRODUCT_API_BASE/detail" \
  --header "Authorization: Bearer $PRODUCT_API_TOKEN" \
  --data-urlencode 'website_id=0' \
  --data-urlencode "global_product_uuid=$PRODUCT_API_UUID"

curl --request PUT "$PRODUCT_API_BASE/edit" \
  --header "Authorization: Bearer $PRODUCT_API_TOKEN" \
  --header 'Content-Type: application/json' \
  --data "{\"website_id\":0,\"global_product_uuid\":\"$PRODUCT_API_UUID\",\"payload\":{\"local_version\":0,\"name\":\"修改后的商品名称\"}}"
```

## 多语言扩展（首版 1.0.176，复验 1.0.184）

外部应用可通过 `locale` 或 `Accept-Language` 指定本次产品文案语言；`translations` 一次提交多个语言，`translate_to` 经已配置翻译渠道生成译文后统一保存。六类文案和自定义属性支持语言与店铺范围，现有产品类型、规格、价格、库存、分类和媒体仍使用原 `payload` 契约。

编辑只更新已提交的语言和字段，所有语言共用一次商品事务与版本检查；新建源文初始化一次默认回退，后续语言编辑不覆盖回退。详情保留原管理快照，增加 `data.locale`、`data.content`、`data.translations`。

完整语言优先级、创建/编辑 JSON 示例、手工译文优先规则、店铺属性、自动翻译失败行为见 [REST 多语言接入指南](rest-api-multilingual.md)。本次真实运行结果见 [多语言验收证据](rest-api-multilingual-acceptance.json)。下方 1.0.166 的 16 次 HTTP 记录为基础 REST 历史证据。

Product REST 定向单测 11 tests / 58 assertions 已通过。多语言创建、请求体 locale 与请求头语言编辑、一次保存多个语言、自定义属性语言、409/400 回滚，以及无渠道自动创建和编辑返回 502 且不写入，已通过真实 HTTP 验证。最终主阶段为 13 次请求、47/47 项断言；手工译文配合 translate_to 的优先覆盖补充为 4 次请求、15/15 项断言；请求头选择及查询参数优先的两个只读复验也通过。 官方路由升级完成后，语言选择、手工译文持久化及文档页共 4/4 项回读通过。 环境翻译渠道记录为 0，真实自动翻译成功仍待配置后验收。API 文档页已恢复 HTTP 200、`text/html`、1,165,919 字节；独立 fresh Weline Chrome 新任务标签页 `goto` 30 秒超时或返回 `Debugger unattached`，另一入口创建内置浏览器立即返回 `Browser is not available: iab`；均未在页面 UI 发请求，在线调用尚未验收。 验收商品 324、339、340 已归档，最终版本分别为 1、9、2；应用 3、4 及令牌已撤销，旧令牌实测 HTTP 401，私有验收凭据文件已删除。

## 开发验证

```bash
php vendor/bin/phpunit --bootstrap app/code/Weline/Product/Test/Unit/bootstrap.php app/code/Weline/Product/Test/Unit/Api/ProductRestInputTest.php
php bin/w setup:upgrade --route
```

2026-09-08 在上述 WLS HTTPS 环境完成 16 次 HTTP 验收调用：两类应用令牌交换成功，
创建为 201，重复创建返回同一产品，编辑与前后回读为 200；未授权/错误 token 为 401，
只读应用创建及编辑均为 403，畸形 JSON/缺少名称为 400，非 JSON 为 415，
旧版本编辑为 409，不存在产品为 404。

验收产品为 `322` / `728a1bbe-4b58-50dd-94a5-2a455a7710e3`，编辑版本由 `0` 到 `1`，
名称更新，未提交的 SKU 和短描述保留。定向单测为 6 tests / 31 assertions。
完整脱敏结果见 [REST 运行验收证据](rest-api-acceptance.json)，清理结果见开发日志。
