---
name: database-model-standards
description: |
  Database model and SQL writing standards for Weline Framework.
  
  Use when:
  - Creating/modifying models and database tables
  - Writing database queries (select/insert/update/delete)
  - Adding columns/fields to existing tables（使用 #[Col] 声明式，执行 setup:upgrade 同步）
  - Using ORM operations (CRITICAL: fetch() required!)
  - Pagination queries (MUST use framework pagination!)
  - Cross-database compatible code
  
  Keywords: Model, 模型, database, 数据库, ORM, select, insert, update, delete, fetch, 查询,
  加字段, 添加字段, add column, addColumn, #[Col], #[Table], #[Index], 声明式 schema, SchemaDiff, SchemaParser, setup:upgrade,
  表结构, schema, 字段, field, column, 数据库升级, PostgreSQL, MySQL,
  分页, pagination, paging, page, pageSize, 翻页, 每页, 页码, limit, offset, getItems, getPagination,
  totalSize, lastPage, 总数, 总页数, 列表, list, 数据列表, 分页查询, paged query, paginated,
  LocalModel, LocalDescription, schema_primary_keys, 联合主键, column does not exist, 列不存在, 报错
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

### 2. 表结构：声明式 Schema（#[Col]/#[Table]/#[Index]）

**已废弃**：Model 的 `install()`/`upgrade()`/`setup()` 及 `ModelSetup`/`hasField()` 建表改表方式已废弃。**属性上的 #[Col] 已废弃**，SchemaParser 仅解析 **常量** 上的 #[Col]。

**当前做法**：在 Model 类上使用 **声明式注解** 定义表结构，由 **SchemaDiffStage** 在 `php bin/w setup:upgrade` 时解析并同步到数据库。

**约定**：`#[Col]` 必须标注在 **`schema_fields_*` 常量**上，列名 = 常量值。只有带 #[Col] 的常量才会被 SchemaParser 解析并参与表结构同步；若某字段只有常量没有 #[Col]，该字段不会出现在 SchemaDiff 中。

**完整 Model 写法示例**（表名、主键、每个字段均需声明）：

```php
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;

#[Table(comment: '示例表')]
#[Index(name: 'uk_name', columns: ['name'], type: 'UNIQUE', comment: '名称唯一')]
class YourModel extends Model
{
    public const schema_table       = 'weline_your_table';  // 表名（含前缀）
    public const schema_primary_key = 'id';

    public string $_primary_key      = 'id';
    public array $_unit_primary_keys = ['id'];

    #[Col(type: 'int', nullable: false, primaryKey: true, autoIncrement: true, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col(type: 'varchar', length: 255, nullable: false, comment: '名称')]
    public const schema_fields_NAME = 'name';
    #[Col(type: 'int', nullable: false, default: 1, comment: '状态')]
    public const schema_fields_Status = 'status';
}
```

- **表与主键**：显式定义 `schema_table`、`schema_primary_key`、`$_primary_key`、`$_unit_primary_keys`，与框架 AbstractModel 初始化约定一致。
- **字段**：每个数据库列对应一个 `public const schema_fields_XXX = '列名';`，且该常量上必须有 `#[Col(type: ..., length: ..., nullable: ..., default: ..., comment: '...')]`，否则该列不会参与 `setup:upgrade` 的表结构同步。
- **加字段 / 改表**：在 Model 上增改带 #[Col] 的 `schema_fields_*` 常量或 #[Index]，然后执行 `php bin/w setup:upgrade`。禁止在业务代码中手写 DDL 或方言 SQL。
- **业务初始化 / 种子数据**：放在模块 **Setup/Install.php**、**Setup/Upgrade.php**，不在 Model 内。
- **columns()**：无需重写。基类 `Model::columns()` 通过 `SHOW FULL COLUMNS` 获取运行时列信息；SchemaDiff 仅通过反射读取常量上的 #[Col]。

### 2.1 SchemaParser / SchemaDiff 解析行为（必读）

SchemaParser 在 `setup:upgrade` 时解析 Model 类，仅当满足以下条件才会建表/改表：

