---
name: weline-social-platform-register
description: >
  口令命中融媒体/社媒或 YouTube、Facebook、Instagram、X、TikTok 等平台，
  且在说注册、发布、查找某站账号时，必须先读本技能。注册只对站柜里已上线的站。
  判断范围里有没有号之前，必须打开该站已登录的融媒体后台看「范围社媒」。
  接口字段为空不能当成范围是空的。开号清单以该站后台平台库页面为准，不要用写死的平台表。
  被封或还没有的平台，用该站 contact@官网域名 新开，并把账号写回线上后台和站柜保险箱。
aliases:
  - social-platform-register
  - 融媒体注册
  - 社媒注册
  - 融媒体口令
---

# 融媒体口令

提到融媒体、社媒，或某个平台（含缩写），并且是在查号、发布或注册时，先按本技能做。

## 触发词

- `融媒体`
- `社媒`
- `媒体邮箱`
- `YouTube`
- `油管`
- `Facebook`
- `FB`
- `Instagram`
- `Ins`
- `IG`
- `TikTok`
- `抖音`
- `X`
- `Twitter`
- `LinkedIn`
- `Pinterest`
- `Snapchat`
- `注册社媒`
- `发布到社媒`

## 先分清两件事

口令里要有**哪个站**（站名或 code）。对不上站名就问用户，不要拿别的站的邮箱去注册。

**平台**可以点名，也可以不点：

| 口令里的平台 | 做什么 |
|--------------|--------|
| 点了具体平台 | 只处理这些平台。 |
| **没写平台**（只说「注册社媒 / 开号」） | **不要停下来问平台**。按下面「线上支持哪些」取出清单，对该站挨个处理。全部跑完再收口。 |

| 口令 | 做什么 |
|------|--------|
| 发布、查找、用某站的 YouTube/FB 等 | 读该站已有社媒账户。在用且主页还在：反馈账户名和公开主页。被封或没有：按「注册」处理，不要只停在「还没有」。 |
| 注册 | 先过上线门槛。不过就停止。过了之后按平台集合逐个处理。 |

每个平台先判状态，再决定动作：

1. **在用**：线上该站已有同 `platform_code`，`status=active`，公开主页仍可打开 → 回报并跳过，不要再开一个。
2. **被封或已禁用**：`status=disabled`，或主页明确停用/封禁/不存在 → **用 `contact@{官网域名}` 新开一个号**。旧记录保持禁用，不要把旧行改成新号。新号成功后再「写入并激活」。
3. **站柜有主页、线上没有、主页没被封** → 不要去平台注册页。直接「写入并激活」。
4. **两边都没有** → 用 `contact@{官网域名}` 新开；成功后再「写入并激活」。

上面四条以「先打开后台看」里看到的范围社媒为准。接口没返回账户，不能当成范围里没有。

平台别名：youtube、facebook / fb、instagram / ins / ig、x / twitter、tiktok、linkedin、pinterest、snapchat、bluesky。线上注册表里的其它 code 以该站后台平台库页面上的 `code` 为准。

## 先打开后台看（硬）

判断「范围里有没有号」「开号清单是不是空的」「该不该再开」之前，必须打开**该站已登录的融媒体后台**看页面。`listPlatforms`、`getWebsiteDefaultAccounts` 和本机代码不能代替这一眼。

1. 上线门槛过了之后，用本机 Google Chrome（chrome-devtools）打开该站后台融媒体页。先开或复用标签组 **`AI·社媒注册`**，不抢焦点，不进隔离上下文。建不出组就停，不要改口说已经看过。
2. 页上的「当前配置范围」必须对上站柜里的这个站（用 `listWebsites` 的 `code` 对页面上的站点范围）。对不上就停，不要拿别的站的范围下结论。
3. 看「范围社媒」里实际勾选的账户：平台、账户名、是否勾选。这是该站范围里有没有号的依据。
4. 再看「平台库」上每个平台有没有注册按钮，以及按钮打开的地址。接口里 `account_signup_url` 为空，只说明接口没带出地址，不能据此说范围是空的，也不能据此说没有可开的平台。
5. 页面打不开、未登录、或标签组建不出来：停，并写明没看成。禁止写成「范围为空」或「没有可注册平台」。

