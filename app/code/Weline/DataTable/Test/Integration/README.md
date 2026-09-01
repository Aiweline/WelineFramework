# DataTable integration tests

`CompositeWriteRuntimeTest.php` 使用框架 ORM、统一写事务协调器和隔离 SQLite 表，验证：

- `datatable.write-plan.v1` 与依赖顺序；
- `u.id -> o.user_id` 的真实主键回填；
- token 只能消费一次，且确认后载荷不可更改；
- 第二个模型唯一键冲突时，第一个模型写入被回滚；
- 精确测试行和仅由用例创建的临时表在 `tearDown()` 中清理。

运行：

```bash
php bin/w phpunit:run --module=Weline_DataTable --name=CompositeWriteRuntimeTest --phpunit
```
