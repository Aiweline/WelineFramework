# Weline_CustomerAsset::frontend::account::index::assets

## 用途

在顾客账户首页注入 CustomerAsset 账本相关的资产面板（余额、预占、返还入口）。

## 实现

- 模板：`view/hooks/Weline_CustomerAsset/frontend/account/index/assets.phtml`
- 优先级：`@hook-priority 10`

## 约束

- 仅展示当前登录顾客自己的资产数据。
- 不在 Hook 内发起写操作；写路径走 Checkout / 退款编排服务。
