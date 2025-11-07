## AI 前端动态属性适配协议（PC / iPad / Mobile）

本文件定义一种简单的三端动态属性输入协议，用于将“设计稿的三端尺寸/样式值”快速转换为可复用的响应式 CSS。

### 目标
- 以一行输入同时描述 PC、iPad、移动端三端的某个样式属性值。
- 统一约定解析方式与输出 CSS 的策略，便于自动化或手工实现。

### 基本语法
格式：

```
<selector> , <property> , <pc_value> , <ipad_value> , <mobile_value>
```

说明：
- 逗号可用半角 `,` 或全角 `，`（两者等价）。
- 允许存在任意数量的空格（会被忽略）。
- 若未提供单位，默认单位为 `px`；也可显式写单位（如 `rem`、`%`、`vw` 等）。
- 建议一行仅描述一个属性；同一选择器的多个属性可用多行描述。

示例：

```
jion-header-button，height,87,75,62
```

含义：将 `jion-header-button` 的高度在三端分别适配为：
- PC：87
- iPad：75
- Mobile：62

若未写单位，则默认 `px`。

### 推荐断点
- Mobile：≤ 767px
- iPad（Tablet）：768px – 1024px
- PC（Desktop）：≥ 1025px

可根据具体项目断点策略调整，但需保持团队内一致。

### 输出 CSS 规则（媒体查询方案，通用且直观）
将上述输入转换为媒体查询 CSS：

```css
/* 流式区间 375px(移动) ~ 1280px(PC) 之间线性插值，高度在 62px ~ 87px 之间平滑过渡 */
.jion-header-button {
  height: clamp(62px, calc(62px + (87px - 62px) * ((100vw - 375px) / (1280 - 375))), 87px);
}
```

说明：
- 默认写入 Mobile 值，其它端通过媒体查询覆盖。
- 若输入值包含单位（如 `6rem,5rem,4rem`），按原样输出。

### 输出 CSS 规则（可选：clamp 动态插值方案，适合字体等连贯过渡）
对于需要在三端间平滑过渡的属性（如 `font-size`），可使用 `clamp()` 与流式插值：

```
<selector> , font-size , <pc> , <ipad> , <mobile>
```

推荐生成：

```css
/* 以 375px ~ 1280px 为流式区间，可按项目统一配置 */
.your-selector {
  font-size: clamp(<mobile>, calc(<mobile> + (<pc> - <mobile>) * ((100vw - 375px) / (1280 - 375))), <pc>);
}
```

注意：
- `clamp` 适合“越宽越大”的连续属性（如字体、间距、圆角）。
- 固定像素需求（如严格元素高度、边框宽度）优先使用媒体查询方案。

### 单位与校验
- 未写单位 → 默认 `px`。
- 允许单位：`px`、`rem`、`em`、`%`、`vh`、`vw` 等。
- 若任一值为空或非数值（在需数值的属性上），应拒绝或给出校验错误提示。

### 允许的属性示例（非穷举）
- 尺寸：`width`、`height`、`min-width`、`max-width`、`min-height`、`max-height`
- 字体：`font-size`、`line-height`、`letter-spacing`
- 间距：`margin`、`margin-top`、`margin-bottom`、`padding`、`padding-left` 等
- 布局：`gap`、`column-gap`、`row-gap`
- 圆角/边框：`border-radius`、`border-width`

### 更多示例
1) 字体大小（clamp 建议）

```
.hero-title , font-size , 32px , 28px , 22px
```

输出（示意）：
```css
.hero-title {
  font-size: clamp(22px, calc(22px + (32px - 22px) * ((100vw - 375px) / (1280 - 375))), 32px);
}
```

2) 按钮高度（clamp 动态计算）：

```
jion-header-button , height , 87 , 75 , 62
```

输出：
```css
.jion-header-button {
  height: clamp(62px, calc(62px + (87px - 62px) * ((100vw - 375px) / (1280 - 375))), 87px);
}
```

### 与当前模板的配合
- 该协议用于描述“数据 → CSS”的映射规则，可由脚本、后端渲染或人工在 `.phtml` 中实现。
- 现有 `jion-landing` 模板已使用响应式 CSS 与色系系统；当接到此类输入时，可将解析后的 CSS 注入到对应片段的 `<style>` 中，或落在公共样式文件。

