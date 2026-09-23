# channel — 测试 → PM · WB 补做

from: Team:测试:  
to: @项目经理  
date: 2026-09-23  
re: plan_id=test-reset **WB 补做 → blocked**（UT 结果保留）  
notify_pm: true

@项目经理：本席已交付/上报，请检查并更新 SESSION。

## 结论

| 项 | 状态 |
|----|------|
| 既有 UT（三契约 PHPUnit） | **保留 pass**（见 `channel/测试-closed.md`：11 tests / 235 assertions） |
| WB-OP confirm 取消不提交 | **blocked / 未执行** |
| WB-OP 确认后壳空 | **blocked / 未执行** |
| 交付 URL | **N/A** |

禁止造假：未打开可用验收 Browser，未点「重置为默认」，不宣称 WB pass。

## 本席尝试时间线

1. **开席探活成功**：`curl -skI https://p05113ef3.test.weline.com:9555/` → **200**；`server:status` 见 HTTP Worker #1–#4（PID 66600–66603）Listening。
2. **cursor-ide-browser 不可用**：
   - `browser_tabs` `new` 返回 viewId（如 `80cc53` / `aab7b5` / `c3c7bc`）
   - 随即 `browser_navigate` / `browser_cdp` / `browser_lock` → `Browser view not found` 或 `No browser tab available`
   - `browser_tabs` `list` 恒为空
   - 故无法按门禁做：省略 position 非抢占 navigate、`Network.setCacheDisabled`、抹 `navigator.webdriver`
3. **chrome-devtools MCP**：`Could not connect to Chrome`（`127.0.0.1:9222` fetch failed）
4. **站点再度不可达**（本席未改代码、未清锁）：
   - 再探 `https://p05113ef3.test.weline.com:9555/` / `127.0.0.1:9555` → connect timeout（http_code=000）
   - `server:status` 仅 Master/Session/Memory/Watchdog/Gateway，**无 HTTP Worker**
   - `server:reload` → `No running WLS Worker` / 请先 `server:start`
   - `var/server/locks/lifecycle_default.lock` 反复出现 `purpose=certificate_retirement_replay`（pid 先后 74423→74733，进程已不在）

## 未完成的 WB 步骤（原计划）

Target（探活曾 200 时）：

- Host：`https://p05113ef3.test.weline.com:9555/`
- Admin：`…/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/admin/login`
- Listing（未登录会 302）：`…/zh_Hans_CN/smtp/backend/template/listing`

计划动作：登录 → 模板编辑 → `data-testid="smtp-template-reset"` → confirm 取消不提交 → confirm 确认后壳空 / 预览无库内炭黑朱砂覆盖 → 关闭 Browser。

**实际：未进入编辑页**（Browser MCP 失效 + 站点二次掉 worker）。

## 请求 PM

1. 处理反复的 `certificate_retirement_replay` lifecycle，保证 workers 稳定监听 9555。  
2. 修复/重启本会话 cursor-ide-browser（或指示可用 Browser 通道）后，再派本席重跑 WB-OP。  
3. UT 无需重跑，除非施工有新 diff。

## 交付地址

N/A（本席本回合未完成可点验收页；Browser 未成功打开故无需关闭）
