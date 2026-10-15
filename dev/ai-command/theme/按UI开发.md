# MCP 指令：按 UI 开发

**触发词（任一即执行）**

- `按UI开发`
- `按 UI 开发`
- `UI驱动开发`
- `UI 驱动开发`
- `对照UI开发`
- `对照 UI 开发`
- `对着设计稿开发`
- `design to code`
- `ui-driven`
- `ui driven development`

技能 / surface：`ui_driven_development`（别名 `weline-ui-driven-dev`）。  
宿主薄镜像：`weline-ui-driven-dev`。

**这就是按 UI 开发**：每开发完一个功能切片，立刻去前端截图，对照参考 UI 看对不对得上、哪里有问题，再调整——循环到对齐后再做下一个功能。

---

## 与「审图」的关系（硬）

| 指令 | 何时用 |
|------|--------|
| **按 UI 开发**（本指令） | 用户明确要按设计稿/参考 UI **边做边对稿**；开发过程中主动截图对稿 |
| **审图**（`dev/ai-command/theme/审图.md`） | 用户**附图/粘贴截图**，或验收阶段 `acceptance_phase_requires_shentu` |

对稿发现的视觉/人性化 fail → **同回合**转入审图联合流水线（线稿 → `prototype` → `frontend-design` → `weline-theme-development`）修到 pass。  
用户附图仍优先走审图硬触发，不因本指令而跳过。

---

## 开工前（硬）

1. **钉参考 UI**：用户给的设计稿 / Figma 导出 / 竞品或目标截图 / 线稿。没有参考稿 → **先问用户要参考**，禁止凭感觉「假装对稿」。
2. **齐读技能**（Web UI）：`weline-theme-development`（`get_skill`）+ `frontend-design` + `prototype` + `local-browser-urls`。缺 UI/原型宿主技能 → 按审图同款提示并自行装入 Agent Store。
3. **切功能切片**：一次只做一个可截图验收的功能面（一个区块 / 一个交互态 / 一个断点视图），禁止一口气做完整页再第一次截图。
4. 既有页上加功能：先遵守审图里的 `feature_add_requires_current_ui_review`（先拍当前页再落位）。

---

## 核心循环（每个功能切片必须跑完）

```text
实现切片 → Browser 禁缓存打开验收 URL → 截图 → Read 截图
    → 对照参考 UI 列差异 → 调整代码
    → 再截图再 Read → 对齐才进入下一切片
```

### 每轮必做

| 步 | 做什么 | 禁止 |
|----|--------|------|
| 1 实现 | 只改本切片相关模板/CSS/部件；走 Token / `w-*` / Taglib | 私造色板；改 `generated/`、`view/tpl` |
| 2 打开 | `local-browser-urls`：探活 → 禁缓存 → 抹自动化标志 → `browser_navigate`（省略 `position`） | 用磁盘缓存验收；`Page.reload` 杀工作台 |
| 3 截图 | `browser_take_screenshot` 盖住本切片改动面 | 只 snapshot / 只 DOM 文本 |
| 4 审图 | **Read 截图文件**（视觉）；对照参考 UI 写差异清单 | 拍了不 Read；用 `getBoundingClientRect` 冒充视觉 PASS |
| 5 判定 | 结构/间距/层次/文案/状态/主 CTA 是否与参考一致；fail 项点名 | 只写「差不多」 |
| 6 调整 | 同回合改码；再截图 Read 直到对齐 | 攒一堆问题下回合再说；甩给用户「帮我看看」 |
| 7 下一刀 | 本切片对齐后再开下一功能切片 | 未对齐就扩大施工面 |

### 对稿维度（最低）

- **结构**：区块顺序、主次、栅格/栏位是否与参考一致  
- **间距与边距**：组内/组间、贴边 gutter  
- **控件语义**：按钮主次、表单态、空态/加载/错误  
- **文案与层次**：标题/副文/CTA 权重  
- **主题契合**：Token / `w-*`（审美服从品牌，不是另发明皮）

细节清单可复用审图 E/F/G；本指令强调 **「相对参考 UI 的差分」**，不是泛泛美观点评。

---

## 汇报模板（每个切片收口）

```markdown
## 按 UI 开发 · 切片
- 切片：…
- 参考 UI：…（路径/附图编号）
- 验收 URL：…

## 对稿
| 项 | 参考 | 实机截图 | 结论 |
|----|------|----------|------|
| … | … | … | 对齐 / 差异：… |

## 本轮已调
1. …

## 切片状态
对齐 | 未对齐（阻塞点：…）
```

整需求结束仍须走既有验收门禁（Browser WB-OP、交付后关 Browser、汇审等）。**交付地址**写完后关闭本回合验收 Browser。

---

## 非目标

- 不替代澄清 / Plan / 工程团队编制 / UC 闭环  
- 不替代用户附图时的「审图」硬触发  
- 无参考 UI 时不假装「按 UI 开发」已执行  
- 不用 curl / CDP 尺寸数字冒充对稿 PASS  
- 不把原型 throwaway hex 粘进生产  
- 不扩大到无关模块（除非对稿暴露同源主题违约）
