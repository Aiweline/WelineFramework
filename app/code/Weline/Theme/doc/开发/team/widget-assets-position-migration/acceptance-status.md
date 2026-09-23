# 部件资源与主题资源配置验收

整体状态：本次范围实现与验收完成（2026-09-23）。保留现有工作区修改；没有提交、合并或发布。

| 要求 | 当前证据 | 尚缺内容 |
| --- | --- | --- |
| 所有清单模板外置 CSS/JS | `widget-inventory-static-recheck-sep23.json` 精确复核 187 个模板，内联残留 0；343 次资源引用对应 160 个现存文件 | 各代表部件真实交互见下项 |
| 布局资源优先、去重及位置字段传递 | PHP契约通过；独立HTTP9568中10项CSS/JS默认head、footer/body/end-body、唯一加载、请求200以及source提升layout的独立完整用例通过（`/tmp/weline-resource-http-positions-final.log`）；真实blank/full无footer回退专项通过，CSS/JS各一次位于body、请求200、JS defer，隔离范围清理及默认范围不变（`/tmp/weline-resource-final-nofooter-shell.log`，1 passed，24.6秒） | 此专项已通过 |
| 主题基础信息分组及六项资源设置 | 双页签、六项字段及继承行为正式浏览器通过；开发/生产默认及手动覆盖测试通过；两页签截图人工核对 | 此专项已通过 |
| 实际压缩合并与行为保持 | `/tmp/weline-resource-config-resumed.log`：真实保存→页面合包→资源请求/返回顶部交互→原六项覆盖恢复，1 passed，6.7 秒 | 配置通过抽屉输入保存后的刷新见下项 |
| 点击资源配置先固化后刷新 iframe | 独立HTTP9568正式用例通过，验证真实SDK固化回执/文件SHA、先固化再iframe导航、身份/语言/版本及范围保持；两分组截图人工核对。日志 `/tmp/weline-resource-http-9568.log` | 此专项已通过 |
| 抽屉保存成功触发固化/刷新 | `/tmp/weline-resource-resume-9555.log` 中完整 UI 保存专项通过：保存→固化回执及文件SHA→iframe导航顺序/上下文保持→六项覆盖精确恢复null | 该专项已通过；其余抽屉及多实例流程见各项 |
| 多实例、新增、更新、删除、配置保存 | 修复SlotRendererService按同code误覆盖不同UID后，`/tmp/weline-resource-http-uid-fixed.log`中多实例完整专项通过：新增两FAQ/独立搜索/配置更新/删除一实例后另实例仍可用；fixture清理及默认范围不变。弹窗真实SDK预览已通过 | 多实例专项已通过 |
| 后台代表部件 | 现有Dashboard系统状态部件只读验收通过，CSS唯一位于head、实际加载且HTTP200、部件无内联可执行脚本/style、页面脚本错误0；`/tmp/weline-resource-backend-final.log`，1 passed，20.0秒；截图人工核对 | 未重建注册或发布默认布局 |
| 商品图片与购买按钮布局 | 1440 和 390 宽度真实专项通过，图片正常加载、购买按钮位于图片下方且在卡片内；两张截图人工复核正常。日志 `/tmp/weline-resource-sdk-recovered.log` 中商品专项 1 passed；未改既有 68% 图片比例 | 无该专项新增缺口 |
| 文档、开发工程师提示及 MCP 规则 | 13 份文档/提示精确复核无旧内联豁免；新增资源配置规范和中英词条；模块翻译采集成功 | 无新增文字缺口已知 |

旧 `widget-inventory-recheck.json` 中 `:29843` 的 HTTP 结果只保留作历史记录，不作为当前验收依据。完整过程、失败原因和测试命令见 `../../session/widget-assets-position-migration.md`。混合批次日志中的历史失败没有抹除，以上逐项列出修复后的独立运行证据。

运行说明：此前9555请求超时，9568被共享证书退役事务占用；正常重试后独立HTTP9568启动成功并返回200。blank/full鉴权预览原先404、随后被后台外壳包装，现已修正：仅该HTML分支暂时关闭后台包装并恢复状态，JavaScript 9项、PHP 3项/14断言通过，真实浏览器已通过。没有强杀共享任务或删除锁。

既有问题：旧草稿中的两项footer-help-center-link引用、Phrase原实现亦存在的6项测试失败，以及MCP旧字面规则断言失败仍保留，不为本次验收改断言或删除用户草稿。共享服务提示旧sidecar协议代次需维护，本次未重启共享服务。上述问题不作本次资源改动通过的证据，也未宣称全仓测试全部通过。
