# Theme Editor 作用域切换

Theme Editor 使用框架 canonical 三段存储值与独立 `store_mode` 表达四级继承上下文：

- Global：`default.default.default`
- Website：`{website}.default.default`（默认网站使用框架哨兵）
- Store：`{website}.{store}.default`
- Channel：`{website}.{store}.{channel}`（默认 Store/Channel 使用非法业务代码哨兵）

固定父链为 `Channel → Store → Website → Global → 主题包默认值`。当前层没有某条路径的 Patch 就表示继承；不复制父级快照，也不保存 `is_inherit`。Theme 绑定与所有配置使用同一规则。

## 顶部信息层级

作用范围是 Theme Editor 的最高级上下文。顶部上下文控件的视觉顺序和键盘顺序均为：

1. 作用范围
2. 编辑区域
3. 主题
4. 页面类型
5. 布局选项
6. 语言

作用范围固定在所有下游选择器之前。模板使用公共 `<w:scope>`，目录由身份 owner 贡献 Website、Store 与 Channel 的权威 ID/code/父级关系；Theme 不直接查询 Websites Model，也不从 Session 推断写目标。

SystemConfig 发布 `ScopeHierarchyInterface`、`ScopeIdentityCatalogInterface` 与 `ScopeSelectorCatalogInterface`；Websites 只通过能力目录贡献权威身份。Theme 持有布局、部件配置、版本与发布数据，并以 typed `ThemeEditorContext` 使用公共 Scope 契约。

## 切换与保存

切换作用范围时，编辑器先完成待提交增量；失败则保留原上下文。成功后释放旧锁、清空下游 Theme/布局/版本缓存，再用服务端规范 Scope 完整重载。读取、预览、锁、幂等、审计和保存均携带 typed `editor_context.scope`；旧 Scope 字符串只允许兼容读取。

Theme、标量字段、布局选择和稳定 `node_uid` 路径分别记录本级 Patch。界面显示“本级修改”或实际来源 Scope；“恢复继承”删除该字段及其子路径的本级 Patch。空字符串、`0`、`false` 与显式 `null` 都是自有值。

发布生成不可变 Release。父级未覆盖路径自动流入后代；后代自有标量继续优先；父级删除后代正在修改的节点、插槽或移动锚点会形成结构冲突。父级发布不被阻断，冲突后代继续服务最后有效 Release，编辑器提供重置、重新定位与重新基线化。

虚拟布局锁定时 Scope 选择器禁用并保留只读兼容身份。历史 `default` 归一到 Global；无法规范化的旧 Scope 只读展示，禁止产生新的非规范写入。

## 预览、迁移与回退

frontend 预览不得直接加载携带草稿参数的业务 URL。编辑器先向预览启动 API 提交 typed context，取得短期不透明 Token，再导航 iframe；Scope 快速切换时只允许最后一次启动响应更新 iframe。backend area 可保留受后台会话保护的直接预览。

迁移先运行 `php bin/w theme:scope:migrate preflight`，确认 Scope 碰撞、重复 `node_uid` 与歧义节点，再运行 `php bin/w theme:scope:migrate apply`。命令可幂等重跑；无法确定归属的旧 Scope 只记录兼容告警，不猜测覆盖，不删除旧表。回退代码时旧表仍可作为兼容投影读取，新表和 Release 不要求在当前版本清理。
