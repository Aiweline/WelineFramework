# Weline_Social 融媒体管理模块

## 模块概述

`Weline_Social` 提供社媒、博客、论坛、消息渠道的统一平台账户、AI 创意和一键多平台发布能力。模块自身可以内置多个平台 Provider，外部模块也可以通过 `extends/module/Weline_Social/platforms.php` 一次追加多个 Provider。

社媒后台页继承 `Weline\Admin\Api\Controller\BaseController`，通过 `fetch()` 走当前
激活的后端主题布局（`Weline_Theme` 的 `default.default` 壳层）；Admin 是已声明的
真实必需依赖，不引用其内部 Controller。

## 核心能力

- 平台库：通过 `etc/social_platforms.php` 注册内置平台，通过 extends 注册外部平台。平台库与账户表单下拉按 Provider `sort_order`（热度）升序展示；数值越小越靠前。前 10 大型平台已按热度落地授权与连通性检测；Facebook / Instagram / WhatsApp / 微信 / X / LinkedIn / Pinterest 支持文本或图片 URL 实发，YouTube / TikTok / Snapchat 第一期明确拒绝视频上传并提示二期。测试平台 `fake_browser` 固定靠后。
- 授权分层：支持 OAuth 的平台可「一键授权」；WhatsApp / 微信等走人工凭据。应用级 App/Client 在统一配置中心「融媒体平台应用」（SystemConfig）配置；账户级 Token 仍加密存入 Social 账户。操作步骤见 [官方凭据与一键授权指南](./用户/官方凭据与一键授权指南.md)。
- 账户连接：支持 OAuth、API Key、Webhook、Bot Token、Application Password、JWT Admin Token 等官方凭据模式。
- 账户展示：账户可独立启用 `widget_enabled`，只用于前台社媒账户部件展示。
- 发布控制：账户必须 `publish_enabled=1` 且官方凭据检测通过后才能进入发布批次。
- 网站范围配置（仅 `website`）：后台顶部先选网站（如长安汉服），账户添加/一键授权/范围社媒/发布都绑定该站。关系表 `weline_social_website_account`；OAuth 完成后会 merge 绑入当前站（不会冲掉已有绑定）；回调回跳带 `scope_key`。平台 App 凭据按 `{website_code}.default.default` 读取，缺字段回落全局。前台 `social-accounts` 部件只展示**当前站**已绑定且 `widget_enabled` 的账户，不回落默认站。账户池本身仍可全局复用（不做账户表 `website_id` 强归属）。
- SVG 图标：平台定义自动按 `platform_code` 解析 SVG 图标，模板不硬编码平台图标。
- AI 创意：通过 `Weline_Ai` 的公开 `AiRuntimeInterface` 生成统一稿件和平台变体。
- 批量发布：创建 batch 和 target，单个平台失败不影响其他平台。
- 队列执行：`SocialPublishQueue` 可消费单个 `SocialPublishTarget`。
- fake 模式：内置 `fake_browser` Provider，供真实浏览器 smoke 验证使用，不访问外部平台。

## 日更自动运营（SocialAutopilot）

- 计划表 `weline_social_autopilot_plan` 每站一份，默认 `enabled=0`；开启后按 `slots`（默认 09:30 / 19:30）由 `SocialAutopilotCron` 每 5 分钟扫描，时段带 0~7 分钟抖动。
- 计划新增 `daily_count`（1-20）、`content_types`（text/image/video）、`image_model_code`、`video_model_code`、`video_source`（image_slideshow/ai_video）；`listMediaModels` 只读返回 image/video/text_default。
- 图片内容必须选择 `listMediaModels.image` 中的模型；`ai_video` 必须选择 video 列表中的模型，否则分别返回 `image_model_required` / `video_model_required`，不写计划表。后台勾图片未选模型时前端提示，不弹原生 alert。
- 取题按 `topic_ratio`（默认站内 3 : 站外 1）；内容按勾选的 `content_types` 与 `mix`（默认 text 2 / image 1 / video 1）分配，没有可用素材时降级图文，不硬凑。
- 一条记录一个 batch、多平台多 target；发布记录新增「平台」列，聚合 `platform_code success/total ✓|✗`，保留内容类型/通道/远端链接列。
- 通道路由：`SocialPublishRouter` 返回 `api` / `extension` / `draft_only` / `blocked(reason)`；`api` 创建发布批次，`extension` 进入浏览器扩展任务队列，`draft_only` 只留草稿不外发。
- 视频且 `review_video=1` 时停在 `awaiting_review`，在后台「审片」页签通过后才生成发布任务；驳回重新生成（最多 2 次）。
- 幂等键 `social:auto:{websiteId}:{Y-m-d}:{slotKey}`，重复触发返回 `duplicate`，不双发。
- 运行状态用 `getAutopilotRunStatus` 读取；扩展离线 24 小时、审片积压会标记 `needs_user`，同一阻塞项同日只记一次通知。

