---
name: production-http-probe
description: >-
  HARD: production/public storefront HTTP probes must NOT curl the public FQDN
  from the Agent local workstation (shared_ban risk). SSH site-cabinet primary
  Host then curl 127.0.0.1 with Host header; unblock via server:security:unblock.
  Triggers: 生产探活, 线上探活, shared_ban, Forbidden, curl 公网, production probe.
aliases:
  - weline-production-http-probe
  - production_http_probe
  - production-http-probe
---

# production-http-probe · 生产公网 HTTP 探活

## 权威

| 层 | 路径 |
|----|------|
| 硬规则 | `production_http_probe_forbids_agent_local_curl` |
| 指令 | `dev/ai-command/ai/生产HTTP探活.md` |
| 规则正文 | `app/code/Weline/Ai/doc/规则/00-MCP调用与会话.md` §8a |

## 强制

1. **禁止** Agent 本机对生产公网 FQDN `curl`/`wget`/`httpie`。  
2. **必须** Read 站柜 README → SSH 主用 Host → 远端 `127.0.0.1` + `Host:` 探活（`curl --max-time 5`，禁止 10/15/30s）。  
3. Forbidden/`shared_ban` → `server:security:unblock`，禁止更多本机公网 curl。  

## 豁免

本机 `*.test.weline.com` / localhost 验收 curl；Browser WB-OP。

## 验收

- [ ] 未从 Agent 本机对公网生产 Host 发 curl  
- [ ] 探活证据来自 SSH 本机或等价 MCP  
