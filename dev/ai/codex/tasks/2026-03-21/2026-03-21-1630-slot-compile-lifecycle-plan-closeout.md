# Slot 编译生命周期计划结案（2026-03-21）

## 目标

核对并收尾 `dev/ai/plans/codex-slot-compile-lifecycle.plan.md`：WLS 下 `w:slot` 静态注册表跨编译/跨请求泄漏、以及编译回调缺少源码位置信息。

## 结论

仓库中**已实现**计划三项内容，无需再改业务代码：

1. **每轮顶层编译重置** — `Taglib::compile()` 在 `topLevelCompile` 时于编译前/后调用 `resetCompileScopedTagState()`，其中对 `Slot::clearRegisteredSlots()` 的调用见 `app/code/Weline/Framework/View/Taglib.php`。
2. **源码 file/line** — `registerTagCallbacks()` 通过 `enrichTagConfigWithSource($tagConfig, $fileName, (int)($params['line'] ?? 0))` 注入；`CodeGenerator::buildTagParams()` 提供 `line`。
3. **WLS 请求级兜底** — `StateManager::registerFrameworkResets()` 中 `registerStaticReset(Slot::class, 'registeredSlots', [])`。

## 验证

```bash
php vendor/phpunit/phpunit/phpunit --configuration dev/phpunit/config.xml --filter SlotTaglibCompileStateTest
```

结果：2 tests, 5 assertions, OK。

## 文档

已更新 `dev/ai/plans/codex-slot-compile-lifecycle.plan.md` 状态为已完成。
