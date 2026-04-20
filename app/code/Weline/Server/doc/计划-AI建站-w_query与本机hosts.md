# AI 建站本地基础设施：WLS / `w_query` / 本机 hosts

> 模块：`Weline_Server`  
> 目标：固化 WLS 的本地域名策略，并把 hosts / SSL / worker 行为统一到同一套规则。

## 本次固化后的规则

- 仅“本地域名”走这套特殊逻辑，真实业务域名逻辑保持不变。
- 开发 / 本地 / 测试环境使用 `*.weline.test`
- 本地回环式生产入口使用 `*.weline.localhost`
- 旧的历史本地域名后缀已删除，不再生成、不再文档化、不再作为兼容入口

## WLS 侧约定

### 1. 主机名生成

- 标准项目主机名格式：
  - 开发态：`p{hash}.weline.test`
  - 生产式本地态：`p{hash}.weline.localhost`
- 真实域名、自定义域名仍按原有配置流程处理，不受本策略影响

### 2. hosts 写入

- `*.weline.test`
  - 需要显式写入本机 hosts
  - `server:start` 与 `server:hosts:add` 会沿用同一套校验逻辑
- `*.weline.localhost`
  - 依赖 `.localhost` 的回环语义
  - 不写 hosts
  - 相关接口会返回 `skipped=true`

### 3. SSL / 通配证书

- 本地托管域名允许复用共享本地通配证书
- 共享通配证书仅针对：
  - `*.weline.test`
  - `*.weline.localhost`
- 证书复用、SNI 回退、worker 磁盘证书扫描，都只认这两类本地后缀
- 真实域名证书申请 / 续期 / 供应商逻辑保持原样

### 4. worker / 路由校验

- WLS worker 允许的标准本地域名仅为：
  - `p[hash].weline.test`
  - `p[hash].weline.localhost`
- 旧格式 `weline-p[hash].local` 继续直接拒绝

## 入口与职责

| 场景 | 入口 | 行为 |
| --- | --- | --- |
| 自动补 hosts | `server:start` | 仅对 `*.weline.test` 写 hosts |
| 手动补 hosts | `php bin/w server:hosts:add <domain>` | 仅接受单标签 `*.weline.test` |
| 查询接口 | `w_query('server', 'hostsAdd', ...)` | `.weline.localhost` 返回跳过 |
| 本地通配证书 | `ensureLocalWelineWildcardCertificate` | 仅允许托管本地通配域名 |

## 推荐用法

### 开发 / E2E

```bash
php bin/w server:start
php bin/w server:hosts:add p11005ce4.weline.test
curl -k https://p11005ce4.weline.test:8443/ -I
```

### 本地回环式生产入口

```bash
php bin/w server:start
curl -k https://p11005ce4.weline.localhost:8443/ -I
```

## 非目标

- 不修改真实域名购买、解析、证书供应商逻辑
- 不把 `.test` / `.localhost` 规则扩散到真实线上域名
- 不保留任何旧历史本地域名兼容分支

## 关联文档

- [Websites 侧 AI 建站工作台计划](../../Websites/doc/计划-AI建站工作台-Websites侧.md)
- [WLS 端口冲突修复记录](./WLS-PORT-CONFLICT-FIX.md)
