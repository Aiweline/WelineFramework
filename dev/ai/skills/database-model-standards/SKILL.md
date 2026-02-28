---
name: database-model-standards
description: |
  Database model and SQL writing standards for Weline Framework.
  
  Use when:
  - Creating/modifying models and database tables
  - Writing database queries (select/insert/update/delete)
  - Adding columns/fields to existing tables (upgrade)
  - Using ORM operations (CRITICAL: fetch() required!)
  - Pagination queries (MUST use framework pagination!)
  - Cross-database compatible code
  
  Keywords: Model, 模型, database, 数据库, ORM, select, insert, update, delete, fetch, 查询,
  加字段, 添加字段, add column, addColumn, alterTable, upgrade, install, ModelSetup, hasField,
  表结构, schema, 字段, field, column, 数据库升级, PostgreSQL, MySQL,
  分页, pagination, paging, page, pageSize, 翻页, 每页, 页码, limit, offset, getItems, getPagination,
  totalSize, lastPage, 总数, 总页数, 列表, list, 数据列表, 分页查询, paged query, paginated
globs:
  - "**/Model/**/*.php"
alwaysApply: false
---

# 数据库模型与SQL编写规范技能

## 技能描述

本技能规定了 Weline Framework 中数据库模型和 SQL 编写的标准，确保代码的跨数据库兼容性和可维护性。

## 核心原则

### 🚫 禁止使用框架不存在的方法

**绝对禁止**凭空编造或假设框架存在某个方法！在使用任何 ORM/Query 方法前，必须确认框架确实支持该方法。

**常见错误示例：**
```php
// ❌ 错误：框架没有 whereIn() 方法！
$model->whereIn('id', [1, 2, 3])->select()->fetchArray();
// 报错：Call to a member function xxx() on false

// ❌ 错误：框架没有 orWhere() 方法！
$model->where('a', 1)->orWhere('b', 2)->select()->fetchArray();

// ❌ 错误：框架的 select() 不接受数组参数！
$model->select(['id', 'name'])->fetchArray();
// 报错：Argument #1 ($fields) must be of type string, array given
```

**正确做法：**
```php
// ✅ 正确：使用 where() 的第三个参数指定 IN 操作符
$model->where('id', [1, 2, 3], 'IN')->select()->fetchArray();

// ✅ 正确：使用 where() 的第四个参数指定 OR 逻辑
$model->where('a', 1)->where('b', 2, '=', 'OR')->select()->fetchArray();

// ✅ 正确：使用 fields() 指定字段，select() 不传参数
$model->fields('id,name')->select()->fetchArray();
```

**不确定方法是否存在时：**
1. 查阅本技能文档的"常用框架查询方法参考"表
2. 搜索 `QueryInterface.php` 或 `QueryAst.php` 确认方法签名
3. 禁止凭记忆或猜测使用方法

**实际案例（2026-02-26）：**
使用了不存在的 `whereIn()` 方法，`__call` 魔术方法找不到对应方法返回意外值，导致后续链式调用报错 `Call to a member function fields() on false`。

---

### ⚠️ 所有 ORM 操作必须调用 fetch() 执行

**这是最重要的规则！** 在 Weline Framework ORM 中，所有查询操作（select/insert/update/delete）都需要调用 `fetch()` 来真正执行 SQL。

```php
// ✅ 查询数据
$data = $model->where('id', 1)->select()->fetch();
$list = $model->where('status', 1)->select()->fetchArray();

// ✅ 插入数据
$model->setData($data)->insert()->fetch();

// ✅ 更新数据
$model->where('id', 1)->setData($data)->update()->fetch();

// ✅ 删除数据
$model->where('id', 1)->delete()->fetch();

// ✅ 查找并加载单条记录
$model->load($id);  // load() 内部已调用 fetch()
$model->where('code', $code)->find()->fetch();
```

### 🚫 禁止在业务代码中写 SQL 方言

**绝对禁止**在以下位置编写特定数据库的方言 SQL：
- 控制器 (Controller)
- 模型 (Model) 的业务方法
- 服务类 (Service)
- 观察者 (Observer)
- 助手类 (Helper)
- 任何非框架核心的业务代码

### ✅ 方言 SQL 只允许在适配器中编写

方言 SQL **只能**在以下位置编写：
- `app/code/Weline/Framework/Database/Connection/Adapter/{数据库类型}/` 目录下的适配器类
- 查询器 (Query) 类
- 表操作类 (Table/Alter/Create)

