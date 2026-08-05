# 万能商城后台管理菜单与 ACL 设计

- 日期：2026-07-30
- 状态：APPROVED_FOR_PLANNING
- 原始目标：为《Weline 通用商城内核实施计划 R4.2》内全部管理能力增加真实后台菜单入口
- 选定方案：各业务模块自主管理菜单、后台页面和 ACL

## 1. 已确认需求

1. 所有计划内功能均提供真实后台管理入口，不以独立说明页面、API 文档或通用仪表盘代替。
2. 菜单入口由所属业务模块声明，统一挂载到现有
   `Weline_Backend::business_operations` 及其商城子分组。
3. 统一使用框架现有 ACL，不增加二次认证或第二套权限系统。
4. 无权限时菜单不展示；手工输入 URL 时 Controller 仍必须拒绝访问。
5. 内部能力，包括迁移、Queue、Outbox、Webhook、索引、对账和修复操作，也提供后台入口。
6. 每个菜单必须指向真实、可渲染、非 404 的后台页面。
7. 缺少页面的模块必须补齐模块自有 Controller、模板和必要的只读/操作接口，而不是只增加 `menu.xml`。
8. 补充 ACL 技能说明，覆盖权限资源、ACL 标签、菜单可见性和 Controller 权限的一致性规则。

## 2. 当前缺口

已存在部分后台入口：

- `Weline_Order`：订单、支付、发货、退款、发票和状态；
- `Weline_Payment`：支付方式、交易记录；
- `Weline_Shipping`：地址、配送系统；
- `Weline_Checkout`：订单入口；
- `Weline_Customer`：客户；
- `Weline_Queue`：队列；
- `Weline_SystemConfig`：统一配置中心。

现有实现仍有三类问题：

1. `Product、Inventory、Tax、Search、Vendor、Subscription、B2B、
   CustomerAsset、Cart` 等计划核心模块没有对应 `etc/backend/menu.xml`
   或后台 Controller。
2. 既有菜单存在重复入口、详情/创建动作直接作为导航入口、通配路由和父级结构不一致。
3. ACL 已支持标签与角色标签授权，但当前专项技能没有说明标签语法和菜单/路由一致性。

## 3. 目标菜单结构

复用现有一级菜单 `业务运营`，在其下建立或整理以下模块化分组：

### 3.1 商城与渠道

- Website
- Store
- Channel
- Scope 与商城配置
- 数据迁移中心

### 3.2 商品中心

- 商品
- Offer 与 SKU Registry
- 分类
- 商品媒体
- Store 商品复制
- 商品分片与投影状态

### 3.3 库存与仓储

- 库存总览
- 库存调整
- 仓库
- Store 与仓库授权
- 预占与租约
- 库存流水
- 多仓迁移

### 3.4 购物车、结账与订单

- 购物车
- 结账会话
- 订单
- 订单状态
- 发货
- 退款
- 发票
- 异常订单

### 3.5 配送与履约

- 发货/收货地址
- 配送区域
- 承运商
- 运费模板
- 免邮规则
- 配送服务
- 物流跟踪
- 履约任务

### 3.6 支付与财务

- 支付方式
- 支付交易
- Webhook Inbox
- 支付 Effect/Outbox
- 支付对账
- 退款对账
- 未知态与紧急事项

### 3.7 税务与搜索

- 税种、税类和税务规则
- 税务引擎状态
- 税务影子比对与 LKG
- 搜索配置
- 索引 Generation
- 增量任务
- 降级状态
- 搜索迁移与切流

### 3.8 Vendor、Subscription、B2B 与 CustomerAsset

- Vendor、授权、商品绑定、分账、结算和冲正
- Subscription、周期、续费任务、失败尝试和迁移
- B2B 客户组、价目表、报价、审批、价格快照和迁移
- CustomerAsset 资产、台账、结算、归还、异常和迁移

### 3.9 运行与审计

- 商城迁移任务
- Queue 与 Consumer
- Inbox/Outbox
- 后台作业与重试
- 运行健康
- 告警
- 审计记录

## 4. 模块所有权

每个业务模块只声明和实现自己的菜单及页面：

| 模块 | 所有入口 |
|---|---|
| `Weline_Product` | 商品、分类、媒体、复制、SKU/Offer、分片状态 |
| `Weline_Inventory` | 库存、仓库、预占、租约、流水、多仓迁移 |
| `Weline_Cart` | 购物车与异常购物车 |
| `Weline_Checkout` | 结账会话和结账诊断 |
| `Weline_Order` | 订单、状态、退款、发票、发货、异常订单 |
| `Weline_Shipping` | 地址、区域、承运商、运费和履约 |
| `Weline_Payment` | 支付、Webhook、Effect、退款和对账 |
| `Weline_Tax` | 税务配置、引擎、影子比对和迁移 |
| `Weline_Search` | 搜索、索引、增量、降级和迁移 |
| `Weline_Vendor` | Vendor 身份、授权、分账、结算和迁移 |
| `Weline_Subscription` | 订阅、续费、调度、失败和迁移 |
| `Weline_B2B` | 客户组、价目表、报价、快照和迁移 |
| `Weline_CustomerAsset` | 客户资产、结算、归还和迁移 |
| `Weline_Websites` / Website 所有者 | Website、Store、Channel |
| `Weline_Queue` | Queue、Consumer、重试和任务状态 |
| `Weline_Backend` | 只拥有公共父菜单，不承载业务页面 |

