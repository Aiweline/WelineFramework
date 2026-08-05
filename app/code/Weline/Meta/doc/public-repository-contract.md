# Meta 公共 Repository 契约

`Weline_Meta` 通过 `Weline\Meta\Api` 发布元数据与元配置的稳定 PHP 契约。其他模块只依赖这些接口和只读 DTO，不得直接引用 `Weline\Meta\Model`、`Service` 或 ORM Query Builder。

## 公开接口

- `MetadataRepositoryInterface`：搜索、精确解析、upsert 和精确删除 `w_meta` 记录。
- `MetaConfigRepositoryInterface`：搜索、单条/批量解析、owner scope 枚举、upsert 和精确删除 `w_meta_config` 记录。
- `ParamDefinitionNormalizerInterface`：统一 `@param` 注解、解析列表和参数 schema 的归一化。

实现类由 `etc/module.php` 的 `provides` 注册，`framework:compile` 生成静态 Provider 映射。消费方可以通过编译容器注入，或使用 `RuntimeProviderResolver` 按接口解析；不应按实现类名查找服务。

## DTO 边界

Repository 只接收 `Weline\Meta\Api\Data` DTO，只返回不可变 Record DTO：

- `MetadataIdentity / MetadataSearch / MetadataWrite / MetadataRecord`
- `MetaConfigIdentity / MetaConfigSearch / MetaConfigScopeSearch / MetaConfigWrite / MetaConfigRecord`

Record 不携带 Model、Collection、Query 或延迟加载器。`meta_data` 和 `setting` 在 Repository 边界内完成 JSON 编解码，消费方不感知存储格式。

Theme 等消费模块必须在调用前自行解析当前上下文，并将以下事实填入 DTO：

- `identifyId`：主题 ID 或其他所有者 ID；`"0"` 是合法值，不能用真值判断丢弃。
- `namespace`：已包含 area 的完整命名空间，例如 `theme.frontend`。
- `scope`：已解析的精确 scope，Repository 不做 scope 链回退。
- `locale`：请求语言，或在精确写入/删除时使用 `NULL` 表示通用值。
- `metaId / metaIdentify`：可选的 Meta 所有者身份；与 `identifyId` 同时提供时按 AND 精确匹配。

Repository 不反查 `ThemeContextService`、Cookie、Session 或当前请求的 area/scope。这保证 Meta 不反向依赖 Theme，也避免 WLS 长驻 Worker 串请求状态。

## Typed Scope 只读适配（TASK-P1C-005-META）

需要 Channel→Store→Website→Global 回落、来源徽章时，使用 `Weline\Meta\Service\MetaConfigTypedScopeService::resolveTyped()`（返回 `MetaConfigScopeValue` / `MetaConfigScopeSource`）。它在精确 Repository 之上叠加链解析；**不得**把 scope 回落塞进 `MetaConfigRepositoryInterface::resolve()`，以免破坏「Repository 不做 scope 链回退」契约。旧精确 `resolve()`/`upsert()` 保持只读兼容。

前台公开读取使用 QueryProvider 操作
`meta.resolvePublicCurrentScope(namespace, config_key, locale?)`。它只接受
`public.*` 命名空间，将 owner 固定为 `"0"`，并且只读取
`RequestContext::scopeIdentity()` 的可信当前 Scope；浏览器不能传入
Website/Store/Channel 或枚举实体 owner。缺少可信 Scope 返回
`meta_request_scope_unavailable`，非公开 namespace 返回
`meta_public_namespace_required`。

## 读取语义

### Metadata

- `resolve()` 只按 `namespace + type + identify` 唯一键解析。
- `search()` 必须指定 namespace，可选精确 type/identify/area/category/filePath，或使用 `identifyPrefix`。
- 返回顺序按 `meta_identify ASC` 固定。

### MetaConfig

