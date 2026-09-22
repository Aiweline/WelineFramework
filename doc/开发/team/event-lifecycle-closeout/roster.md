# roster — event-lifecycle-closeout / sitewide pixel

| 席位 | agent_id | 状态 |
|------|----------|------|
| 项目经理 | 5118b63e-7b86-43df-b88a-6295b2876cc2 | R2a closed；等 R2b/R2c |
| 数据分析 | a55f4e78-938c-4214-802e-dc52b8f28671 | **R2a closed · pass**（空壳闸；page_view=50359） |
| 测试（本 PM） | 281e1a4f-b731-43a9-94fb-21c07601b4bc | **R2b CNY+CN Paid · running** |
| 测试（父会话） | 705a98cd-13e3-4377-9fc4-c60f3a656cc4 | closed（主链未 PASS） |
| 支付开发工程师 | afe691fe-c509-4218-aa8f-3e037f1ce1df | **R2b 假卡保驾 · running** |
| 后端（Cart） | 986e4c59-66b9-447f-bc4d-e862ab4a80ef | **R2c cart 可读 · running**（有货 view_cart 复测依赖此） |
| 事件 | — | skip |

## 汇审进度

| 事件 | 判 | 备注 |
|------|----|------|
| view_item / add_to_cart | PASS | 50176 / 50171 |
| view_cart / begin_checkout 空壳 | **闸已关** | >50356 空壳=0；有货合规待 R2c |
| page_view additional | PASS | 50359 |
| checkout_success / payment_success | 待 R2b | 强制 CNY+CN fake_card |

通道：`channel/rework-param-ga4.md` msg-7+