## 浏览器扩展安装

1. 在后台「扩展发布」页签下载 `social-extension.zip`。
2. 打开 `chrome://extensions`，开启开发者模式，点「加载已解压的扩展程序」并选中解压后的目录。
3. 回到后台「扩展发布」页签，点「生成授权码（5 分钟）」。
4. 点「复制」，打开扩展的选项页粘贴授权码并点「连接」。
5. 扩展每 60s 心跳上报；后台「待发队列」显示设备在线/离线与待发数量。
6. 用户打开页面或扩展在线后队列自动排空；离线只累计，不判失败，超过 24 小时计入 `needs_user`。

## QueryProvider

由于 `WeShop_Social` 已经占用 `social` provider，`Weline_Social` 使用：

```js
const Social = await Weline.Api.resource('welineSocial')
```

支持操作：

- `listPlatforms`
- `getPlatform`
- `listAccounts`
- `getAccount`
- `listWidgetAccounts`
- `startAuthorization`
- `completeAuthorization`
- `saveCredentialAccount`
- `testAccount`
- `disableAccount`
- `listWebsites`
- `listWebsiteAccountRelations`
- `saveWebsiteAccountDefaults`
- `getWebsiteDefaultAccounts`
- `resolvePublishAccounts`
- `generateCreative`
- `createPublishBatch`
- `getPublishBatchStatus`
- `listAutopilotPlan`
- `saveAutopilotPlan`
- `listMediaModels`
- `listExtensionJobs`
- `listReviewQueue`
- `getAutopilotRunStatus`
- `approveReview`
- `rejectReview`
- `createExtensionGrantCode`

后台页面通过 `Weline.Query.request('welineSocial', operation, params)`（经 Query worker）调用这些操作。管理写操作已声明 `frontend/backend/external`，避免后台页被误判为「未对 frontend worker 开放」。前台公开面仍建议只调用只读能力（如 `listPlatforms`、`listWidgetAccounts`）。

应用凭据与一键授权步骤见：`doc/用户/官方凭据与一键授权指南.md`。

## 站点与社媒账户关系

后台 `站点社媒` 页签用于为每个站点配置默认社媒账户。关系只保存 `website_id`、`account_id`、`platform_code`、默认标记、排序和状态，不保存 token、secret、cookie 或 Authorization 等凭据。

后台范围选择器分层为：范围类型（如「站点」）→ 站点范围（具体站点，含零号默认站）→ 二级范围（如「站点默认」）。与账户表单中的平台、授权方式一样，均使用可搜索标签弹层选择器（选中态为标签 + 编码胶囊）。全页「添加到当前范围 / 范围社媒 / 一键发布」共用顶部范围选择。

**默认与深度链接**：无 URL 参数时默认选中 `website_id=0` 的站点默认范围；切换范围会同步更新地址栏 `scope_key`（可分享）。支持的 query 参数：

- `scope_key=website:0:website_default:0`（推荐）
- `scope_code` / `website_code`（站点编码）
- `scope_type` + `scope_id` + `child_scope_type` + `child_scope_id`
- `scope={website_code}.default.default`（与系统配置 scope 语法兼容）
- `tab=accounts|scopeAccounts|creative|publish|records|autopilotPlan|extensionPublish|review`（打开指定页签）

示例：`/weline_social/backend/social?scope_key=website:0:website_default:0&tab=scopeAccounts`

一键发布窗口默认先选择站点，再调用 `getWebsiteDefaultAccounts` 勾选该站点可发布账户；勾选 `全部站发布` 时调用 `resolvePublishAccounts`，按所有站点默认关系取账号并集。发布服务端也会兜底解析 `website_id` / `website_ids` / `all_sites`，避免只依赖前端勾选状态。账户仍必须满足 `publish_enabled=1` 且 `test_status=passed` 才能进入发布任务。

## 社媒账户部件

模块注册 `social-accounts` 部件：

```php
// extends/module/Weline_Widget/Weline_Social/widget.php
return [
    'Weline_Social::theme/frontend/widgets/social/social-accounts/default.phtml',
];
```

部件只渲染满足以下条件的账户：

