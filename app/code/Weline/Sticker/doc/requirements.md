## Weline_Sticker 需求与约定（归档）

### 背景与目标

提供"非侵入式"的方式修改其他模块的文件，通过 Sticker 规则在编译阶段与源文件合并，输出到生成目录，运行时优先加载编译产物，避免直接改动上游模块代码。

### 核心需求

- 规则载体：直接在同路径的文件内使用 `w:sticker` 标签（不再使用独立 XML）
- 匹配机制：去除多余空白的代码匹配，支持 `position`（all、N、N-M）
- 输出目录：`generated/extends/module/Weline_Sticker/`
- 注册表：`generated/sticker.php`，开发模式自动更新，生产模式用命令更新
- 加载拦截：事件 `Framework_View::fetch_file`，优先使用编译产物
- 冲突检测：同一目标代码在相同索引的多处修改视作冲突
- 日志记录：`Model/StickerLog.php` 保留，记录错误/告警
- 管理后台：提供 Sticker 列表与详情页（筛选、查看规则来源与目标）
- 单元与集成测试：覆盖扫描、解析、注册表、编译、位置参数、整体流程

### 显式约束

- 不使用 `module.xml`
- 不使用 Plugin（插件），通过事件实现拦截
- Sticker 源目录：`extends/module/Weline_Sticker`
- 规则路径与目标文件路径保持一致（模块前缀 + 相对路径）

### 事件与命令

- 事件：
  - `setup_upgrade_after`：升级后收集/检测并更新注册表
  - `Framework_View::fetch_file`：模板加载拦截
- 命令：
  - 收集：更新注册表并冲突检测（示例：`php bin/w sticker:collect`）
  - 刷新：重新编译（示例：`php bin/w sticker:refresh`）

### 冲突策略

- 冲突定义：同一目标代码 + 相同位置索引（position）
- 冲突处理：
  - 记录到 `StickerLog`
  - 通过 `SystemNotification` 提示管理员
  - 不阻断其它不冲突规则的生效

### PHP 8.2+ 兼容要求（重要）

- 禁止向字符串/数组函数传 `null`，统一 `??` 兜底
- `json_decode($s ?? '', true)`、`htmlspecialchars($x ?? '')`、`count($a ?? [])`、`array_merge($a ?? [], $b ?? [])`
- 数据库 `addColumn()` 的 `$options` 必须为字符串（可空传空字符串）

### 命名与配置规范

- 事件 XML：命名空间 `xmlns:xs="http://www.w3.org/2001/XMLSchema-instance"`
- Schema：`urn:Weline_Framework::Event/etc/xsd/event.xsd`
- observer 属性齐全：`disabled`、`shared`、`sort`
- observer 命名：`Module::observer_name`

### 验收清单

- 规则扫描/解析/编译/注册表/冲突检测/通知/后台 UI/测试 全部可用
- 生成优先级：`TemplateFetchFile` 能正确替换为 `generated/extends/module/Weline_Sticker/...`
- 注册表在开发环境能自动刷新；生产可通过命令刷新