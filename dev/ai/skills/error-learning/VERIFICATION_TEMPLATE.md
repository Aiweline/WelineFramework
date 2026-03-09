# 验证模板 (Verification Templates)

## 目的
为每次错误修复提供快速验证方法，确保修复真正有效。

---

## 模板 1: 数据库操作验证脚本

**适用场景**: 数据库增删改查操作

```php
<?php
/**
 * 验证脚本：[功能描述]
 */

require __DIR__ . '/app/bootstrap.php';

use Weline\Framework\Manager\ObjectManager;

echo "\n=== [功能]验证测试 ===\n\n";

// 1. 准备测试数据
echo "步骤 1: 准备测试数据\n";
echo str_repeat("-", 60) . "\n";

$model = ObjectManager::getInstance(ModelClass::class);

// 插入测试数据
$testData = [
    'field1' => 'value1',
    'field2' => 'value2',
];

$model->setData($testData)->save();
echo "✓ 测试数据已插入\n";

// 2. 执行目标操作
echo "\n步骤 2: 执行目标操作\n";
echo str_repeat("-", 60) . "\n";

// 删除前记录数
$beforeCount = $model->reset()->where('field1', 'value1')->count();
echo "操作前记录数: {$beforeCount}\n";

// 执行操作
$model->reset()
    ->where('field1', 'value1')
    ->delete()
    ->fetch();

// 操作后记录数
$afterCount = $model->reset()->where('field1', 'value1')->count();
echo "操作后记录数: {$afterCount}\n";

// 3. 验证结果
echo "\n步骤 3: 验证结果\n";
echo str_repeat("-", 60) . "\n";

if ($afterCount === 0) {
    echo "✅ 验证成功！操作正确执行\n";
    exit(0);
} else {
    echo "❌ 验证失败！操作未正确执行\n";
    echo "期望: 0 条记录\n";
    echo "实际: {$afterCount} 条记录\n";
    exit(1);
}
```

---

## 模板 2: HTTP 请求验证

**适用场景**: API 接口、控制器方法

```bash
# 方法 1: 使用 http:req 命令
php bin/w http:req "/path/to/endpoint" -X POST -d '{"key":"value"}' -b --login -u=admin -p=admin

# 方法 2: 使用 http:req 命令搜索关键词
php bin/w http:req "/path/to/page" "filter=success" -n=5

# 方法 3: 直接访问 URL（适用于 GET 请求）
php bin/w http:req "/category/list"
```

**期望输出示例：**
```
✅ 响应状态码: 200
✅ 响应包含: "success": true
✅ 数据库已更新
```

---

## 模板 3: 单元测试验证

**适用场景**: 模型方法、服务类、助手函数

```bash
# 运行特定测试类
php bin/w test:unit ModuleName/Test/Unit/ModelTest

# 运行特定测试方法
php bin/w test:unit ModuleName/Test/Unit/ModelTest::testDeleteMethod

# 运行整个模块的测试
php bin/w test:unit ModuleName
```

**创建测试类示例：**
```php
<?php

namespace ModuleName\Test\Unit;

use PHPUnit\Framework\TestCase;

class ModelTest extends TestCase
{
    public function testDeleteMethod()
    {
        // Arrange
        $model = $this->createModel();
        $model->setData(['id' => 1])->save();
        
        // Act
        $model->reset()->where('id', 1)->delete()->fetch();
        
        // Assert
        $count = $model->reset()->where('id', 1)->count();
        $this->assertEquals(0, $count, '删除后应该没有记录');
    }
}
```

---

## 模板 4: 功能集成测试脚本

**适用场景**: 完整功能流程测试

```php
<?php
/**
 * 集成测试：[功能名称]
 */

require __DIR__ . '/app/bootstrap.php';

use Weline\Framework\Manager\ObjectManager;

echo "\n=== [功能]集成测试 ===\n\n";

$testsPassed = 0;
$testsFailed = 0;

// 测试 1: [测试场景1]
echo "测试 1: [场景描述]\n";
try {
    // 测试代码
    $result = performAction();
    
    if ($result === expectedValue) {
        echo "  ✅ 通过\n";
        $testsPassed++;
    } else {
        echo "  ❌ 失败：期望 " . expectedValue . "，实际 " . $result . "\n";
        $testsFailed++;
    }
} catch (Exception $e) {
    echo "  ❌ 异常：" . $e->getMessage() . "\n";
    $testsFailed++;
}

// 测试 2: [测试场景2]
echo "\n测试 2: [场景描述]\n";
// ... 类似结构

// 汇总
echo "\n" . str_repeat("=", 60) . "\n";
echo "测试完成！\n";
echo "通过: {$testsPassed}, 失败: {$testsFailed}\n";

if ($testsFailed > 0) {
    echo "\n❌ 集成测试失败\n";
    exit(1);
} else {
    echo "\n✅ 集成测试通过\n";
    exit(0);
}
```