## 详细规范

### 1. 业务代码中使用框架查询方法

```php
// ✅ 正确：使用框架提供的查询方法
$this->model->clearQuery()
    ->where('status', 1)
    ->where('created_at', $date, '>=')
    ->order('id', 'DESC')
    ->select()
    ->fetchArray();

// ❌ 错误：在业务代码中直接写 SQL
$this->model->query("SELECT * FROM users WHERE status = 1 ORDER BY id DESC");

// ❌ 错误：在业务代码中写 PostgreSQL 特有语法
$setup->query("CREATE UNIQUE INDEX IF NOT EXISTS \"idx_name\" ON \"table\" (\"field\")");

// ❌ 错误：在业务代码中写 MySQL 特有语法
$setup->query("ALTER TABLE `table` ADD INDEX `idx_name` (`field`)");
```

### 2. 模型 Setup/Upgrade 中使用框架适配器

```php
// ✅ 正确：使用 ModelSetup 适配器方法
public function install(ModelSetup $setup, Context $context): void
{
    if (!$setup->tableExist()) {
        $setup->createTable()
            ->addColumn('id', TableInterface::column_type_INTEGER, 0, 'primary key auto_increment')
            ->addColumn('name', TableInterface::column_type_VARCHAR, 255, 'not null')
            ->addIndex(TableInterface::index_type_UNIQUE, 'uk_name', 'name')
            ->create();
    }
}

public function upgrade(ModelSetup $setup, Context $context): void
{
    if ($setup->tableExist() && !$setup->hasIndex('uk_name')) {
        $setup->alterTable()
            ->addIndex(TableInterface::index_type_UNIQUE, 'uk_name', 'name', '名称唯一索引')
            ->alter();
    }
}
```

### ⚠️ 重要：触发 upgrade() 必须更新版本号

**upgrade() 方法只有在模块版本号变化时才会执行！**

修改 upgrade() 后，必须同时更新模块的 `register.php` 中的版本号：

```php
// register.php
Register::register(
    Register::MODULE,
    'Vendor_ModuleName',
    __DIR__,
    '1.0.1',  // ⚠️ 必须增加版本号！从 1.0.0 改为 1.0.1
    '模块描述'
);
```

然后运行升级命令：
```bash
php bin/m s:up --module=Vendor_ModuleName
```

**常见错误**：只修改 upgrade() 但不更新版本号 → upgrade() 不会执行！

```php
// ❌ 错误：在 upgrade 中直接写方言 SQL
public function upgrade(ModelSetup $setup, Context $context): void
{
    // PostgreSQL 语法
    $setup->query("CREATE UNIQUE INDEX IF NOT EXISTS \"uk_name\" ON \"{$table}\" (\"name\")");
    // MySQL 语法
    $setup->query("ALTER TABLE `{$table}` ADD UNIQUE INDEX `uk_name` (`name`)");
}
```

### 3. JOIN 查询使用框架方法

```php
// ✅ 正确：使用 joinModel 方法
$this->model->joinModel(
    RelatedModel::class,
    'rm',
    'main_table.related_id = rm.id',
    'left',
    'rm.name as related_name'
);

// ✅ 正确：使用 join 方法（表名和别名分开或用空格分隔）
$this->model->join(
    $relatedTable . ' rm',
    'main_table.related_id = rm.id',
    'left'
);

// ❌ 错误：在业务代码中拼接 JOIN SQL
$sql = "SELECT * FROM {$table} LEFT JOIN {$relatedTable} ON ...";
```

### 4. INSERT 操作使用框架方法

```php
// ✅ 正确：使用模型 insert 方法，指定冲突字段
$this->model->clearQuery()
    ->insert($data, 'unique_field1,unique_field2')
    ->fetch();

// ✅ 正确：使用模型 save 方法
$this->model->setData($data)->save();

// ❌ 错误：直接写 INSERT SQL
$sql = "INSERT INTO table (field1, field2) VALUES (?, ?) ON DUPLICATE KEY UPDATE ...";
$sql = "INSERT INTO table (field1, field2) VALUES (?, ?) ON CONFLICT (field1) DO UPDATE SET ...";
```

### 5. 删除重复数据使用模型方法

