# Weline_B2B（P4C）

## ToC/ToB 一期（文档合同）

- **万能车/单**：不新建平行车/单；`Weline_Cart` / `Weline_Order` 各内置类型 SPI + Registry（默认 `toc`），**不**硬依赖 B2B。
- **双注册**：B2B 启用后自动向 Cart **与** Order 注册 `tob`；卸载后 Registry 无 `tob`，零售继续，历史 tob 单只读 fail-soft。
- **热路径**：未装/卸载后加车、列表价、结账 **零** B2B 类探测与 miss 重试税。
- **SellingModePolicy**（`2.4.0` / 产品旗标水合 `2.6.58` / 显示门禁 `2.6.69`）：Website/Store ConfigStore 键 `selling_mode_toc_enabled` / `selling_mode_tob_enabled`（默认 true）；商品 EAV `selling_mode_tob` fail-soft（`ProductSellingModeFlags`）；会话仅偏好；MOQ/step 默认 5。
- **批发显示门禁（`2.6.69`+ / 加购路由 `2.6.77`）**：`ProductWholesaleEligibility` 要求产品 tob 允许 + **SKU 本站生效价目档**才渲染 PDP 批发段/阶梯价；站点默认 VIP 模板单独存在不放行展示。否则当正常品。**无资格加购进 toc（零售车）**（`B2BCartOfferRouting` remap）；有资格仍走 tob + moq/step + 价目。
- **默认批发模板与护栏（`2.6.73`）**：website 配置 VIP0–12 起订/折扣模板 + `max_discount_bps`（默认 2000）/ `min_margin_bps`（默认 0）；无显式价目时运行时继承；改模板不回刷 PriceList/订单；写路径与配置保存拒超折扣；模板保存清店面 FPC。
- **PDP 阶梯价说明（`2.6.58`/`2.6.69`/`2.6.70`）**：有资格时 `qty-tiers` 零售 SSR 仍输出 DOM（`hidden`），切批发即时 unhide；无资格/关批发则不渲染。后台：`edit::offers-after` 内嵌启用批发与 SKU 阶梯编辑（website 级价目 copy-forward）；`basic-after` 为镜像开关；创建仅 basic 开关。
- **商品阶梯写入（`2.6.70`/`2.6.71`）**：`ProductSkuQtyTierAdminService::upsertSkuQtyTiers`；`save-product-sku-tiers`；ControlCenter 单档兼容 + 可选附加档；商品 Hook 深链/保存 URL 用 `getBackendUrl('b2b/backend/…')`（`2.6.71` 修 Product 宿主前缀 404）。
- **批发配置页（`2.6.15`）**：菜单「批发配置」→ `Controller/Backend/Config`；Extends 声明同上二键；本页 `<w:config:embed>` 快捷启停（参照客服配置）；范围走 URL `target_scope`。
- **ControlCenter 作用范围（`2.6.43`）**：客户组/价目表/报价/快照/身份申请/挂单/迁移状态顶栏统一 `<w:scope>`（网站→店铺→渠道）；列表按 `website_id`/`channel_id` 过滤；写回保留 `target_scope`。
- **身份申请**（`2.4.0` / 状态投影 `2.6.29` / 运营面 `2.6.38` / 唯一 upsert `2.6.39` / 删除 ACL `2.6.40` / 删动作 `2.6.42` / 全模块范围 `2.6.43`）：`MembershipApplicationService` + 表 `weline_b2b_membership_application`；前台 Query `b2b.membership.submit` / `b2b.membership.status`；后台 ControlCenter 批准指定组、驳回、**行内撤销**、**重新授权**、**删除申请**（ACL `applications:delete` + `<acl>` 包裹）与**多站快速撤销**；顶栏作用范围过滤整页；**不**改 Customer 注册。
- **店面 CTA 投影**：`MembershipStatusProjection`（`need_login|can_apply|pending|rejected|active|inactive`）。有效 membership 永远 `active` 且 `should_poll=false`。仅 `pending` 轮询：首探 45s，之后 300s；通过后停申请 poll；回前台/再点批发做资格机会刷新。`approved` 无资格投影为 `inactive`（可编辑后重提）。
- **重复提交**：同一 `(customer, website)` **仅一条申请记录**；已有 pending 拒绝再插；驳回/撤销后 `submit` 更新当前行回 pending；已开通拒绝再申请。
- **在途单**：撤销后新 tob 加车/报价/结账 fail-closed；已生成 hang/订单不自动作废，走既有 hang 状态机。
- **定金挂单**（仅 `order_type=tob`）：全面禁折后含税商品小计 × 30% 为定金；`hang_status`：`awaiting_deposit` → `awaiting_merchant_approval` → `awaiting_balance` → paid；先定金后审批再尾款。状态图见 [`hang-status-state.md`](hang-status-state.md)。
- **尾款支付闭环**：账户 `#orders`（列表+详情）「支付尾款」→ `/checkout?purpose=balance&order_uuid=`；hang 面板渲染可用支付方式（禁止 silent `fake_card`）；稳定幂等键 `hang_{purpose}_{order_uuid}`；浏览器回跳按 `metadata.purpose` 推进 hang（定金不 `notifyOrderPaid`）；`already_paid` 时 reconcile hang；尾款禁止再 reserve 批发信用。
- **支付桥接**：`B2BHangPaymentBridgeInterface`；Hang Event `Weline_B2B::hang_status_changed`；定金后 `InventoryCapabilityInterface::reserve`。
- **订单沟通**：`b2b.orderChat.*` + `#b2b-order-chat` + `AccountMenuSignal` `b2b.order_chat`（不绑客服 ChatSession）。
- **尾款协商改价**：`proposeBalanceRevision` / `confirmBalanceRevision`；`hang_revision_pending` 挡支付；确认后 `OrderFacadeInterface::reviseTobHangPayable`。
- **数量档 / MOQ**：价目项 `min_qty`（旧行默认 1）；Engine `qty` 取最高档；tob 车默认 moq=5 / step=5（`B2BCartQtyPolicy`）；`2.6.69` 起仅对批发资格 SKU 强制。
- **店面 Theme UI（2.6.0 / 双车 2.6.20）**：PDP 价格旁 ToC/ToB 切换（cookie `weline_selling_mode`）；tob 数量 MOQ/step=5；**迷你车/购物车「零售车|批发车」分段**（body-end boot 加载 `b2bSellingMode` 注入 type-host）；结账定金说明+禁券；账户订单 hang CTA（`purpose=deposit|balance`）。
- **店面价**：`B2BStorefrontPriceAdjustmentProvider`；含价缓存 vary `selling_mode` + tob `group_id`（详见 Product `storefront-offer-price.md`）。
- 需求正文：`doc/需求.md`（`REQ-B2B-0002`…`0007`）；Cart 键与摘要：`Weline_Cart/doc/cart.md`；事件追加字段：`Weline_Order/doc/event/order_created.md` 等。

