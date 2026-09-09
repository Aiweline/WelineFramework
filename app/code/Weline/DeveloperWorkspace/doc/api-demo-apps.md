# API 示例应用接入

API 文档页面 `/dev/tool/docs/api` 可从一个 API 组打开示例应用。模块声明输入字段、动作和响应绑定，页面使用当前登录身份调用实际 REST，展示请求、响应和可继续操作的数据。Product 是首个接入例子，用于创建产品、多语言编辑、发布及查看店面结果。

当前为功能接入契约与开发说明。实际页面创建、编辑、发布和店面可见流程尚未完成 WebUI 验收；语法检查、隔离测试或静态资源 HTTP 回读不能替代这些操作。自动翻译成功还需要已配置且可用的翻译渠道。

## 接入既有 API 文档收集事件

扩展点为 `ApiDocCollector::EVENT_COLLECT_AFTER`，事件名是 `Weline_DeveloperWorkspace::api_doc_collect_after`。Collector 先通过公开 `ApiDocumentationProviderInterface::generateAll()` 收集文档，再发出事件。事件数据为：

| 字段 | 类型 | 含义 |
|---|---|---|
| `apis` | array | 模块显示分组到 API 记录列表的映射，即 `apis[分组名][] = api` |
| `force` | boolean | 本轮是否请求强制重新生成文档 |
| `source` | string | 当前入口为 `developer_workspace` |

观察者读取 `apis`，在本模块已有 API 记录上追加 `demo`，最后 `setData('apis', $apis)`。分组名用于显示，不能假定它等于模块代码；通过记录的 `module`、`class` 和 `method` 识别接口。不要把声明写成单独的事件字段 `demos`，Collector 返回的是更新后的 `apis`。

```php
public function execute(\Weline\Framework\Event\Event &$event): void
{
    $apis = $event->getData('apis');
    if (!is_array($apis)) {
        return;
    }
    foreach ($apis as &$group) {
        if (!is_array($group)) {
            continue;
        }
        foreach ($group as &$api) {
            if (is_array($api)
                && ($api['module'] ?? '') === 'Vendor_Module'
                && ($api['class'] ?? '') === 'Vendor\\Module\\Api\\Rest\\V1\\Items') {
                $api['demo'] = $this->descriptor->describe();
            }
        }
        unset($api);
    }
    unset($group);
    $event->setData('apis', $apis);
}
```

这是观察者方法片段，`descriptor` 由接入模块提供。事件注册方式见 [API 文档收集后](hook/api-doc-collect-after.md)。新增或变更监听注册后执行官方 `php bin/w event:rebuild`，发布时按项目正常流程同步运行环境。

Docs 控制器随后按模块、版本、类组织记录。文档本地化保留 `demo`、字段默认值、请求和响应绑定。模块应在构造声明时提供已经本地化的标题、说明、字段标签和动作标签；通用页面的操作文案由模板 `config.text` 和模块 CSV 翻译提供。

## `api.demo` 声明

| 字段 | 类型 | 用途 |
|---|---|---|
| `id` | string | 同一示例应用的稳定标识；同组接口共享它 |
| `title` / `description` | string | 示例标题和说明 |
| `fields` | array | 输入字段及响应状态字段 |
| `actions` | array | 用户可执行的动作，可为单请求或多步骤 |
| `links` | array | 从响应回填的状态字段展示真实链接 |

一个 API 记录仍保留自己的 `module`、`version`、`class`、`method`、`route`、`document`、`parameters`、`responses` 和 `example`。Demo 是该记录的附加元数据；通用页面不需要 Product 的模型、服务或业务分支。

### 字段

每个字段使用 `name` 作为绑定标识，`label` 作为显示名称。`default` 保存初始值，`readonly: true` 表示由响应回填的只读状态。`options` 可以是值列表，或 `[{"value":"...","label":"..."}]`。`generate: "unique"` 配合 `prefix` 可在填写新示例时生成前缀加唯一值，例如 SKU。