```php
// ✅ 正确：使用模型查询方法清理重复数据
private function cleanupDuplicates(): void
{
    // 查找重复数据
    $duplicates = $this->clearQuery()
        ->fields('field1,field2,COUNT(*) as cnt')
        ->group('field1,field2')
        ->having('COUNT(*) > 1')
        ->select()
        ->fetchArray();
    
    // 删除重复记录（保留一条）
    foreach ($duplicates as $dup) {
        $records = $this->clearQuery()
            ->where('field1', $dup['field1'])
            ->where('field2', $dup['field2'])
            ->select()
            ->fetchArray();
        
        $first = true;
        foreach ($records as $record) {
            if ($first) {
                $first = false;
                continue;
            }
            $this->clearQuery()
                ->where('id', $record['id'])
                ->delete()
                ->fetch();
        }
    }
}

// ❌ 错误：使用方言 SQL 删除重复数据
// PostgreSQL
$sql = "DELETE FROM table a USING table b WHERE a.ctid < b.ctid AND a.field = b.field";
// MySQL  
$sql = "DELETE t1 FROM table t1 INNER JOIN table t2 WHERE t1.id < t2.id AND t1.field = t2.field";
```

## 检查索引是否存在

```php
// ✅ 正确：使用 ModelSetup 方法
if (!$setup->hasIndex('idx_name')) {
    $setup->alterTable()
        ->addIndex(TableInterface::index_type_KEY, 'idx_name', 'field')
        ->alter();
}

// ❌ 错误：直接查询系统表
// PostgreSQL
$sql = "SELECT 1 FROM pg_indexes WHERE indexname = 'idx_name'";
// MySQL
$sql = "SHOW INDEX FROM table WHERE Key_name = 'idx_name'";
```

## 常用框架查询方法参考

| 操作 | 框架方法 | 说明 |
|------|---------|------|
| 查询 | `select()` | SELECT 查询 |
| 插入 | `insert($data, $conflictFields)` | INSERT，支持冲突处理 |
| 更新 | `update($data)` | UPDATE 查询 |
| 删除 | `delete()` | DELETE 查询 |
| 条件 | `where($field, $value, $op)` | WHERE 条件（支持 =, >, <, IN, NOT IN 等） |
| 排序 | `order($field, $sort)` | ORDER BY |
| 分组 | `group($fields)` | GROUP BY |
| 分组条件 | `having($condition)` | HAVING |
| 关联 | `join($table, $condition, $type)` | JOIN |
| 模型关联 | `joinModel($model, $alias, $condition, $type, $fields)` | 模型 JOIN |
| **分页** | `pagination($page, $pageSize)` | **分页查询（推荐）** |
| 计数 | `count()` / `total()` | 统计数量 |
| 清除 | `clearQuery()` / `reset()` | 清除查询条件 |
| 获取结果 | `getItems()` | 获取分页后的数据列表 |
| 获取分页信息 | `getPagination()` | 获取分页元数据 |

---

## ⭐ 分页查询（pagination）

### 核心原则：必须使用框架内置分页

**禁止手动实现分页逻辑！** 框架已提供完整的 `pagination()` 方法，自动处理 count、limit、offset。

### 标准分页用法

```php
// ✅ 正确：使用框架 pagination() 方法
$model->clearQuery()
    ->where('status', 'active')
    ->order('created_at', 'DESC')
    ->pagination($page, $pageSize)  // 页码从 1 开始
    ->select()
    ->fetch();

// 获取分页数据
$items = $model->getItems();           // 当前页数据列表
$pagination = $model->getPagination(); // 分页元数据

// 分页元数据结构
$pagination = [
    'page'      => 1,      // 当前页码
    'pageSize'  => 20,     // 每页数量
    'totalSize' => 150,    // 总记录数
    'lastPage'  => 8,      // 总页数
];
```

### 完整示例：Service/Controller 中的分页列表

```php
// ✅ 正确：Service 层实现分页列表
public function getPagedList(array $filters, int $page = 1, int $limit = 20): array
{
    $query = $this->model->clearQuery();

    // 应用筛选条件
    if (!empty($filters['account_id'])) {
        $query->where('account_id', (int) $filters['account_id']);
    }
    if (!empty($filters['status'])) {
        $query->where('status', $filters['status']);
    }
    if (!empty($filters['search'])) {
        $query->where('name', '%' . trim($filters['search']) . '%', 'like');
    }

    // 排序 + 分页 + 执行
    $query->order('created_at', 'DESC')
        ->pagination($page, $limit)
        ->select()
        ->fetch();

    $pagination = $this->model->getPagination();

    return [
        'items'  => $this->model->getItems(),
        'total'  => (int) ($pagination['totalSize'] ?? 0),
        'page'   => (int) ($pagination['page'] ?? $page),
        'limit'  => (int) ($pagination['pageSize'] ?? $limit),
        'pages'  => (int) ($pagination['lastPage'] ?? 1),
    ];
}
```

