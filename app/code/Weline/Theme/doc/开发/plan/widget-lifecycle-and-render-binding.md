# 部件生命周期与统一渲染绑定实施计划

## 背景

用户已确认 [架构方案](../spec/widget-lifecycle-and-render-binding.md)，要求实施修复。当前工作区包含多人修改，基于当前磁盘精准编辑；不创建丢失脏改的隔离副本，不提交其他修改。MCP prepare 报 MCP_RUNTIME_STALE；ensure确认宿主旧代次，依文档使用限定原生读取，不重启共享宿主。

## 方案

复用现有 EntityRenderBinding、ThemeLayoutEntityChrome、配置读取缓存和既有草稿/固化服务。公共区域只解析一次版本；嵌套槽读同一绑定，失效恢复只认本实例边界；注册完整扫描对账；删除定位真实拥有者并保留历史发布版本。

## 细节

- [x] P1 统一 chrome 选择（主题渲染）：修改 ThemeLayoutEntityChrome::resolveRenderSource/renderCurrent，SlotRendererService::loadSharedChromeSlotWidgetsFromEntity；绑定在请求范围共享，配置从绑定的实际scope/版本读取。最小测试提供两套版本绑定：预览选择10，旧发布9；嵌套消费已解析绑定，不能因请求scope不同转回9；空配置表示清空，不能回退旧数据。
- [x] P2 修复诊断（主题渲染）：有效账户 wrapper 后跟失效 help wrapper，syncUnavailableWidgetsFromHtml 仅返回 help UID且reason=missing_definition；覆盖嵌套父wrapper及脚本内伪标签。先观察旧正则误选账户的红灯，再仅遍历真实tip所属wrapper。
- [x] P3 注册退役（部件席）：WidgetRegistryRecordService + Registry/Scanner扫描覆盖报告；完整成功范围才停用缺席普通定义，局部/失败不退役，不同module/type/area同code互不替代。运行真实服务行为的单元fixture；不执行无界全站refresh。
- [x] P4 删除归属（删除席）：Controller委托小型ThemeWidgetRemovalService，当前页面不含UID不能提前返回成功；查实际chrome草稿与homepage；通过服务复制发布版本后修改。Chrome显式移除复用is_active=false及来源元数据保留操作决定，避免required重固化复活。覆盖发布历史保留、重复删除、未知UID、重固化。
- [x] P5 运行验收与收口：先运行各最小测试并记录红绿，合并后一次范围回归/lint/diff check；标准服务恢复后在用户商品编辑URL及快捷购买弹层观察，验证无误报、头尾存在、应有购买/分享等嵌套部件；问题按真实能力与架构故障区分。未通过运行验收不标完成。

代码边界：主题渲染父席负责 SlotRendererService/Chrome/ConfigStore；部件席只改Widget；删除席只改删除Controller和小服务；运行性能席只操作标准服务命令及只读检查。新接口由父席通报，避免同文件冲突。

验证命令：`php vendor/phpunit/phpunit/phpunit --bootstrap vendor/autoload.php <目标测试文件>`；继承TestCore的测试按现有bootstrap启动。行为测试不以源码包含字符串代替。预期每个缺陷测试修前FAIL、修后PASS。编译与运行环境失败如实记录。

全局约束：不更改已发布历史，不删除有效账户/FAQ；不用全量主题重置；不新增支付业务限制；required实例有槽且无有效人工卸载时必须固化；不增加店面每请求全量扫描；复用现有请求memo与HotCache；没有新UI文案或布局设计。
