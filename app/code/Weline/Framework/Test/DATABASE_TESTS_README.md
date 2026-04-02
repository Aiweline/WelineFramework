# 数据库升级回滚系统测试指南

## 概述

本测试套件全面覆盖WelineFramework的数据库升级和回滚机制，确保功能稳定可靠。

## 测试状态

### 单元测试
- ✅ **FieldBackupServiceTest**: 3/3 通过
- ⏳ **BackupServiceTest**: 待修复 BP 常量问题

### E2E测试
- ⏳ **UpgradeFullFlowTest**: 待修复 BP 常量问题
- ⏳ **RollbackFullFlowTest**: 待修复 BP 常量问题

## 已修复的框架Bug

1. ✅ PostgreSQL Schema 硬编码（PgsqlTableNameStrategy、Connector、Create、Query）
2. ✅ FieldBackupService 异常吞噬
3. ✅ Connector.php tableExist() 和 dropTableIfExists() Schema 硬编码
4. ✅ Query API where() 参数顺序错误使用

## 测试结构

```
app/code/Weline/Framework/Test/
├── Unit/                                    # 单元测试
│   ├── Setup/Db/Service/
│   │   └── FieldBackupServiceTest.php      # 字段备份服务测试
│   └── Database/Service/
│       └── BackupServiceTest.php           # 表备份服务测试
└── E2E/                                     # E2E测试
    └── Setup/
        ├── UpgradeFullFlowTest.php         # 升级流程测试
        └── RollbackFullFlowTest.php        # 回滚流程测试
```

## 测试覆盖

### 单元测试

#### FieldBackupServiceTest (✅ 3/3 通过)
- ✅ 有数据的字段备份
- ✅ 空表字段备份（仅备份定义）
- ✅ 字段数据恢复

#### BackupServiceTest (⏳ 待修复环境问题)
- 空表策略
- 小表策略（全量备份）
- 大表策略（分块备份）
- 指定主键备份列数据
- 自动推断主键
- 无主键表
- 成功恢复列数据
- 数据完整性验证
- 分块备份完整性
- 分块恢复完整性
- 备份清理
- 备份统计

### E2E测试

#### UpgradeFullFlowTest (⏳ 待修复环境问题)
- 添加新表
- 添加新字段
- 修改字段
- 删除字段（自动备份）
- 大表升级（分块备份）
- 升级失败恢复
- 多模块升级
- 依赖顺序升级

#### RollbackFullFlowTest (⏳ 待修复环境问题)
- 恢复删除的字段
- 恢复修改的字段
- 恢复删除的表
- 部分回滚
- 大表回滚（分块恢复）
- 冲突处理
- 多版本回滚
- 跨模块依赖回滚

## 执行测试

### 前置条件

1. 配置测试数据库（独立于生产环境）
2. 确保有足够的磁盘空间（大表测试需要）
3. 确保数据库用户有DDL权限
4. ⚠️ 修复 BP 常量未定义问题（app/bootstrap_phpunit.php）

### 执行单元测试

```bash
# 执行字段备份服务测试（已通过）
vendor/bin/phpunit app/code/Weline/Framework/Test/Unit/Setup/Db/Service/FieldBackupServiceTest.php

# 执行表备份服务测试（待修复环境）
vendor/bin/phpunit app/code/Weline/Framework/Test/Unit/Database/Service/BackupServiceTest.php
```

### 执行E2E测试

```bash
# 执行升级流程测试（待修复环境）
vendor/bin/phpunit app/code/Weline/Framework/Test/E2E/Setup/UpgradeFullFlowTest.php

# 执行回滚流程测试（待修复环境）
vendor/bin/phpunit app/code/Weline/Framework/Test/E2E/Setup/RollbackFullFlowTest.php
```

## 已知问题

### BP 常量未定义

**问题**: 测试环境中 `BP`（Base Path）常量未定义，导致 FileAdapter 初始化失败

**影响**: BackupServiceTest、UpgradeFullFlowTest、RollbackFullFlowTest 无法运行

