# Hanfu 不包邮文案翻译记录

记录时间：2026-09-23 14:53 +08:00。

## 已完成

仅本波 6 个唯一源串：Theme 5 键、Shipping 2 键、Faq 1 键，补齐各模块 en_US / zh_Hans_CN CSV。读取当前脏文件后定向更新，保留其它条目。六组模块/语言精确审计均无差异。标准译法复用 `Shipping calculated at checkout` 与 `International delivery`。没有修改模板、FAQ实体或语言配置，没有启动 collect、其它语言队列或旧任务重跑。

精确源串 `/tmp/hanfu-no-free-copy-terms.json`，映射 `/tmp/hanfu-no-free-translations.json`，审计 `/tmp/hanfu-no-free-csv-audit.json`。

## 官方发布状态：尚未完成

现有单飞脚本 `/tmp/hanfu-no-free-publish.php` 使用官方 Dictionary getEntries/upsert、TransactionCoordinator 和 AiTranslationPublisher::publishLocale，顺序 en_US、en_GB。原执行 session 33275，PID 14931；日志 `/tmp/hanfu-no-free-publish.jsonl`。

截至记录时间，日志仅有 en_US 的 new_terms=6、upsert=5（另 1 键已有正确译文）。事务已提交，但 publishLocale 尚无返回；en_GB 尚未开始，不能宣称运行字典更新成功。

一次只读诊断：PID 14931 在约 3 分钟时为 Rs、CPU 91.8%。其数据库 socket 客户端端口 62698 对应 PG PID 14976；状态 idle、wait_event_type=Client、wait_event=ClientRead、xact_start=null、blockers={}。没有数据库锁阻塞或未结束事务证据，当前为本地 PHP 计算阶段。保留此原进程，不重跑、不盲杀。复核入口是原 session / 日志，成功后还需核对 generated/language/en_US.php 与 en_GB.php 的 6 个值。

## 验收限制

项目经理通知 9555 运行服务当前 master 无 worker，页面超时，本席没有继续页面探针；因此本波没有真实英文页面验收通过证据。其余 38 语言未处理，旧 FAQ 免邮实体仍需后续独立处理，不能以英文回落冒充翻译。以上状态不表示整个店铺已达上线条件。