- `status=active`
- `widget_enabled=1`
- `profile_url` 为 `http` 或 `https`

可配置参数包括 `title`、`layout`、`icon_size`、`icon_style`、`show_label`、`platforms`、`limit`。没有可展示账户时不输出示例链接。

## 扩展平台

扩展模块创建：

```php
// extends/module/Weline_Social/platforms.php
return [
    Vendor\Module\Platform\CustomProvider::class,
];
```

Provider 必须实现 `Weline\Social\Interface\SocialPlatformProviderInterface`，需要凭据检测时实现 `SocialPlatformConfigTesterInterface`。

## Fake 浏览器验证

访问 `/weline_social/frontend/social/smoke?fake=1&relation=1&no_publish=1` 会执行：

1. 创建或更新 fake 账户。
2. 读取站点列表中的首个站点。
3. 保存该站点与 fake 账户的默认社媒关系。
4. 解析该站点默认可发布账户。
5. 页面显示 `data-social-site-account-smoke="passed"`，且不创建发布批次。

如不传 `no_publish=1`，页面保留旧的 fake 发布冒烟能力，只调用本地 `fake_browser` Provider，不访问外部社媒平台。

该流程只使用本地 fake Provider，不保存真实平台凭据，不访问外部社媒平台。

## 安全边界

本模块的浏览器扩展与 AI 操作流按以下边界实施（2026-10-24 用户拍板；与旧版「不使用浏览器模拟」口径的差异在此明确记录）：

- **扩展运行在用户自己的浏览器里**，用用户自己的登录态；Weline 只下发「发什么、怎么发」，不接收、不存储密码或 Cookie。
- 扩展仅对用户在站点里显式勾选过的平台与账号工作；权限按平台域名最小化，不使用 `<all_urls>`。
- **AI 只按配方声明的入口与字段操作**：动作白名单 9 种（navigate / click / type / upload / scroll / wait / assert / done / give_up），单账号每日 1~2 条、带随机间隔；不做关注、私信、评论等互动自动化。
- 遇验证码、二次验证或风控提示即停，标记 `needs_user`；不绕过、不用打码平台、不走接码。
- **平台侧风险**：即使全部合规操作，仍可能触发平台自动化策略导致限流或封号；建议只用运营账号（`contact@` 体系开的号），不用个人主号。
- **配乐许可三件套**：`source_url`、`license`、`attribution` 必须齐备；只接受可商用或明确授权的来源；人工确认 `license_confirmed_at` 后才允许发布。视频审片开启时，人审同时确认配乐许可，缺一不进包。
- 凭据使用 `Weline_Ai\Api\SecretStoreInterface` 加密存储；Social 不依赖 Ai 内部 Service 或密钥实现。
- 发布日志会脱敏 token、secret、password、authorization、api_key 等字段。
- 前台部件和 QueryProvider 安全输出不包含加密凭据。
- 自动日更默认关闭：全局 `social_autopilot_enabled` 与站点计划 `enabled` 双开关，必须人工打开；未勾选的平台只出草稿，不外发。

## 前 10 平台实发与授权

应用凭据入口：统一配置中心 → `Weline_Social` →「融媒体平台应用」（`social/platform/{code}/*`）。OAuth 回调：`/weline_social/backend/oauth/callback`。

| 热度 | 平台 | 授权 | 第一期内容 | 状态 |
|------|------|------|------------|------|
| 1 | Facebook | 一键 OAuth + Page ID | 文本/链接/图片 URL | `live_publish_enabled` |
| 2 | YouTube | 一键 Google OAuth | 连通检测；视频上传二期 | `live_publish_enabled` |
| 3 | Instagram | 一键 Meta OAuth | 图片 URL 发布 | `live_publish_enabled` |
| 4 | TikTok | 一键 OAuth | 连通检测；视频上传二期 | `live_publish_enabled` |
| 5 | WhatsApp | 人工 token | Cloud API 文本 | `live_publish_enabled` |
| 6 | 微信公众号 | 人工 AppID/Secret | 客服文本（需 openid） | `live_publish_enabled` |
| 7 | X | 一键 OAuth 2.0 PKCE | 推文文本 | `live_publish_enabled` |
| 8 | LinkedIn | 一键 OAuth | UGC 文本帖 | `live_publish_enabled` |
| 9 | Pinterest | 一键 OAuth | Pin（需 image URL） | `live_publish_enabled` |
| 10 | Snapchat | 一键 OAuth | 连通检测；创意上传二期 | `live_publish_enabled` |
