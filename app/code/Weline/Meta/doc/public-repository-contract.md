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

## 读取语义

### Metadata

- `resolve()` 只按 `namespace + type + identify` 唯一键解析。
- `search()` 必须指定 namespace，可选精确 type/identify/area/category/filePath，或使用 `identifyPrefix`。
- 返回顺序按 `meta_identify ASC` 固定。

### MetaConfig

- `search()` 必须指定 owner 身份、namespace 和精确 scope。
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

- `upsert()` 只使用 Write DTO 中的精确 identity，不自动推断当前主题、area、scope 或 locale。
- Metadata Repository 只负责 Meta 持久化，不隐式写入 I18n 字典；需要翻译收集时由消费方通过已声明的 I18n/Event 契约单独提交。
- `delete()` 始终精确到 locale；DTO 的 locale 为 `NULL` 时只删除 SQL `NULL` 记录，绝不删除其他语言。
- 不存在的精确记录返回 `false`，不扩大删除范围。
- Repository 使用独立 Query 并感知事务所有权：没有外层事务时自行原子提交；已有外层事务时绝不提交或回滚调用方的事务。
- 写入成功后会清理 Meta 进程内旧缓存；消费模块仍负责清理自己的 L1/共享缓存与发布相应 epoch。

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