## 冻结

| 项 | 值 |
|---|---|
| owning module | `Weline_B2B`（禁止把价目逻辑塞入 Product/Cart/Order 内部） |
| rollout | `B2BRolloutGate`；capability=`b2b`；默认 **mode off**（关闭 B2B 候选；零售路径继续） |
| website | `website_id=0`（default）合法 |
| 规则栈 | Channel 覆盖 → Website 价目 → 零售回退 |
| quote / submit | 服务端 token + 重验；版本/组冲突拒绝 |
| snapshot | 下单瞬间冻结；后续价目变更不回算旧单 |
| MIG | 仅 registry 登记的 PostgreSQL `full` clone；版本映射、事实或快照不守恒 fail closed |

## 组件

| 路径 | 职责 |
|---|---|
| P4C-001 | durable CustomerGroup/Membership/PriceList/Item / B2BPriceEngine / shadow |
| P4C-002 | quote token / ACL / submit recheck / Order snapshot |
| `Service/B2BMigrationService` | MIG-P4C cutover |
| `Service/B2BShadowComparator` | shadow + mapping observe |
| `Service/B2BQueryHarnessCatalog` | TEST-P4C-01 的进程外只读 E2E fixture（`var/b2b_query_harness/`） |
| `extends/.../Query/B2BQueryProvider` | 仅发布只读 `b2b.resolve`；fixture 未准备时执行 fail closed |
| `Console/Commerce/MigrateP4cB2b` | CLI `commerce:migrate-p4c-b2b` |

## 验证

