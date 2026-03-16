---
name: database-model-standards
description: 数据库模型与 SQL 规范。#[Col]/#[Table]、ORM 必须 fetch()、分页用 pagination()、禁止业务代码写方言 SQL。
globs:
  - "**/Model/**/*.php"
alwaysApply: false
---

# database-model-standards（极简版）

## 何时使用

- 创建/修改 Model、表结构
- 写 select/insert/update/delete
- 加字段（#[Col] + setup:upgrade）
- 分页查询、ORM 操作

## 必做

- 表结构用 #[Table]/#[Col]/#[Index] 声明，执行 `setup:upgrade` 同步
- 所有 ORM 操作必须调用 `fetch()` 执行
- 分页用 `pagination($page, $pageSize)->select()->fetch()`，禁止手动 count+limit
- IN 条件用 `where('id', [1,2,3], 'IN')`，无 whereIn()
- LocalModel 子类必须为 schema_fields_ID 等显式加 #[Col]
- 方言 SQL 只能在 Framework 适配器中写

## 最小示例

```php
// 查询
$model->clearQuery()->where('status', 1)->select()->fetch();

// 分页
$model->clearQuery()->where('status', 1)->pagination($page, 20)->select()->fetch();
$items = $model->getItems();
$pagination = $model->getPagination();

// 删除必须 fetch
$model->where('id', $id)->delete()->fetch();
```

## 禁止

- select/insert/update/delete 后不调用 fetch()
- 业务代码写方言 SQL（MySQL/PostgreSQL 特有语法）
- 在 Setup/Upgrade.php 做字段 CRUD（表结构用 #[Col]+setup:upgrade）
- 使用框架不存在的方法（先 grep/search 确认）
- 手动 count 后再 select 同一 query（污染状态）
