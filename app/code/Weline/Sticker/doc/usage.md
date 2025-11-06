## Weline_Sticker 使用文档

> 提供非侵入式修改其他模块文件的能力，通过编译机制将源文件与 Sticker 规则合并并输出到生成目录。

### 快速开始

- 模块：`Weline_Sticker`
- 源规则目录：`<你的模块>/extends/Weline_Sticker/<目标模块>/<相对路径>`
- 生成目录（编译输出）：`generated/extends/Weline_Sticker/`
- 注册表文件：`generated/sticker.php`
- 事件拦截：`Framework_View::fetch_file`
- 不使用：`module.xml`、Plugin（插件）。全部用事件实现。

### 目录约定

示例：对 `Weline_Sticker` 自身的模板 `view/templates/Test/index.phtml` 进行贴纸修改：
```
app/code/Weline/Sticker/extends/Weline_Sticker/Weline/Sticker/view/templates/Test/index.phtml
```
- 规则文件路径与目标文件路径严格一致（以模块根为前缀）。
- Sticker 文件内部使用 `w:sticker` 标签描述修改规则。

### 规则语法（w:sticker）

- 支持动作：`replace`、`before`、`after`
- 支持位置：`position`（全部、指定第 N 个、或区间 N-M）
- 目标与修改：`w:sticker:target`（匹配的代码），`w:sticker:code`（替换/插入的代码）

示例：
```html
<!-- 替换所有匹配项 -->
<w:sticker action="replace" position="all">
  <w:sticker:target>
    <p>原始文本</p>
  </w:sticker:target>
  <w:sticker:code>
    <p class="sticker-all">替换后的文本</p>
  </w:sticker:code>
  </w:sticker>

<!-- 仅在第2处匹配前插入 -->
<w:sticker action="before" position="2">
  <w:sticker:target>
    <div class="item"></div>
  </w:sticker:target>
  <w:sticker:code>
    <div class="sticker-inserted">插入内容</div>
  </w:sticker:code>
</w:sticker>

<!-- 在第1到第3处匹配后追加 -->
<w:sticker action="after" position="1-3">
  <w:sticker:target>
    <footer></footer>
  </w:sticker:target>
  <w:sticker:code>
    <div class="sticker-appended">后追加内容</div>
  </w:sticker:code>
</w:sticker>
```

匹配规则：
- 以源代码与目标代码均“去除多余空白”（保留字符串与标签内语义）的方式进行匹配。
- 通过 `position` 决定修改第几个匹配或匹配区间；`all` 表示全部。
- 冲突：同一“目标代码 + 相同位置索引”的修改会被判定为冲突。

### 编译与注册表

- 开发环境：
  - 监听文件修改时间（`filemtime()`）进行增量编译与注册表刷新。
  - `generated/sticker.php` 自动更新。
- 生产环境：
  - 不定时编译，推荐通过命令手动更新。
  - 注册表仅在执行命令或发布时更新。

输出目录与文件：
- 编译输出：`generated/extends/Weline_Sticker/`
- 注册表：`generated/sticker.php`

### CLI 命令

```bash
# 收集所有 Sticker 信息、检测冲突并更新注册表
php bin/w sticker:collect

# 刷新（重新编译）所有 Sticker 输出
php bin/w sticker:refresh

# 仅刷新指定模块（示例）
php bin/w sticker:refresh Weline_Sticker
```

实际命令名以 `Console/Command/Collect.php`、`Console/Command/Refresh.php` 为准。

### 模板加载优先级

- 监听 `Framework_View::fetch_file` 事件，在框架获取模板文件时：
  1) 先判断该模板所属模块是否存在于注册表中
  2) 若存在且该文件有规则，优先使用 `generated/extends/Weline_Sticker/...` 下的编译产物

### 冲突与通知

- 冲突检测：当多个 Sticker 修改“同一目标代码”的“相同位置索引”时，判定冲突
- 通知：使用 `Weline\\Admin\\Model\\System\\SystemNotification` 发送系统告警
- 记录：`Weline\\Sticker\\Model\\StickerLog` 记录错误与警告

### 常见问题

- 修改不生效？
  - 确认规则文件路径是否与目标文件路径一致
  - 确认 `generated/sticker.php` 是否已包含对应条目
  - 开发环境考虑清理 `generated/` 后重试

- 生产环境如何更新？
  - 执行收集与刷新命令，或在部署脚本中集成

——

示例参考：
- `view/templates/Test/*.phtml`
- `extends/Weline_Sticker/Weline/Sticker/view/templates/Test/*.phtml`