```bash
vendor/bin/phpunit --bootstrap app/code/Weline/B2B/Test/Unit/bootstrap.php \
  app/code/Weline/B2B/Test/Unit/Service/

cd tests/e2e
PLAYWRIGHT_TARGET_ORIGIN=https://127.0.0.1:{port} \
  PLAYWRIGHT_DISABLE_PROXY=1 PLAYWRIGHT_WORKERS=1 \
  PLAYWRIGHT_TEST_FILES='["app/code/Weline/B2B/Test/e2e/frontend/plan-p4c01-b2b-retail-candidate.spec.js","app/code/Weline/B2B/Test/e2e/frontend/plan-p4c03-05-b2b-submit-snapshot.spec.js"]' \
  NODE_TLS_REJECT_UNAUTHORIZED=0 \
  node node_modules/playwright/cli.js test --config=playwright.config.js \
  --project=chromium --workers=1
cd ../..

php bin/w commerce:migrate-p4c-b2b help
php bin/w mig:foundation clone-create --mode=full --purpose=p4cb2b
php bin/w commerce:migrate-p4c-b2b preflight \
  --database=mig_clone_p4cb2b_... --website=0
php bin/w commerce:migrate-p4c-b2b apply \
  --database=mig_clone_p4cb2b_... --website=0
php bin/w commerce:migrate-p4c-b2b verify \
  --database=mig_clone_p4cb2b_... --checkpoint=p4cb2b-...
php bin/w commerce:migrate-p4c-b2b allowlist \
  --database=mig_clone_p4cb2b_... --checkpoint=p4cb2b-... --website=0
php bin/w commerce:migrate-p4c-b2b rollback \
  --database=mig_clone_p4cb2b_... --checkpoint=p4cb2b-...
php bin/w mig:foundation clone-destroy --database=mig_clone_p4cb2b_...
```

## P4C-001 候选合同

- 生产 Group、Customer+Website membership、PriceList revision 与 SKU 明细
  都是 ORM 持久事实；数组实现只能显式 `forTesting()`。
- Customer group 只能从服务端 `(customer_id, website_id)` membership
  解析；请求携带 `group_id` 一律拒绝，Website `0` 合法。
- 每个 `(list_id, version)` 不可覆盖；Channel 精确命中优先，其次 Website
  revision，最后零售价。金额只使用整数 minor unit。
- candidate 层只读且永远不写 Order。零售身份声明 B2B list/version
  必须 fail closed，写入计数保持 `0`。
- Browser QueryProvider 是 TEST-P4C-01 fixture 表面：fixture 不存在时
  fail closed，浏览器不能 configure/clear harness。

## P4C-002 提交与快照合同

- production/default runtime 使用 PostgreSQL。SQLite 只用于隔离的开发
  可移植性回归，不得替代 PostgreSQL 验收。
- quote token 是 ORM 持久事实，绑定 Customer、Website、SKU、原始零售价、
  候选版本、签发时间和过期时间；token identity 不可覆盖。
- submit 必须携带稳定 `order_ref`，并以 token 保存的原始零售价重新解析
  当前候选；Customer、Website、membership、价格来源、金额、版本或 Channel
  任一漂移都在 Order snapshot 写入前拒绝。
- PostgreSQL submit 事务先对 token 做 `FOR UPDATE` 锁定读，再原子写入
  snapshot 并消费 token；重放、过期和并发提交不能产生第二个 Order。
- snapshot 对 `order_ref` 与 `token_id` 都有唯一约束，payload hash 在读取时
  重验；没有更新入口，读取必须同时匹配 Customer 与 Website。
- 浏览器 QueryProvider 仍只发布候选读取 `resolve`；quote/submit/snapshot
  是服务端接口，不通过前端测试资源暴露。

## MIG-P4C 迁移合同

- 所有动作都必须显式绑定 registry 登记的 PostgreSQL `full` clone；
  仅名字匹配 `mig_clone_*`、schema-only clone 或 fingerprint 漂移都会
  非零失败。
- `preflight` 只读目标 Website 的真实 group、membership、list
  revision/item、quote 与 Order snapshot；Website `0` 是合法值，但仍需
  `--website=0` 显式冻结。
- `apply` 首先冻结 schema/facts/version mapping/shadow sample/checkpoint，
  然后只进入 shadow，业务事实写入计数必须为 `0`，不得直接 production-on。
- `verify` 必须由新进程从 checkpoint/journal 重载并重新读取 ORM；
  `allowlist` 是 fresh verify 后的独立精确 Website 动作。
- `rollback` 绑定同一 target/checkpoint，持久回到 mode off；既有 quote
  和不可变 Order snapshot 数量、payload hash 不变，零售路径继续。
- 使用完毕必须销毁 clone，并以 `mig:foundation clone-list` 的 `count=0`
  作为清理证据。

模块版本：`2.5.0`。`TASK-P4C-001..002`、`TASK-MIG-P4C = ACCEPTED`。
ToC/ToB 一期：禁折门禁、多行 quote set、定金 hang 生命周期已落地（`REQ-B2B-0005` unit）。
MIG 聚焦测试 `6/66`、完整模块测试 `29/240`、B2B E2E `2/2`；真实
PostgreSQL full-clone apply/fresh verify/allowlist/rollback/replay 与资源
清理通过，schema drift 和 B2B-local architecture finding 均为 `0`。
独立聚合复验已签署 `GATE-P4C = GO`；下一工程前沿为
`TASK-P4D-001`，`GATE-P4D`、`GATE-P4` 与 `PG-8` 仍为 `NO-GO`。