## 线上支持哪些（不要写死清单）

口令没点名平台时，开号范围只来自**刚看过的该站后台平台库**，不要用本技能或记忆里的固定九个平台。

1. `welineSocial.listPlatforms` 只作对照，不能单独当结论。
2. 页面上有注册按钮、且地址是 `https` 的平台才进入开号清单。只配 bot token、webhook、api key、页面上没有注册按钮的不进。
3. 记下每条的 `code`、`title`、`auth_modes`、`supports_one_click_auth`、页面上的注册地址、`docs`。
4. 注册页或 `docs` 没写清**当前**要填的账号种类（邮箱、手机、用户名，还是跳到别的登录）时，再检索该平台官方帮助页，按当下页面操作。不要把某一次查到的点击步骤写进本技能。

`{email}` 换成下面的 contact 邮箱。只要 `https`。

## 上线门槛（注册必过）

1. 用口令里的站名对 `websites/README.md`，锁定 `websites/<site>/`。对不上就问用户，不要猜。
2. 读该柜 `server.md`。必须写有主用 SSH Host、应用根，以及官网域名。没有 `server.md`，或写明还没有生产站：告诉用户这站还没上线，停止。不要打开浏览器。
3. 官网从**源站**能通：SSH 到主用 Host，在机上请求 `127.0.0.1` 并带官网 `Host`。接受 200、302、308。禁止从本机对公网域名做 curl。不通就停止。
4. 邮箱域名必须是 `server.md` 里的官网域名，不是本机测试域名。

## 用哪只邮箱、密码放哪

新开和被封后重开，注册邮箱一律是 **`contact@{官网域名}`**。不要再为开号去建 `social-{网站code}@`。

读信身份在该应用根机器的 mail-reader 凭据里，只读 JSON 的 `username`（以及需要时的 `base_url`）。凭据在 `/etc/weline/mail-reader.credential`，或 `{应用根}/var/mail/mail-reader.credential`。`sealed_secret` 不要读、不要打印、不要复制。

`username` 为 `contact@{官网域名}` 时，验证信就在这只收件箱里，可以直接读。这不是故障。

账号和密码只给执行开号的这一步，落点只有站柜保险箱：

| 要填的东西 | 从哪拿 |
|------------|--------|
| 注册邮箱 | `contact@{官网域名}` |
| 这个平台的新登录密码 | 开号时生成一次，立刻写入 `websites/<site>/credentials/social-accounts.md`。填表只读这一份。 |
| 快捷注册所依赖的父账号 | 同一保险箱里该父身份的邮箱和密码。见下一节。 |

禁止把密码写进技能、源码、模块文档或会进 Git 的文件。禁止在对话里贴出密码。

## 快捷注册（依赖另一个账号时）

有些平台的开号页不是自己收邮箱，而是「用 Google / Apple / Facebook 继续」，或 `supports_one_click_auth=true` 且 `account_signup_url` 的主机不是这个平台自己。

1. 先看出注册页实际跳到哪个身份。YouTube 的开号页在 `accounts.google.com`，父身份是 Google，邮箱仍用这站的 contact。
2. 开页之前，从站柜 `credentials/social-accounts.md`（或该站专门的登录保险箱）读出**那个父账号**的登录名和密码，用它完成快捷注册。
3. 保险箱里没有这个父账号：停，写明缺的是哪一个身份。不要现编密码，不要改用别的站的账号。
4. 父账号本身已被封：用 contact 邮箱新开那个父身份，记入保险箱，再用它去开下游平台。

## 开号

