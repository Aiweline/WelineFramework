# wave9-9s3 — 出站丢 chrome 热修（主题）

- date: 2026-09-22 ~20:35+08
- seat: Team:主题开发工程师:
- channel: `framework-unreasonable-audit.md` **msg-113**（re msg-111b / msg-109）
- work_mode: `theme_module_runtime`
- Theme: **2.2.587**
- claim_sla: **false** · 禁自 reload · **禁** 8c\* · 禁回退 skip-fill 快路径（完整壳仍可 skip）

## 铁证复盘

FPC MISS `/`：`data-slot-id` 仅 content + homepage-*；盘上 `shell.phtml`（r270）含 delivery/footer/header-nav-extensions WidgetRenderer。

## 根因

1. **主因**：`<if condition="meta.showHeader">` → `if(($meta['showHeader'] ?? null))`；layout params 省略键 → Partials 顶栏/页脚 **整段不渲染** → 无 chrome 目的地。
2. **次因**：`spliceChromeSlotsFromBake` 在 `regions=[]` 时 continue，heal 无法植入。
3. **信号**：完整性门仅认 `weline-header` 类名；汉服 published 槽 / `hanfu-atelier-chrome` 易假阴。

## 落地

| 项 | 内容 |
|----|------|
| P0 meta | `ensureStorefrontChromeVisibilityDefaults`（缺省 true；显式 false 保留） |
| P0 heal | `graftMissingChromePublishedSlot` |
| chrome | `delivery` ∈ CHROME_SLOTS；门信号对齐 data-slot-id / atelier |
| UT | ForcedZeroFill + SolidifiedShell + ChromeVisibilityContract |

## 自测（本席 · 改码后未 reload）

```bash
curl -sk "https://p05113ef3.test.weline.com:9555/?_t=…" -D - -o /tmp/body.html
# 期望 reload 后：X-WLS-Fpc-Status: MISS 且 data-slot-id 含 footer|delivery|header-nav-extensions 或 weline-header
```

## escalate

@项目经理：**NEED_PM `server:reload -n`** 吃 Theme **2.2.587** → 再 curl 完整性；过则开 9v2。禁本席自 reload。
