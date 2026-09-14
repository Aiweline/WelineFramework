# 产品 REST 多语言管理

本接口复用 ProductAdminCommand / ProductAdminRead、应用授权和商品事务。语言文案与 SKU、商品类型、规格、价格、库存、分类、媒体分开提交；这些公共商品能力继续使用既有 `payload` 契约。

## 路由与授权

| 方法 | 路径 | 应用权限 |
| --- | --- | --- |
| POST | `/api/weline_product/rest/v1/products/create` | `Weline_Product::api_products_create` |
| PUT | `/api/weline_product/rest/v1/products/edit` | `Weline_Product::api_products_update` |
| GET | `/api/weline_product/rest/v1/products/detail` | `Weline_Product::api_products_detail` |

使用已授权的 global 应用安装与 `Authorization: Bearer <access_token>`。写请求要求 `Content-Type: application/json`。成功创建为 HTTP 201，读取及编辑为 HTTP 200，响应类型为 JSON。

## 请求语言

语言优先级为：请求体顶层 `locale` → `payload.locale` → 查询参数 `locale` → `Accept-Language` → 框架当前请求语言。`Accept-Language` 按 `q` 权重选取已登记的语言；`q=0` 不参与选择。

使用框架语言目录中的完整代码，例如 `zh_Hans_CN`、`en_US`；支持对应连字符形式 `zh-Hans-CN`、`en-US` 和规范大小写。浏览器省略脚本的语言加地区形式会匹配已安装目录中唯一同语言、同地区的规范代码，例如 `zh-CN`、`zh_CN` 解析为 `zh_Hans_CN`。只含语言的 `en` 等简写、地区不匹配、显式脚本不匹配或有多个匹配项时不会自动推定；没有匹配语言的明确请求返回 `product_api_locale_unsupported`，不会悄悄写入默认语言。

名称、短描述、详情、SEO 标题、SEO 描述和 SEO 关键词是语言字段：`name`、`short_description`、`description`、`meta_name`、`meta_description`、`meta_keywords`。编辑时只改提交的语言和字段；未提交的语言、描述及商品公共字段保留。描述传空字符串代表明确保存空值；名称不能为空。

新建时，源语言文案同时初始化一次无语言的默认回退值，供既有默认展示与发布检查使用。后续指定语言的编辑只修改该语言，不覆盖这份回退值。

## 一次创建多语言商品

```json
{
  "website_id": 0,
  "locale": "zh_Hans_CN",
  "request_hash": "1111111111111111111111111111111111111111111111111111111111111111",
  "payload": {
    "product_type": "simple",
    "sku": "SHIRT-I18N-001",
    "name": "棉质衬衫",
    "short_description": "柔软透气的日常衬衫",
    "store_ids": []
  },
  "translations": {
    "en_US": {
      "name": "Cotton shirt",
      "short_description": "A soft, breathable everyday shirt"
    }
  }
}
```

`translations` 也可放在 `payload` 中；同一语言两处都有时，顶层字段优先。`store_ids: []` 创建未选择任何店铺的验收草稿；实际接入按既有商品可售店铺规则提交。商品类型可使用已安装提供者支持的其他类型，继续提交对应 `axes`、`offers`、`offer_matrix` 或 `type_configuration` 等结构参数。

`request_hash` 为可选 64 位十六进制幂等键；重试创建使用同一键和同一请求内容。键按应用安装、站点和动作隔离。编辑使用版本并发检查，不应把旧版本重试当作一次新的成功编辑。

## 指定语言编辑

先读取详情，将 `data.product.publish_version` 作为本次 `payload.local_version`。以下请求仅改英文名称，中文及中英文描述均保持原值：

```http
PUT /api/weline_product/rest/v1/products/edit
Authorization: Bearer <access_token>
Content-Type: application/json
Accept-Language: en-US
```

```json
{
  "website_id": 0,
  "global_product_uuid": "12345678-1234-4234-8234-123456789abc",
  "payload": {
    "local_version": 0,
    "name": "Updated cotton shirt"
  }
}
```

也可一次编辑多语言，省略单一语言的 `payload.name`，提交：

```json
{
  "website_id": 0,
  "locale": "zh_Hans_CN",
  "global_product_uuid": "12345678-1234-4234-8234-123456789abc",
  "payload": {"local_version": 1},
  "translations": {
    "zh_Hans_CN": {"name": "新款棉质衬衫"},
    "en_US": {"name": "New cotton shirt", "meta_name": "New cotton shirt"}
  }
}
```