- `search()` 必须指定 owner 身份、namespace 和精确 scope。
- SQL 条件只负责缩小候选集；`search()`、`resolveBatch()` 和 `listScopes()` 都必须在 PHP 中重新以存储字节做大小写敏感的精确比较。MySQL/SQLite 的大小写或重音宽松 collation 可以多返候选，不能扩大最终结果。
- `allLocales=false` 时 locale 为精确条件；`locale=NULL` 生成 `IS NULL`，不代表“任意语言”。
- 确实需要查询所有语言时才显式设置 `allLocales=true`，且不能同时提供 locale。
- `resolve()` 固定按“请求 locale → `zh_Hans_CN` → `NULL`”去重后回退，不改变 scope 和 owner 身份。
- `resolveBatch()` 用一次批量读取解析多个 identity，结果与输入下标一一对齐，用于取代 ThemeData 里的逐参数 N+1 查询。
- `listScopes()` 只按 `namespace + owner identity` 枚举去重后的 scope；它不读取当前 Theme/Session，也不把其他 owner 的 scope 混入结果。

### 批量目录消费模式

- 消费方应用一个有界 `MetadataSearch(namespace, area, identifyPrefix)` 取回同一目录的 option/field/directory Record，再在内存中按 `type/category/identify` 分组；禁止每个 option 分别查 name 和 description。
- 需要兼容历史 `.value` 配置时，每个逻辑键按 `[canonical, legacy.value]` 构造相邻 identity，一次传入 `resolveBatch()`；消费方依输入下标先取 canonical，再取 legacy，不得用返回顺序重新排序。
- `MetadataSearch` 不承担 Theme 目录规则；像 `colors/variables` 的空 category 兼容由 Theme 消费方在 Record 上精确过滤，Meta Repository 不反查 Theme 或扩大查询语义。

### ThemeData 消费约定

- `ThemeData` 只依赖本页公开 Interface、DTO 与 `ParamDefinitionNormalizerInterface`，不得再引用 Meta Helper、Model 或内部 Service。
- 配置值使用无 `.value` 后缀的 canonical `config_key` 写入；读取同时兼容历史 `.value` 键，canonical 记录优先。
- 单值热路径把最终字符串缓存在请求 L1 与共享运行时缓存；Meta Record DTO、Model、Collection 和 Query 不得进入共享缓存。
- `getParamValues()` 必须先收集全部非翻译参数 identity，再调用一次 `resolveBatch()`；禁止在参数循环中逐条查库。
- 列表预热先按精确 owner/namespace/scope 一次读取全部 locale，再在内存中按请求 locale → `zh_Hans_CN` → `NULL` 选择，不得让任意其他语言覆盖当前请求。

## 写入与删除

- `upsert()` 只使用 Write DTO 显式给出的 context 与 owner 条件，不自动推断当前主题、area、scope 或 locale。
- DTO 未提供的可选 owner 字段保持历史兼容语义：查询时只约束已提供的 `identifyId / metaId / metaIdentify`。Repository 按 context、精确 locale 和已提供 owner 查询后，必须再用 PHP 原始字节复核；恰好一个候选时沿用候选的完整 owner，多于一个候选按 `config_id` 报歧义，零候选的新写入才把缺省 owner 保存为 SQL `NULL`。
- 完整存储身份确定后，`upsert()` 先按七字段 SHA-256 指纹定位，再逐字段按原始字节复核 `namespace + config_key + scope + locale + identify_id + meta_id + meta_identify`；即使出现理论哈希碰撞或并发唯一冲突，也不得更新另一条 identity。
- Metadata Repository 只负责 Meta 持久化，不隐式写入 I18n 字典；需要翻译收集时由消费方通过已声明的 I18n/Event 契约单独提交。
- `delete()` 始终精确到 locale；DTO 的 locale 为 `NULL` 时只删除 SQL `NULL` 记录，绝不删除其他语言。
- 不存在的精确记录返回 `false`，不扩大删除范围。
- Repository 通过 `WriteIntentTransactionCoordinatorInterface` 进入写意图事务。没有外层事务时可对已确认的死锁/序列化冲突有界重试；已有外层事务时仍必须进入 coordinator 的嵌套写 scope，成功不提交外层事务，任何内层异常则把外层标记为 rollback-only。调用方捕获异常后也不能把半完成的 upsert 提交。
- 并发新建使用数据库方言的原子 no-op upsert，再用 locking/current read 回读完整七字段。MySQL 的 `ON DUPLICATE KEY` 对任意 UNIQUE 冲突都可触发，因此冲突分支只能把已存指纹自赋值，不得用输入指纹改写其他行。
- 兼容 `MetaConfig::setConfig/getConfig/deleteConfig` 不得绕过上述原字节语义。`getConfig()` 按“请求 locale → 调用方给定的 default locale → `NULL`”逐层调用精确 search，同层多候选必须失败；`deleteConfig()` 先用 Repository 找原字节候选，再在该 legacy Model 当前连接的同一写事务内按 `config_id + fingerprint` 删除。每行删除前都要从事务连接回读七字段与指纹；Repository/Model connector 被错配、指纹伪造或中途失败时整批回滚，不得部分删除。
- 写入成功后会清理 Meta 进程内旧缓存；消费模块仍负责清理自己的 L1/共享缓存与发布相应 epoch。

