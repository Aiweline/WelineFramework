# 部件生命周期与统一渲染绑定

- 阶段：已完成实现与针对性运行验收；用户 2026-09-23 确认“好的，修复”。
- 规格：../spec/widget-lifecycle-and-render-binding.md
- 计划：../plan/widget-lifecycle-and-render-binding.md
- P1/P2：已实现；公共区域和嵌套插槽复用同一组按继承顺序排列的 EntityRenderBinding，缓存命中也恢复来源；显式空槽遮蔽祖先。失效诊断从实际 DOM tip 找最近所属 wrapper，不再跨兄弟/父实例误报。
- P3：已实现；完整成功扫描才对覆盖模块内普通定义退役；局部/失败扫描不退役。退役事件仅更新当前未发布草稿，不改发布和历史版本。未扫描的停用/删除模块不自动退役。
- P4：已实现；删除定位当前草稿或实际继承插槽，保留同槽兄弟及其他本地槽；发布版本先复制草稿；人工删除以 inactive/user_deleted 保留，旧 active 载荷不得复活。未增加恢复 UI。
- P5：Theme 标准升级成功，version/setup_version 均读回 2.2.609。最终合并回归 67 tests / 345 assertions 全部通过（/tmp/weline-binding-regression-final-fixed.txt）；编辑身份测试显式启动真实模块 DI 并隔离全局测试状态。目标 PHP lint 与 diff check 通过，一次集成复审所发现四项已修复。
- 决议：Chrome显式删除复用is_active=false并保留人工卸载来源，避免另建不同版本ID空间的卸载账本；必须测试固化不复活。
- 运行验收：Weline Chrome 实际用户普通商品 URL，页头/页尾存在，购买区有加购、结账、分享、快捷购买、代付和 PayPal；点击推荐商品“繁花曲”的加购入口，加载完成的“选择规格并加购”弹层也有全部这些部件，分享按钮可见。未创建订单。
- 编辑页验收：用户原始无 editor_context URL，加载身份修复后连续两次刷新，widget-unavailable-tip=0、无失效警告；我的账户与 FAQ 保留，分享/快捷购买/代付/PayPal 存在。
- 追加根因修复：PublishedSlotHost 将同一发布实体的扁平子槽合成回父片段（明确空槽不复活）；PurchasePanelService 为片段建立并恢复商品/发布上下文，走既有 processSlots；Chrome 投影先合成父子；LayoutSlotRenderer 无 typed query 时复用编辑操作的 ScopeIdentity，显式 typed 优先于继承 LayoutIdentity。
- 数据：仅此前已备份的两条退役注册记录与 channel 草稿 10 清理；未发布草稿、未改写发布历史。store11 也有同 UID，不能凭 UID 推断 global8。
- 环境：期间其他 Cursor 曾强制重启同一服务；另有 Worker 内存压力/请求状态重置失败导致隔离与自愈失败的独立运行问题。未杀其他任务或删除其锁；标准升级等计划任务锁自然释放，标准 stop/start 恢复后完成上述浏览器验收。不把该运行问题归因于本补丁或宣称已解决。
