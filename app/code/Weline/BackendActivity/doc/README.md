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

本模块自身的后台控制器和 ACL 观察器显式依赖 `Weline_Backend`、`Weline_Acl` 与 `Weline_Framework`；权威声明位于 `etc/module.php`，Composer 元数据必须与其同步。

后台日志列表只通过 `Weline\Backend\Api\User\BackendUserAdministrationInterface`
按用户名匹配用户 ID，并为当前分页批量补全 `username`。不再 JOIN BackendUser Model，
因此密码哈希、Session ID 和登录 IP 不会被带入视图数组；页面显示、搜索、分页与排序保持不变。

同步与响应后延迟日志都通过
`Weline\Acl\Api\Authorization\AuthorizationServiceInterface::findRouteResource()` 获取不可变路由资源；
未找到或资源名为空时仍写入 `Unnamed Access` 和 `acl_id=0`，日志创建/响应指标更新顺序不变。
