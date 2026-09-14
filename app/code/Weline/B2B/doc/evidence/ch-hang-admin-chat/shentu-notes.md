# 审图 — 定金挂单商家沟通挂载

- 分类：web_ui / ui_shot（后台定金挂单表）
- 结论：每行挂单下可见「订单沟通」商家手风琴；未读角标挂在沟通按钮（如「订单沟通 1」）。
- 订单侧：部件 `b2b-backend-order-chat` → 槽 `backend-order-b2b-chat`（default_injections），非 after Hook 直出。
- Browser：Network.setCacheDisabled；URL hang-orders；截图 page-2026-09-12T07-08-01-546Z.png