1. 用**本机 Google Chrome**（chrome-devtools）打开该平台的 `account_signup_url`。先开或复用标签组 **`AI·社媒注册`**，页只放进这一组，也只操作这一组。不要开到用户看不见的隔离上下文。不要用编辑器内置浏览器去填第三方注册页，不要用 AppleScript 把 Chrome 抢到前台。这一轮开号收口后删掉该组。
2. 表单填 contact 邮箱，密码用保险箱里刚写下的那一次。要快捷注册时改填父账号。
3. 验证码从 contact 收件箱读，不要换邮箱。
4. 页面上普通人能点的按钮可以点。出现人机验证且这一步过不去：停下来，请用户在这个 Chrome 窗口里协助。不要用打码平台。
5. 平台侧注册成功后，立刻做下一节。不要只在对话里说号开好了。

## 写入并激活（注册成功后的闭环）

写入只发生在该站 `server.md` 的**应用根**上。调用 `welineSocial`。不要写到当前打开的本机框架库，也不要写到别的站。

用站柜里的网站 code 对 `listWebsites` 的 `code`，锁定 `website_id`。对不上就停，不要猜 `website_id`。

1. `saveCredentialAccount`。
   - `platform_code`：与线上注册表 `code` 一致。
   - `account_name`：平台上的显示名或句柄。
   - `profile_url`：公开主页，必须是 `http` 或 `https`。
   - 已有公开主页：`widget_enabled=true`，`publish_enabled=false`。
   - 还没有公开主页：不要开 `widget_enabled`。告诉用户缺主页，网页上先不出现。
   - 开号阶段不要开 `publish_enabled`。
   - 登录邮箱是 `contact@{官网域名}`。密码不要放进这条接口的源码或仓库；平台口令只在站柜保险箱。
2. 绑进这个站的范围，并且**并入**已有账户，不要覆盖。
   - 先 `getWebsiteDefaultAccounts`（`website_id`，不要 `publish_only`）。
   - 把上一步返回的 `account_id` 追加到已有 `account_id` 列表。被封的旧号不要放回这份列表。
   - 再 `saveWebsiteAccountDefaults`，传入这份**完整**列表。
3. 有公开主页时：`listWidgetAccounts` 里必须有这条，且 `profile_url` 对得上。
4. 回填站柜：
   - `credentials/social-accounts.md`：平台、账户名、公开主页、登录邮箱、密码、是否依赖快捷注册以及父账号名称。
   - 站柜 README 的对外清单只写公开 URL，不写密码。

向用户回报：站、平台、账户名、公开主页、是否已出现在该站网页上。密码只说「已写入站柜保险箱」。

## 权威

| 层 | 位置 |
|----|------|
| 范围里有哪些号 | 该站后台「范围社媒」页面上勾选的账户 |
| 平台清单 | 该站后台「平台库」页面；`listPlatforms` 只作对照 |
| 注册页 | 平台库按钮上的地址（`account_signup_url` 只是接口字段，空了不等于没有按钮） |
| 邮箱 | `contact@{官网域名}`；验证信走 mail-reader 的 contact 收件箱 |
| 密码 | `websites/<site>/credentials/social-accounts.md` |
| 写入并激活 | `saveCredentialAccount` → `getWebsiteDefaultAccounts` → `saveWebsiteAccountDefaults` → `listWidgetAccounts` |
| 硬规则 | `social_scope_mailbox_for_platform_register` |

## 禁止

- 没打开该站融媒体后台页面，就说范围是空的，或说没有可开的平台。
- 用一份写死的平台清单代替该站后台平台库页面。
- 每个平台各用一个邮箱，或把邮箱、密码写进源码、技能、会进 Git 的文件。
- 在用且主页还在的号再开一个。
- 被封之后仍只回报旧号、不去用 contact 邮箱新开。
- 口令没写平台时停下来问「注册哪个」。
- 把某一个站的邮箱或父账号当成所有站的默认。
- 站柜没有 `server.md`、或官网从源站不通，仍去注册。
- 快捷注册缺父账号时现编密码或借用别的站。
- 平台号已经注册成功，却不写入该站线上后台、不回填站柜。
- `saveWebsiteAccountDefaults` 只提交新账户，把该站已有社媒绑定关掉。
- 开号阶段打开 `publish_enabled`，或把账户写到别的站、写到本机测试库。