| `type` | 表单值/绑定结果 |
|---|---|
| `string` | 字符串 |
| `number` | 数值 |
| `integer` | 整数 |
| `boolean` | 布尔值，保留 `false` |
| `json` | 解析后的 JSON 对象、数组或值，保留嵌套结构 |
| `locale` | 可填写语言代码；未提供 `options` 时由 `config.availableLocales` 提供输入建议，显式 `options` 使用下拉选择 |

字段初值可以包含数字 `0`、布尔值 `false`、空数组或对象，不需要转为字符串。格式错误会定位到输入，服务端仍负责完整的业务校验。

### 动作与步骤

| 字段 | 类型 | 用途 |
|---|---|---|
| `id` / `label` | string | 动作标识和按钮文案 |
| `required_fields` | string[] | 该动作当前需要填写的字段，不会把全表单都设为必填 |
| `api` | object | `{ "class": "完整PHP类名", "method": "实际PHP方法名" }` |
| `request` | object | 本次请求的所有业务参数 |
| `capture` | object | `目标字段名: "响应中的点分路径"` |
| `steps` | array，可选 | 顺序执行的步骤，每步含 `api`、`request`、`capture`，可带 `id`、`label` |

没有 `steps` 时，动作本身描述一次请求。存在 `steps` 时，页面依次调用各步，每步成功回填后再生成下一步请求。动作所需字段只描述开始动作前的输入；UUID、版本等中间结果可以由前一步提供。

`api.class` 和 `api.method` 对应已收集的真实 API 文档记录。`method` 是 `postCreate`、`putEdit`、`getDetail` 这类 PHP 方法名，HTTP 方法与路径来自记录的 `route`。示例不另行拼造业务 URL；依赖接口未收集时页面给出明确错误。

### 请求绑定

`request` 是所有输入的唯一来源，不会自动把整个表单发送给接口。GET 使用 query，其余 HTTP 方法使用 body。对象和数组中的绑定可递归组合：

```json
{
  "website_id": {"$field": "website_id"},
  "payload": {
    "name": {"$field": "name"},
    "price_minor": {"$field": "price_minor"},
    "enabled": {"$field": "enabled"},
    "attributes": {"$field": "attributes"}
  },
  "translations": {"$field": "translations"}
}
```

`{"$field":"字段名"}` 取当前字段值并保留类型。它是一个完整的绑定对象，不是字符串插值语法；`json` 字段中的多语言对象可以直接作为 `translations` 发送。

`{"$unique":"前缀"}` 在实际调用时生成前缀加 UUID，可用于需要唯一值的业务字段。它不是哈希算法。Product 的 `request_hash` 要求 64 位十六进制值，当前 Product Demo 省略该参数，由服务端按自己的契约生成，不把 UUID 填入 `request_hash`。

### 响应回填和链接

`capture` 从实际 JSON 响应中按点分路径取值，写回对应字段。例如 `"expected_version":"data.identity.version"`。回填后显示的数据和下一步输入都来自这次响应；页面同时保留请求、响应、步骤状态与调用记录。

`links` 使用 `[{"label":"查看店面产品","field":"storefront_urls"}]` 指定状态字段。字段支持 URL 字符串数组，也支持含 `loc` 或 `url` 的对象。通用 UI 只显示同源 HTTP(S) 链接，不给外部地址附带 Token。业务模块应在最终回读响应中提供真实地址，不让页面推测店面路由。

多步骤对应多次独立 REST 请求。步骤失败会停止后续调用并保留已完成记录，不自动撤销先前成功的创建、编辑或发布。应根据当前真实响应回读状态后继续操作，不能把跨请求流程当成一个数据库事务。

## 源文件与语言输入

通用脚本源文件为 `view/statics/js/api-docs.js`。执行 `php bin/w resource:compile welineUi` 后生成 Theme 的 `view/statics/ui/pages/weline-developer-api.js`；后者由编译器生成，修改源文件才能在后续编译中保留。

