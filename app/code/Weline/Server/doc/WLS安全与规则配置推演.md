# WLS 安全与规则配置推演

## 一、架构与防护位置

- **防护层**：攻击检测与封禁在 **Dispatcher** 完成（`AttackDetector`），Worker 不参与检测。
- **流量路径**：`客户端 → Dispatcher → Worker` 时，所有请求都会经过 `detect()`；**直连 Worker**（未经 Dispatcher）时，**无 WLS 层防护**。
- **规则持久化**：`var/server/security-rules.json`，后台保存后写入；Dispatcher 通过 `security-rules-update.flag` 每 5 秒检查并重载。

## 二、默认阻止规则（默认即启用）

| 规则 | 默认行为 | 说明 |
|------|----------|------|
| rate_limit | 启用，200 次/60 秒，超则封禁 300 秒 | 全局限流 |
| path_rate_limits | 启用，/api/framework/query 等路径限流 | 路径级限流 |
| path_scan | 启用，60 秒内 50 个不同路径则封禁 600 秒 | 路径扫描 |
| malicious_patterns | 启用，命中则封禁 3600 秒 | SQL/XSS/路径遍历/命令注入/PHP 伪协议 |
| bad_user_agents | 启用，空 UA、sqlmap、nikto 等封禁 300 秒 | 恶意 UA |
| protected_paths | 启用，/.git/、/.env、/wp-admin 等封禁 1800 秒 | 敏感路径 |
| ssl_handshake_failure | 启用，60 秒内 30 次快速关闭封禁 60 秒 | SSL 握手失败 |
| slowloris | 配置存在，**detect() 未接入** | 慢速攻击（待实现） |

默认即为「检测到则阻止」；封禁时长、窗口、阈值等均可后台调整。

## 三、注入类防护能力

- **恶意特征**（`malicious_patterns`）对 **URI + 请求体** 做正则匹配，覆盖：
  - SQL 注入：`union select`、`or 1=1`、`'--` 等
  - XSS：`<script>`、`javascript:`、`on*=`
  - 路径遍历：`../`、`..\`
  - 命令注入：`;`、`|`、`` ` ``、`$(`、`> /`
  - PHP 伪协议：`php://`、`data://`、`expect://`
- **局限**：
  - 仅检查 URI + body，**未检查 Header**（Cookie、Referer、自定义头等）。若业务把 Header 拼进 SQL/命令，可能被绕过。
  - 正则存在误报可能（如 query 中含 `;`、`|`），可据业务收紧或放宽。
  - 无专门 LDAP/NoSQL/模板注入规则，可后续在 `malicious_patterns` 中增补。

结论：**常见注入（SQL/XSS/命令/路径/伪协议）在经 Dispatcher 的流量上可被默认规则拦截**；Header 注入与更小众注入需规则扩展或应用层防护。

## 四、后台可管理性

- **安全规则页**：后台「服务器监控」→「安全规则」，可视化配置各规则开关、窗口、阈值、封禁时长、路径/正则/白名单等。
- **保存逻辑**：提交后调用 `AttackDetector::updateRules()`，合并默认规则并写入 `security-rules.json` 与更新标记；Dispatcher 在下次规则检查（约 5 秒内）重载。
- **规则来源**：首次加载从文件读；无文件时用代码内 `defaultRules`。保存时写入**合并后的完整规则**，避免缺省项丢失。

## 五、已发现缺陷与修复

1. **updateRules 持久化不完整**  
   - 问题：此前将前端传入的 `$rules` 写入文件，若前端漏传某段，重载后该段会回退默认且与当前内存不一致。  
   - 修复：改为写入合并结果 `$this->rules`，保证文件与当前内存一致。

2. **slowloris 未接入 detect()**  
   - 问题：默认规则中有 slowloris 配置，但 `detect()` 流程中未调用对应检测。  
   - 处理：在默认规则处增加注释说明「detect() 中尚未接入，需 Dispatcher 层按连接状态统计后调用」，便于后续实现。

## 六、剩余风险与建议

| 风险 | 建议 |
|------|------|
| 直连 Worker 无防护 | 生产环境务必前置 Dispatcher；或未来在 Worker 增加可选轻量检测（如仅恶意特征+敏感路径）。 |
| 恶意特征不扫 Header | 可考虑将关键 Header（如 Cookie、Referer）拼入 `checkMaliciousPatterns` 的输入，或单独做 Header 特征检测。 |
| 多 Dispatcher 封禁不共享 | 封禁为进程内内存，多实例时各进程独立封禁；可接受，可在文档中说明。 |
| 命令注入正则误报 | 根据业务 URL/参数形态收紧或放宽；必要时对特定路径关闭或放宽该规则。 |

## 七、结论

- **是否按需求完成**：默认阻止规则已配置且启用，后台可对各项规则进行管理与持久化；常见注入类攻击在「经 Dispatcher」的流量上可由默认规则拦截。
- **缺陷**：已修复「规则持久化写不全」问题，并标明 slowloris 未接入；直连 Worker 无防护、未扫 Header、多实例封禁不共享等已列为已知限制与改进建议。