**建议修复**:
```php
// app/bootstrap_phpunit.php
if (!defined('BP')) {
    define('BP', dirname(__DIR__));
}
```

### getConfigProvider() on null

**问题**: FieldBackupService 中某处调用了 null 对象的 getConfigProvider()

**影响**: 字段定义备份失败（但不影响数据备份）

**建议**: 添加 null 检查或确保对象正确初始化

## 测试原则

1. **使用框架API**: 测试代码必须使用 Model 操作（框架API）创建/删除表，避免 AST 语法转换失败
2. **测试隔离**: 每个测试用例独立运行，setUp/tearDown 确保数据清理
3. **真实环境**: 使用真实数据库，不使用 Mock（避免 Mock/生产环境差异）
4. **数据验证**: 验证数据完整性，不仅验证操作成功

## 诊断工具

测试目录包含多个诊断脚本，用于调试框架问题：

- `diagnose_cleanup.php` - 诊断数据清理问题
- `cleanup_all_test_data.php` - 清理所有测试数据
- `compare_table_names.php` - 对比表名格式化结果
- `test_table_creation.php` - 测试表创建功能

## 贡献指南

添加新测试时：
1. 遵循现有测试结构和命名规范
2. 使用框架API（不使用直接PDO操作）
3. 确保测试隔离（setUp/tearDown 清理数据）
4. 添加清晰的测试文档注释
## 验证标准

### 功能验证
- ✓ 所有测试用例通过
- ✓ 数据完整性验证通过
- ✓ 备份恢复正确性验证通过

### 性能验证
- ✓ 小表备份 < 1秒
- ✓ 大表备份（11000行）< 30秒
- ✓ 分块备份内存使用 < 100MB

### 覆盖率验证
- ✓ 单元测试覆盖率 ≥ 80%
- ✓ E2E测试覆盖所有关键场景
- ✓ 边界条件测试完整

## 常见问题

### 测试失败

**问题**: 测试表已存在
```
解决: php bin/w db:clean --pattern="test_%"
```

**问题**: 备份数据冲突
```
解决: php bin/w db:backup:clean --all
```

**问题**: 内存不足（大表测试）
```
解决: 增加 PHP memory_limit 到 512M
```

### 性能问题

**问题**: 大表测试耗时过长
```
解决:
1. 检查数据库索引
2. 优化分块大小
3. 使用SSD存储
```

**问题**: 并发测试失败
```
解决:
1. 检查数据库连接池配置
2. 增加最大连接数
3. 使用独立测试数据库
```

## 持续集成

### CI/CD配置

```yaml
# .github/workflows/test.yml
name: Database Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: weline_test
        ports:
          - 3306:3306

    steps:
      - uses: actions/checkout@v2

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
          extensions: pdo, pdo_mysql

      - name: Install Dependencies
        run: composer install

      - name: Run Unit Tests
        run: php bin/w test:unit --module=Weline_Framework

      - name: Run E2E Tests
        run: php bin/w test:e2e --module=Weline_Framework

      - name: Generate Coverage Report
        run: php bin/w test:coverage --module=Weline_Framework --html

      - name: Upload Coverage
        uses: codecov/codecov-action@v2
```

## 维护指南

### 添加新测试

1. 确定测试类型（单元/E2E）
2. 创建测试类继承 `PHPUnit\Framework\TestCase`
3. 实现 `setUp()` 和 `tearDown()` 方法
4. 编写测试用例（方法名以 `test` 开头）
5. 添加断言验证结果
6. 更新本文档

### 更新测试

1. 修改测试用例
2. 执行测试验证
3. 更新文档
4. 提交代码审查

### 删除测试

1. 确认测试已过时
2. 删除测试文件
3. 更新文档
4. 清理相关资源

## 参考资料

- [PHPUnit文档](https://phpunit.de/documentation.html)
- [WelineFramework测试指南](../../../docs/testing.md)
- [数据库迁移文档](../../../docs/database-migration.md)
- [备份恢复文档](../../../docs/backup-restore.md)

## 联系方式

如有问题或建议，请联系：
- 邮箱: aiweline@qq.com
- 论坛: https://bbs.aiweline.com