### ❌ 禁止的分页写法

```php
// ❌ 错误：手动实现 count + limit/offset
$query = $this->clearQuery()->where('status', 1);
$total = $query->count('id');                    // count() 后查询状态已改变！
$items = $query->limit($limit, $offset)          // 可能导致错误
    ->select()
    ->fetchArray();

// ❌ 错误：先 count 再重用同一个 $query 对象
$total = $query->count();
$items = $query->select()->fetchArray();  // 查询状态污染，结果不可预测

// ❌ 错误：手动计算 offset
$offset = ($page - 1) * $pageSize;
$this->model->limit($pageSize, $offset)->select()->fetchArray();
```

### 为什么必须用 pagination()？

1. **避免查询状态污染**：`count()` 会改变查询状态，手动分页容易出错
2. **自动计算 total/lastPage**：框架内部处理，无需手动 count
3. **统一的元数据格式**：`getPagination()` 返回标准结构
4. **跨数据库兼容**：框架自动处理 MySQL/PostgreSQL 差异

### pagination() 方法签名

```php
public function pagination(
    int $page = 1,           // 页码（从 1 开始）
    int $pageSize = 20,      // 每页数量
    array $params = [],      // 可选：附加参数
    int $max_limit = 1000,   // 可选：每页最大限制
    int $total = 0           // 可选：预设总数（跳过 count 查询）
): QueryInterface;
```

### 获取分页结果的方法

| 方法 | 返回值 | 说明 |
|------|--------|------|
| `getItems()` | `array` | 当前页的数据列表 |
| `getPagination()` | `array` | 分页元数据（page, pageSize, totalSize, lastPage） |
| `fetchArray()` | `array` | 原始查询结果（不推荐用于分页） |

### 实际案例（2026-02-26）

**错误场景**：手动实现分页，先调用 `count()` 再调用 `select()`，导致查询状态污染，返回 `Call to a member function fields() on false`。

**解决方案**：使用框架内置 `pagination()` 方法，自动处理 count 和 limit/offset，避免状态污染。

```php
// ❌ 之前的错误写法
$total = $query->count('id');  // 污染了 $query
$items = $query->select()->fetchArray();  // 失败！

// ✅ 修复后使用 pagination()
$query->pagination($page, $limit)->select()->fetch();
$items = $model->getItems();
$pagination = $model->getPagination();
```

## ModelSetup 常用方法

| 操作 | 方法 | 说明 |
|------|------|------|
| 检查表存在 | `tableExist()` | 检查表是否存在 |
| 检查字段存在 | `hasField($field)` | 检查字段是否存在 |
| 检查索引存在 | `hasIndex($indexName)` | 检查索引是否存在 |
| 创建表 | `createTable()` | 获取建表构造器 |
| 修改表 | `alterTable()` | 获取改表构造器 |
| 删除表 | `dropTable()` | 删除表 |

## 索引类型常量

```php
use Weline\Framework\Database\Api\Db\TableInterface;

TableInterface::index_type_KEY      // 普通索引
TableInterface::index_type_UNIQUE   // 唯一索引
TableInterface::index_type_FULLTEXT // 全文索引
TableInterface::index_type_SPATIAL  // 空间索引
TableInterface::index_type_MULTI    // 组合索引
```

## 违规示例与修复

### 示例 1：模型 upgrade 中写方言 SQL

```php
// ❌ 违规代码
public function upgrade(ModelSetup $setup, Context $context): void
{
    try {
        $setup->query("CREATE UNIQUE INDEX IF NOT EXISTS \"uk_name\" ON \"{$table}\" (\"field\")");
    } catch (\Exception $e) {
        $setup->query("ALTER TABLE `{$table}` ADD UNIQUE INDEX `uk_name` (`field`)");
    }
}

// ✅ 修复后
public function upgrade(ModelSetup $setup, Context $context): void
{
    if ($setup->tableExist() && !$setup->hasIndex('uk_name')) {
        $setup->alterTable()
            ->addIndex(TableInterface::index_type_UNIQUE, 'uk_name', 'field', '唯一索引')
            ->alter();
    }
}
```