### 约定与扩展
- 逗号/中文逗号均可；建议统一为英文逗号书写。
- 未来可扩展为多属性一行：`selector, prop1:pc|ipad|mobile, prop2:pc|ipad|mobile`，但当前版本按“一行一属性”执行。
- 未来可支持别名映射（如 `btn` → `.jion-header-button`）。

---

若需要，我可以提供一个小型解析器（PHP/JS）读取上述输入并自动生成对应 CSS 片段。

## 选择器 + 三段 CSS 块 输入协议（三端差异混合：动态计算 + 媒体查询）

当输入为“一个选择器 + 三段 CSS”时，表示分别针对 PC / iPad / Mobile 三端给出样式：

语法（建议）：

```
<selector>
---PC---
<css for pc>
---iPad---
<css for ipad>
---Mobile---
<css for mobile>
```

或单行分隔（等价）：

```
<selector> || <css for pc> || <css for ipad> || <css for mobile>
```

解析与生成规则：
- 对于“可动态计算”的属性（如：font-size、line-height、width、height、min/max-width、min/max-height，以及可解析为单一数值长度的属性），且 PC/iPad/Mobile 三端均提供同单位数值时，生成 clamp+calc 的流式插值（默认区间 375px~1280px，可配置）。
- 其它不适合或无法插值的差异（如颜色、display、阴影、复杂复合值等），使用媒体查询覆盖。
- 若某属性仅在部分端出现或单位不一致，则回退媒体查询。

示例：

输入：
```
.jion-header-button || height:87px; padding: 0 28px; background:#2A64F6;
                    || height:75px; padding: 0 24px; background:#2A64F6;
                    || height:62px; padding: 0 20px; background:#2A64F6;
```

输出（混合策略）：
```css
/* 动态可插值属性：height → clamp */
.jion-header-button {
  height: clamp(62px, calc(62px + (87px - 62px) * ((100vw - 375px) / (1280 - 375))), 87px);
}

/* padding 也使用 clamp 动态插值（避免媒体查询误导）*/
.jion-header-button {
  padding: 0 clamp(20px, calc(20px + (28px - 20px) * ((100vw - 375px) / (1280 - 375))), 28px);
}

/* 颜色等非数值插值属性：媒体查询或直接统一值 */
.jion-header-button { background: #2A64F6; }
```

注意：
- 若单位不同（如 px / rem 混用）将回退媒体查询，或在规范中统一单位后再插值。
- 复合属性（如 margin: 10px 20px）只有在所有分量均为可比较数值且单位一致时才建议插值，否则媒体查询更可控。
- 项目可统一配置流式区间（默认 375~1280），以保证团队一致性。

### 媒体查询组织规范（合并写法）
为提升可维护性，建议将相同断点的媒体查询集中书写（同一断点的样式写在一个块中），避免在文件中分散多处的相同断点。

推荐断点块：
```css
/* Mobile ≤ 375（如需对极小屏做特殊处理）*/
@media (max-width: 375px) {
  /* .selector {...} 多个选择器集中在此 */
}

/* iPad（Tablet）：768 ~ 1024 */
@media (min-width: 768px) and (max-width: 1024px) {
  /* .selector {...} 多个选择器集中在此 */
}

/* PC（Desktop）≥ 1025 */
@media (min-width: 1025px) {
  /* .selector {...} 多个选择器集中在此 */
}
```

示例（将分散写法合并）：
分散写法：
```css
.jion-header { display: block; }
@media (min-width: 768px) { .jion-header { display: flex; } }
@media (min-width: 1025px) { .jion-header { display: grid; } }

.hero-title { text-transform: none; }
@media (min-width: 768px) { .hero-title { text-transform: capitalize; } }
@media (min-width: 1025px) { .hero-title { text-transform: uppercase; } }
```

合并写法：
```css
/* Mobile 默认（使用不可插值属性作为示例，避免误导）*/
.jion-header { display: block; }
.hero-title { text-transform: none; }

/* iPad 合并 */
@media (min-width: 768px) and (max-width: 1024px) {
  .jion-header { display: flex; }
  .hero-title { text-transform: capitalize; }
}

/* PC 合并 */
@media (min-width: 1025px) {
  .jion-header { display: grid; }
  .hero-title { text-transform: uppercase; }
}
```


