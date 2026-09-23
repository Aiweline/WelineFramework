# channel — WO-BUILD-THEME-01 回执

`work_mode`: **`design_theme`**（店面意图等价 `storefront_theme_polish`）+ 同步 **`default_theme`**

日期：2026-09-23  
席位：`Team:主题开发工程师:`  
工单：`WO-BUILD-THEME-01`  
`client_session_id`：`f22ca421-theme-hanfu-build-01`（替换席曾用 `...-02`）  
`notify_pm`: **true**  
状态：**closed**

## 声明（硬）

- `work_mode=design_theme`（+ `default_theme` 壳同步）
- 未拆 chrome；未改 `theme.css` / `theme.js` 同 key；无卸载必装仍在
- **禁止** `.wpc-media { max-height: <有限值> }` → 显式 `max-height: none` + `padding-top` 方图锁
- 包邮 **`$49`** 数字未改
- Wave-3 不回归：矮 Hero → 信任条 → 精选前 4 带价；货架优先

## 本回合改码（已落盘）

| 文件 | 要点 |
|------|------|
| `app/design/Weline/hanfu/frontend/layouts/homepage/default.phtml` | THEME-01：矮 Hero `min(40vh,20rem)` + 水墨侧晕 overlay；信任/精选淡墨底；区头墨印；价签主色；品类墨线；`.wpc-media` **`max-height:none`** |
| `app/design/Weline/hanfu/frontend/assets/css/hanfu-homepage.css` | 信任底边 / 精选卡 body 顶墨线 / 货架 header 墨线；再禁 `.wpc-media` 有限 max-height |
| `app/code/Weline/Theme/view/theme/frontend/layouts/homepage/default.phtml` | default_theme 壳对齐（Hero 40vh、68% 媒体锁、墨线标题/价签） |
| `app/design/Weline/hanfu/frontend/variables/_hanfu-ink.css` | 书卷字距/朱印度量略加强（仅 Token） |

## Wave-3 锁

1. Hero ≤50–60vh（现 `min(40vh,20rem)`）  
2. 槽序：Hero → trust → featured → category → deals…  
3. 精选 1 行×4；无 Hero 内嵌爆款卡；无 `.wpc-media` 数字 max-height  

## Browser / 抽检证据

- Cursor ide-browser：**无法稳定建 tab**（new 后 navigate 失败）→ **N/A 降级**  
- 验收 Host 本回合 curl：**timeout 0 bytes**（本机 `*.test.weline.com:9555` 暂不可达）→ 运行时 DOM **未拿到**；以源码门禁为准  
- 源码门禁：**PASS** — homepage layout 精选 `.wpc-media` 为 `max-height: none`；无 `7.5rem` 压塌规则  

## related_web_urls

- https://p05113ef3.test.weline.com:9555/zh_Hans_CN/

@项目经理：WO-BUILD-THEME-01 **closed**（改码已落盘 + done）。请更新 SESSION / progress；Host 探活 timeout 请另排运维/本地服务检查，非本席阻塞关单条件。
