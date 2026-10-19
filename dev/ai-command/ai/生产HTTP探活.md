# MCP 指令：生产公网 HTTP 探活

**触发（任一）**

- `生产探活` / `线上探活` / `公网探活`
- `shared_ban` / 浏览器 `Forbidden` 且对象是公网站点
- `本机 curl 生产` / `curl 公网` / `production http probe`

技能：`production_http_probe`（别名 `weline-production-http-probe`）。  
硬规则：`production_http_probe_forbids_agent_local_curl`。

---

## 硬门槛

1. **禁止**从 Cursor / Agent **本机**对生产或 CDN 前公网 FQDN 执行 `curl` / `wget` / `httpie` / 裸 `fetch` 做连通性、状态码或抽 HTML。  
2. **必须**先 Read `websites/<site>/README.md`，用柜内 **主用 SSH Host**（或等价 SSH MCP profile）登录目标机。  
3. 在远端对本机 loopback 探活，例如：  
   `curl -sI --max-time 5 -H 'Host: www.example.com' http://127.0.0.1/path`  
   （HTTPS 时加 `-k` 与正确 SNI/`--resolve` 按机房约定。）  
4. 命中 `shared_ban` / 纯文字 Forbidden：在目标机执行  
   `php bin/w server:security:unblock --ip=<客户端IP>` 或 `--clear-all`（按运维判断）——**禁止**再用本机公网 curl「试到通」。  

## 豁免

- 本机验收 Host：`*.test.weline.com`、项目 `127.0.0.1`/`localhost` 端口——仍服从 `browser_operator_fail_closed_release` 的打开前 curl。  
- 宿主 Browser WB-OP（真实浏览器 UA）不是本条封禁路径。

## 与相邻规则

| 规则 | 关系 |
|------|------|
| `runtime_status_query_local_first` | 未明示线上时默认查本机；本条只管「已授权生产后怎么探 HTTP」 |
| SSH 主机映射 / 站柜 README | 决定连哪台，不授权本机公网 curl |

权威正文：`app/code/Weline/Ai/doc/规则/00-MCP调用与会话.md` §8a。
