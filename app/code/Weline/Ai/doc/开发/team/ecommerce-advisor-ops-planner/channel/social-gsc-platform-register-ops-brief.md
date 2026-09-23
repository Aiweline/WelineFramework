# channel — 社媒 / GSC 分发渠道注册运营 brief（电商顾问）

日期：2026-09-22  
角色：`Team:电商顾问:` / 运营策划（**禁写码**）  
品牌：长安汉服 / Chang'an Hanfu  
统一 handle 意向：`changanhanfu`  
后台对照：SEO「Google 账户」→ 分发渠道主页登记（`GoogleSearchConsoleAdapter` 渠道字段）  
检索日期（联网）：**2026-09-22**（公开注册入口与 GSC 平台属性范围；**本席未替用户开号**）

---

## 1. 目标与非目标

### 目标（运营要做成什么）

1. 为品牌在 **YouTube / X / Instagram / TikTok / LinkedIn** 各建立（或确认已有）**官方可访问主页**。  
2. 优先占用统一 handle **`changanhanfu`**；展示名统一为 **长安汉服** / **Chang'an Hanfu**。  
3. 在 **Google Search Console** 对官方四平台（YouTube、X、Instagram、TikTok）**手动添加「平台属性」**并完成授权验证。  
4. 把最终公开 URL 清单交给 SEO/后端，回填后台 Google 账户「分发渠道」五字段（见下表）。

### 非目标（禁止误解）

| 误解 | 正确口径 |
|------|----------|
| 登记 = 自动分发内容到各平台 | **否**。后台只存 URL 清单；本系统不自动发帖/推片 |
| 加平台属性 = 直接涨排名 / 涨信任分 | **否**。GSC 平台属性用于对照社交/视频内容在 Google 上的表现；**不直接加分** |
| LinkedIn 也要在 GSC 加平台属性 | **否**。GSC 官方仅四平台；LinkedIn **仅本地存档** |
| 顾问/开发已替运营开好号 | **否**。开号、资料页、GSC 加属性均由**运营执行** |

权威文案对齐后台 hint：分发渠道「只存 URL 作清单，不会自动分发内容，也不会直接提高排名或信任分；请在 GSC 手动添加平台属性（官方 API 尚未开放拉取）」。

---

## 2. 品牌与 handle 锁定

| 项 | 标准 |
|----|------|
| 中文名 | 长安汉服 |
| 英文名 | Chang'an Hanfu |
| 优先 handle | `changanhanfu`（全小写、无空格、无多余下划线） |
| 资料页必填 | Logo / 头像、品牌简介、官网链接（独立站主域）、联系邮箱（公司邮箱优先） |
| 语言 | 简介可中英并列或分平台主语言；**品牌名不得写成无关英文店名** |

### handle 占用时的变体策略（按优先级）

1. `changanhanfu`（首选，五平台尽量一致）  
2. `changan.hanfu` / `changan_hanfu`（仅当平台允许 `.` / `_` 且可读）  
3. `changanhanfu.official` / `changanhanfu_hq` / `changanhanfu.shop`  
4. `changanhanfu.cn` 或区域后缀（海外主推仍优先无后缀）  
5. **禁止**：随机数字尾、山寨仿牌拼写、与品牌无关的网红式昵称

任一平台必须用变体时：在运营回执中写明「平台 | 实际 handle | 原因」，并保持展示名仍为长安汉服 / Chang'an Hanfu。

---

## 3. 五平台注册清单

> 目标 URL 为 **意向规范形态**（handle=`changanhanfu`）。实际以开号后平台给出的规范 URL 为准；回填后台时须与公开可访问地址**完全一致**。

