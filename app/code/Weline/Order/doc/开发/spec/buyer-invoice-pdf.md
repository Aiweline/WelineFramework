# 买家订单发票 PDF

- slug: buyer-invoice-pdf
- module: Weline_Order
- work_kind: feature
- plan_complexity: simple
- plan_skip_rationale: 单模块复用统一配置与既有一单一票记录，不新建扩展点，店面只在已有订单入口加下载。
- status: ready-for-plan

## 澄清

用户要的是：后台已有发票入口，但没有给买家的 PDF。能力放在订单模块。每个订单下载自己的那张发票。必须先在订单配置里开启，开启处直接填写公司信息。

## 用户故事

作为已登录买家，我希望在自己的订单上下载这张订单已开具的发票 PDF，以便留存报销凭证。

## EARS

- WHEN 订单配置未开启买家发票下载 OR 开票公司名称为空，THE 订单模块 SHALL 不展示下载入口，直接请求下载 SHALL 拒绝。
- WHEN 配置已开启且公司名称已填，AND 当前登录顾客拥有该订单，AND 该订单已有状态为已开具的发票，THE 订单模块 SHALL 返回仅包含这张发票的 PDF。
- IF 请求者不是该订单的顾客，THE 订单模块 SHALL 拒绝下载。

## 用例

| id | 路径 | acceptance |
|----|------|------------|
| UC-1 | 配置关闭时账户订单无下载入口 | ACC-1 |
| UC-2 | 配置开启且公司名称已填、订单已开票时，本人可下载 PDF | ACC-2 |
| UC-3 | 他人订单或未开票订单不能下到 PDF | ACC-3 |

## 架构

- mechanism: SystemConfig Extends 字段（既有配置机制）+ 订单模块把既有 `OrderInvoice` 渲染成 PDF 文件流
- owning_module: Weline_Order
- reuse: `InvoiceService` 一单一票、账户订单钩子、`ResponseTerminateException` 文件下载
- invent: 无新扩展点。仅订单模块内的设置读取、PDF 渲染与前台下载动作
- not_to_do:
  - 不另建配置表或独立设置页
  - 不因下载再插一张发票
  - 不在支付模块生成买家 PDF
  - 不在关闭或公司名称为空时仍提供下载
- req_map: 开启与公司信息 → SystemConfig 模板；按订单下载 → 前台 `Invoice::download`；按钮 → 账户订单钩子

## 审美与线稿

- work_mode: theme_module_runtime
- area: frontend 账户订单、backend 发票页入口
- 地面/抬起、主墨/次墨、强调、间距、字号：沿用账户订单里「查看详情」已有链接，不新写色板。后台入口沿用 `w-button`。
- 下载不是主操作，「继续支付」仍是唯一实心强调。

## 验收

- ACC-1 type=unit：关闭或公司名称为空时 `isOffered()` 为假
- ACC-2 type=unit：PDF 以 `%PDF` 开头且内含 JPEG 图；文案行含发票号与公司名称
- ACC-3 type=unit：顾客与订单主人不一致时拒绝
- ACC-4 type=browser：配置页能看到开关和公司字段；账户订单在开启且已开票时出现下载（环境不具备则记未完成）