跨模块聚合页面只能调用已发布接口或 QueryProvider，不读取其他模块私有 Model。

## 5. ACL 与标签设计

### 5.1 唯一权限标识

菜单 `source` 与 Controller `#[Acl(...)]` 使用同一资源语义。资源标识采用框架现有格式：

`Vendor_Module::tag1:tag2:code`

其中最后一段是资源 code，前面的段是 ACL 标签。示例结构：

- `Weline_Product::commerce:catalog:product:list`
- `Weline_Inventory::commerce:inventory:warehouse:list`
- `Weline_Payment::commerce:payment:webhook:list`
- `Weline_Search::commerce:operations:migration:execute`

实施前必须通过 `SourceIdParser` 和现有 ACL 收集流程验证每个标识，不允许自行解析。

### 5.2 可见性与访问

1. 菜单注册进入 ACL 菜单资源表。
2. 当前角色没有精确资源权限或匹配的标签授权时，菜单被过滤。
3. Controller 类与每个可调用动作使用 `#[Acl]`。
4. URL 直访仍经过路由 ACL；菜单隐藏不能替代 Controller 保护。
5. 所有后台 QueryProvider/操作资源使用精确 `source_id`，默认拒绝。
6. 父菜单用于拓扑和授权祖先展开，不得替代叶子动作权限。

### 5.3 操作约束

按用户决定，不拆分额外“查看/执行”权限体系，也不增加二次认证。每个页面和动作仍拥有独立框架 ACL 资源；角色可以通过精确资源或标签路径授权。

危险写操作继续遵守框架原有安全合同：

- 仅 POST；
- CSRF 与 Origin 校验；
- 明确确认界面；
- 当前 Scope 校验；
- 幂等键、事务和审计；
- 失败时不产生部分业务事实。

这些是请求安全要求，不是第二套权限系统。

## 6. 页面合同

每个新增菜单入口必须满足：

1. 列表入口只指向 `index/list/dashboard` 等可直接打开的页面。
2. `create/view/edit/execute` 作为列表或详情页内动作，不作为需要参数的裸菜单入口。
3. 页面显示当前 Website/Store/Channel Scope。
4. 空数据展示明确空状态，不显示异常或 404。
5. 跨模块读取使用公开接口或 `Weline.Api.resource()/graph()/stream()`。
6. 写操作展示真实结果和错误，不使用模拟成功或静默降级。
7. 用户可见文字进入模块 i18n。

## 7. 迁移和内部任务页面

每个迁移/内部任务都提供真实入口，页面至少显示：

- 当前 mode 与 allowlist；
- preflight/verify/rollback 状态；
- checkpoint、manifest、watermark 或 generation；
- 最近任务、失败原因和审计记录；
- 可执行动作及其框架 ACL。

执行动作只调用现有 owning service/command contract，不通过 Shell 拼接命令，不把测试 harness 暴露到生产页面。

## 8. 验收设计

每个模块必须同时通过：

1. `menu.xml` 可解析且 source 唯一；
2. 每个菜单 action 能解析到真实 Controller；
3. 菜单 source、Controller `#[Acl]` 和 ACL 标签一致；
4. 有权限角色能看到菜单并打开页面；
5. 无权限角色看不到菜单；
6. 无权限角色直访 URL 得到拒绝；
7. 页面不是 404、通用占位页或 API 文档替代物；
8. Browser 控制台无新增 error/warn；
9. 写动作使用 POST/CSRF 并调用真实业务服务；
10. PostgreSQL 场景用于验证持久化和 Scope 隔离。

建立全量追踪矩阵：

`计划功能 → 模块 → 菜单 source → ACL 标签 → Controller/动作 → 页面 → TEST`

只有矩阵中每一行都有真实 Browser 与权限证据，才可宣告后台管理面完成。

## 9. 技能文档变更

更新：

`dev/ai/skills/安全权限工程师-ACL与后台安全/SKILL.md`

增加：

- ACL source 标签语法；
- 标签路径授权与精确资源授权的关系；
- menu source 与 Controller `#[Acl]` 一致性；
- “无权限不展示”和“URL 直访拒绝”双重验收；
- 父菜单、叶子菜单和动作资源的区别；
- 后台 QueryProvider/任务/操作资源的精确 source 约束；
- ACL 标签管理页面及标签元数据只影响展示，不替代授权判断。

同步更新相应技能索引或参考文档，但不复制完整 ACL 规则到多个位置。

## 10. 明确不做

- 不创建独立说明站点或第二套商城后台。
- 不把 API 文档、测试页或通用仪表盘当作业务管理页面。
- 不修改生成目录或 `view/tpl`。
- 不新增第二套权限、二次认证或自定义角色系统。
- 不执行生产迁移、真实支付、部署、提交或推送。

## 11. 完成定义

当且仅当以下条件全部成立，任务完成：

- 原计划全部管理功能都有真实后台入口；
- 所有入口均由 owning module 实现；
- 菜单、路由、Controller 和 ACL 标签一致；
- 有权限可见可访问，无权限不可见且直访拒绝；
- 所有页面通过真实 WLS Browser 验收；
- 相关持久化操作通过 PostgreSQL 验证；
- ACL 技能已补齐标签和菜单权限说明；
- 全量追踪矩阵无缺项。
