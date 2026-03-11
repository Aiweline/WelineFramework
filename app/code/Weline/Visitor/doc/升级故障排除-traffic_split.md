# 升级报错：traffic_split value too long for type character varying(20)

## 现象

执行 `php bin/w setup:upgrade` 时出现：

```text
Schema DDL failed (table="public"."m_w_ab_test" kind=modify_column col=traffic_split): 
SQLSTATE[22001]: String data, right truncated: 7 ERROR: value too long for type character varying(20)
```

## 原因

表 `m_w_ab_test` 中列 `traffic_split` 在库里为 `varchar(20)`，升级需改为 `varchar(255)`。在 PostgreSQL 执行 ALTER 时可能因数据或执行顺序触发上述错误。

## 处理步骤（一次性）

1. **在 PostgreSQL 中先手动扩展列长**（表名以实际表前缀为准，一般为 `m_w_ab_test`）：

   ```sql
   ALTER TABLE m_w_ab_test ALTER COLUMN traffic_split TYPE character varying(255);
   ```

2. **再执行升级**：

   ```bash
   php bin/w setup:upgrade
   ```

手动改列后，实际表结构已为 `varchar(255)`，与模型声明一致，Schema 差异会消失，升级可正常完成。

## 可选：清理缓存后重试

若希望不手动改库、仅通过升级完成，可先清缓存再升级（若仍报错，再采用上面 SQL）：

```bash
# 清理生成与缓存（按项目实际路径调整）
rm -rf generated/* var/cache/*
php bin/w setup:upgrade
```
