# FPC 策略收集

## 复用事件与执行顺序

复用 Framework 已有 `Weline_Framework_Setup::after_route_collection`，不新增事件名称。触发方为 Framework 正式路由收集/升级流程；`Framework/etc/event.xml` 中中立注解收集 sort=120、Extra 声明侧车收集 sort=130。Cdn 的 `Weline_Cdn::collect_fpc_policies` 注册 sort=135，保证读取已写出的 Extra 声明侧车。

观察者：`Weline\Cdn\Observer\CollectFpcPolicies`。它调用本模块 `FpcPolicyManagementService::collectDeclarations()`，将本次结果置于事件数据 `fpc_policy_collection`，失败时记录原因。Framework 仅提供事件和快照公共接口，不直接依赖 Cdn 模型或服务。

## Payload

触发数据为 `touched_modules: list<string>|null`：非空列表表示本轮重收集模块，null 表示全量。策略收集仍以 ExtraCollector 已发布的完整声明侧车为来源，以免局部升级误删除未触及模块声明。

观察者追加 `fpc_policy_collection`，其结构复用管理服务 `{success, message, data, error_code?}`。服务成功表示收集/持久化并安排处理；此事件结果不证明源站策略已发布、云端已接受规则、purge 已完成或 HTTP 已验证。

## 处理与注册

声明投影与人工覆盖独立保存，身份由模块、类、方法及类型构成；改路径或重新收集保留覆盖。变化经现有 Queue 公共契约安排 `Weline\Cdn\Queue\FpcPolicySync`，依序发布源站编译快照、同步同 Zone 受管规则、处理累计清理目标。Queue 类型由正式 `queue:collect`/升级后置收集器登记。

注册变更必须递增 Cdn 模块版本并执行正式 `setup:upgrade`；有路由变更时执行路由收集。仅模型准备可通过正式 `--model --stage=schema_diff` 排除 route_update，但升级后置观察者仍应以实际日志核对，不能把参数名视为所有副作用均已跳过的证据。

详细策略和验收要求见 [FPC 策略管理与 CDN 自动同步](../开发/spec/cdn-fpc-policy-sync.md)；Queue 使用边界见 [Queue README](../../../Queue/doc/README.md)。