| 平台 | 目标 URL（意向） | 账号类型建议 | 运营动作 | GSC 是否要加平台属性 | 回填后台字段 |
|------|------------------|--------------|----------|----------------------|--------------|
| YouTube | `https://www.youtube.com/@changanhanfu` | **Brand Account 频道**（可多人管理）；公开名 Chang'an Hanfu / 长安汉服 | 用公司 Google 账号创建频道 → 设 `@changanhanfu` → 完善频道页与官网链；入口：[YouTube 创建频道说明](https://support.google.com/youtube/answer/1646861) / `https://www.youtube.com` | **是**（官方四平台之一） | `youtube_channel_url` |
| X (Twitter) | `https://x.com/changanhanfu` | 先个人号再转 **Professional / Business** | 用公司邮箱在 `https://x.com` / `https://x.com/i/flow/signup` 注册 → 设 username → 转 Professional；入口：`https://x.com` | **是** | `x_profile_url` |
| Instagram | `https://www.instagram.com/changanhanfu` | 先注册再转 **Professional → Business** | App/`https://www.instagram.com` 注册 → Settings → Switch to professional → Business；官方帮助：[Set up a professional Instagram account](https://www.facebook.com/help/instagram/502981923235522) | **是** | `instagram_profile_url` |
| TikTok | `https://www.tiktok.com/@changanhanfu` | 先个人号再转 **Business Account** | App 或 `https://www.tiktok.com` 注册 → Settings → Switch to Business Account；官方说明：[TikTok Business Account](https://www.tiktok.com/business/en/solutions/business-account) | **是** | `tiktok_profile_url` |
| LinkedIn | `https://www.linkedin.com/company/changanhanfu` | **Company Page**（非个人主页） | 个人 LinkedIn 登录后 For Business → Create a Company Page，或 `https://www.linkedin.com/company/setup/new`；公共 URL 选 `changanhanfu`；官方帮助：[Create a LinkedIn Page](https://www.linkedin.com/help/linkedin/answer/a543852) | **否**（GSC 官方四平台以外；**仅本地存档**） | `linkedin_profile_url` |

### 联网核对摘要（2026-09-22）

- **GSC 平台属性**：官方支持 Instagram、TikTok、X、YouTube；在 Search Console「添加属性」选平台并 OAuth 验证（见 [Google Search Central · Platform properties](https://developers.google.com/search/blog/2026/07/platform-properties-social-video-guide)）。**不含 LinkedIn**。  
- **自定义用户名**：上述五平台均常见支持品牌自定义 handle / 公共 URL；是否空闲以开号现场为准，本 brief **不声称已占用**。  
- **本席未开号**：仅提供入口与标准；账号、密码、手机验证由运营用公司资产完成。

---

## 4. 执行顺序与负责人

| 步骤 | 动作 | 负责人 | 顾问角色 |
|------|------|--------|----------|
| 0 | 准备：公司邮箱、手机号、Logo、品牌简介中英、官网 URL、统一密码保险箱 | 运营 | 定标准（本 brief） |
| 1 | 按序注册：**Instagram → TikTok → YouTube → X → LinkedIn**（先占视觉/短视频面，再建视频站与职场页；可同日并行，但每号单独验收） | 运营 | 不代开 |
| 2 | 五平台资料页对齐品牌名 / handle / 官网链 | 运营 | 抽检标准 |
| 3 | GSC：对 YouTube、X、Instagram、TikTok **分别添加平台属性并完成授权**（站点域名属性已验证为前提；与站点属性分开） | 运营（持有 GSC 权限者）或 SEO 协同 | 不代操作 |
| 4 | 汇总五条最终 URL → 通知 PM / SEO 席回填后台 Google 账户分发渠道 | 运营产出清单；**填档：开发/SEO 席** | escalate，不排施工 |

说明：顾问只定标准与验收；**禁止**本席改 PHP/后台配置；回填后台注明「完成后通知开发/SEO 席填档」。

---

## 5. 验收标准（运营过签一眼看）

1. **账号真实可访问**：无登录态下打开五条 URL，均为品牌公开主页（非 404、非私密墙挡住品牌识别）。  
2. **资料页品牌一致**：展示名含长安汉服或 Chang'an Hanfu；头像/Logo 一致；简介不出现无关品牌。  
3. **Handle**：优先 `@changanhanfu` / `company/changanhanfu`；若变体，五平台尽量同一变体策略并有文字记录。  
4. **GSC 四平台已加属性**：YouTube、X、Instagram、TikTok 在 Search Console 属性列表中可见且验证通过；LinkedIn **不要求** GSC 平台属性。  
5. **后台 URL 一致**：`youtube_channel_url` / `x_profile_url` / `instagram_profile_url` / `tiktok_profile_url` / `linkedin_profile_url` 与公开 URL 字面一致（含 `https://`、`@`、末尾斜杠习惯以平台规范为准，勿自创改写）。

---

## 6. ops_notes

- 登记目的：品牌官方渠道对照 +（四平台）GSC 表现报表；**不是**本波内容日历或投放开工令。  
- 开号后短期内可不日更；但资料页不得长期空白（至少头像 + 一句话 + 官网）。  
- 密码与恢复邮箱必须公司可控；禁止绑个人私人号后失联。  
- LinkedIn 仍要建 Company Page：职场/合作方信任与本地 SEO 账户存档；勿因「GSC 不加属性」而跳过。

---

## 7. dev_ask（仅账号就绪后）

> **要开发什么（领域）**：无新功能开发。仅需在运营交付最终 URL 后，把五条地址写入既有 SEO → Google 账户 → 分发渠道字段（`youtube_channel_url`、`x_profile_url`、`instagram_profile_url`、`tiktok_profile_url`、`linkedin_profile_url`）。

成功标准：后台保存后再次打开与运营清单一致；不触发自动分发。

**本席不排施工波**；请项目经理组队安排 SEO/后端填档。

---

## 8. 升级项目经理

`notify_pm: true`

@项目经理：社媒五平台账号与 GSC 四平台属性由运营按本 brief 执行；**运营完成后若需写后台**，请立刻组队安排 SEO/后端将最终 URL 填入 Google 账户「分发渠道」（非电商顾问写码、非本席代排技术施工）。

`suggested_seats`：SEO（或后端熟悉 `Weline_Seo` 账户配置者）；运营执行开号。  
`result=escalate`（填档依赖，非新需求开发）。
