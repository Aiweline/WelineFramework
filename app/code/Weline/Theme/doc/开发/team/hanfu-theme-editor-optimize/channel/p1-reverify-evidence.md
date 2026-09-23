# channel — HF-ED-P1-REVERIFY 证据

日期：2026-09-23T13:14+08:00  
角色：`Team:测试:`  
轮次：**rework round · attempt-2**（原型∥UI 已报 pass 后正式复测）  
`notify_pm: true`  
**@项目经理：本席已交付/上报，请检查并更新 SESSION**

## result

**`result=blocked`**（入口二次探活仍不可连；**未能完成** UC-P1-01～04 实机度量；未发布、未 `theme:active`；**未** `server:start/stop`）

对照：
- 返工前（12:48）：`result=fail`（四项 UC FAIL）
- attempt-1（13:02）：入口 CONNECTION_REFUSED → blocked
- **attempt-2（本轮）**：曾短暂绿 → 度量未收口 → 再红 → 等 10s 重试仍红 → blocked

## 环境（本轮）

| 项 | 值 |
|----|-----|
| Host | `https://p05113ef3.test.weline.com:29843` |
| 探活方式 | `curl --resolve p05113ef3.test.weline.com:29843:127.0.0.1`（**未**裸 `127.0.0.1`） |
| 开测时前台 | `/` → **200**（约 13:05） |
| admin 登录 | admin/admin + `form_key` POST → 仪表盘 200；编辑器壳 curl **200**（`title=Weline 管理后台`） |
| 禁缓存 / 抹 webdriver | CDP `Network.setCacheDisabled` + `Page.addScriptToEvaluateOnNewDocument`（已配置） |
| Browser | Chrome CDP `:9333`（`--host-resolver-rules MAP Host→127.0.0.1`）；IDE Browser tab **不可用** |
| 收口时前台 | `/` → **000 / connect timeout**（等 **10s** 后再试 **仍 000**） |
| `:29843` | `lsof` 见 `weline-wls-master` **LISTEN**，但 TCP **无法建立**（`nc` 失败 / curl connect timeout） |
| WLS | **禁止**本席启停；入口红只上报 |

编辑器草稿 URL：  
`https://p05113ef3.test.weline.com:29843/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/theme/backend/theme-editor?theme_id=3&page_type=homepage&layout_option=default&editor_area=frontend&preview_area=frontend&status=draft&interaction_mode=edit`

## UC 度量（rework round · attempt-2）

| UC | verdict | 证据 |
|----|---------|------|
| UC-P1-01 | **blocked** | 未取得可信 `#previewFrame` fold 度量 |
| UC-P1-02 | **blocked** | 同上 |
| UC-P1-03 | **blocked** | 同上 |
| UC-P1-04 | **blocked** | 同上 |
| products 弱抽检 | **blocked** | 同上 |

### 度量尝试纪要（非裁决）

1. **13:07–13:08** 编辑器壳曾打开（`shell_title=Weline 管理后台`，`iframe_src` 指向草稿预览），但 `#previewFrame` 内 **`total_cards=0` / `body_sample=""`**（空预览），pass 全 false —— **不作 fail 裁决**（疑加载未完成 / 运行态已开始不稳）。
2. **13:10–13:13** 复跑时长时间 `#previewFrame` 缺失（轮询 `n=-1`），随后 `Page.navigate` **timeout**。
3. **13:14** Host 再探 → connect timeout；等 10s → 仍 timeout。LISTEN 进程在、连接不进。

原始 JSON（空预览那次）：`/tmp/hf-ed-p1-reverify-result.json`（ts=`2026-09-23T05:08:15.205Z`）——**仅作尝试记录，非正式 UC 结论**。

## 禁止项确认

- **未** `theme:active` / 发布
- **未** `server:start` / `server:stop` / 杀 WLS / 清锁

## 请 PM

1. 恢复 pure WLS 入口真正可连（LISTEN≠可服务）。  
2. 绿灯后重派本席 **rework round 实机复测**（禁缓存 + 抹 webdriver；仍禁发布激活）。  
3. 原型∥UI pass **不替代**本席 UC 实机度量。

---

## 归档：返工前 fail 摘要（2026-09-23T12:48）

| UC | 当时 |
|----|------|
| UC-P1-01 | FAIL：fold 带价卡=0；精选 top≈1123 |
| UC-P1-02 | FAIL：槽序 OK；deals_top_vh≈3.65 |
| UC-P1-03 | FAIL：`columns-4` 实渲 2 列（344+344 / 700） |
| UC-P1-04 | FAIL：实心主 CTA=0 |
| products | 壳不崩弱 PASS |
