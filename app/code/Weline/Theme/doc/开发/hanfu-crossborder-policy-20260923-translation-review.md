# Hanfu 跨境政策翻译复审（2026-09-23）

状态：**partial / 未完成上线验收**。本记录只覆盖本波 45 个界面与政策源串，不代表全站翻译或各市场法律审核通过。

## 范围与事实边界

- Theme 14 条、Shipping 28 条、Product 3 条；源串保持简体中文。
- 已写入三个模块各自的 `i18n/zh_Hans_CN.csv` 与 `i18n/en_US.csv`，不新增其他语言模块 CSV。
- 逐条检查 45 个键在两份 CSV 中唯一存在、值正确；英文无中文占位。现有无关脏改保留。
- 区分制作/备货与国际运输；没有编造所有商品全手工、量体定制或统一制作天数。标准尺码/颜色、接单制作不直接译为不可退的个性定制。
- 法定公司名保留「成都阿玛云科技有限公司」，不编造官方英文名称。注册地址与退货地址不得混同。

## 默认站语言范围

已通过 `WebsiteLanguage::getWebsiteLanguageCodes(0)` 实读 40 个已选语言：en_US、ar_SA、bn_BD、es_ES、fr_FR、hi_IN、id_ID、pt_BR、ur_PK、zh_Hans_CN、bg_BG、ca_ES、cs_CZ、da_DK、de_DE、el_GR、en_GB、es_MX、et_EE、fi_FI、fr_CA、ga_IE、hr_HR、hu_HU、is_IS、it_IT、lt_LT、lv_LV、mt_MT、nb_NO、nl_NL、pl_PL、pt_PT、ro_RO、ru_RU、sk_SK、sl_SI、sv_SE、tr_TR、uk_UA。

模块中英边界不等于可以忽略其他 38 个已选语言。本波未更改网站语言配置。

## 收集、词典与发布

1. 首次 `php bin/w i18n:collect Weline_Theme` 返回 0，模块收集成功，但明确警告 `Not all WLS Workers completed cache clearing`。该次在本波英文写入之前，不能充当写后完成证据。
2. 写入 CSV 后运行 `php bin/w i18n:collect Weline_Shipping`（原 PID 94057）。收集长时间进行 CPU 工作，随后会话被用户新消息中断。续接时 PID 不存在、原会话句柄失效，未取得最终成功回执；不能认定已完成运行刷新。Theme 写后及 Product collect 尚待完成。
3. 为精准更新本波英文，使用现有 `Locale\Dictionary::upsert` 与 `AiTranslationPublisher::publishLocale`，没有改运行机制。第一次 en_US 发布操作被中断：续接读库证实仅 2/45 匹配，生成词典文件匹配 23/45。随后只对缺失的 43 条执行官方 upsert，取得 43 条成功回执；`publishLocale('en_US')` 已返回 true，进程退出 0。发布后直接读取生成词典，45/45 条与本波英文逐字匹配。
4. en_GB 复用本波英式英文，45 条 upsert 及 `publishLocale('en_GB')` 已返回 true；页面验收尚未通过。
5. 其他 35 个语言经官方幂等队列服务入队。已回读全部队列内容，`word_filter` 均精确 45 条，`requested_by=hanfu-policy-20260923`。没有改成全站翻译，也没有启动、安装或改配 Ollama；复用已配置且可用的 translation 场景。
6. ar_SA 已有全局任务，本波未重复入队。id_ID 的既有 cancelled 队列重开报 `idempotent_queue_reopen_failed:queue_force_required`，没有强制重开或改业务规则。
7. 队列快照曾为 29 pending / 3 running / 3 done。done 只表示调度状态，**不等同于全部译文成功、法律语义已校对或页面通过**。

## 真实 HTTP 验收

- `http://127.0.0.1:9555/guide/shipping` 返回 308 到项目 HTTPS；空 body 是跳转，不是服务故障证据。
- 已实际取得 `https://p05113ef3.test.weline.com:9555/guide/shipping` 英文页面。逐条核对 1.1 制作/运输分开、1.2 国际运输估计、1.3 延误同意/取消退款、6.1 丢损、6.2 签收争议、6.3 海关延误不免责：六条均完整匹配本波英文，未显示对应中文源串。
- `/policy/refund`、`/guide/returns` 与 `/en_GB/guide/shipping` 后续请求均在 20 秒内未返回正文，记录超时，**未宣称页面通过**。需恢复响应后复验通知/寄回/退款的 14 日语义与定制例外。
- FAQ 实体语言记录由主题席经 FaqService 更新，与系统词典分开验收；不能用词典已发布代替 FAQ 实体各 locale 已更新的证据。

## 续接状态与证据

