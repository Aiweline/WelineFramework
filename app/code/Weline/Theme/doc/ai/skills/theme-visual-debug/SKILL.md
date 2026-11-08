---
name: theme-visual-debug
description: >-
  主题视觉故障定位（样式错乱、组件挤成一列、逐词换行、条目嵌套、改了看不见）。
  必须先跑 dev/tools/theme-visual-probe.mjs 取证再改，禁止读 CSS 猜、禁止反复 curl 试探。
  正文见 dev/ai-command/ai/主题开发.md 的「§ 视觉故障定位」。
aliases:
  - 主题样式错乱
  - 样式坏了
  - 逐词换行
  - 组件挤成一列
  - 改了看不见
---

# 主题视觉故障定位

正文在 `dev/ai-command/ai/主题开发.md` 的「§ 视觉故障定位（P0 · 先实测再改，禁盲试）」。本文件只留最短路径。

## 30 秒固定动作（不要自由发挥）

```bash
# 取证（结构 + 计算样式 + 命中规则 + 截图），线上/本地同一命令
node dev/tools/theme-visual-probe.mjs \
  --url='http://<host>/<页面>' --host-map=127.0.0.1:9555 \
  --selector='.<组件根类>' --screenshot=/tmp/probe.png
```

按证据判定：

- `childCount` 不符 / `htmlHead` 里条目互相嵌套 → **DOM 生成坏了**（PROD 会剥离 `<script>` 字符串内的闭合标签）→ 改 `document.createElement` + `textContent`，不要再拼 HTML 字符串。
- 结构正常但子项宽度远小于容器（逐词换行）→ **布局 min-content 塌陷** → `grid-template-columns: repeat(2, minmax(0, 1fr))` + 子项/文本列 `min-inline-size: 0`。
- `matchedRules[].href` 是 hash 文件名 → 主题 CSS 是编译产物，改源后必须重编译/重发布才生效。

改完走完生效链再复验：源 → `theme:update -t <theme> -f` → 真实重启实例（看 worker `lstart` 是否变新）→ `fpc:clear -w <站点code>` → `cache:clear` → CDN purge → 探针复验。

## 禁止

- 只改源文件就宣称修好（`app/design/` 不进 Git，线上靠 scp；CSS 是 hash 化编译产物）。
- 用「命令回显成功」代替探针复验。
- 业务时段反复 `server:stop/start` 试探；先看 `server:status` 与 worker `lstart`。
