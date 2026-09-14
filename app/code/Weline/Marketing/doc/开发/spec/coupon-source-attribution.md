# 规格：优惠券来源标记

## 分类

- work_kind: feature
- FE/BE: BE 字段 + 列表/表单展示
- ui_skill_decision: participate（列表缺来源列、折扣值未标基准货币）

## EARS

- When 外部模块经 `RandomCouponCampaignProvider::issueRandomCoupon` 发券，系统 shall 将 `source_module` / `source_type` / `source_id` / `source_key` 写入券行。
- When 运营在后台手工保存优惠券，系统 shall 标记来源为 `manual`（模块 `Weline_Marketing`）。
- While 列表展示优惠券，系统 shall 显示可读来源文案（如「维护等待礼金」），不得只暴露空列。
- When 历史券缺少 `source_*`，系统 shall 能从绑定规则的 external_managed 动作元数据回填。

## 用例

### UC1 维护等待礼金发券

1. Maintenance 兑礼调用 `issueRandomCoupon`。
2. 券行 `source_type=maintenance_wait_gift`，列表显示「维护等待礼金」。

### UC2 后台手工建券

1. 运营保存券。
2. 来源为「后台手工」。

## 非目标

- 不在列表做复杂筛选器（可后续加）。
- 不改结账校验语义。
