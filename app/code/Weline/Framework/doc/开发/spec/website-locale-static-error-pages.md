---
status: implemented
work_kind: feature
feature_slug: website-locale-static-error-pages
module: Weline_Framework
updated: 2026-09-15
---

# website×locale 静态错误页 + Fiber 并发发布

## 澄清记录

| 问题 | 答案 | 来源 |
|------|------|------|
| 主键 | website **code**（sanitize） | 计划锁定 |
| 维度 | website×locale（不含 store/channel） | 计划锁定 |
| 映射键 | `host` 或 `host\|subPath` | DetectWebsite 对齐 |
| 并发 | Fiber 协作默认 4；不宣称多核 | 缺陷审查 |
| 热路径 | 零库 `_host_map.php` | 计划锁定 |

## 用户故事

作为多站运营者，我希望维护页与前台 404 静态快照按网站×语言落盘，热路径按 Host(+path) 选对站点文件，以免多站串品牌/文案。

## EARS

1. WHEN CLI/upgrade 发布静态错误页，系统 SHALL 按启用 Website×该站 locale 策略落盘 `{websiteCode}/{lang}.html`（维护页另写 `.json`）。
2. WHEN 站已声明语言列表，系统 SHALL 仅发布该列表（与站默认语）；IF 无声明，系统 SHALL 使用「站默认语 + en_US + zh_Hans_CN」最小集，禁止无脑全语×全站。
3. WHEN 发布完成，系统 SHALL 一次原子写入 `_host_map.php`（键为 `strtolower(host)` 或 `strtolower(host)|{subPath}`）；失败任务不得进入 map。
4. WHEN 热路径加载维护页/404，系统 SHALL 零库：Host+path 最长前缀匹配 map → `{code}/{lang}` → `default/{lang}` → 扁平 `{lang}.html`。
5. WHEN Fiber 并发发布，系统 SHALL 仅用 Fiber 内 Context 覆盖语言/Scope；禁止任务内 `WelineEnv::restore` / 写全局 `$_SERVER`；进度仅主线程打印。
6. IF website code 无法通过 `[a-zA-Z0-9_-]+` sanitize，系统 SHALL 跳过该站并告警。
7. WHEN 404 `publishOne`，系统 SHALL 按目标 Website 的 ScopeIdentity 解析主题，并在写盘前后 `FiberTaskRunner::yield()`（协作切换，非多核）。

## 用例 UC1 同 Host 不同 path

1. 发布：`shop.example.com` → `shop`；`shop.example.com|/store-a` → `shop_a`。
2. 请求 Host=`shop.example.com` Path=`/store-a/...` → 读 `shop_a/{lang}.html`。
3. 请求同 Host Path=`/` → 读 `shop/{lang}.html`。

## 用例 UC2 回退链

1. 缺 `{code}/{lang}` 时回退 `default/{lang}`，再回退扁平 `{lang}.html`。