- 精确中英映射：`/tmp/hanfu-translations-en.json`。
- CSV 逐键验证：`/tmp/hanfu-i18n-csv-audit.json`。
- 默认英文 Shipping 真实 HTTP 断言：`/tmp/hanfu-shipping-en-http-audit.json`；正文 `/tmp/hanfu-shipping-en-resume.html`。
- 35 队列 receipt：`/tmp/hanfu-i18n-queue-receipts.json`；逐队列 scope/status 快照 `/tmp/hanfu-i18n-queue-status.jsonl`。
- 精确 en_US 恢复发布日志：`/tmp/hanfu-i18n-publish-en-resume.log`；最终包含 `pending=43`、`already_correct=2`、`upsert=43`、`published=true`，进程退出 0。生成词典另行读回 45/45 匹配，无须重跑本次发布。
- MCP 本席 prepare/resolve/get_skill 均 180 秒超时，未把不存在的返回当已读。使用仓库允许的宿主文档回退：AI 硬规则索引、翻译工程师指令、模块翻译 CSV 规范、lang 标签指南；项目经理已有 readiness 亦已提供。

待完成：三个模块所需写后 collect 及运行刷新回执、剩余真实页面与非中英语言抽检、各语言新增政策译文语义校对及 FAQ 实体同步。未经这些证据，不将本波标记为翻译全量 pass 或上线就绪。

## 法律主体标签追加波

本波另收到 6 个唯一源串：经营主体、注册地址、公司联系邮箱、注册地址不作为退货地址的说明、网站经营主体信息、仅当前网站使用不继承全局主体的说明。Theme 中英各补 4 行，Websites 中英各补 5 行（三个标签共用），已逐键验证。只翻译字段标签和说明，不翻译公司中文法定名称、不修改注册地址值、不把注册地址认作退货地址。

映射 `/tmp/hanfu-legal-labels.json`；仅这 6 词使用既有官方词典接口更新 en_US/en_GB，发布回执写 `/tmp/hanfu-legal-labels-publish.jsonl`。未重复旧 45 词翻译、未启动全量 collect、未将新词塞进旧队列并称已完成。其他 38 个语言对这 6 个新增标签的覆盖与页面抽检尚未完成。

en_US 新 6 词已取得 `upsert=6`、`published=true`，并直接读取生成词典确认 6/6 匹配。en_GB 阶段尚未取得 upsert/publish 回执。一次 PG 只读快照确认，本脚本 PHP PID 51202（客户端端口 50832）的 PG PID 51223 正在等待 `Lock / transactionid`，被 PG PID 53703 的 `idle in transaction / ClientRead` 事务阻塞；该阻塞事务开始于 2026-09-23 14:22:13（+08），客户端端口 51246。证据 `/tmp/hanfu-pg-wait-snapshot.json`。未读取/输出其他会话的 SQL 正文，未取消或终止阻塞者。不能将 en_GB 新标签标为已发布；续接应先确认原脚本与该事务状态，避免重复写入。

**最终更新：新标签中英词典发布已完成。** 项目经理确认阻塞来自本任务自有公司保存进程，并在主线程授权后终止该进程、回滚未提交主体配置。翻译脚本没有重跑，自然恢复并得到 en_GB `upsert=6`、`published=true`，进程退出 0。再次直接读取 en_US 与 en_GB 生成词典，两者均 6/6 匹配；原 PHP PID 51202 已不存在，按原客户端连接复查 PG 返回空集，原等待关系已结束（`/tmp/hanfu-pg-wait-resolved.json`）。本波标签发布成功不代表主体配置已保存；公司三个配置字段最终仍以主题席的独立回读结果为准。其它语言新增标签及未完成页面验收仍保持待办。

## 旧 45 词后续单次只读快照

2026-09-23 14:23:17（Asia/Shanghai）读取：35 个定向队列为 25 done、1 running、9 pending；38 个非中英 locale 中，仅 ar_SA、es_ES、ur_PK、en_GB 的词典达到 45/45 非空，其余 34 个 locale 仍只有本波复用的 5/45。已存在项没有中文源串原样占位，但没有因此认定目标语言准确或政策法律语义校对完成。该快照直接证明不能把队列 done 当作翻译完成。未在本轮重开或追加旧任务。完整快照：`/tmp/hanfu-i18n-final-snapshot.json`。

对一个 done 但仅 5/45 的任务作了一次只读根因核对：bn_BD / queue_id=46588 的最新 result 末段为 `This batch=0, Failed=40, Remaining=40, Consecutive Failures=1/3`，随后明确 `AI_TRANSLATION_BUSY`，说明本地模型被其他任务占用，本批结束、不立即续队、等待下次计划任务，然后 `QUEUE_DONE`。因此该任务的 40 个新增词尚未产出，不能笼统解释为“已经翻译完成”或“没有待翻词”。证据 `/tmp/hanfu-one-done-result.json`；未重跑 35 批、未修改框架或并发控制。