### 示例 2：控制器中拼接 SQL

```php
// ❌ 违规代码
$sql = "SELECT * FROM {$table} WHERE status = 1 AND created_at >= '{$date}'";
$result = $this->model->query($sql);

// ✅ 修复后
$result = $this->model->clearQuery()
    ->where('status', 1)
    ->where('created_at', $date, '>=')
    ->select()
    ->fetchArray();
```

## 常见问题

### Q1: ORM delete() 操作后为什么提示成功但数据还在？

**错误信息：**
```
前端提示：✓ 删除成功
实际效果：刷新后数据还在
```

**原因：**
Weline ORM 的 `delete()` 方法只是构建删除操作，**必须调用 `fetch()` 才能真正执行**。这与 `select()` 操作类似。

**错误代码：**
```php
// ❌ 错误：delete() 后未调用 fetch()
$this->model->reset()
    ->where('id', $id)
    ->delete();  // ❌ 只是构建了删除操作，未真正执行
```

**解决方案：**
```php
// ✅ 正确：delete() 后必须调用 fetch() 执行删除
$this->model->reset()
    ->where('id', $id)
    ->delete()
    ->fetch();  // ✅ 真正执行删除操作

// 或者使用 load() 后删除
$this->model->reset()->load($id);
if ($this->model->getId()) {
    $this->model->delete()->fetch();
}
```

### Q2: 如何正确进行批量删除操作？

**错误代码：**
```php
// ❌ 错误：先查询再循环删除（效率低）
$records = $this->model->reset()
    ->where('status', 'expired')
    ->select()
    ->fetchArray();

foreach ($records as $record) {
    $this->model->reset()
        ->where('id', $record['id'])
        ->delete()
        ->fetch();  // 效率低但功能正确
}
```

**正确做法：**
```php
// ✅ 更好：直接批量删除
$this->model->reset()
    ->where('status', 'expired')
    ->delete()
    ->fetch();  // 一条 SQL 删除所有符合条件的记录
```

### Q3: Weline ORM 删除操作的最佳实践？

**规则：**
1. ✅ `delete()` 构建删除操作，**必须调用 `fetch()` 才能执行**
2. ✅ 批量删除优先，避免循环逐个删除
3. ✅ 删除前可以用 `where()` 添加条件
4. ✅ 使用 `load()` 加载后可以直接 `delete()->fetch()`

**完整示例：**
```php
// 单条删除（已知主键）
$model->reset()->where('id', $id)->delete()->fetch();

// 批量删除（条件删除）
$model->reset()
    ->where('field', $value)
    ->where('status', 'expired')
    ->delete()
    ->fetch();

// 删除前验证（需要先查询）
$record = $model->reset()->where('id', $id)->select()->fetch();
if ($record && $this->canDelete($record)) {
    $model->reset()->where('id', $id)->delete()->fetch();
}

// 使用 load() 方式删除
$model->reset()->load($id);
if ($model->getId()) {
    $model->delete()->fetch();
}

// ❌ 禁止的写法
$model->delete();                    // 错误：未调用 fetch()，删除不会执行
$model->delete()->fetchArray();      // 错误：delete 后不能调用 fetchArray
$model->delete()->select();          // 错误：delete 后不能调用 select
```

**实际案例（2026-01-29）：**
删除主题布局部件时，`deleteWidget()` 方法中使用了 `$this->themeLayout->delete();` 但未调用 `fetch()`，导致删除操作未真正执行。修复后改为 `$this->themeLayout->delete()->fetch();`，问题解决。

参考：[ERROR_LOG.md - ORM delete 操作缺少 fetch() 导致删除失败](../error-tracking/ERROR_LOG.md)

---

### Q4: clone + load + save 偶发 NOT NULL / 唯一约束冲突？

**错误信息：**
```
SQLSTATE[23502]: Not null violation on save()
SQLSTATE[23505]: Unique violation on save()
```

**原因：**
`AbstractModel::checkUpdateOrInsert()` 内部查询前未 `clearQuery()`，`load()` 残留的 WHERE 条件叠加导致存在性检查失败，误走 INSERT 分支。

**框架已修复（2026-02-07）：** `checkUpdateOrInsert()` 三处操作前均已加 `clearQuery()`。

