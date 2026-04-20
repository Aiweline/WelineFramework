# AI 建站工作台：Websites 侧域名与本地流程

> 模块：`Weline_Websites`  
> 目标：把工作台中的“本地域名”流程与 WLS 新规则对齐，同时不影响真实域名业务。

## 本次固化后的本地域名策略

- 仅本地开发域名走这套特殊逻辑
- 开发 / 本地 / 测试：`*.weline.test`
- 本地回环式生产入口：`*.weline.localhost`
- 旧的历史本地域名后缀已移除
- 真实购买域名、真实解析、真实证书流程不变

## Websites 侧行为

### 1. 本地建站模拟

- 当域名命中托管本地后缀时，工作台允许跳过真实注册商购买
- 仍保留本地建站成功所需的生命周期数据、状态与交付结果
- 本地推荐域名会跟随当前本地策略生成：
  - 开发态优先 `*.weline.test`
  - 本地回环式生产入口使用 `*.weline.localhost`

### 2. hosts 与回环

- `*.weline.test`
  - 需要通过 WLS 的 hosts 能力补解析
  - Websites 侧调用 hosts 注入时会走统一的 WLS 查询入口
- `*.weline.localhost`
  - 不需要 hosts
  - Websites 侧会直接跳过 hosts 注入

### 3. 共享本地通配证书

- 托管本地域名共享本地通配证书
- 共享证书仅适用于：
  - `*.weline.test`
  - `*.weline.localhost`
- 工作台在本地建站完成时，会把当前实际使用的 wildcard domain 回传给前端与日志

### 4. 与真实域名分流

- 只要不是托管本地域名，就继续走原有真实域名流程：
  - 域名推荐
  - 可用性检查
  - 注册商账户
  - 订单
  - 真实解析与证书

## 工作台输出约定

- 本地模式下返回：
  - `domain`
  - `simulated=true`
  - `wildcard_domain`
  - `https_ok`
- 其中 `wildcard_domain` 只会是：
  - `*.weline.test`
  - `*.weline.localhost`

## 非目标

- 不让真实域名使用 `.test` 或 `.localhost`
- 不再保留任何旧历史本地域名兼容入口
- 不改变真实注册商供应商与支付逻辑

## 关联文档

- [WLS / hosts / SSL 本地策略](../../Server/doc/计划-AI建站-w_query与本机hosts.md)
