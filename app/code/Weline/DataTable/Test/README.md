# DataTable 模块单元测试

## 测试结构

```
Test/
├── Unit/                    # 单元测试
│   ├── ApiTest.php         # API 接口测试
│   └── TaglibTest.php      # 标签库测试
├── Integration/            # 集成测试（可选）
└── phpunit.xml             # PHPUnit 配置文件
```

## 运行测试

### 使用框架命令（推荐）

```bash
# 运行 DataTable 模块的所有测试
php bin/w phpunit:run --module=Weline_DataTable -b

# 运行所有测试
php bin/w phpunit:run -b

# 运行特定测试文件
php bin/w phpunit:run --module=Weline_DataTable --filter=ApiTest -b
```

### 使用 PHPUnit 直接运行

```bash
# 进入测试目录
cd app/code/Weline/DataTable/Test

# 运行所有测试
../../../../vendor/bin/phpunit

# 运行特定测试文件
../../../../vendor/bin/phpunit Unit/ApiTest.php

# 生成覆盖率报告
../../../../vendor/bin/phpunit --coverage-html coverage-html
```

## 测试覆盖范围

### API 接口测试 (ApiTest.php)

#### DataTable API
- ✅ `postData()` - 获取数据表格数据
- ✅ `postCreate()` - 创建记录
- ✅ `postUpdate()` - 更新记录
- ✅ `postDelete()` - 删除记录（单个/批量）
- ✅ 参数验证（缺少必需参数、无效参数）
- ✅ 分页参数处理
- ✅ 排序参数处理
- ✅ 筛选参数处理
- ✅ 字段类型判断（数字字段、日期字段）

#### Form API
- ✅ `postFields()` - 获取表单字段信息
- ✅ `postRecord()` - 获取表单记录数据
- ✅ 参数验证
- ✅ 字段类型推断
- ✅ 字段标签获取
- ✅ 字段选项获取
- ✅ 字段验证规则获取
- ✅ 排除字段功能
- ✅ 包含字段功能

#### Trash API
- ✅ `getData()` - 获取回收站数据
- ✅ `restore()` - 恢复记录（单个/批量）
- ✅ `forceDelete()` - 永久删除记录（单个/批量）
- ✅ `empty()` - 清空回收站
- ✅ 参数验证
- ✅ 确认机制验证

## 测试说明

### Mock 对象使用

测试使用 PHPUnit Mock 对象来模拟：
- `BackendApiSession` - 模拟后端会话（已登录状态）
- `Request` - 模拟 HTTP 请求对象

### 注意事项

1. **数据库依赖**: 部分测试需要实际的数据库连接，如果模型类不存在或数据库未配置，测试可能会失败
2. **输出缓冲**: 测试中使用 `ob_start()` 和 `ob_end_clean()` 来捕获可能的 header 输出
3. **JSON 解析**: API 返回的是 JSON 字符串，测试中需要解析为数组进行验证

### 测试数据模型

测试使用 `Weline\DataTable\Model\TestUser` 作为测试模型，确保该模型已正确配置。

## 测试报告

测试报告会生成在以下位置：
- HTML 报告: `Test/coverage-html/index.html`
- 文本报告: `Test/coverage.txt`
- XML 报告: `Test/coverage.xml`
- JUnit 报告: `Test/test-results.xml`

## 持续集成

在 CI/CD 环境中，可以使用以下命令运行测试：

```bash
php bin/w phpunit:run --module=Weline_DataTable -b --coverage-clover=coverage.xml
```

