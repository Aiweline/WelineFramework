# Plan - e2e framework package

## Outcome

- 让 E2E 对框架运行态更“无感”：
- 自动收集所有模块下的 `test/e2e` 用例继续可用
- Playwright 默认通过统一代理域名/端口进入当前真实运行实例
- 后台、前台、API 地址与认证 helper 统一由封包处理
- 典型错误用例完成修正并可通过定向验证

## Steps

- [x] Clarify scope, affected files, and risks
- [ ] Implement E2E framework runtime info + proxy package
- [ ] Wire Playwright/startup flow to the new package
- [ ] Migrate broken specs away from hardcoded runtime addresses
- [ ] Run validation commands
- [ ] Update result.md and memory if needed

## Verification Targets

- [ ] Node-level syntax / config load
- [ ] E2E collection output
- [ ] Targeted Playwright flows through proxy