所有语言与本次其他商品变更位于同一保存事务，成功后版本只增加一次。旧版本返回 HTTP 409，事务回滚，不会留下只保存一部分语言的状态。

## 自定义属性与店铺覆盖

每个语言分组可带 `store_id` 与 `attributes`。缺省店铺为 0（网站基础范围），店铺语言覆盖不会反写基础范围的本地化文案表。

```json
{
  "translations": {
    "en_US": {
      "store_id": 3,
      "name": "Store-specific shirt name",
      "attributes": [
        {"attribute_code": "source_public_specs", "value_type": "string", "value": "100% cotton", "scope_state": "explicit"}
      ]
    }
  }
}
```

属性必须已经在商品属性目录中登记，并符合原有类型与范围规则；示例属性仅在目录存在时使用。语言组内的属性跟随组语言，显式指定另一语言会报错。普通 `payload.attributes` 中未指定语言的行跟随请求语言，指定非空 `locale` 的行按该语言保存；显式 `locale: ""` 仍写无语言的公共范围。SKU、库存、规格结构等不放入语言分组。

## 2026-09-13 API 文档实际验收

- 已创建仅具产品创建、详情、编辑、发布四项权限的 API 用户，并通过文档顶部普通登录表单登录；没有使用后台会话注入代替 API 登录。
- 在 Product Demo 实际创建并发布产品 564，四步 HTTP 为 201 / 200 / 200 / 200。指定 en-US 独立编辑随后回读均为 200，英文更新、中文保持原值；Accept-Language zh-CN / en-US 分别返回 zh_Hans_CN / en_US，无效显式语言返回 400。
- 早先中英文批量写入曾出现提交后 409 和 ERR_CONNECTION_CLOSED，回读确认版本为 4；这些失败记录保留。2026-09-12 18:21 UTC（北京时间 2026-09-13 02:21）服务恢复后，同一正常登录用户从 Demo 再次批量编辑，实际 PUT 200 → GET 200，版本升到 5，两种语言正确。浏览器记录为单次 PUT，响应约 11.8 秒，回读约 1 秒；这次完整调用成功不能证明此前断链的所有原因都已消除。
- 已定位并修复 EAV 译文观察器误处理 Product 保存事件、同步扫描无关 LocalModel 译文的问题：先依据事件携带的模型限定到原有五类 EAV 元数据，再使用原请求 memo 和队列入口。普通热更新后，北京时间 2026-09-13 02:40 从 Demo 单次批量编辑再次 PUT 200 → GET 200，最终版本为 6，中英文内容正确；此次保存约 0.72 秒、回读约 0.43 秒。该时间来自共享运行环境的实际观察，不是隔离性能基准。
- 本轮也修复了 Demo 批量请求漏传当前语言、语言别名解析、浮动目录遮挡按钮、默认网站 URL 被读取路径覆盖，以及 IPC 缓存清理抹去活动 Fiber 请求上下文导致 ACL 空路由的问题。没有取消权限检查或乐观锁；Product 统一事务与单次版本递增规则保留。
- 定向回归通过：Product 8 项 / 41 断言、文档工具 Node 11 项、Websites 配置保留 1 项 / 23 断言、Framework 请求作用域 23 项 / 126 断言、EAV 触发范围 1 项 / 12 断言。普通资源编译与热更新成功。最终编辑前后，catalog namespace generation 1665 → 1667，projection event_seq 39935 → 39936；两者为站点级辅助证据，不能代替店面或每个观察器的执行回执。
- 完整浏览器验收仍未通过：店面标签页的 CDP 验收动作被浏览器安全策略拒绝，未换工具绕过；已新增真实 UI 全链路用例，但仅通过语法与官方用例收集，未宣称运行成功。此前断链原因仍需区分具体修复与服务恢复的影响。当前 CDN 账户与域名均为 0，没有外部 purge 成功证据。

### 内置浏览器补测（2026-09-13）

- 使用内置浏览器，在 API 文档 Product Demo 中通过普通登录完成产品管理；新建产品为 `565`，UUID 为 `25a53a76-d50a-5556-b6cd-50586bca34ce`，与此前产品 `564` 的记录分别保留。创建草稿、回读、发布、再次回读依次返回 201 / 200 / 200 / 200，发布后版本为 1。
- 请求语言指定 `en-US`，仅保存当前语言并回读，均返回 200，版本升到 2；英文更新为 `API IAB English-only edit 20260913`，中文仍为 `API 内置浏览器验收 20260913`。
- 请求语言指定 `zh-CN`，同时提交 `zh-CN`、`en-US` 两组译文，保存与回读均返回 200，版本升到 3。最终中文名称为 `API 内置浏览器多语言完成 20260913`，英文名称为 `API IAB multilingual verified 20260913`；响应语言归一化为 `zh_Hans_CN` / `en_US`。
- 共 8 步调用的页面响应均为 `success:true`，证据来自实际 Demo 调用记录及页面显示的响应 JSON。本轮未改源码。店面 URL 安全限制尚未解除，未通过内置浏览器绕过；前台即时更新、完整 E2E、外部自动翻译与 CDN 清理仍没有完成实测。

