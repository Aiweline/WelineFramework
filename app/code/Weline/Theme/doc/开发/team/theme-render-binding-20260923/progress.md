# 主题渲染与布局固化实施记录

计划：用户于 2026-09-23 明确批准本会话「主题渲染与布局固化优化计划」。

## 已冻结边界

- 非视觉运行时优化，work_mode=theme_module_runtime；保留三态身份、完整公共壳、必装、动态业务数据。
- 结构不变不写 phtml；配置独立版本绑定；部件注入结构差异更新所有主题对应布局。
- 正式身份 r 发布号不变，草稿 d 修订号；实体内 s 结构摘要与 configs 配置摘要目录，binding 原子切换。
- 验收：配置立即可见且模板 hash/mtime 不变，同结构发布复用，变更精准，全主题覆盖、人工卸载、并发完整、普通请求零结构组装。

## 分工与依赖

| 席位/代理 | 独占职责 | 依赖 |
|---|---|---|
| 项目经理 /root | Coordinator接线、迁移、文档、整体运行验收 | 以下三项接口 |
| 架构 /root/architecture_review | Binding、BindingStore、Paths、Materializer、行为测试 | 无 |
| 主题 /root/theme_review | ConfigStore、WidgetRenderer、SlotFiller、Chrome、行为测试 | Binding接口 |
| 部件 /root/injection_review | Widget注册差异、Merger、InjectionTargets、行为测试 | Coordinator由主代理接线 |

## 决策

- 继续当前 dev 脏工作区：用户明确要求保留当前修改，独立 HEAD 工作树缺少本次依赖的未提交实现。所有编辑先读磁盘，不清场、不回滚他人修改。
- get_edit_bundle/apply_compact_edit 未挂载；已 prepare_project，按仓库后文授权用兼容知识入口与原生定点编辑。
- 不改视觉样式，不新增业务工作流门禁，不新建缓存体系。
- 测试为实际行为及运行结果，不用锁定实现字符串替代正确性。

## 进度

- [x] 只读审查与用户批准计划
- [x] 当前分支、脏文件、MCP与学习冲突检查（dev；无学习冲突）
- [x] 最小失败用例
- [x] 结构/配置绑定与发布复用
- [x] 注入差异与所有主题目标（未映射历史关联单独报告）
- [x] 渲染路径、缓存与迁移（完整快照可原样转换，不猜卸载关联）
- [ ] 定向测试、一次独立复审、真实运行验收

## 验证记录（2026-09-23）

- 140 个定向行为/兼容测试、817 项断言通过。覆盖布局全套、历史版本解析、注册声明差异、路径键扫描和迁移命令。
- 真实注册刷新：更新 1 条，派发安装事件；第二次迁移处理 1392 个实体。
- 报告 1710 项待核对关联：629 草稿父基线缺失、1077 页面编辑版本缺失、4 公共壳版本关联缺失。关联缺失与迁移失败不是同义：完整快照可原样生成绑定，安装差异不能猜测卸载版本。
- 反射元数据和编译工厂已生成。结构/配置产物留在 var/runtime，不加入 Git。
- 首页真实 HTTP 曾200；正式浏览器必装菜单检查失败后，已定位并修复扫描器漏声明、chrome嵌套槽分类及快照格式，尚待最终浏览器复验。
- 后台默认注入 E2E 在 prepare_dashboard_identity 夹具中持续7.7分钟，采样显示PHP数组复制/执行，主动中止；3条未运行。单独自动保存 E2E 在后台登录超时，未进入编辑器。
- 当前运行验收阻塞于本机WLS Worker不持续存活，既有reload反馈无Worker。只恢复当前default实例，没有修改Framework或其他实例。
- 扩展Widget现有测试还有一个模型事务回滚namespace断言失败（AiWidgetRegistryCacheTest:177）；该测试不经过此次注册/注入路径，未扩大修复范围。
- 尚未取得相同条件的FPC/动态性能对比，不宣称提速，也不宣称完整验收通过。

### 后续真实复验与收口

- 新增产物级E2E断言：自动保存前后对比实际binding、phtml哈希/mtime及配置摘要；PHP/JS语法通过，真实操作仍停在登录阶段。
- stop/start后浏览器可以进入后台登录页，但正式框架两次WLS session bootstrap均重定向 `not_logged_in`，没有进入编辑器，不绕过鉴权。
- 首页all-menu的tv5/tv7快照本身完整；已修复bound片段提前跳过祖先编译HTML组合、PublishedSlotHost按名称忽略显式chrome归属、HeadAssets只持有最后一个祖先binding三处缺陷。真实槽fixture先红后绿。
- 最新定向测试：144 tests / 826 assertions通过。当前指针使用既有AtomicPublisher目录锁保护读改写，同值不写。
- 既有滚动重载发生Unknown error，已使用原有stop/start流程恢复当前default实例，最终首页复验结果另记。

### 最终环境状态

- 新实现全部定向测试通过：144 / 826。已补自动保存产物断言，但浏览器操作尚未到达这些断言。
- 最新首页复验仍在all-menu处失败；随后已修复祖先组合及SlotHost读取，最终代码因运行环境无法启动而尚未HTTP复验。
- 既有default实例滚动重载报Unknown error；stop/start先报fallback port范围错误；使用已验证29843端口启动又报 `Unix batch process creation is unavailable; refusing to register a child-owned PID in the parent.`，未修改Server实现或放宽其检查。
- 当前不能宣称本机实例已恢复，不能宣称完整验收通过。后续先恢复WLS进程创建及正式E2E后台登录，再运行首页、自动保存、默认注入、预览发布退出及性能对比。
- 原始验收日志保留在 `var/runtime/theme-layout-binding-acceptance-20260923/`（不入Git）。代码未提交，其他会话脏改保留。