**业务代码最佳实践：**
```php
// ✅ clone + load + save — 框架内部已保证查询隔离
$page = clone $this->pageModel;
$page->load($pageId);
$page->setData('style', $styleCode);
$page->save();

// ✅ 如需额外保险，可在 save 前手动清理
$page->getQuery()->clearQuery();
$page->save();
```

参考：[ERROR_LOG.md - ORM save() 查询状态叠加](../error-tracking/ERROR_LOG.md)

---

### Q5: 安装阶段初始化数据触发外键失败（`SQLSTATE[23503]`）怎么办？

**错误信息：**
```
insert or update on table "..._user_role" violates foreign key constraint
DETAIL: Key (user_id)=(2) is not present in table "..._user".
```

**原因：**
在 `install()` 中硬编码了跨表外键 ID（如 `user_id=2`），但被依赖表并未保证存在该记录。

**正确做法：**
```php
// ✅ 安装阶段先建表，再按“存在性”初始化关联数据
$backendUser = ObjectManager::getInstance(BackendUser::class);
$backendUser->reset()->load(1);
if ($backendUser->getId()) {
    $this->clear()
        ->setData('user_id', (int)$backendUser->getId())
        ->setData('role_id', 1)
        ->save(true);
}
```

**实践建议：**
1. 种子数据避免硬编码跨表 ID。  
2. 需要跨表初始化时，先判定存在性或拆分到专门的数据初始化阶段。

参考：[ERROR_LOG.md - 安装阶段外键失败](../error-tracking/ERROR_LOG.md)

---

### Q6: PostgreSQL 报 `syntax error at or near ":"`（占位符）怎么排查？

**错误信息：**
```
SQLSTATE[42601]: Syntax error at or near ":"
... VALUES (:2a92c4a34...
```

**原因：**
参数名规范化后（如 `:2xxx` -> `:p2xxx`），SQL 与绑定参数键不一致，
导致回退 `exec()` 时占位符未被替换，原始 `:xxx` 被直接发送到 PostgreSQL。

**正确做法：**
```php
// ✅ 参数规范化后保持 SQL 与 bound_values 同步
$sql = $this->normalizeParameterNames($sql);
$this->sql = $sql; // 回退 exec() 路径可复用一致状态
```

**实践建议：**
1. `prepare` 与 `exec` 双路径必须共用一套占位符命名。  
2. 对 SQL/绑定做重命名时，必须确保两者同源一致。

参考：[ERROR_LOG.md - Pgsql 回退 exec 占位符未替换](../error-tracking/ERROR_LOG.md)

---

### Q7: 如何使用 IN 条件查询？

**错误用法：**
```php
// ❌ 错误：框架没有 whereIn() 方法！
$this->model->whereIn('id', [1, 2, 3])->select()->fetchArray();
// 报错：Call to a member function xxx() on false
```

**正确用法：**
```php
// ✅ 正确：使用 where() 方法的第三个参数指定 IN 操作符
$this->model->clearQuery()
    ->where('id', [1, 2, 3], 'IN')
    ->select()
    ->fetchArray();

// ✅ NOT IN 条件
$this->model->clearQuery()
    ->where('status', ['deleted', 'expired'], 'NOT IN')
    ->select()
    ->fetchArray();
```

**where() 方法签名：**
```php
where(
    array|string $field,      // 字段名
    mixed $value = null,      // 值（数组时配合 IN/NOT IN）
    string $condition = '=',  // 操作符：=, >, <, >=, <=, IN, NOT IN, LIKE 等
    string $where_logic = 'AND'
): QueryInterface
```

**实际案例（2026-02-26）：**
使用了不存在的 `whereIn()` 方法导致返回 `false`，后续链式调用报错 `Call to a member function fields() on false`。修复后改为 `where($field, $array, 'IN')`。

---

## 总结

1. **业务代码中绝对禁止写任何数据库方言 SQL**
2. **使用框架提供的查询方法和适配器方法**
3. **方言 SQL 只能在 `Framework/Database/Connection/Adapter/{DB}/` 目录下的适配器类中编写**
4. **遇到框架不支持的操作，应该扩展适配器，而不是在业务代码中写方言 SQL**
5. **ORM 操作规范：delete() 必须调用 fetch() 才能真正执行删除**
6. **ORM 内部已保证 save() → checkUpdateOrInsert() 的查询隔离，无需业务层手动 clearQuery()**