| 规则 | 说明 |
|------|------|
| **只解析类** | 解析继承链（子类 → 父类 → AbstractModel），**不解析接口**。接口中的 `schema_fields_*` 常量无 #[Col]，不会参与建表。 |
| **必须有 #[Col]** | 只有带 `#[Col]` 的 `schema_fields_*` 常量才会被解析。**仅有常量无 #[Col] 的列不会出现在 SchemaDiff 中，也不会建表/加列。** |
| **子类覆盖父类** | 子类中同名常量（列名相同）会覆盖父类的列定义。 |
| **parse 返回 null 则不建表** | 若 `parseColumns()` 返回空数组，则 `parse()` 返回 null，该 Model 不会被 SchemaDiff 处理，表不会建/不会改。 |
| **schema_primary_keys** | 联合主键时定义 `schema_primary_keys = ['col1', 'col2']`，会跳过 AbstractModel 默认的 `id` 列。 |
| **schema_primary_key** | 单主键且非 `id` 时定义 `schema_primary_key = 'pk_name'`，会跳过默认 `id`。 |

**典型错误**：Model 只有 `public const schema_fields_ID = 'page_id'` 无 #[Col]，SchemaParser 跳过该常量 → 表无 `page_id` 列 → 查询报 `column "page_id" does not exist`。

### 2.2 LocalModel 子类表结构声明（多语言翻译表）

继承 `Weline\I18n\LocalModel` 的模型（如 `*LocalDescription`）必须**显式为关联主表 ID 等字段添加 #[Col]**，否则 SchemaDiff 不会同步这些列。

```php
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\I18n\LocalModel;

#[Table(comment: 'XXX多语言翻译表')]
#[Index(name: 'idx_xxx_id', columns: ['xxx_id'], comment: '主表ID索引')]
class LocalDescription extends LocalModel
{
    public const schema_table = 'vendor_module_xxx_local_description';
    /** 联合主键：(xxx_id, local_code) */
    public const schema_primary_keys = ['xxx_id', 'local_code'];

    /** 关联主表ID — 必须带 #[Col] 才会被 SchemaDiff 同步 */
    #[Col(type: 'int', nullable: false, primaryKey: true, comment: '关联主表ID')]
    public const schema_fields_ID = MainModel::schema_fields_ID;

    /** 语言代码 — 接口定义无 #[Col]，子类需显式声明 */
    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: '语言代码')]
    public const schema_fields_local_code = 'local_code';

    /** 配置 JSON（可选） */
    #[Col(type: 'text', nullable: true, comment: '配置JSON')]
    public const schema_fields_config = 'config';

    // 其他多语言字段...
    #[Col(type: 'varchar', length: 255, nullable: true, comment: '名称')]
    public const schema_fields_NAME = 'name';
}
```

**要点**：
- `schema_fields_ID` 引用主表主键名（如 `page_id`、`store_id`），**必须有 #[Col]**。
- `schema_fields_local_code`、`schema_fields_config` 等在 `LocalModelInterface` 中定义但无 #[Col]，子类需显式加 #[Col] 才能参与建表。
- 使用 `schema_primary_keys` 表示联合主键，不要混用 `schema_primary_key` 单主键。

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

**批量唯一键写入注意（PostgreSQL）**:
- 对唯一键（如 `identify`）做批量写入时，先做“批内去重”，避免同一批次重复键直接冲突。
- 若升级脚本场景出现批量 upsert 路径不稳定，可采用幂等策略：`where(unique_key IN (...))->delete()->fetch()` 后再 `insert(rows)->fetch()`。
- 模型复用场景下，删除前请先 `recovery()` 清理模型残留 `id`；否则 `delete()` 可能被委托逻辑降级为“按主键删单条”，导致 `where IN` 条件不生效。
- 升级/同步类任务优先保证“可重复执行不报错”，再优化为更激进的 upsert。

**PostgreSQL 特别注意：**
- `insert($data, $conflictFields)` / `save(true)` 最终生成的 `ON CONFLICT (...)` 字段，**必须与数据库中真实存在的唯一索引完全一致**
- 不能把普通业务字段误当成冲突字段，否则 PostgreSQL 会先报 `there is no unique or exclusion constraint matching the ON CONFLICT specification`，随后同事务里的后续 SQL 常表现为 `SQLSTATE[25P02]: current transaction is aborted`

