# Sticker 模块单元测试

## 测试结构

```
app/code/Weline/Sticker/
├── Test/
│   ├── Unit/
│   │   ├── Helper/
│   │   │   └── CodeMinifierTest.php          # 代码压缩工具测试
│   │   ├── Service/
│   │   │   ├── RuleScannerTest.php           # 规则扫描器测试
│   │   │   ├── RuleParserTest.php            # 规则解析器测试
│   │   │   ├── StickerRegistryTest.php       # 注册表管理测试
│   │   │   └── CompilerTest.php              # 编译服务测试
│   │   └── StickerIntegrationTest.php        # 集成测试
├── view/templates/Test/
│   ├── index.phtml                           # 测试目标文件
│   └── duplicate.phtml                       # 重复内容测试文件
└── extends/Weline_Sticker/
    └── Weline/Sticker/view/templates/Test/
        ├── index.phtml                       # Sticker 测试文件
        └── duplicate.phtml                   # 重复内容 Sticker 测试
```

## 运行测试

### 运行所有测试

```bash
php bin/w p:r Weline_Sticker
```

### 运行特定测试文件

```bash
php bin/w p:r app/code/Weline/Sticker/Test/Unit/Helper/CodeMinifierTest.php
```

### 运行特定测试类

```bash
php bin/w p:r Weline_Sticker --filter CodeMinifierTest
```

## 测试用例说明

### 1. CodeMinifierTest - 代码压缩工具测试

- ✅ 测试代码压缩移除空白字符
- ✅ 测试保留字符串内容
- ✅ 测试保留 HTML 标签
- ✅ 测试查找匹配位置
- ✅ 测试位置参数解析（all/单个/范围）

### 2. RuleParserTest - 规则解析器测试

- ✅ 测试解析 replace 操作
- ✅ 测试解析 before 操作
- ✅ 测试解析 after 操作
- ✅ 测试解析多个规则
- ✅ 测试规则验证

### 3. RuleScannerTest - 规则扫描器测试

- ✅ 测试扫描所有 Sticker 文件
- ✅ 测试检查模块是否有 Sticker
- ✅ 测试不存在的模块

### 4. StickerRegistryTest - 注册表管理测试

- ✅ 测试保存和读取注册表
- ✅ 测试检查文件是否有 Sticker
- ✅ 测试检查模块是否有 Sticker
- ✅ 测试获取文件 Sticker 规则
- ✅ 测试清除缓存

### 5. CompilerTest - 编译服务测试

- ✅ 测试编译 replace 操作
- ✅ 测试编译 before 操作
- ✅ 测试编译 after 操作
- ✅ 测试编译不存在的文件

### 6. StickerIntegrationTest - 集成测试

- ✅ 测试完整的 Sticker 流程（扫描→解析→注册表→编译）
- ✅ 测试位置参数功能

## 测试文件说明

### 目标文件

**view/templates/Test/index.phtml**
- 包含标题、段落、按钮、页脚等元素
- 用于测试 replace、before、after 操作

**view/templates/Test/duplicate.phtml**
- 包含重复的段落内容
- 用于测试位置参数（单个/范围/all）

### Sticker 文件

**extends/Weline_Sticker/Weline/Sticker/view/templates/Test/index.phtml**
- 包含三个 Sticker 规则：
  1. replace: 替换标题
  2. before: 在按钮前插入内容
  3. after: 在页脚后追加内容

**extends/Weline_Sticker/Weline/Sticker/view/templates/Test/duplicate.phtml**
- 包含两个 Sticker 规则：
  1. replace position="2": 替换第二段
  2. replace position="1-3": 替换所有段落

## 测试前准备

1. 确保测试文件存在：
   - `view/templates/Test/index.phtml`
   - `view/templates/Test/duplicate.phtml`
   - `extends/Weline_Sticker/Weline/Sticker/view/templates/Test/index.phtml`
   - `extends/Weline_Sticker/Weline/Sticker/view/templates/Test/duplicate.phtml`

2. 运行 Sticker 收集命令（首次测试）：
   ```bash
   php bin/w sticker:collect
   ```

3. 运行编译命令（验证编译功能）：
   ```bash
   php bin/w sticker:refresh
   ```

## 验证测试结果

### 检查编译文件

编译后的文件位于：
```
generated/extends/Weline_Sticker/Weline/Sticker/view/templates/Test/index.phtml
```

验证内容：
- ✅ 包含 `sticker-modified` class（标题被替换）
- ✅ 包含 `sticker-inserted` class（按钮前插入的内容）
- ✅ 包含 `sticker-appended` class（页脚后追加的内容）

### 检查注册表

注册表文件：
```
generated/sticker.php
```

验证内容：
- ✅ 包含 `Weline_Sticker` 模块
- ✅ 包含测试文件路径
- ✅ 包含正确的规则信息

## 测试覆盖率

- ✅ 代码压缩功能
- ✅ 规则扫描功能
- ✅ 规则解析功能
- ✅ 注册表管理功能
- ✅ 编译功能
- ✅ 位置参数功能
- ✅ 完整流程集成

## 注意事项

1. 测试会修改 `generated/sticker.php` 文件，测试完成后会自动恢复
2. 编译文件会生成到 `generated/extends/Weline_Sticker/` 目录
3. 某些测试可能需要数据库连接，如果数据库未配置会跳过测试
4. 集成测试需要确保测试文件存在，否则会标记为跳过

## 添加新测试

1. 在 `Test/Unit/` 目录下创建新的测试文件
2. 继承 `PHPUnit\Framework\TestCase` 或使用 `TestCore`
3. 测试方法命名以 `test` 开头
4. 使用 `@dataProvider` 进行参数化测试
5. 使用 `markTestSkipped()` 跳过无法运行的测试

## 故障排除

### 测试失败：文件不存在

确保测试文件已创建：
```bash
ls -la app/code/Weline/Sticker/view/templates/Test/
ls -la app/code/Weline/Sticker/extends/Weline_Sticker/Weline/Sticker/view/templates/Test/
```

### 测试失败：注册表为空

运行收集命令：
```bash
php bin/w sticker:collect
```

### 测试失败：编译文件不存在

运行编译命令：
```bash
php bin/w sticker:refresh
```

