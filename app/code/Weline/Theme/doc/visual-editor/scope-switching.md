# Theme Editor 作用域切换

> 2026-09-25：保留逐值继承产品语义；主题版本、迁移和预览段按[主题固化物实施方案](../开发/spec/layout-entity-per-version-isolation.md)更新，相关业务改造待实施。

## Scope 与编辑所有权

Theme Editor 使用框架 canonical 三段存储值与独立 `store_mode` 表达上下文：Global 为 `default.default.default`，Website 为 `{website}.default.default`，Store 为 `{website}.{store}.default`，Channel 为 `{website}.{store}.{channel}`；实际默认站/店/渠道使用框架返回的哨兵，禁止手工猜值。

父链为 Channel→Store→Website→Global→主题包默认值。没有本级 Patch 表示继承；不把父值复制为本级用户修改，也不保存 `is_inherit`。空字符串、0、false、显式 null 都是自有值。恢复继承删除该字段及子路径的本级 Patch。

版本有效快照可以固定祖先来源与合成结果，用于一致渲染；它不改变上述编辑所有权。结构不按 locale/currency 分叉，语言资源保留自身身份。

## 上下文顺序与切换

顶部视觉与键盘顺序保持：作用范围 → 编辑区域 → 主题 → 页面类型 → 布局选项 → 语言。模板使用公共 `<w:scope>`；SystemConfig 的 ScopeHierarchy/ScopeIdentityCatalog/ScopeSelectorCatalog 接口提供规范目录，Websites 贡献身份，Theme 不直查其 Model 或用 Session 推断写目标。

切换时先完成待提交增量；失败保留原上下文。成功后释放旧锁、清理下游选择缓存并按服务端 context 重载。读写、锁、幂等和审计均携带 typed context；目标版本 owner 包含 `(theme_id,canonical_scope,store_mode,area)`，写入另带 D/R 和 selection 并发字段。

新运行只接受规范身份。历史 Scope 的别名/旧字符串只在一次性转换器解释，无法确定的原始记录只读归档。虚拟布局的 Scope 选择器可以保持锁定，但必须持有已解析的规范身份，不再借“只读兼容”使用歧义版本。

## 逐值继承如何与版本配合

父级未覆盖路径继续流入后代，本级标量覆盖优先。某槽有本级 ADD_NODE 时保留现有整槽截断规则，父变动不穿透该槽；其它未覆盖路径仍可传播。SET 不占用整槽。

父 P→P' 发布在冷路径复用现有冲突/rebase 规则：没有本级正式覆盖的范围直接回落；有 C 且有效值改变、无冲突的范围生成系统派生 C'。先准备完整候选，再在同一事务切父与可更新子 selection。父删除子正在编辑的节点/锚点产生结构冲突时，父可发布，冲突后代继续服务整套 C，page 与 chrome 不能分开切。

子草稿无冲突时生成新修订并重基线；冲突时保留旧修订和用户操作供恢复。历史版本读取固定祖先来源，不因以后父发布而漂移。正式请求先解析有效 owner/P，再读取同一版本的 page/chrome/assets，不逐页面/部件另查祖先最新版本。

## 预览三态

画布使用真实路由与已校验的编辑参数/typed context，不种店面 Token；真实前端预览才调用 start-preview，Token 固定 owner/V/mode/R。正式请求使用 RequestContext、已发布主题绑定和版本选择。详细规则见[预览与运行态](../preview-and-runtime-modes.md)。Scope 快速切换时仍只允许最后一次有效响应更新画布。

## 一次性转换与删除

旧文档曾给出的 `theme:scope:migrate preflight/apply` 当前未挂载，不得照文档当现成命令执行，也不能自写 SQL 猜删 Scope 表。

新模型按主方案实施一次性离线转换，核对规范身份、版本来源与完整内容，再硬切新运行；删除旧双读和 Adapter 兼容投影。无法证明的旧历史保留原始档案，不伪造可预览版本。业务原始数据和源模板不随派生文件 purge 删除。回退恢复匹配的 DB/代码备份，不在新运行临时启用旧表读取。