## 自动翻译后创建或编辑

在同样的创建或编辑请求中增加 `translate_to`：

```json
{
  "website_id": 0,
  "locale": "zh_Hans_CN",
  "payload": {
    "sku": "SHIRT-AUTO-001",
    "name": "棉质衬衫",
    "short_description": "柔软透气的日常衬衫",
    "store_ids": []
  },
  "translate_to": ["en_US"],
  "translations": {
    "en_US": {"name": "Our cotton shirt"}
  }
}
```

只翻译本次提交的六类源语言文案，不自动翻译 SKU、规格标识、历史未提交文案或自定义属性。已有的手工译文优先，例如上述英文名称保留手工值，仅自动生成英文短描述。目标语言未提交的其他字段保持原值。自动译文继承合并后的源语言组 `store_id`；目标语言已明确指定的店铺优先。

自动翻译调用现有 `translationService.batchTranslate` 公开 Query；全部译文准备成功后才调用商品事务。渠道不可用、请求失败或译文结果不完整时返回 HTTP 502 / `product_api_translation_failed`，不创建商品或部分更新语言。源语言必须明确有本次待翻译文案。

需要先在后台“系统服务 → 翻译服务 → 渠道配置”配置并启用渠道及支持语言。2026-09-13 的运行检查中，该环境渠道记录总数和启用数均为 0，真实自动翻译成功验收尚待渠道配置；不能把注入测试译文当成真实服务商翻译已通过。

## 读取与错误

```http
GET /api/weline_product/rest/v1/products/detail?website_id=0&global_product_uuid=<uuid>&locale=en_US
Authorization: Bearer <access_token>
```

详情保留既有商品、报价、价格、库存、分类、媒体、属性目录等管理快照，并增加：

- `data.locale`：本次读取语言。
- `data.content`：网站基础范围中，该语言优先于无语言回退的字段值；明确空值不会被默认文案覆盖。
- `data.translations`：网站基础范围已明确保存的各语言字段。
- `data.attributes`：保留完整原始属性行及语言、店铺、范围状态，供管理其他语言和店铺覆盖。

常见失败：401（应用凭据）、403（权限）、400（输入或语言无效）、404（商品不存在）、409（版本冲突或归档只读）、415（非 JSON 写请求）、502（自动翻译失败）。内部 SQL、渠道密钥和异常堆栈不返回给应用。

## 验收记录

实现前已通过真实 HTTP 复现：带中文 `locale` 和英文 `translations` 的创建返回 201，但仅写入默认语言的中文值，英文不存在。多语言首版为 `1.0.176`，本次复验模块版本为 `1.0.184`。

Product REST 定向单测 11 tests / 58 assertions 已通过。多语言创建、请求体 locale 与请求头语言编辑、一次保存多个语言、自定义属性语言、409/400 回滚，以及无渠道自动创建和编辑返回 502 且不写入，已通过真实 HTTP 验证。最终主阶段为 13 次请求、47/47 项断言；手工译文配合 translate_to 的优先覆盖补充为 4 次请求、15/15 项断言；请求头选择及查询参数优先的两个只读复验也通过。 官方路由升级完成后，语言选择、手工译文持久化及文档页共 4/4 项回读通过。 环境翻译渠道记录为 0，真实自动翻译成功仍待配置后验收。API 文档页已恢复 HTTP 200、`text/html`、1,165,919 字节；独立 fresh Weline Chrome 新任务标签页 `goto` 30 秒超时或返回 `Debugger unattached`，另一入口创建内置浏览器立即返回 `Browser is not available: iab`；均未在页面 UI 发请求，在线调用尚未验收。 验收商品 324、339、340 已归档，最终版本分别为 1、9、2；应用 3、4 及令牌已撤销，旧令牌实测 HTTP 401，私有验收凭据文件已删除。

真实请求状态、语言回读和阶段断言见 [多语言验收证据](rest-api-multilingual-acceptance.json)。历史失败阶段保留，按阶段分别统计；原 1.0.166 的 16 次基础 REST 调用见 [基础 REST 验收证据](rest-api-acceptance.json)。
