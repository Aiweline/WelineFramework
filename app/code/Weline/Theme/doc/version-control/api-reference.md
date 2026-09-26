# 主题版本 API 目标契约

> 2026-09-25，任务 4/6 后 scope-* 端点已挂载；旧 `ThemeLayoutVersion` 五组 HTTP 已删除。数据、事务及删除范围以[实施方案](../开发/spec/layout-entity-per-version-isolation.md)为准。

## 归属与路由

接口归属 `Weline_Theme`，由 `ThemeEditor` 控制器适配 Theme 自有版本服务。下表为控制器相对操作名，实际后台前缀由项目路由生成；不得硬编码 `/backend` 或环境专用前缀。页面通过已有 `Weline.Api`/资源调用机制访问，不手写 fetch。

| 方法 / 相对操作 | 控制器方法（目标） | 业务含义 |
|---|---|---|
| GET `scope-versions` | `getScopeVersions` | 列出指定 owner 的 sealed 历史、当前 P、当前 D/R 和仅归档旧记录 |
| POST `create-scope-draft` | `postCreateScopeDraft` | 按三种来源创建；普通续编返回现有 D；明确从历史/主题包另建时先自动备份原 D 再切新草稿 |
| POST `save-scope-version` | `postSaveScopeVersion` | 校验 D/R，将其封存为命名 N=D，默认不上线 |
| POST `publish-scope-version` | `postPublishScopeVersion` | 单页/明确集合/整主题发布 D，或发布已有 sealed H；共用一套编排 |
| POST `restore-scope-defaults` | `postRestoreScopeDefaults` | 备份当前编辑状态后，在新草稿恢复指定资源默认值 |
| POST `start-preview`（现有操作改契约） | `postStartPreview` | 签发固定 owner/V/mode/R 的真实预览 Token |
| scoped workspace 现有读写操作 | 现有方法改签名 | 加入 V/R 与 selection 校验，仍通过增量 changes 保存；不能另行发布孤立 page release |

画布切换到 H 只是只读加载明确版本；“从 H 继续编辑”才调用创建草稿。旧 `switch-version` 把页面快照复写当前工作区的方式删除。

## 公共输入

执行版本创建、封存、发布或恢复默认前，编辑器先完成待保存表单/增量并取得 D/R；失败保留当前编辑状态。所有输入先通过 typed context、后台权限和归属校验。客户端不决定实际磁盘路径、祖先链或跨主题身份。

| 字段 | 约束 |
|---|---|
| `editor_context` | 服务端规范化的 `ThemeEditorContext`，含 typed Scope、store mode、area、theme、资源/layout/target/locale。Scope payload 仍由现有公共 owner 解析，不在此复制一套新格式 |
| `theme_version_id` | 明确 `ThemeScopeVersion.version_id`；不能接受旧 ThemeLayoutVersion 数字别名 |
| `mode` | `draft/formal`；只读指定历史用 formal，普通正式请求不接受客户端覆盖 |
| `content_revision` | 读请求固定 R；写请求携带 `expected_content_revision` 并只写当前 D |
| `expected_selection_revision` | 写/发布前读到的 selection 代次，防止并发改变 published/draft 选择 |
| `expected_parent_release_id` / `expected_revision` | 涉及资源增量时继续保留既有 scoped 并发字段；不能被主题级 R 替代掉节点重基线校验 |
| `creation_source_kind` | 创建草稿时必需，三种明确枚举；普通编辑自动使用 continue_current，无新增弹窗 |
| `source_theme_version_id` | 仅 explicit_historical 时必需；同 owner、sealed、具有可渲染数据。服务器保存创建来源审计 |
| `publish_set` | `all` 或明确资源集合；页面集合必须列出 layout 身份和是否包含 chrome/appearance/theme_binding。服务端展开依赖并回显最终集合 |
| 现有幂等标识 | 对创建、封存和发布继续使用现有机制；重试返回同一 receipt，不制造重复版本 |

只有显式历史继承允许跨版本硬链；客户端不提供 `bake_inherit`、`inherit.json` 或可任意指定的文件地址。

## 单页发布的结果

准备时固定 P、D/R 和选择代次。N=D 的 sealed 修订只采用选中资源，其余固定准备时 P。未选 D 改动及决定重锚到 D'。B 可能是历史 X，因此不能错误地把“未选资源”退回 B。

响应至少返回：

- `theme_version_id`、正式 `content_revision`、`published_version_id`、新的 `selection_revision`。
- `draft_version_id` 与草稿修订（无剩余草稿则为空）。
- 最终 `published_resources`、`remaining_draft_resources` 和原有发布 receipt。
- Scope 传播实际更新的 owner、保留旧有效版本的冲突项，沿用现有冲突表达，不新增审批状态。

明确来源创建新草稿且原先已有 D 时，同时返回原 D 的自动备份版本，不覆盖未发布编辑内容。只保存命名版本时返回 N 与 P，并明确 `published=false`；不让“保存”默默上线。恢复默认返回自动备份版本与新 D，P 保持不变。

## Token 与错误边界

Token 保存 owner、V、mode、R、target 和既有有效期/权限信息。旧 draft Token 即使 D 已封存，也按原修订头读取 B、chrome 和资源；不会跳到版本行的新 R。Token 的 V/R 身份不被 URL 覆盖，locale 仍按现有文案预览规则处理。画布不调用 start-preview，画布与 Token 都绕过公共 FPC。

沿用现有错误/冲突框架区分：无权限或 owner 不匹配、预期修订冲突、不可转换旧记录、缺失明确数据引用、候选文件写入失败。派生文件缺失是可定点重建的 cache miss；不能伪装成成功并读旧版本。候选或 CAS 失败保留 P/D；默认注入未填仍按现有 soft-skip，不能增设店面硬失败。

## 删除旧契约

实施时移除旧 `versions/save-version/switch-version/restore-original/publish-version` 五条页面版本操作、成对 Payload 方法、模板 data-api、JS 调用与旧 Token 含义。删除 `page_type + 旧 version_id` 选版本、`normalizeLegacyLayoutSnapshot` 在线回放和节点投影猜历史的逻辑。一次性转换器可以读旧表，在线接口不能双读或接受旧字段别名。

当前 `ThemeLayoutVersion` 等表的原始数据保留用于转换/档案；表还在不代表旧 API 继续有效。新实现完成前不要用本文的新端点冒充可执行操作。
