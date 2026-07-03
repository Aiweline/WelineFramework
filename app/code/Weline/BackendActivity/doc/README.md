# Weline_BackendActivity

`Weline_BackendActivity` 负责记录后台请求与用户操作日志。模块会在后台控制器请求进入时创建日志，并在请求结束后写入响应状态、响应耗时等运行信息。

## 业务上下文

业务模块需要把某次后台请求关联到具体业务对象时，依赖公开契约：

```php
\Weline\BackendActivity\Api\BusinessContextInterface
```

调用 `mark($businessModule, $entityType, $entityId, $action, $title, $payload)` 后，当前后台请求日志会追加：

- `business_module`：业务模块，例如 `Weline_Cms`
- `business_entity_type`：业务实体类型，例如 `cms_page`
- `business_entity_id`：业务实体 ID
- `business_action`：业务动作，例如 `save`、`trash`、`restore`
- `business_title`：业务对象标题
- `business_payload`：业务上下文 JSON

WLS 持久化运行时下，GET/HEAD 请求可能延迟到响应后写日志。`BusinessContextInterface` 会同步更新延迟 payload，因此预览、跳转类请求也能保留业务对象关系。

## 跨模块边界

其他模块只允许依赖 `Api` 契约，不应直接调用 `Weline_BackendActivity\Service\*` 内部实现。服务实现和字段落库属于 Activity 模块内部细节。