---

## 模板 5: 手动验证步骤清单

**适用场景**: 前端功能、UI 变更、复杂交互

```markdown
## 手动验证步骤

### 前置条件
- [ ] 清理缓存：`php bin/w c:f`
- [ ] 数据库处于已知状态
- [ ] 浏览器已打开开发者工具

### 验证步骤
1. **访问页面**
   - [ ] 打开 URL: `http://localhost/path/to/page`
   - [ ] 检查控制台无错误

2. **执行操作**
   - [ ] 点击 [按钮名称]
   - [ ] 观察 [预期行为]

3. **验证结果**
   - [ ] 检查页面元素: [CSS选择器]
   - [ ] 检查网络请求: [API endpoint] 返回 200
   - [ ] 检查数据库: `SELECT * FROM table WHERE ...`

4. **刷新验证**
   - [ ] 刷新页面 (F5)
   - [ ] 确认数据持久化

### 期望结果
- ✅ [期望1]
- ✅ [期望2]
- ✅ [期望3]

### 实际结果
- [ ] [实际1]
- [ ] [实际2]
- [ ] [实际3]

### 结论
- [ ] ✅ 验证通过
- [ ] ❌ 验证失败（原因：___）
```

---

## 模板 6: 浏览器自动化测试

**适用场景**: 前端功能、UI 交互（使用 cursor-ide-browser MCP）

```javascript
// 使用 cursor-ide-browser MCP 工具验证

// 1. 导航到页面
browser_navigate({
    url: "http://localhost/path/to/page"
})

// 2. 获取页面快照
browser_snapshot()

// 3. 点击元素
browser_click({
    ref: "element-ref-from-snapshot"
})

// 4. 等待响应
browser_wait({
    seconds: 2
})

// 5. 获取新快照并验证
browser_snapshot()

// 6. 验证元素状态
// 检查快照中是否包含期望的元素或文本
```

---

## 验证方法选择指南

| 场景类型 | 推荐验证方法 | 模板编号 |
|---------|-------------|---------|
| 数据库操作（CRUD） | 验证脚本 | 模板 1 |
| API 接口 | HTTP 请求 | 模板 2 |
| 模型/服务方法 | 单元测试 | 模板 3 |
| 完整功能流程 | 集成测试脚本 | 模板 4 |
| 前端 UI/交互 | 手动步骤 + 浏览器自动化 | 模板 5 + 6 |
| 权限/路由 | HTTP 请求 | 模板 2 |
| 配置/升级 | 验证脚本 | 模板 1 |

---

## 验证脚本命名规范

```
verify_[功能名称].php        # 一般验证脚本
test_[功能名称].php          # 临时测试脚本
integration_[功能名称].php   # 集成测试脚本
```

**示例：**
- `verify_orphan_widget_deletion.php`
- `test_theme_layout_save.php`
- `integration_eav_attribute_crud.php`

---

## 最佳实践

### 1. 验证脚本应该：
- ✅ 输出清晰的步骤和结果
- ✅ 使用 `exit(0)` 表示成功，`exit(1)` 表示失败
- ✅ 包含前置条件检查
- ✅ 清理测试数据（如果需要）
- ✅ 提供详细的失败信息

### 2. 验证后应该：
- ✅ 删除临时验证脚本
- ✅ 保留可复用的测试脚本（放到 `test/` 目录）
- ✅ 在 DEVELOPMENT_NOTES.md 中记录可复用的验证规则
- ✅ 更新相关技能文档的验证示例

### 3. 验证失败时：
- ❌ 不要更新知识库
- ❌ 不要继续其他操作
- ✅ 分析失败原因
- ✅ 进行第二次尝试（如果尚未达到2次）
- ✅ 两次失败后询问用户

---

**最后更新**: 2026-01-29
