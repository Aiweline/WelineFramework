# channel · 主题席合规复审 · mailbox / enterprise

日期：2026-09-22  
from: `Team:主题开发工程师:`  
to: `@项目经理:`  
slug: `mail-channel-default-mailboxes`  
文件：`Mail/view/templates/Backend/Index/enterprise.phtml`  
线稿：`channel/ui-shentu-mailbox-20260922.md`

## work_mode 声明

| 项 | 值 |
|----|-----|
| `work_mode` | **`implement`**（本波：模块自有模板 Token / `w-*` 合规施工） |
| `area` | `backend` |
| 官方 Theme 三态 | **未进入** `default_theme` / `design_theme` / `theme_module_runtime`（未改 `Theme/view/theme`、`app/design`、Theme PHP） |
| 高压线 | **`theme_design_must_not_override_core_runtime_assets` = PASS**（未覆盖同 key `theme.css` / `theme.js`） |

技能：宿主 Read `weline-theme-development` + `dev/ai-command/ai/主题开发.md`；MCP `prepare_project` OK；`get_skill` 本回合 indexing DISABLED，未编造规则。

## 合规清单（G 主题契合）

| 项 | 结论 | 说明 |
|----|------|------|
| 去掉硬编码 `Inter` | **pass** | `font-family: var(--weline-font-family)` |
| `#hex` → Token | **pass**（本文件） | 内联 CSS 仅 `--weline-theme-*` / `--weline-space-*` / `--weline-radius-*` / `--weline-font-*`；无裸 `#hex` |
| `w-backend-page` | **pass** | 根节点 `w-backend-page mail-enterprise` + `w-backend-page__heading` / `__title` |
| `w-card` | **pass** | compose / 列表 / 阅读 / 账号表 / 域名卡均 `w-card` + `__header` / `__body` / `__title` |
| `w-button` / `w-field` / `w-select` | **pass** | 工具条、表单、CTA 使用 |
| `w-badge` tone | **pass** | Stalwart `success` / `warning`；账号/域名状态 `neutral` |
| 主导航 | **pass** | `w-tabs` + `w-tabs__list` + `w-tabs__tab`（与文件夹 soft/outline 按钮分层，不再双排同款 Tab） |
| 空态 | **pass** | `w-empty` + icon；文件夹空态含「写第一封」「用户与账号」下一步 |
| Stalwart 警告可行动 | **pass** | warning badge 旁「去域名与 DNS」`w-button` |
| 写邮件主 CTA | **pass** | 工具条「写邮件」→ `#mail-compose`；summary 去 ▶ 悬浮感（隐藏 details marker） |

**主题契合总评：pass**

## 非本席范围 / 残留（给 PM）

| 项 | 状态 | 建议 |
|----|------|------|
| E 人性化（「用户 #1」文案语义） | 部分 | 显示仍含用户 ID；产品文案优化交原型/前端/业务，非 Token 门禁 |
| `index.phtml` 并行 inbox 私造 `#hex` | **fail 残留** | 线稿 G 提到；本波 **未改** `Backend/Index/index.phtml`（企业页以 enterprise 为准）。建议下一波统一或下线旧 inbox 皮肤 |
| 前端 `mail-client.phtml` 仍含 `Inter` | 残留 | 站外/账户页，非本波 enterprise |
| Browser 实机验收 | 未做 | 需测试席禁缓存打开后台企业邮箱页 |

## 改动路径

- `app/code/Weline/Mail/view/templates/Backend/Index/enterprise.phtml`

## notify

`notify_pm: true`

@项目经理：主题席 mailbox/enterprise Token + `w-*` 合规 **pass**（`work_mode=implement`）。请更新 SESSION/roster；建议安排测试席 Browser 验收，并排期清理 `index.phtml` 旧 inbox `#hex` 平行皮肤。
