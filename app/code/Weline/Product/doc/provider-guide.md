# Product Provider SPI 指南

`Weline_Product` 对外产品类型扩展点（P2A-005 / REQ-008）。只发布小接口，不暴露内部 Service/Model。

## 扩展位置

```
{Vendor}/{Module}/extends/module/Weline_Product/ProductProvider/{Name}Provider.php
```

声明：`Weline_Product/extends.php` → `ProductProvider`。

## 契约

实现 `Weline\Product\Api\ProductProviderInterface`：

| 方法 | 规则 |
|---|---|
| `getCode()` | 全局唯一（规范化小写） |
| `getType()` | 全局唯一产品类型键 |
| `getRequiredAttributes()` | 非空、去空白后不重复；注册时固化为发布/可售门禁快照 |
| `getCapabilityMap()` / `get*Capability()` | pricing / inventory / renderer 声明必须与对应接口实例一致 |
| `getMetadata()` | 可扩展注册元数据；code/type/required/capabilities 等权威字段由 Registry 覆盖；**禁止**触发渲染副作用 |
| `isEnabled()` | `false` 时 `getByType(..., onlyEnabled=true)` 不可见；默认 `simple` 仍可用 |

重复 `code` 或 `type`、可变/非法 required contract、capability
声明/实例不一致、匹配扩展点但类缺失/实例化失败/未实现接口，都通过
`ProductProviderConflictException` 硬失败。整个 Extends registry 不可用时，
内置 default 仍可启动；已匹配的坏扩展不得静默跳过。

## 内置默认

- code=`default`，type=`simple`
- required=`name`,`sku`
- capabilities：`DefaultProductPricingCapability` / `DefaultProductInventoryCapability` / `DefaultProductRendererCapability`
- Renderer 仅声明 scenes；真实 HTML 调度归 `TASK-P2C-001`

## Scene 渲染（P2C-001）

入口：`Service/ProductSceneRenderer::render(ProductSceneContext): ProductSceneRenderResult`

| 情况 | 行为 |
|---|---|
| 无 custom / 缺类 | 默认场景模板（白名单 scene） |
| Provider 未注册/已禁用 | 记录稳定错误码，使用内置 `simple/default` 场景模板 |
| scene 不在白名单 | fail-closed，返回空 HTML，不把未知 scene 当详情页 |
| custom 返回空且非 handled_empty | 视为 bug，回默认 |
| `handled_empty=true` | 真真空，不 fallback |
| custom 抛异常 | 记录错误并回默认 |
| `options.template(_path)` | 拒绝并回默认（防路径注入） |

自定义渲染器实现 `Api/ProductSceneRendererInterface`，FQCN 写在 `ProductRendererCapabilityInterface::getRendererClass()`；有构造函数的 renderer 通过 Framework ObjectManager 创建，构造依赖可正常注入。缓存键覆盖 scene/type/provider、Website/Store、完整 product/options，并对关联数组键排序后哈希，避免渲染输入变化复用旧 HTML。错误诊断只保存稳定错误码到 RequestContext，异常消息和商品字段不进入诊断缓冲，避免常驻 Worker 跨请求串数据。

Framework Hook：`HookRenderResult` + `Template::getHookResult($name, $force, $preferFallbackOnEmpty)`；仅当模板写了 `<else/>` 时运行时 opt-in fallback。fallback 判定使用未注入 DEV/visual-editor 注释的 fresh semantic render；`markHookHandledEmpty()` / HTML 标记 `<!--weline:hook:handled_empty-->` 只约束当前 render，阻止 fallback。

## 入口

| 类 | 用途 |
|---|---|
| `Service/ProductProviderRegistry` | register / get / getByType / listMetadata |
| `Service/DefaultProductProvider` | 内置简单商品 |
| `Api/Capability/*` | 小接口 |
| `extends/.../Query/ProductSceneQueryProvider` | 前台 `product_scene` harness（E2E；隔离 registry） |
| `extends/.../Query/ProductMediaQueryProvider` | 前台 `product_media` harness（E2E；真实 shard Media shareCopy/COW） |
| `Service/ProductSceneQueryHarnessCatalog` | var 夹具状态 |

## 验证

```bash
php bin/w phpunit:run --name=ProductProviderRegistryTest
php bin/w phpunit:run --name=ProductSceneRendererTest
php bin/w phpunit:run --name=BuiltInProductValidationTest
php bin/w phpunit:run --name=SubscriptionProductProviderTest

# Browser（需 WLS + PLAYWRIGHT_TARGET_ORIGIN）
PLAYWRIGHT_TARGET_ORIGIN=http://127.0.0.1:{port} PLAYWRIGHT_DISABLE_PROXY=1 \
  node tests/e2e/node_modules/playwright/cli.js test --config tests/e2e/playwright.config.js \
  app/code/Weline/Product/Test/e2e/frontend/plan-p2c-render-scene.spec.js --reporter=line
```

验收覆盖 metadata 防伪、注册后 required contract 不漂移、capability
一致性、重复 code/type、坏扩展 fail-fast，以及禁用扩展 Provider 后默认
type=`simple` 继续服务；Browser 面覆盖 TEST-P2C-RENDER-01/02/03（不得用单测冒充）。


## Provider V2

新 Provider 优先实现 `Weline\Product\Api\ProductProviderV2Interface`。它保留 V1 的 code/type/capability 契约，并新增：

- `getDefinition(): ProductTypeDefinition`：不可变 Offer 基数、后台 form schema、商品/Offer 必填属性和价格、库存、配送、数字交付、组合、渲染能力。
- `validateForPublish(ProductValidationContext): ProductValidationResult`：返回稳定错误码和按 Store/Offer 可定位的诊断；不得直接写数据库或执行渲染副作用。

现有 V1 Provider 由 `ProductProviderV1Adapter` 以“单 Offer、能力保守推断”的方式继续工作。标准类型由 Product 内置：普通实体、多规格、虚拟/服务、下载、组合。订阅、礼品卡、预约、租赁必须由所属模块通过 `extends/module/Weline_Product/ProductProvider/` 注册，禁止写入 Product 的硬编码分支。

发布规则：

- 草稿允许不完整。
- 发布统一经过 Provider 诊断。
- 主图默认非必填，除非定义显式要求。
- `amount_minor=0` 是有效零价；缺价、`cleared` 与零价不同。
- 零库存只产生警告并允许发布，购买阶段由库存/Cart 拒绝。
- 下载资产必须是 private 资产并由订单支付后的权益 Hook 发放。
- 组合 Provider 必须拒绝循环引用和未发布组件。

2026-08-26 证据：五类不可变定义、五类发布验证、下载权益和 Subscription
扩展注册均已通过正式 PHPUnit；Product 全量为 205 tests / 1485 assertions（1 skip、1 PHPUnit deprecation）。
独立 WLS 的 managed-child Master lease 故障使五类后台/前台 Browser 流程暂时
不可执行，因此这些开发证据不能单独把万能产品标记为 ACCEPTED。
