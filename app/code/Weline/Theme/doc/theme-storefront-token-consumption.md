# 默认主题店面 Token 消费约定

> 适用范围：`Weline_Theme` 前台默认主题源 CSS / 部件样式 / `components/*.phtml` 内嵌 style。  
> 关联：`theme-css-variables-only.md`（REQ-THEME-0007）。

## 店面只消费这些族

| 类别 | 优先 Token | 禁止 |
|------|------------|------|
| 间距 | `--weline-space-1`…`6`/`8`/`10`/`12`（桥接 `--weline-layout-spacing-*` 可留） | 裸 `0.55rem`、`6px 10px`、`gap: 12px` 等私造刻度 |
| 圆角 | `--weline-theme-radius-sm\|md\|lg\|xl\|full`（叶子在 `variables/_borders.css`：3/4/8/12px） | `border-radius: 6px`；错误 fallback 如 `var(--radius-md, 0.375rem)` |
| 字号 | `--weline-font-size-*` / `--weline-layout-font-size-*` | 裸 `13px`/`15px` 等非刻度 |
| 阴影 | `--weline-shadow-*` / `--weline-theme-shadow-*` / `--focus-ring` | 组件私写 `0 4px 14px rgba(...)`（除非落盘新 Token） |
| 控件高 | `--weline-theme-control-height*` / `--control-height*` | 裸 `min-height: 48px`（应用 `control-height-xl`） |

## 映射原则

1. 需要间距时，**取最近主刻度**，不要发明 `0.35`/`0.55`/`1.1`。
2. `var(--token, fallback)` 的 fallback **必须等于盘内叶子**；缺盘时宁可不写 fallback。
3. 颜色可走色盘定制；**间距/圆角/字号不得因“局部好看”另起一套**。
4. `@media` 断点字面量例外（浏览器不支持 `var()` 在 media 条件里）。

## 分层圆角（约定）

| 场景 | Token |
|------|--------|
| 控件（按钮/输入） | `--weline-theme-radius-md` |
| 卡片/面板 | `--weline-theme-radius-lg` |
| 大营销面/抽屉 | `--weline-theme-radius-xl` |
| 胶囊/徽章 | `--weline-theme-radius-full` |

## 非本约定范围

- Theme Editor / 后台工具 CSS（可另债）
- `colors/_ink.css`、`_amazon.css` 色盘内容本身