```php
// ✅ 正确：真实唯一键是 (user_id, key)
$model->setData('user_id', $userId, true)
    ->setData('key', $key, true)
    ->setData('module', $module) // 普通字段，不参与冲突键
    ->setData('name', $name)     // 普通字段，不参与冲突键
    ->save(true);

// ❌ 错误：把 module、name 也塞进冲突键
$model->setData('user_id', $userId, true)
    ->setData('key', $key, true)
    ->setData('module', $module, true)
    ->setData('name', $name, true)
    ->save(true);
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

## 索引与表结构

索引与表结构均在 Model 上通过 **#[Index]**、**[Col]** 声明，由 `setup:upgrade` 触发 SchemaDiff 同步，禁止在业务代码中查系统表或手写 DDL。

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

## 表结构维护（已废弃 ModelSetup/hasField）

**已废弃**：`ModelSetup`、`hasField()`、`hasIndex()`、`createTable()`、`alterTable()` 等建表/改表方式已废弃。表结构统一使用 **#[Table]/#[Col]/#[Index]** 声明，执行 `php bin/w setup:upgrade` 由 SchemaDiffStage 同步。见上文「表结构：声明式 Schema」。

## 违规示例与修复

### 示例 1：加字段/加索引

**已废弃**：在 Model 的 `upgrade()` 里用 `hasField`/`hasIndex`+`alterTable()` 已废弃。

**正确做法**：在 Model 上增加 `#[Col]` 或 `#[Index]` 注解，然后执行 `php bin/w setup:upgrade`。

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

参考：[开发注意事项（高抽象）](../error-tracking/DEVELOPMENT_NOTES.md)

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

参考：[开发注意事项（高抽象）](../error-tracking/DEVELOPMENT_NOTES.md)

---

### Q5: 安装阶段初始化数据触发外键失败（`SQLSTATE[23503]`）怎么办？

**错误信息：**
```
insert or update on table "..._user_role" violates foreign key constraint
DETAIL: Key (user_id)=(2) is not present in table "..._user".
```

**原因：**
在安装/种子逻辑中硬编码了跨表外键 ID（如 `user_id=2`），但被依赖表并未保证存在该记录。

**正确做法：**
- 种子/初始化逻辑应放在模块 **Setup/Install.php**（或 Setup/Upgrade.php），不在 Model 内。
- 跨表初始化时先判定存在性再写关联数据：
```php
// ✅ Setup/Install.php 中：先建表（SchemaDiff），再按“存在性”初始化
$backendUser = ObjectManager::getInstance(BackendUser::class);
$backendUser->reset()->load(1);
if ($backendUser->getId()) {
    $userRole->clear()
        ->setData('user_id', (int)$backendUser->getId())
        ->setData('role_id', 1)
        ->save(true);
}
```

**实践建议：**
1. 种子数据避免硬编码跨表 ID。  
2. 需要跨表初始化时，先判定存在性或拆分到专门的数据初始化阶段。

参考：[开发注意事项（高抽象）](../error-tracking/DEVELOPMENT_NOTES.md)

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

参考：[开发注意事项（高抽象）](../error-tracking/DEVELOPMENT_NOTES.md)

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

### Q8: `column "xxx_id" does not exist` 怎么办？

**错误信息示例：**
```
SQLSTATE[42703]: Undefined column: 7 ERROR: column "page_id" does not exist
LINE 1: ... WHERE ("page_id" ...
```

**原因**：Model 定义了 `schema_fields_ID = 'page_id'` 等常量，但**未加 #[Col]**。SchemaParser 只解析带 #[Col] 的常量，该列未参与 SchemaDiff，表中不存在该列。

**解决**：在 Model 上为相关常量添加 #[Col]，然后执行 `php bin/w setup:upgrade`：

```php
#[Col(type: 'int', nullable: false, primaryKey: true, comment: '关联页面ID')]
public const schema_fields_ID = Page::schema_fields_ID;  // 'page_id'
```

**典型场景**：`LocalModel` 子类（如 `Page\LocalDescription`）必须为 `schema_fields_ID`、`schema_fields_local_code` 等显式加 #[Col]，否则表结构与业务查询不一致。见上文「2.2 LocalModel 子类表结构声明」。

---

## 总结

1. **业务代码中绝对禁止写任何数据库方言 SQL**
2. **使用框架提供的查询方法和适配器方法**
3. **方言 SQL 只能在 `Framework/Database/Connection/Adapter/{DB}/` 目录下的适配器类中编写**
4. **遇到框架不支持的操作，应该扩展适配器，而不是在业务代码中写方言 SQL**
5. **ORM 操作规范：delete() 必须调用 fetch() 才能真正执行删除**
6. **ORM 内部已保证 save() → checkUpdateOrInsert() 的查询隔离，无需业务层手动 clearQuery()**
