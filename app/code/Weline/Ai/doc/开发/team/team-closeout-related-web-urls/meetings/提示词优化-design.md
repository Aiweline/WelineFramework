# 提示词优化 · design（team-closeout-related-web-urls · 施工轨）

- seat: Team:提示词优化工程师:
- wave: team-closeout-related-web-urls / 施工轨
- date: 2026-09-22
- stance: **同意施工**

## 用户明示新增义务（本波例外说明）

**本波是用户明示要求补强，不是「禁止乱加」意义上的乱加。**

用户原话：「修改MCP团队技能。完成要汇报呀。要汇报涉及到的相关的web的地址。」

与已有权威 **同义补强到团队回报路径**（不新造冲突规矩）：

| 已有权威 | 本波补强落点 |
|----------|--------------|
| `feature_delivery_urls` / `closeout_delivery_reminder`（向用户写「交付地址」） | 回报单字段 `related_web_urls` → 各席 closed 必填 → PM 汇总进「交付地址」 |
| WebUI浏览器验收与交付地址门禁.md | 测试席探活地址写入 `related_web_urls`，与交付地址同源 |

未削弱 `feature_delivery_urls` 语义；未发明第二套 Host/链接格式。

## 重复证据（施工前）

| # | 同义主题 | 处 A（权威已有） | 处 B（缺口） | 判定 |
|---|----------|------------------|--------------|------|
| 1 | 完成须列交付地址 | `feature_delivery_urls` / closeout reminder | 工程团队回报单无字段强制席位上报 URL | **缺口**：PM 收口缺席位侧原料 |
| 2 | 交付地址格式 | WebUI 门禁 + feature_delivery_urls | 骨架/席位镜未点名 related_web_urls | **缺口**：子智能体 closed 可空报 |

无「同义全文第三份拷贝」可压；本波是**用户明示填缺口**，施工为加字段 + HARD 指针，禁止再展开 feature_delivery_urls 全文。

## 施工方案（已冻 · 执行）

1. `工程团队.md` 回报单 + 子智能体骨架：`related_web_urls` 必填（closed）
2. `McpSkillCatalog`：项目经理 / 测试 `prompt_increment` HARD；bundle principle `closeout_related_web_urls`
3. `GuidanceWorkflowCatalog` engineering_team：norm `seat_closed_reports_related_web_urls` + forbidden/required
4. 契约测：`guidance-workflow-contract.php` / `mcp-skills-catalog.php`
5. HardConstraintsCatalog：**不改**（权威已在 feature_delivery_urls；本波只打通团队回报路径）

## 禁丢义 / 禁削弱确认

- 不削弱 feature_delivery_urls Host/Markdown/关 Browser 语义
- 纯逻辑仍允许 N/A / 无
- 不新增与 hard_constraints 冲突的 Host 规则
