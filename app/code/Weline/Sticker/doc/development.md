## Weline_Sticker 开发文档

本模块通过事件与编译机制，为其他模块提供“非侵入式”的模板与代码片段修改能力。

### 关键点

- 事件：`Framework_View::fetch_file`（模板加载时优先使用编译产物）
- 注册表：`generated/sticker.php`（内存缓存 + mtime 校验）
- 规则扫描：`extends/Weline_Sticker` 目录递归
- 规则解析：`w:sticker` 标签（`action`、`position`、`w:sticker:target`、`w:sticker:code`）
- 编译输出：`generated/extends/Weline_Sticker/`
- 冲突检测：同一目标代码的相同位置索引视为冲突
- 通知与日志：`SystemNotification` + `StickerLog`
- 不使用 `module.xml`，不使用插件（Plugin），仅用事件

### 目录结构

```
app/code/Weline/Sticker/
  ├─ extends/Weline_Sticker/               # 源 Sticker 规则
  ├─ Service/Compiler.php                  # 编译器
  ├─ Service/RuleScanner.php               # 扫描器
  ├─ Service/RuleParser.php                # 解析器
  ├─ Service/StickerRegistry.php           # 注册表
  ├─ Service/ConflictDetector.php          # 冲突检测
  ├─ Observer/TemplateFetchFile.php        # 事件拦截
  ├─ Observer/SetupUpgradeAfter.php        # 升级后收集/检测
  ├─ Console/Command/Collect.php           # 收集命令
  ├─ Console/Command/Refresh.php           # 刷新命令
  ├─ Model/StickerLog.php                  # 日志模型
  └─ view/templates/...                    # 示例模板
```

### StickerRegistry 设计

- 路径：`generated/sticker.php`
- 读取：`include` 返回数组；若异常返回空数组
- 写入：`var_export` 写 PHP 数组文件；写后更新内存缓存与 mtime
- 缓存：内存缓存（数组） + 文件 mtime 校验
- API：
  - `getRegistry(bool $forceReload = false): array`
  - `saveRegistry(array $registry): bool`
  - `hasSticker(string $module, string $file): bool`
  - `getFileStickers(string $module, string $file): array`
  - `hasModuleStickers(string $module): bool`
  - `buildRegistryFromScanned(array $scan, RuleParser $parser): array`

### 规则解析与位置参数

- `action`: `replace|before|after`
- `position`: `all` 或 `N` 或 `N-M`
- 去空白匹配：移除非字符串/非标签的无意义空白与换行，保障跨格式一致性
- 解析错误：写 `StickerLog`，并跳过该规则

### 编译流程

1. 扫描 `extends/Weline_Sticker` 找到所有 Sticker 文件
2. 解析文件内 `w:sticker` 规则
3. 找到对应目标源文件并进行最小化（与规则一致的方式）
4. 按 `position` 与 `action` 应用修改，生成合并后的内容
5. 写入 `generated/extends/Weline_Sticker/<同路径>`
6. 更新 `generated/sticker.php`

### 冲突检测

- 相同目标代码 + 相同索引（位置） => 冲突
- 在 `setup_upgrade_after` 或收集流程中检测
- 冲突告警：`SystemNotification`
- 冲突记录：`StickerLog`

### 事件拦截（模板优先级）

`Observer/TemplateFetchFile::execute`：
1) 由模板完整路径提取模块名
2) 若模块在注册表中，检查该文件是否存在规则
3) 若存在规则且编译产物存在，则替换为生成文件路径
4) 开发模式对文件存在做 1 秒缓存，减少 IO

### CLI 工作流

- 收集：扫描 → 解析 → 构表 → 写注册表 → 冲突检测
- 刷新：按注册表/扫描结果执行编译，产物落在 `generated/extends/Weline_Sticker/`

### PHP 8.2+ 兼容要点（重要）

- 任何可能为 null 的参数都需 `??` 兜底（如 `htmlspecialchars($x ?? '')`、`json_decode($s ?? '', true)`）
- 数据库 `addColumn()` 的 `$options` 传空字符串而非 `null`
- `count()`/`array_merge()` 等对可能为 null 的数组使用 `?? []`

### 测试建议

- 单元测试：
  - `CodeMinifierTest`、`RuleParserTest`、`RuleScannerTest`、`StickerRegistryTest`、`CompilerTest`
- 集成测试：
  - `StickerIntegrationTest`（扫描 → 解析 → 注册表 → 编译）
- 隔离策略：
  - 用内存保存与还原注册表原始内容，`tearDown` 里恢复
  - 读写后 `clearstatcache(true, $file)`

### 性能建议

- 开发模式：`filemtime()` 轮询 + 1 秒级缓存
- 生产模式：关闭轮询，仅命令触发
- 仅对在注册表里的模块与文件做判断，减少不必要工作量

