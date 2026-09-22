# 对齐冻结会 — API 席职责与 Query 核共用

- 主持：项目经理
- 参会：架构师、API、查询、安全
- 通道：`channel/api-charter.md`
- 日期：2026-09-22

## 决议（采纳）

1. **可复用 I/O 契约核 = QueryProvider**（经 `w_query` / FrameworkQueryService）。REST 与 BinQuery/query-bin 均为**入口壳**，禁止在壳内重写业务。
2. **仓内已有先例**：`Weline_Websites/Api/Rest/V1/Provisioning.php`、`DomainRegistrar.php` 薄封装 `w_query('websites', …)` + `#[Acl]`。
3. **独立 REST 例外**（可不经 Query）：multipart/流式上传、Webhook 回调、登录换票、纯 `@Document` 对外 SDK 语义、框架 QueryBin/BinQuery 网关壳本身。
4. **API 席**：QueryProvider 实现 + 入口壳（薄 REST / external 标志）+ 权限声明 + compile/help/文档；施工+合规复审。
5. **查询席**：机制选型 + 契约合规复审；不代写 Provider。
6. **后端席**：Service/Model；不写 Rest/QueryProvider。
7. **权限矩阵（硬）**：见下表；任一入口漏门 = 安全否决。

## 权限矩阵

| 入口 | 必须门 |
|------|--------|
| 后台/业务 REST | 类/方法 `#[Acl]`；薄封装只调 `w_query`，不旁路鉴权 |
| 站内 query-bin（Weline.Api） | FrontendQueryGateway 鉴权 + operation `auth`；后台 op 必 `backend_acl`（敏感写优先 `kind=source`） |
| 站外 `/bin/query` | 仅 `external=true`（frontend 另需 `frontend=true`）+ API Key BinQuery scope；写操作 `mode=write` 不得 CDN public |
| CDN 公开读 | `external` + `mode=read` + `cache.cdn` + `visibility=public` 四条件同时满足 |

## 暴露面权限 = 契约硬要件（架构 msg-7 · 2026-09-22 升格）

**写了 QueryProvider ≠ query-bin 可调。** 权限是 Provider 契约一等公民，不是事后补丁。

| 规则 | 硬要求 |
|------|--------|
| `frontend=true` | 必须显式 `auth`（any\|guest\|customer\|backend）；缺则不可 compile/上线 |
| `auth=backend` / `backend=true` | 必须合格 `backend_acl`；敏感写禁止仅 `kind=self` |
| 后台产品编辑类 | `frontend=true` + `backend=true` + `external=false` + `auth=backend` + `mode=write` + `backend_acl.kind=source`（真实 ACL 资源） |
| 冰块调后台 | **唯一真相** = descriptor（auth+backend_acl）经 `FrontendQueryGateway`；REST `#[Acl]` / 业务内校验不可替代主门 |
| 存量策略 | 敏感后台写：立刻盘点封堵（禁 allowlist）；公开前台缺 auth：分阶段 allowlist |

## 否决条件（安全）

- 写操作 `external=true` 且无有效 API Key / scope
- REST 有 Acl，但同一 Query 操作无 `backend_acl`，导致 query-bin 旁路
- 为图省事把敏感读操作标成 CDN public
- 跨模块代写 Rest / 在壳 Controller 重写业务

## 后续

- **已落盘（用户确认 2026-09-22）**：`工程团队.md` API/查询席、`McpSkillCatalog` seat_skill_mirrors、`HardConstraintsCatalog::api_rest_in_owning_module`、`GuidanceWorkflowCatalog::apiSdkDevelopmentSurface`、`AI硬规则索引.md`；Ai `1.2.101`。
