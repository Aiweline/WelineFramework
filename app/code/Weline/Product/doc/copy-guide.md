# Store Copy 指南（P2C-002）

`ProductCopyService` 提供 Store 经营投影复制：`blank` / `site_pull` / `store_inherit` 三入口共用同一服务。

## 流程

```text
createDraft → preview → commit(request_hash)
                 ↘ cancel（仅未提交）
```

- 同一 `draft_id + request_hash` 成功提交可安全重放并返回原 receipt；同一
  draft 使用不同 hash 固定返回 `copy_idempotency_conflict`
- 每个目标 Store 是一个原子边界；目录与 Inventory 命令任一失败时必须
  一起回滚，draft 保持 `draft`
- 生产路径使用 `ProductCopyOperation` 持久化 draft/claim/receipt/audit；
  memory/harness 与真实分片共享同一 DTO 与幂等语义

## 后台与 QueryProvider 边界

- 后台入口：`/{backendKey}/websites/admin/store-copy/wizard`
- 浏览器资源：`Weline.Api.resource('product_copy')`
- Product 自有 `ProductCopyQueryProvider` 发布
  `scopeOptions/createDraft/getDraft/preview/commit/cancel`；Websites 只拥有
  页面与 `Weline_Websites::store_copy_wizard` ACL，不创建跨模块 Service
  适配器
- 六个操作均为 `auth=backend`、`external=false`，并显式绑定上述 ACL
- `scopeOptions` 通过 Websites 公共 Catalog 接口发布选项；`createDraft`
  再校验目标/来源 Store 的 Website 归属与 active 生命周期
- 草稿以 `target_store_ids` 保存目标 Store 集合，并保留
  `target_store_id` 作为旧调用兼容字段；浏览器未传任一字段时默认选择
  目标 Website 下全部活动 Store，显式列表可取消个别 Store，但不得为空
- 页面必须先创建 draft 并 preview，之后才能启用 commit；提交
  `request_hash` 在同一浏览器草稿内稳定，重放由服务端 receipt 判定

## 入口

| entry | 含义 |
|---|---|
| `blank` | 空 Store 上下文，不拉目录 |
| `site_pull` | 从 Website 目录（`source_store_id=0`）勾选分类/商品到目标 Store |
| `store_inherit` | 从源 Store 选品/overlay 继承到目标 Store；跨 Website 时先建目标目录再挂 Store |

## 字段包

`identity` / `attrs` / `price` / `media` / `inventory`

- **库存默认 0**：即使包含 `inventory` 包，也只有 `inventory_copy_qty=true` 才复制 `on_hand`
- **cleared**：attrs/price 的 cleared 必须原样写入目标，禁止当 inherit
- **重复来源**：`duplicate_policy=skip`（默认）或 `update_selected_fields`（只更新勾选包，不删未选字段）

## 分类树

- 勾父 → 默认含子孙；`excluded_category_ids` 取消该分类及完整子树
- `include_products=false` → 只规划分类，不建商品投影
- 一商品一投影、可多 category link

## 跨 Website

1. 目标 Website 新建 Category（**新 UUID**）/ Product / Offer / Media(share blob)
2. 再写目标 Store select / overlay
3. **不抬升**来源 Store overlay

Category 的目标 UUID 由来源身份与目标 Website 稳定派生：与来源 UUID
不同，但同一来源再次补抄时能定位既有目标分类。Product/Offer 使用全局
UUID 去重；`skip` 仍允许把既有目录挂入新的目标 Store，不重复创建目录行。

## 持久化边界

- `ProductCopyDurableCatalogAdapter` 只对已 `ready` 的 Website shard 工作，
  request path 不 provision、不执行 DDL
- preview 与 commit 按每个 `global_product_uuid` 调用归属/分享治理规则；
  跨站未授权返回 `product_copy_not_authorized`，并在 claim、目录和库存写入前终止
- commit claim 在写入前持久化；目录 DML、全部所选 Store overlay、
  Inventory 命令与成功 receipt 处于同一目标 Website 事务边界
- 任一所选 Store 写入失败时，目录及所有 Store/库存写入整体回滚，claim
  复位为 `draft`；返回稳定错误码，不透传异常详情
- Product/Offer 目录只实例化一次，Store 选择、来源 Store attrs/price
  overlay 与库存初始化遍历 `target_store_ids`；库存幂等键包含 Store
- attrs/price 复制显式 Website 行及来源 Store 行；跨站先写目标 Website
  默认值，再把来源 Store overlay 写到每个所选目标 Store，`cleared`
  保持终止语义
- 库存包默认把每个所选 Store 的数量初始化为 0；只有
  `inventory_copy_qty=true` 且库存复制能力可用时才读取并复制来源数量

## 测试入口

```bash
php bin/w phpunit:run --name=ProductCopyServiceTest
php bin/w phpunit:run --name=ProductCopyDurableCatalogAdapterTest

# 正式 PostgreSQL 矩阵；只允许本任务创建并登记的 mig_clone_* 数据库
WELINE_PRODUCT_COPY_TEST_PGSQL_DATABASE=mig_clone_xxx \
  php bin/w phpunit:run --name=ProductCopyDurableCatalogAdapterTest
```

默认的一次性 SQLite 模式只作隔离开发/可移植性回归，覆盖跨站/同站复制、
已有目录补抄、分类身份、字段包、
媒体、库存默认 0、receipt 重放/冲突，以及库存第二次写入失败时目录与
库存整体回滚。设置 `WELINE_PRODUCT_COPY_TEST_PGSQL_DATABASE` 后，同一
测试改用 PostgreSQL 并作为正式验收证据；连接可通过
`WELINE_PRODUCT_COPY_TEST_PGSQL_HOST/PORT/USERNAME/PASSWORD` 指定。
只允许传入本任务创建、可在测试后删除的一次性数据库，禁止使用共享库或
生产库。PostgreSQL 矩阵同样覆盖跨站/同站/重放/补抄和目录+库存共同回滚。
每个 PostgreSQL 用例创建随机 `product_copy_test_*` schema 并在 finally 中
整 schema 清理，不与 full clone 的既有 Product 表碰撞；数据库名不匹配
`mig_clone_[a-z0-9_]+` 时直接拒绝。2026-08-26 正式矩阵为 4 tests / 88
assertions，结束后随机 schema 数为 0，临时 full clone 已通过
`mig:foundation clone-destroy` 销毁。

QueryProvider 契约测试覆盖 ACL、scope 归属和真实 blank preview/commit；
后台可见交互仍必须在本任务专用 WLS 上完成 Browser/E2E 验收，不能用
静态断言替代。
