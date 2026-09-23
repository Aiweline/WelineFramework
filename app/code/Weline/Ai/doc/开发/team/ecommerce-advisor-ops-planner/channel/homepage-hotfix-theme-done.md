# channel — 席 A 回执：`WO-HP-P0-HOTFIX-THEME`

日期：2026-09-23  
席位：`Team:主题开发工程师:` + `Team:部件开发工程师:`（席 A）  
`agent_id`：`78708b02`  
`client_session_id`：`f22ca421-theme-hotfix-broken-home`  
`work_mode`：本席 **stop 源码双写**（不改 design/default 业务码）  
`notify_pm`: **true**

## 结论

**源码由父会话热修；本席 stop 双写，仅验收/清缓存。**  
DoD 双路径禁缓存验收 **PASS**。本席 **未**对本热点业务码做任何写入。

## 1. dirty-load（确认父会话已在磁盘）

| 文件 | 父会话热修痕迹（已在） |
|------|------------------------|
| `app/design/Weline/hanfu/frontend/layouts/homepage/default.phtml` | `max-height: none`；`.hanfu-hero-disclosure`；媒体 `padding-top: 85%`；`.wpc-image` 铺满规则 |
| `app/code/Weline/Theme/view/theme/frontend/layouts/homepage/default.phtml` | 同上 + 注释「方图锁必须 absolute 铺满」 |

**冲突写入**：**无**（本席收到 PM stop 后未改上述文件；此前亦未落盘冲突改动）。

## 2. 本席实际动作（仅辅助）

```bash
php bin/w template:clear   # 已清理 38 个模块模板缓存
php bin/w cache:clear      # WLS 缓存清理完成
php bin/w cache:flush      # 无过期文件可刷
```

未跑破坏性 `theme:upgrade` / `theme:disk:compile` / 发布覆盖。

## 3. 禁缓存 Browser 验收

工具：Chrome DevTools MCP（Cursor ide-browser 本回合无法稳定建 tab；降级注明）。  
每路径：`ignoreCache: true` + `initScript` 抹 `navigator.webdriver` + `?nocache=1`。

| 路径 | 主图铺满 | Hero CTA 完整 / 不与信任条叠 | 无 Illustrative scene |
|------|----------|------------------------------|------------------------|
| [英文首页](https://p05113ef3.test.weline.com:9555/) | PASS：4 卡 `mediaH≈294`、`imgPos=absolute`、`imgFillRatio=1`、`max-height:none`、未塌陷 | PASS：Shop Featured / Shop by Occasion 可见；`heroTrustOverlap=0` | PASS：正文无匹配；DOM 无可见 `.hanfu-hero-disclosure` |
| [中文首页](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/) | PASS：同上 | PASS：浏览精选 / 按场景选；`overlap=0` | PASS：同上 |

## 4. 交付地址（验收面）

- [英文首页](https://p05113ef3.test.weline.com:9555/)
- [中文首页](https://p05113ef3.test.weline.com:9555/zh_Hans_CN/)

验收 Browser 页已关闭（本席打开的 homepage tab）。

## 5. 交接 PM

- 工单 `WO-HP-P0-HOTFIX-THEME`：**done（验收收口）**
- 源码归属：**父会话**
- 席 B（store-music / 悬浮头像挡标题）不在本席 DoD 范围，不代验 PASS/FAIL
