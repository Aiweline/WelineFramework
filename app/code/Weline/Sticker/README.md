# Weline_Sticker 模块

## 📋 目录

- [模块概述](#模块概述)
- [实现目的](#实现目的)
- [核心功能](#核心功能)
- [实现逻辑](#实现逻辑)
- [工作流程](#工作流程)
- [目录结构](#目录结构)
- [使用指南](#使用指南)
- [技术细节](#技术细节)
- [命令说明](#命令说明)
- [常见问题](#常见问题)

---

## 模块概述

**Weline_Sticker** 是 Weline Framework 的一个核心模块，提供了一种**非侵入式**修改其他模块文件的能力。通过编译机制将源文件和 Sticker 规则合并，输出到生成目录，运行时优先加载编译产物，避免直接修改上游模块代码。

### 核心优势

- ✅ **非侵入式**：不直接修改源文件，保持代码整洁
- ✅ **可维护性**：修改集中在 Sticker 规则文件中，易于管理
- ✅ **可追溯性**：所有修改都有明确的来源和规则
- ✅ **冲突检测**：自动检测多个 Sticker 之间的冲突
- ✅ **开发友好**：开发环境自动编译，生产环境手动控制

---

## 实现目的

### 1. 解决的核心问题

在模块化开发中，经常需要修改第三方模块或框架核心模块的文件，但直接修改会带来以下问题：

- **升级困难**：直接修改的代码在模块升级时会被覆盖
- **维护困难**：修改分散在各个文件中，难以追踪和管理
- **冲突风险**：多个模块同时修改同一文件时容易产生冲突
- **代码污染**：直接修改破坏了模块的原始结构

### 2. 设计目标

Sticker 模块旨在通过以下方式解决上述问题：

1. **集中管理**：所有修改规则集中在 `extends/Weline_Sticker` 目录下
2. **编译机制**：通过编译将规则应用到源文件，生成最终文件
3. **运行时拦截**：在模板加载时自动使用编译后的文件
4. **冲突检测**：自动检测并报告规则冲突
5. **日志记录**：记录所有操作和错误，便于排查问题

---

## 核心功能

### 1. 规则扫描（RuleScanner）

- **功能**：扫描所有模块的 Sticker 目录
- **扫描范围**：
  - 模块 Sticker：`extends/module/Weline_Sticker/{模块名}/{文件路径}`
  - 主题 Sticker：`extends/theme/{主题名}/Weline_Sticker/{模块名}/{文件路径}`
- **输出**：返回所有发现的 Sticker 文件信息

### 2. 规则解析（RuleParser）

- **功能**：解析 Sticker 文件中的 `w:sticker` 标签
- **支持的操作**：
  - `replace`：替换目标代码
  - `before`：在目标代码前插入
  - `after`：在目标代码后插入
- **位置控制**：
  - `all`：匹配所有位置
  - `N`：匹配第 N 个位置
  - `N-M`：匹配第 N 到 M 个位置

### 3. 注册表管理（StickerRegistry）

- **功能**：管理 Sticker 规则的注册表缓存
- **存储位置**：`generated/sticker.php`
- **数据结构**：
  ```php
  [
    '目标模块名' => [
      '目标文件路径' => [
        [
          'source_module' => '来源模块',
          'sticker_file' => 'Sticker文件路径',
          'actions' => [规则数组]
        ]
      ]
    ]
  ]
  ```

### 4. 代码压缩（CodeMinifier）

- **功能**：压缩代码以进行精确匹配
- **规则**：
  - 保留字符串内容（单引号、双引号内）
  - 保留 HTML/XML 标签结构
  - 去除其他位置的空白字符和换行
  - 保留注释内容

### 5. 冲突检测（ConflictDetector）

- **功能**：检测多个 Sticker 规则之间的冲突
- **冲突定义**：同一目标代码的相同位置索引被多个 Sticker 修改
- **检测策略**：
  - 按目标代码分组
  - 检查每个位置的修改来源
  - 报告冲突详情

### 6. 编译服务（Compiler）

- **功能**：将源文件与 Sticker 规则合并，生成最终文件
- **流程**：
  1. 读取源文件
  2. 压缩源文件
  3. 查找所有匹配位置
  4. 按位置从后往前应用规则（避免位置偏移）
  5. 输出到 `generated/extends/Weline_Sticker/`

### 7. 模板拦截（TemplateFetchFile Observer）

- **功能**：在模板加载时拦截，使用编译后的文件
- **事件**：`Weline_Framework_View::fetch_file`
- **逻辑**：
  1. 检查文件是否有 Sticker 规则
  2. 检查编译文件是否存在
  3. 开发环境：检查源文件是否更新，自动重新编译
  4. 替换文件路径为编译文件路径

### 8. 通知服务（NotificationService）

- **功能**：发送系统通知
- **通知类型**：
  - 规则失效警告
  - 目标代码未找到
  - 冲突检测结果

---

## 实现逻辑

### 1. 整体架构

```
┌─────────────────────────────────────────────────────────────┐
│                      Sticker 模块架构                         │
└─────────────────────────────────────────────────────────────┘

┌──────────────┐      ┌──────────────┐      ┌──────────────┐
│  规则扫描     │ ───> │  规则解析     │ ───> │  注册表管理   │
│ RuleScanner  │      │ RuleParser   │      │StickerRegistry│
└──────────────┘      └──────────────┘      └──────────────┘
                                                      │
                                                      ▼
┌──────────────┐      ┌──────────────┐      ┌──────────────┐
│  冲突检测     │      │  代码压缩     │      │  编译服务     │
│ConflictDetect│      │CodeMinifier  │      │  Compiler    │
└──────────────┘      └──────────────┘      └──────────────┘
                                                      │
                                                      ▼
┌──────────────┐      ┌──────────────┐      ┌──────────────┐
│  模板拦截     │      │  通知服务     │      │  日志记录     │
│TemplateFetch │      │Notification  │      │ StickerLog   │
└──────────────┘      └──────────────┘      └──────────────┘
```

### 2. 数据流

```
源文件 (app/code/...)
    │
    ├─> 规则扫描 ──> 发现 Sticker 文件
    │
    ├─> 规则解析 ──> 提取 w:sticker 标签
    │
    ├─> 注册表构建 ──> 生成注册表缓存
    │
    ├─> 冲突检测 ──> 检查规则冲突
    │
    └─> 编译服务 ──> 合并源文件和规则
                    │
                    └─> 生成文件 (generated/extends/Weline_Sticker/...)
                            │
                            └─> 模板拦截 ──> 运行时使用编译文件
```

### 3. 匹配机制

#### 代码压缩规则

1. **保留内容**：
   - 字符串内容（单引号、双引号内）
   - HTML/XML 标签及其属性
   - 注释内容

2. **去除内容**：
   - 标签外的空白字符
   - 标签外的换行符
   - PHP 注释（单行 `//` 和多行 `/* */`）

#### 匹配流程

```
原始代码 ──> 压缩 ──> 查找匹配 ──> 应用规则 ──> 生成文件
```

#### 位置控制

- `position="all"`：匹配所有出现的位置
- `position="1"`：只匹配第 1 个位置
- `position="2-5"`：匹配第 2 到第 5 个位置

### 4. 冲突检测逻辑

1. **分组**：按目标代码（压缩后）分组
2. **索引映射**：记录每个位置索引被哪些 Sticker 修改
3. **冲突判断**：同一索引被多个 Sticker 修改时判定为冲突
4. **报告**：生成详细的冲突报告

---

## 工作流程

### 1. 收集阶段（Collect）

```bash
php bin/w sticker:collect
```

**流程**：
1. 扫描所有模块的 Sticker 目录
2. 解析每个 Sticker 文件中的规则
3. 构建注册表数据结构
4. 检测冲突
5. 保存注册表到 `generated/sticker.php`

### 2. 编译阶段（Refresh）

```bash
php bin/w sticker:refresh
```

**流程**：
1. 读取注册表
2. 遍历所有需要编译的文件
3. 读取源文件并压缩
4. 应用所有 Sticker 规则
5. 输出编译文件到 `generated/extends/Weline_Sticker/`

### 3. 运行时阶段

**流程**：
1. 框架触发 `Weline_Framework_View::fetch_file` 事件
2. `TemplateFetchFile` Observer 拦截
3. 检查文件是否有 Sticker 规则
4. 检查编译文件是否存在
5. 开发环境：检查源文件是否更新，自动重新编译
6. 替换文件路径为编译文件路径
7. 框架加载编译后的文件

### 4. 升级后自动处理

**流程**：
1. 模块升级触发 `setup:upgrade_after` 事件
2. `SetupUpgradeAfter` Observer 执行
3. 自动执行收集和冲突检测
4. 发现冲突时抛出异常，中断升级流程
5. 无冲突时更新注册表

---

## 目录结构

### Sticker 规则目录

```
<模块根目录>/
└── extends/
    └── module/
        └── Weline_Sticker/
            └── {目标模块路径}/
                └── {目标文件路径}
```

### 生成目录

```
项目根目录/
└── generated/
    ├── sticker.php                    # 注册表缓存
    └── extends/
        ├── module/
        │   └── Weline_Sticker/
        │       └── {目标模块路径}/
        │           └── {目标文件路径}  # 编译后的文件
        └── theme/
            └── Weline_Sticker/
                └── {主题名}/
                    └── {目标模块路径}/
                        └── {目标文件路径}
```

### 示例

**目标文件**：
```
app/code/Weline/Demo/view/templates/Backend/index.phtml
```

**Sticker 规则文件**：
```
app/code/Weline/MyModule/extends/module/Weline_Sticker/Weline/Demo/view/templates/Backend/index.phtml
```

**编译输出文件**：
```
generated/extends/module/Weline_Sticker/Weline/Demo/view/templates/Backend/index.phtml
```

---

## 使用指南

### 1. 创建 Sticker 规则文件

在您的模块中创建 Sticker 规则文件，路径必须与目标文件路径一致：

```
您的模块/extends/module/Weline_Sticker/{目标模块路径}/{目标文件路径}
```

### 2. 编写规则语法

```html
<!-- 替换所有匹配项 -->
<w:sticker action="replace" position="all">
  <w:sticker:target>
    <p>原始文本</p>
  </w:sticker:target>
  <w:sticker:code>
    <p class="modified">修改后的文本</p>
  </w:sticker:code>
</w:sticker>

<!-- 在指定位置前插入 -->
<w:sticker action="before" position="2">
  <w:sticker:target>
    <div class="item"></div>
  </w:sticker:target>
  <w:sticker:code>
    <div class="inserted">插入的内容</div>
  </w:sticker:code>
</w:sticker>

<!-- 在指定位置后追加 -->
<w:sticker action="after" position="1-3">
  <w:sticker:target>
    <footer></footer>
  </w:sticker:target>
  <w:sticker:code>
    <div class="appended">追加的内容</div>
  </w:sticker:code>
</w:sticker>
```

### 3. 执行收集和编译

```bash
# 收集 Sticker 规则并检测冲突
php bin/w sticker:collect

# 编译所有 Sticker 规则
php bin/w sticker:refresh
```

### 4. 验证结果

- 检查 `generated/sticker.php` 是否包含您的规则
- 检查 `generated/extends/Weline_Sticker/` 下是否生成了编译文件
- 访问页面验证修改是否生效

---

## 技术细节

### 1. 代码压缩算法

`CodeMinifier` 使用状态机实现代码压缩：

- **状态**：单引号、双引号、标签、注释
- **规则**：在非字符串、非标签、非注释区域去除空白字符
- **保留**：字符串内容、标签结构、注释内容

### 2. 匹配算法

1. **压缩目标代码**：使用 `CodeMinifier` 压缩
2. **查找所有匹配**：使用 `strpos` 查找所有出现位置
3. **位置索引**：为每个匹配位置分配索引（从 1 开始）
4. **位置过滤**：根据 `position` 参数过滤索引

### 3. 规则应用顺序

- **从后往前**：按位置从后往前应用规则，避免位置偏移
- **多规则合并**：同一文件的多个规则按注册表顺序应用

### 4. 性能优化

- **内存缓存**：注册表使用内存缓存，减少文件读取
- **文件存在性缓存**：开发环境使用 1 秒缓存
- **快速判断**：先检查模块是否有 Sticker，再检查具体文件

### 5. 开发环境特性

- **自动编译**：源文件更新时自动重新编译
- **增量更新**：只编译有变化的文件
- **实时反馈**：编译失败时记录日志并发送通知

---

## 命令说明

### sticker:collect

**功能**：收集所有 Sticker 规则并更新注册表

**执行流程**：
1. 扫描所有模块的 Sticker 目录
2. 解析 Sticker 文件中的规则
3. 构建注册表
4. 检测冲突
5. 保存注册表

**输出**：
- 发现的 Sticker 文件数量
- 冲突检测结果
- 注册表保存状态

### sticker:refresh

**功能**：重新编译所有 Sticker 规则

**执行流程**：
1. 读取注册表
2. 遍历所有需要编译的文件
3. 编译每个文件
4. 输出编译结果

**输出**：
- 成功编译的文件数量
- 失败的文件数量
- 错误详情

---

## 常见问题

### 1. 修改不生效？

**可能原因**：
- 规则文件路径不正确
- 注册表中没有对应条目
- 编译文件未生成或已过期

**解决方法**：
1. 检查规则文件路径是否与目标文件路径一致
2. 执行 `php bin/w sticker:collect` 更新注册表
3. 执行 `php bin/w sticker:refresh` 重新编译
4. 开发环境：清理 `generated/` 目录后重试

### 2. 目标代码未找到？

**可能原因**：
- 目标代码已更改
- 代码压缩后格式不匹配
- 位置参数不正确

**解决方法**：
1. 检查源文件中的目标代码是否还存在
2. 检查目标代码的格式（空白字符、换行等）
3. 查看系统通知和日志获取详细信息

### 3. 检测到冲突？

**冲突定义**：同一目标代码的相同位置索引被多个 Sticker 修改

**解决方法**：
1. 查看冲突详情，了解哪些 Sticker 冲突
2. 调整 `position` 参数，避免修改同一位置
3. 合并冲突的 Sticker 规则
4. 删除不必要的 Sticker 规则

### 4. 生产环境如何更新？

**推荐流程**：
1. 在开发环境测试 Sticker 规则
2. 执行 `php bin/w sticker:collect` 和 `php bin/w sticker:refresh`
3. 将 `generated/` 目录包含在部署包中
4. 或在部署脚本中执行收集和编译命令

### 5. 性能影响？

**优化措施**：
- 注册表使用内存缓存
- 文件存在性检查使用缓存
- 开发环境增量编译
- 生产环境预编译

**建议**：
- 避免创建过多的 Sticker 规则
- 定期清理无效的 Sticker 规则
- 使用位置参数精确匹配，减少不必要的匹配

---

## 相关文档

- [使用文档](./doc/usage.md)
- [需求文档](./doc/requirements.md)
- [开发文档](./doc/development.md)
- [测试文档](./Test/README.md)

---

## 版本信息

- **当前版本**：1.0.0
- **作者**：秋枫雁飞
- **邮箱**：aiweline@qq.com
- **网址**：aiweline.com
- **论坛**：https://bbs.aiweline.com

---

## 许可证

本模块由 Aiweline 所有，所有解释权归 Aiweline 所有。