### `identity_fingerprint` 第一阶段约束

- 指纹由固定顺序的七个身份字段生成，使用带类型和字节长度的无歧义编码后计算 SHA-256，输出固定为 64 位小写十六进制。
- 生成器本身不 trim、不做 Unicode normalization、不 case-fold；Repository 在调用生成器前继续执行其原有的 namespace/config key/scope/owner trim 语义，locale 保持原值。
- 模型声明中的 `nullable=true` 仅是 `1.0.1` 旧数据迁移窗口，不是公开写入契约。Repository、兼容 `setConfig()` 和直接 Model `save()` 的应用写入都必须非空。
- 应用边界在三库之前统一拒绝非 UTF-8 或超长值：namespace/scope 最多 100 字符，config key/value 最多 255，locale 最多 20，字符串 owner 最多 255，`meta_id` 必须为 1..2147483647；三个 owner 不得同时缺失。
- 直接 Model 只允许两种更新：仅 `config_id + value` 的部分更新，或同时提供全部七个身份字段的完整身份更新。value-only 不得把补读的身份写回 Model，并要求存储指纹已存在且与当前行匹配；不完整身份、单独伪造指纹或无 owner 直接写入必须失败。
- `Setup/Upgrade.php` 只做数据迁移，不做 DDL：先全表验证已有非空指纹并检测精确重复/理论碰撞，任何问题都在首次写入前按 `config_id` 失败；之后事务化回填 `NULL`，最终断言 `NULL=0` 且无重复。
- 第二阶段必须在所有环境完成第一阶段验收后另升版本，将字段收紧为 `NOT NULL`；不得把两个阶段合并成一次不可恢复变更。

## 迁移对照

| 旧用法 | 新契约 |
|---|---|
| 跨模块直接查 `Meta` Model | `MetadataRepositoryInterface::search/resolve` |
| 跨模块直接查 `MetaConfig` Model | `MetaConfigRepositoryInterface::search/resolve/resolveBatch/listScopes` |
| `MetaConfig::setConfig/deleteConfig` | Repository `upsert/delete` + 显式 DTO |
| 直接引用 `Service\ParamDefinitionNormalizer` | `ParamDefinitionNormalizerInterface` |

迁移过程不得把 ORM Model 放进 DTO 或缓存，也不得为了兼容在 Meta Repository 内重新引入 Theme 上下文查询。

旧 Meta facade 若为兼容调用补全当前主题 ID，只能解析 Framework 的
`ThemeContextProviderInterface`，并把返回对象立即收敛为标量 ID；Meta 源码不得出现
`Weline\Theme\*` 类型、主题 Service 或主题 Model。

## Meta 消费 I18n 的边界

Meta Helper、Taglib 和后台翻译控制器只能解析
`Weline\I18n\Api\Translation\DictionaryRepositoryInterface`，并消费不可变
`DictionaryEntry` DTO。读写身份固定为 `word + localeCode`；I18n Model、ORM Query、表字段常量、
MD5 指纹和 upsert 实现均是 `Weline_I18n` 内部细节，不得重新进入 Meta 源码或编译模板。

`w:meta` 的 scope 读取顺序保持为「带 scope 的 word → 不带 scope 的 word」；
MetaData 的 locale 查找顺序保持为「请求 locale → `zh_Hans_CN`」，两者都缺失时继续使用原有调用点的字段值或 `null` 回退。
批量标签翻译先一次读取请求 locale，仅对缺失键再批量读取默认 locale，禁止回退为逐词 ORM 查询。