`type: "locale"` 未声明显式 `options` 时使用可填写的语言代码输入框，文档的 `availableLocales` 仅提供输入建议。产品内容语言可填写 `en_US` 等服务端已登记代码，不被当前文档译文语言列表限制；服务端仍校验语言是否合法。声明显式 `options` 时沿用下拉选项。

行为回归：在仓库根执行 `node app/code/Weline/DeveloperWorkspace/Test/Unit/ApiDocsIdentityDemoRegression.cjs`。它验证绑定、顺序调用、身份处理和语言输入；实际登录、产品写入与店面传播仍须浏览器验收。

## Product 首例

Product 声明对应 `Weline\Product\Api\Rest\V1\Products`，使用 `postCreate`、`putEdit`、`postPublish` 和 `getDetail`。创建和编辑不会隐式发布；“创建并发布”通过顺序步骤组合 `create → detail → publish → detail`，最终 detail 返回 `storefront_urls`。这条链路的字段及发布请求由 Product 模块维护。

| 响应来源 | 回填字段 | 响应路径 |
|---|---|---|
| create | `global_product_uuid` | `data.identity.global_product_uuid` |
| create | `product_id` | `data.product_id` |
| create / detail / publish | `expected_version` | `data.identity.version` |
| detail / publish | `local_version` | `data.product.publish_version` |
| edit | `local_version` | `data.local_version` |
| detail | `product_id` | `data.product.product_id` |
| detail | `storefront_urls` | `data.storefront_urls` |

下面是 Product detail 的最小绑定例子。它展示如何取回真实版本和店面链接；完整创建、编辑及发布声明由 Product 模块注册。

```json
{
  "id": "product-example",
  "title": "产品示例",
  "description": "回读当前产品版本和实际店面地址",
  "fields": [
    {"name": "website_id", "label": "Website ID", "type": "integer", "default": 0},
    {"name": "global_product_uuid", "label": "产品 UUID", "type": "string", "default": ""},
    {"name": "locale", "label": "语言", "type": "locale", "default": "zh_Hans_CN"},
    {"name": "expected_version", "label": "全局版本", "type": "integer", "default": 0, "readonly": true},
    {"name": "local_version", "label": "本地版本", "type": "integer", "default": 0, "readonly": true},
    {"name": "storefront_urls", "label": "店面地址", "type": "json", "default": [], "readonly": true}
  ],
  "actions": [
    {
      "id": "detail",
      "label": "回读产品",
      "required_fields": ["website_id", "global_product_uuid"],
      "api": {"class": "Weline\\Product\\Api\\Rest\\V1\\Products", "method": "getDetail"},
      "request": {
        "website_id": {"$field": "website_id"},
        "global_product_uuid": {"$field": "global_product_uuid"},
        "locale": {"$field": "locale"}
      },
      "capture": {
        "expected_version": "data.identity.version",
        "local_version": "data.product.publish_version",
        "storefront_urls": "data.storefront_urls"
      }
    }
  ],
  "links": [{"label": "查看店面产品", "field": "storefront_urls"}]
}
```

使用步骤：在 API 文档选择 Product 接口并打开示例；使用页面支持且服务端认可的登录身份；填写示例数据后创建产品；根据回填版本修改指定语言或一次提交多语言；执行发布并回读；通过返回链接打开店面核对实际内容。所有动作继续受真实 REST 的身份、权限、并发版本及发布条件约束。

使用手工 `translations` 与请求语言编辑可独立于自动翻译渠道运行。需要自动翻译时，仍由 Product REST 调用现有翻译服务；渠道未配置的真实错误应保留，不能用本地伪造译文表示成功。

## 运行验收记录

当前尚未从实际页面完成本专题流程。交付时应补充真实登录身份类别、各动作 HTTP 状态及业务结果、回读版本、各语言持久化结果和店面页面结果；只记录去除凭据的证据。API 文档静态资源能访问，不等于 Demo 已经执行成功。
